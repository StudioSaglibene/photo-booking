<?php
namespace PhotoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Database tables, capabilities, cron.
 */
class Install {

	const CAP = 'pb_manage_bookings';

	public static function activate() {
		self::create_tables();
		self::add_caps();
		Cron::schedule();
		update_option( 'pb_db_version', PB_DB_VERSION );
		// Endpoints for My Account.
		update_option( 'pb_flush_rewrite', 1 );
	}

	public static function deactivate() {
		Cron::unschedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pb_db_version' ) !== PB_DB_VERSION ) {
			self::activate();
		}
		if ( get_option( 'pb_version' ) !== PB_VERSION ) {
			self::upgrade_data();
			update_option( 'pb_version', PB_VERSION, false );
		}
	}

	/** One-off data fixes shipped with a release (so that a git deploy is enough, without touching the database by hand). */
	private static function upgrade_data() {
		$addr = 'Via Mariagrazia Costanzo 1, 90040 Carini (PA)';
		$s    = Settings::all();
		$old  = array( '', 'Via Chiovaro 107, 90044 Carini (PA)', 'Via Mariagrazia Costanzo, Carini (PA)', 'Via Mariagrazia Costanzo 1, 90044 Carini (PA)' );
		$upd  = array();
		if ( in_array( $s['studio_address'], $old, true ) ) {
			$upd['studio_address'] = $addr;
		}
		if ( in_array( $s['business_address'], $old, true ) ) {
			$upd['business_address'] = $addr;
		}
		if ( $upd ) {
			Settings::update( $upd );
		}
		$footer = (string) get_option( 'woocommerce_email_footer_text' );
		if ( false !== strpos( $footer, 'Costanzo' ) || false !== strpos( $footer, 'Chiovaro' ) ) {
			update_option( 'woocommerce_email_footer_text', 'Viviana Saglibene Fotografia · ' . $addr . ' · P. IVA 06942900827 · {site_url}' );
		}
		if ( 'Via Chiovaro 107' === get_option( 'woocommerce_store_address' ) ) {
			update_option( 'woocommerce_store_address', 'Via Mariagrazia Costanzo 1' );
			update_option( 'woocommerce_store_postcode', '90040' );
		}
	}

	public static function add_caps() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
		self::add_studio_role();
	}

	/**
	 * "Studio" role for the photographer: bookings, deliveries, packages, orders, customers, media and the
	 * WooCommerce emails. No themes, plugins, pages, posts or WordPress settings (see Admin::trim_menu).
	 */
	public static function add_studio_role() {
		$shop = get_role( 'shop_manager' );
		$caps = $shop ? $shop->capabilities : array();
		$caps = array_merge(
			$caps,
			array(
				'read'                 => true,
				'upload_files'         => true,
				'list_users'           => true,
				'edit_users'           => true,
				'create_users'         => false,
				'delete_users'         => false,
				'promote_users'        => false,
				'edit_posts'           => false,
				'edit_pages'           => false,
				'manage_options'       => false,
				'edit_theme_options'   => false,
				'activate_plugins'     => false,
				'install_plugins'      => false,
				'update_plugins'       => false,
				'update_core'          => false,
				'view_woocommerce_reports' => true,
				self::CAP              => true,
			)
		);
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'Studio fotografico', 'photo-booking' ), array_filter( $caps ) );
	}
}
