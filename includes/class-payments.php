<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Money: linked orders, balance orders, cash, refunds, credits.
 *
 * Every euro received is a WooCommerce order (online deposit/full, online balance, cash in studio),
 * so WooCommerce reports stay accurate. Orders are linked to appointments through the pb_payments table
 * and the order meta _pb_appointment_id.
 */
class Payments {

	const CASH_METHOD = 'pb_cash';

	/* ------------------------------------------------------------------ */
	/* Ledger                                                             */
	/* ------------------------------------------------------------------ */

	public static function record( Appointment $appt, $type, $amount, $order_id = 0, $method = '', $note = '' ) {
		global $wpdb;
		$wpdb->insert(
			Install::table( 'payments' ),
			array(
				'appointment_id' => $appt->id,
				'order_id'       => (int) $order_id,
				'type'           => $type,
				'amount'         => round( (float) $amount, 2 ),
				'method'         => (string) $method,
				'note'           => (string) $note,
				'user_id'        => get_current_user_id(),
				'created_at'     => Time::now_db(),
			)
		);
	}

	public static function type_labels() {
		return array(
			'deposit'       => __( 'Acconto online', 'photo-booking' ),
			'full'          => __( 'Pagamento intero online', 'photo-booking' ),
			'balance'       => __( 'Saldo online', 'photo-booking' ),
			'cash'          => __( 'Contanti in sede', 'photo-booking' ),
			'credit_used'   => __( 'Credito utilizzato', 'photo-booking' ),
			'refund'        => __( 'Rimborso', 'photo-booking' ),
			'refund_manual' => __( 'Rimborso manuale (da restituire)', 'photo-booking' ),
			'credit_issued' => __( 'Credito emesso', 'photo-booking' ),
		);
	}

	/** Order IDs linked to an appointment. */
	public static function order_ids( Appointment $appt ) {
		global $wpdb;
		$t   = Install::table( 'payments' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$t} WHERE appointment_id = %d AND order_id > 0", $appt->id ) ); // phpcs:ignore
		if ( $appt->order_id ) {
			$ids[] = $appt->order_id;
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Amount of PB credit coupons used in an order. */
	public static function credit_used_in_order( \WC_Order $order ) {
		$used = 0;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$coupon = new \WC_Coupon( $item->get_code() );
			if ( $coupon->get_id() && $coupon->get_meta( '_pb_credit' ) ) {
				$used += (float) $item->get_discount();
			}
		}
		return round( $used, 2 );
	}

	/**
	 * Recompute amount paid from linked orders (paid orders: total + credit used − refunds).
	 */
	public static function recalc( Appointment $appt ) {
		$paid = 0;
		foreach ( self::order_ids( $appt ) as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || ! self::order_is_paid( $order ) ) {
				continue;
			}
			$paid += (float) $order->get_total() + self::credit_used_in_order( $order ) - (float) $order->get_total_refunded();
		}
		$paid    = round( max( 0, $paid ), 2 );
		$changes = array( 'paid' => $paid );
		if ( ! $appt->is_closed() ) {
			if ( $paid <= 0 ) {
				$changes['payment_status'] = Appointment::PAY_UNPAID;
			} elseif ( $paid + 0.009 >= $appt->total ) {
				$changes['payment_status'] = Appointment::PAY_PAID;
			} else {
				$changes['payment_status'] = Appointment::PAY_DEPOSIT;
			}
		}
		$appt->update( $changes );
		return $appt;
	}

	public static function order_is_paid( \WC_Order $order ) {
		return $order->is_paid() || in_array( $order->get_status(), array( 'pb-deposit', 'completed', 'processing' ), true );
	}

