<?php
namespace PhotoBooking\Admin;

use PhotoBooking\Appointment;
use PhotoBooking\Availability;
use PhotoBooking\Bookings;
use PhotoBooking\Cron;
use PhotoBooking\Deliveries;
use PhotoBooking\Install;
use PhotoBooking\Package;
use PhotoBooking\Payments;
use PhotoBooking\Policy;
use PhotoBooking\R2;
use PhotoBooking\Rest;
use PhotoBooking\Settings;
use PhotoBooking\Time;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin: menu, pages, actions.
 */
class Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), 999 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'studio_dashboard_redirect' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_pb_admin', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PB_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pb-settings' ) ) . '">' . esc_html__( 'Impostazioni', 'photo-booking' ) . '</a>' );
		return $links;
	}

	public static function pending_count() {
		return Appointment::count( array( 'status' => array( Appointment::PENDING, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ) ) );
	}

	public static function menu() {
		$count  = self::pending_count();
		$bubble = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';
		add_menu_page( __( 'Prenotazioni', 'photo-booking' ), __( 'Prenotazioni', 'photo-booking' ) . $bubble, Install::CAP, 'pb-calendar', array( __CLASS__, 'page_calendar' ), 'dashicons-camera', 56 );
		add_submenu_page( 'pb-calendar', __( 'Calendario', 'photo-booking' ), __( 'Calendario', 'photo-booking' ), Install::CAP, 'pb-calendar', array( __CLASS__, 'page_calendar' ) );
		add_submenu_page( 'pb-calendar', __( 'Elenco prenotazioni', 'photo-booking' ), __( 'Elenco', 'photo-booking' ) . $bubble, Install::CAP, 'pb-list', array( __CLASS__, 'page_list' ) );
		add_submenu_page( 'pb-calendar', __( 'Nuovo appuntamento', 'photo-booking' ), __( 'Nuovo appuntamento', 'photo-booking' ), Install::CAP, 'pb-new', array( __CLASS__, 'page_new' ) );
		add_submenu_page( 'pb-calendar', __( 'Consegne', 'photo-booking' ), __( 'Consegne', 'photo-booking' ), Install::CAP, 'pb-deliveries', array( __CLASS__, 'page_deliveries' ) );
		add_submenu_page( 'pb-calendar', __( 'Pacchetti', 'photo-booking' ), __( 'Pacchetti', 'photo-booking' ), 'edit_products', 'edit.php?post_type=product&product_type=' . Package::TYPE );
		add_submenu_page( 'pb-calendar', __( 'Impostazioni', 'photo-booking' ), __( 'Impostazioni', 'photo-booking' ), 'manage_options', 'pb-settings', array( __CLASS__, 'page_settings' ) );
		$hook = add_submenu_page( 'options.php', __( 'Prenotazione', 'photo-booking' ), __( 'Prenotazione', 'photo-booking' ), Install::CAP, 'pb-appointment', array( __CLASS__, 'page_appointment' ) );
		add_action(
			'load-' . $hook,
			static function () {
				global $title;
				$title = __( 'Prenotazione', 'photo-booking' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		);
		// Keep the "Prenotazioni" menu highlighted on the detail page.
		add_filter(
			'parent_file',
			static function ( $parent ) {
				return 'pb-appointment' === ( $_GET['page'] ?? '' ) ? 'pb-calendar' : $parent; // phpcs:ignore
			}
		);
	}

	public static function is_pb_screen() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return 0 === strpos( $page, 'pb-' );
	}

	public static function assets() {
		if ( ! self::is_pb_screen() ) {
			return;
		}
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_style( 'pb-admin', PB_URL . 'assets/css/admin.css', array(), pb_asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'pb-admin', PB_URL . 'assets/js/admin.js', array(), pb_asset_ver( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'pb-admin',
			'PBAdmin',
			array(
				'rest'      => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'adminUrl'  => admin_url( 'admin.php' ),
				'i18n'      => array(
					'confirmMove'  => __( 'Spostare l\'appuntamento a %s?', 'photo-booking' ),
					'notifyMove'   => __( 'Inviare un\'email al cliente con il nuovo orario?', 'photo-booking' ),
					'noSlots'      => __( 'Nessuno slot libero in questa data.', 'photo-booking' ),
					'error'        => __( 'Errore', 'photo-booking' ),
					'saved'        => __( 'Salvato', 'photo-booking' ),
					'confirmLeave' => __( 'Caricamento in corso: se esci verrà interrotto.', 'photo-booking' ),
				),
			)
		);
		if ( 'pb-calendar' === $page ) {
			$local = PB_DIR . 'assets/vendor/fullcalendar/index.global.min.js';
			$src   = file_exists( $local ) ? PB_URL . 'assets/vendor/fullcalendar/index.global.min.js' : Settings::get( 'fullcalendar_src' );
			wp_enqueue_script( 'fullcalendar', $src, array(), '6.1.15', true );
			wp_enqueue_script( 'pb-admin-calendar', PB_URL . 'assets/js/admin-calendar.js', array( 'fullcalendar', 'pb-admin' ), pb_asset_ver( 'assets/js/admin-calendar.js' ), true );
			$hours = Settings::get( 'hours' );
			$bh    = array();
			$min   = 24 * 60;
			$max   = 0;
			foreach ( $hours as $dow => $ranges ) {
				foreach ( Availability::parse_ranges( $ranges ) as $r ) {
					$bh[] = array(
						'daysOfWeek' => array( 7 === (int) $dow ? 0 : (int) $dow ),
						'startTime'  => sprintf( '%02d:%02d', intdiv( $r[0], 60 ), $r[0] % 60 ),
						'endTime'    => sprintf( '%02d:%02d', intdiv( $r[1], 60 ), $r[1] % 60 ),
					);
					$min = min( $min, $r[0] );
					$max = max( $max, $r[1] );
				}
			}
			// Package windows may go outside normal hours.
			foreach ( Package::all() as $p ) {
				foreach ( Package::config( $p )['windows'] as $w ) {
					foreach ( Availability::parse_ranges( $w['ranges'] ) as $r ) {
						$min = min( $min, $r[0] );
						$max = max( $max, $r[1] );
					}
				}
			}
			if ( $max <= $min ) {
				$min = 8 * 60;
				$max = 20 * 60;
			}
			wp_localize_script(
				'pb-admin-calendar',
				'PBCalendar',
				array(
					'businessHours' => $bh,
					'slotMinTime'   => sprintf( '%02d:00:00', max( 0, intdiv( $min, 60 ) - 1 ) ),
					'slotMaxTime'   => sprintf( '%02d:00:00', min( 24, (int) ceil( $max / 60 ) + 1 ) ),
					'newUrl'        => admin_url( 'admin.php?page=pb-new' ),
					'timeZone'      => wp_timezone_string(),
				)
			);
		}
		if ( 'pb-deliveries' === $page ) {
			wp_enqueue_script( 'pb-admin-deliveries', PB_URL . 'assets/js/admin-deliveries.js', array( 'pb-admin' ), pb_asset_ver( 'assets/js/admin-deliveries.js' ), true );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                              */
	/* ------------------------------------------------------------------ */

	private static function view( $name, array $vars = array() ) {
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include PB_DIR . 'admin-views/' . $name . '.php';
	}

	private static function is_studio_user() {
		$u = wp_get_current_user();
		return $u && in_array( Install::ROLE, (array) $u->roles, true ) && ! current_user_can( 'manage_options' );
	}

	/** The photographer sees only what she uses. */
	public static function trim_menu() {
		if ( ! self::is_studio_user() ) {
			return;
		}
		foreach ( array( 'index.php', 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'tools.php', 'options-general.php', 'elementor', 'edit.php?post_type=elementor_library', 'wpforms-overview', 'maintenance', 'edit.php?post_type=works', 'edit.php?post_type=albums', 'edit.php?post_type=people', 'edit.php?post_type=a13_albums', 'edit.php?post_type=a13_works', 'wc-admin&path=/analytics/overview', 'wc-admin&path=/marketing', 'woocommerce-marketing', 'wp-mail-smtp', 'bsf-custom-fonts', 'edit.php?post_type=product&page=product_attributes', 'elementor-home', 'import.php', 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' ) as $slug ) {
			remove_menu_page( $slug );
		}
		// Leftover taxonomy entries (categories of posts, albums, works, people, Elementor) once their parents are gone.
		foreach ( (array) $GLOBALS['menu'] as $item ) {
			if ( isset( $item[2] ) && ( 0 === strpos( $item[2], 'edit-tags.php' ) || 0 === strpos( $item[2], 'admin.php?page=wc-settings' ) ) ) {
				remove_menu_page( $item[2] );
			}
		}
		remove_submenu_page( 'woocommerce', 'wc-settings' );
		remove_submenu_page( 'woocommerce', 'wc-status' );
		remove_submenu_page( 'woocommerce', 'wc-addons' );
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/extensions' );
		remove_submenu_page( 'woocommerce', 'wc-reports' );
		remove_submenu_page( 'woocommerce', 'wc-admin' );
		remove_submenu_page( 'edit.php?post_type=product', 'product_attributes' );
		remove_submenu_page( 'edit.php?post_type=product', 'edit-tags.php?taxonomy=product_tag&post_type=product' );
		remove_submenu_page( 'users.php', 'user-new.php' );
	}

	public static function trim_admin_bar( $bar ) {
		if ( ! self::is_studio_user() ) {
			return;
		}
		foreach ( array( 'wp-logo', 'updates', 'comments', 'new-content', 'customize', 'elementor_edit_page', 'wpforms-menu', 'maintenance', 'themes', 'site-name' ) as $id ) {
			$bar->remove_node( $id );
		}
	}

	/** The dashboard is hidden: land on the calendar instead. */
	public static function studio_dashboard_redirect() {
		global $pagenow;
		if ( 'index.php' === $pagenow && self::is_studio_user() && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( admin_url( 'admin.php?page=pb-calendar' ) );
			exit;
		}
	}

	public static function page_calendar() {
		$pending = Appointment::query(
			array(
				'status'  => array( Appointment::PENDING, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION, Appointment::PROPOSED ),
				'orderby' => 'start_at',
			)
		);
		self::view( 'calendar', array( 'pending' => $pending ) );
	}

	public static function page_list() {
		$status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$when   = sanitize_key( $_GET['when'] ?? 'upcoming' ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per    = 30;
		$args   = array(
			'search'  => $search,
			'limit'   => $per + 1,
			'offset'  => ( $paged - 1 ) * $per,
			'orderby' => 'start_at',
			'order'   => 'upcoming' === $when ? 'ASC' : 'DESC',
		);
		if ( 'todo' === $status ) {
			$args['status'] = array( Appointment::PENDING, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION );
		} elseif ( 'balance' === $status ) {
			$args['status'] = array( Appointment::PENDING, Appointment::CONFIRMED, Appointment::COMPLETED, Appointment::PROPOSED );
		} elseif ( $status ) {
			$args['status'] = $status;
		} else {
			$args['exclude_status'] = array( Appointment::PENDING_PAYMENT, Appointment::EXPIRED );
		}
		if ( 'upcoming' === $when ) {
			$args['from'] = Time::now()->setTime( 0, 0 )->format( Time::DB );
		} elseif ( 'past' === $when ) {
			$args['to'] = Time::now_db();
		}
		$items = Appointment::query( $args );
		if ( 'balance' === $status ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $a ) {
						return $a->balance_due() > 0;
					}
				)
			);
		}
		$has_more = count( $items ) > $per;
		$items    = array_slice( $items, 0, $per );
		self::view( 'list', compact( 'items', 'status', 'search', 'when', 'paged', 'has_more' ) );
	}

	public static function page_appointment() {
		$a = Appointment::get( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $a ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Prenotazione non trovata.', 'photo-booking' ) . '</p></div>';
			return;
		}
		Payments::recalc( $a );
		self::view(
			'appointment',
			array(
				'a'         => $a,
				'proposals' => $a->proposals( null ),
				'outcome'   => Policy::cancel_outcome( $a ),
				'orders'    => array_filter( array_map( 'wc_get_order', Payments::order_ids( $a ) ) ),
				'packages'  => Package::all(),
			)
		);
	}

	public static function page_new() {
		self::view(
			'new',
			array(
				'packages' => Package::all(),
				'start'    => sanitize_text_field( wp_unslash( $_GET['start'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			)
		);
	}

	public static function page_deliveries() {
		$customer = absint( $_GET['customer'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$status   = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$list     = Deliveries::query(
			array(
				'customer_id' => $customer,
				'status'      => $status ? $status : array( 'active', 'expired', 'uploading' ),
				'limit'       => 200,
			)
		);
		$prefill = null;
		if ( ! empty( $_GET['appointment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$prefill = Appointment::get( absint( $_GET['appointment'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		self::view(
			'deliveries',
			array(
				'list'       => $list,
				'configured' => R2::is_configured(),
				'prefill'    => $prefill,
			)
		);
	}

	public static function page_settings() {
		self::view(
			'settings',
			array(
				's'     => Settings::all(),
				'pages' => get_pages(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                            */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! self::is_pb_screen() ) {
			return;
		}
		$msg = get_transient( 'pb_notice_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'pb_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msg[0] ), wp_kses_post( $msg[1] ) );
		}
		if ( ! Settings::get( 'booking_page_id' ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Photo Booking: imposta la pagina di prenotazione (con lo shortcode <code>[prenotazione]</code>) nelle <a href="%s">impostazioni</a>.', 'photo-booking' ), esc_url( admin_url( 'admin.php?page=pb-settings' ) ) ) ) . '</p></div>';
		}
	}

	private static function notice( $type, $text ) {
		set_transient( 'pb_notice_' . get_current_user_id(), array( $type, $text ), 60 );
	}

	/* ------------------------------------------------------------------ */
	/* Actions (admin-post.php?action=pb_admin)                           */
	/* ------------------------------------------------------------------ */

	private static function dt_from_input( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = str_replace( 'T', ' ', $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}
		return Time::to_db( $value ) ?: '';
	}

	public static function handle() {
		$do = sanitize_key( $_POST['do'] ?? '' );
		if ( ! current_user_can( 'settings' === $do ? 'manage_options' : Install::CAP ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'photo-booking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pb_admin_' . $do );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=pb-calendar' );
		$post = wp_unslash( $_POST );

		$a  = ! empty( $post['id'] ) ? Appointment::get( absint( $post['id'] ) ) : null;
		$r  = null;
		$ok = '';
		if ( null === $a && in_array( $do, array( 'confirm', 'reject', 'propose', 'move', 'cancel', 'complete', 'cash', 'payment_link', 'edit' ), true ) ) {
			self::notice( 'error', esc_html__( 'Prenotazione non trovata.', 'photo-booking' ) );
			wp_safe_redirect( $back );
			exit;
		}

		switch ( $do ) {
			case 'confirm':
				$r = Bookings::confirm( $a, sanitize_textarea_field( $post['message'] ?? '' ) );
				$ok = __( 'Prenotazione confermata, email inviata al cliente.', 'photo-booking' );
				break;

			case 'reject':
				$r  = Bookings::reject( $a, 'credit' === ( $post['outcome'] ?? '' ) ? 'credit' : 'refund', sanitize_textarea_field( $post['message'] ?? '' ) );
				$ok = __( 'Richiesta rifiutata.', 'photo-booking' );
				break;

			case 'propose':
				$starts = array_map( array( __CLASS__, 'dt_from_input' ), (array) ( $post['starts'] ?? array() ) );
				$r      = Bookings::propose( $a, array_values( array_filter( $starts ) ), sanitize_textarea_field( $post['message'] ?? '' ) );
				$ok     = __( 'Proposta inviata al cliente.', 'photo-booking' );
				break;

			case 'move':
				$r  = Bookings::reschedule(
					$a,
					self::dt_from_input( $post['start'] ?? '' ),
					'admin',
					array(
						'notify'  => ! empty( $post['notify'] ),
						'force'   => ! empty( $post['force'] ),
						'message' => sanitize_textarea_field( $post['message'] ?? '' ),
					)
				);
				$ok = __( 'Appuntamento spostato.', 'photo-booking' );
				break;

			case 'cancel':
				$outcome = in_array( $post['outcome'] ?? '', array( 'refund', 'credit', 'lost' ), true ) ? $post['outcome'] : 'refund';
				$r       = Bookings::cancel( $a, $outcome, 'admin', sanitize_textarea_field( $post['message'] ?? '' ) );
				$ok      = __( 'Appuntamento annullato.', 'photo-booking' );
				break;

			case 'complete':
				$r  = Bookings::complete( $a );
				$ok = __( 'Segnato come svolto.', 'photo-booking' );
				break;

			case 'cash':
				$r  = Bookings::cash( $a, (float) wc_format_decimal( $post['amount'] ?? 0 ), sanitize_text_field( $post['note'] ?? '' ), ! empty( $post['notify'] ) );
				$ok = __( 'Pagamento in contanti registrato.', 'photo-booking' );
				break;

			case 'payment_link':
				$order = Payments::balance_order( $a );
				if ( is_wp_error( $order ) ) {
					$r = $order;
				} else {
					$ok = sprintf( __( 'Link di pagamento del saldo: <input type="text" readonly class="large-text" onclick="this.select()" value="%s">', 'photo-booking' ), esc_attr( $order->get_checkout_payment_url() ) );
				}
				break;

			case 'edit':
				$changes = array(
					'customer_name'  => sanitize_text_field( $post['customer_name'] ?? $a->customer_name ),
					'customer_email' => sanitize_email( $post['customer_email'] ?? $a->customer_email ),
					'customer_phone' => sanitize_text_field( $post['customer_phone'] ?? $a->customer_phone ),
					'customer_notes' => sanitize_textarea_field( $post['customer_notes'] ?? '' ),
					'admin_notes'    => sanitize_textarea_field( $post['admin_notes'] ?? '' ),
				);
				if ( isset( $post['total'] ) && '' !== $post['total'] ) {
					$changes['total'] = (float) wc_format_decimal( $post['total'] );
				}
				$a->update( $changes );
				$a->log( 'edit', 'Dati modificati' );
				Payments::recalc( $a );
				Payments::sync_main_order_status( $a );
				$r  = true;
				$ok = __( 'Dati salvati.', 'photo-booking' );
				break;

			case 'new':
				$r    = self::create_manual( $post );
				$ok   = __( 'Appuntamento creato.', 'photo-booking' );
				if ( $r instanceof Appointment ) {
					$back = $r->admin_url();
				}
				break;

			case 'delivery_expiry':
				$r  = Deliveries::set_expiry( absint( $post['delivery'] ?? 0 ), sanitize_text_field( $post['expires'] ?? '' ) );
				$ok = __( 'Scadenza aggiornata.', 'photo-booking' );
				break;

			case 'delivery_delete':
				$r  = Deliveries::delete( absint( $post['delivery'] ?? 0 ) );
				$ok = __( 'Consegna eliminata (file rimosso da R2).', 'photo-booking' );
				break;

			case 'delivery_notify':
				$d  = Deliveries::get( absint( $post['delivery'] ?? 0 ) );
				$r  = $d ? \PhotoBooking\Emails::send( 'delivery_ready', $d ) : false;
				$r  = $r ? true : new \WP_Error( 'pb', __( 'Email non inviata (controlla che sia attiva in WooCommerce → Impostazioni → Email).', 'photo-booking' ) );
				$ok = __( 'Email inviata al cliente.', 'photo-booking' );
				break;

			case 'settings':
				self::save_settings( $post );
				$r  = true;
				$ok = __( 'Impostazioni salvate.', 'photo-booking' );
				break;

			case 'r2_test':
				$client = R2::from_settings();
				$r      = is_wp_error( $client ) ? $client : $client->test();
				$ok     = __( 'Connessione a Cloudflare R2 riuscita.', 'photo-booking' );
				break;

			case 'run_cron':
				Cron::run();
				$r  = true;
				$ok = __( 'Attività pianificate eseguite.', 'photo-booking' );
				break;

			default:
				$r = new \WP_Error( 'pb', 'Azione sconosciuta' );
		}

		if ( is_wp_error( $r ) ) {
			self::notice( 'error', esc_html( $r->get_error_message() ) );
		} else {
			$extra = '';
			if ( is_array( $r ) && isset( $r['outcome'] ) ) {
				$extra = ' ' . esc_html( Bookings::money_summary( $r ) ) . '.';
				if ( ! empty( $r['errors'] ) ) {
					self::notice( 'warning', $ok . $extra );
					wp_safe_redirect( $back );
					exit;
				}
			}
			self::notice( 'success', $ok . $extra );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * @return Appointment|\WP_Error
	 */
	private static function create_manual( array $post ) {
		$customer_id = absint( $post['customer_id'] ?? 0 );
		$name        = sanitize_text_field( $post['name'] ?? '' );
		$email       = sanitize_email( $post['email'] ?? '' );
		$phone       = sanitize_text_field( $post['phone'] ?? '' );
		if ( $customer_id ) {
			$u = get_userdata( $customer_id );
			if ( ! $u ) {
				return new \WP_Error( 'pb', __( 'Cliente non trovato.', 'photo-booking' ) );
			}
			$email = $email ? $email : $u->user_email;
			$name  = $name ? $name : trim( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) );
			$name  = $name ? $name : $u->display_name;
			$phone = $phone ? $phone : (string) get_user_meta( $u->ID, 'billing_phone', true );
		} else {
			if ( ! $name || ! is_email( $email ) ) {
				return new \WP_Error( 'pb', __( 'Seleziona un cliente esistente oppure inserisci nome ed email del nuovo cliente.', 'photo-booking' ) );
			}
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$customer_id = $existing->ID;
			} else {
				$parts       = explode( ' ', $name, 2 );
				$customer_id = wc_create_new_customer(
					$email,
					'',
					'',
					array(
						'first_name' => $parts[0],
						'last_name'  => $parts[1] ?? '',
					)
				);
				if ( is_wp_error( $customer_id ) ) {
					return $customer_id;
				}
				update_user_meta( $customer_id, 'billing_first_name', $parts[0] );
				update_user_meta( $customer_id, 'billing_last_name', $parts[1] ?? '' );
				update_user_meta( $customer_id, 'billing_email', $email );
				update_user_meta( $customer_id, 'billing_phone', $phone );
			}
		}
		$start = self::dt_from_input( ( $post['date'] ?? '' ) . ' ' . ( $post['time'] ?? '' ) );
		return Bookings::create_manual(
			array(
				'customer_id' => $customer_id,
				'name'        => $name,
				'email'       => $email,
				'phone'       => $phone,
				'product_id'  => absint( $post['product_id'] ?? 0 ),
				'start'       => $start,
				'status'      => sanitize_key( $post['status'] ?? 'confirmed' ),
				'payment'     => sanitize_key( $post['payment'] ?? 'none' ),
				'cash_amount' => wc_format_decimal( $post['cash_amount'] ?? '' ),
				'total'       => wc_format_decimal( $post['total'] ?? '' ),
				'notes'       => sanitize_textarea_field( $post['notes'] ?? '' ),
				'notify'      => ! empty( $post['notify'] ),
				'force'       => ! empty( $post['force'] ),
			)
		);
	}

	private static function save_settings( array $p ) {
		$int = static function ( $k, $min = 0 ) use ( $p ) {
			return max( $min, (int) ( $p[ $k ] ?? 0 ) );
		};
		$hours = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$hours[ $d ] = sanitize_text_field( $p['hours'][ $d ] ?? '' );
		}
		$values = array(
			'booking_page_id'        => absint( $p['booking_page_id'] ?? 0 ),
			'studio_address'         => sanitize_text_field( $p['studio_address'] ?? '' ),
			'business_name'          => sanitize_text_field( $p['business_name'] ?? '' ),
			'business_vat'           => sanitize_text_field( $p['business_vat'] ?? '' ),
			'business_address'       => sanitize_text_field( $p['business_address'] ?? '' ),
			'business_email'         => sanitize_email( $p['business_email'] ?? '' ),
			'business_phone'         => sanitize_text_field( $p['business_phone'] ?? '' ),
			'accent_color'           => sanitize_hex_color( $p['accent_color'] ?? '' ) ?: '#8a6d4b',
			'hours'                  => $hours,
			'slot_interval'          => $int( 'slot_interval' ),
			'min_notice_hours'       => $int( 'min_notice_hours' ),
			'max_advance_days'       => $int( 'max_advance_days', 1 ),
			'hold_minutes'           => $int( 'hold_minutes', 5 ),
			'proposal_hours'         => $int( 'proposal_hours', 1 ),
			'proposal_max_slots'     => min( 5, $int( 'proposal_max_slots', 1 ) ),
			'reschedule_min_hours'   => $int( 'reschedule_min_hours' ),
			'reschedule_max'         => $int( 'reschedule_max' ),
			'refund_min_days'        => $int( 'refund_min_days' ),
			'credit_min_hours'       => $int( 'credit_min_hours' ),
			'credit_validity_months' => $int( 'credit_validity_months', 1 ),
			'policy_text'            => sanitize_textarea_field( $p['policy_text'] ?? '' ),
			'reminder_enabled'       => empty( $p['reminder_enabled'] ) ? 0 : 1,
			'r2_account_id'          => sanitize_text_field( $p['r2_account_id'] ?? '' ),
			'r2_access_key'          => sanitize_text_field( $p['r2_access_key'] ?? '' ),
			'r2_bucket'              => sanitize_text_field( $p['r2_bucket'] ?? '' ),
			'r2_endpoint'            => esc_url_raw( $p['r2_endpoint'] ?? '' ),
			'r2_prefix'              => sanitize_text_field( $p['r2_prefix'] ?? '' ),
			'delivery_days'          => $int( 'delivery_days', 1 ),
			'delivery_warn_days'     => $int( 'delivery_warn_days' ),
			'delivery_purge_days'    => $int( 'delivery_purge_days' ),
			'download_link_minutes'  => $int( 'download_link_minutes', 1 ),
			'fullcalendar_src'       => esc_url_raw( $p['fullcalendar_src'] ?? '' ) ?: Settings::defaults()['fullcalendar_src'],
		);
		if ( ! empty( $p['r2_secret_key'] ) ) {
			$values['r2_secret_key'] = sanitize_text_field( $p['r2_secret_key'] );
		}
		Settings::update( $values );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers for views                                                  */
	/* ------------------------------------------------------------------ */

	public static function form_open( $do, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'; // phpcs:ignore
		echo '<input type="hidden" name="action" value="pb_admin"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'pb_admin_' . $do );
	}

	public static function badge( Appointment $a ) {
		$c = Appointment::status_colors();
		return '<span class="pb-status" style="--c:' . esc_attr( $c[ $a->status ] ?? '#888' ) . '">' . esc_html( $a->status_label() ) . '</span>';
	}

	public static function to_input( $db ) {
		$dt = Time::parse( $db );
		return $dt ? $dt->format( 'Y-m-d\TH:i' ) : '';
	}
}
