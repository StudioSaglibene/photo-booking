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
