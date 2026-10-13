<?php
/**
 * Plugin Name:       Photo Booking – Prenotazioni, pagamenti e consegne
 * Description:       Prenotazione sessioni fotografiche con acconto/saldo (WooCommerce), calendario amministrativo, area privata clienti e consegne su Cloudflare R2.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.5
 * Author:            Emanuele Archintelli
 * Text Domain:       photo-booking
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PB_VERSION', '1.1.5' );
define( 'PB_DB_VERSION', '2' );
define( 'PB_FILE', __FILE__ );
define( 'PB_DIR', plugin_dir_path( __FILE__ ) );
define( 'PB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'PhotoBooking\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'PhotoBooking\\' ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$dir      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path     = PB_DIR . 'includes/' . $dir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'PhotoBooking\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PhotoBooking\\Install', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Photo Booking richiede WooCommerce attivo.', 'photo-booking' ) . '</p></div>';
				}
			);
			return;
		}
		PhotoBooking\Plugin::instance();
	},
	20
);

/**
 * Template loader: theme can override in yourtheme/photo-booking/<template>.
 *
 * @param string $template Relative template path.
 * @param array  $args     Variables.
 */
function pb_get_template( $template, $args = array() ) {
	$located = locate_template( 'photo-booking/' . $template );
	if ( ! $located ) {
		$located = PB_DIR . 'templates/' . $template;
	}
	if ( ! is_readable( $located ) ) {
		return;
	}
	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
	extract( $args );
	include $located;
}

/**
 * @param string $template Relative template path.
 * @param array  $args     Variables.
 * @return string
 */
/** Cache busting: version = plugin version + file modification time, so every release reloads CSS/JS on every device. */
function pb_asset_ver( $relative ) {
	$file = PB_DIR . ltrim( $relative, '/' );
	return PB_VERSION . '.' . ( file_exists( $file ) ? filemtime( $file ) : 0 );
}

function pb_get_template_html( $template, $args = array() ) {
	ob_start();
	pb_get_template( $template, $args );
	return (string) ob_get_clean();
}