	/**
	 * After any payment, set the main order status according to the appointment balance.
	 */
	public static function sync_main_order_status( Appointment $appt ) {
		$order = $appt->order_id ? wc_get_order( $appt->order_id ) : null;
		if ( ! $order || ! self::order_is_paid( $order ) ) {
			return;
		}
		$target = $appt->balance_due() > 0 ? 'pb-deposit' : 'completed';
		if ( $order->get_status() !== $target && ! in_array( $order->get_status(), array( 'refunded', 'cancelled' ), true ) ) {
			$order->update_status( $target, __( 'Stato aggiornato da Photo Booking.', 'photo-booking' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Balance / cash orders                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Create (or reuse) the pending order for the remaining balance.
	 *
	 * @return \WC_Order|\WP_Error
	 */
	public static function balance_order( Appointment $appt, $amount = null ) {
		$due = $appt->balance_due();
		if ( $due <= 0 ) {
			return new \WP_Error( 'pb_nothing_due', __( 'Non c\'è nessun saldo da pagare.', 'photo-booking' ) );
		}
		// A partial amount (the deposit of a booking entered by the studio) is allowed; never more than what is due.
		if ( null !== $amount && (float) $amount > 0 && (float) $amount < $due - 0.009 ) {
			$due = round( (float) $amount, 2 );
		}
		$existing_id = (int) $appt->get_meta( 'balance_order_id' );
		if ( $existing_id ) {
			$existing = wc_get_order( $existing_id );
			if ( $existing && $existing->has_status( array( 'pending', 'failed' ) ) ) {
				if ( abs( (float) $existing->get_total() - $due ) < 0.01 ) {
					return $existing;
				}
				$existing->update_status( 'cancelled', __( 'Sostituito da un nuovo ordine di saldo.', 'photo-booking' ) );
			}
		}
		$order = self::create_order( $appt, $due, $due < $appt->balance_due() - 0.009 ? 'deposit' : 'balance' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$order->update_meta_data( '_pb_order_type', 'balance' );
		$order->set_status( 'pending' );
		$order->save();
		$appt->update( array( 'meta' => array( 'balance_order_id' => $order->get_id() ) ) );
		$appt->log( 'balance_order', sprintf( 'Creato ordine di saldo #%d (%s)', $order->get_id(), wc_format_decimal( $due, 2 ) ) );
		return $order;
	}

	/**
	 * Register a cash payment received in the studio.
	 *
	 * @return \WC_Order|\WP_Error
	 */
	public static function cash_payment( Appointment $appt, $amount, $note = '' ) {
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'pb_amount', __( 'Importo non valido.', 'photo-booking' ) );
		}
		$order = self::create_order( $appt, $amount, 'cash' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$order->set_payment_method( self::CASH_METHOD );
		$order->set_payment_method_title( __( 'Contanti in sede', 'photo-booking' ) );
		$order->set_date_paid( time() );
		$order->update_meta_data( '_pb_processed', 1 );
		$order->set_status( 'completed', __( 'Pagamento in contanti registrato in studio.', 'photo-booking' ) . ( $note ? ' ' . $note : '' ) );
		$order->save();

		// Cancel any pending online balance order, it is no longer valid.
		$bid = (int) $appt->get_meta( 'balance_order_id' );
		if ( $bid && ( $b = wc_get_order( $bid ) ) && $b->has_status( array( 'pending', 'failed' ) ) ) {
			$b->update_status( 'cancelled', __( 'Saldo ricevuto in contanti.', 'photo-booking' ) );
		}

		self::record( $appt, 'cash', $amount, $order->get_id(), self::CASH_METHOD, $note );
		self::recalc( $appt );
		self::sync_main_order_status( $appt );
		return $order;
	}

	/**
	 * Create an order linked to an appointment with a single line for $amount.
	 *
	 * @param string $kind balance|cash
	 * @return \WC_Order|\WP_Error
	 */
	private static function create_order( Appointment $appt, $amount, $kind ) {
		$order = wc_create_order( array( 'customer_id' => $appt->customer_id ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$product = $appt->product();
		$labels  = array( 'cash' => __( 'Pagamento in sede', 'photo-booking' ), 'deposit' => __( 'Acconto', 'photo-booking' ), 'balance' => __( 'Saldo', 'photo-booking' ) );
		$name    = ( $labels[ $kind ] ?? $labels['balance'] ) . ' – ' . $appt->package_name() . ' (' . $appt->number() . ')';
		$net     = $amount;
		if ( $product && wc_tax_enabled() && wc_prices_include_tax() ) {
			$incl = wc_get_price_including_tax( $product, array( 'price' => 100 ) );
			$excl = wc_get_price_excluding_tax( $product, array( 'price' => 100 ) );
			if ( $incl > 0 ) {
				$net = round( $amount * $excl / $incl, wc_get_price_decimals() + 2 );
			}
		}
		$item = new \WC_Order_Item_Product();
		$item->set_props(
			array(
				'name'         => $name,
				'product_id'   => $product ? $product->get_id() : 0,
				'quantity'     => 1,
				'subtotal'     => $net,
				'total'        => $net,
				'tax_class'    => $product ? $product->get_tax_class() : '',
			)
		);
		$item->add_meta_data( '_pb_appointment_id', $appt->id, true );
		$item->add_meta_data( __( 'Appuntamento', 'photo-booking' ), Time::format_datetime( $appt->start_at ), true );
		$order->add_item( $item );

		$name_parts = explode( ' ', trim( $appt->customer_name ), 2 );
		$order->set_billing_first_name( $name_parts[0] ?? '' );
		$order->set_billing_last_name( $name_parts[1] ?? '' );
		$order->set_billing_email( $appt->customer_email );
		$order->set_billing_phone( $appt->customer_phone );
		if ( $appt->customer_id && ( $customer = new \WC_Customer( $appt->customer_id ) ) && $customer->get_id() ) {
			foreach ( array( 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state' ) as $f ) {
				$getter = 'get_billing_' . $f;
				$setter = 'set_billing_' . $f;
				$order->$setter( $customer->$getter() );
			}
		}
		$order->set_created_via( 'photo-booking' );
		$order->update_meta_data( '_pb_appointment_id', $appt->id );
		$order->update_meta_data( '_pb_order_type', $kind );
		$order->calculate_totals( wc_tax_enabled() );
		// Make sure rounding never changes the amount requested.
		if ( abs( (float) $order->get_total() - $amount ) >= 0.01 ) {
			$order->set_total( $amount );
		}
		$order->save();
		return $order;
	}

	/* ------------------------------------------------------------------ */
	/* Refunds                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Refund everything paid for an appointment.
	 * Card payments are refunded through the gateway; cash payments are recorded as manual refunds
	 * (admin is notified); amounts paid with credit are re-issued as credit.
	 *
	 * @return array refunded, manual, credit, credit_code, errors
	 */
	public static function refund_all( Appointment $appt, $reason = '' ) {
		$result = array(
			'refunded'    => 0.0,
			'manual'      => 0.0,
			'credit'      => 0.0,
			'credit_code' => '',
			'errors'      => array(),
		);
		$credit_back = 0;
		foreach ( self::order_ids( $appt ) as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order || ! self::order_is_paid( $order ) ) {
				continue;
			}
			$credit_back += self::credit_used_in_order( $order );
			$refundable   = round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );
			if ( $refundable <= 0 ) {
				continue;
			}
			$gateway      = wc_get_payment_gateway_by_order( $order );
			$can_auto     = $gateway && $gateway->supports( 'refunds' ) && self::CASH_METHOD !== $order->get_payment_method();
			$line_items   = array();
			$refund       = wc_create_refund(
				array(
					'amount'         => $refundable,
					'reason'         => $reason ? $reason : __( 'Rimborso prenotazione', 'photo-booking' ) . ' ' . $appt->number(),
					'order_id'       => $order->get_id(),
					'line_items'     => $line_items,
					'refund_payment' => $can_auto,
					'restock_items'  => false,
				)
			);
			if ( is_wp_error( $refund ) ) {
				$result['errors'][] = sprintf( '#%d: %s', $order->get_id(), $refund->get_error_message() );
				continue;
			}
			if ( $can_auto ) {
				$result['refunded'] += $refundable;
				self::record( $appt, 'refund', -$refundable, $order->get_id(), $order->get_payment_method(), $order->get_payment_method_title() );
			} else {
				$result['manual'] += $refundable;
				self::record( $appt, 'refund_manual', -$refundable, $order->get_id(), $order->get_payment_method(), __( 'Da restituire manualmente', 'photo-booking' ) );
			}
		}
		if ( $credit_back > 0 ) {
			$result['credit']      = $credit_back;
			$result['credit_code'] = self::issue_credit( $appt, $credit_back, __( 'Restituzione credito utilizzato', 'photo-booking' ) );
		}
		self::recalc( $appt );
		if ( $result['manual'] > 0 ) {
			Emails::send( 'admin_manual_refund', $appt, array( 'amount' => $result['manual'] ) );
		}
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Credits (personal WooCommerce coupons)                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a personal, single-use credit coupon.
	 *
	 * @return string Coupon code.
	 */
	public static function issue_credit( Appointment $appt, $amount, $reason = '', $expires = null ) {
		$amount = round( (float) $amount, 2 );
		if ( $amount <= 0 ) {
			return '';
		}
		$code   = 'CREDITO-' . strtoupper( wp_generate_password( 8, false, false ) );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		$coupon->set_individual_use( false );
		$coupon->set_email_restrictions( array( $appt->customer_email ) );
		$months = max( 1, (int) Settings::get( 'credit_validity_months' ) );
		$coupon->set_date_expires( $expires ? $expires : Time::now()->modify( "+{$months} months" )->setTime( 23, 59, 59 )->getTimestamp() );
		$coupon->set_description( sprintf( 'Credito prenotazione %s – %s', $appt->number(), $reason ) );
		$coupon->update_meta_data( '_pb_credit', 1 );
		$coupon->update_meta_data( '_pb_customer_id', $appt->customer_id );
		$coupon->update_meta_data( '_pb_appointment_id', $appt->id );
		$coupon->save();
		self::record( $appt, 'credit_issued', $amount, 0, 'coupon', $code );
		$appt->log( 'credit', sprintf( 'Credito emesso %s: %s', $code, wc_format_decimal( $amount, 2 ) ) );
		Emails::send( 'credit_issued', $appt, array( 'amount' => $amount, 'code' => $code, 'expires' => $coupon->get_date_expires() ) );
		return $code;
	}

	/**
	 * Valid credit coupons for a customer.
	 *
	 * @return \WC_Coupon[]
	 */
	public static function credits_for( $user_id, $email = '' ) {
		$user  = $user_id ? get_userdata( $user_id ) : null;
		$email = $email ? $email : ( $user ? $user->user_email : '' );
		if ( ! $email && ! $user_id ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_query'     => array( // phpcs:ignore
					array(
						'key'   => '_pb_credit',
						'value' => '1',
					),
				),
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $posts as $pid ) {
			$c = new \WC_Coupon( $pid );
			if ( ! $c->get_id() ) {
				continue;
			}
			$emails = array_map( 'strtolower', $c->get_email_restrictions() );
			$owner  = (int) $c->get_meta( '_pb_customer_id' );
			if ( ! in_array( strtolower( $email ), $emails, true ) && ! ( $user_id && $owner === (int) $user_id ) ) {
				continue;
			}
			if ( $c->get_usage_limit() && $c->get_usage_count() >= $c->get_usage_limit() ) {
				continue;
			}
			$exp = $c->get_date_expires();
			if ( $exp && $exp->getTimestamp() < time() ) {
				continue;
			}
			$out[] = $c;
		}
		return $out;
	}

	public static function credit_total( $user_id, $email = '' ) {
		$sum = 0;
		foreach ( self::credits_for( $user_id, $email ) as $c ) {
			$sum += (float) $c->get_amount();
		}
		return round( $sum, 2 );
	}

	/**
	 * After an order is paid: record credit used; re-issue unused remainder of credit coupons.
	 */
	public static function handle_credit_usage( Appointment $appt, \WC_Order $order ) {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$coupon = new \WC_Coupon( $item->get_code() );
			if ( ! $coupon->get_id() || ! $coupon->get_meta( '_pb_credit' ) ) {
				continue;
			}
			$used = round( (float) $item->get_discount(), 2 );
			self::record( $appt, 'credit_used', $used, $order->get_id(), 'coupon', $coupon->get_code() );
			$left = round( (float) $coupon->get_amount() - $used, 2 );
			if ( $left > 0 && ! $coupon->get_meta( '_pb_remainder_issued' ) ) {
				$coupon->update_meta_data( '_pb_remainder_issued', 1 );
				$coupon->save();
				$exp = $coupon->get_date_expires();
				self::issue_credit( $appt, $left, __( 'Credito residuo', 'photo-booking' ), $exp ? $exp->getTimestamp() : null );
			}
		}
	}
}
