<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Booking workflow: every status change goes through here (history + emails).
 */
class Bookings {

	/* ------------------------------------------------------------------ */
	/* Creation                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Hold a slot while the customer pays.
	 *
	 * @param int    $product_id
	 * @param string $start DB datetime.
	 * @param array  $customer name, email, phone, notes, meta[]
	 * @param string $mode deposit|full
	 * @return Appointment|\WP_Error
	 */
	public static function create_hold( $product_id, $start, array $customer, $mode, array $selection = array() ) {
		if ( ! Package::is_package( $product_id ) ) {
			return new \WP_Error( 'pb_package', __( 'Pacchetto non valido.', 'photo-booking' ) );
		}
		$cfg   = Package::config( $product_id );
		$quote = Package::quote( $product_id, (string) ( $selection['tier'] ?? '' ), (array) ( $selection['extras'] ?? array() ) );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$total   = $quote['total'];
		$deposit = min( $cfg['deposit'] < $cfg['price'] ? $cfg['deposit'] : $total, $total );
		$mode    = ( 'full' === $mode || $deposit >= $total ) ? 'full' : 'deposit';
		$meta    = array( 'product_name' => $cfg['name'] );
		if ( $quote['tier'] ) {
			$meta['tier']        = $quote['tier']['key'];
			$meta['tier_name']   = $quote['tier']['name'];
			$meta['tier_price']  = $quote['tier']['price'];
			$meta['extras']      = $quote['extras'];
		}

		return Lock::with(
			static function () use ( $product_id, $start, $customer, $mode, $total, $deposit, $meta ) {
				$slot = Availability::find_slot( $product_id, $start );
				if ( ! $slot ) {
					return new \WP_Error( 'pb_slot', __( 'L\'orario scelto non è più disponibile. Scegline un altro.', 'photo-booking' ) );
				}
				$appt = Appointment::create(
					array(
						'customer_id'     => get_current_user_id(),
						'customer_name'   => $customer['name'],
						'customer_email'  => $customer['email'],
						'customer_phone'  => $customer['phone'],
						'product_id'      => $product_id,
						'start_at'        => $slot['start'],
						'end_at'          => $slot['end'],
						'busy_until'      => $slot['busy_until'],
						'status'          => Appointment::PENDING_PAYMENT,
						'payment_mode'    => $mode,
						'total'           => $total,
						'deposit'         => $deposit,
						'hold_expires_at' => Time::now()->modify( '+' . (int) Settings::get( 'hold_minutes' ) . ' minutes' )->format( Time::DB ),
						'source'          => 'online',
						'customer_notes'  => $customer['notes'] ?? '',
						'meta'            => array_merge( (array) ( $customer['meta'] ?? array() ), $meta ),
					)
				);
				$appt->log( 'hold', 'Slot riservato in attesa di pagamento' );
				return $appt;
			}
		);
	}

	/**
	 * Re-hold an expired hold if the slot is still free (customer came back to checkout late).
	 *
	 * @return bool
	 */
	public static function refresh_hold( Appointment $appt, $minutes = null ) {
		$minutes = $minutes ? $minutes : (int) Settings::get( 'hold_minutes' );
		$res     = Lock::with(
			static function () use ( $appt, $minutes ) {
				if ( ! Availability::is_free( $appt->start_at, $appt->busy_until, $appt->id ) ) {
					return false;
				}
				$appt->update(
					array(
						'status'          => Appointment::PENDING_PAYMENT,
						'hold_expires_at' => Time::now()->modify( "+{$minutes} minutes" )->format( Time::DB ),
					)
				);
				return true;
			}
		);
		return true === $res;
	}

