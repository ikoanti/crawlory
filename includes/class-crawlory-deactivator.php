<?php
/**
 * Fired during plugin deactivation.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Deactivator {

	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate() {
		// Clear cron jobs.
		$timestamp = wp_next_scheduled( 'crawlory_scheduled_scan_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'crawlory_scheduled_scan_event' );
		}

		// Release any active scan locks.
		delete_transient( 'crawlory_scan_lock' );

		// Mark any running scan as paused so it does not remain stuck in running.
		global $wpdb;
		$table_scans = esc_sql( $wpdb->prefix . 'crawlory_scans' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_scans} SET status = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'paused',
				'running'
			)
		);
	}
}
