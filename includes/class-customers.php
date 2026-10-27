<?php
/**
 * Customers as the studio sees them: WordPress users with the data the booking uses
 * (name, email, phone) plus their bookings, with or without an appointment.
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Customers {

	/** Roles that count as customers of the studio. */
	public static function roles() {
		return array( 'customer', 'subscriber' );
	}

	/** One row for the lists: id, name, email, phone, bookings, last (start_at of the latest booking), registered. */
	public static function row( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return null;
		}
		return self::row_from_user( $u, self::booking_summaries( array( $u->ID ) ) );
	}

	private static function row_from_user( \WP_User $u, array $summaries ) {
		$name = trim( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) );
		$s    = $summaries[ $u->ID ] ?? array( 'bookings' => 0, 'last' => '', 'paid' => 0 );
		return array(
			'id'         => $u->ID,
			'name'       => $name ? $name : $u->display_name,
			'email'      => $u->user_email,
			'phone'      => (string) get_user_meta( $u->ID, 'billing_phone', true ),
			'registered' => $u->user_registered,
			'bookings'   => (int) $s['bookings'],
			'last'       => $s['last'],
			'paid'       => (float) $s['paid'],
		);
	}

	/** Bookings count, latest date and total paid per user (closed bookings excluded from the count). */
	private static function booking_summaries( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$t    = Install::table( 'appointments' );
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( "SELECT customer_id, COUNT(*) AS bookings, MAX(start_at) AS last, SUM(paid) AS paid FROM {$t} WHERE customer_id IN ({$in}) AND status NOT IN ('pending_payment','expired','rejected','cancelled') GROUP BY customer_id", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['customer_id'] ] = array( 'bookings' => (int) $r['bookings'], 'last' => (string) $r['last'], 'paid' => (float) $r['paid'] );
		}
		return $out;
	}

	/**
	 * @return array rows, total
	 */
	public static function search( $q = '', $limit = 40, $offset = 0 ) {
		$args = array(
			'role__in' => self::roles(),
			'number'   => $limit,
			'offset'   => $offset,
			'orderby'  => 'registered',
			'order'    => 'DESC',
		);
		$ids  = array();
		if ( '' !== $q ) {
			$by_user = get_users( array( 'role__in' => self::roles(), 'search' => '*' . $q . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'fields' => 'ID', 'number' => 200 ) );
			$by_meta = get_users(
				array(
					'role__in'   => self::roles(),
					'fields'     => 'ID',
					'number'     => 200,
					'meta_query' => array( // phpcs:ignore
						'relation' => 'OR',
						array( 'key' => 'billing_first_name', 'value' => $q, 'compare' => 'LIKE' ),
						array( 'key' => 'billing_last_name', 'value' => $q, 'compare' => 'LIKE' ),
						array( 'key' => 'billing_phone', 'value' => $q, 'compare' => 'LIKE' ),
					),
				)
			);
			$ids = array_values( array_unique( array_map( 'intval', array_merge( $by_user, $by_meta ) ) ) );
			if ( ! $ids ) {
				return array( 'rows' => array(), 'total' => 0 );
			}
			$args['include'] = $ids;
		}
		$query = new \WP_User_Query( $args + array( 'count_total' => true ) );
		$users = $query->get_results();
		$sum   = self::booking_summaries( wp_list_pluck( $users, 'ID' ) );
		$rows  = array();
		foreach ( $users as $u ) {
			$rows[] = self::row_from_user( $u, $sum );
		}
		return array( 'rows' => $rows, 'total' => (int) $query->get_total() );
	}

	/**
	 * Creates a customer account from the admin, without any appointment.
	 *
	 * @param array $post first_name, last_name, email, phone, notes, welcome
	 * @return int|\WP_Error user id
	 */
	public static function create( array $post ) {
		$first = sanitize_text_field( $post['first_name'] ?? '' );
		$last  = sanitize_text_field( $post['last_name'] ?? '' );
		$email = sanitize_email( $post['email'] ?? '' );
		$phone = sanitize_text_field( $post['phone'] ?? '' );
		if ( ! $first || ! is_email( $email ) ) {
			return new \WP_Error( 'pb', __( 'Nome ed email sono obbligatori.', 'photo-booking' ) );
		}
		if ( email_exists( $email ) ) {
			return new \WP_Error( 'pb', __( 'Esiste già un cliente con questa email.', 'photo-booking' ) );
		}
		$password = wp_generate_password( 16, true );
		// WooCommerce would send its own "account created" email (without the password link): we send the right one below.
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		$id = wc_create_new_customer( $email, '', $password, array( 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );
		remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( array( 'billing_first_name' => $first, 'billing_last_name' => $last, 'billing_email' => $email, 'billing_phone' => $phone, 'first_name' => $first, 'last_name' => $last ) as $k => $v ) {
			update_user_meta( $id, $k, $v );
		}
		if ( ! empty( $post['notes'] ) ) {
			update_user_meta( $id, 'description', sanitize_textarea_field( $post['notes'] ) );
		}
		update_user_meta( $id, 'pb_created_by', get_current_user_id() );
		if ( ! empty( $post['welcome'] ) ) {
			self::send_welcome( $id );
		}
		return $id;
	}

	/** WooCommerce "new account" email with the link to choose a password. */
	public static function send_welcome( $user_id ) {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['WC_Email_Customer_New_Account'] ?? null;
		if ( ! $email ) {
			return false;
		}
		$email->trigger( $user_id, wp_generate_password( 16, true ), true );
		return true;
	}
}
