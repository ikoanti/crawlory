<?php
/**
 * Cron scheduler for Technical SEO Dashboard.
 *
 * Handles automated periodic scans via WP-Cron with durable continuation.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Cron {

	const CRON_HOOK = 'crawlory_scheduled_scan_event';

	/**
	 * Scanner instance.
	 *
	 * @var Crawlory_Scanner
	 */
	private $scanner;

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_Scanner  $scanner Scanner instance.
	 * @param Crawlory_Settings $settings Settings instance.
	 */
	public function __construct( Crawlory_Scanner $scanner, Crawlory_Settings $settings ) {
		$this->scanner  = $scanner;
		$this->settings = $settings;
	}

	/**
	 * Initialize cron hooks and custom intervals.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_intervals' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
	}

	/**
	 * Register weekly and monthly intervals for WP-Cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'crawlory-technical-seo-dashboard' ),
			);
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Every 30 Days', 'crawlory-technical-seo-dashboard' ),
			);
		}

		return $schedules;
	}

	/**
	 * Sync WP-Cron schedule with current plugin settings.
	 */
	public function sync_schedule() {
		$schedule = $this->settings->get( 'scan_schedule', 'disabled' );
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( 'disabled' === $schedule || empty( $schedule ) ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
			return;
		}

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
	}

	/**
	 * Callback when cron triggers.
	 * Starts the scan and runs a bounded batch. The scanner automatically schedules
	 * background continuation until the entire inventory is complete.
	 */
	public function run_scheduled_scan() {
		$schedule = $this->settings->get( 'scan_schedule', 'disabled' );
		if ( 'disabled' === $schedule ) {
			return;
		}

		// Start a full scan.
		$res = $this->scanner->start_scan( 'full' );
		if ( empty( $res['success'] ) || empty( $res['scan_id'] ) ) {
			return;
		}

		$scan_id = (int) $res['scan_id'];

		// Execute initial slice in cron context.
		// If incomplete, scanner automatically enqueues crawlory_process_background_batch continuation.
		$this->scanner->process_work( $scan_id, 25 );
	}
}
