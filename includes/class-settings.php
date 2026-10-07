<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (single option array).
 */
class Settings {

	const OPTION = 'pb_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			// Booking.
			'booking_page_id'         => 0,
			'hours'                   => array(
				1 => '09:00-18:00',
				2 => '09:00-18:00',
				3 => '09:00-18:00',
				4 => '09:00-18:00',
				5 => '09:00-18:00',
				6 => '09:00-18:00',
				7 => '',
			),
			'slot_interval'           => 0,   // Minutes between slot starts; 0 = duration + buffer.
			'min_notice_hours'        => 48,
			'max_advance_days'        => 90,
			'hold_minutes'            => 15,
			'proposal_hours'          => 48,
			'proposal_max_slots'      => 3,
			// Customer self-service.
			'reschedule_min_hours'    => 48,
			'reschedule_max'          => 1,
			// Cancellation policy.
			'refund_min_days'         => 7,
			'credit_min_hours'        => 24,
			'credit_validity_months'  => 12,
			'policy_text'             => "Fino a 7 giorni prima: rimborso completo oppure credito, a tua scelta.\nDa 7 giorni a 24 ore prima: l'importo versato diventa un credito valido 12 mesi su qualsiasi servizio in studio.\nMeno di 24 ore prima o mancata presentazione: l'importo versato non viene restituito.\nPuoi spostare gratuitamente l'appuntamento una volta, fino a 48 ore prima.\nSe annulliamo noi la sessione, rimborso completo o nuova data a tua scelta.",
			'reminder_enabled'        => 1,
			'studio_address'          => '',
			// Deliveries / R2.
			'r2_account_id'           => '',
			'r2_access_key'           => '',
			'r2_secret_key'           => '',
			'r2_bucket'               => '',
			'r2_endpoint'             => '',
			'r2_prefix'               => 'consegne/',
			'delivery_days'           => 30,
			'delivery_warn_days'      => 3,
			'delivery_purge_days'     => 0,
			'download_link_minutes'   => 10,
			// Appearance.
			'accent_color'            => '#8a6d4b',
			'fullcalendar_src'        => 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			if ( ! is_array( self::$cache['hours'] ) ) {
				self::$cache['hours'] = self::defaults()['hours'];
			}
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function update( array $values ) {
		$all = wp_parse_args( $values, self::all() );
		update_option( self::OPTION, $all, false );
		self::$cache = null;
	}

	public static function booking_page_url( $args = array() ) {
		$id  = (int) self::get( 'booking_page_id' );
		$url = $id ? get_permalink( $id ) : home_url( '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}
}
