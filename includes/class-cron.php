<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled jobs (every 15 minutes): expired holds, proposals, reminders, deliveries.
 */
class Cron {

	const HOOK = 'pb_cron_tick';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					self::schedule();
				}
			}
		);
	}

	public static function schedules( $s ) {
		$s['pb_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Ogni 15 minuti (Photo Booking)',
		);
		return $s;
	}

	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'pb_15min', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run() {
		Bookings::expire_holds();
		Bookings::payment_deadlines();
		Bookings::expire_proposals();
		if ( (int) Settings::get( 'purge_stale_days' ) > 0 ) {
			Bookings::purge_stale( (int) Settings::get( 'purge_stale_days' ) );
		}
		Bookings::send_reminders();
		Bookings::auto_payment_reminders();
		Bookings::auto_complete();
		Deliveries::cron();
		update_option( 'pb_cron_last_run', time(), false );
	}
}