	/**
	 * Online payment received for an order linked to an appointment.
	 *
	 * @param string $type deposit|full|balance
	 */
	public static function payment_received( Appointment $appt, \WC_Order $order, $type ) {
		Payments::record( $appt, $type, (float) $order->get_total(), $order->get_id(), $order->get_payment_method(), $order->get_payment_method_title() );
		Payments::handle_credit_usage( $appt, $order );
		Payments::recalc( $appt );

		if ( 'balance' === $type ) {
			$appt->log( 'payment', sprintf( 'Saldo online ricevuto: %s (ordine #%d)', wc_format_decimal( $order->get_total(), 2 ), $order->get_id() ) );
			Payments::sync_main_order_status( $appt );
			Emails::send( 'balance_received', $appt, array( 'amount' => (float) $order->get_total(), 'method' => $order->get_payment_method_title() ) );
			Emails::send( 'admin_balance_paid', $appt, array( 'amount' => (float) $order->get_total() ) );
			return;
		}

		// First payment for the booking.
		$changes = array(
			'hold_expires_at' => null,
			'order_id'        => $order->get_id(),
		);
		if ( ! $appt->customer_id && $order->get_customer_id() ) {
			$changes['customer_id'] = $order->get_customer_id();
		}
		$conflict = false;
		if ( in_array( $appt->status, array( Appointment::PENDING_PAYMENT, Appointment::EXPIRED ), true ) ) {
			if ( Appointment::EXPIRED === $appt->status && ! Availability::is_free( $appt->start_at, $appt->busy_until, $appt->id ) ) {
				$conflict = true;
				$changes['status'] = Appointment::NEEDS_ACTION; // Does not occupy the calendar.
				$changes['meta']   = array( 'slot_conflict' => 1 );
			} else {
				$changes['status'] = Appointment::PENDING;
			}
		}
		$appt->update( $changes );
		$appt->log( 'payment', sprintf( '%s ricevuto: %s (ordine #%d, %s)', 'full' === $type ? 'Pagamento intero' : 'Acconto', wc_format_decimal( $order->get_total(), 2 ), $order->get_id(), $order->get_payment_method_title() ) );
		if ( $conflict ) {
			$appt->log( 'conflict', 'Pagamento arrivato dopo la scadenza del blocco: lo slot è stato nel frattempo occupato. Proporre un\'altra data.' );
		}
		Payments::sync_main_order_status( $appt );
		Emails::send( 'request_received', $appt );
		Emails::send( 'admin_new_request', $appt, array( 'conflict' => $conflict ) );
	}

	/**
	 * Manual appointment created by the admin.
	 *
	 * @param array $data customer_id, name, email, phone, product_id, start, status, payment (none|deposit_cash|full_cash), cash_amount, notes, total
	 * @return Appointment|\WP_Error
	 */
	public static function create_manual( array $data ) {
		$cfg = Package::config( $data['product_id'] );
		if ( ! $cfg ) {
			return new \WP_Error( 'pb_package', __( 'Pacchetto non valido.', 'photo-booking' ) );
		}
		$start = Time::parse( $data['start'] );
		if ( ! $start ) {
			return new \WP_Error( 'pb_date', __( 'Data non valida.', 'photo-booking' ) );
		}
		$end   = $start->modify( '+' . $cfg['duration'] . ' minutes' );
		$busy  = $end->modify( '+' . $cfg['buffer'] . ' minutes' );
		$force = ! empty( $data['force'] );

		$appt = Lock::with(
			static function () use ( $data, $cfg, $start, $end, $busy, $force ) {
				if ( ! $force && ! Availability::is_free( $start->format( Time::DB ), $busy->format( Time::DB ) ) ) {
					return new \WP_Error( 'pb_slot', __( 'L\'orario si sovrappone a un altro appuntamento o a un blocco. Spunta "forza" per inserirlo comunque.', 'photo-booking' ) );
				}
				$total = isset( $data['total'] ) && '' !== $data['total'] ? (float) $data['total'] : $cfg['price'];
				$appt  = Appointment::create(
					array(
						'customer_id'    => (int) $data['customer_id'],
						'customer_name'  => $data['name'],
						'customer_email' => $data['email'],
						'customer_phone' => $data['phone'],
						'product_id'     => $cfg['id'],
						'start_at'       => $start->format( Time::DB ),
						'end_at'         => $end->format( Time::DB ),
						'busy_until'     => $busy->format( Time::DB ),
						'status'         => in_array( $data['status'] ?? '', array( Appointment::PENDING, Appointment::CONFIRMED ), true ) ? $data['status'] : Appointment::CONFIRMED,
						'payment_mode'   => 'deposit',
						'total'          => $total,
						'deposit'        => $cfg['deposit'],
						'source'         => 'admin',
						'customer_notes' => $data['notes'] ?? '',
						'meta'           => array( 'product_name' => $cfg['name'] ),
					)
				);
				$appt->log( 'created', 'Appuntamento creato manualmente' . ( $force ? ' (sovrapposizione forzata)' : '' ) );
				return $appt;
			}
		);
		if ( is_wp_error( $appt ) ) {
			return $appt;
		}
		$amount = 0;
		if ( 'deposit_cash' === ( $data['payment'] ?? '' ) ) {
			$amount = (float) ( $data['cash_amount'] ?? 0 ) > 0 ? (float) $data['cash_amount'] : $cfg['deposit'];
		} elseif ( 'full_cash' === ( $data['payment'] ?? '' ) ) {
			$amount = $appt->total;
		}
		if ( $amount > 0 ) {
			$order = Payments::cash_payment( $appt, $amount, __( 'Registrato alla creazione', 'photo-booking' ) );
			if ( ! is_wp_error( $order ) ) {
				$appt->update( array( 'order_id' => $order->get_id() ) );
			}
		}
		if ( ! empty( $data['notify'] ) ) {
			Emails::send( Appointment::CONFIRMED === $appt->status ? 'confirmed' : 'request_received', $appt );
		}
		return Appointment::get( $appt->id );
	}

