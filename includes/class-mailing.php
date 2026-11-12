<?php
/**
 * "Invio PDF": one generic email with a PDF attached, sent to the customers of chosen packages.
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Mailing {

	/**
	 * Every package on offer with its sub-packages, each with the customers who booked it (past and future,
	 * any status that is a real purchase). Customers who only registered never appear.
	 *
	 * @return array product_id => [ name, tiers => [ tier_key => [ name, rows => [ [id, name, email, date, status] ] ] ] ]
	 */
	public static function groups() {
		$groups = array();
		$seed   = static function ( $pid, $name ) use ( &$groups ) {
			if ( ! isset( $groups[ $pid ] ) ) {
				$groups[ $pid ] = array( 'name' => $name ?: __( 'Pacchetto non più in listino', 'photo-booking' ), 'tiers' => array() );
			}
		};
		foreach ( Package::all() as $product ) {
			$pid = (int) $product->get_id();
			$seed( $pid, $product->get_name() );
			foreach ( (array) ( Package::config( $product )['tiers'] ?? array() ) as $t ) {
				$groups[ $pid ]['tiers'][ (string) $t['key'] ] = array( 'name' => $t['name'], 'rows' => array(), 'emails' => array() );
			}
		}
		$add = static function ( $pid, $pname, $tier_key, $tier_name, array $row ) use ( &$groups, $seed ) {
			$seed( $pid, $pname );
			$tier_key = (string) $tier_key;
			if ( ! isset( $groups[ $pid ]['tiers'][ $tier_key ] ) ) {
				$groups[ $pid ]['tiers'][ $tier_key ] = array( 'name' => '' === $tier_key ? __( 'Senza sotto-pacchetto', 'photo-booking' ) : ( $tier_name ?: $tier_key ), 'rows' => array(), 'emails' => array() );
			}
			$key = strtolower( $row['email'] );
			if ( isset( $groups[ $pid ]['tiers'][ $tier_key ]['emails'][ $key ] ) ) {
				return; // One entry per customer per sub-package (the most recent booking).
			}
			$groups[ $pid ]['tiers'][ $tier_key ]['emails'][ $key ] = 1;
			$groups[ $pid ]['tiers'][ $tier_key ]['rows'][]         = $row;
		};
		// Every booking except the ones that never became a purchase (unpaid holds, expired) or were closed (rejected, cancelled).
		$list = Appointment::query(
			array(
				'exclude_status' => array( Appointment::PENDING_PAYMENT, Appointment::EXPIRED, Appointment::REJECTED, Appointment::CANCELLED ),
				'orderby'        => 'start_at',
				'order'          => 'DESC',
			)
		);
		foreach ( $list as $a ) {
			if ( ! is_email( $a->customer_email ) ) {
				continue;
			}
			$add(
				(int) $a->product_id,
				$a->package_name(),
				$a->get_meta( 'tier', '' ),
				$a->get_meta( 'tier_name', '' ),
				array( 'id' => $a->id, 'name' => $a->customer_name, 'email' => $a->customer_email, 'date' => $a->start_at, 'status' => $a->status_label() )
			);
		}
		// Paid WooCommerce orders for a package whose booking row is missing (e.g. deleted): the buyer is still a buyer.
		foreach ( self::order_only_buyers() as $pid => $rows ) {
			foreach ( $rows as $row ) {
				$p = wc_get_product( $pid );
				$add( $pid, $p ? $p->get_name() : '', $row['tier_key'], $row['tier_name'], $row );
			}
		}
		return $groups;
	}

	/**
	 * Buyers known only from WooCommerce orders (processing, completed or with deposit) of package products
	 * that have no booking row. Keyed by product id; rows carry a negative id (-order_id) as recipient key.
	 */
	public static function order_only_buyers() {
		$out = array();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 500, 'status' => array( 'wc-processing', 'wc-completed', 'wc-pb-deposit' ), 'orderby' => 'date', 'order' => 'DESC' ) );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || $order->get_parent_id() ) {
				continue; // Refunds and other order types are not purchases.
			}
			if ( Cart::appointment_from_order( $order ) ) {
				continue;
			}
			$email = $order->get_billing_email();
			if ( ! is_email( $email ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$pid     = $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0;
				if ( ! $pid || ! Package::config( $pid ) ) {
					continue;
				}
				// The chosen sub-package travels on the order line as "Pacchetto: <name>".
				$tier_name = (string) $item->get_meta( __( 'Pacchetto', 'photo-booking' ) );
				$tier_key  = '';
				foreach ( (array) ( Package::config( $pid )['tiers'] ?? array() ) as $t ) {
					if ( $tier_name && 0 === strcasecmp( $t['name'], $tier_name ) ) {
						$tier_key = (string) $t['key'];
						break;
					}
				}
				$out[ $pid ][] = array(
					'id'        => -$order->get_id(),
					'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $email,
					'email'     => $email,
					'date'      => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
					'tier_key'  => $tier_key,
					'tier_name' => $tier_name,
					'status'    => sprintf( __( 'Ordine #%d', 'photo-booking' ), $order->get_id() ),
				);
			}
		}
		return $out;
	}

	/**
	 * @param array $post recipients[] (appointment ids), subject, message
	 * @param array $file $_FILES['pdf']
	 * @return int|\WP_Error number of emails sent
	 */
	public static function send( array $post, array $file ) {
		$subject = sanitize_text_field( $post['subject'] ?? '' );
		$message = sanitize_textarea_field( $post['message'] ?? '' );
		$ids     = array_filter( array_map( 'intval', (array) ( $post['recipients'] ?? array() ) ) );
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
			if ( $id < 0 ) {
				// Buyer known only from a WooCommerce order: a temporary appointment object just for the email.
				$order = wc_get_order( -$id );
				if ( ! $order || ! is_email( $order->get_billing_email() ) ) {
					continue;
				}
				$a = new Appointment(
					array(
						'id'             => 0,
						'customer_id'    => $order->get_customer_id(),
						'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'customer_email' => $order->get_billing_email(),
						'start_at'       => Time::now_db(),
						'end_at'         => Time::now_db(),
						'meta'           => wp_json_encode( array() ),
					)
				);
			} else {
				$a = Appointment::get( $id );
			}
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
				if ( $a->id ) {
					$a->log( 'email', sprintf( 'Email "%s" inviata con il PDF %s', $subject, basename( $path ) ) );
				} elseif ( isset( $order ) ) {
					$order->add_order_note( sprintf( 'Email "%s" inviata con il PDF %s', $subject, basename( $path ) ) );
				}
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
