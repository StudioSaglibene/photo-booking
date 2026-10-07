<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Date helpers. All stored datetimes are in the site timezone, format Y-m-d H:i:s.
 */
class Time {

	const DB = 'Y-m-d H:i:s';

	public static function tz() {
		return wp_timezone();
	}

	/** @return \DateTimeImmutable */
	public static function now() {
		return new \DateTimeImmutable( 'now', self::tz() );
	}

	public static function now_db() {
		return self::now()->format( self::DB );
	}

	/**
	 * @param string|\DateTimeInterface $value
	 * @return \DateTimeImmutable|null
	 */
	public static function parse( $value ) {
		if ( $value instanceof \DateTimeImmutable ) {
			return $value;
		}
		if ( $value instanceof \DateTimeInterface ) {
			return ( new \DateTimeImmutable( '@' . $value->getTimestamp() ) )->setTimezone( $value->getTimezone() );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value, self::tz() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function to_db( $dt ) {
		$dt = self::parse( $dt );
		return $dt ? $dt->setTimezone( self::tz() )->format( self::DB ) : null;
	}

	public static function is_valid_date( $date ) {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, self::tz() );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Human friendly Italian date, e.g. "sabato 28 novembre 2026".
	 */
	public static function format_date( $value ) {
		$dt = self::parse( $value );
		return $dt ? wp_date( 'l j F Y', $dt->getTimestamp(), self::tz() ) : '';
	}

	public static function format_time( $value ) {
		$dt = self::parse( $value );
		return $dt ? wp_date( 'H:i', $dt->getTimestamp(), self::tz() ) : '';
	}

	public static function format_datetime( $value ) {
		$dt = self::parse( $value );
		return $dt ? self::format_date( $dt ) . ' ' . __( 'alle', 'photo-booking' ) . ' ' . self::format_time( $dt ) : '';
	}

	/** Hours from now until $value (negative if in the past). */
	public static function hours_until( $value ) {
		$dt = self::parse( $value );
		return $dt ? ( $dt->getTimestamp() - time() ) / HOUR_IN_SECONDS : 0;
	}

	/** ISO 8601 with offset, for JS. */
	public static function iso( $value ) {
		$dt = self::parse( $value );
		return $dt ? $dt->format( 'c' ) : null;
	}
}
