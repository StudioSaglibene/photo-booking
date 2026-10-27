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
		if ( ! get_option( 'pb_stock_images' ) ) {
			add_action( 'init', array( __CLASS__, 'install_stock_images' ), 20 );
		}
		if ( get_option( 'pb_legal_pending' ) ) {
			add_action( 'init', array( __CLASS__, 'apply_legal_pages' ), 20 );
		}
		if ( get_option( 'pb_seo_pending' ) ) {
			add_action( 'init', array( __CLASS__, 'apply_seo' ), 21 );
		}
	}

	/** One-off data fixes shipped with a release (so that a git deploy is enough, without touching the database by hand). */
	private static function upgrade_data() {
		$addr = 'Via Mariagrazia Costanzo 1, 90040 Capaci (PA)';
		$s    = Settings::all();
		$old  = array( '', 'Via Chiovaro 107, 90044 Carini (PA)', 'Via Mariagrazia Costanzo, Carini (PA)', 'Via Mariagrazia Costanzo 1, 90044 Carini (PA)', 'Via Mariagrazia Costanzo 1, 90040 Carini (PA)' );
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
		if ( in_array( get_option( 'woocommerce_store_address' ), array( 'Via Chiovaro 107', 'Via Mariagrazia Costanzo 1' ), true ) ) {
			update_option( 'woocommerce_store_address', 'Via Mariagrazia Costanzo 1' );
			update_option( 'woocommerce_store_postcode', '90040' );
			update_option( 'woocommerce_store_city', 'Capaci' );
		}
		update_option( 'pb_legal_pending', PB_VERSION, false ); // applied on init: wp_update_post is not available this early.
		self::add_studio_role(); // capabilities may change between releases.
		if ( 'customer' !== get_option( 'default_role' ) ) {
			update_option( 'default_role', 'customer' ); // users added from the panel are customers of the studio.
		}
		update_option( 'pb_seo_pending', PB_VERSION, false );
	}

	/**
	 * Installs the stock photos shipped in assets/stock as package images and Prenota hero (once).
	 */
	public static function install_stock_images() {
		if ( get_option( 'pb_stock_images' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		update_option( 'pb_stock_images', array( 'started' => time() ), false );
		try {
			self::do_install_stock_images();
		} catch ( \Throwable $e ) {
			update_option( 'pb_stock_images', array( 'error' => $e->getMessage() ), false );
		}
	}

	private static function do_install_stock_images() {
		wp_raise_memory_limit( 'image' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$map = array(
			'newborn'    => 'Newborn',
			'maternita'  => 'Maternità',
			'family'     => 'Family in studio',
			'smash-cake' => 'Smash Cake',
			'natale'     => 'Natale',
		);
		$ids = array();
		foreach ( array_merge( array_keys( $map ), array( 'hero' ) ) as $key ) {
			$src = PB_DIR . 'assets/stock/prenota-' . $key . '.jpg';
			if ( ! file_exists( $src ) ) {
				continue;
			}
			$tmp = wp_tempnam( 'prenota-' . $key . '.jpg' );
			copy( $src, $tmp );
			$id = media_handle_sideload( array( 'name' => 'prenota-' . $key . '.jpg', 'tmp_name' => $tmp ), 0, 'Prenota – ' . $key );
			if ( ! is_wp_error( $id ) ) {
				$ids[ $key ] = (int) $id;
			}
		}
		foreach ( $map as $key => $title ) {
			if ( empty( $ids[ $key ] ) ) {
				continue;
			}
			$found = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'title' => $title, 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( $found ) {
				set_post_thumbnail( $found[0], $ids[ $key ] );
			}
		}
		$page = get_page_by_path( 'prenota' );
		if ( $page && ! empty( $ids['hero'] ) ) {
			$raw  = get_post_meta( $page->ID, '_elementor_data', true );
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				$done = false;
				$walk = function ( &$els ) use ( &$walk, &$done, $ids ) {
					foreach ( $els as &$el ) {
						if ( ! $done && ! empty( $el['settings']['background_image']['id'] ) ) {
							$el['settings']['background_image'] = array( 'id' => $ids['hero'], 'url' => wp_get_attachment_url( $ids['hero'] ) );
							$done = true;
						}
						if ( ! empty( $el['elements'] ) ) {
							$walk( $el['elements'] );
						}
					}
				};
				$walk( $data );
				if ( $done ) {
					update_post_meta( $page->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
					delete_post_meta( $page->ID, '_elementor_css' );
					if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
					}
				}
			}
		}
		update_option( 'pb_stock_images', $ids, false );
	}

	/** Titles, descriptions, noindex and schema settings shipped with the plugin (see Seo). */
	public static function apply_seo() {
		delete_option( 'pb_seo_pending' );
		if ( class_exists( 'WPSEO_Options' ) ) {
			Seo::apply();
		}
	}

	/** Pages shipped in legal/ are re-published after each release that changes them. */
	public static function apply_legal_pages() {
		delete_option( 'pb_legal_pending' );
		self::update_legal_page( 'condizioni-di-vendita', 'Termini e condizioni di vendita' );
		self::update_legal_page( 'cookie-policy' );
	}

	/** Replaces the content of a legal page with the version shipped in legal/<slug>.html (shared with production). */
	private static function update_legal_page( $slug, $title = '' ) {
		$file = PB_DIR . 'legal/' . $slug . '.html';
		$page = get_page_by_path( $slug );
		if ( ! $page || ! file_exists( $file ) ) {
			return;
		}
		$args = array( 'ID' => $page->ID, 'post_content' => file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $title ) {
			$args['post_title'] = $title;
		}
		wp_update_post( wp_slash( $args ) );
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
				'create_users'         => true,
				'delete_users'         => true,
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