	/* ------------------------------------------------------------------ */
	/* Admin decisions                                                    */
	/* ------------------------------------------------------------------ */

	public static function confirm( Appointment $appt, $message = '' ) {
		if ( ! in_array( $appt->status, array( Appointment::PENDING, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'La prenotazione non è in attesa di conferma.', 'photo-booking' ) );
		}
		if ( in_array( $appt->status, array( Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) && ! Availability::is_free( $appt->start_at, $appt->busy_until, $appt->id ) ) {
			return new \WP_Error( 'pb_slot', __( 'L\'orario originale non è più libero: proponi un\'altra data.', 'photo-booking' ) );
		}
		$appt->update( array( 'status' => Appointment::CONFIRMED ) );
		$appt->log( 'confirmed', 'Prenotazione confermata' . ( $message ? ': ' . $message : '' ) );
		Emails::send( 'confirmed', $appt, array( 'message' => $message ) );
		return $appt;
	}

	/**
	 * Reject a request. $outcome: refund|credit.
	 */
	public static function reject( Appointment $appt, $outcome = 'refund', $message = '' ) {
		if ( ! in_array( $appt->status, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'Solo le richieste non ancora confermate possono essere rifiutate. Usa "Annulla".', 'photo-booking' ) );
		}
		self::close_proposals( $appt, 'cancelled' );
		$appt->update( array( 'status' => Appointment::REJECTED ) );
		$money = self::settle_money( $appt, $outcome, __( 'Richiesta rifiutata', 'photo-booking' ) );
		$appt->log( 'rejected', 'Richiesta rifiutata' . ( $message ? ': ' . $message : '' ) . ' – ' . self::money_summary( $money ) );
		Emails::send( 'rejected', $appt, array( 'message' => $message, 'money' => $money ) );
		return $money;
	}

	/**
	 * Propose alternative dates (1..N). The original slot is released, proposed slots are held.
	 *
	 * @param string[] $starts DB datetimes.
	 */
	public static function propose( Appointment $appt, array $starts, $message = '' ) {
		if ( ! in_array( $appt->status, array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION, Appointment::CONFIRMED ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'Non è possibile proporre date per questa prenotazione.', 'photo-booking' ) );
		}
		$cfg    = Package::config( $appt->product_id );
		$starts = array_slice( array_unique( array_filter( $starts ) ), 0, max( 1, (int) Settings::get( 'proposal_max_slots' ) ) );
		if ( ! $starts ) {
			return new \WP_Error( 'pb_empty', __( 'Indica almeno una data alternativa.', 'photo-booking' ) );
		}
		$duration = $cfg ? $cfg['duration'] : (int) ( ( strtotime( $appt->end_at ) - strtotime( $appt->start_at ) ) / 60 );
		$buffer   = $cfg ? $cfg['buffer'] : 0;

