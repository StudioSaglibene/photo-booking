<?php
/**
 * Uninstall: data is kept unless PB_REMOVE_ALL_DATA is true in wp-config.php
 * (appointments and payments are accounting records).
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'PB_REMOVE_ALL_DATA' ) || ! PB_REMOVE_ALL_DATA ) {
	return;
}
global $wpdb;
foreach ( array( 'appointments', 'blocks', 'proposals', 'payments', 'events', 'deliveries', 'locks' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pb_{$t}" ); // phpcs:ignore
}
delete_option( 'pb_settings' );
delete_option( 'pb_db_version' );
delete_option( 'pb_cron_last_run' );
wp_clear_scheduled_hook( 'pb_cron_tick' );
