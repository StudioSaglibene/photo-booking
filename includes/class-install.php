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
		$s = Settings::all();
		if ( in_array( $s['studio_address'], array( '', 'Via Chiovaro 107, 90044 Carini (PA)', 'Via Mariagrazia Costanzo, Carini (PA)' ), true ) ) {
			Settings::update( array( 'studio_address' => 'Via Mariagrazia Costanzo 1, 90044 Carini (PA)' ) );
		}
		$footer = (string) get_option( 'woocommerce_email_footer_text' );
		if ( false !== strpos( $footer, 'Via Mariagrazia Costanzo, Carini' ) || false !== strpos( $footer, 'Via Chiovaro 107' ) ) {
			update_option( 'woocommerce_email_footer_text', 'Viviana Saglibene Fotografia · Studio in Via Mariagrazia Costanzo 1, 90044 Carini (PA) · P. IVA 06942900827 · {site_url}' );
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'pb_' . $name;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$tables = array();

		$tables[] = 'CREATE TABLE ' . self::table( 'appointments' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_name varchar(190) NOT NULL DEFAULT '',
  customer_email varchar(190) NOT NULL DEFAULT '',
  customer_phone varchar(60) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  start_at datetime NOT NULL,
  end_at datetime NOT NULL,
  busy_until datetime NOT NULL,
  status varchar(30) NOT NULL DEFAULT 'pending_payment',
  payment_status varchar(30) NOT NULL DEFAULT 'unpaid',
  payment_mode varchar(20) NOT NULL DEFAULT 'deposit',
  total decimal(12,2) NOT NULL DEFAULT 0,
  deposit decimal(12,2) NOT NULL DEFAULT 0,
  paid decimal(12,2) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hold_expires_at datetime NULL DEFAULT NULL,
  reschedule_count int(11) NOT NULL DEFAULT 0,
  reminder_sent tinyint(1) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'online',
  customer_notes text NULL,
  admin_notes text NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY start_at (start_at),
  KEY customer_id (customer_id),
  KEY order_id (order_id)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'blocks' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  start_at datetime NOT NULL,
  end_at datetime NOT NULL,
  reason varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY start_at (start_at)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'proposals' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  appointment_id bigint(20) unsigned NOT NULL,
  start_at datetime NOT NULL,
  end_at datetime NOT NULL,
  busy_until datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY appointment_id (appointment_id),
  KEY status (status)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'payments' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  appointment_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(30) NOT NULL,
  amount decimal(12,2) NOT NULL DEFAULT 0,
  method varchar(60) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY appointment_id (appointment_id),
  KEY order_id (order_id)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'events' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  appointment_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  message text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY appointment_id (appointment_id)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'deliveries' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL,
  appointment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(190) NOT NULL DEFAULT '',
  object_key varchar(255) NOT NULL DEFAULT '',
  filename varchar(190) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  upload_id text NULL,
  status varchar(20) NOT NULL DEFAULT 'uploading',
  uploaded_at datetime NULL DEFAULT NULL,
  expires_at datetime NULL DEFAULT NULL,
  warned tinyint(1) NOT NULL DEFAULT 0,
  downloads int(11) NOT NULL DEFAULT 0,
  last_download_at datetime NULL DEFAULT NULL,
  deleted_at datetime NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY customer_id (customer_id),
  KEY status (status)
) $c;";

		$tables[] = 'CREATE TABLE ' . self::table( 'locks' ) . " (
  name varchar(100) NOT NULL,
  expires_at int(11) unsigned NOT NULL,
  PRIMARY KEY  (name)
) $c;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	const ROLE = 'pb_studio';

	/** The photographer lands on the calendar after login (wp-login.php is not an admin screen, so this lives here). */
	public static function studio_login_redirect( $redirect, $requested, $user ) {
		if ( $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true ) ) {
			return admin_url( 'admin.php?page=pb-calendar' );
		}
		return $redirect;
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
