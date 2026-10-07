<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Slot engine. Single source of truth for public form, customer area and admin calendar.
 *
 * Busy time = appointments in blocking statuses (non-expired holds) + admin blocks + open proposals.
 */
class Availability {

	/**
	 * "09:00-13:00, 14:30-18:00" => [[540,780],[870,1080]] (minutes from midnight).
	 */
	public static function parse_ranges( $str ) {
		$out = array();
		foreach ( preg_split( '/[,;]+/', (string) $str ) as $part ) {
			if ( preg_match( '/^\s*(\d{1,2})[:.](\d{2})\s*-\s*(\d{1,2})[:.](\d{2})\s*$/', $part, $m ) ) {
				$a = (int) $m[1] * 60 + (int) $m[2];
				$b = (int) $m[3] * 60 + (int) $m[4];
				if ( $b > $a && $b <= 24 * 60 ) {
					$out[] = array( $a, $b );
				}
			}
		}
		return $out;
	}

	/**
	 * Opening ranges for a package on a date.
	 *
	 * @return array [[startMin,endMin],...]
	 */
	public static function ranges_for( array $cfg, $date ) {
		if ( ! empty( $cfg['windows'] ) ) {
			$ranges = array();
			foreach ( $cfg['windows'] as $w ) {
				if ( ( $w['date'] ?? '' ) === $date ) {
					$ranges = array_merge( $ranges, self::parse_ranges( $w['ranges'] ) );
				}
			}
			return $ranges;
		}
		$hours = Settings::get( 'hours' );
		$dow   = (int) Time::parse( $date . ' 12:00:00' )->format( 'N' );
		return self::parse_ranges( $hours[ $dow ] ?? '' );
	}

