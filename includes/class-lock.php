<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Portable mutex based on a PRIMARY KEY insert (works on MySQL/MariaDB and SQLite).
 * Used around "check availability + write appointment" to prevent double bookings.
 */
class Lock {

	/**
	 * @param string $name    Lock name.
	 * @param int    $wait    Seconds to wait.
	 * @param int    $ttl     Seconds after which a stale lock is ignored.
	 * @return bool
	 */
	public static function acquire( $name = 'booking', $wait = 10, $ttl = 30 ) {
		global $wpdb;
		$table    = Install::table( 'locks' );
		$deadline = microtime( true ) + $wait;
		do {
			// Remove stale lock.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE name = %s AND expires_at < %d", $name, time() ) ); // phpcs:ignore
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (name, expires_at) VALUES (%s, %d)", $name, time() + $ttl ) ); // phpcs:ignore
			$wpdb->suppress_errors( $suppress );
			if ( $ok ) {
				return true;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	public static function release( $name = 'booking' ) {
		global $wpdb;
		$table = Install::table( 'locks' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE name = %s", $name ) ); // phpcs:ignore
	}

	/**
	 * Run a callback while holding the lock.
	 *
	 * @param callable $callback
	 * @return mixed|\WP_Error
	 */
	public static function with( callable $callback, $name = 'booking' ) {
		if ( ! self::acquire( $name ) ) {
			return new \WP_Error( 'pb_busy', __( 'Il sistema è momentaneamente occupato, riprova tra qualche secondo.', 'photo-booking' ) );
		}
		try {
			return $callback();
		} finally {
			self::release( $name );
		}
	}
}
