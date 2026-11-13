<?php
/**
 * Database manager for Technical SEO Dashboard.
 *
 * Handles custom tables, queries, migrations, pagination, and data retention.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_DB {

	/**
	 * Table names.
	 */
	public $scans_table;
	public $pages_table;
	public $findings_table;
	public $dup_table;
	public $links_table;
	public $sitemaps_table;
	public $scan_items_table;
	public $resource_cache_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->scans_table          = esc_sql( $wpdb->prefix . 'crawlory_scans' );
		$this->pages_table          = esc_sql( $wpdb->prefix . 'crawlory_pages' );
		$this->findings_table       = esc_sql( $wpdb->prefix . 'crawlory_findings' );
		$this->dup_table            = esc_sql( $wpdb->prefix . 'crawlory_duplicate_groups' );
		$this->links_table          = esc_sql( $wpdb->prefix . 'crawlory_links' );
		$this->sitemaps_table       = esc_sql( $wpdb->prefix . 'crawlory_sitemaps' );
		$this->scan_items_table     = esc_sql( $wpdb->prefix . 'crawlory_scan_items' );
		$this->resource_cache_table = esc_sql( $wpdb->prefix . 'crawlory_resource_cache' );
	}

	/**
	 * Create a new scan record.
	 *
	 * @param array $args Scan arguments.
	 * @return int Scan ID.
	 */
	public function create_scan( array $args = array() ) {
		global $wpdb;

		$now_utc = gmdate( 'Y-m-d H:i:s' );

		$defaults = array(
			'status'             => 'pending',
			'scan_type'          => 'full',
			'target_type'        => null,
			'total_urls'         => 0,
			'scanned_urls'       => 0,
			'failed_urls'        => 0,
			'findings_count'     => 0,
			'batch_size'         => 20,
			'cursor_offset'      => 0,
			'last_examined_id'   => 0,
			'examined_urls'      => 0,
			'excluded_urls'      => 0,
			'discovered_urls'    => 0,
			'pending_urls'       => 0,
			'processing_urls'    => 0,
			'discovery_status'   => 'pending',
			'finalization_stage' => 'none',
			'current_step'       => 'crawl',
			'started_at'         => $now_utc,
			'created_at'         => $now_utc,
		);

		$data = wp_parse_args( $args, $defaults );
		$wpdb->insert( $this->scans_table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing scan.
	 *
	 * Supports intentional non-worker operations (when $worker_token is empty/omitted)
	 * and atomic worker-owned updates requiring scan ID, matching worker token, and unexpired lease.
	 *
	 * @param int         $scan_id Scan ID.
	 * @param array       $data Fields to update.
	 * @param string|null $worker_token Optional worker token for lease-owned updates.
	 * @return bool True if updated or authoritatively verified unchanged; false on error, lost ownership, or expired lease.
	 */
	public function update_scan( $scan_id, array $data, $worker_token = null ) {
		global $wpdb;

		if ( empty( $scan_id ) || empty( $data ) ) {
			return false;
		}

		// Intentional non-worker operation (e.g. administrator pause, cancel, initial scan setup)
		if ( empty( $worker_token ) ) {
			$res = $wpdb->update( $this->scans_table, $data, array( 'id' => (int) $scan_id ) );
			return false !== $res;
		}

		// Worker-owned update: requires scan ID, matching worker token, and an unexpired lease in the atomic UPDATE condition
		$now_utc     = Crawlory_Helper::now_utc();
		$set_clauses = array();
		$values      = array();

		$allowed_columns = array(
			'status'               => 'string',
			'scan_type'            => 'string',
			'target_type'          => 'string',
			'total_urls'           => 'int',
			'scanned_urls'         => 'int',
			'failed_urls'          => 'int',
			'findings_count'       => 'int',
			'batch_size'           => 'int',
			'cursor_offset'        => 'int',
			'last_examined_id'     => 'int',
			'examined_urls'        => 'int',
			'excluded_urls'        => 'int',
			'discovered_urls'      => 'int',
			'pending_urls'         => 'int',
			'processing_urls'      => 'int',
			'discovery_status'     => 'string',
			'finalization_stage'   => 'string',
			'finalization_error'   => 'string',
			'auth_token_hash'      => 'string',
			'auth_token_expires'   => 'string',
			'target_urls'          => 'string',
			'current_step'         => 'string',
			'worker_token'         => 'string',
			'worker_lease_expires' => 'string',
			'started_at'           => 'string',
			'finished_at'          => 'string',
			'error_message'        => 'string',
			'created_at'           => 'string',
		);

		foreach ( $data as $column => $value ) {
			if ( ! isset( $allowed_columns[ $column ] ) ) {
				continue;
			}
			if ( null === $value ) {
				$set_clauses[] = "`{$column}` = NULL";
			} elseif ( 'int' === $allowed_columns[ $column ] ) {
				$set_clauses[] = "`{$column}` = %d";
				$values[]      = (int) $value;
			} elseif ( is_float( $value ) ) {
				$set_clauses[] = "`{$column}` = %f";
				$values[]      = (float) $value;
			} else {
				$set_clauses[] = "`{$column}` = %s";
				$values[]      = (string) $value;
			}
		}

		if ( empty( $set_clauses ) ) {
			return $this->verify_worker_lease( $scan_id, $worker_token );
		}

		$set_sql  = implode( ', ', $set_clauses );
		$values[] = (int) $scan_id;
		$values[] = $worker_token;
		$values[] = $now_utc;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Schema-allowlisted columns, trusted table identifier, and parameterized values with explicit SQL NULLs.
		$rows_affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$this->scans_table}` SET {$set_sql} WHERE id = %d AND worker_token = %s AND worker_lease_expires >= %s",
				$values
			)
		);

		// 1. Database error
		if ( false === $rows_affected ) {
			return false;
		}

		// 2. Successful change
		if ( $rows_affected > 0 ) {
			return true;
		}

		// 3. Zero affected rows: verify authoritative ownership, lease validity, and requested persisted values
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans_table}
				WHERE id = %d AND worker_token = %s AND worker_lease_expires >= %s",
				(int) $scan_id,
				$worker_token,
				$now_utc
			),
			ARRAY_A
		);

		if ( ! $row ) {
			// Lost or expired ownership
			return false;
		}

		// Verify every requested key matches persisted value
		foreach ( $data as $column => $value ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return false;
			}
			if ( null === $value ) {
				if ( null !== $row[ $column ] ) {
					return false;
				}
			} elseif ( is_numeric( $value ) && is_numeric( $row[ $column ] ) ) {
				if ( (string) $value !== (string) $row[ $column ] ) {
					return false;
				}
			} else {
				if ( (string) $value !== (string) $row[ $column ] ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get scan by ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	public function get_scan( $scan_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scans_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $scan_id
			)
		);
	}

	/**
	 * Get active scan if any.
	 *
	 * @return object|null
	 */
	public function get_active_scan() {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT * FROM {$this->scans_table} WHERE status IN ('running', 'pending', 'paused') ORDER BY id DESC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get latest interrupted scan if any.
	 *
	 * @return object|null
	 */
	public function get_interrupted_scan() {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT * FROM {$this->scans_table} WHERE status = 'interrupted' ORDER BY id DESC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Retrieve a page record by URL using prepared query and url_hash.
	 *
	 * @param string $url URL to look up.
	 * @return object|null Page record row or null if not found.
	 */
	public function get_page_by_url( $url ) {
		global $wpdb;

		if ( empty( $url ) || ! is_string( $url ) ) {
			return null;
		}

		$clean_url = esc_url_raw( trim( $url ) );
		if ( empty( $clean_url ) ) {
			return null;
		}

		$url_hash = md5( $clean_url );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->pages_table} WHERE url_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url_hash
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Retrieve a page record by its Crawlory audit page ID (primary key).
	 *
	 * Note: this is NOT the WordPress post ID.
	 *
	 * @param int $page_id Crawlory audit page ID.
	 * @return object|null Page record row or null if not found.
	 */
	public function get_page_by_id( $page_id ) {
		global $wpdb;

		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->pages_table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Summarise a list of finding rows (as returned by get_findings_for_page()).
	 *
	 * Active counts exclude ignored and resolved findings; ignored and resolved
	 * findings are reported separately as history.
	 *
	 * @param array $findings Finding rows.
	 * @return array Summary counts.
	 */
	public static function summarize_findings( array $findings ) {
		$summary = array(
			'active_total'   => 0,
			'active_error'   => 0,
			'active_warning' => 0,
			'active_info'    => 0,
			'ignored'        => 0,
			'resolved'       => 0,
		);

		foreach ( $findings as $f ) {
			$is_ignored  = ! empty( $f['is_ignored'] );
			$is_resolved = isset( $f['finding_status'] ) && 'resolved' === $f['finding_status'];

			if ( $is_ignored ) {
				$summary['ignored']++;
				continue;
			}
			if ( $is_resolved ) {
				$summary['resolved']++;
				continue;
			}

			$summary['active_total']++;
			$sev = isset( $f['severity'] ) ? $f['severity'] : '';
			if ( 'error' === $sev ) {
				$summary['active_error']++;
			} elseif ( 'warning' === $sev ) {
				$summary['active_warning']++;
			} else {
				$summary['active_info']++;
			}
		}

		return $summary;
	}

	/**
	 * Atomically claim a worker lease on a running scan using strict UTC.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Unique worker token.
	 * @param int    $lease_seconds Lease duration in seconds.
	 * @return bool True if lease was successfully claimed.
	 */
	public function claim_scan_job( $scan_id, $worker_token, $lease_seconds = 60 ) {
		global $wpdb;

		$now_utc     = gmdate( 'Y-m-d H:i:s' );
		$expires_utc = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );

		$rows_affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans_table}
				SET worker_token = %s,
					worker_lease_expires = %s
				WHERE id = %d
					AND status = 'running'
					AND (worker_token IS NULL OR worker_lease_expires < %s OR worker_token = %s)",
				$worker_token,
				$expires_utc,
				(int) $scan_id,
				$now_utc,
				$worker_token
			)
		);

		return ( false !== $rows_affected && $rows_affected > 0 );
	}

	/**
	 * Verify that the given worker token currently owns an unexpired lease in UTC.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Worker token.
	 * @return bool
	 */
	/**
	 * Verify that the given worker token currently owns an unexpired lease in UTC.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Worker token.
	 * @return bool
	 */
	public function verify_worker_lease( $scan_id, $worker_token ) {
		global $wpdb;
		$now_utc = Crawlory_Helper::now_utc();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$this->scans_table}
				WHERE id = %d
					AND worker_token = %s
					AND worker_lease_expires >= %s",
				(int) $scan_id,
				$worker_token,
				$now_utc
			)
		);
		return ( (int) $count > 0 );
	}

	/**
	 * Renew worker lease for currently owned worker token using strict UTC.
	 * Requires matching ownership and an unexpired existing lease as part of the atomic renewal condition.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Worker token.
	 * @param int    $lease_seconds Duration.
	 * @return bool True if lease renewed or authoritatively verified, false if expired or lost.
	 */
	public function renew_worker_lease( $scan_id, $worker_token, $lease_seconds = 60 ) {
		global $wpdb;

		if ( empty( $scan_id ) || empty( $worker_token ) ) {
			return false;
		}

		$now_utc     = Crawlory_Helper::now_utc();
		$expires_utc = Crawlory_Helper::now_utc( time() + $lease_seconds );

		$rows_affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans_table}
				SET worker_lease_expires = %s
				WHERE id = %d AND worker_token = %s AND worker_lease_expires >= %s",
				$expires_utc,
				(int) $scan_id,
				$worker_token,
				$now_utc
			)
		);

		if ( false === $rows_affected ) {
			return false;
		}

		if ( $rows_affected > 0 ) {
			return true;
		}

		// Zero affected rows: verify if this was an unexpired, owned no-op. If already expired, rejects authoritatively:
		return $this->verify_worker_lease( $scan_id, $worker_token );
	}

	/**
	 * Release worker lease owned by this worker.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Worker token.
	 * @return bool
	 */
	public function release_worker_lease( $scan_id, $worker_token ) {
		global $wpdb;

		$rows_affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scans_table}
				SET worker_token = NULL,
					worker_lease_expires = NULL
				WHERE id = %d AND worker_token = %s",
				(int) $scan_id,
				$worker_token
			)
		);

		return ( false !== $rows_affected && $rows_affected > 0 );
	}

	/**
	 * Add an item to the durable pending scan queue.
	 *
	 * Idempotent: checks for existing item matching (scan_id, url_hash).
	 * Distinguishes explicit outcomes:
	 * - 'inserted': Newly inserted queue item with its new ID.
	 * - 'existing': Pre-existing item for the same scan and URL with its existing ID.
	 * - 'failed': Invalid input or database error, with structured error message.
	 *
	 * Supports both structured array and discrete scalar arguments.
	 *
	 * @param int          $scan_id Scan ID.
	 * @param array|string $item Item data array with url, post_id, etc. OR URL string.
	 * @param int          $post_id Post ID (used if $item is string).
	 * @param string       $post_type Post type (used if $item is string).
	 * @param string       $post_title Post title (used if $item is string).
	 * @param string       $slug Slug (used if $item is string).
	 * @return array Structured outcome array with keys 'status' ('inserted'|'existing'|'failed'), 'id' (int), 'is_new' (bool), and 'error' (string|null).
	 */
	public function add_scan_item( $scan_id, $item, $post_id = 0, $post_type = 'page', $post_title = '', $slug = '' ) {
		global $wpdb;

		if ( is_array( $item ) ) {
			$raw_url    = isset( $item['url'] ) ? $item['url'] : '';
			$post_id    = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			$post_type  = isset( $item['post_type'] ) ? (string) $item['post_type'] : 'page';
			$post_title = isset( $item['post_title'] ) ? (string) $item['post_title'] : '';
			$slug       = isset( $item['slug'] ) ? (string) $item['slug'] : '';
		} else {
			$raw_url = (string) $item;
		}

		$scan_id = (int) $scan_id;
		if ( $scan_id <= 0 ) {
			return array(
				'status' => 'failed',
				'id'     => 0,
				'is_new' => false,
				'error'  => __( 'Invalid scan ID provided for scan item queue.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		$url = esc_url_raw( trim( $raw_url ) );
		if ( empty( $url ) ) {
			return array(
				'status' => 'failed',
				'id'     => 0,
				'is_new' => false,
				'error'  => __( 'Invalid or empty URL provided for scan item queue.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		$url_hash = md5( $url );

		// Check for an existing item for the same scan and URL hash.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->scan_items_table} WHERE scan_id = %d AND url_hash = %s LIMIT 1",
				$scan_id,
				$url_hash
			)
		);

		// Check the existing-item SELECT error immediately. Do not continue to INSERT after a failed lookup.
		if ( ! empty( $wpdb->last_error ) ) {
			return array(
				'status' => 'failed',
				'id'     => 0,
				'is_new' => false,
				'error'  => __( 'Database lookup error checking existing scan item: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error,
			);
		}

		if ( ! empty( $existing_id ) ) {
			return array(
				'status' => 'existing',
				'id'     => (int) $existing_id,
				'is_new' => false,
				'error'  => null,
			);
		}

		$data = array(
			'scan_id'      => $scan_id,
			'url'          => $url,
			'url_hash'     => $url_hash,
			'post_id'      => (int) $post_id,
			'post_title'   => (string) $post_title,
			'post_type'    => (string) $post_type,
			'slug'         => (string) $slug,
			'item_status'  => 'pending',
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		$result = $wpdb->insert( $this->scan_items_table, $data );

		if ( false === $result || ! empty( $wpdb->last_error ) ) {
			// Concurrency safety: check if another concurrent worker inserted the item in race condition.
			$existing_concurrent = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->scan_items_table} WHERE scan_id = %d AND url_hash = %s LIMIT 1",
					$scan_id,
					$url_hash
				)
			);
			if ( ! empty( $existing_concurrent ) ) {
				return array(
					'status' => 'existing',
					'id'     => (int) $existing_concurrent,
					'is_new' => false,
					'error'  => null,
				);
			}

			return array(
				'status' => 'failed',
				'id'     => 0,
				'is_new' => false,
				'error'  => __( 'Database insert error for scan item: ', 'crawlory-technical-seo-dashboard' ) . ( ! empty( $wpdb->last_error ) ? $wpdb->last_error : 'Failed to insert row.' ),
			);
		}

		$new_id = (int) $wpdb->insert_id;
		if ( $new_id <= 0 ) {
			return array(
				'status' => 'failed',
				'id'     => 0,
				'is_new' => false,
				'error'  => __( 'Database insert failed to generate a valid item ID.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		return array(
			'status' => 'inserted',
			'id'     => $new_id,
			'is_new' => true,
			'error'  => null,
		);
	}

	/**
	 * Atomically claim the next pending queue item for processing.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $worker_token Worker token.
	 * @return object|null Claimed item row or null if queue empty.
	 */
	public function claim_next_scan_item( $scan_id, $worker_token ) {
		global $wpdb;

		$now_utc = gmdate( 'Y-m-d H:i:s' );

		// Find earliest pending item for this scan.
		$item_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->scan_items_table}
				WHERE scan_id = %d AND item_status = 'pending'
				ORDER BY id ASC LIMIT 1",
				(int) $scan_id
			)
		);

		if ( ! $item_id ) {
			return null;
		}

		// Atomically claim the item.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scan_items_table}
				SET item_status = 'processing',
					worker_token = %s,
					claimed_at = %s
				WHERE id = %d AND item_status = 'pending'",
				$worker_token,
				$now_utc,
				$item_id
			)
		);

		if ( ! $updated ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->scan_items_table} WHERE id = %d",
				$item_id
			)
		);
	}

	/**
	 * Atomically commit terminal completion of a queue item.
	 *
	 * @param int|array   $item_id Item ID or outcome array from add_scan_item.
	 * @param string      $worker_token Worker token.
	 * @param string      $status 'completed' or 'failed'.
	 * @param int         $http_status HTTP status code.
	 * @param int         $findings_count Findings count.
	 * @param string|null $error Error message if failed.
	 * @return bool
	 */
	public function complete_scan_item( $item_id, $worker_token, $status = 'completed', $http_status = 200, $findings_count = 0, $error = null ) {
		global $wpdb;

		if ( is_array( $item_id ) && isset( $item_id['id'] ) ) {
			$item_id = $item_id['id'];
		}
		$item_id = (int) $item_id;
		if ( $item_id <= 0 ) {
			return false;
		}

		$now_utc = gmdate( 'Y-m-d H:i:s' );
		$status  = ( 'success' === $status ) ? 'completed' : $status;

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scan_items_table}
				SET item_status = %s,
					completed_at = %s,
					http_status = %d,
					findings_count = %d,
					error_message = %s
				WHERE id = %d AND (worker_token = %s OR worker_token IS NULL)",
				$status,
				$now_utc,
				(int) $http_status,
				(int) $findings_count,
				$error,
				$item_id,
				$worker_token
			)
		);
	}

	/**
	 * Reclaim orphaned/stalled items whose processing workers timed out or crashed.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $timeout_seconds Stall threshold in seconds.
	 * @return int Reclaimed item count.
	 */
	public function reap_stalled_scan_items( $scan_id, $timeout_seconds = 300 ) {
		global $wpdb;

		$threshold_utc = gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->scan_items_table}
				SET item_status = 'pending',
					worker_token = NULL,
					claimed_at = NULL
				WHERE scan_id = %d
					AND item_status = 'processing'
					AND claimed_at < %s",
				(int) $scan_id,
				$threshold_utc
			)
		);

		return (int) $affected;
	}

	/**
	 * Retrieve queue item counts for a scan.
	 * Calculates discovered/total counts from distinct persisted inventory,
	 * and pending counts from actual pending queue rows.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array
	 */
	public function get_scan_item_counts( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_status, COUNT(id) as count
				FROM {$this->scan_items_table}
				WHERE scan_id = %d
				GROUP BY item_status",
				$scan_id
			)
		);
		if ( ! empty( $wpdb->last_error ) || false === $rows ) {
			throw new Exception( __( 'Failed to retrieve scan item counts: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		// Calculate distinct total inventory count from distinct persisted inventory.
		$distinct_total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT url_hash) FROM {$this->scan_items_table} WHERE scan_id = %d",
				$scan_id
			)
		);
		if ( ! empty( $wpdb->last_error ) || null === $distinct_total ) {
			throw new Exception( __( 'Failed to retrieve distinct scan item counts: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		$counts = array(
			'total'      => (int) $distinct_total,
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
		);

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$status_key = ( 'success' === $r->item_status ) ? 'completed' : $r->item_status;
				if ( isset( $counts[ $status_key ] ) ) {
					$counts[ $status_key ] += (int) $r->count;
				}
			}
		}

		return $counts;
	}

	/**
	 * Retrieve cached resource from persistent deduplicated cache.
	 *
	 * Signature: get_cached_resource( $url, $type = 'link', $max_age_seconds = 86400 )
	 * Backwards compatible if 2nd parameter is passed as numeric max_age_seconds.
	 *
	 * @param string     $url Resource URL.
	 * @param string|int $type Resource type ('link', 'image', 'internal_link', etc.) or max_age_seconds for legacy callers.
	 * @param int        $max_age_seconds Freshness window in seconds (default 86400).
	 * @return array|null
	 */
	public function get_cached_resource( $url, $type = 'link', $max_age_seconds = 86400 ) {
		global $wpdb;

		if ( empty( $url ) || ! is_string( $url ) ) {
			return null;
		}

		// Backward compatibility: If 2nd argument is numeric, caller passed ($url, $max_age_seconds).
		if ( is_numeric( $type ) ) {
			$max_age_seconds = (int) $type;
			$type            = null;
		}

		$clean = esc_url_raw( trim( $url ) );
		if ( empty( $clean ) ) {
			return null;
		}

		$hash          = md5( $clean );
		$max_age       = max( 1, (int) $max_age_seconds );
		$threshold_utc = gmdate( 'Y-m-d H:i:s', time() - $max_age );

		if ( ! empty( $type ) && is_string( $type ) ) {
			$clean_type = sanitize_key( $type );
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->resource_cache_table}
					WHERE url_hash = %s AND resource_type = %s AND last_checked_at >= %s LIMIT 1",
					$hash,
					$clean_type,
					$threshold_utc
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->resource_cache_table}
					WHERE url_hash = %s AND last_checked_at >= %s LIMIT 1",
					$hash,
					$threshold_utc
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			return null;
		}

		// Validate database record integrity before returning.
		$status = isset( $row['http_status'] ) ? (int) $row['http_status'] : 0;
		if ( $status <= 0 ) {
			// Malformed or invalid record - treat as cache miss.
			return null;
		}

		// Provide both legacy and standard keys so all callers receive consistent payload.
		$row['http_status'] = $status;
		$row['status_code'] = $status;
		$row['is_broken']   = ( 1 === (int) $row['is_broken'] || true === $row['is_broken'] );
		$row['is_inconclusive'] = ( 1 === (int) $row['is_inconclusive'] || true === $row['is_inconclusive'] );
		$row['error']       = ! empty( $row['error_message'] ) ? $row['error_message'] : null;

		return $row;
	}

	/**
	 * Save resource check result in persistent deduplicated cache.
	 *
	 * Flexible contract accepting either:
	 * set_cached_resource( $url, $type, $http_status, $is_broken, $is_inconclusive, $error_message )
	 * OR
	 * set_cached_resource( $url, $type, array( 'status_code' => 200, 'is_broken' => false, ... ) )
	 *
	 * @param string            $url Resource URL.
	 * @param string            $type 'link', 'image', 'internal_link', 'external_link'.
	 * @param int|array         $http_status Status code int OR array of attributes.
	 * @param bool              $is_broken Broken flag.
	 * @param bool              $is_inconclusive Inconclusive flag.
	 * @param string|null       $error_message Diagnostic message.
	 * @return bool
	 */
	public function set_cached_resource( $url, $type, $http_status, $is_broken = false, $is_inconclusive = false, $error_message = null ) {
		global $wpdb;

		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$clean = esc_url_raw( trim( $url ) );
		if ( empty( $clean ) ) {
			return false;
		}

		$hash       = md5( $clean );
		$clean_type = ! empty( $type ) ? sanitize_key( $type ) : 'link';
		$now        = gmdate( 'Y-m-d H:i:s' );

		// If caller supplied array as 3rd parameter.
		if ( is_array( $http_status ) ) {
			$data            = $http_status;
			$status_code     = isset( $data['http_status'] ) ? (int) $data['http_status'] : ( isset( $data['status_code'] ) ? (int) $data['status_code'] : 0 );
			$is_broken       = ! empty( $data['is_broken'] );
			$is_inconclusive = ! empty( $data['is_inconclusive'] );
			$error_message   = isset( $data['error_message'] ) ? $data['error_message'] : ( isset( $data['error'] ) ? $data['error'] : null );
		} else {
			$status_code = (int) $http_status;
		}

		// Never cache non-positive HTTP statuses or inconclusive checks as healthy passes.
		if ( $status_code <= 0 && empty( $is_broken ) && empty( $is_inconclusive ) ) {
			return false;
		}

		// An unavailable destination (broken or 0 status) must never become a cached pass.
		if ( ( $status_code >= 400 || 0 === $status_code ) && ! $is_inconclusive ) {
			$is_broken = true;
		}

		return false !== $wpdb->replace(
			$this->resource_cache_table,
			array(
				'url_hash'        => $hash,
				'url'             => $clean,
				'resource_type'   => $clean_type,
				'http_status'     => (int) $status_code,
				'is_broken'       => ! empty( $is_broken ) ? 1 : 0,
				'is_inconclusive' => ! empty( $is_inconclusive ) ? 1 : 0,
				'error_message'   => is_string( $error_message ) ? $error_message : ( null !== $error_message ? wp_json_encode( $error_message ) : null ),
				'last_checked_at' => $now,
			)
		);
	}

	/**
	 * Check whether the database storage engine for participating tables supports transactions.
	 *
	 * Inspects information_schema.TABLES for confirmed transactional engine support (InnoDB/NDB/XtraDB).
	 * If engine inspection fails or returns an unknown engine, returns null (unknown).
	 * If any table uses a non-transactional engine (e.g. MyISAM, MEMORY), returns false.
	 * Returns true only when all participating tables have confirmed transaction support.
	 *
	 * @param array|null $tables Participating table names to inspect. Defaults to pages, findings, and links tables.
	 * @return bool|null True if confirmed transactional, false if unsupported, null if unknown.
	 */
	public function supports_transactions( $tables = null ) {
		global $wpdb;
		static $engine_cache = array();

		if ( null === $tables ) {
			$tables = array( $this->pages_table, $this->findings_table, $this->links_table );
		}

		$tables = array_filter( (array) $tables );
		if ( empty( $tables ) ) {
			return false;
		}

		foreach ( $tables as $tbl ) {
			if ( ! array_key_exists( $tbl, $engine_cache ) ) {
				$engine = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
						$tbl
					)
				);
				if ( ! empty( $wpdb->last_error ) || null === $engine || '' === trim( (string) $engine ) ) {
					$engine_cache[ $tbl ] = null;
				} else {
					$engine_cache[ $tbl ] = (string) $engine;
				}
			}

			$cached_engine = $engine_cache[ $tbl ];
			if ( null === $cached_engine ) {
				return null;
			}

			$tx_engines = array( 'innodb', 'ndb', 'xtradb' );
			if ( ! in_array( strtolower( $cached_engine ), $tx_engines, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Save or update page record.
	 *
	 * Preserves last successful metadata if the new attempt failed.
	 *
	 * @param array $page_data Page data.
	 * @return int Page ID.
	 */
	public function upsert_page( array $page_data ) {
		global $wpdb;

		$url      = $page_data['url'];
		$url_hash = md5( $url );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->pages_table} WHERE url_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url_hash
			)
		);

		$now = Crawlory_Helper::now_utc();
		$page_data['url_hash']   = $url_hash;
		$page_data['updated_at'] = $now;

		if ( $existing ) {
			// If scan failed, do NOT erase previous valid HTML metadata!
			if ( isset( $page_data['scan_status'] ) && 'failed' === $page_data['scan_status'] ) {
				$fields_to_preserve = array(
					'seo_title', 'seo_title_length', 'meta_description', 'meta_description_length',
					'h1_count', 'h1_contents', 'canonical_url', 'canonical_status',
					'robots_meta', 'normalized_title', 'normalized_description',
					'title_duplicate_group_id', 'desc_duplicate_group_id', 'last_successful_scanned_at',
					'scan_id'
				);
				foreach ( $fields_to_preserve as $fp ) {
					if ( isset( $existing->$fp ) && null !== $existing->$fp && ( ! isset( $page_data[ $fp ] ) || empty( $page_data[ $fp ] ) ) ) {
						$page_data[ $fp ] = $existing->$fp;
					}
				}
			}

			$update_result = $wpdb->update( $this->pages_table, $page_data, array( 'id' => (int) $existing->id ) );
			if ( false === $update_result ) {
				return false;
			}
			return (int) $existing->id;
		}

		$insert_result = $wpdb->insert( $this->pages_table, $page_data );
		if ( false === $insert_result || empty( $wpdb->insert_id ) ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Synchronize findings for a page with complete lifecycle tracking and check execution status.
	 *
	 * Distinguishes new, persistent, resolved, and reappeared findings.
	 * Only resolves a previous finding if an applicable check was successfully evaluated and found no defect.
	 * Never resolves findings after failed fetches, timeouts, or inconclusive checks.
	 *
	 * @param int    $page_id Page ID.
	 * @param array  $findings List of finding arrays.
	 * @param array  $check_statuses Map of rule_id => check execution status ('evaluated_successfully', 'not_applicable', 'not_evaluated', 'inconclusive', 'failed').
	 * @param string $scan_outcome Fetch outcome ('success', 'http_error', 'timeout', etc.).
	 * @return int Count of active findings saved.
	 */
	public function replace_page_findings( $page_id, array $findings, array $check_statuses = array(), $scan_outcome = 'success' ) {
		global $wpdb;

		if ( isset( $check_statuses['scan_outcome'] ) ) {
			$scan_outcome = $check_statuses['scan_outcome'];
			unset( $check_statuses['scan_outcome'] );
		}

		$page_id = (int) $page_id;
		$now     = Crawlory_Helper::now_utc();

		// 1. Fetch existing non-sitewide findings for this page.
		// Note: Rules 14, 17 (duplicates) and 27 (orphans) are handled by sitewide managers.
		$existing_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->findings_table} WHERE page_id = %d AND rule_id NOT IN (14, 17, 27)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page_id
			),
			ARRAY_A
		);

		$existing_by_hash = array();
		foreach ( $existing_rows as $row ) {
			$hash = ! empty( $row['evidence_hash'] ) ? $row['evidence_hash'] : md5( $row['rule_id'] . ':' . $row['observed_value'] );
			$existing_by_hash[ $hash ] = $row;
		}

		$processed_hashes   = array();
		$image_alt_count    = 0;
		$active_count       = 0;
		$persistence_failed = false;

		foreach ( $findings as $f ) {
			$rule_id     = (int) $f['rule_id'];
			$observed    = (string) $f['observed_value'];
			$context_key = '';

			if ( ! empty( $f['context_data'] ) ) {
				$ctx = is_array( $f['context_data'] ) ? $f['context_data'] : json_decode( $f['context_data'], true );
				if ( ! empty( $ctx['image_src'] ) ) {
					$context_key = $ctx['image_src'];
				} elseif ( ! empty( $ctx['image_url'] ) ) {
					$context_key = $ctx['image_url'];
				} elseif ( ! empty( $ctx['destination'] ) ) {
					$context_key = $ctx['destination'];
				} elseif ( ! empty( $ctx['target_url'] ) ) {
					$context_key = $ctx['target_url'];
				}
			}

			// Generate stable evidence hash incorporating rule_id, observed value, and affected element/target.
			$evidence_hash = ! empty( $f['evidence_hash'] ) ? $f['evidence_hash'] : md5( $rule_id . ':' . $observed . ':' . $context_key );
			$processed_hashes[] = $evidence_hash;

			$context_json = isset( $f['context_data'] ) ? ( is_array( $f['context_data'] ) ? wp_json_encode( $f['context_data'] ) : $f['context_data'] ) : null;
			$why          = isset( $f['why_warrants_attention'] ) ? $f['why_warrants_attention'] : '';
			$correction   = isset( $f['suggested_correction'] ) ? $f['suggested_correction'] : '';
			$verify       = isset( $f['how_to_verify'] ) ? $f['how_to_verify'] : '';
			$chk_status   = isset( $check_statuses[ $rule_id ] ) ? $check_statuses[ $rule_id ] : 'unknown';

			if ( 10 === $rule_id ) {
				$image_alt_count++;
			}

			if ( isset( $existing_by_hash[ $evidence_hash ] ) ) {
				// Finding existed before.
				$old        = $existing_by_hash[ $evidence_hash ];
				$is_ignored = (int) $old['is_ignored'];
				$status     = 'persistent';

				// If it was previously resolved and reappeared:
				if ( 'resolved' === $old['finding_status'] ) {
					$status = 'reappeared';
				}

				if ( ! $is_ignored && 'resolved' !== $status ) {
					$active_count++;
				}

				$update_payload = array(
					'rule_name'              => $f['rule_name'],
					'severity'               => $f['severity'],
					'category'               => $f['category'],
					'finding_status'         => $status,
					'check_status'           => $chk_status,
					'evidence_hash'          => $evidence_hash,
					'message'                => $f['message'],
					'observed_value'         => $observed,
					'expected_threshold'     => $f['expected_threshold'],
					'why_warrants_attention' => $why,
					'suggested_correction'   => $correction,
					'how_to_verify'          => $verify,
					'context_data'           => $context_json,
					'last_checked_at'        => $now,
					'resolved_at'            => null,
				);

				if ( 'reappeared' === $status ) {
					$update_payload['reappeared_at'] = $now;
				}

				$upd_res = $wpdb->update(
					$this->findings_table,
					$update_payload,
					array( 'id' => (int) $old['id'] )
				);
				if ( false === $upd_res ) {
					$persistence_failed = true;
				}
			} else {
				// Brand new finding.
				// Match ignore state ONLY by exact evidence_hash or specific finding identity,
				// never blindly ignore because a completely different link or image was ignored.
				$is_ignored = 0;
				$prev_ignored = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$this->findings_table} WHERE page_id = %d AND evidence_hash = %s AND is_ignored = 1 LIMIT 1",
						$page_id,
						$evidence_hash
					)
				);
				if ( $prev_ignored ) {
					$is_ignored = 1;
				}

				if ( ! $is_ignored ) {
					$active_count++;
				}

				$ins_res = $wpdb->insert(
					$this->findings_table,
					array(
						'page_id'                => $page_id,
						'rule_id'                => $rule_id,
						'rule_name'              => $f['rule_name'],
						'severity'               => $f['severity'],
						'category'               => $f['category'],
						'finding_status'         => 'new',
						'check_status'           => $chk_status,
						'evidence_hash'          => $evidence_hash,
						'message'                => $f['message'],
						'observed_value'         => $observed,
						'expected_threshold'     => $f['expected_threshold'],
						'why_warrants_attention' => $why,
						'suggested_correction'   => $correction,
						'how_to_verify'          => $verify,
						'context_data'           => $context_json,
						'is_ignored'             => $is_ignored,
						'first_detected_at'      => $now,
						'last_checked_at'        => $now,
						'resolved_at'            => null,
						'created_at'             => $now,
					)
				);
				if ( false === $ins_res ) {
					$persistence_failed = true;
				}
			}
		}

		// 2. Mark previous findings that disappeared as RESOLVED.
		// ONLY resolve if:
		// - The scan outcome was a successful HTML audit, AND
		// - The specific check for that rule was evaluated successfully and applicable!
		if ( 'success' === $scan_outcome ) {
			foreach ( $existing_by_hash as $hash => $old_row ) {
				$old_rule_id = (int) $old_row['rule_id'];
				$rule_status = isset( $check_statuses[ $old_rule_id ] ) ? $check_statuses[ $old_rule_id ] : 'unknown';

				// ONLY resolve if the specific check for that rule was evaluated successfully and found no defect.
				// If not evaluated, inconclusive, failed, or disabled (not_applicable), DO NOT resolve!
				if ( 'evaluated_successfully' !== $rule_status ) {
					continue;
				}

				if ( ! in_array( $hash, $processed_hashes, true ) && 'resolved' !== $old_row['finding_status'] ) {
					$res_upd = $wpdb->update(
						$this->findings_table,
						array(
							'finding_status'  => 'resolved',
							'check_status'    => 'evaluated_successfully',
							'resolved_at'     => $now,
							'last_checked_at' => $now,
						),
						array( 'id' => (int) $old_row['id'] )
					);
					if ( false === $res_upd ) {
						$persistence_failed = true;
					}
				}
			}
		}

		// 3. Recount total active findings for this page (including duplicates).
		$total_page_findings = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->findings_table} WHERE page_id = %d AND is_ignored = 0 AND finding_status != 'resolved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page_id
			)
		);

		// Update page record findings count.
		$page_upd = $wpdb->update(
			$this->pages_table,
			array(
				'findings_count'           => $total_page_findings,
				'image_alt_findings_count' => $image_alt_count,
				'updated_at'               => $now,
			),
			array( 'id' => $page_id )
		);
		if ( false === $page_upd ) {
			$persistence_failed = true;
		}

		if ( $persistence_failed ) {
			return false;
		}

		return $active_count;
	}

	/**
	 * Persist links discovered on a scanned page.
	 *
	 * @param int    $page_id Source page ID.
	 * @param string $source_url Source URL.
	 * @param array  $links Discovered links array.
	 * @return int Number of links saved.
	 */
	public function save_page_links( $page_id, $source_url, array $links ) {
		global $wpdb;

		$page_id = (int) $page_id;
		// Delete previous links for this page.
		$del = $wpdb->delete( $this->links_table, array( 'source_page_id' => $page_id ) );
		if ( false === $del ) {
			return false;
		}

		if ( empty( $links ) ) {
			return 0;
		}

		$now   = Crawlory_Helper::now_utc();
		$count = 0;

		foreach ( $links as $link ) {
			$target_url = esc_url_raw( $link['target_url'] );
			if ( empty( $target_url ) ) {
				continue;
			}
			$target_hash = md5( $target_url );

			$ins = $wpdb->insert(
				$this->links_table,
				array(
					'source_page_id'     => $page_id,
					'source_url'         => $source_url,
					'target_url'         => $target_url,
					'target_url_hash'    => $target_hash,
					'anchor_text'        => isset( $link['anchor_text'] ) ? sanitize_text_field( $link['anchor_text'] ) : '',
					'is_internal'        => ! empty( $link['is_internal'] ) ? 1 : 0,
					'http_status'        => isset( $link['http_status'] ) ? (int) $link['http_status'] : null,
					'status_type'        => isset( $link['status_type'] ) ? sanitize_key( $link['status_type'] ) : 'pending',
					'redirect_final_url' => isset( $link['redirect_final_url'] ) ? esc_url_raw( $link['redirect_final_url'] ) : null,
					'redirect_hops'      => isset( $link['redirect_hops'] ) ? absint( $link['redirect_hops'] ) : 0,
					'last_checked_at'    => $now,
				)
			);
			if ( false === $ins ) {
				return false;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * Mark a page record as failed following an audit or persistence error.
	 *
	 * Preserves previous valid HTML metadata while recording failure timestamp and error message.
	 *
	 * @param int    $page_id Page record ID.
	 * @param string $error_message Error description.
	 * @return bool True on success, false on failure.
	 */
	public function mark_page_failed( $page_id, $error_message = '' ) {
		global $wpdb;

		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return false;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->pages_table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page_id
			)
		);

		if ( ! $exists ) {
			return false;
		}

		$now = Crawlory_Helper::now_utc();
		return false !== $wpdb->update(
			$this->pages_table,
			array(
				'scan_status'            => 'failed',
				'fetch_error'            => ! empty( $error_message ) ? sanitize_text_field( $error_message ) : 'Persistence failure',
				'last_failed_scanned_at' => $now,
				'updated_at'             => $now,
			),
			array( 'id' => (int) $page_id )
		);
	}

	/**
	 * Retrieve links discovered for a specific page.
	 *
	 * @param int  $page_id Page ID.
	 * @param bool $internal_only Filter internal only.
	 * @return array
	 */
	public function get_page_links( $page_id, $internal_only = false ) {
		global $wpdb;

		if ( $internal_only ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
					"SELECT * FROM {$this->links_table} WHERE source_page_id = %d AND is_internal = 1 ORDER BY id ASC",
					(int) $page_id
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
				"SELECT * FROM {$this->links_table} WHERE source_page_id = %d ORDER BY id ASC",
				(int) $page_id
			),
			ARRAY_A
		);
	}

	/**
	 * Save URLs extracted from XML sitemaps.
	 *
	 * @param array  $urls Array of URL strings.
	 * @param string $sitemap_url Source sitemap URL.
	 * @param int    $http_status HTTP status code.
	 * @param string $discovery_status Discovery status ('valid', 'error', 'partial').
	 * @return int Saved count.
	 */
	public function save_sitemap_urls( array $urls, $sitemap_url, $http_status = 200, $discovery_status = 'valid' ) {
		global $wpdb;

		if ( empty( $urls ) ) {
			return 0;
		}

		$now   = Crawlory_Helper::now_utc();
		$count = 0;

		foreach ( $urls as $url ) {
			$clean_url = esc_url_raw( $url );
			if ( empty( $clean_url ) ) {
				continue;
			}
			$hash = md5( $clean_url );

			$wpdb->replace(
				$this->sitemaps_table,
				array(
					'sitemap_url'      => $sitemap_url,
					'url'              => $clean_url,
					'url_hash'         => $hash,
					'http_status'      => (int) $http_status,
					'discovery_status' => sanitize_key( $discovery_status ),
					'last_checked_at'  => $now,
				)
			);
			$count++;
		}

		// Update in_sitemap flag on crawlory_pages.
		$wpdb->query(
			"UPDATE {$this->pages_table} p
			INNER JOIN {$this->sitemaps_table} s ON s.url_hash = p.url_hash
			SET p.in_sitemap = 1"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Prune removed URLs from a successfully refreshed sitemap.
	 *
	 * Ensures removed URLs do not remain listed forever, without erasing reliable
	 * previous data if a fetch temporarily fails.
	 *
	 * @param string $sitemap_url Source sitemap URL.
	 * @param array  $active_hashes Hashes of URLs present in latest successful parse.
	 */
	public function prune_stale_sitemap_urls( $sitemap_url, array $active_hashes ) {
		global $wpdb;

		if ( empty( $active_hashes ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $active_hashes ), '%s' ) );
		$params       = array_merge( array( $sitemap_url ), array_values( $active_hashes ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->sitemaps_table} WHERE sitemap_url = %s AND url_hash NOT IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$params
			)
		);

		// Recompute in_sitemap flag across pages.
		$wpdb->query(
			"UPDATE {$this->pages_table} p
			LEFT JOIN {$this->sitemaps_table} s ON s.url_hash = p.url_hash
			SET p.in_sitemap = (CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Recalculate orphan pages across current scan inventory.
	 *
	 * Compares WordPress content inventory with internal incoming links.
	 * Excludes static/blog homepage and self-links.
	 *
	 * @param bool $is_rule_enabled Whether Rule 27 is enabled.
	 * @param string $severity Configured severity.
	 * @return array Orphan statistics.
	 */
	/**
	 * Recount active findings count on all pages in the database using set-based SQL.
	 *
	 * Strictly defines active findings as is_ignored = 0 AND finding_status != 'resolved'.
	 *
	 * @throws Exception On query failure or database error.
	 */
	public function refresh_all_page_findings_counts() {
		global $wpdb;

		$res = $wpdb->query(
			"UPDATE {$this->pages_table} p
			LEFT JOIN (
				SELECT page_id, COUNT(*) as active_count
				FROM {$this->findings_table}
				WHERE is_ignored = 0 AND finding_status != 'resolved'
				GROUP BY page_id
			) f ON f.page_id = p.id
			SET p.findings_count = COALESCE(f.active_count, 0)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $res || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to refresh page findings counts: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}
	}

	/**
	 * Recalculate orphan pages across current scan inventory.
	 *
	 * Compares WordPress content inventory with internal incoming links.
	 * Excludes static/blog homepage and self-links.
	 *
	 * @param bool $is_rule_enabled Whether Rule 27 is enabled.
	 * @param string $severity Configured severity.
	 * @return array Orphan statistics.
	 */
	public function recalculate_orphan_pages( $is_rule_enabled = true, $severity = 'warning' ) {
		global $wpdb;

		$participating_tables = array( $this->pages_table, $this->findings_table, $this->links_table );
		$supports_tx = $this->supports_transactions( $participating_tables );
		if ( true !== $supports_tx ) {
			throw new Exception( __( 'Cannot safely recalculate orphan pages: database tables do not support confirmed transactions.', 'crawlory-technical-seo-dashboard' ) );
		}

		$tx_started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $tx_started || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to start transaction for orphan recalculation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		try {
			// 1. Recount incoming internal links for each page, strictly ignoring self-links.
			$res1 = $wpdb->query(
				"UPDATE {$this->pages_table} p
				LEFT JOIN (
					SELECT target_url_hash, COUNT(*) as in_count
					FROM {$this->links_table}
					WHERE is_internal = 1 AND source_url != target_url
					GROUP BY target_url_hash
				) l ON l.target_url_hash = p.url_hash
				SET p.internal_incoming_links_count = COALESCE(l.in_count, 0)"
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $res1 || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to recalculate incoming internal link counts: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			// 2. Identify published orphan pages:
			// Published, scanned, not homepage, and 0 incoming internal links.
			$home_hash = md5( home_url( '/' ) );

			$res2 = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->pages_table}
					SET is_orphan = (CASE WHEN post_type != 'homepage' AND url_hash != %s AND internal_incoming_links_count = 0 AND scan_status = 'success' THEN 1 ELSE 0 END)",
					$home_hash
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $res2 || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to update orphan page flags: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			// 3. Sync Rule 27 findings for orphan pages if rule is enabled.
			$now = Crawlory_Helper::now_utc();

			if ( ! $is_rule_enabled ) {
				// Mark all active Rule 27 findings as resolved if rule disabled, preserving ignored flags and audit history.
				$res3 = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->findings_table}
						SET finding_status = 'resolved', resolved_at = %s
						WHERE rule_id = 27 AND is_ignored = 0 AND finding_status != 'resolved'",
						$now
					)
				);
				if ( false === $res3 || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to resolve Rule 27 findings for disabled rule: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$this->refresh_all_page_findings_counts();
				$commit_res = $wpdb->query( 'COMMIT' );
				if ( false === $commit_res || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to commit transaction for orphan recalculation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				return array( 'orphan_count' => 0 );
			}

			$orphans = $wpdb->get_results(
				"SELECT id, url, post_title, post_type FROM {$this->pages_table} WHERE is_orphan = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( false === $orphans || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to query orphan pages: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}
			$orphans = (array) $orphans;

			// Mark previous Rule 27 findings as resolved for non-orphans.
			$res4 = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->findings_table} f
					INNER JOIN {$this->pages_table} p ON p.id = f.page_id
					SET f.finding_status = 'resolved', f.resolved_at = %s
					WHERE f.rule_id = 27 AND p.is_orphan = 0 AND f.finding_status != 'resolved'",
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $res4 || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to resolve previous orphan findings: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			// Insert or update Rule 27 for current orphans.
			foreach ( $orphans as $orphan ) {
				$p_id = (int) $orphan['id'];
				$existing_f = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, finding_status, is_ignored FROM {$this->findings_table} WHERE page_id = %d AND rule_id = 27", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$p_id
					)
				);
				if ( ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to query existing orphan finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}

				$msg = 'Potential orphan page: No internal incoming links pointing to this page were discovered during the scan.';

				if ( ! $existing_f ) {
					$ins = $wpdb->insert(
						$this->findings_table,
						array(
							'page_id'                => $p_id,
							'rule_id'                => 27,
							'rule_name'              => 'Potential orphan page',
							'severity'               => $severity,
							'category'               => 'structure',
							'finding_status'         => 'new',
							'check_status'           => 'evaluated_successfully',
							'evidence_hash'          => md5( '27:orphan:' . $orphan['url'] ),
							'message'                => $msg,
							'observed_value'         => '0 incoming internal links found',
							'expected_threshold'     => 'At least 1 incoming internal link',
							'why_warrants_attention' => 'Orphan pages receive zero link equity, are difficult for search engine crawlers to discover, and generate low organic visibility.',
							'suggested_correction'   => 'Add contextual internal links from relevant parent pages, blog articles, category hubs, or site navigation.',
							'how_to_verify'          => 'Add internal links pointing to this URL and perform a rescan to verify that internal incoming links are detected.',
							'context_data'           => wp_json_encode( array( 'url' => $orphan['url'], 'incoming_links' => 0 ) ),
							'is_ignored'             => 0,
							'first_detected_at'      => $now,
							'last_checked_at'        => $now,
							'created_at'             => $now,
						)
					);
					if ( false === $ins || ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to insert orphan finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
				} else {
					$new_status = 'persistent';
					$reappear_at = null;
					if ( 'resolved' === $existing_f->finding_status ) {
						$new_status = 'reappeared';
						$reappear_at = $now;
					}

					$update_data = array(
						'finding_status'  => $new_status,
						'check_status'    => 'evaluated_successfully',
						'severity'        => $severity,
						'last_checked_at' => $now,
						'resolved_at'     => null,
					);
					if ( $reappear_at ) {
						$update_data['reappeared_at'] = $reappear_at;
					}

					$upd = $wpdb->update(
						$this->findings_table,
						$update_data,
						array( 'id' => (int) $existing_f->id )
					);
					if ( false === $upd || ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to update orphan finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
				}
			}

			// Synchronize page finding counts before commit
			$this->refresh_all_page_findings_counts();

			$commit_res = $wpdb->query( 'COMMIT' );
			if ( false === $commit_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to commit transaction for orphan recalculation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			return array(
				'orphan_count' => count( $orphans ),
			);
		} catch ( Throwable $t ) {
			$rb_res = $wpdb->query( 'ROLLBACK' );
			if ( false === $rb_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception(
					sprintf(
						/* translators: 1: original error message, 2: rollback error */
						__( 'Orphan reconciliation failed (%1$s) and rollback also failed (%2$s).', 'crawlory-technical-seo-dashboard' ),
						$t->getMessage(),
						$wpdb->last_error ? $wpdb->last_error : 'Unknown rollback failure'
					),
					0,
					$t
				);
			}
			throw $t;
		}
	}

	/**
	 * Toggle ignore status on a specific finding.
	 *
	 * @param int  $finding_id Finding ID.
	 * @param bool $ignore True to ignore, false to unignore.
	 * @param int  $user_id User ID performing action.
	 * @return bool
	 */
	public function toggle_ignore_finding( $finding_id, $ignore = true, $user_id = 0 ) {
		global $wpdb;

		$finding = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->findings_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $finding_id
			)
		);

		if ( ! $finding ) {
			return false;
		}

		$update_data = array(
			'is_ignored' => $ignore ? 1 : 0,
			'ignored_at' => $ignore ? Crawlory_Helper::now_utc() : null,
			'ignored_by' => $ignore ? (int) $user_id : null,
		);

		$wpdb->update( $this->findings_table, $update_data, array( 'id' => (int) $finding_id ) );

		// Update page findings count excluding resolved findings.
		$new_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->findings_table} WHERE page_id = %d AND is_ignored = 0 AND finding_status != 'resolved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$finding->page_id
			)
		);

		$wpdb->update(
			$this->pages_table,
			array( 'findings_count' => $new_count, 'updated_at' => Crawlory_Helper::now_utc() ),
			array( 'id' => $finding->page_id )
		);

		return true;
	}

	/**
	 * Mark a page as stale when content is updated.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public function mark_post_stale( $post_id ) {
		global $wpdb;
		return false !== $wpdb->update(
			$this->pages_table,
			array( 'scan_status' => 'stale', 'updated_at' => Crawlory_Helper::now_utc() ),
			array( 'post_id' => (int) $post_id )
		);
	}

	/**
	 * Delete a page and its findings when post is deleted.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public function delete_post_page( $post_id ) {
		global $wpdb;
		$page_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->pages_table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $post_id
			)
		);

		if ( $page_id ) {
			$wpdb->delete( $this->findings_table, array( 'page_id' => $page_id ) );
			$wpdb->delete( $this->pages_table, array( 'id' => $page_id ) );
			return true;
		}

		return false;
	}

	/**
	 * Retrieve dashboard overview statistics.
	 *
	 * @return array
	 */
	public function get_dashboard_stats() {
		global $wpdb;

		// 1. Total recorded URLs.
		$total_recorded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 2. Successfully scanned URLs.
		$scanned_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE scan_status = 'success'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 3. URLs with active findings.
		$urls_with_findings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE findings_count > 0 AND scan_status = 'success'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 4. Total active findings across all URLs (excluding resolved).
		$total_findings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->findings_table} WHERE is_ignored = 0 AND finding_status != 'resolved'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 5. Findings lifecycle breakdowns.
		$new_findings        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->findings_table} WHERE finding_status = 'new' AND is_ignored = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$persistent_findings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->findings_table} WHERE finding_status = 'persistent' AND is_ignored = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$resolved_findings   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->findings_table} WHERE finding_status = 'resolved'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ignored_findings    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->findings_table} WHERE is_ignored = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 6. Failed scans.
		$failed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE scan_status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 7. Pending or stale.
		$pending_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE scan_status IN ('pending', 'stale')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 8. Priority Category Counts.
		$orphan_count        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE is_orphan = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$broken_links_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->links_table} WHERE is_internal = 1 AND status_type = 'broken'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexing_blocked    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE (is_noindex = 1 OR is_robots_blocked = 1) AND scan_status = 'success'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 9. Last completed scan info.
		$last_scan = $wpdb->get_row( "SELECT * FROM {$this->scans_table} WHERE status = 'completed' ORDER BY finished_at DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 10. Breakdown by rule count for filter pills.
		$rule_counts = $wpdb->get_results(
			"SELECT rule_id, COUNT(*) as count FROM {$this->findings_table} WHERE is_ignored = 0 AND finding_status != 'resolved' GROUP BY rule_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$rule_map = array();
		foreach ( $rule_counts as $rc ) {
			$rule_map[ (int) $rc['rule_id'] ] = (int) $rc['count'];
		}

		return array(
			'total_urls'            => $total_recorded,
			'scanned_urls'          => $scanned_count,
			'urls_with_findings'    => $urls_with_findings,
			'total_findings'        => $total_findings,
			'new_findings'          => $new_findings,
			'persistent_findings'   => $persistent_findings,
			'resolved_findings'     => $resolved_findings,
			'ignored_findings'      => $ignored_findings,
			'failed_urls'           => $failed_count,
			'pending_urls'          => $pending_count,
			'orphan_pages'          => $orphan_count,
			'broken_internal_links' => $broken_links_count,
			'indexing_blocked'      => $indexing_blocked,
			'last_scan'             => $last_scan,
			'rule_counts'           => $rule_map,
		);
	}

	/**
	 * Retrieve paginated pages list with rich filtering.
	 *
	 * @param array $args Filter and pagination parameters.
	 * @return array Array with 'items' and 'total'.
	 */
	public function get_pages( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'page'           => 1,
			'per_page'       => 20,
			'orderby'        => 'findings_count',
			'order'          => 'DESC',
			'post_type'      => '',
			'rule_id'        => 0,
			'severity'       => '',
			'category'       => '',
			'scan_status'    => '',
			'finding_status' => '',
			'is_orphan'      => null,
			'broken_links'   => null,
			'search'         => '',
			'has_issues'     => null,
			'is_ignored'     => 0,
		);

		$params = wp_parse_args( $args, $defaults );

		$page     = max( 1, (int) $params['page'] );
		$per_page = max( 1, min( 100, (int) $params['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where_clauses = array( '1=1' );
		$where_params  = array();
		$join          = '';

		// Filter by post_type.
		if ( ! empty( $params['post_type'] ) ) {
			$where_clauses[] = 'p.post_type = %s';
			$where_params[]  = sanitize_key( $params['post_type'] );
		}

		// Filter by scan_status.
		if ( ! empty( $params['scan_status'] ) ) {
			$where_clauses[] = 'p.scan_status = %s';
			$where_params[]  = sanitize_key( $params['scan_status'] );
		}

		// Filter by is_orphan.
		if ( null !== $params['is_orphan'] ) {
			$where_clauses[] = 'p.is_orphan = %d';
			$where_params[]  = $params['is_orphan'] ? 1 : 0;
		}

		// Filter by broken_links.
		if ( ! empty( $params['broken_links'] ) ) {
			$where_clauses[] = 'p.broken_internal_links_count > 0';
		}

		// Filter by issues.
		if ( true === $params['has_issues'] ) {
			$where_clauses[] = 'p.findings_count > 0';
		}

		// Search.
		if ( ! empty( $params['search'] ) ) {
			$search = '%' . $wpdb->esc_like( trim( $params['search'] ) ) . '%';
			if ( is_numeric( $params['search'] ) ) {
				$where_clauses[] = '(p.url LIKE %s OR p.post_title LIKE %s OR p.post_id = %d)';
				$where_params[]  = $search;
				$where_params[]  = $search;
				$where_params[]  = (int) $params['search'];
			} else {
				$where_clauses[] = '(p.url LIKE %s OR p.post_title LIKE %s)';
				$where_params[]  = $search;
				$where_params[]  = $search;
			}
		}

		// Filter by rule_id, severity, category, or finding_status requires joining findings table.
		$rule_id        = (int) $params['rule_id'];
		$severity       = sanitize_key( $params['severity'] );
		$category       = sanitize_key( $params['category'] );
		$finding_status = sanitize_key( $params['finding_status'] );

		if ( $rule_id > 0 || ! empty( $severity ) || ! empty( $category ) || ! empty( $finding_status ) ) {
			$join .= " INNER JOIN {$this->findings_table} f ON f.page_id = p.id ";

			if ( ! empty( $finding_status ) ) {
				if ( 'ignored' === $finding_status ) {
					$where_clauses[] = 'f.is_ignored = 1';
				} elseif ( 'resolved' === $finding_status ) {
					$where_clauses[] = "f.finding_status = 'resolved' AND f.is_ignored = 0";
				} elseif ( 'persistent' === $finding_status ) {
					$where_clauses[] = "f.finding_status IN ('persistent', 'reappeared') AND f.is_ignored = 0";
				} elseif ( 'new' === $finding_status ) {
					$where_clauses[] = "f.finding_status = 'new' AND f.is_ignored = 0";
				} else {
					$where_clauses[] = 'f.finding_status = %s AND f.is_ignored = 0';
					$where_params[]  = $finding_status;
				}
			} else {
				$where_clauses[] = "f.is_ignored = 0 AND f.finding_status != 'resolved'";
			}

			if ( $rule_id > 0 ) {
				$where_clauses[] = 'f.rule_id = %d';
				$where_params[]  = $rule_id;
			}
			if ( ! empty( $severity ) ) {
				$where_clauses[] = 'f.severity = %s';
				$where_params[]  = $severity;
			}
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Whitelist orderby.
		$allowed_orderby = array(
			'findings_count'          => 'p.findings_count',
			'last_scanned_at'         => 'p.last_scanned_at',
			'post_title'              => 'p.post_title',
			'post_type'               => 'p.post_type',
			'url'                     => 'p.url',
			'seo_title_length'        => 'p.seo_title_length',
			'meta_description_length' => 'p.meta_description_length',
			'h1_count'                => 'p.h1_count',
		);

		$sort_col = isset( $allowed_orderby[ $params['orderby'] ] ) ? $allowed_orderby[ $params['orderby'] ] : 'p.findings_count';
		$sort_dir = 'ASC' === strtoupper( $params['order'] ) ? 'ASC' : 'DESC';

		$distinct = ( $rule_id > 0 || ! empty( $severity ) ) ? 'DISTINCT' : '';

		// Count query.
		$count_sql = "SELECT COUNT({$distinct} p.id) FROM {$this->pages_table} p {$join} WHERE {$where_sql}";
		if ( ! empty( $where_params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Items query.
		$items_sql    = "SELECT {$distinct} p.* FROM {$this->pages_table} p {$join} WHERE {$where_sql} ORDER BY {$sort_col} {$sort_dir} LIMIT %d OFFSET %d";
		$items_params = array_merge( $where_params, array( $per_page, $offset ) );
		$items        = $wpdb->get_results( $wpdb->prepare( $items_sql, $items_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Attach findings summary to each page.
		if ( ! empty( $items ) ) {
			$page_ids = array_column( $items, 'id' );
			$findings_grouped = $this->get_findings_for_pages( $page_ids, $finding_status );

			$active_scan = $this->get_active_scan();
			$scan_ids    = array_unique( array_filter( array_map( 'intval', array_column( $items, 'scan_id' ) ) ) );
			$scans_by_id = array();

			if ( ! empty( $scan_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $scan_ids ), '%d' ) );
				$scans_rows   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$this->scans_table} WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						$scan_ids
					)
				);
				if ( ! empty( $scans_rows ) ) {
					foreach ( $scans_rows as $sr ) {
						$scans_by_id[ (int) $sr->id ] = $sr;
					}
				}
			}

			$shared_context = array(
				'active_scan' => $active_scan,
				'scans'       => $scans_by_id,
			);

			foreach ( $items as &$item ) {
				$p_id = (int) $item['id'];
				$item['findings']    = isset( $findings_grouped[ $p_id ] ) ? $findings_grouped[ $p_id ] : array();
				$item['audit_state'] = self::compute_page_audit_state( $item, $shared_context );
				$item['edit_link']   = $item['post_id'] > 0 ? get_edit_post_link( $item['post_id'], 'raw' ) : null;
			}
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => ceil( $total / $per_page ),
		);
	}

	/**
	 * Get findings grouped by page ID.
	 *
	 * Scopes returned findings to the specific finding_status view requested.
	 *
	 * @param array  $page_ids Array of page IDs.
	 * @param string $finding_status View filter ('', 'new', 'persistent', 'resolved', 'ignored').
	 * @return array Grouped findings indexed by page ID.
	 */
	public function get_findings_for_pages( array $page_ids, $finding_status = '' ) {
		global $wpdb;

		if ( empty( $page_ids ) ) {
			return array();
		}

		$clean_ids    = array_map( 'intval', $page_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $clean_ids ), '%d' ) );

		if ( 'ignored' === $finding_status ) {
			$where_filter = ' AND is_ignored = 1';
		} elseif ( 'resolved' === $finding_status ) {
			$where_filter = " AND finding_status = 'resolved' AND is_ignored = 0";
		} elseif ( 'persistent' === $finding_status ) {
			$where_filter = " AND finding_status IN ('persistent', 'reappeared') AND is_ignored = 0";
		} elseif ( 'new' === $finding_status ) {
			$where_filter = " AND finding_status = 'new' AND is_ignored = 0";
		} elseif ( ! empty( $finding_status ) ) {
			$where_filter = $wpdb->prepare( ' AND finding_status = %s AND is_ignored = 0', $finding_status );
		} else {
			// Default view: active, un-ignored, non-resolved findings.
			$where_filter = " AND is_ignored = 0 AND finding_status != 'resolved'";
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->findings_table} WHERE page_id IN ($placeholders) {$where_filter} ORDER BY severity = 'error' DESC, severity = 'warning' DESC, rule_id ASC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$clean_ids
			),
			ARRAY_A
		);

		$grouped = array();
		foreach ( $rows as $r ) {
			$p_id = (int) $r['page_id'];
			if ( ! isset( $grouped[ $p_id ] ) ) {
				$grouped[ $p_id ] = array();
			}
			$grouped[ $p_id ][] = $r;
		}

		return $grouped;
	}

	/**
	 * Get findings for a single page with full details and ignore state.
	 *
	 * @param int $page_id Page ID.
	 * @return array
	 */
	public function get_findings_for_page( $page_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->findings_table} WHERE page_id = %d ORDER BY is_ignored ASC, severity = 'error' DESC, severity = 'warning' DESC, rule_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $page_id
			),
			ARRAY_A
		);
	}

	/**
	 * Compute authoritative audit state for a page record.
	 *
	 * Distinguishes evaluated, unavailable, incomplete, stale, pending analysis, and clean states.
	 * Prevents failed, pending, stale, or incomplete responses from falsely displaying as 'Clean'.
	 * Associates duplicate and orphan finalization with the relevant audit.
	 *
	 * @param object|array $page Page record object or array.
	 * @param array|null   $shared_context Shared scan context to avoid per-row repeated queries.
	 * @return array State descriptor with state, label, css_class, is_stale, and is_evaluated.
	 */
	public static function compute_page_audit_state( $page, $shared_context = null ) {
		$p               = (object) $page;
		$scan_status     = isset( $p->scan_status ) ? $p->scan_status : 'pending';
		$last_scanned_at = isset( $p->last_scanned_at ) ? $p->last_scanned_at : null;
		$last_succ       = isset( $p->last_successful_scanned_at ) ? $p->last_successful_scanned_at : null;
		$last_fail       = isset( $p->last_failed_scanned_at ) ? $p->last_failed_scanned_at : null;
		$http_status     = isset( $p->http_status ) ? (int) $p->http_status : 0;
		$findings_count  = isset( $p->findings_count ) ? (int) $p->findings_count : 0;
		$fetch_error     = isset( $p->fetch_error ) ? $p->fetch_error : '';
		$has_incomplete  = ! empty( $p->has_incomplete_resources );
		$page_scan_id    = isset( $p->scan_id ) ? (int) $p->scan_id : 0;

		// Explicit successful evaluation evidence is strictly required for measurement availability.
		// Freshness is determined conservatively from the latest attempt outcome rather than mixed-timezone comparisons.
		$avail_info            = Crawlory_Helper::get_measurement_availability( $p, 'html' );
		$has_html_measurements = $avail_info['available'];
		$is_stale              = $avail_info['stale'];
		$freshness             = ( empty( $last_scanned_at ) || 'pending' === $scan_status ) ? 'unscanned' : ( $is_stale ? 'stale' : 'current' );

		// 1. Unscanned / Pending page.
		if ( empty( $last_scanned_at ) || 'pending' === $scan_status ) {
			return array(
				'state'                 => 'pending',
				'label'                 => __( 'Unscanned', 'crawlory-technical-seo-dashboard' ),
				'completeness'          => 'unscanned',
				'freshness'             => 'unscanned',
				'css_class'             => 'crawlory-badge-pending',
				'is_stale'              => false,
				'is_incomplete'         => true,
				'is_evaluated'          => false,
				'has_html_measurements' => false,
				'findings_count'        => 0,
				'count'                 => 0,
			);
		}

		// 2. Explicit scan failure or HTTP error status >= 400.
		if ( 'failed' === $scan_status || ( $http_status >= 400 && $http_status > 0 ) ) {
			$label = $http_status >= 400 ? sprintf( 'HTTP %d', $http_status ) : __( 'Failed', 'crawlory-technical-seo-dashboard' );
			return array(
				'state'                 => 'failed',
				'label'                 => $label,
				'completeness'          => 'incomplete',
				'freshness'             => $freshness,
				'error'                 => $fetch_error,
				'css_class'             => 'crawlory-badge-error',
				'is_stale'              => $is_stale,
				'is_incomplete'         => true,
				'is_evaluated'          => false,
				'has_html_measurements' => $has_html_measurements,
				'findings_count'        => $findings_count,
				'count'                 => $findings_count,
			);
		}

		// 3. Explicitly interrupted page scan.
		if ( 'interrupted' === $scan_status ) {
			return array(
				'state'                 => 'interrupted',
				'label'                 => __( 'Interrupted', 'crawlory-technical-seo-dashboard' ),
				'completeness'          => 'incomplete',
				'freshness'             => $freshness,
				'css_class'             => 'crawlory-badge-warn',
				'is_stale'              => $is_stale,
				'is_incomplete'         => true,
				'is_evaluated'          => false,
				'has_html_measurements' => $has_html_measurements,
				'findings_count'        => $findings_count,
				'count'                 => $findings_count,
			);
		}

		// 4. Retrieve associated scan explicitly by scan_id (avoiding inference solely from timestamps).
		$assoc_scan = null;
		if ( $page_scan_id > 0 ) {
			if ( null !== $shared_context && is_array( $shared_context ) && isset( $shared_context['scans'][ $page_scan_id ] ) ) {
				$assoc_scan = $shared_context['scans'][ $page_scan_id ];
			} elseif ( class_exists( 'Crawlory' ) && isset( Crawlory::get_instance()->db ) ) {
				$assoc_scan = Crawlory::get_instance()->db->get_scan( $page_scan_id );
			}
		}

		// Fallback to active scan if page had no scan_id recorded (e.g. legacy row) and URL matches active scan scope.
		if ( ! $assoc_scan ) {
			$active_scan = null;
			if ( null !== $shared_context && is_array( $shared_context ) && array_key_exists( 'active_scan', $shared_context ) ) {
				$active_scan = $shared_context['active_scan'];
			} elseif ( class_exists( 'Crawlory' ) && isset( Crawlory::get_instance()->db ) ) {
				$active_scan = Crawlory::get_instance()->db->get_active_scan();
			}

			if ( $active_scan && ! empty( $last_scanned_at ) ) {
				$scan_type = isset( $active_scan->scan_type ) ? $active_scan->scan_type : 'full';
				if ( 'single_url' === $scan_type || 'single' === $scan_type ) {
					$target_urls = ! empty( $active_scan->target_urls ) ? (array) json_decode( $active_scan->target_urls, true ) : array();
					$page_url    = isset( $p->url ) ? $p->url : '';
					if ( in_array( $page_url, $target_urls, true ) ) {
						$assoc_scan = $active_scan;
					}
				} else {
					$scan_started = ! empty( $active_scan->started_at ) ? strtotime( $active_scan->started_at ) : 0;
					$page_scanned = strtotime( $last_scanned_at );
					if ( $scan_started > 0 && $page_scanned >= $scan_started ) {
						$assoc_scan = $active_scan;
					}
				}
			}
		}

		// 5. Evaluate associated scan state (evaluating finalization errors BEFORE generic running-state logic).
		$scan_incomplete     = false;
		$scan_state_override = null;

		if ( ! $assoc_scan ) {
			// Legacy or unassociated scan records cannot be authoritatively verified as complete.
			$scan_incomplete     = true;
			$scan_state_override = array(
				'state'     => 'incomplete',
				'label'     => __( 'Needs rescan', 'crawlory-technical-seo-dashboard' ),
				'css_class' => 'crawlory-badge-warn',
			);
		} else {
			$scan_stat  = isset( $assoc_scan->status ) ? $assoc_scan->status : 'running';
			$scan_stage = isset( $assoc_scan->finalization_stage ) ? $assoc_scan->finalization_stage : 'none';
			$fin_err    = ! empty( $assoc_scan->finalization_error ) ? $assoc_scan->finalization_error : null;

			// Handle finalization errors BEFORE generic running-state logic!
			if ( $fin_err || 'failed' === $scan_stat ) {
				$scan_incomplete     = true;
				$scan_state_override = array(
					'state'     => 'incomplete',
					'label'     => $fin_err ? __( 'Finalization Failed', 'crawlory-technical-seo-dashboard' ) : __( 'Scan Failed', 'crawlory-technical-seo-dashboard' ),
					'error'     => $fin_err,
					'css_class' => 'crawlory-badge-error',
				);
			} elseif ( 'paused' === $scan_stat ) {
				$scan_incomplete     = true;
				$scan_state_override = array(
					'state'     => 'incomplete',
					'label'     => __( 'Analysis Paused', 'crawlory-technical-seo-dashboard' ),
					'css_class' => 'crawlory-badge-warn',
				);
			} elseif ( 'interrupted' === $scan_stat ) {
				$scan_incomplete     = true;
				$scan_state_override = array(
					'state'     => 'incomplete',
					'label'     => __( 'Analysis Interrupted', 'crawlory-technical-seo-dashboard' ),
					'css_class' => 'crawlory-badge-warn',
				);
			} elseif ( 'cancelled' === $scan_stat ) {
				$scan_incomplete     = true;
				$scan_state_override = array(
					'state'     => 'incomplete',
					'label'     => __( 'Analysis Cancelled', 'crawlory-technical-seo-dashboard' ),
					'css_class' => 'crawlory-badge-warn',
				);
			} elseif ( in_array( $scan_stat, array( 'running', 'pending' ), true ) || 'completed' !== $scan_stage ) {
				$scan_incomplete     = true;
				$scan_state_override = array(
					'state'     => 'pending_analysis',
					'label'     => __( 'Pending Analysis', 'crawlory-technical-seo-dashboard' ),
					'css_class' => 'crawlory-badge-warn',
				);
			}
		}

		// Completeness: complete only if no incomplete resources AND associated scan completed.
		$is_incomplete         = ( $has_incomplete || $scan_incomplete );
		$completeness          = $is_incomplete ? 'incomplete' : 'complete';

		// 6. Represent findings count and analysis completeness independently.
		// A page may have findings while its analysis remains incomplete.
		if ( $findings_count > 0 ) {
			/* translators: %d: Number of SEO issues detected. */
			$label = sprintf( _n( '%d issue', '%d issues', $findings_count, 'crawlory-technical-seo-dashboard' ), $findings_count );
			if ( $is_incomplete ) {
				$label .= ' ' . __( '(Incomplete)', 'crawlory-technical-seo-dashboard' );
			}
			return array(
				'state'                 => 'issues_found',
				'label'                 => $label,
				'completeness'          => $completeness,
				'freshness'             => $freshness,
				'count'                 => $findings_count,
				'findings_count'        => $findings_count,
				'css_class'             => 'crawlory-badge-issues',
				'is_stale'              => $is_stale,
				'is_incomplete'         => $is_incomplete,
				'is_evaluated'          => true,
				'has_html_measurements' => $has_html_measurements,
			);
		}

		// 7. Stale page with 0 active findings.
		if ( $is_stale ) {
			return array(
				'state'                 => 'stale',
				'label'                 => __( 'Stale', 'crawlory-technical-seo-dashboard' ),
				'completeness'          => $completeness,
				'freshness'             => 'stale',
				'css_class'             => 'crawlory-badge-warn',
				'is_stale'              => true,
				'is_incomplete'         => $is_incomplete,
				'is_evaluated'          => true,
				'has_html_measurements' => $has_html_measurements,
				'findings_count'        => 0,
				'count'                 => 0,
			);
		}

		// 8. Incomplete analysis with 0 active findings (show scan state override if applicable).
		if ( $is_incomplete ) {
			if ( $scan_state_override ) {
				return array(
					'state'                 => $scan_state_override['state'],
					'label'                 => $scan_state_override['label'],
					'completeness'          => 'incomplete',
					'freshness'             => $freshness,
					'error'                 => isset( $scan_state_override['error'] ) ? $scan_state_override['error'] : null,
					'css_class'             => $scan_state_override['css_class'],
					'is_stale'              => false,
					'is_incomplete'         => true,
					'is_evaluated'          => true,
					'has_html_measurements' => $has_html_measurements,
					'findings_count'        => 0,
					'count'                 => 0,
				);
			}

			return array(
				'state'                 => 'incomplete',
				'label'                 => __( 'Incomplete', 'crawlory-technical-seo-dashboard' ),
				'completeness'          => 'incomplete',
				'freshness'             => $freshness,
				'css_class'             => 'crawlory-badge-warn',
				'is_stale'              => false,
				'is_incomplete'         => true,
				'is_evaluated'          => true,
				'has_html_measurements' => $has_html_measurements,
				'findings_count'        => 0,
				'count'                 => 0,
			);
		}

		// 9. Show Clean ONLY when required checks completed successfully, result is current, and 0 findings remain.
		if ( 'success' === $scan_status && ! $is_stale && ! $is_incomplete && 0 === $findings_count ) {
			return array(
				'state'                 => 'clean',
				'label'                 => __( 'Clean', 'crawlory-technical-seo-dashboard' ),
				'completeness'          => 'complete',
				'freshness'             => 'current',
				'css_class'             => 'crawlory-badge-clean',
				'is_stale'              => false,
				'is_incomplete'         => false,
				'is_evaluated'          => true,
				'has_html_measurements' => true,
				'findings_count'        => 0,
				'count'                 => 0,
			);
		}

		return array(
			'state'                 => 'incomplete',
			'label'                 => __( 'Incomplete', 'crawlory-technical-seo-dashboard' ),
			'completeness'          => 'incomplete',
			'freshness'             => $freshness,
			'css_class'             => 'crawlory-badge-warn',
			'is_stale'              => $is_stale,
			'is_incomplete'         => true,
			'is_evaluated'          => false,
			'has_html_measurements' => false,
			'findings_count'        => $findings_count,
			'count'                 => $findings_count,
		);
	}

	/**
	 * Prune old audit data according to retention days setting.
	 *
	 * @param int $retention_days Number of days to retain. 0 for indefinite.
	 * @return int Deleted pages count.
	 */
	public function prune_old_data( $retention_days = 90 ) {
		global $wpdb;

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// Delete findings of pages older than threshold.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE f FROM {$this->findings_table} f INNER JOIN {$this->pages_table} p ON f.page_id = p.id WHERE p.last_scanned_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold_date
			)
		);

		// Delete old scans.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->scans_table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold_date
			)
		);

		// Delete old pages.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->pages_table} WHERE last_scanned_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold_date
			)
		);
	}

	/**
	 * Clear all audit data completely across all 8 plugin-owned tables.
	 *
	 * Uses checked DELETE statements with safe error checking so that
	 * environments with restricted TRUNCATE privileges or SQLite test drivers
	 * succeed reliably. Returns boolean success.
	 *
	 * @return bool True if all tables cleared successfully, false if any failed.
	 */
	public function clear_all_data() {
		global $wpdb;

		$tables = array(
			$this->findings_table,
			$this->pages_table,
			$this->dup_table,
			$this->scans_table,
			$this->links_table,
			$this->sitemaps_table,
			$this->scan_items_table,
			$this->resource_cache_table,
		);

		$all_ok = true;
		foreach ( $tables as $tbl ) {
			if ( empty( $tbl ) ) {
				continue;
			}
			$safe_tbl = esc_sql( $tbl );
			// Attempt DELETE FROM which returns number of affected rows (0 is completely valid for empty table).
			$res = $wpdb->query( "DELETE FROM {$safe_tbl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $res ) {
				// Fallback to TRUNCATE if DELETE failed.
				$trunc = $wpdb->query( "TRUNCATE TABLE {$safe_tbl}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $trunc ) {
					$all_ok = false;
				}
			}
		}

		return $all_ok;
	}

	/**
	 * Check if a URL exists in the inspected sitemaps table.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_url_in_sitemap( $url ) {
		global $wpdb;
		$hash  = md5( esc_url_raw( $url ) );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->sitemaps_table} WHERE url_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);
		return ! empty( $found );
	}

	/**
	 * Get sitewide scan coverage statistics for provisional checks.
	 *
	 * @return array Coverage data.
	 */
	public function get_scan_coverage() {
		global $wpdb;

		$total_scanned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE scan_status = 'success'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table} WHERE scan_status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_pages   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->pages_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pct = ( $total_pages > 0 ) ? round( ( $total_scanned / $total_pages ) * 100, 1 ) : 0.0;

		return array(
			'total_pages'   => $total_pages,
			'scanned_pages' => $total_scanned,
			'failed_pages'  => $total_failed,
			'coverage_pct'  => $pct,
			'is_complete'   => ( $total_pages > 0 && $total_scanned >= $total_pages ),
		);
	}
}