	/**
	 * Busy intervals overlapping [from, to).
	 *
	 * @param string $from DB datetime.
	 * @param string $to   DB datetime.
	 * @param int    $exclude_appointment Appointment ID to ignore (e.g. when moving it).
	 * @return array [[startTs, endTs, type, id], ...]
	 */
	public static function busy( $from, $to, $exclude_appointment = 0 ) {
		global $wpdb;
		$busy = array();
		$now  = Time::now_db();

		$t        = Install::table( 'appointments' );
		$statuses = Appointment::blocking_statuses();
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT id, start_at, busy_until, status, hold_expires_at FROM {$t} WHERE status IN ($ph) AND start_at < %s AND busy_until > %s AND id <> %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $statuses, array( $to, $from, (int) $exclude_appointment ) ) ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			if ( Appointment::PENDING_PAYMENT === $r['status'] && $r['hold_expires_at'] && $r['hold_expires_at'] < $now ) {
				continue; // Expired hold.
			}
			$busy[] = array( strtotime_local( $r['start_at'] ), strtotime_local( $r['busy_until'] ), 'appointment', (int) $r['id'] );
		}

		$t    = Install::table( 'blocks' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, start_at, end_at FROM {$t} WHERE start_at < %s AND end_at > %s", $to, $from ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$busy[] = array( strtotime_local( $r['start_at'] ), strtotime_local( $r['end_at'] ), 'block', (int) $r['id'] );
		}

		$t    = Install::table( 'proposals' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, appointment_id, start_at, busy_until FROM {$t} WHERE status = 'open' AND expires_at > %s AND start_at < %s AND busy_until > %s AND appointment_id <> %d", $now, $to, $from, (int) $exclude_appointment ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$busy[] = array( strtotime_local( $r['start_at'] ), strtotime_local( $r['busy_until'] ), 'proposal', (int) $r['id'] );
		}
		return $busy;
	}

	/**
	 * Is the interval [start, busy_until) free?
	 */
	public static function is_free( $start, $busy_until, $exclude_appointment = 0 ) {
		$s = strtotime_local( $start );
		$e = strtotime_local( $busy_until );
		foreach ( self::busy( Time::to_db( $start ), Time::to_db( $busy_until ), $exclude_appointment ) as $b ) {
			if ( $s < $b[1] && $b[0] < $e ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Slots for a package on a date.
	 *
	 * @param int    $product_id
	 * @param string $date Y-m-d
	 * @param array  $opts exclude (appointment id), ignore_notice (bool), busy (preloaded), ignore_capacity (bool)
	 * @return array [ ['start' => 'Y-m-d H:i:s', 'end' => ..., 'busy_until' => ..., 'time' => 'H:i'], ... ]
	 */
	public static function slots( $product_id, $date, array $opts = array() ) {
		if ( ! Time::is_valid_date( $date ) ) {
			return array();
		}
		$cfg = Package::config( $product_id );
		if ( ! $cfg ) {
			return array();
		}
		if ( empty( $opts['ignore_capacity'] ) && 0 === Package::remaining_places( $product_id ) ) {
			return array();
		}
		$ranges = self::ranges_for( $cfg, $date );
		if ( ! $ranges ) {
			return array();
		}

		$tz        = Time::tz();
		$day_start = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
		$now       = time();
		$min_ts    = empty( $opts['ignore_notice'] ) ? $now + (int) Settings::get( 'min_notice_hours' ) * HOUR_IN_SECONDS : $now;
		if ( empty( $opts['ignore_notice'] ) && empty( $cfg['windows'] ) ) {
			$max_ts = $now + (int) Settings::get( 'max_advance_days' ) * DAY_IN_SECONDS;
			if ( $day_start->getTimestamp() > $max_ts ) {
				return array();
			}
		}

		$duration = $cfg['duration'] * 60;
		$buffer   = $cfg['buffer'] * 60;
		$step     = (int) Settings::get( 'slot_interval' );
		$step     = $step > 0 ? $step * 60 : $duration + $buffer;

		if ( isset( $opts['busy'] ) ) {
			$busy = $opts['busy'];
		} else {
			$busy = self::busy( $day_start->format( Time::DB ), $day_start->modify( '+1 day' )->modify( '+12 hours' )->format( Time::DB ), (int) ( $opts['exclude'] ?? 0 ) );
		}

		$slots = array();
		foreach ( $ranges as $r ) {
			$range_end = $day_start->getTimestamp() + $r[1] * 60;
			// Use DateTime arithmetic so DST days are handled.
			$cursor = $day_start->modify( '+' . $r[0] . ' minutes' );
			while ( $cursor->getTimestamp() + $duration <= $range_end ) {
				$s  = $cursor->getTimestamp();
				$e  = $s + $duration;
				$be = $e + $buffer;
				$ok = $s >= $min_ts;
				if ( $ok ) {
					foreach ( $busy as $b ) {
						if ( $s < $b[1] && $b[0] < $be ) {
							$ok = false;
							break;
						}
					}
				}
				if ( $ok ) {
					$slots[] = array(
						'start'      => $cursor->format( Time::DB ),
						'end'        => $cursor->modify( '+' . $cfg['duration'] . ' minutes' )->format( Time::DB ),
						'busy_until' => $cursor->modify( '+' . ( $cfg['duration'] + $cfg['buffer'] ) . ' minutes' )->format( Time::DB ),
						'time'       => $cursor->format( 'H:i' ),
					);
				}
				$cursor = $cursor->modify( '+' . ( $step / 60 ) . ' minutes' );
			}
		}
		return $slots;
	}

	/**
	 * Dates of a month with at least one slot.
	 *
	 * @param int    $product_id
	 * @param string $month Y-m
	 * @return string[] Y-m-d
	 */
	public static function days( $product_id, $month, array $opts = array() ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return array();
		}
		$cfg = Package::config( $product_id );
		if ( ! $cfg ) {
			return array();
		}
		$first = new \DateTimeImmutable( $month . '-01 00:00:00', Time::tz() );
		$last  = $first->modify( 'last day of this month' );
		$busy  = self::busy( $first->format( Time::DB ), $last->modify( '+2 days' )->format( Time::DB ), (int) ( $opts['exclude'] ?? 0 ) );
		$opts['busy'] = $busy;
		$days = array();
		for ( $d = $first; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			$date = $d->format( 'Y-m-d' );
			if ( $d->getTimestamp() + DAY_IN_SECONDS < time() ) {
				continue;
			}
			if ( self::slots( $product_id, $date, $opts ) ) {
				$days[] = $date;
			}
		}
		return $days;
	}

	/** First month (Y-m) with availability, scanning up to 12 months ahead. */
	public static function first_month( $product_id ) {
		$cfg = Package::config( $product_id );
		if ( ! empty( $cfg['windows'] ) ) {
			foreach ( $cfg['windows'] as $w ) {
				if ( $w['date'] >= Time::now()->format( 'Y-m-d' ) ) {
					return substr( $w['date'], 0, 7 );
				}
			}
		}
		return Time::now()->format( 'Y-m' );
	}

	/**
	 * Is a specific start time bookable for this package (on the slot grid, respecting rules)?
	 *
	 * @return array|false The slot or false.
	 */
	public static function find_slot( $product_id, $start, array $opts = array() ) {
		$dt = Time::parse( $start );
		if ( ! $dt ) {
			return false;
		}
		foreach ( self::slots( $product_id, $dt->format( 'Y-m-d' ), $opts ) as $slot ) {
			if ( $slot['start'] === $dt->format( Time::DB ) ) {
				return $slot;
			}
		}
		return false;
	}
}

/**
 * Timestamp for a site-local DB datetime.
 */
function strtotime_local( $value ) {
	$dt = Time::parse( $value );
	return $dt ? $dt->getTimestamp() : 0;
}
