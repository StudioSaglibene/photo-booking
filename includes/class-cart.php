<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce cart / checkout / order integration.
 */
class Cart {

	public static function init() {
		// Cart.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_holds' ) );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'release_on_remove' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'fixed_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'item_permalink' ), 10, 2 );

		// Checkout.
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_account' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'enable_registration' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'apply_credits' ), 5 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'checkout_notice' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'link_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link_order' ) );

		// Payment.
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'paid_order_status' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'order_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'order_paid' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'order_paid' ), 20 );
		add_action( 'woocommerce_order_status_pb-deposit', array( __CLASS__, 'order_paid' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'order_cancelled' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'order_refunded' ), 20 );

		// Order statuses.
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'payment_complete_from' ) );

		// Suppress default WooCommerce emails for booking orders (Photo Booking sends its own).
		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'new_order' ) as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'suppress_wc_email' ), 10, 2 );
		}

		// Thank you page.
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'thankyou' ), 5 );

		// Order admin: show the link to the booking.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_order_box' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Cart                                                               */
	/* ------------------------------------------------------------------ */

	public static function block_direct_add( $passed, $product_id ) {
		if ( Package::is_package( $product_id ) && ! did_action( 'pb_adding_booking_to_cart' ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s link */
					__( 'Le sessioni fotografiche si prenotano dal modulo di prenotazione: <a href="%s">scegli data e orario</a>.', 'photo-booking' ),
					esc_url( Settings::booking_page_url( array( 'pacchetto' => $product_id ) ) )
				),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/** Add the held appointment to the cart (empties it first: one booking per checkout). */
	public static function add_booking( Appointment $appt ) {
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		do_action( 'pb_adding_booking_to_cart' );
		$key = WC()->cart->add_to_cart(
			$appt->product_id,
			1,
			0,
			array(),
			array(
				'pb_appointment_id' => $appt->id,
				'pb_mode'           => $appt->payment_mode,
			)
		);
		self::apply_credits();
		return $key;
	}

	public static function cart_has_booking() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['pb_appointment_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sum of PB credit coupons applied to the cart. */
	private static function applied_credit() {
		$sum = 0;
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			if ( $coupon->get_meta( '_pb_credit' ) ) {
				$sum += (float) $coupon->get_amount();
			}
		}
		return $sum;
	}

	/**
	 * Line price = deposit or full price. If the customer has credit, the online line is raised up to the
	 * credit (max full price) so the credit is not wasted on the deposit only.
	 */
	public static function set_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['pb_appointment_id'] ) ) {
				continue;
			}
			$appt = Appointment::get( $item['pb_appointment_id'] );
			if ( ! $appt ) {
				continue;
			}
			$price = 'full' === $appt->payment_mode ? $appt->total : $appt->deposit;
			if ( 'full' !== $appt->payment_mode ) {
				$credit = self::applied_credit();
				if ( $credit > $price ) {
					$price = min( $appt->total, $credit );
				}
			}
			$item['data']->set_price( $price );
		}
	}

	public static function item_data( $data, $item ) {
		if ( empty( $item['pb_appointment_id'] ) ) {
			return $data;
		}
		$appt = Appointment::get( $item['pb_appointment_id'] );
		if ( ! $appt ) {
			return $data;
		}
		if ( $appt->get_meta( 'tier_name' ) ) {
			$data[] = array(
				'key'   => __( 'Pacchetto', 'photo-booking' ),
				'value' => $appt->get_meta( 'tier_name' ),
			);
		}
		if ( $appt->extras_label() ) {
			$data[] = array(
				'key'   => __( 'Extra', 'photo-booking' ),
				'value' => $appt->extras_label(),
			);
		}
		$data[] = array(
			'key'   => __( 'Data', 'photo-booking' ),
			'value' => ucfirst( Time::format_date( $appt->start_at ) ),
		);
		$data[] = array(
			'key'   => __( 'Orario', 'photo-booking' ),
			'value' => Time::format_time( $appt->start_at ) . ' – ' . Time::format_time( $appt->end_at ),
		);
		if ( 'full' === $appt->payment_mode ) {
			$data[] = array(
				'key'   => __( 'Pagamento', 'photo-booking' ),
				'value' => __( 'Intero importo', 'photo-booking' ),
			);
		} else {
			$data[] = array(
				'key'     => __( 'Pagamento', 'photo-booking' ),
				'value'   => sprintf( __( 'Acconto – totale %1$s, il saldo si paga dopo online o in studio', 'photo-booking' ), wp_strip_all_tags( wc_price( $appt->total ) ) ),
				'display' => sprintf( __( 'Acconto – totale %1$s, il saldo si paga dopo online o in studio', 'photo-booking' ), wc_price( $appt->total ) ),
			);
		}
		if ( $appt->hold_expires_at && Appointment::PENDING_PAYMENT === $appt->status ) {
			$data[] = array(
				'key'   => __( 'Orario riservato fino alle', 'photo-booking' ),
				'value' => Time::format_time( $appt->hold_expires_at ),
			);
		}
		return $data;
	}

	/**
	 * On cart/checkout: make sure every booking still has a valid hold; refresh if expired but still free.
	 */
	public static function check_holds() {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['pb_appointment_id'] ) ) {
				continue;
			}
			$appt = Appointment::get( $item['pb_appointment_id'] );
			$ok   = $appt && in_array( $appt->status, array( Appointment::PENDING_PAYMENT, Appointment::EXPIRED ), true ) && ! $appt->order_id;
			if ( $ok && ( Appointment::EXPIRED === $appt->status || ( $appt->hold_expires_at && $appt->hold_expires_at < Time::now_db() ) ) ) {
				$ok = Bookings::refresh_hold( $appt );
			}
			if ( $appt && $appt->order_id ) {
				$order = wc_get_order( $appt->order_id );
				$ok    = $order && $order->has_status( array( 'pending', 'failed' ) ) && Bookings::refresh_hold( $appt, 60 );
				if ( $ok ) {
					// Previous attempt failed: let the customer retry with a fresh order.
					$appt->update( array( 'order_id' => 0 ) );
				}
			}
			if ( ! $ok ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice(
					sprintf(
						/* translators: %s link */
						__( 'L\'orario che avevi scelto non è più disponibile. <a href="%s">Scegline un altro</a>.', 'photo-booking' ),
						esc_url( Settings::booking_page_url() )
					),
					'error'
				);
			}
		}
	}

	public static function release_on_remove( $key, $cart ) {
		$item = $cart->removed_cart_contents[ $key ] ?? ( $cart->cart_contents[ $key ] ?? null );
		if ( ! $item || empty( $item['pb_appointment_id'] ) ) {
			return;
		}
		$appt = Appointment::get( $item['pb_appointment_id'] );
		if ( $appt && Appointment::PENDING_PAYMENT === $appt->status && ! $appt->order_id ) {
			$appt->update( array( 'status' => Appointment::EXPIRED ) );
			$appt->log( 'expired', 'Rimosso dal carrello' );
		}
	}

	public static function fixed_quantity( $html, $key, $item ) {
		return ! empty( $item['pb_appointment_id'] ) ? '1' : $html;
	}

	public static function item_permalink( $link, $item ) {
		return ! empty( $item['pb_appointment_id'] ) ? '' : $link;
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                           */
	/* ------------------------------------------------------------------ */

	public static function require_account( $required ) {
		return self::cart_has_booking() ? true : $required;
	}

	public static function enable_registration( $enabled ) {
		return self::cart_has_booking() ? true : $enabled;
	}

	public static function prefill( $value, $input ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		$data = WC()->session ? WC()->session->get( 'pb_customer' ) : null;
		if ( ! $data ) {
			return $value;
		}
		$map = array(
			'billing_first_name' => 'first_name',
			'billing_last_name'  => 'last_name',
			'billing_email'      => 'email',
			'billing_phone'      => 'phone',
		);
		return isset( $map[ $input ] ) ? ( $data[ $map[ $input ] ] ?? $value ) : $value;
	}

	/** Apply the customer's credit coupons automatically. */
	public static function apply_credits() {
		if ( ! is_user_logged_in() || ! WC()->cart || ! self::cart_has_booking() ) {
			return;
		}
		foreach ( Payments::credits_for( get_current_user_id() ) as $coupon ) {
			if ( ! WC()->cart->has_discount( $coupon->get_code() ) ) {
				WC()->cart->apply_coupon( $coupon->get_code() );
			}
		}
	}

	public static function checkout_notice() {
		if ( ! self::cart_has_booking() ) {
			return;
		}
		echo '<div class="pb-checkout-note"><strong>' . esc_html__( 'Come funziona', 'photo-booking' ) . '</strong><p>' . esc_html__( 'Dopo il pagamento la tua richiesta arriva allo studio, che la confermerà al più presto. Se non fosse possibile, ti proporremo un\'altra data oppure ti rimborseremo l\'intero importo.', 'photo-booking' ) . '</p></div>';
	}

	public static function order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['pb_appointment_id'] ) ) {
			return;
		}
		$appt = Appointment::get( $values['pb_appointment_id'] );
		$item->add_meta_data( '_pb_appointment_id', (int) $values['pb_appointment_id'], true );
		if ( $appt ) {
			if ( $appt->get_meta( 'tier_name' ) ) {
				$item->add_meta_data( __( 'Pacchetto', 'photo-booking' ), $appt->get_meta( 'tier_name' ), true );
			}
			if ( $appt->extras_label() ) {
				$item->add_meta_data( __( 'Extra', 'photo-booking' ), $appt->extras_label(), true );
			}
			$item->add_meta_data( __( 'Appuntamento', 'photo-booking' ), Time::format_datetime( $appt->start_at ), true );
			$item->add_meta_data( __( 'Pagamento', 'photo-booking' ), 'full' === $appt->payment_mode ? __( 'Intero importo', 'photo-booking' ) : __( 'Acconto', 'photo-booking' ), true );
		}
		$order->update_meta_data( '_pb_appointment_id', (int) $values['pb_appointment_id'] );
		$order->update_meta_data( '_pb_order_type', 'main' );
	}

	/**
	 * @param \WC_Order|int $order
	 */
	public static function link_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order ) {
			return;
		}
		$appt = self::appointment_from_order( $order );
		if ( ! $appt ) {
			return;
		}
		$order->update_meta_data( '_pb_appointment_id', $appt->id );
		if ( ! $order->get_meta( '_pb_order_type' ) ) {
			$order->update_meta_data( '_pb_order_type', 'main' );
		}
		$order->save_meta_data();
		$changes = array( 'order_id' => $order->get_id() );
		if ( $order->get_customer_id() ) {
			$changes['customer_id'] = $order->get_customer_id();
		}
		// Give the payment gateway (3-D Secure etc.) time to complete.
		if ( Appointment::PENDING_PAYMENT === $appt->status ) {
			$changes['hold_expires_at'] = Time::now()->modify( '+60 minutes' )->format( Time::DB );
		}
		$appt->update( $changes );
		if ( WC()->session ) {
			WC()->session->set( 'pb_customer', null );
		}
	}

	/** @return Appointment|null */
	public static function appointment_from_order( \WC_Order $order ) {
		$id = (int) $order->get_meta( '_pb_appointment_id' );
		if ( ! $id ) {
			foreach ( $order->get_items() as $item ) {
				$id = (int) $item->get_meta( '_pb_appointment_id' );
				if ( $id ) {
					break;
				}
			}
		}
		return $id ? Appointment::get( $id ) : null;
	}

	/* ------------------------------------------------------------------ */
	/* Payment                                                            */
	/* ------------------------------------------------------------------ */

	public static function paid_order_status( $status, $order_id, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return $status;
		}
		$type = $order->get_meta( '_pb_order_type' );
		if ( 'balance' === $type || 'cash' === $type ) {
			return 'completed';
		}
		$appt = self::appointment_from_order( $order );
		if ( ! $appt ) {
			return $status;
		}
		$paid = (float) $order->get_total() + Payments::credit_used_in_order( $order );
		return $paid + 0.009 >= $appt->total ? 'completed' : 'pb-deposit';
	}

	/**
	 * Idempotent handler for "order is now paid".
	 */
	public static function order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_pb_processed' ) ) {
			return;
		}
		if ( ! Payments::order_is_paid( $order ) ) {
			return;
		}
		$appt = self::appointment_from_order( $order );
		if ( ! $appt ) {
			return;
		}
		$order->update_meta_data( '_pb_processed', 1 );
		$order->save_meta_data();

		$kind = $order->get_meta( '_pb_order_type' );
		if ( 'balance' === $kind ) {
			$type = 'balance';
		} elseif ( 'cash' === $kind ) {
			return; // Handled by Payments::cash_payment.
		} else {
			$type = 'full' === $appt->payment_mode ? 'full' : 'deposit';
			if ( ! $appt->order_id || $appt->order_id !== $order->get_id() ) {
				$appt->update( array( 'order_id' => $order->get_id() ) );
			}
		}
		Bookings::payment_received( $appt, $order, $type );
	}

	public static function order_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'main' !== $order->get_meta( '_pb_order_type' ) ) {
			return;
		}
		$appt = self::appointment_from_order( $order );
		if ( $appt && Appointment::PENDING_PAYMENT === $appt->status ) {
			$appt->update( array( 'status' => Appointment::EXPIRED ) );
			$appt->log( 'expired', sprintf( 'Ordine #%d annullato senza pagamento', $order_id ) );
		}
	}

	public static function order_refunded( $order_id ) {
		$order = wc_get_order( $order_id );
		$appt  = $order ? self::appointment_from_order( $order ) : null;
		if ( $appt ) {
			Payments::recalc( $appt );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Order statuses                                                     */
	/* ------------------------------------------------------------------ */

	public static function register_status() {
		register_post_status(
			'wc-pb-deposit',
			array(
				'label'                     => _x( 'Acconto versato', 'Order status', 'photo-booking' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Acconto versato <span class="count">(%s)</span>', 'Acconto versato <span class="count">(%s)</span>', 'photo-booking' ),
			)
		);
	}

	public static function order_statuses( $statuses ) {
		$new = array();
		foreach ( $statuses as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				$new['wc-pb-deposit'] = _x( 'Acconto versato', 'Order status', 'photo-booking' );
			}
		}
		if ( ! isset( $new['wc-pb-deposit'] ) ) {
			$new['wc-pb-deposit'] = _x( 'Acconto versato', 'Order status', 'photo-booking' );
		}
		return $new;
	}

	public static function paid_statuses( $statuses ) {
		if ( is_array( $statuses ) && ! in_array( 'pb-deposit', $statuses, true ) ) {
			$statuses[] = 'pb-deposit';
		}
		return $statuses;
	}

	public static function payment_complete_from( $statuses ) {
		return $statuses;
	}

	public static function suppress_wc_email( $enabled, $order ) {
		if ( $order instanceof \WC_Order && $order->get_meta( '_pb_order_type' ) ) {
			return false;
		}
		return $enabled;
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		$appt  = $order ? self::appointment_from_order( $order ) : null;
		if ( ! $appt ) {
			return;
		}
		$type = $order->get_meta( '_pb_order_type' );
		echo '<div class="pb-thankyou" style="padding:1.25rem 1.5rem;border-radius:10px;background:rgba(0,0,0,.035);margin:0 0 2rem">';
		if ( 'balance' === $type ) {
			echo '<h2 style="margin-top:0">' . esc_html__( 'Saldo ricevuto, grazie!', 'photo-booking' ) . '</h2>';
		} elseif ( $order->is_paid() || Payments::order_is_paid( $order ) ) {
			echo '<h2 style="margin-top:0">' . esc_html__( 'Richiesta di prenotazione inviata!', 'photo-booking' ) . '</h2>';
			/* translators: 1 package 2 date */
			echo '<p>' . esc_html( sprintf( __( 'Abbiamo ricevuto il pagamento per %1$s, %2$s. Lo studio confermerà l\'appuntamento al più presto: riceverai una email.', 'photo-booking' ), $appt->package_name(), Time::format_datetime( $appt->start_at ) ) ) . '</p>';
		} else {
			echo '<h2 style="margin-top:0">' . esc_html__( 'Ordine ricevuto', 'photo-booking' ) . '</h2><p>' . esc_html__( 'La richiesta verrà inviata allo studio appena il pagamento sarà confermato.', 'photo-booking' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( Account::booking_url( $appt ) ) . '">' . esc_html__( 'Vedi la prenotazione nella tua area privata', 'photo-booking' ) . '</a></p></div>';
	}

	public static function admin_order_box( $order ) {
		$appt = self::appointment_from_order( $order );
		if ( ! $appt ) {
			return;
		}
		echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Prenotazione', 'photo-booking' ) . ':</strong> <a href="' . esc_url( $appt->admin_url() ) . '">' . esc_html( $appt->number() . ' – ' . Time::format_datetime( $appt->start_at ) . ' (' . $appt->status_label() . ')' ) . '</a></p>';
	}
}