		$res = Lock::with(
			static function () use ( $appt, $starts, $duration, $buffer, $message ) {
				global $wpdb;
				$rows = array();
				foreach ( $starts as $s ) {
					$st = Time::parse( $s );
					if ( ! $st || $st->getTimestamp() < time() ) {
						return new \WP_Error( 'pb_date', __( 'Una delle date proposte non è valida o è nel passato.', 'photo-booking' ) );
					}
					$en = $st->modify( "+{$duration} minutes" );
					$bu = $en->modify( "+{$buffer} minutes" );
					if ( ! Availability::is_free( $st->format( Time::DB ), $bu->format( Time::DB ), $appt->id ) ) {
						/* translators: %s date */
						return new \WP_Error( 'pb_slot', sprintf( __( 'Lo slot del %s non è libero.', 'photo-booking' ), Time::format_datetime( $st ) ) );
					}
					$rows[] = array( $st, $en, $bu );
				}
				self::close_proposals( $appt, 'cancelled' );
				$expires = Time::now()->modify( '+' . (int) Settings::get( 'proposal_hours' ) . ' hours' )->format( Time::DB );
				foreach ( $rows as $r ) {
					$wpdb->insert(
						Install::table( 'proposals' ),
						array(
							'appointment_id' => $appt->id,
							'start_at'       => $r[0]->format( Time::DB ),
							'end_at'         => $r[1]->format( Time::DB ),
							'busy_until'     => $r[2]->format( Time::DB ),
							'status'         => 'open',
							'expires_at'     => $expires,
							'created_at'     => Time::now_db(),
						)
					);
				}
				$appt->update(
					array(
						'status' => Appointment::PROPOSED,
						'meta'   => array(
							'proposal_message' => $message,
							'proposal_expires' => $expires,
							'slot_conflict'    => 0,
							'status_before_proposal' => $appt->status,
						),
					)
				);
				return true;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$labels = array_map(
			static function ( $s ) {
				return Time::format_datetime( $s );
			},
			$starts
		);
		$appt->log( 'proposed', 'Proposte nuove date: ' . implode( '; ', $labels ) . ( $message ? ' – ' . $message : '' ) );
		Emails::send( 'proposal', $appt, array( 'message' => $message ) );
		return true;
	}

	public static function close_proposals( Appointment $appt, $status ) {
		global $wpdb;
		$wpdb->update( Install::table( 'proposals' ), array( 'status' => $status ), array( 'appointment_id' => $appt->id, 'status' => 'open' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Customer answers                                                   */
	/* ------------------------------------------------------------------ */

	public static function accept_proposal( Appointment $appt, $proposal_id ) {
		$res = Lock::with(
			static function () use ( $appt, $proposal_id ) {
				global $wpdb;
				$t = Install::table( 'proposals' );
				$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND appointment_id = %d", $proposal_id, $appt->id ), ARRAY_A ); // phpcs:ignore
				if ( ! $p || 'open' !== $p['status'] || $p['expires_at'] < Time::now_db() || Appointment::PROPOSED !== $appt->status ) {
					return new \WP_Error( 'pb_proposal', __( 'La proposta non è più valida. Contatta lo studio.', 'photo-booking' ) );
				}
				$wpdb->update( $t, array( 'status' => 'accepted' ), array( 'id' => $p['id'] ) );
				self::close_proposals( $appt, 'declined' );
				$appt->update(
					array(
						'start_at'   => $p['start_at'],
						'end_at'     => $p['end_at'],
						'busy_until' => $p['busy_until'],
						'status'     => Appointment::CONFIRMED,
					)
				);
				return true;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$appt->log( 'proposal_accepted', 'Il cliente ha accettato la data proposta: ' . Time::format_datetime( $appt->start_at ) );
		Emails::send( 'confirmed', $appt, array( 'message' => __( 'Hai scelto la nuova data proposta.', 'photo-booking' ) ) );
		Emails::send( 'admin_proposal_answered', $appt, array( 'accepted' => true ) );
		return true;
	}

	/**
	 * Customer declines all proposed dates. $outcome refund|credit.
	 */
	public static function decline_proposal( Appointment $appt, $outcome ) {
		if ( ! in_array( $appt->status, array( Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED ), true ) ) {
			return new \WP_Error( 'pb_status', __( 'Operazione non disponibile.', 'photo-booking' ) );
		}
		self::close_proposals( $appt, 'declined' );
		$appt->update( array( 'status' => Appointment::CANCELLED ) );
		$money = self::settle_money( $appt, 'credit' === $outcome ? 'credit' : 'refund', __( 'Date proposte non accettate', 'photo-booking' ) );
		$appt->log( 'proposal_declined', 'Il cliente ha rifiutato le date proposte – ' . self::money_summary( $money ) );
		Emails::send( 'cancelled', $appt, array( 'money' => $money, 'by' => 'customer' ) );
		Emails::send( 'admin_proposal_answered', $appt, array( 'accepted' => false, 'money' => $money ) );
		return $money;
	}

	/* ------------------------------------------------------------------ */
	/* Reschedule / cancel                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $by admin|customer
	 * @param array  $opts notify (bool), force (bool, admin only), message
	 */
	public static function reschedule( Appointment $appt, $new_start, $by = 'admin', array $opts = array() ) {
		$cfg   = Package::config( $appt->product_id );
		$start = Time::parse( $new_start );
		if ( ! $start ) {
			return new \WP_Error( 'pb_date', __( 'Data non valida.', 'photo-booking' ) );
		}
		if ( 'customer' === $by ) {
			$can = Policy::can_customer_reschedule( $appt );
			if ( is_wp_error( $can ) ) {
				return $can;
			}
		}
		$old = $appt->start_at;
		$res = Lock::with(
			static function () use ( $appt, $start, $by, $opts, $cfg ) {
				if ( 'customer' === $by ) {
					$slot = Availability::find_slot(
						$appt->product_id,
						$start->format( Time::DB ),
						array(
							'exclude'         => $appt->id,
							'ignore_capacity' => true,
						)
					);
					if ( ! $slot ) {
						return new \WP_Error( 'pb_slot', __( 'L\'orario scelto non è disponibile.', 'photo-booking' ) );
					}
					$end  = $slot['end'];
					$busy = $slot['busy_until'];
				} else {
					$duration = $cfg ? $cfg['duration'] : (int) ( ( strtotime( $appt->end_at ) - strtotime( $appt->start_at ) ) / 60 );
					$buffer   = $cfg ? $cfg['buffer'] : 0;
					$end      = $start->modify( "+{$duration} minutes" )->format( Time::DB );
					$busy     = $start->modify( '+' . ( $duration + $buffer ) . ' minutes' )->format( Time::DB );
					if ( empty( $opts['force'] ) && ! Availability::is_free( $start->format( Time::DB ), $busy, $appt->id ) ) {
						return new \WP_Error( 'pb_slot', __( 'Il nuovo orario si sovrappone a un altro appuntamento o a un blocco.', 'photo-booking' ) );
					}
				}
				$changes = array(
					'start_at'      => $start->format( Time::DB ),
					'end_at'        => $end,
					'busy_until'    => $busy,
					'reminder_sent' => 0,
				);
				if ( 'customer' === $by ) {
					$changes['reschedule_count'] = $appt->reschedule_count + 1;
				}
				$appt->update( $changes );
				return true;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$appt->log( 'rescheduled', sprintf( 'Spostata da %s a %s (%s)', Time::format_datetime( $old ), Time::format_datetime( $appt->start_at ), 'customer' === $by ? 'dal cliente' : 'dallo studio' ) );
		if ( 'customer' === $by || ! empty( $opts['notify'] ) ) {
			Emails::send( 'rescheduled', $appt, array( 'old' => $old, 'by' => $by, 'message' => $opts['message'] ?? '' ) );
		}
		if ( 'customer' === $by ) {
			Emails::send( 'admin_customer_rescheduled', $appt, array( 'old' => $old ) );
		}
		return true;
	}

	/**
	 * Cancel an appointment.
	 *
	 * @param string $outcome refund|credit|lost
	 * @param string $by      admin|customer
	 */
	public static function cancel( Appointment $appt, $outcome, $by = 'admin', $message = '' ) {
		if ( $appt->is_closed() ) {
			return new \WP_Error( 'pb_status', __( 'La prenotazione è già chiusa.', 'photo-booking' ) );
		}
		if ( 'customer' === $by ) {
			$can = Policy::can_customer_cancel( $appt );
			if ( is_wp_error( $can ) ) {
				return $can;
			}
			// The customer cannot pick a better outcome than the policy allows.
			$policy = Policy::cancel_outcome( $appt );
			if ( Policy::OUTCOME_CREDIT === $policy['type'] ) {
				$outcome = 'credit';
			} elseif ( Policy::OUTCOME_LOST === $policy['type'] ) {
				$outcome = 'lost';
			} else {
				$outcome = 'credit' === $outcome ? 'credit' : 'refund';
			}
		}
		self::close_proposals( $appt, 'cancelled' );
		$appt->update( array( 'status' => Appointment::CANCELLED ) );
		$money = self::settle_money( $appt, $outcome, 'customer' === $by ? __( 'Disdetta del cliente', 'photo-booking' ) : __( 'Annullata dallo studio', 'photo-booking' ) );
		$appt->log( 'cancelled', ( 'customer' === $by ? 'Disdetta dal cliente' : 'Annullata dallo studio' ) . ( $message ? ': ' . $message : '' ) . ' – ' . self::money_summary( $money ) );
		Emails::send( 'cancelled', $appt, array( 'money' => $money, 'by' => $by, 'message' => $message ) );
		if ( 'customer' === $by ) {
			Emails::send( 'admin_customer_cancelled', $appt, array( 'money' => $money ) );
		}
		return $money;
	}

	/**
	 * Apply the money outcome of a rejection/cancellation.
	 *
	 * @param string $outcome refund|credit|lost
	 * @return array outcome, refunded, manual, credit, credit_code, errors
	 */
	private static function settle_money( Appointment $appt, $outcome, $reason ) {
		Payments::recalc( $appt );
		$money = array(
			'outcome'     => $outcome,
			'paid'        => $appt->paid,
			'refunded'    => 0,
			'manual'      => 0,
			'credit'      => 0,
			'credit_code' => '',
			'errors'      => array(),
		);
		// Cancel any pending balance order.
		$bid = (int) $appt->get_meta( 'balance_order_id' );
		if ( $bid && ( $b = wc_get_order( $bid ) ) && $b->has_status( array( 'pending', 'failed' ) ) ) {
			$b->update_status( 'cancelled', __( 'Prenotazione chiusa.', 'photo-booking' ) );
		}
		if ( $appt->paid <= 0 ) {
			$appt->update( array( 'payment_status' => Appointment::PAY_UNPAID ) );
			return $money;
		}
		if ( 'refund' === $outcome ) {
			$r     = Payments::refund_all( $appt, $reason . ' ' . $appt->number() );
			$money = array_merge( $money, $r );
			$status = $r['errors'] ? Appointment::PAY_PARTIAL_REFUND : Appointment::PAY_REFUNDED;
			$appt->update( array( 'payment_status' => $status ) );
		} elseif ( 'credit' === $outcome ) {
			$money['credit']      = $appt->paid;
			$money['credit_code'] = Payments::issue_credit( $appt, $appt->paid, $reason );
			$appt->update( array( 'payment_status' => Appointment::PAY_CREDIT ) );
		} else {
			$appt->update( array( 'payment_status' => Appointment::PAY_LOST ) );
		}
		// Main order: mark as cancelled/refunded for clarity in WooCommerce.
		return $money;
	}

	public static function money_summary( array $m ) {
		$parts = array();
		if ( $m['refunded'] > 0 ) {
			$parts[] = 'rimborsati ' . wc_format_decimal( $m['refunded'], 2 );
		}
		if ( $m['manual'] > 0 ) {
			$parts[] = 'da restituire a mano ' . wc_format_decimal( $m['manual'], 2 );
		}
		if ( $m['credit'] > 0 ) {
			$parts[] = 'credito ' . wc_format_decimal( $m['credit'], 2 ) . ( $m['credit_code'] ? ' (' . $m['credit_code'] . ')' : '' );
		}
		if ( 'lost' === $m['outcome'] && $m['paid'] > 0 ) {
			$parts[] = 'importo trattenuto ' . wc_format_decimal( $m['paid'], 2 );
		}
		if ( ! empty( $m['errors'] ) ) {
			$parts[] = 'ERRORI: ' . implode( '; ', $m['errors'] );
		}
		return $parts ? implode( ', ', $parts ) : 'nessun importo';
	}

	public static function complete( Appointment $appt ) {
		$appt->update( array( 'status' => Appointment::COMPLETED ) );
		$appt->log( 'completed', 'Sessione svolta' );
		return true;
	}

	/**
	 * Cash payment in the studio.
	 */
	public static function cash( Appointment $appt, $amount, $note = '', $notify = true ) {
		$order = Payments::cash_payment( $appt, $amount, $note );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$appt = Appointment::get( $appt->id );
		$appt->log( 'payment', sprintf( 'Pagamento in contanti registrato: %s (ordine #%d)', wc_format_decimal( $amount, 2 ), $order->get_id() ) );
		if ( $notify ) {
			Emails::send( 'balance_received', $appt, array( 'amount' => (float) $amount, 'method' => __( 'Contanti in sede', 'photo-booking' ) ) );
		}
		return $appt;
	}

	/* ------------------------------------------------------------------ */
	/* Cron jobs                                                          */
	/* ------------------------------------------------------------------ */

	public static function expire_holds() {
		global $wpdb;
		$t   = Install::table( 'appointments' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = %s AND hold_expires_at IS NOT NULL AND hold_expires_at < %s", Appointment::PENDING_PAYMENT, Time::now()->modify( '-10 minutes' )->format( Time::DB ) ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			$a = Appointment::get( $id );
			if ( $a ) {
				$a->update( array( 'status' => Appointment::EXPIRED ) );
				$a->log( 'expired', 'Blocco scaduto senza pagamento' );
			}
		}
	}

	public static function expire_proposals() {
		global $wpdb;
		$t   = Install::table( 'proposals' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT appointment_id FROM {$t} WHERE status = 'open' AND expires_at < %s", Time::now_db() ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			$a = Appointment::get( $id );
			$wpdb->update( $t, array( 'status' => 'expired' ), array( 'appointment_id' => $id, 'status' => 'open' ) );
			if ( $a && Appointment::PROPOSED === $a->status ) {
				$a->update( array( 'status' => Appointment::PROPOSAL_EXPIRED ) );
				$a->log( 'proposal_expired', 'Il cliente non ha risposto alla proposta entro la scadenza' );
				Emails::send( 'proposal_expired', $a );
				Emails::send( 'admin_proposal_unanswered', $a );
			}
		}
	}

	public static function send_reminders() {
		if ( ! Settings::get( 'reminder_enabled' ) ) {
			return;
		}
		if ( (int) Time::now()->format( 'G' ) < 9 ) {
			return; // Don't send at night.
		}
		$tomorrow = Time::now()->modify( '+1 day' );
		$list     = Appointment::query(
			array(
				'status' => array( Appointment::CONFIRMED ),
				'from'   => $tomorrow->setTime( 0, 0 )->format( Time::DB ),
				'to'     => $tomorrow->setTime( 23, 59, 59 )->format( Time::DB ),
			)
		);
		foreach ( $list as $a ) {
			if ( ! $a->reminder_sent && $a->start()->format( 'Y-m-d' ) === $tomorrow->format( 'Y-m-d' ) ) {
				$a->update( array( 'reminder_sent' => 1 ) );
				Emails::send( 'reminder', $a );
			}
		}
	}
}
