<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Photo deliveries stored on Cloudflare R2.
 *
 * Lifecycle: uploading → active → expired (not downloadable, admin can re-activate) → deleted (file purged).
 */
class Deliveries {

	const MIN_PART = 5242880; // 5 MiB (S3 minimum).

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_download' ) );
	}

	public static function status_labels() {
		return array(
			'uploading' => __( 'Caricamento', 'photo-booking' ),
			'active'    => __( 'Attiva', 'photo-booking' ),
			'expired'   => __( 'Scaduta', 'photo-booking' ),
			'deleted'   => __( 'Eliminata', 'photo-booking' ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$t = Install::table( 'deliveries' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( Install::table( 'deliveries' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * @param array $args customer_id, status, limit, offset, search
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$t     = Install::table( 'deliveries' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['customer_id'] ) ) {
			$where[] = 'customer_id = %d';
			$vals[]  = (int) $args['customer_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$st      = (array) $args['status'];
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $st );
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		if ( ! empty( $args['limit'] ) ) {
			$sql .= ' LIMIT ' . (int) $args['limit'] . ' OFFSET ' . (int) ( $args['offset'] ?? 0 );
		}
		return (array) ( $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore
	}

	/** Expiry as "end of day N days from now". */
	private static function expiry_from_days( $days ) {
		$days = $days > 0 ? $days : (int) Settings::get( 'delivery_days' );
		return Time::now()->modify( "+{$days} days" )->setTime( 23, 59, 59 )->format( Time::DB );
	}

	/* ------------------------------------------------------------------ */
	/* Upload (browser → R2 multipart, signed by the server)              */
	/* ------------------------------------------------------------------ */

	public static function part_size( $size ) {
		// Aim for <= 1000 parts, at least 16 MiB, max 512 MiB per part.
		$ps = max( 16 * 1048576, (int) ceil( $size / 1000 ) );
		$ps = (int) apply_filters( 'pb_delivery_part_size', min( $ps, 512 * 1048576 ), $size );
		return max( self::MIN_PART, $ps );
	}

	/**
	 * @return array|\WP_Error id, upload_id, part_size, parts
	 */
	public static function start_upload( array $a ) {
		global $wpdb;
		$user = get_userdata( (int) $a['customer_id'] );
		if ( ! $user ) {
			return new \WP_Error( 'pb_customer', __( 'Seleziona un cliente.', 'photo-booking' ) );
		}
		if ( $a['size'] <= 0 || '' === $a['filename'] ) {
			return new \WP_Error( 'pb_file', __( 'File non valido.', 'photo-booking' ) );
		}
		if ( $a['size'] > 50 * 1073741824 ) {
			return new \WP_Error( 'pb_file', __( 'File troppo grande (max 50 GB).', 'photo-booking' ) );
		}
		$r2 = R2::from_settings();
		if ( is_wp_error( $r2 ) ) {
			return $r2;
		}
		$title = $a['title'] ? $a['title'] : preg_replace( '/\.zip$/i', '', $a['filename'] );
		$key   = trim( (string) Settings::get( 'r2_prefix' ), '/' );
		$key   = ( $key ? $key . '/' : '' ) . $user->ID . '/' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '-' . sanitize_file_name( $a['filename'] );

		$upload_id = $r2->create_multipart( $key, 'application/zip' );
		if ( is_wp_error( $upload_id ) ) {
			return $upload_id;
		}
		$wpdb->insert(
			Install::table( 'deliveries' ),
			array(
				'customer_id'    => $user->ID,
				'appointment_id' => (int) $a['appointment_id'],
				'title'          => $title,
				'object_key'     => $key,
				'filename'       => $a['filename'],
				'size'           => (int) $a['size'],
				'upload_id'      => $upload_id,
				'status'         => 'uploading',
				'expires_at'     => self::expiry_from_days( (int) $a['days'] ),
				'created_by'     => get_current_user_id(),
				'created_at'     => Time::now_db(),
			)
		);
		$id = (int) $wpdb->insert_id;
		update_option( 'pb_delivery_notify_' . $id, ! empty( $a['notify'] ) ? 1 : 0, false );
		$ps = self::part_size( (int) $a['size'] );
		return array(
			'id'        => $id,
			'part_size' => $ps,
			'parts'     => (int) ceil( $a['size'] / $ps ),
		);
	}

	public static function presign_parts( $id, array $numbers ) {
		$d = self::get( $id );
		if ( ! $d || 'uploading' !== $d['status'] ) {
			return new \WP_Error( 'pb_delivery', __( 'Caricamento non trovato.', 'photo-booking' ) );
		}
		$r2 = R2::from_settings();
		if ( is_wp_error( $r2 ) ) {
			return $r2;
		}
		$urls = array();
		foreach ( array_slice( array_unique( $numbers ), 0, 100 ) as $n ) {
			if ( $n >= 1 && $n <= 10000 ) {
				$urls[ $n ] = $r2->presign_part( $d['object_key'], $d['upload_id'], $n, 3 * HOUR_IN_SECONDS );
			}
		}
		return array( 'urls' => $urls );
	}

	/**
	 * @param array $parts [ ['n' => 1, 'etag' => '"..."'], ... ]
	 */
	public static function complete_upload( $id, array $parts ) {
		$d = self::get( $id );
		if ( ! $d || 'uploading' !== $d['status'] ) {
			return new \WP_Error( 'pb_delivery', __( 'Caricamento non trovato.', 'photo-booking' ) );
		}
		$r2 = R2::from_settings();
		if ( is_wp_error( $r2 ) ) {
			return $r2;
		}
		$list = array();
		foreach ( $parts as $p ) {
			$list[] = array(
				'PartNumber' => (int) ( $p['n'] ?? 0 ),
				'ETag'       => (string) ( $p['etag'] ?? '' ),
			);
		}
		$res = $r2->complete_multipart( $d['object_key'], $d['upload_id'], $list );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$size = $r2->head( $d['object_key'] );
		if ( is_wp_error( $size ) ) {
			return $size;
		}
		if ( (int) $size !== (int) $d['size'] ) {
			return new \WP_Error( 'pb_size', sprintf( 'Dimensione non corrispondente (%d / %d byte).', $size, $d['size'] ) );
		}
		self::update(
			$id,
			array(
				'status'      => 'active',
				'upload_id'   => '',
				'uploaded_at' => Time::now_db(),
			)
		);
		$d = self::get( $id );
		if ( get_option( 'pb_delivery_notify_' . $id ) ) {
			Emails::send( 'delivery_ready', $d );
		}
		delete_option( 'pb_delivery_notify_' . $id );
		return array( 'ok' => true, 'delivery' => $d );
	}

	public static function abort_upload( $id ) {
		$d = self::get( $id );
		if ( ! $d ) {
			return new \WP_Error( 'pb_delivery', __( 'Caricamento non trovato.', 'photo-booking' ) );
		}
		if ( 'uploading' === $d['status'] && $d['upload_id'] ) {
			$r2 = R2::from_settings();
			if ( ! is_wp_error( $r2 ) ) {
				$r2->abort_multipart( $d['object_key'], $d['upload_id'] );
			}
		}
		global $wpdb;
		$wpdb->delete( Install::table( 'deliveries' ), array( 'id' => (int) $id ) );
		delete_option( 'pb_delivery_notify_' . $id );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ */
	/* Admin operations                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Change expiry. A future date re-activates an expired delivery (if the file still exists).
	 */
	public static function set_expiry( $id, $date ) {
		$d = self::get( $id );
		if ( ! $d || 'deleted' === $d['status'] || 'uploading' === $d['status'] ) {
			return new \WP_Error( 'pb_delivery', __( 'Consegna non modificabile.', 'photo-booking' ) );
		}
		if ( ! Time::is_valid_date( $date ) ) {
			return new \WP_Error( 'pb_date', __( 'Data non valida.', 'photo-booking' ) );
		}
		$exp  = $date . ' 23:59:59';
		$data = array(
			'expires_at' => $exp,
			'warned'     => 0,
		);
		if ( $exp > Time::now_db() ) {
			$data['status'] = 'active';
		} else {
			$data['status'] = 'expired';
		}
		self::update( $id, $data );
		return true;
	}

	public static function delete( $id ) {
		$d = self::get( $id );
		if ( ! $d ) {
			return new \WP_Error( 'pb_delivery', __( 'Consegna non trovata.', 'photo-booking' ) );
		}
		if ( 'uploading' === $d['status'] ) {
			return self::abort_upload( $id );
		}
		$r2 = R2::from_settings();
		if ( is_wp_error( $r2 ) ) {
			return $r2;
		}
		$res = $r2->delete( $d['object_key'] );
		if ( is_wp_error( $res ) && 404 !== (int) ( $res->get_error_data()['code'] ?? 0 ) ) {
			return $res;
		}
		self::update(
			$id,
			array(
				'status'     => 'deleted',
				'deleted_at' => Time::now_db(),
			)
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Customer download                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * /my-account/consegne/?pb_download=ID&_wpnonce=... → authorization check → redirect to a short-lived signed URL.
	 */
	public static function maybe_download() {
		if ( empty( $_GET['pb_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = absint( $_GET['pb_download'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( wc_get_account_endpoint_url( Account::EP_DELIVERIES ) ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'pb_download_' . $id ) ) {
			wp_die( esc_html__( 'Link non valido o scaduto. Torna alla tua area privata e riprova.', 'photo-booking' ), '', array( 'response' => 403 ) );
		}
		$d = self::get( $id );
		// Server-side authorization: owner (or admin) + active + not expired.
		$is_admin = current_user_can( Install::CAP );
		if ( ! $d || ( (int) $d['customer_id'] !== get_current_user_id() && ! $is_admin ) ) {
			wp_die( esc_html__( 'Consegna non trovata.', 'photo-booking' ), '', array( 'response' => 404 ) );
		}
		if ( ! $is_admin && ( 'active' !== $d['status'] || ( $d['expires_at'] && $d['expires_at'] < Time::now_db() ) ) ) {
			wp_die( esc_html__( 'Questa consegna è scaduta. Contatta lo studio.', 'photo-booking' ), '', array( 'response' => 410 ) );
		}
		$r2 = R2::from_settings();
		if ( is_wp_error( $r2 ) ) {
			wp_die( esc_html( $r2->get_error_message() ) );
		}
		if ( ! $is_admin ) {
			global $wpdb;
			$t = Install::table( 'deliveries' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET downloads = downloads + 1, last_download_at = %s WHERE id = %d", Time::now_db(), $id ) ); // phpcs:ignore
		}
		$url = $r2->download_url( $d['object_key'], $d['filename'], max( 1, (int) Settings::get( 'download_link_minutes' ) ) * MINUTE_IN_SECONDS );
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- external R2 host.
		exit;
	}

	public static function download_link( array $d ) {
		return wp_nonce_url( add_query_arg( 'pb_download', $d['id'], wc_get_account_endpoint_url( Account::EP_DELIVERIES ) ), 'pb_download_' . $d['id'] );
	}

	/* ------------------------------------------------------------------ */
	/* Cron                                                               */
	/* ------------------------------------------------------------------ */

	public static function cron() {
		global $wpdb;
		$t   = Install::table( 'deliveries' );
		$now = Time::now();

		// Warn N days before expiry.
		$warn = $now->modify( '+' . (int) Settings::get( 'delivery_warn_days' ) . ' days' )->format( Time::DB );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'active' AND warned = 0 AND expires_at <= %s AND expires_at > %s", $warn, $now->format( Time::DB ) ), ARRAY_A ) as $d ) { // phpcs:ignore
			self::update( $d['id'], array( 'warned' => 1 ) );
			Emails::send( 'delivery_expiring', $d );
		}

		// Expire.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'active' AND expires_at <= %s", $now->format( Time::DB ) ), ARRAY_A ) as $d ) { // phpcs:ignore
			self::update( $d['id'], array( 'status' => 'expired' ) );
			Emails::send( 'delivery_expired', self::get( $d['id'] ) );
		}

		// Purge files N days after expiry.
		$purge = $now->modify( '-' . (int) Settings::get( 'delivery_purge_days' ) . ' days' )->format( Time::DB );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'expired' AND expires_at <= %s", $purge ), ARRAY_A ) as $d ) { // phpcs:ignore
			self::delete( $d['id'] );
		}

		// Abandoned uploads (> 2 days).
		$old = $now->modify( '-2 days' )->format( Time::DB );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'uploading' AND created_at < %s", $old ), ARRAY_A ) as $d ) { // phpcs:ignore
			self::abort_upload( $d['id'] );
		}
	}
}
