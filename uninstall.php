<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Crawlory
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Retrieve settings to see if the user opted to delete all data on uninstall.
$settings = get_option( 'crawlory_settings', array() );
$purge_on_uninstall = ! empty( $settings['purge_on_uninstall'] );

if ( $purge_on_uninstall ) {
	// Drop custom tables (both current crawlory_* and legacy tsd_*).
	$tables = array(
		esc_sql( $wpdb->prefix . 'crawlory_findings' ),
		esc_sql( $wpdb->prefix . 'crawlory_pages' ),
		esc_sql( $wpdb->prefix . 'crawlory_duplicate_groups' ),
		esc_sql( $wpdb->prefix . 'crawlory_scans' ),
		esc_sql( $wpdb->prefix . 'crawlory_links' ),
		esc_sql( $wpdb->prefix . 'crawlory_sitemaps' ),
		esc_sql( $wpdb->prefix . 'crawlory_scan_items' ),
		esc_sql( $wpdb->prefix . 'crawlory_scan_items_preserved' ),
		esc_sql( $wpdb->prefix . 'crawlory_scan_items_preserved_conflicts' ),
		esc_sql( $wpdb->prefix . 'crawlory_resource_cache' ),
		esc_sql( $wpdb->prefix . 'tsd_findings' ),
		esc_sql( $wpdb->prefix . 'tsd_pages' ),
		esc_sql( $wpdb->prefix . 'tsd_duplicate_groups' ),
		esc_sql( $wpdb->prefix . 'tsd_scans' ),
		esc_sql( $wpdb->prefix . 'tsd_links' ),
		esc_sql( $wpdb->prefix . 'tsd_sitemaps' ),
		esc_sql( $wpdb->prefix . 'tsd_scan_items' ),
		esc_sql( $wpdb->prefix . 'tsd_scan_items_preserved' ),
		esc_sql( $wpdb->prefix . 'tsd_scan_items_preserved_conflicts' ),
		esc_sql( $wpdb->prefix . 'tsd_resource_cache' ),
	);

	foreach ( $tables as $table ) {
		// Table names are safely constructed using wpdb prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Delete plugin options.
	delete_option( 'crawlory_settings' );
	delete_option( 'crawlory_db_version' );
	delete_option( 'crawlory_last_scan_id' );
	delete_option( 'crawlory_active_scan_id' );
	delete_option( 'crawlory_migration_completed' );
	delete_option( 'crawlory_migration_lock' );
	delete_option( 'crawlory_migration_error' );
	delete_option( 'tsd_settings' );
	delete_option( 'tsd_db_version' );
	delete_option( 'tsd_last_scan_id' );
	delete_option( 'tsd_active_scan_id' );

	// Delete any scan locks and transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_crawlory_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_crawlory_' ) . '%',
			$wpdb->esc_like( '_transient_tsd_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tsd_' ) . '%'
		)
	);

	// Clear scheduled cron events.
	wp_clear_scheduled_hook( 'crawlory_scheduled_scan_event' );
	wp_clear_scheduled_hook( 'crawlory_process_background_batch' );
	wp_clear_scheduled_hook( 'tsd_scheduled_scan_event' );
	wp_clear_scheduled_hook( 'tsd_process_background_batch' );
}
