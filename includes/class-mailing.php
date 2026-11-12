<?php
/**
 * "Invio PDF": one generic email with a PDF attached, sent to the customers of chosen packages.
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Mailing {

	/**
	 * Customers with a live booking, grouped by package.
	 *
	 * @param bool $past Include sessions already held (otherwise only from today on).
	 * @return array product_id => [ name, rows => [ [id, name, email, date, tier, status] ] ]
	 */
	public static function groups( $past = false ) {
		$args = array(
			'status'  => array( Appointment::PENDING, Appointment::PROPOSED, Appointment::CONFIRMED, Appointment::COMPLETED ),
			'orderby' => 'start_at',
		);
		if ( ! $past ) {
			$args['from'] = Time::now()->setTime( 0, 0 )->format( Time::DB );
		}
		$groups = array();
		foreach ( Appointment::query( $args ) as $a ) {
			if ( ! is_email( $a->customer_email ) ) {
				continue;
			}
			$pid = (int) $a->product_id;
			if ( ! isset( $groups[ $pid ] ) ) {
				$product         = $a->product();
				$groups[ $pid ]  = array( 'name' => $product ? $product->get_name() : $a->package_name(), 'rows' => array(), 'emails' => array() );
			}
			$key = strtolower( $a->customer_email );
			if ( isset( $groups[ $pid ]['emails'][ $key ] ) ) {
				continue; // One email per customer per package.
			}
			$groups[ $pid ]['emails'][ $key ] = 1;
			$groups[ $pid ]['rows'][]         = array(
				'id'     => $a->id,
				'name'   => $a->customer_name,
				'email'  => $a->customer_email,
				'date'   => $a->start_at,
				'tier'   => $a->get_meta( 'tier_name', '' ),
				'status' => $a->status_label(),
			);
		}
		uasort(
			$groups,
			static function ( $x, $y ) {
				return strcasecmp( $x['name'], $y['name'] );
			}
		);
		return $groups;
	}

	/**
	 * @param array $post recipients[] (appointment ids), subject, message
	 * @param array $file $_FILES['pdf']
	 * @return int|\WP_Error number of emails sent
	 */
	public static function send( array $post, array $file ) {
		$subject = sanitize_text_field( $post['subject'] ?? '' );
		$message = sanitize_textarea_field( $post['message'] ?? '' );
		$ids     = array_filter( array_map( 'absint', (array) ( $post['recipients'] ?? array() ) ) );
		if ( ! $subject || ! $message ) {
			return new \WP_Error( 'pb', __( 'Scrivi oggetto e testo dell\'email.', 'photo-booking' ) );
		}
		if ( ! $ids ) {
			return new \WP_Error( 'pb', __( 'Seleziona almeno un cliente.', 'photo-booking' ) );
		}
		$path = self::store_pdf( $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$sent  = 0;
		$seen  = array();
		$extra = array( 'subject' => $subject, 'message' => $message, 'attachment' => $path );
		foreach ( $ids as $id ) {
			$a = Appointment::get( $id );
			if ( ! $a || ! is_email( $a->customer_email ) ) {
				continue;
			}
			$key = strtolower( $a->customer_email );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = 1;
			if ( Emails::send( 'bulk', $a, $extra ) ) {
				$sent++;
				$a->log( 'email', sprintf( 'Email "%s" inviata con il PDF %s', $subject, basename( $path ) ) );
			}
		}
		if ( ! $sent ) {
			return new \WP_Error( 'pb', __( 'Nessuna email inviata: controlla che l\'email "Comunicazione ai clienti con PDF" sia attiva in WooCommerce → Impostazioni → Email.', 'photo-booking' ) );
		}
		update_option( 'pb_last_mailing', array( 'subject' => $subject, 'file' => basename( $path ), 'sent' => $sent, 'at' => Time::now_db() ), false );
		return $sent;
	}

	/** Saves the uploaded PDF under uploads/photo-booking/mailings (PDF only). */
	private static function store_pdf( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new \WP_Error( 'pb', __( 'Carica il PDF da allegare.', 'photo-booking' ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );
		if ( 'pdf' !== $check['ext'] ) {
			return new \WP_Error( 'pb', __( 'Il file deve essere un PDF.', 'photo-booking' ) );
		}
		if ( $file['size'] > 10 * MB_IN_BYTES ) {
			return new \WP_Error( 'pb', __( 'Il PDF supera i 10 MB.', 'photo-booking' ) );
		}
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'photo-booking/mailings';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name = wp_unique_filename( $dir, sanitize_file_name( $file['name'] ) );
		$dest = $dir . '/' . $name;
		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) && ! @copy( $file['tmp_name'], $dest ) ) { // phpcs:ignore
			return new \WP_Error( 'pb', __( 'Impossibile salvare il PDF.', 'photo-booking' ) );
		}
		return $dest;
	}
}
