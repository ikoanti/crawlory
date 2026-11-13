<?php
/**
 * Scanner Orchestrator for Technical SEO Dashboard / Crawlory.
 *
 * Implements memory-efficient, durable job queue, cursor-paginated discovery,
 * atomic worker leases, rate-limited processing, and multi-stage resumable finalization.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Scanner {

	/**
	 * Lock transient name.
	 */
	const LOCK_NAME = 'crawlory_scan_lock';
	const LOCK_EXPIRATION = 300; // 5 minutes.

	/**
	 * Database instance.
	 *
	 * @var Crawlory_DB
	 */
	private $db;

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	private $settings;

	/**
	 * Fetcher instance.
	 *
	 * @var Crawlory_Fetcher
	 */
	private $fetcher;

	/**
	 * Auditor instance.
	 *
	 * @var Crawlory_Auditor
	 */
	private $auditor;

	/**
	 * Duplicate manager.
	 *
	 * @var Crawlory_Duplicate_Manager
	 */
	private $dup_manager;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_DB       $db Database layer.
	 * @param Crawlory_Settings $settings Settings.
	 */
	public function __construct( Crawlory_DB $db, Crawlory_Settings $settings ) {
		$this->db          = $db;
		$this->settings    = $settings;
		$this->fetcher     = new Crawlory_Fetcher( $settings );
		$this->auditor     = new Crawlory_Auditor( $settings, $db );
		$this->dup_manager = new Crawlory_Duplicate_Manager( $db, $settings );

		$this->register_hooks();
	}

	/**
	 * Register background worker hooks.
	 */
	private function register_hooks() {
		add_action( 'crawlory_process_background_batch', array( $this, 'process_work' ) );
		add_action( 'wp_ajax_crawlory_spawn_worker', array( $this, 'ajax_spawn_worker' ) );
		add_action( 'wp_ajax_nopriv_crawlory_spawn_worker', array( $this, 'ajax_spawn_worker' ) );
	}

	/**
	 * AJAX endpoint to spawn non-blocking background worker continuation.
	 * Secured via cryptographically random dispatch token scoped to the job.
	 */
	public function ajax_spawn_worker() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Unattended background worker authenticated via job-scoped random bearer token, validated with hash_equals() and expiration check below.
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Unattended background worker authenticated via job-scoped random bearer token, validated with hash_equals() and expiration check below.
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! $scan_id || empty( $token ) ) {
			wp_die( 'Forbidden: Missing credentials', 403 );
		}

		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan || 'running' !== $scan->status ) {
			wp_die( 'Forbidden: Inactive scan', 403 );
		}

		// Constant-time hash verification of bearer token.
		$token_hash = hash( 'sha256', $token );
		$is_valid   = ! empty( $scan->auth_token_hash ) && hash_equals( $scan->auth_token_hash, $token_hash );
		$expires_ts = ! empty( $scan->auth_token_expires ) ? strtotime( $scan->auth_token_expires ) : 0;

		if ( ! $is_valid || $expires_ts < time() ) {
			wp_die( 'Forbidden: Expired or invalid token', 403 );
		}

		$this->process_work( $scan_id, 20 );
		wp_die( 'OK' );
	}

	/**
	 * Discover all public post types available for scanning.
	 * Automatically includes pages, posts, WooCommerce products,
	 * and any registered portfolio post types.
	 *
	 * @return array List of post type objects.
	 */
	public static function get_eligible_post_types() {
		$args = array(
			'public' => true,
		);

		$types = get_post_types( $args, 'objects' );

		// Exclude internal attachments and revisions.
		unset(
			$types['attachment'],
			$types['revision'],
			$types['nav_menu_item'],
			$types['custom_css'],
			$types['customize_changeset'],
			$types['oembed_cache'],
			$types['user_request'],
			$types['wp_block'],
			$types['wp_template'],
			$types['wp_template_part'],
			$types['wp_navigation']
		);

		return $types;
	}

	/**
	 * Check if a post type is considered a portfolio post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_portfolio_post_type( $post_type ) {
		$portfolio_keywords = array( 'portfolio', 'project', 'work', 'case-study', 'gallery' );
		foreach ( $portfolio_keywords as $keyword ) {
			if ( false !== strpos( strtolower( $post_type ), $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Start a new scan.
	 *
	 * @param string      $scan_type 'full', 'post_type', 'selected_urls', or 'single'.
	 * @param string|null $target_type Specific post_type if scan_type is 'post_type'.
	 * @param array       $target_urls Array of URLs if selected_urls or single.
	 * @return array Scan status response.
	 */
	public function start_scan( $scan_type = 'full', $target_type = null, array $target_urls = array() ) {
		// Migration readiness: block scan starts while migration is incomplete or failed.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			$diag = get_option( Crawlory_Migrator::ERROR_OPTION, '' );
			if ( $diag ) {
				/* translators: %s: Detailed error message explaining why database migration failed. */
				$msg = sprintf( __( 'Database migration is incomplete or failed: %s. Scans cannot start until migration is resolved.', 'crawlory-technical-seo-dashboard' ), $diag );
			} else {
				$msg = __( 'Database migration or schema update is incomplete. Scans cannot start until migration is resolved.', 'crawlory-technical-seo-dashboard' );
			}
			return array(
				'success' => false,
				'message' => $msg,
				'scan_id' => 0,
				'job_id'  => 0,
			);
		}

		// Check if another scan is already actively running.
		$active = $this->db->get_active_scan();
		if ( $active && 'running' === $active->status ) {
			// If active scan has an expired worker lease (older than 10 minutes in UTC), allow resuming.
			$lease_exp = ! empty( $active->worker_lease_expires ) ? strtotime( $active->worker_lease_expires ) : 0;
			if ( $lease_exp > 0 && $lease_exp < time() ) {
				$this->resume_scan( $active->id );
				return array(
					'success' => true,
					'scan_id' => $active->id,
					'job_id'  => $active->id,
					'message' => 'Resumed interrupted scan job.',
					'status'  => 'running',
				);
			}

			return array(
				'success' => false,
				'message' => 'A scan is already actively running. Please pause or cancel it first.',
				'scan_id' => $active->id,
				'job_id'  => $active->id,
			);
		}

		// Filter target_urls if supplied to ensure valid same-site URLs.
		// Normalize and deduplicate selected target URLs before calculating totals.
		$sanitized_targets  = array();
		$seen_target_hashes = array();
		if ( ! empty( $target_urls ) ) {
			foreach ( $target_urls as $u ) {
				$u_clean = esc_url_raw( trim( (string) $u ) );
				if ( empty( $u_clean ) || $this->is_url_excluded( $u_clean ) ) {
					continue;
				}
				$u_hash = md5( $u_clean );
				if ( isset( $seen_target_hashes[ $u_hash ] ) ) {
					continue;
				}
				$seen_target_hashes[ $u_hash ] = true;
				$sanitized_targets[]           = $u_clean;
			}
		}

		// Count total URLs estimate.
		$total_count = $this->calculate_total_urls( $scan_type, $target_type, $sanitized_targets );
		$batch_size  = (int) $this->settings->get( 'batch_size', 20 );

		$scan_id = $this->db->create_scan(
			array(
				'status'             => 'running',
				'scan_type'          => $scan_type,
				'target_type'        => $target_type,
				'target_urls'        => ! empty( $sanitized_targets ) ? wp_json_encode( array_values( $sanitized_targets ) ) : null,
				'total_urls'         => $total_count,
				'scanned_urls'       => 0,
				'failed_urls'        => 0,
				'findings_count'     => 0,
				'batch_size'         => $batch_size,
				'cursor_offset'      => 0,
				'last_examined_id'   => 0,
				'examined_urls'      => 0,
				'excluded_urls'      => 0,
				'discovered_urls'    => 0,
				'pending_urls'       => 0,
				'processing_urls'    => 0,
				'discovery_status'   => 'pending',
				'finalization_stage' => 'pending',
				'current_step'       => 'discovery',
			)
		);

		// Seed initial inventory items into durable pending table.
		if ( in_array( $scan_type, array( 'selected_urls', 'single' ), true ) ) {
			try {
				foreach ( $sanitized_targets as $t_url ) {
					$pid  = url_to_postid( $t_url );
					$post = $pid ? get_post( $pid ) : null;
					$add_outcome = $this->db->add_scan_item(
						$scan_id,
						array(
							'url'        => $t_url,
							'post_id'    => $pid ? $pid : 0,
							'post_type'  => $post ? $post->post_type : 'custom',
							'post_title' => $post ? $post->post_title : 'Target URL',
							'slug'       => $post ? $post->post_name : '',
						)
					);

					// In start_scan(), check every selected-URL queue operation before marking discovery complete or starting workers.
					if ( ! is_array( $add_outcome ) || 'failed' === $add_outcome['status'] ) {
						$fail_err = ( is_array( $add_outcome ) && ! empty( $add_outcome['error'] ) )
							? $add_outcome['error']
							: __( 'Failed to queue target URL.', 'crawlory-technical-seo-dashboard' );

						/* translators: %s: Failure reason or database error message. */
						throw new RuntimeException( sprintf( __( 'Queue operation failed for target URL: %s', 'crawlory-technical-seo-dashboard' ), $fail_err ) );
					}
				}

				// Calculate discovered/total counts from distinct persisted inventory.
				// Calculate pending counts from actual pending queue rows.
				$counts = $this->db->get_scan_item_counts( $scan_id );

				$upd_targets = $this->db->update_scan(
					$scan_id,
					array(
						'discovered_urls'  => (int) $counts['total'],
						'pending_urls'     => (int) $counts['pending'],
						'total_urls'       => (int) $counts['total'],
						'discovery_status' => 'completed',
						'current_step'     => 'auditing',
					)
				);
				if ( false === $upd_targets ) {
					throw new RuntimeException( __( 'Failed to record target inventory state.', 'crawlory-technical-seo-dashboard' ) );
				}
			} catch ( Throwable $t ) {
				// Preserve successfully queued items; record failed startup state when database writes permit.
				// Do not replace unavailable counts with zero. Do not mark discovery complete.
				$this->db->update_scan(
					$scan_id,
					array(
						'status'        => 'failed',
						'error_message' => $t->getMessage(),
						'finished_at'   => Crawlory_Helper::now_utc(),
					)
				);
				/* translators: %s: Error message describing target URL startup failure. */
				$startup_err = sprintf( __( 'Target URL startup failed: %s', 'crawlory-technical-seo-dashboard' ), $t->getMessage() );
				return array(
					'success' => false,
					'scan_id' => $scan_id,
					'job_id'  => $scan_id,
					'message' => $startup_err,
					'status'  => 'failed',
				);
			}
		} else {
			// Pre-discover initial chunk of inventory.
			try {
				$this->discover_inventory( $scan_id, 100 );
			} catch ( Throwable $t ) {
				$this->db->update_scan(
					$scan_id,
					array(
						'status'        => 'failed',
						'error_message' => $t->getMessage(),
						'finished_at'   => Crawlory_Helper::now_utc(),
					)
				);
				/* translators: %s: Error message describing inventory discovery failure. */
				$discovery_err = sprintf( __( 'Inventory discovery failed: %s', 'crawlory-technical-seo-dashboard' ), $t->getMessage() );
				return array(
					'success' => false,
					'scan_id' => $scan_id,
					'job_id'  => $scan_id,
					'message' => $discovery_err,
					'status'  => 'failed',
				);
			}
		}

		// Enqueue durable background processing event in WP-Cron.
		if ( ! wp_next_scheduled( 'crawlory_process_background_batch', array( $scan_id ) ) ) {
			wp_schedule_single_event( time(), 'crawlory_process_background_batch', array( $scan_id ) );
		}

		// Spawn non-blocking background loopback request so execution begins immediately.
		$this->spawn_background_worker( $scan_id );

		return array(
			'success'    => true,
			'scan_id'    => $scan_id,
			'job_id'     => $scan_id,
			'total_urls' => $total_count,
			'status'     => 'running',
		);
	}

	/**
	 * Spawn an asynchronous non-blocking loopback request with secure dispatch token.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function spawn_background_worker( $scan_id ) {
		try {
			$raw_token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			$raw_token = wp_generate_password( 64, false );
		}

		$token_hash    = hash( 'sha256', $raw_token );
		$token_expires = gmdate( 'Y-m-d H:i:s', time() + 300 ); // 5 minute UTC validity.

		$this->db->update_scan(
			$scan_id,
			array(
				'auth_token_hash'    => $token_hash,
				'auth_token_expires' => $token_expires,
			)
		);

		$url = admin_url( 'admin-ajax.php' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
				'body'      => array(
					'action'  => 'crawlory_spawn_worker',
					'scan_id' => (int) $scan_id,
					'token'   => $raw_token,
				),
			)
		);

		// Fallback: schedule WP-Cron event if not already queued.
		if ( ! wp_next_scheduled( 'crawlory_process_background_batch', array( $scan_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'crawlory_process_background_batch', array( $scan_id ) );
		}
	}

	/**
	 * Legacy lock compatibility no-op.
	 * Active scans and worker leases are managed authoritatively in the database.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	private function acquire_lock( $scan_id ) {
		return true;
	}

	/**
	 * Safely clean up legacy coordination options and transients if they match this scan ID.
	 * Normal scans no longer use crawlory_scan_lock or crawlory_active_scan_id, but legacy
	 * records are cleaned up conditionally without reintroducing race conditions or shared-cache collisions.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private function cleanup_legacy_coordination( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;
		if ( $scan_id <= 0 ) {
			return;
		}

		// Delete legacy active scan option if it matches this scan ID.
		$deleted_active = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = 'crawlory_active_scan_id' AND option_value = %s",
				(string) $scan_id
			)
		);
		if ( $deleted_active ) {
			wp_cache_delete( 'crawlory_active_scan_id', 'options' );
		}

		// Delete legacy transient lock if it matches this scan ID.
		// Note: The transient value holds the scan ID; the timeout option holds an integer timestamp.
		// Do NOT compare the timeout option's value to the scan ID!
		$deleted_lock = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = '_transient_crawlory_scan_lock' AND option_value = %s",
				(string) $scan_id
			)
		);
		if ( $deleted_lock ) {
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name = '_transient_timeout_crawlory_scan_lock'"
			);
			wp_cache_delete( self::LOCK_NAME, 'transient' );
			wp_cache_delete( 'timeout_' . self::LOCK_NAME, 'transient' );
		}
	}

	/**
	 * Release legacy concurrency lock safely if matching this scan.
	 *
	 * @param int|null $scan_id Optional scan ID to verify ownership before deletion.
	 */
	private function release_lock( $scan_id = null ) {
		if ( ! empty( $scan_id ) ) {
			$this->cleanup_legacy_coordination( $scan_id );
		}
	}

	/**
	 * Calculate total eligible URLs for a scan.
	 *
	 * @param string      $scan_type Scan type.
	 * @param string|null $target_type Post type.
	 * @param array       $target_urls Specific URLs.
	 * @return int
	 */
	public function calculate_total_urls( $scan_type, $target_type = null, array $target_urls = array() ) {
		global $wpdb;

		if ( 'selected_urls' === $scan_type || 'single' === $scan_type ) {
			// Normalize and deduplicate selected target URLs before calculating totals.
			$seen_hashes = array();
			$count       = 0;
			foreach ( $target_urls as $u ) {
				$u_clean = esc_url_raw( trim( (string) $u ) );
				if ( ! empty( $u_clean ) ) {
					$u_hash = md5( $u_clean );
					if ( ! isset( $seen_hashes[ $u_hash ] ) ) {
						$seen_hashes[ $u_hash ] = true;
						$count++;
					}
				}
			}
			return $count;
		}

		$post_types = array();
		if ( 'post_type' === $scan_type && ! empty( $target_type ) ) {
			$post_types = array( $target_type );
		} else {
			$post_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );
		}

		if ( empty( $post_types ) ) {
			return 0;
		}

		$clean_types  = array_values( array_map( 'sanitize_key', $post_types ) );
		$placeholders = implode( ', ', array_fill( 0, count( $clean_types ), '%s' ) );
		$count        = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table name and dynamic %s placeholders for allowlisted post types array.
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_password = ''",
				$clean_types
			)
		);

		if ( ( 'full' === $scan_type || ( 'post_type' === $scan_type && 'page' === $target_type ) )
			&& $this->settings->get( 'include_homepage', true )
			&& 'posts' === get_option( 'show_on_front' )
		) {
			$count++;
		}

		return $count;
	}

	/**
	 * Discover inventory items in chunks and persist them into durable pending queue.
	 * Separates discovery state from processing state completely.
	 *
	 * @param int         $scan_id Scan ID.
	 * @param int         $limit Batch discovery size.
	 * @param string|null $worker_token Worker token if leased.
	 * @return int Number of newly discovered and queued items.
	 * @throws Exception On query failure, write failure, or lost ownership.
	 */
	public function discover_inventory( $scan_id, $limit = 50, $worker_token = null ) {
		global $wpdb;

		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan ) {
			throw new Exception( __( 'Scan record not found.', 'crawlory-technical-seo-dashboard' ) );
		}
		if ( 'completed' === $scan->discovery_status ) {
			return 0;
		}

		$last_examined_id = (int) $scan->last_examined_id;
		$examined_count   = (int) $scan->examined_urls;
		$excluded_count   = (int) $scan->excluded_urls;
		$newly_added      = 0;

		// Check homepage eligibility on initial step.
		if ( 0 === $last_examined_id && 0 === $examined_count
			&& ( 'full' === $scan->scan_type || 'page' === $scan->target_type )
			&& $this->settings->get( 'include_homepage', true )
			&& 'posts' === get_option( 'show_on_front' )
		) {
			$home_url = home_url( '/' );
			$examined_count++;
			if ( ! $this->is_url_excluded( $home_url ) ) {
				$home_res = $this->db->add_scan_item(
					$scan_id,
					array(
						'url'        => $home_url,
						'post_id'    => 0,
						'post_type'  => 'homepage',
						'post_title' => 'Homepage (Latest Posts)',
						'slug'       => '',
					)
				);

				if ( ! is_array( $home_res ) || 'failed' === $home_res['status'] ) {
					$err_text = ( is_array( $home_res ) && ! empty( $home_res['error'] ) )
						? $home_res['error']
						: __( 'Failed to queue homepage.', 'crawlory-technical-seo-dashboard' );
					throw new Exception( __( 'Database error while queueing homepage: ', 'crawlory-technical-seo-dashboard' ) . $err_text );
				}

				if ( 'inserted' === $home_res['status'] ) {
					$newly_added++;
				}
			} else {
				$excluded_count++;
			}
		}

		$post_types = array();
		if ( 'post_type' === $scan->scan_type && ! empty( $scan->target_type ) ) {
			$post_types = array( $scan->target_type );
		} else {
			$post_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );
		}

		if ( empty( $post_types ) ) {
			$counts       = $this->db->get_scan_item_counts( $scan_id );
			$upd_empty_pt = $this->db->update_scan(
				$scan_id,
				array(
					'discovery_status' => 'completed',
					'discovered_urls'  => (int) $counts['total'],
					'pending_urls'     => (int) $counts['pending'],
					'total_urls'       => (int) $counts['total'],
					'current_step'     => 'auditing',
				),
				$worker_token
			);
			if ( false === $upd_empty_pt ) {
				throw new Exception( __( 'Failed to update scan discovery status: database write error or lost worker ownership.', 'crawlory-technical-seo-dashboard' ) );
			}
			return $newly_added;
		}

		$clean_types  = array_values( array_map( 'sanitize_key', $post_types ) );
		$placeholders = implode( ', ', array_fill( 0, count( $clean_types ), '%s' ) );
		$params       = array_merge( $clean_types, array( (int) $last_examined_id, (int) $limit ) );

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table name and dynamic %s placeholders for allowlisted post types array.
				"SELECT ID, post_title, post_type, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$params
			),
			ARRAY_A
		);

		// Check $wpdb->last_error immediately after the posts SELECT, before interpreting empty results as completion.
		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Database query error during inventory discovery: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		if ( empty( $posts ) ) {
			// All published posts traversed!
			$counts          = $this->db->get_scan_item_counts( $scan_id );
			$upd_empty_posts = $this->db->update_scan(
				$scan_id,
				array(
					'discovery_status' => 'completed',
					'discovered_urls'  => (int) $counts['total'],
					'pending_urls'     => (int) $counts['pending'],
					'total_urls'       => (int) $counts['total'],
					'current_step'     => 'auditing',
				),
				$worker_token
			);
			if ( false === $upd_empty_posts ) {
				throw new Exception( __( 'Failed to record discovery completion: database write error or lost worker ownership.', 'crawlory-technical-seo-dashboard' ) );
			}
			return $newly_added;
		}

		$persisted_cursor = $last_examined_id;
		$enqueue_error    = null;

		foreach ( $posts as $p ) {
			$post_id   = (int) $p['ID'];
			$permalink = get_permalink( $post_id );

			if ( empty( $permalink ) || $this->is_url_excluded( $permalink ) ) {
				$examined_count++;
				$excluded_count++;
				$persisted_cursor = $post_id;
				continue;
			}

			$item_res = $this->db->add_scan_item(
				$scan_id,
				array(
					'url'        => $permalink,
					'post_id'    => $post_id,
					'post_type'  => $p['post_type'],
					'post_title' => $p['post_title'],
					'slug'       => $p['post_name'],
				)
			);

			if ( ! is_array( $item_res ) || 'failed' === $item_res['status'] ) {
				$enqueue_error = ( is_array( $item_res ) && ! empty( $item_res['error'] ) )
					? $item_res['error']
					: __( 'Failed to queue scan item.', 'crawlory-technical-seo-dashboard' );
				// STOP on enqueue failure without advancing the persisted cursor past this item!
				break;
			}

			$examined_count++;
			$persisted_cursor = $post_id;

			if ( 'inserted' === $item_res['status'] ) {
				$newly_added++;
			}
		}

		// Calculate discovered/total counts from distinct persisted inventory.
		// Calculate pending counts from actual pending queue rows.
		$item_counts      = $this->db->get_scan_item_counts( $scan_id );
		$discovered_count = (int) $item_counts['total'];
		$pending_count    = (int) $item_counts['pending'];

		$update_args = array(
			'last_examined_id' => $persisted_cursor,
			'examined_urls'    => $examined_count,
			'excluded_urls'    => $excluded_count,
			'discovered_urls'  => $discovered_count,
			'pending_urls'     => $pending_count,
		);

		if ( null === $enqueue_error && count( $posts ) < $limit ) {
			$update_args['discovery_status'] = 'completed';
			$update_args['total_urls']       = $discovered_count;
			$update_args['current_step']     = 'auditing';
		} else {
			$update_args['total_urls']   = max( (int) $scan->total_urls, $discovered_count );
			$update_args['current_step'] = 'discovery';
		}

		$upd_final_cur = $this->db->update_scan( $scan_id, $update_args, $worker_token );
		if ( false === $upd_final_cur ) {
			throw new Exception( __( 'Failed to update discovery cursor: database write error or lost worker ownership.', 'crawlory-technical-seo-dashboard' ) );
		}

		if ( null !== $enqueue_error ) {
			throw new Exception( __( 'Database error while queueing scan item: ', 'crawlory-technical-seo-dashboard' ) . $enqueue_error );
		}

		return $newly_added;
	}

	/**
	 * Retrieve next inventory batch using ID cursor pagination (WHERE ID > %d).
	 * Advances past excluded batches without skipping items.
	 *
	 * @param object|int $scan Scan object or scan ID.
	 * @param int        $limit Batch limit.
	 * @return array Eligible inventory items.
	 */
	public function get_next_inventory_items( $scan, $limit = 50 ) {
		global $wpdb;

		if ( is_numeric( $scan ) ) {
			$scan = $this->db->get_scan( (int) $scan );
		}
		if ( ! $scan ) {
			return array();
		}

		$last_id = isset( $scan->last_examined_id ) ? (int) $scan->last_examined_id : 0;
		$post_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );
		if ( empty( $post_types ) ) {
			return array();
		}

		$clean_types  = array_values( array_map( 'sanitize_key', $post_types ) );
		$placeholders = implode( ', ', array_fill( 0, count( $clean_types ), '%s' ) );
		$params       = array_merge( $clean_types, array( (int) $last_id, (int) $limit ) );

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table name and dynamic %s placeholders for allowlisted post types array.
				"SELECT ID, post_title, post_type, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$params
			),
			ARRAY_A
		);

		if ( ! empty( $wpdb->last_error ) ) {
			return array();
		}

		$items = array();
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $p ) {
				$permalink = get_permalink( $p['ID'] );
				if ( ! empty( $permalink ) && ! $this->is_url_excluded( $permalink ) ) {
					$items[] = array(
						'url'        => $permalink,
						'post_id'    => (int) $p['ID'],
						'post_type'  => $p['post_type'],
						'post_title' => $p['post_title'],
						'slug'       => $p['post_name'],
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Execute durable, resumable background processing loop with time budgets and atomic leases.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $budget_seconds Execution time budget in seconds (default: 20s).
	 * @return array Status array.
	 */
	public function process_work( $scan_id = 0, $budget_seconds = 20 ) {
		// Migration readiness: block background processing while migration is incomplete or failed.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return array(
				'status'  => 'unavailable',
				'message' => 'Migration in progress or incomplete. Processing deferred until database readiness is restored.',
			);
		}

		if ( $scan_id <= 0 ) {
			$active = $this->db->get_active_scan();
			if ( ! $active ) {
				return array( 'status' => 'idle', 'message' => 'No active scan found.' );
			}
			$scan_id = (int) $active->id;
		}

		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan ) {
			return array( 'status' => 'failed', 'error' => 'Scan not found.' );
		}

		if ( in_array( $scan->status, array( 'paused', 'cancelled', 'completed' ), true ) ) {
			return $this->get_scan_status( $scan_id );
		}

		// Attempt atomic lease claim in UTC.
		$worker_token = wp_generate_uuid4();
		$claimed      = $this->db->claim_scan_job( $scan_id, $worker_token, 300 );
		if ( ! $claimed ) {
			return array(
				'status'       => 'locked',
				'message'      => 'Job is currently leased by another active worker.',
				'scanned_urls' => (int) $scan->scanned_urls,
				'total_urls'   => (int) $scan->total_urls,
			);
		}

		$start_time  = microtime( true );
		$delay_ms    = (int) $this->settings->get( 'request_delay_ms', 150 );

		// 1. Recover any stalled scan items from previous workers.
		$this->db->reap_stalled_scan_items( $scan_id );

		// 2. Discover inventory if not yet completed.
		if ( 'completed' !== $scan->discovery_status ) {
			try {
				$this->discover_inventory( $scan_id, 100, $worker_token );
			} catch ( Throwable $t ) {
				$err_msg = 'Inventory discovery error: ' . $t->getMessage();
				$this->db->update_scan(
					$scan_id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				$this->db->release_worker_lease( $scan_id, $worker_token );
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'scan_id' => $scan_id,
				);
			}
		}

		while ( ( microtime( true ) - $start_time ) < $budget_seconds ) {
			// Check if a reset or migration barrier was raised using unified readiness check.
			if ( get_transient( 'crawlory_reset_barrier' ) || ! Crawlory_Migrator::is_ready() ) {
				$this->db->release_worker_lease( $scan_id, $worker_token );
				return array( 'status' => 'cancelled', 'message' => 'Migration or reset barrier active.' );
			}

			// Check for pause/cancel signals.
			$fresh_scan = $this->db->get_scan( $scan_id );
			if ( ! $fresh_scan || in_array( $fresh_scan->status, array( 'paused', 'cancelled' ), true ) ) {
				$this->db->release_worker_lease( $scan_id, $worker_token );
				return $this->get_scan_status( $scan_id );
			}

			// Atomically claim the next pending item.
			$item = $this->db->claim_next_scan_item( $scan_id, $worker_token );

			if ( ! $item ) {
				// No pending items in queue.
				// If discovery is not finished, discover more posts across batches until items found, finished, or time budget met.
				if ( 'completed' !== $fresh_scan->discovery_status ) {
					$found_items = false;
					$disc_failed = false;
					$disc_err    = '';
					while ( 'completed' !== $fresh_scan->discovery_status && ( microtime( true ) - $start_time ) < ( $budget_seconds - 2.0 ) ) {
						try {
							$discovered = $this->discover_inventory( $scan_id, 100, $worker_token );
						} catch ( Throwable $t ) {
							$disc_failed = true;
							$disc_err    = $t->getMessage();
							break;
						}
						$fresh_scan = $this->db->get_scan( $scan_id );
						if ( $discovered > 0 ) {
							$found_items = true;
							break;
						}
					}
					if ( $disc_failed ) {
						$err_msg = 'Inventory discovery error: ' . $disc_err;
						$this->db->update_scan(
							$scan_id,
							array(
								'finalization_error' => $err_msg,
							),
							$worker_token
						);
						$this->db->release_worker_lease( $scan_id, $worker_token );
						return array(
							'status'  => 'failed',
							'error'   => $err_msg,
							'scan_id' => $scan_id,
						);
					}
					if ( $found_items ) {
						continue;
					}
				}

				// Check item counts.
				try {
					$counts = $this->db->get_scan_item_counts( $scan_id );
				} catch ( Throwable $t ) {
					$err_msg = 'Failed to retrieve scan item counts: ' . $t->getMessage();
					$this->db->update_scan(
						$scan_id,
						array(
							'finalization_error' => $err_msg,
						),
						$worker_token
					);
					$this->db->release_worker_lease( $scan_id, $worker_token );
					return array(
						'status'  => 'failed',
						'error'   => $err_msg,
						'scan_id' => $scan_id,
					);
				}
				// Only finalize if discovery is fully completed AND no items remain pending or processing!
				if ( 'completed' === $fresh_scan->discovery_status && 0 === $counts['pending'] && 0 === $counts['processing'] ) {
					// All eligible items have finished! Finalize scan.
					$remaining_budget = max( 5, $budget_seconds - ( microtime( true ) - $start_time ) );
					return $this->finalize_scan( $fresh_scan, $worker_token, $remaining_budget );
				}

				// Discovery still incomplete or other workers busy; exit this slice cleanly.
				break;
			}

			// Bounded execution budget for auditing this item.
			$remaining_budget = max( 2.0, $budget_seconds - ( microtime( true ) - $start_time ) );

			$item_array = array(
				'url'        => $item->url,
				'post_id'    => (int) $item->post_id,
				'post_type'  => $item->post_type,
				'post_title' => $item->post_title,
				'slug'       => $item->slug,
			);

			$audit_res = $this->audit_single_url_item( $item_array, $remaining_budget, $scan_id, $worker_token );

			// Verify worker lease ownership before committing.
			if ( ! empty( $audit_res['lease_expired'] ) || ! $this->db->verify_worker_lease( $scan_id, $worker_token ) ) {
				return array(
					'status' => 'lease_expired',
					'error'  => 'Worker lease expired before commit.',
				);
			}

			// Atomically complete item in queue with accurate status, HTTP code, findings count, and error message.
			$status_val = $audit_res['success'] ? 'completed' : 'failed';
			$http_code  = isset( $audit_res['http_status'] ) ? (int) $audit_res['http_status'] : ( $audit_res['success'] ? 200 : 0 );
			$findings_c = isset( $audit_res['findings_count'] ) ? (int) $audit_res['findings_count'] : 0;
			$err_msg    = ! empty( $audit_res['error'] ) ? (string) $audit_res['error'] : null;

			$this->db->complete_scan_item(
				$item->id,
				$worker_token,
				$status_val,
				$http_code,
				$findings_c,
				$err_msg
			);

			// Update scan counters.
			try {
				$counts = $this->db->get_scan_item_counts( $scan_id );
				$upd_cnt = $this->db->update_scan(
					$scan_id,
					array(
						'scanned_urls'    => $counts['completed'],
						'failed_urls'     => $counts['failed'],
						'pending_urls'    => $counts['pending'],
						'processing_urls' => $counts['processing'],
						'findings_count'  => (int) $fresh_scan->findings_count + $findings_c,
					),
					$worker_token
				);
				if ( false === $upd_cnt ) {
					$this->db->release_worker_lease( $scan_id, $worker_token );
					return array(
						'status'  => 'failed',
						'error'   => 'Failed to update scan counters: lease expired or write failed.',
						'scan_id' => $scan_id,
					);
				}
			} catch ( Throwable $t ) {
				$err_msg = 'Failed to update scan item counters: ' . $t->getMessage();
				$this->db->update_scan(
					$scan_id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				$this->db->release_worker_lease( $scan_id, $worker_token );
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'scan_id' => $scan_id,
				);
			}

			// Renew worker lease and handle ownership loss.
			if ( ! $this->db->renew_worker_lease( $scan_id, $worker_token, 300 ) ) {
				return array(
					'status'  => 'lease_expired',
					'error'   => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
					'message' => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
				);
			}

			// Rate limiting delay.
			if ( $delay_ms > 0 ) {
				usleep( $delay_ms * 1000 );
			}
		}

		// Reached budget limit: release lease and spawn continuation.
		$this->db->release_worker_lease( $scan_id, $worker_token );

		$final_scan = $this->db->get_scan( $scan_id );
		if ( $final_scan && 'running' === $final_scan->status ) {
			$this->spawn_background_worker( $scan_id );
		}

		return $this->get_scan_status( $scan_id );
	}

	/**
	 * Run a batch iteration (called via CLI or backwards-compatible test runner).
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Batch progress status.
	 */
	public function run_batch( $scan_id ) {
		return $this->process_work( $scan_id, 8 );
	}

	/**
	 * Get read-only scan status.
	 * Fast (<10ms) read-only query that never crawls, parses sitemaps, or recalculates duplicates.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Scan status details.
	 */
	public function get_scan_status( $scan_id ) {
		$scan = $this->db->get_scan( $scan_id );
		if ( ! $scan ) {
			return array(
				'success' => false,
				'message' => 'Scan not found.',
				'status'  => 'unknown',
			);
		}

		try {
			$counts = $this->db->get_scan_item_counts( $scan_id );
		} catch ( Throwable $t ) {
			// Status polling must remain read-only and must not report failed count queries as zero.
			return array(
				'success'            => false,
				'scan_id'            => (int) $scan->id,
				'job_id'             => (int) $scan->id,
				'status'             => $scan->status,
				'error'              => 'Failed to retrieve scan item counts: ' . $t->getMessage(),
				'message'            => 'Failed to retrieve scan item counts: ' . $t->getMessage(),
				'finalization_stage' => isset( $scan->finalization_stage ) ? $scan->finalization_stage : null,
				'finalization_error' => isset( $scan->finalization_error ) ? $scan->finalization_error : null,
			);
		}

		$total     = (int) $scan->total_urls;
		$done      = (int) $counts['completed'];
		$failed    = (int) $counts['failed'];
		$processed = $done + $failed; // Terminal URL outcomes
		$is_provisional = ( 'completed' !== $scan->discovery_status );
		$pct       = ( $total > 0 ) ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;
		if ( 'completed' === $scan->status && ( 0 === $total || $processed >= $total ) ) {
			$pct = 100;
		}

		return array(
			'success'            => true,
			'scan_id'            => (int) $scan->id,
			'job_id'             => (int) $scan->id,
			'status'             => $scan->status,
			'total_urls'         => $total,
			'scanned_urls'       => $done,
			'processed_urls'     => $processed,
			'failed_urls'        => (int) $counts['failed'],
			'pending_urls'       => (int) $counts['pending'],
			'processing_urls'    => (int) $counts['processing'],
			'discovered_urls'    => (int) $scan->discovered_urls,
			'excluded_urls'      => (int) $scan->excluded_urls,
			'findings_count'     => (int) $scan->findings_count,
			'percent'            => $pct,
			'is_complete'        => ( 'completed' === $scan->status ),
			'is_provisional'     => $is_provisional,
			'current_step'       => isset( $scan->current_step ) ? $scan->current_step : null,
			'finalization_stage' => isset( $scan->finalization_stage ) ? $scan->finalization_stage : null,
			'finalization_error' => isset( $scan->finalization_error ) ? $scan->finalization_error : null,
		);
	}

	/**
	 * Check if a URL matches any excluded pattern in settings.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_url_excluded( $url ) {
		$raw_exclusions = $this->settings->get( 'excluded_urls', '' );
		if ( empty( $raw_exclusions ) ) {
			return false;
		}

		$lines = explode( "\n", str_replace( "\r", '', $raw_exclusions ) );
		foreach ( $lines as $line ) {
			$pattern = trim( $line );
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== stripos( $url, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persist audit results to database with atomic transaction safety and confirmed commit.
	 *
	 * Verifies engine transaction support, checks START TRANSACTION, all required writes,
	 * and confirmed COMMIT. Handles rollback consistently, preserves last successful audit,
	 * and avoids updating or returning rolled-back page IDs.
	 *
	 * @param array       $page_data Page record fields.
	 * @param array       $links Discovered links array.
	 * @param array       $findings Findings array.
	 * @param array       $check_statuses Rule execution statuses.
	 * @param string      $outcome Scan outcome.
	 * @param bool        $is_new_page True if page row did not exist in DB prior to write.
	 * @param object|null $existing_page Pre-existing page row if available.
	 * @return array Persistence result with 'success', 'page_id', 'findings_count', 'error'.
	 */
	private function persist_audit_result( array $page_data, array $links, array $findings, array $check_statuses, $outcome, $is_new_page, $existing_page ) {
		global $wpdb;

		$supports_tx = $this->db->supports_transactions();
		if ( true !== $supports_tx ) {
			return array(
				'success'        => false,
				'page_id'        => ( $existing_page && isset( $existing_page->id ) ) ? (int) $existing_page->id : 0,
				'findings_count' => 0,
				'error'          => __( 'Database transaction support is unavailable or unknown. Halting before destructive writes to protect audit integrity.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		$tx_res = $wpdb->query( 'START TRANSACTION' );
		if ( false === $tx_res ) {
			return array(
				'success'        => false,
				'page_id'        => 0,
				'findings_count' => 0,
				'error'          => 'Failed to start database transaction.',
			);
		}
		$tx_active = true;

		$page_id   = 0;
		$error_msg = null;

		try {
			$is_success_outcome = ( 'success' === $outcome );
			if ( $is_success_outcome ) {
				$page_data['scan_status'] = 'processing';
			}

			$page_id = $this->db->upsert_page( $page_data );
			if ( ! $page_id ) {
				throw new Exception( 'Database write failed during page upsert.' );
			}

			if ( $is_success_outcome ) {
				$links_res = $this->db->save_page_links( $page_id, $page_data['url'], $links );
				if ( false === $links_res ) {
					throw new Exception( 'Database write failed during links persistence.' );
				}
			}

			$active_findings_count = $this->db->replace_page_findings(
				$page_id,
				$findings,
				$check_statuses,
				$outcome
			);
			if ( false === $active_findings_count ) {
				throw new Exception( 'Database write failed during findings persistence.' );
			}

			if ( $is_success_outcome ) {
				$now          = Crawlory_Helper::now_utc();
				$finalize_res = $wpdb->update(
					$wpdb->prefix . 'crawlory_pages',
					array(
						'scan_status'                => 'success',
						'last_scanned_at'            => $now,
						'last_successful_scanned_at' => $now,
						'fetch_error'                => null,
						'updated_at'                 => $now,
					),
					array( 'id' => (int) $page_id )
				);
				if ( false === $finalize_res ) {
					throw new Exception( 'Database write failed during final page status commit.' );
				}
			}

			if ( $tx_active ) {
				$commit_res = $wpdb->query( 'COMMIT' );
				if ( false === $commit_res ) {
					throw new Exception( 'Database transaction commit failed.' );
				}
				$tx_active = false;
			}

			return array(
				'success'        => $is_success_outcome,
				'page_id'        => (int) $page_id,
				'findings_count' => (int) $active_findings_count,
				'error'          => null,
			);
		} catch ( Throwable $e ) {
			$error_msg = $e->getMessage();

			if ( $tx_active ) {
				$wpdb->query( 'ROLLBACK' );
				$tx_active = false;
			}

			// Crucial requirement: After rolling back a newly inserted page,
			// the row is discarded by MySQL. Do NOT update or return the rolled-back page ID!
			if ( $is_new_page ) {
				return array(
					'success'        => false,
					'page_id'        => 0,
					'findings_count' => 0,
					'error'          => $error_msg,
				);
			}

			// If existing page row was modified and rolled back, the existing row is restored by MySQL rollback.
			// Return failure without modifying or deleting prior data.
			if ( $existing_page && isset( $existing_page->id ) ) {
				$this->db->mark_page_failed( (int) $existing_page->id, $error_msg );
				return array(
					'success'        => false,
					'page_id'        => (int) $existing_page->id,
					'findings_count' => 0,
					'error'          => $error_msg,
				);
			}

			return array(
				'success'        => false,
				'page_id'        => 0,
				'findings_count' => 0,
				'error'          => $error_msg,
			);
		}
	}

	/**
	 * Audit a single URL item (fetch, parse, analyze, record findings).
	 * Enforces outcome-aware evaluation to prevent false findings on fetch failures.
	 *
	 * @param array       $item Item with 'url', 'post_id', 'post_title', 'post_type', 'slug'.
	 * @param float       $time_budget Remaining worker execution budget in seconds.
	 * @param int         $scan_id Scan ID for lease verification.
	 * @param string|null $worker_token Worker token for lease verification.
	 * @return array Result summary.
	 */
	public function audit_single_url_item( array $item, $time_budget = 15.0, $scan_id = 0, $worker_token = null ) {
		// Migration readiness check: block execution while migration is incomplete or failed.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return array(
				'success'        => false,
				'page_id'        => 0,
				'http_status'    => 0,
				'findings_count' => 0,
				'error'          => 'Migration incomplete or failed. Audit execution deferred.',
				'outcome'        => 'migration_in_progress',
			);
		}

		$url         = $item['url'];
		$post_id     = (int) $item['post_id'];
		$post_title  = $item['post_title'];
		$post_type   = $item['post_type'];
		$stored_slug = ! empty( $item['slug'] ) ? $item['slug'] : '';

		// 1. Fetch live HTML.
		$fetch_res = $this->fetcher->fetch( $url );

		$now          = gmdate( 'Y-m-d H:i:s' );
		$url_max      = (int) $this->settings->get( 'url_max_length', 115 );
		$url_analysis = Crawlory_URL_Analyzer::analyze( $url, $stored_slug, $url_max );

		$outcome = isset( $fetch_res['outcome'] ) ? $fetch_res['outcome'] : ( $fetch_res['success'] ? 'success' : 'http_error' );

		// Preflight existing page row before persistence to distinguish new vs existing rows on rollback.
		$existing_page = $this->db->get_page_by_url( $url );
		$is_new_page   = ( null === $existing_page );

		// If fetch was NOT successful (timeout, HTTP error >= 400, blocked, non-HTML, etc.):
		if ( 'success' !== $outcome ) {
			// Run auditor with outcome awareness (technical rules evaluate, HTML checks receive not_evaluated status).
			$findings = $this->auditor->audit_page( array(), $url_analysis, $url, $stored_slug, $fetch_res, array(), $time_budget );

			$page_data = array(
				'scan_id'                => (int) $scan_id,
				'url'                    => $url,
				'post_id'                => $post_id,
				'post_type'              => $post_type,
				'post_title'             => $post_title,
				'http_status'            => isset( $fetch_res['http_status'] ) ? $fetch_res['http_status'] : 0,
				'final_url'              => isset( $fetch_res['final_url'] ) ? $fetch_res['final_url'] : $url,
				'is_redirect'            => ! empty( $fetch_res['is_redirect'] ) ? 1 : 0,
				'content_type'           => isset( $fetch_res['content_type'] ) ? $fetch_res['content_type'] : null,
				'scan_status'            => 'failed',
				'fetch_error'            => isset( $fetch_res['error'] ) ? $fetch_res['error'] : 'Audit fetch unsuccessful.',
				'last_scanned_at'        => $now,
				'last_failed_scanned_at' => $now,
			);

			// Preserve existing metadata if previously audited successfully.
			if ( $existing_page ) {
				$page_data['seo_title']               = $existing_page->seo_title;
				$page_data['seo_title_length']        = $existing_page->seo_title_length;
				$page_data['meta_description']        = $existing_page->meta_description;
				$page_data['meta_description_length'] = $existing_page->meta_description_length;
				$page_data['h1_count']                = $existing_page->h1_count;
				$page_data['h1_contents']             = $existing_page->h1_contents;
				$page_data['canonical_url']           = $existing_page->canonical_url;
				$page_data['canonical_status']        = $existing_page->canonical_status;
			}

			// Verify worker lease ownership and ensure reset barrier is not active before writing results to database.
			if ( get_transient( 'crawlory_reset_barrier' ) || ! Crawlory_Migrator::is_ready() || ( $scan_id > 0 && ! empty( $worker_token ) && ! $this->db->verify_worker_lease( $scan_id, $worker_token ) ) ) {
				return array(
					'success'        => false,
					'page_id'        => 0,
					'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 0,
					'findings_count' => 0,
					'error'          => 'Worker lease expired or migration/reset barrier active before commit.',
					'outcome'        => 'lease_expired',
					'lease_expired'  => true,
				);
			}

			$p_res = $this->persist_audit_result(
				$page_data,
				array(),
				$findings,
				$this->auditor->executed_checks,
				$outcome,
				$is_new_page,
				$existing_page
			);

			return array(
				'success'        => false,
				'page_id'        => $p_res['page_id'],
				'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 0,
				'findings_count' => $p_res['findings_count'],
				'error'          => ! empty( $p_res['error'] ) ? $p_res['error'] : ( isset( $fetch_res['error'] ) ? $fetch_res['error'] : 'Scan failed' ),
				'outcome'        => $outcome,
			);
		}

		// 2. Parse HTML using final URL destination as base for relative URLs.
		$base_url    = ! empty( $fetch_res['final_url'] ) ? $fetch_res['final_url'] : $url;
		$parsed_html = Crawlory_HTML_Parser::parse( $fetch_res['html'], $base_url );

		// If HTML parser failed or returned invalid HTML, handle as parse_error outcome.
		// Never generate missing title/description/h1 findings on parse failures!
		if ( empty( $parsed_html['is_valid_html'] ) ) {
			$parse_fetch_res            = $fetch_res;
			$parse_fetch_res['outcome'] = 'parse_error';
			$parse_fetch_res['error']   = ! empty( $parsed_html['parse_error'] ) ? $parsed_html['parse_error'] : 'HTML parsing failed.';

			$findings = $this->auditor->audit_page( array(), $url_analysis, $url, $stored_slug, $parse_fetch_res, array(), $time_budget );

			$page_data = array(
				'scan_id'                => (int) $scan_id,
				'url'                    => $url,
				'post_id'                => $post_id,
				'post_type'              => $post_type,
				'post_title'             => $post_title,
				'http_status'            => isset( $fetch_res['http_status'] ) ? $fetch_res['http_status'] : 200,
				'final_url'              => isset( $fetch_res['final_url'] ) ? $fetch_res['final_url'] : $url,
				'is_redirect'            => ! empty( $fetch_res['is_redirect'] ) ? 1 : 0,
				'content_type'           => isset( $fetch_res['content_type'] ) ? $fetch_res['content_type'] : null,
				'scan_status'            => 'failed',
				'fetch_error'            => $parse_fetch_res['error'],
				'last_scanned_at'        => $now,
				'last_failed_scanned_at' => $now,
			);

			// Preserve existing metadata if previously audited successfully.
			if ( $existing_page ) {
				$page_data['seo_title']               = $existing_page->seo_title;
				$page_data['seo_title_length']        = $existing_page->seo_title_length;
				$page_data['meta_description']        = $existing_page->meta_description;
				$page_data['meta_description_length'] = $existing_page->meta_description_length;
				$page_data['h1_count']                = $existing_page->h1_count;
				$page_data['h1_contents']             = $existing_page->h1_contents;
				$page_data['canonical_url']           = $existing_page->canonical_url;
				$page_data['canonical_status']        = $existing_page->canonical_status;
			}

			// Verify worker lease ownership and ensure reset barrier is not active before writing results.
			if ( get_transient( 'crawlory_reset_barrier' ) || ! Crawlory_Migrator::is_ready() || ( $scan_id > 0 && ! empty( $worker_token ) && ! $this->db->verify_worker_lease( $scan_id, $worker_token ) ) ) {
				return array(
					'success'        => false,
					'page_id'        => 0,
					'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200,
					'findings_count' => 0,
					'error'          => 'Worker lease expired or migration/reset barrier active before commit.',
					'outcome'        => 'lease_expired',
					'lease_expired'  => true,
				);
			}

			$p_res = $this->persist_audit_result(
				$page_data,
				array(),
				$findings,
				$this->auditor->executed_checks,
				'parse_error',
				$is_new_page,
				$existing_page
			);

			return array(
				'success'        => false,
				'page_id'        => $p_res['page_id'],
				'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200,
				'findings_count' => $p_res['findings_count'],
				'error'          => ! empty( $p_res['error'] ) ? $p_res['error'] : $parse_fetch_res['error'],
				'outcome'        => 'parse_error',
			);
		}

		// 3. Run audit rules (1-33).
		$findings = $this->auditor->audit_page( $parsed_html, $url_analysis, $url, $stored_slug, $fetch_res, array(), $time_budget );

		// 4. Prepare page record fields.
		$primary_title = ! empty( $parsed_html['titles'][0] ) ? $parsed_html['titles'][0]['normalized'] : '';
		$title_len     = ! empty( $parsed_html['titles'][0] ) ? $parsed_html['titles'][0]['char_count'] : 0;

		$primary_desc = '';
		$desc_len     = 0;
		foreach ( $parsed_html['descriptions'] as $d ) {
			if ( 'valid' === $d['status'] && ! empty( $d['normalized'] ) ) {
				$primary_desc = $d['normalized'];
				$desc_len     = $d['char_count'];
				break;
			}
		}

		$h1_texts = array();
		foreach ( $parsed_html['h1s'] as $h ) {
			$h1_texts[] = $h['normalized'];
		}

		$norm_title_for_dup = Crawlory_Normalizer::normalize_for_comparison( $primary_title );
		$norm_desc_for_dup  = Crawlory_Normalizer::normalize_for_comparison( $primary_desc );

		// Extract canonical and indexing signals.
		$canon_url = ! empty( $parsed_html['canonicals'][0]['href'] ) ? $parsed_html['canonicals'][0]['href'] : ( ! empty( $fetch_res['canonical_header'] ) ? $fetch_res['canonical_header'] : '' );
		$is_noindex = false;
		$robots_directives = array();
		foreach ( $parsed_html['robots_meta'] as $rm ) {
			if ( ! empty( $rm['has_noindex'] ) ) {
				$is_noindex = true;
			}
			$robots_directives[] = $rm['content'];
		}
		if ( ! empty( $fetch_res['x_robots_tag'] ) && false !== stripos( $fetch_res['x_robots_tag'], 'noindex' ) ) {
			$is_noindex = true;
		}

		// Check robots.txt blocking for Googlebot.
		$robots_eval = class_exists( 'Crawlory_Robots_Parser' ) ? Crawlory_Robots_Parser::evaluate_url( $url ) : array();
		$is_blocked  = ! empty( $robots_eval['is_blocked'] ) ? 1 : 0;
		$block_rule  = ! empty( $robots_eval['matching_rule'] ) ? $robots_eval['matching_rule'] : null;

		$in_sitemap = $this->db->is_url_in_sitemap( $url ) ? 1 : 0;

		$page_data = array(
			'scan_id'                    => (int) $scan_id,
			'url'                        => $url,
			'post_id'                    => $post_id,
			'post_type'                  => $post_type,
			'post_title'                 => $post_title,
			'http_status'                => $fetch_res['http_status'],
			'final_url'                  => $fetch_res['final_url'],
			'is_redirect'                => ! empty( $fetch_res['is_redirect'] ) ? 1 : 0,
			'content_type'               => $fetch_res['content_type'],
			'scan_status'                => 'success',
			'fetch_error'                => null,
			'seo_title'                  => $primary_title,
			'seo_title_length'           => $title_len,
			'meta_description'           => $primary_desc,
			'meta_description_length'    => $desc_len,
			'h1_count'                   => count( $parsed_html['h1s'] ),
			'h1_contents'                => wp_json_encode( $h1_texts ),
			'canonical_url'              => $canon_url,
			'canonical_status'           => ! empty( $canon_url ) ? ( untrailingslashit( $canon_url ) === untrailingslashit( $url ) ? 'self' : 'other' ) : 'missing',
			'robots_meta'                => implode( ', ', $robots_directives ),
			'x_robots_tag'               => isset( $fetch_res['x_robots_tag'] ) ? $fetch_res['x_robots_tag'] : null,
			'is_noindex'                 => $is_noindex ? 1 : 0,
			'is_robots_blocked'          => $is_blocked,
			'robots_blocking_rule'       => $block_rule,
			'in_sitemap'                 => $in_sitemap,
			'has_incomplete_resources'   => ! empty( $this->auditor->has_incomplete_resources ) ? 1 : 0,
			'normalized_title'           => $norm_title_for_dup,
			'normalized_description'     => $norm_desc_for_dup,
			'last_scanned_at'            => $now,
			'last_successful_scanned_at' => $now,
		);

		// Verify worker lease ownership and ensure reset and migration barriers are not active before writing results.
		if ( get_transient( 'crawlory_reset_barrier' ) || ! Crawlory_Migrator::is_ready() || ( $scan_id > 0 && ! empty( $worker_token ) && ! $this->db->verify_worker_lease( $scan_id, $worker_token ) ) ) {
			return array(
				'success'        => false,
				'page_id'        => 0,
				'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200,
				'findings_count' => 0,
				'error'          => 'Worker lease expired or migration/reset barrier active before commit.',
				'outcome'        => 'lease_expired',
				'lease_expired'  => true,
			);
		}

		// 5. Build links list.
		$links_to_save = array();
		if ( ! empty( $parsed_html['links'] ) ) {
			foreach ( $parsed_html['links'] as $lnk ) {
				$links_to_save[] = array(
					'target_url'  => $lnk['href'],
					'anchor_text' => $lnk['anchor_text'],
					'is_internal' => $lnk['is_internal'],
				);
			}
		}

		// 6. Persist with full atomic transaction protection and confirmed commit.
		$p_res = $this->persist_audit_result(
			$page_data,
			$links_to_save,
			$findings,
			$this->auditor->executed_checks,
			'success',
			$is_new_page,
			$existing_page
		);

		if ( ! $p_res['success'] ) {
			return array(
				'success'        => false,
				'page_id'        => $p_res['page_id'],
				'http_status'    => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200,
				'findings_count' => 0,
				'error'          => $p_res['error'],
				'outcome'        => 'db_error',
			);
		}

		return array(
			'success'                  => true,
			'page_id'                  => $p_res['page_id'],
			'http_status'              => isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200,
			'findings_count'           => $p_res['findings_count'],
			'has_incomplete_resources' => ! empty( $this->auditor->has_incomplete_resources ),
			'error'                    => null,
			'outcome'                  => 'success',
		);
	}

	/**
	 * Rescan a single URL on demand.
	 *
	 * @param string $url URL to rescan.
	 * @param int    $post_id Post ID if known.
	 * @return array Audit result.
	 */
	public function rescan_single_url( $url, $post_id = 0 ) {
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return array(
				'success' => false,
				'error'   => __( 'Database migration is incomplete or failed. Rescan deferred until database readiness is restored.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		$val = Crawlory_Fetcher::validate_audit_url( $url, true );
		if ( ! $val['valid'] ) {
			return array(
				'success' => false,
				'error'   => $val['error'],
			);
		}

		$post_title = '';
		$post_type  = 'page';
		$slug       = '';

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_title = $post->post_title;
				$post_type  = $post->post_type;
				$slug       = $post->post_name;
			}
		} elseif ( ! empty( $url ) ) {
			$resolved_id = url_to_postid( $url );
			if ( $resolved_id > 0 ) {
				$post_id    = $resolved_id;
				$post       = get_post( $resolved_id );
				$post_title = $post ? $post->post_title : '';
				$post_type  = $post ? $post->post_type : 'page';
				$slug       = $post ? $post->post_name : '';
			}
		}

		$worker_token = wp_generate_uuid4();
		$now_utc      = gmdate( 'Y-m-d H:i:s' );
		$expires_utc  = gmdate( 'Y-m-d H:i:s', time() + 120 );

		// 1. Create a real persisted single-URL scan record
		$scan_id = $this->db->create_scan(
			array(
				'status'               => 'running',
				'scan_type'            => 'single_url',
				'total_urls'           => 1,
				'target_urls'          => wp_json_encode( array( $url ) ),
				'worker_token'         => $worker_token,
				'worker_lease_expires' => $expires_utc,
				'discovery_status'     => 'completed',
				'finalization_stage'   => 'pending',
				'current_step'         => 'audit',
				'started_at'           => $now_utc,
			)
		);

		if ( ! $scan_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to create audit record in database.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		$item = array(
			'url'        => $url,
			'post_id'    => $post_id,
			'post_title' => $post_title ? $post_title : 'Custom URL',
			'post_type'  => $post_type,
			'slug'       => $slug,
		);

		// Pass real scan_id and worker_token to page audit
		$res = $this->audit_single_url_item( $item, 20.0, $scan_id, $worker_token );

		// If audit failed to save or aborted before commit
		if ( empty( $res['success'] ) ) {
			$this->db->update_scan(
				$scan_id,
				array(
					'status'             => 'failed',
					'failed_urls'        => 1,
					'error_message'      => ! empty( $res['error'] ) ? $res['error'] : __( 'Audit failed', 'crawlory-technical-seo-dashboard' ),
					'finalization_stage' => 'failed',
					'finished_at'        => Crawlory_Helper::now_utc(),
				),
				$worker_token
			);
			$this->db->release_worker_lease( $scan_id, $worker_token );
			return $res;
		}

		// Verify worker ownership before starting cross-page reconciliation
		if ( ! $this->db->renew_worker_lease( $scan_id, $worker_token, 120 ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		// Reconcile duplicates and orphans; mark completion ONLY after reconciliation succeeds
		try {
			$upd_dup = $this->db->update_scan(
				$scan_id,
				array(
					'finalization_stage' => 'duplicates',
					'current_step'       => 'duplicates',
				),
				$worker_token
			);
			if ( false === $upd_dup ) {
				throw new Exception( __( 'Processing stopped because worker ownership was lost or duplicates stage update failed.', 'crawlory-technical-seo-dashboard' ) );
			}
			$this->dup_manager->recalculate_all_duplicates();

			$upd_orph = $this->db->update_scan(
				$scan_id,
				array(
					'finalization_stage' => 'orphans',
					'current_step'       => 'orphans',
				),
				$worker_token
			);
			if ( false === $upd_orph ) {
				throw new Exception( __( 'Processing stopped because worker ownership was lost or orphans stage update failed.', 'crawlory-technical-seo-dashboard' ) );
			}
			$is_r27_enabled = $this->settings->is_rule_enabled( 27 );
			$r27_severity   = $this->settings->get_rule_severity( 27 );
			$this->db->recalculate_orphan_pages( $is_r27_enabled, $r27_severity );

			// Refresh finding counts across all pages after duplicate and orphan changes
			// to ensure pages affected indirectly by the single-URL scan are updated.
			$this->db->refresh_all_page_findings_counts();

			global $wpdb;
			$page_id = isset( $res['page_id'] ) ? (int) $res['page_id'] : 0;
			if ( $page_id > 0 ) {
				$new_page_findings = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT findings_count FROM {$this->db->pages_table} WHERE id = %d",
						$page_id
					)
				);
				if ( null === $new_page_findings || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to retrieve updated findings count for audited page: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$res['findings_count'] = (int) $new_page_findings;
			}

			// Refresh total scan findings count consistently with scan scope
			$total_scan_findings = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->db->findings_table} f
					INNER JOIN {$this->db->pages_table} p ON p.id = f.page_id
					WHERE p.scan_id = %d AND f.is_ignored = 0 AND f.finding_status != 'resolved'",
					$scan_id
				)
			);
			if ( null === $total_scan_findings || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to query scan total findings count: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}
			$total_scan_findings = (int) $total_scan_findings;

			// Authoritatively verify worker ownership before publishing terminal completion state
			if ( ! $this->db->renew_worker_lease( $scan_id, $worker_token, 120 ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
				);
			}

			// Mark terminal completion and check scan update result
			$now_comp = Crawlory_Helper::now_utc();
			$upd_scan = $this->db->update_scan(
				$scan_id,
				array(
					'status'             => 'completed',
					'scanned_urls'       => 1,
					'failed_urls'        => 0,
					'findings_count'     => $total_scan_findings,
					'finalization_stage' => 'completed',
					'current_step'       => 'completed',
					'finished_at'        => $now_comp,
				),
				$worker_token
			);
			if ( false === $upd_scan ) {
				throw new Exception( __( 'Failed to record scan completion state.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Run completion side effects only after confirmed completion persistence
			update_option( 'crawlory_last_scan_id', $scan_id );
		} catch ( Throwable $t ) {
			// Reconciliation failed: retain prior findings and mark scan as failed
			$this->db->update_scan(
				$scan_id,
				array(
					'status'             => 'failed',
					'finalization_stage' => 'failed',
					'finalization_error' => 'Reconciliation error: ' . $t->getMessage(),
					'error_message'      => 'Reconciliation error: ' . $t->getMessage(),
					'finished_at'        => Crawlory_Helper::now_utc(),
				),
				$worker_token
			);
			$this->db->release_worker_lease( $scan_id, $worker_token );

			return array(
				'success'        => false,
				'page_id'        => isset( $res['page_id'] ) ? (int) $res['page_id'] : 0,
				'findings_count' => isset( $res['findings_count'] ) ? (int) $res['findings_count'] : 0,
				'error'          => sprintf(
					/* translators: %s: Error message */
					__( 'Audit completed but cross-page reconciliation failed: %s', 'crawlory-technical-seo-dashboard' ),
					$t->getMessage()
				),
			);
		}

		$this->db->release_worker_lease( $scan_id, $worker_token );

		return $res;
	}

	/**
	 * Finalize scan through multi-stage resumable and idempotent stages.
	 *
	 * @param object      $scan Scan record.
	 * @param string|null $worker_token Worker token if leased.
	 * @param float       $budget Remaining seconds for this execution slice.
	 * @return array Finalization response.
	 */
	public function finalize_scan( $scan, $worker_token = null, $budget = 15.0 ) {
		$stage      = ! empty( $scan->finalization_stage ) ? $scan->finalization_stage : 'sitemaps';
		$start_time = microtime( true );

		if ( $worker_token ) {
			if ( ! $this->db->renew_worker_lease( $scan->id, $worker_token, 120 ) ) {
				return array(
					'status'  => 'lease_expired',
					'error'   => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
					'message' => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
				);
			}
		}

		// Stage 1: Inspect XML sitemaps using correct Crawlory_Sitemap_Parser definition.
		if ( in_array( $stage, array( 'pending', 'sitemaps' ), true ) ) {
			try {
				if ( class_exists( 'Crawlory_Sitemap_Parser' ) ) {
					$sitemap_parser = new Crawlory_Sitemap_Parser( $this->db );
					$rem_budget     = max( 2.5, $budget - ( microtime( true ) - $start_time ) );
					$sm_res         = $sitemap_parser->inspect_sitemaps( 20, $rem_budget );
					if ( ! empty( $sm_res['is_incomplete'] ) && ! empty( $sm_res['remaining_queue'] ) ) {
						// Sitemap queue incomplete due to budget or network limits - yield and resume in next worker slice.
						if ( $worker_token ) {
							$this->db->release_worker_lease( $scan->id, $worker_token );
						}
						$this->spawn_background_worker( $scan->id );
						return $this->get_scan_status( $scan->id );
					}
				}
				$upd_stage1 = $this->db->update_scan(
					$scan->id,
					array(
						'finalization_stage' => 'duplicates',
						'finalization_error' => null,
						'current_step'       => 'duplicates',
					),
					$worker_token
				);
				if ( false === $upd_stage1 ) {
					if ( $worker_token ) {
						$this->db->release_worker_lease( $scan->id, $worker_token );
					}
					return array(
						'status'  => 'failed',
						'error'   => __( 'Processing stopped because worker ownership was lost or stage update failed.', 'crawlory-technical-seo-dashboard' ),
						'stage'   => 'sitemaps',
						'scan_id' => $scan->id,
					);
				}
				$stage = 'duplicates';
			} catch ( Throwable $t ) {
				$err_msg = 'Sitemap inspection error: ' . $t->getMessage();
				$this->db->update_scan(
					$scan->id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'stage'   => 'sitemaps',
					'scan_id' => $scan->id,
				);
			}

			// Time budget check: yield if slice budget is nearly exhausted.
			if ( ( microtime( true ) - $start_time ) >= ( $budget - 2.0 ) ) {
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				$this->spawn_background_worker( $scan->id );
				return $this->get_scan_status( $scan->id );
			}
		}

		// Stage 2: Reconcile duplicate titles and meta descriptions across site.
		if ( 'duplicates' === $stage ) {
			try {
				$this->dup_manager->recalculate_all_duplicates();
				$upd_stage2 = $this->db->update_scan(
					$scan->id,
					array(
						'finalization_stage' => 'orphans',
						'finalization_error' => null,
						'current_step'       => 'orphans',
					),
					$worker_token
				);
				if ( false === $upd_stage2 ) {
					if ( $worker_token ) {
						$this->db->release_worker_lease( $scan->id, $worker_token );
					}
					return array(
						'status'  => 'failed',
						'error'   => __( 'Processing stopped because worker ownership was lost or stage update failed.', 'crawlory-technical-seo-dashboard' ),
						'stage'   => 'duplicates',
						'scan_id' => $scan->id,
					);
				}
				$stage = 'orphans';
			} catch ( Throwable $t ) {
				$err_msg = 'Duplicate reconciliation error: ' . $t->getMessage();
				$this->db->update_scan(
					$scan->id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'stage'   => 'duplicates',
					'scan_id' => $scan->id,
				);
			}

			// Time budget check: yield if slice budget is nearly exhausted.
			if ( ( microtime( true ) - $start_time ) >= ( $budget - 2.0 ) ) {
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				$this->spawn_background_worker( $scan->id );
				return $this->get_scan_status( $scan->id );
			}
		}

		// Stage 3: Recalculate potential orphan pages.
		if ( 'orphans' === $stage ) {
			try {
				$is_r27_enabled = $this->settings->is_rule_enabled( 27 );
				$r27_severity   = $this->settings->get_rule_severity( 27 );
				$this->db->recalculate_orphan_pages( $is_r27_enabled, $r27_severity );
				$upd_stage3 = $this->db->update_scan(
					$scan->id,
					array(
						'finalization_stage' => 'retention',
						'finalization_error' => null,
						'current_step'       => 'retention',
					),
					$worker_token
				);
				if ( false === $upd_stage3 ) {
					if ( $worker_token ) {
						$this->db->release_worker_lease( $scan->id, $worker_token );
					}
					return array(
						'status'  => 'failed',
						'error'   => __( 'Processing stopped because worker ownership was lost or stage update failed.', 'crawlory-technical-seo-dashboard' ),
						'stage'   => 'orphans',
						'scan_id' => $scan->id,
					);
				}
				$stage = 'retention';
			} catch ( Throwable $t ) {
				$err_msg = 'Orphan page reconciliation error: ' . $t->getMessage();
				$this->db->update_scan(
					$scan->id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'stage'   => 'orphans',
					'scan_id' => $scan->id,
				);
			}

			// Time budget check: yield if slice budget is nearly exhausted.
			if ( ( microtime( true ) - $start_time ) >= ( $budget - 2.0 ) ) {
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				$this->spawn_background_worker( $scan->id );
				return $this->get_scan_status( $scan->id );
			}
		}

		// Stage 4: Prune data retention if configured.
		if ( 'retention' === $stage ) {
			try {
				$retention_days = (int) $this->settings->get( 'data_retention_days', 90 );
				if ( $retention_days > 0 ) {
					$this->db->prune_old_data( $retention_days );
				}
				$upd_stage4 = $this->db->update_scan(
					$scan->id,
					array(
						'finalization_stage' => 'counters',
						'finalization_error' => null,
						'current_step'       => 'counters',
					),
					$worker_token
				);
				if ( false === $upd_stage4 ) {
					if ( $worker_token ) {
						$this->db->release_worker_lease( $scan->id, $worker_token );
					}
					return array(
						'status'  => 'failed',
						'error'   => __( 'Processing stopped because worker ownership was lost or stage update failed.', 'crawlory-technical-seo-dashboard' ),
						'stage'   => 'retention',
						'scan_id' => $scan->id,
					);
				}
				$stage = 'counters';
			} catch ( Throwable $t ) {
				$err_msg = 'Data retention pruning error: ' . $t->getMessage();
				$this->db->update_scan(
					$scan->id,
					array(
						'finalization_error' => $err_msg,
					),
					$worker_token
				);
				if ( $worker_token ) {
					$this->db->release_worker_lease( $scan->id, $worker_token );
				}
				return array(
					'status'  => 'failed',
					'error'   => $err_msg,
					'stage'   => 'retention',
					'scan_id' => $scan->id,
				);
			}
		}

		// Stage 5: Terminal completion & lease release.
		if ( $worker_token && ! $this->db->renew_worker_lease( $scan->id, $worker_token, 120 ) ) {
			return array(
				'status'  => 'lease_expired',
				'error'   => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
				'message' => __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		// Refresh finding counts for all pages after duplicate & orphan changes
		try {
			$this->db->refresh_all_page_findings_counts();
		} catch ( Throwable $t ) {
			$err_msg = 'Failed to refresh page findings counts: ' . $t->getMessage();
			$this->db->update_scan(
				$scan->id,
				array(
					'finalization_error' => $err_msg,
				),
				$worker_token
			);
			if ( $worker_token ) {
				$this->db->release_worker_lease( $scan->id, $worker_token );
			}
			return array(
				'status'  => 'failed',
				'error'   => $err_msg,
				'stage'   => 'counters',
				'scan_id' => $scan->id,
			);
		}

		try {
			$counts = $this->db->get_scan_item_counts( $scan->id );
		} catch ( Throwable $t ) {
			$err_msg = 'Failed to retrieve scan item counts: ' . $t->getMessage();
			$this->db->update_scan(
				$scan->id,
				array(
					'finalization_error' => $err_msg,
				),
				$worker_token
			);
			if ( $worker_token ) {
				$this->db->release_worker_lease( $scan->id, $worker_token );
			}
			return array(
				'status'  => 'failed',
				'error'   => $err_msg,
				'stage'   => 'counters',
				'scan_id' => $scan->id,
			);
		}

		// Compute total active non-ignored findings for this scan
		global $wpdb;
		$total_scan_findings = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->db->findings_table} f
				INNER JOIN {$this->db->pages_table} p ON p.id = f.page_id
				WHERE p.scan_id = %d AND f.is_ignored = 0 AND f.finding_status != 'resolved'",
				(int) $scan->id
			)
		);
		if ( null === $total_scan_findings || ! empty( $wpdb->last_error ) ) {
			$err_msg = __( 'Failed to calculate scan total findings count: ', 'crawlory-technical-seo-dashboard' ) . ( ! empty( $wpdb->last_error ) ? $wpdb->last_error : 'query returned null' );
			$this->db->update_scan(
				$scan->id,
				array(
					'finalization_error' => $err_msg,
				),
				$worker_token
			);
			if ( $worker_token ) {
				$this->db->release_worker_lease( $scan->id, $worker_token );
			}
			return array(
				'status'  => 'failed',
				'error'   => $err_msg,
				'stage'   => 'counters',
				'scan_id' => $scan->id,
			);
		}

		$now       = Crawlory_Helper::now_utc();
		$upd_final = $this->db->update_scan(
			$scan->id,
			array(
				'status'             => 'completed',
				'scanned_urls'       => $counts['completed'],
				'failed_urls'        => $counts['failed'],
				'pending_urls'       => 0,
				'processing_urls'    => 0,
				'findings_count'     => (int) $total_scan_findings,
				'finished_at'        => $now,
				'finalization_stage' => 'completed',
				'finalization_error' => null,
				'current_step'       => 'completed',
			),
			$worker_token
		);
		if ( false === $upd_final ) {
			$err_msg = __( 'Failed to record scan completion state.', 'crawlory-technical-seo-dashboard' );
			$this->db->update_scan(
				$scan->id,
				array(
					'finalization_error' => $err_msg,
				),
				$worker_token
			);
			if ( $worker_token ) {
				$this->db->release_worker_lease( $scan->id, $worker_token );
			}
			return array(
				'status'  => 'failed',
				'error'   => $err_msg,
				'stage'   => 'counters',
				'scan_id' => $scan->id,
			);
		}

		// Run completion side effects only after confirmed completion persistence
		update_option( 'crawlory_last_scan_id', $scan->id );

		if ( $worker_token ) {
			$this->db->release_worker_lease( $scan->id, $worker_token );
		}
		$this->cleanup_legacy_coordination( $scan->id );

		return $this->get_scan_status( $scan->id );
	}

	/**
	 * Pause the current scan.
	 * Preserves administrator action and cleans up legacy coordination only after the database state transition succeeds.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public function pause_scan( $scan_id ) {
		$res = $this->db->update_scan( $scan_id, array( 'status' => 'paused' ) );
		if ( $res ) {
			$this->cleanup_legacy_coordination( $scan_id );
		}
		return $res;
	}

	/**
	 * Resume a paused scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public function resume_scan( $scan_id ) {
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return false;
		}

		$res = $this->db->update_scan( $scan_id, array( 'status' => 'running' ) );
		if ( ! $res ) {
			return false;
		}

		if ( ! wp_next_scheduled( 'crawlory_process_background_batch', array( $scan_id ) ) ) {
			wp_schedule_single_event( time(), 'crawlory_process_background_batch', array( $scan_id ) );
		}
		$this->spawn_background_worker( $scan_id );

		return $res;
	}

	/**
	 * Cancel the current scan.
	 * Preserves administrator action and cleans up legacy coordination only after the database state transition succeeds.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public function cancel_scan( $scan_id ) {
		$res = $this->db->update_scan( $scan_id, array( 'status' => 'cancelled', 'finished_at' => Crawlory_Helper::now_utc() ) );
		if ( $res ) {
			$this->cleanup_legacy_coordination( $scan_id );
		}
		return $res;
	}
}
