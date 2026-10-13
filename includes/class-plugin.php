<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Main loader.
 */
class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'photo-booking', false, dirname( plugin_basename( PB_FILE ) ) . '/languages' );
		Install::maybe_upgrade();
		add_filter( 'login_redirect', array( Install::class, 'studio_login_redirect' ), 999, 3 );

		Package::init();
		Booking_Form::init();
		Cart::init();
		Checkout::init();
		Account::init();
		Emails::init();
		Rest::init();
		Cron::init();
		Deliveries::init();
		Cookies::init();
		Seo::init();
		Cli::register();

		if ( is_admin() ) {
			Admin\Admin::init();
		}

		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	public function maybe_flush() {
		if ( get_option( 'pb_flush_rewrite' ) ) {
			delete_option( 'pb_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}
}
