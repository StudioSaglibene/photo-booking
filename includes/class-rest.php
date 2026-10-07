<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * REST API: photo-booking/v1
 */
class Rest {

	const NS = 'photo-booking/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		// Public availability.
		register_rest_route(
			self::NS,
			'/days',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'days' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'product' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'month'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'exclude' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'slots' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'product' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'date'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'exclude' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// Admin.
		$admin = array( __CLASS__, 'admin_permission' );
		register_rest_route(
			self::NS,
			'/admin/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_events' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/appointments/(?P<id>\d+)/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_move' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/blocks',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_block_create' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/blocks/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'admin_block_delete' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'admin_block_update' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/customers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_customers' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_slots' ),
				'permission_callback' => $admin,
			)
		);

		// Deliveries (admin upload to R2).
		register_rest_route(
			self::NS,
			'/admin/deliveries',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delivery_init' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/deliveries/(?P<id>\d+)/parts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delivery_parts' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/deliveries/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delivery_complete' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/deliveries/(?P<id>\d+)/abort',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'delivery_abort' ),
				'permission_callback' => $admin,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                        */
	/* ------------------------------------------------------------------ */

	public static function admin_permission() {
		return current_user_can( Install::CAP );
	}

	/**
	 * Public endpoints: rate limited per IP. "exclude" (own appointment while rescheduling) requires ownership.
	 */
	public static function public_permission( \WP_REST_Request $req ) {
		$ip  = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'pb_rl_' . $ip;
		$n   = (int) get_transient( $key );
		if ( $n > 120 && ! current_user_can( Install::CAP ) ) {
			return new \WP_Error( 'pb_rate_limit', __( 'Troppe richieste, riprova tra un minuto.', 'photo-booking' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		$exclude = (int) $req->get_param( 'exclude' );
		if ( $exclude ) {
			$appt = Appointment::get( $exclude );
			if ( ! $appt || ( ! current_user_can( Install::CAP ) && ( ! is_user_logged_in() || $appt->customer_id !== get_current_user_id() ) ) ) {
				return new \WP_Error( 'pb_forbidden', __( 'Non autorizzato.', 'photo-booking' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	private static function public_product( $id ) {
		return Package::is_package( $id ) && ( 'publish' === get_post_status( $id ) || current_user_can( Install::CAP ) );
	}

	/* ------------------------------------------------------------------ */
	/* Public                                                             */
	/* ------------------------------------------------------------------ */

	public static function days( \WP_REST_Request $req ) {
		$pid = (int) $req['product'];
		if ( ! self::public_product( $pid ) ) {
			return new \WP_Error( 'pb_package', 'Pacchetto non valido', array( 'status' => 404 ) );
		}
		$opts = array();
		if ( $req['exclude'] ) {
			$opts['exclude']         = (int) $req['exclude'];
			$opts['ignore_capacity'] = true;
		}
		$res = rest_ensure_response( array( 'days' => Availability::days( $pid, (string) $req['month'], $opts ) ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function slots( \WP_REST_Request $req ) {
		$pid = (int) $req['product'];
		if ( ! self::public_product( $pid ) ) {
			return new \WP_Error( 'pb_package', 'Pacchetto non valido', array( 'status' => 404 ) );
		}
		$opts = array();
		if ( $req['exclude'] ) {
			$opts['exclude']         = (int) $req['exclude'];
			$opts['ignore_capacity'] = true;
		}
		$slots = array_map(
			static function ( $s ) {
				return array(
					'start' => $s['start'],
					'end'   => $s['end'],
					'time'  => $s['time'],
				);
			},
			Availability::slots( $pid, (string) $req['date'], $opts )
		);
		$res = rest_ensure_response( array( 'slots' => $slots ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* Admin calendar                                                     */
	/* ------------------------------------------------------------------ */

	public static function admin_events( \WP_REST_Request $req ) {
		$from = Time::to_db( (string) $req->get_param( 'start' ) ) ?: Time::now()->modify( '-1 month' )->format( Time::DB );
		$to   = Time::to_db( (string) $req->get_param( 'end' ) ) ?: Time::now()->modify( '+2 months' )->format( Time::DB );
		$show_closed = (bool) $req->get_param( 'closed' );

		$colors = Appointment::status_colors();
		$events = array();
		$status = $show_closed ? array() : array_diff( array_keys( Appointment::status_labels() ), array( Appointment::REJECTED, Appointment::CANCELLED, Appointment::EXPIRED ) );
		foreach ( Appointment::query( array( 'status' => $status, 'from' => $from, 'to' => $to ) ) as $a ) {
			if ( Appointment::PENDING_PAYMENT === $a->status && $a->hold_expires_at && $a->hold_expires_at < Time::now_db() ) {
				continue;
			}
			$events[] = array(
				'id'              => 'a' . $a->id,
				'title'           => $a->customer_name . ' · ' . $a->package_name(),
				'start'           => Time::iso( $a->start_at ),
				'end'             => Time::iso( $a->end_at ),
				'backgroundColor' => $colors[ $a->status ] ?? '#888',
				'borderColor'     => $colors[ $a->status ] ?? '#888',
				'editable'        => in_array( $a->status, array( Appointment::PENDING, Appointment::CONFIRMED ), true ),
				'url'             => $a->admin_url(),
				'extendedProps'   => array(
					'kind'          => 'appointment',
					'appointmentId' => $a->id,
					'status'        => $a->status,
					'statusLabel'   => $a->status_label(),
					'payment'       => $a->payment_label(),
					'phone'         => $a->customer_phone,
					'balance'       => $a->balance_due(),
				),
			);
		}

		global $wpdb;
		$t = Install::table( 'blocks' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE start_at < %s AND end_at > %s", $to, $from ), ARRAY_A ) as $b ) { // phpcs:ignore
			$events[] = array(
				'id'              => 'b' . $b['id'],
				'title'           => '⛔ ' . ( $b['reason'] ? $b['reason'] : __( 'Bloccato', 'photo-booking' ) ),
				'start'           => Time::iso( $b['start_at'] ),
				'end'             => Time::iso( $b['end_at'] ),
				'backgroundColor' => '#5f6b7a',
				'borderColor'     => '#5f6b7a',
				'classNames'      => array( 'pb-ev-block' ),
				'editable'        => true,
				'extendedProps'   => array(
					'kind'    => 'block',
					'blockId' => (int) $b['id'],
					'reason'  => $b['reason'],
				),
			);
		}

		$t = Install::table( 'proposals' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'open' AND start_at < %s AND busy_until > %s", $to, $from ), ARRAY_A ) as $p ) { // phpcs:ignore
			$a = Appointment::get( $p['appointment_id'] );
			if ( ! $a ) {
				continue;
			}
			$events[] = array(
				'id'              => 'p' . $p['id'],
				'title'           => '? ' . $a->customer_name . ' (' . __( 'proposta', 'photo-booking' ) . ')',
				'start'           => Time::iso( $p['start_at'] ),
				'end'             => Time::iso( $p['end_at'] ),
				'backgroundColor' => 'transparent',
				'borderColor'     => $colors[ Appointment::PROPOSED ],
				'textColor'       => $colors[ Appointment::PROPOSED ],
				'classNames'      => array( 'pb-ev-proposal' ),
				'editable'        => false,
				'url'             => $a->admin_url(),
				'extendedProps'   => array(
					'kind'          => 'proposal',
					'appointmentId' => $a->id,
				),
			);
		}
		return rest_ensure_response( $events );
	}

	public static function admin_move( \WP_REST_Request $req ) {
		$appt = Appointment::get( (int) $req['id'] );
		if ( ! $appt ) {
			return new \WP_Error( 'pb_404', 'Non trovato', array( 'status' => 404 ) );
		}
		$start = Time::to_db( (string) $req->get_param( 'start' ) );
		$res   = Bookings::reschedule(
			$appt,
			$start,
			'admin',
			array(
				'notify' => (bool) $req->get_param( 'notify' ),
				'force'  => (bool) $req->get_param( 'force' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 409 ) );
			return $res;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function admin_block_create( \WP_REST_Request $req ) {
		global $wpdb;
		$start = Time::to_db( (string) $req->get_param( 'start' ) );
		$end   = Time::to_db( (string) $req->get_param( 'end' ) );
		if ( ! $start || ! $end || $end <= $start ) {
			return new \WP_Error( 'pb_range', __( 'Intervallo non valido.', 'photo-booking' ), array( 'status' => 400 ) );
		}
		$wpdb->insert(
			Install::table( 'blocks' ),
			array(
				'start_at'   => $start,
				'end_at'     => $end,
				'reason'     => sanitize_text_field( (string) $req->get_param( 'reason' ) ),
				'created_by' => get_current_user_id(),
				'created_at' => Time::now_db(),
			)
		);
		$warning = '';
		$overlap = Appointment::query(
			array(
				'status' => Appointment::blocking_statuses(),
				'from'   => $start,
				'to'     => $end,
			)
		);
		if ( $overlap ) {
			/* translators: %d count */
			$warning = sprintf( _n( 'Attenzione: nell\'intervallo c\'è già %d appuntamento, che resta valido.', 'Attenzione: nell\'intervallo ci sono già %d appuntamenti, che restano validi.', count( $overlap ), 'photo-booking' ), count( $overlap ) );
		}
		return rest_ensure_response(
			array(
				'id'      => (int) $wpdb->insert_id,
				'warning' => $warning,
			)
		);
	}

	public static function admin_block_update( \WP_REST_Request $req ) {
		global $wpdb;
		$start = Time::to_db( (string) $req->get_param( 'start' ) );
		$end   = Time::to_db( (string) $req->get_param( 'end' ) );
		if ( ! $start || ! $end || $end <= $start ) {
			return new \WP_Error( 'pb_range', __( 'Intervallo non valido.', 'photo-booking' ), array( 'status' => 400 ) );
		}
		$data = array(
			'start_at' => $start,
			'end_at'   => $end,
		);
		if ( null !== $req->get_param( 'reason' ) ) {
			$data['reason'] = sanitize_text_field( (string) $req->get_param( 'reason' ) );
		}
		$wpdb->update( Install::table( 'blocks' ), $data, array( 'id' => (int) $req['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function admin_block_delete( \WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'blocks' ), array( 'id' => (int) $req['id'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function admin_customers( \WP_REST_Request $req ) {
		$q = sanitize_text_field( (string) $req->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array() );
		}
		$users = get_users(
			array(
				'search'         => '*' . $q . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 20,
			)
		);
		// Also by billing name/phone.
		$by_meta = get_users(
			array(
				'number'     => 20,
				'meta_query' => array( // phpcs:ignore
					'relation' => 'OR',
					array( 'key' => 'billing_first_name', 'value' => $q, 'compare' => 'LIKE' ),
					array( 'key' => 'billing_last_name', 'value' => $q, 'compare' => 'LIKE' ),
					array( 'key' => 'billing_phone', 'value' => $q, 'compare' => 'LIKE' ),
				),
			)
		);
		$out  = array();
		$seen = array();
		foreach ( array_merge( $users, $by_meta ) as $u ) {
			if ( isset( $seen[ $u->ID ] ) ) {
				continue;
			}
			$seen[ $u->ID ] = 1;
			$name           = trim( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) );
			$out[]          = array(
				'id'    => $u->ID,
				'name'  => $name ? $name : $u->display_name,
				'email' => $u->user_email,
				'phone' => (string) get_user_meta( $u->ID, 'billing_phone', true ),
			);
		}
		return rest_ensure_response( $out );
	}

	/** Slots for admin pickers (ignores minimum notice; optional exclude). */
	public static function admin_slots( \WP_REST_Request $req ) {
		$opts = array(
			'ignore_notice'   => true,
			'ignore_capacity' => true,
			'exclude'         => (int) $req->get_param( 'exclude' ),
		);
		$pid = (int) $req->get_param( 'product' );
		if ( $req->get_param( 'month' ) ) {
			return rest_ensure_response( array( 'days' => Availability::days( $pid, (string) $req->get_param( 'month' ), $opts ) ) );
		}
		return rest_ensure_response( array( 'slots' => Availability::slots( $pid, (string) $req->get_param( 'date' ), $opts ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Deliveries                                                         */
	/* ------------------------------------------------------------------ */

	public static function delivery_init( \WP_REST_Request $req ) {
		return self::wrap(
			Deliveries::start_upload(
				array(
					'customer_id'    => (int) $req->get_param( 'customer_id' ),
					'appointment_id' => (int) $req->get_param( 'appointment_id' ),
					'title'          => sanitize_text_field( (string) $req->get_param( 'title' ) ),
					'filename'       => sanitize_file_name( (string) $req->get_param( 'filename' ) ),
					'size'           => (int) $req->get_param( 'size' ),
					'days'           => (int) $req->get_param( 'days' ),
					'notify'         => (bool) $req->get_param( 'notify' ),
				)
			)
		);
	}

	public static function delivery_parts( \WP_REST_Request $req ) {
		return self::wrap( Deliveries::presign_parts( (int) $req['id'], array_map( 'intval', (array) $req->get_param( 'parts' ) ) ) );
	}

	public static function delivery_complete( \WP_REST_Request $req ) {
		return self::wrap( Deliveries::complete_upload( (int) $req['id'], (array) $req->get_param( 'parts' ) ) );
	}

	public static function delivery_abort( \WP_REST_Request $req ) {
		return self::wrap( Deliveries::abort_upload( (int) $req['id'] ) );
	}

	private static function wrap( $res ) {
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		return rest_ensure_response( $res );
	}
}
