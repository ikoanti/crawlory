<?php
/**
 * Utility helpers for Crawlory.
 *
 * Provides centralized UTC timestamp formatting and measurement availability helpers.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Helper {

	/**
	 * Return current UTC timestamp in MySQL datetime format (Y-m-d H:i:s).
	 *
	 * @param int|null $timestamp Optional Unix timestamp. Defaults to current time.
	 * @return string Formatted UTC datetime.
	 */
	public static function now_utc( $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = time();
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Compute measurement availability independently of freshness.
	 *
	 * A successfully evaluated measurement (including empty string or zero count)
	 * is distinct from unavailable (never evaluated or missing evaluation evidence).
	 * Schema defaults (such as h1_count defaulting to 0) are never treated as evaluation evidence.
	 *
	 * Freshness is determined conservatively from the latest attempt outcome rather than
	 * solely relying on comparisons between historical mixed-timezone timestamp values.
	 *
	 * @param object|array $page Page record.
	 * @param string       $metric_key 'seo_title', 'meta_description', 'h1', or 'html'.
	 * @return array Array with 'available' (bool) and 'stale' (bool).
	 */
	public static function get_measurement_availability( $page, $metric_key = 'html' ) {
		$p = (object) $page;

		$scan_status    = isset( $p->scan_status ) ? (string) $p->scan_status : 'pending';
		$last_succ      = isset( $p->last_successful_scanned_at ) ? $p->last_successful_scanned_at : null;
		$last_fail      = isset( $p->last_failed_scanned_at ) ? $p->last_failed_scanned_at : null;
		$has_prior_eval = ! empty( $last_succ );

		// Explicit successful evaluation evidence is strictly required for measurement availability.
		// A first failed scan or unscanned page has never been successfully evaluated.
		$is_evaluated = ( 'success' === $scan_status || $has_prior_eval );

		if ( ! $is_evaluated ) {
			return array(
				'available' => false,
				'stale'     => false,
			);
		}

		// Freshness determined conservatively from the latest attempt outcome:
		// 1. Explicitly marked 'stale' due to content edits.
		// 2. Prior successful evaluation exists, but the latest attempt failed or was interrupted.
		// 3. Prior successful evaluation exists, failure timestamp exists, and current state is not confirmed 'success'.
		$is_stale = ( 'stale' === $scan_status || in_array( $scan_status, array( 'failed', 'interrupted' ), true ) );
		if ( ! $is_stale && ! empty( $last_fail ) && 'success' !== $scan_status ) {
			$is_stale = true;
		}

		return array(
			'available' => true,
			'stale'     => $is_stale,
		);
	}
}
