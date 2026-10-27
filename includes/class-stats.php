<?php
/**
 * Bookings and money per package for a period (by session date).
 */

namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

class Stats {

	/** Statuses that count as real bookings (not closed, not unpaid holds). */
	public static function live_statuses() {
		return array( Appointment::PENDING, Appointment::PROPOSED, Appointment::PROPOSAL_EXPIRED, Appointment::NEEDS_ACTION, Appointment::CONFIRMED, Appointment::COMPLETED );
	}

	/**
	 * @param string $from Y-m-d
	 * @param string $to   Y-m-d (inclusive)
	 * @param int    $product_id 0 = all
	 * @return array packages => [ product_id => row ], totals => row, closed => [ count, kept ]
	 *               row: name, bookings, completed, value, deposits, balances, outstanding
	 */
	public static function compute( $from, $to, $product_id = 0 ) {
		$args = array(
			'status' => self::live_statuses(),
			'from'   => $from . ' 00:00:00',
			'to'     => $to . ' 23:59:59',
		);
		if ( $product_id ) {
			$args['product_id'] = $product_id;
		}
		$list     = Appointment::query( $args );
		$packages = array();
		$totals   = self::empty_row( __( 'Totale', 'photo-booking' ) );
		foreach ( $list as $a ) {
			$pid = (int) $a->product_id;
			if ( ! isset( $packages[ $pid ] ) ) {
				$packages[ $pid ] = self::empty_row( $a->package_name() );
			}
			$deposit_in = round( min( (float) $a->paid, (float) $a->deposit ), 2 );
			$balance_in = round( max( 0, (float) $a->paid - $deposit_in ), 2 );
			foreach ( array( &$packages[ $pid ], &$totals ) as &$row ) {
				$row['bookings']++;
				if ( Appointment::COMPLETED === $a->status ) {
					$row['completed']++;
				}
				$row['value']       += (float) $a->total;
				$row['deposits']    += $deposit_in;
				$row['balances']    += $balance_in;
				$row['outstanding'] += max( 0, (float) $a->balance_due() );
			}
			unset( $row );
		}
		uasort(
			$packages,
			static function ( $x, $y ) {
				return $y['bookings'] <=> $x['bookings'];
			}
		);

		// Closed bookings in the same period: how many, and money kept (lost deposits).
		$closed_args           = $args;
		$closed_args['status'] = array( Appointment::REJECTED, Appointment::CANCELLED, Appointment::EXPIRED );
		$closed                = array( 'count' => 0, 'kept' => 0.0 );
		foreach ( Appointment::query( $closed_args ) as $a ) {
			$closed['count']++;
			if ( Appointment::PAY_LOST === $a->payment_status ) {
				$closed['kept'] += (float) $a->paid;
			}
		}
		return array( 'packages' => $packages, 'totals' => $totals, 'closed' => $closed );
	}

	private static function empty_row( $name ) {
		return array( 'name' => $name, 'bookings' => 0, 'completed' => 0, 'value' => 0.0, 'deposits' => 0.0, 'balances' => 0.0, 'outstanding' => 0.0 );
	}
}
