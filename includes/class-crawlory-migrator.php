<?php
/**
 * Safe, Failure-Tolerant, Atomic Database & Configuration Migrator for Crawlory.
 *
 * Handles coordinated atomic renaming of legacy tsd_* database tables to crawlory_*,
 * invokes schema verification and updates (including has_incomplete_resources),
 * copies over legacy settings and options, unschedules legacy cron and continuation events
 * across all scan-ID argument sets, and manages atomic lock ownership tokens.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Migrator {

	/**
	 * Dedicated migration schema version.
	 */
	const MIGRATION_VERSION = '1.3.11';

	/**
	 * Option key recording completed migration version.
	 */
	const COMPLETED_OPTION = 'crawlory_migration_completed';

	/**
	 * Option key storing atomic lock state.
	 */
	const LOCK_OPTION = 'crawlory_migration_lock';

	/**
	 * Option key storing administrative error notice.
	 */
	const ERROR_OPTION = 'crawlory_migration_error';

	/**
	 * Transient key indicating migration is active to prevent scanner execution on partial data.
	 */
	const BARRIER_TRANSIENT = 'crawlory_migration_barrier';

	/**
	 * Process-level memoization of schema verification.
	 *
	 * @var bool|null
	 */
	private static $schema_verified = null;

	/**
	 * Invalidate memoized schema readiness.
	 */
	public static function invalidate_schema_readiness() {
		self::$schema_verified = null;
	}

	/**
	 * Check if database schema is verified with process-level memoization.
	 *
	 * Avoids full information_schema queries on every scanned URL and ordinary request once verified.
	 *
	 * @return bool True if schema is complete, false otherwise.
	 */
	public static function is_schema_verified() {
		if ( null !== self::$schema_verified ) {
			return self::$schema_verified;
		}

		if ( get_option( self::ERROR_OPTION ) ) {
			return false;
		}

		$installed_db_ver = get_option( 'crawlory_db_version', '' );
		$completed_ver    = get_option( self::COMPLETED_OPTION, '' );

		if ( $installed_db_ver === CRAWLORY_DB_VERSION && $completed_ver === self::MIGRATION_VERSION ) {
			require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-activator.php';
			if ( Crawlory_Activator::verify_schema_ready() ) {
				self::$schema_verified = true;
				return true;
			}
			return false;
		}

		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-activator.php';
		$ready = Crawlory_Activator::verify_schema_ready();
		if ( $ready && ! get_option( self::ERROR_OPTION ) ) {
			self::$schema_verified = true;
			return true;
		}

		return false;
	}

	/**
	 * Check whether migration and schema updates are already completely finished.
	 *
	 * Does NOT inspect lock ownership or barriers so an active lock holder re-checking
	 * completion post-acquisition will correctly determine if another worker finished it.
	 *
	 * @return bool True if migration is complete; false otherwise.
	 */
	public static function is_migration_completed() {
		$completed = get_option( self::COMPLETED_OPTION, '' );
		if ( version_compare( $completed, self::MIGRATION_VERSION, '<' ) ) {
			return false;
		}

		$db_ver = get_option( 'crawlory_db_version', '' );
		if ( version_compare( $db_ver, CRAWLORY_DB_VERSION, '<' ) ) {
			return false;
		}

		if ( get_option( self::ERROR_OPTION ) ) {
			return false;
		}

		return self::is_schema_verified();
	}

	/**
	 * Check if database migration and schema are fully ready for scanning.
	 *
	 * Persistent readiness check: requires migration completion AND no active migration barrier
	 * or unexpired lock held by another process.
	 *
	 * @return bool True if migration is complete and scanning is currently permitted; false otherwise.
	 */
	public static function is_ready() {
		// 1. Migration must be fully completed.
		if ( ! self::is_migration_completed() ) {
			return false;
		}

		// 2. Authoritative check on active migration lock.
		$lock = get_option( self::LOCK_OPTION, null );
		if ( is_array( $lock ) && ! empty( $lock['token'] ) && isset( $lock['expires'] ) && (int) $lock['expires'] > time() ) {
			return false;
		}

		// 3. Owner-specific cache barrier check:
		if ( is_array( $lock ) && ! empty( $lock['token'] ) ) {
			if ( get_transient( self::BARRIER_TRANSIENT . '_' . $lock['token'] ) ) {
				return false;
			}
		}

		// 4. Token-conditioned database barrier check:
		global $wpdb;
		$timeout = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'_transient_timeout_' . self::BARRIER_TRANSIENT
			)
		);
		if ( $timeout && (int) $timeout > time() ) {
			return false;
		}

		return true;
	}

	/**
	 * Attempt to acquire an atomic migration lock with an owner token.
	 *
	 * Uses add_option() for atomic insert, and Compare-and-Swap (CAS) UPDATE
	 * on wp_options for expired locks. Flushes option caches to maintain cache consistency.
	 *
	 * @param string $lock_token Generated owner UUID token.
	 * @param int    $ttl Lock time-to-live in seconds.
	 * @return bool True if lock was acquired, false if held by another active process.
	 */
	private static function acquire_lock( $lock_token, $ttl = 120 ) {
		global $wpdb;

		$now     = time();
		$expires = $now + $ttl;
		$payload = array(
			'token'   => $lock_token,
			'expires' => $expires,
		);

		// 1. Try atomic insert with add_option (only succeeds if option doesn't exist).
		if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return true;
		}

		// 2. Option already exists. Check if current lock is expired.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);

		if ( ! $row ) {
			// Option might have been removed right between add_option and get_row. Retry add_option once.
			$added = (bool) add_option( self::LOCK_OPTION, $payload, '', 'no' );
			if ( $added ) {
				wp_cache_delete( self::LOCK_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
			return $added;
		}

		$current_data = maybe_unserialize( $row->option_value );
		$current_exp  = ( is_array( $current_data ) && isset( $current_data['expires'] ) ) ? (int) $current_data['expires'] : 0;

		if ( $current_exp > $now ) {
			// Lock is active and unexpired under another process.
			return false;
		}

		// 3. Lock is expired. Use atomic Compare-and-Swap (CAS) to take ownership.
		$serialized_payload = maybe_serialize( $payload );
		$rows_affected      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s
				WHERE option_name = %s
				  AND option_value = %s",
				$serialized_payload,
				self::LOCK_OPTION,
				$row->option_value
			)
		);

		if ( 1 === (int) $rows_affected ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return true;
		}

		return false;
	}


	/**
	 * Authoritatively verify that this process still owns the active migration lock.
	 * Ensures raw SQL changes remain consistent with WordPress option caches.
	 *
	 * @param string $lock_token Owner token to verify.
	 * @return bool True if owned and unexpired, false otherwise.
	 */
	public static function verify_lock_ownership( $lock_token ) {
		global $wpdb;

		if ( empty( $lock_token ) ) {
			return false;
		}

		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);
		if ( ! $row ) {
			return false;
		}

		$lock_data = maybe_unserialize( $row->option_value );
		if ( ! is_array( $lock_data ) || empty( $lock_data['token'] ) ) {
			return false;
		}

		if ( $lock_data['token'] !== $lock_token ) {
			return false;
		}

		$expires = isset( $lock_data['expires'] ) ? (int) $lock_data['expires'] : 0;
		return ( $expires > time() );
	}

	/**
	 * Renew lock lease during long migrations to prevent premature expiry.
	 *
	 * Atomically verifies ownership and requires an unexpired existing lease.
	 * Zero affected rows triggers authoritative verification rather than automatic success or failure.
	 *
	 * @param string $lock_token Owner token.
	 * @param int    $ttl Extension time-to-live in seconds.
	 * @return bool True if lease was extended and verified, false if ownership was lost or expired.
	 */
	public static function renew_lock( $lock_token, $ttl = 120 ) {
		global $wpdb;

		if ( empty( $lock_token ) ) {
			return false;
		}

		$now = time();

		// 1. Read existing option row to verify current ownership and active validity.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);

		if ( ! $row ) {
			return false;
		}

		$current_data = maybe_unserialize( $row->option_value );
		if ( ! is_array( $current_data ) || empty( $current_data['token'] ) || $current_data['token'] !== $lock_token ) {
			// Lost ownership to another worker.
			return false;
		}

		$current_exp = isset( $current_data['expires'] ) ? (int) $current_data['expires'] : 0;
		if ( $current_exp <= $now ) {
			// Existing lease has already expired. Reject renewal.
			return false;
		}

		// 2. Perform atomic Compare-and-Swap (CAS) update requiring the exact existing unexpired option_value.
		$new_expires = $now + $ttl;
		$payload     = array(
			'token'   => $lock_token,
			'expires' => $new_expires,
		);
		$serialized  = maybe_serialize( $payload );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s
				WHERE option_name = %s
				  AND option_value = %s",
				$serialized,
				self::LOCK_OPTION,
				$row->option_value
			)
		);

		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// If query failed completely with a database error, renewal failed.
		if ( false === $rows ) {
			return false;
		}

		if ( 1 === (int) $rows ) {
			set_transient( self::BARRIER_TRANSIENT . '_' . $lock_token, $lock_token, $ttl );
			$transient_option = '_transient_' . self::BARRIER_TRANSIENT;
			$timeout_option   = '_transient_timeout_' . self::BARRIER_TRANSIENT;
			$esc_token        = '%' . $wpdb->esc_like( $lock_token ) . '%';
			$res_timeout      = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} t
					INNER JOIN {$wpdb->options} o ON o.option_name = %s AND o.option_value = %s
					INNER JOIN {$wpdb->options} lk ON lk.option_name = %s AND lk.option_value LIKE %s
					  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
					SET t.option_value = %s
					WHERE t.option_name = %s",
					$transient_option,
					$lock_token,
					self::LOCK_OPTION,
					$esc_token,
					$now,
					(string) ( $now + $ttl ),
					$timeout_option
				)
			);
			wp_cache_delete( $timeout_option, 'options' );

			if ( false === $res_timeout ) {
				return false;
			}
			if ( $res_timeout > 0 ) {
				return true;
			}

			// Zero affected rows: verify that current owner, unexpired lock, barrier token, and required timeout are already correct before accepting a no-op.
			return self::verify_barrier_timeout( $lock_token, $now + $ttl - 5 );
		}

		// Zero affected rows (e.g. identical payload/timestamp): authoritatively verify ownership and unexpired lease.
		$verified = self::verify_lock_ownership( $lock_token );
		if ( ! $verified ) {
			return false;
		}

		set_transient( self::BARRIER_TRANSIENT . '_' . $lock_token, $lock_token, $ttl );
		$transient_option = '_transient_' . self::BARRIER_TRANSIENT;
		$timeout_option   = '_transient_timeout_' . self::BARRIER_TRANSIENT;
		$esc_token        = '%' . $wpdb->esc_like( $lock_token ) . '%';
		$res_timeout      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} t
				INNER JOIN {$wpdb->options} o ON o.option_name = %s AND o.option_value = %s
				INNER JOIN {$wpdb->options} lk ON lk.option_name = %s AND lk.option_value LIKE %s
				  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
				SET t.option_value = %s
				WHERE t.option_name = %s",
				$transient_option,
				$lock_token,
				self::LOCK_OPTION,
				$esc_token,
				$now,
				(string) ( $now + $ttl ),
				$timeout_option
			)
		);
		wp_cache_delete( $timeout_option, 'options' );

		if ( false === $res_timeout ) {
			return false;
		}
		if ( $res_timeout > 0 ) {
			return true;
		}

		// Zero affected rows: verify that current owner, unexpired lock, barrier token, and required timeout are already correct before accepting a no-op.
		return self::verify_barrier_timeout( $lock_token, $now + $ttl - 5 );
	}

	/**
	 * Verify that the barrier and its timeout are authoritatively established for this owner.
	 *
	 * @param string $lock_token Owner lock token.
	 * @param int    $min_timeout Minimum acceptable timeout timestamp.
	 * @return bool
	 */
	private static function verify_barrier_timeout( $lock_token, $min_timeout ) {
		global $wpdb;

		if ( ! self::verify_lock_ownership( $lock_token ) ) {
			return false;
		}

		$transient_option = '_transient_' . self::BARRIER_TRANSIENT;
		$timeout_option   = '_transient_timeout_' . self::BARRIER_TRANSIENT;

		wp_cache_delete( $transient_option, 'options' );
		wp_cache_delete( $timeout_option, 'options' );

		$barrier_val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$transient_option
			)
		);
		$timeout_val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$timeout_option
			)
		);

		if ( $barrier_val !== $lock_token ) {
			return false;
		}

		if ( ! $timeout_val || (int) $timeout_val < $min_timeout ) {
			return false;
		}

		return true;
	}

	/**
	 * Safely release the migration lock and barrier with atomic conditional cleanup matching the current owner token only.
	 * Prevents an expired worker from releasing a newer worker's lock or barrier.
	 *
	 * Uses an atomic multi-table DELETE for database options to atomically remove the barrier and its timeout
	 * together if and only if the owner matches, preventing deletion of a replacement barrier's timeout.
	 * For persistent object caches, uses owner-specific keys so cleanup cannot affect a newer owner.
	 *
	 * @param string $lock_token Owner token.
	 */
	private static function release_lock( $lock_token ) {
		global $wpdb;

		if ( empty( $lock_token ) ) {
			return;
		}

		// 1. Atomic conditional deletion of the migration lock matching the owner token only.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::LOCK_OPTION,
				'%' . $wpdb->esc_like( $lock_token ) . '%'
			)
		);

		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// 2. Database branch: Atomic multi-table deletion of migration barrier AND timeout together
		// only if the barrier's option_value matches this owner's token.
		// If another worker has taken over, 0 rows match and neither the barrier nor replacement timeout is touched.
		$transient_option = '_transient_' . self::BARRIER_TRANSIENT;
		$timeout_option   = '_transient_timeout_' . self::BARRIER_TRANSIENT;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE o, t FROM {$wpdb->options} o
				LEFT JOIN {$wpdb->options} t ON t.option_name = %s
				WHERE o.option_name = %s
				  AND o.option_value = %s",
				$timeout_option,
				$transient_option,
				$lock_token
			)
		);

		wp_cache_delete( $transient_option, 'options' );
		wp_cache_delete( $timeout_option, 'options' );

		// 3. Persistent object cache branch: Delete owner-specific barrier key.
		// Never call wp_cache_delete on a shared key or race-prone read-then-delete.
		delete_transient( self::BARRIER_TRANSIENT . '_' . $lock_token );
	}

	/**
	 * Run the migration if needed.
	 *
	 * Idempotent: checks completed migration version. If already migrated and schema verified, returns true immediately (no-op).
	 * If migration is currently in progress by another worker, returns false (unavailable).
	 *
	 * @return bool True if migration is complete or was already completed; false if in-progress or failed.
	 */
	public static function migrate() {
		global $wpdb;

		// 1. If migration is already complete, immediate no-op.
		if ( self::is_migration_completed() ) {
			return true;
		}

		// Invalidate memoization before attempting migration.
		self::invalidate_schema_readiness();

		// 2. Atomic migration lock with ownership token and expiration.
		$lock_token = wp_generate_uuid4();
		if ( ! self::acquire_lock( $lock_token, 120 ) ) {
			// Migration is currently active under another thread/worker.
			// Treat as unavailable, not successful!
			return false;
		}

		// Recheck migration completion immediately after acquiring the lock.
		// Note: is_migration_completed() checks completion without being blocked by this process's own lock!
		if ( self::is_migration_completed() ) {
			self::release_lock( $lock_token );
			return true;
		}

		$did_migrate_tables = false;

		try {
			// Activate owner-specific cache barrier for object cache environments.
			$ttl = 120;
			set_transient( self::BARRIER_TRANSIENT . '_' . $lock_token, $lock_token, $ttl );

			$transient_option = '_transient_' . self::BARRIER_TRANSIENT;
			$timeout_option   = '_transient_timeout_' . self::BARRIER_TRANSIENT;
			$now_ts           = time();
			$exp_ts           = $now_ts + $ttl;
			$esc_token        = '%' . $wpdb->esc_like( $lock_token ) . '%';

			// Ownership-conditioned database barrier upsert:
			// Couples ownership validation to the mutation atomically; an expired worker cannot create or overwrite.
			$res_barrier = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					SELECT %s, %s, 'no'
					FROM {$wpdb->options} lk
					WHERE lk.option_name = %s
					  AND lk.option_value LIKE %s
					  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
					ON DUPLICATE KEY UPDATE
					  option_value = VALUES(option_value)",
					$transient_option,
					$lock_token,
					self::LOCK_OPTION,
					$esc_token,
					$now_ts
				)
			);

			$res_timeout = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
					SELECT %s, %s, 'no'
					FROM {$wpdb->options} lk
					WHERE lk.option_name = %s
					  AND lk.option_value LIKE %s
					  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
					ON DUPLICATE KEY UPDATE
					  option_value = VALUES(option_value)",
					$timeout_option,
					(string) $exp_ts,
					self::LOCK_OPTION,
					$esc_token,
					$now_ts
				)
			);

			wp_cache_delete( $transient_option, 'options' );
			wp_cache_delete( $timeout_option, 'options' );

			if ( false === $res_barrier || false === $res_timeout ) {
				throw new Exception( __( 'Failed to write migration barrier: database query error.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Check barrier write results and verify active ownership before schema changes.
			$cur_barrier = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$transient_option
				)
			);
			$cur_timeout = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$timeout_option
				)
			);

			if ( $cur_barrier !== $lock_token || (int) $cur_timeout < $exp_ts || ! self::verify_lock_ownership( $lock_token ) ) {
				throw new Exception( __( 'Failed to establish migration barrier: lock ownership was lost or expired.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Verify lock ownership before starting schema mutations.
			if ( ! self::verify_lock_ownership( $lock_token ) ) {
				throw new Exception( __( 'Migration aborted: lost lock ownership.', 'crawlory-technical-seo-dashboard' ) );
			}

			// 3. Preflight and atomically rename tables (if legacy tables exist).
			$did_migrate_tables = self::migrate_tables();

			// Renew lock lease before running schema creation.
			if ( ! self::renew_lock( $lock_token, 120 ) ) {
				throw new Exception( __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ) );
			}

			// 4. Run schema creation/update through activator.
			require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-activator.php';
			Crawlory_Activator::create_or_update_tables( $lock_token );

			// Verify schema is complete before continuing.
			if ( ! Crawlory_Activator::verify_schema_ready() ) {
				throw new Exception( __( 'Database schema verification failed: required tables or columns missing.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Renew lock lease before options and cron.
			if ( ! self::renew_lock( $lock_token, 120 ) ) {
				throw new Exception( __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ) );
			}

			// 5. Migrate options and settings.
			self::migrate_options();

			// 6. Correct scheduled-task migration.
			self::migrate_cron_events();

			// 7. Invalidate legacy workers ONLY during actual table upgrade, never on normal requests.
			if ( $did_migrate_tables ) {
				self::invalidate_active_leases();
			}

			// 8. Reload settings into singleton instance if available.
			self::reload_settings();

			// Verify lock ownership before final commit of completion markers!
			if ( ! self::verify_lock_ownership( $lock_token ) ) {
				throw new Exception( __( 'Processing stopped because worker ownership was lost. Retry when the current operation finishes.', 'crawlory-technical-seo-dashboard' ) );
			}

			// 9. Mark completion: only after all operations succeed.
			update_option( 'crawlory_db_version', CRAWLORY_DB_VERSION );
			$comp_res = update_option( self::COMPLETED_OPTION, self::MIGRATION_VERSION );
			if ( false === $comp_res && get_option( self::COMPLETED_OPTION ) !== self::MIGRATION_VERSION ) {
				throw new Exception( __( 'Failed to record completed migration version marker in database.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Mark schema verified in memory.
			self::$schema_verified = true;

			delete_option( self::ERROR_OPTION );
			self::release_lock( $lock_token );

			return true;
		} catch ( Exception $e ) {
			self::invalidate_schema_readiness();
			// Record diagnostics without allowing an expired worker to overwrite the current worker's state
			if ( self::verify_lock_ownership( $lock_token ) ) {
				update_option( self::ERROR_OPTION, $e->getMessage() );
			}
			self::release_lock( $lock_token );

			return false;
		}
	}

	/**
	 * Preflight and atomically rename legacy database tables.
	 *
	 * Uses coordinated atomic RENAME TABLE when supported so all tables migrate together.
	 * Avoids destructive guessing if both old and new tables coexist.
	 *
	 * @return bool True if legacy tables were found and renamed; false if no legacy tables existed.
	 * @throws Exception When a database error occurs or when old and new tables unresolvably coexist.
	 */
	private static function migrate_tables() {
		global $wpdb;

		$table_pairs = array(
			$wpdb->prefix . 'tsd_scans'            => $wpdb->prefix . 'crawlory_scans',
			$wpdb->prefix . 'tsd_pages'            => $wpdb->prefix . 'crawlory_pages',
			$wpdb->prefix . 'tsd_findings'         => $wpdb->prefix . 'crawlory_findings',
			$wpdb->prefix . 'tsd_duplicate_groups' => $wpdb->prefix . 'crawlory_duplicate_groups',
			$wpdb->prefix . 'tsd_links'            => $wpdb->prefix . 'crawlory_links',
			$wpdb->prefix . 'tsd_sitemaps'         => $wpdb->prefix . 'crawlory_sitemaps',
			$wpdb->prefix . 'tsd_scan_items'       => $wpdb->prefix . 'crawlory_scan_items',
			$wpdb->prefix . 'tsd_resource_cache'   => $wpdb->prefix . 'crawlory_resource_cache',
		);

		// Preflight table existence using exact schema queries (avoids LIKE underscore wildcard matching).
		$all_tables      = array_merge( array_keys( $table_pairs ), array_values( $table_pairs ) );
		$existing_tables = self::get_existing_tables( $all_tables );

		$rename_clauses   = array();
		$old_tables_found = 0;

		foreach ( $table_pairs as $old_table => $new_table ) {
			$old_exists = in_array( $old_table, $existing_tables, true );
			$new_exists = in_array( $new_table, $existing_tables, true );

			if ( ! $old_exists ) {
				// Old table does not exist. Nothing to rename for this pair.
				continue;
			}

			$old_tables_found++;

			if ( $new_exists ) {
				// Both old and new tables exist.
				// Do not make destructive guesses by dropping tables!
				// Throw an explicit exception to preserve all data.
				throw new Exception(
					sprintf(
						/* translators: 1: Old table name, 2: New table name. */
						__( 'Migration stopped: both legacy table "%1$s" and new table "%2$s" exist. Automatic rename aborted to protect data from accidental loss. Please inspect and resolve table names manually.', 'crawlory-technical-seo-dashboard' ),
						esc_html( $old_table ),
						esc_html( $new_table )
					)
				);
			}

			$old_escaped      = esc_sql( $old_table );
			$new_escaped      = esc_sql( $new_table );
			$rename_clauses[] = "`{$old_escaped}` TO `{$new_escaped}`";
		}

		if ( 0 === $old_tables_found ) {
			// No legacy tables to rename.
			return false;
		}

		if ( ! empty( $rename_clauses ) ) {
			// Coordinated atomic rename of all related tables in a single statement.
			$sql    = 'RENAME TABLE ' . implode( ', ', $rename_clauses );
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $result ) {
				throw new Exception(
					sprintf(
						/* translators: %s: Database error string. */
						__( 'Database rename query failed: %s', 'crawlory-technical-seo-dashboard' ),
						$wpdb->last_error ? $wpdb->last_error : 'Unknown database error'
					)
				);
			}
		}

		return true;
	}

	/**
	 * Query exact table existence using information_schema or direct show query.
	 *
	 * @param array $table_names List of table names to verify.
	 * @return array Existing table names.
	 * @throws Exception If database query fails.
	 */
	private static function get_existing_tables( array $table_names ) {
		global $wpdb;

		if ( empty( $table_names ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );
		$query        = $wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$table_names
		);

		$results = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $results || ! empty( $wpdb->last_error ) ) {
			// Query failed! Check SHOW TABLES with error checking.
			$existing = array();
			foreach ( $table_names as $tbl ) {
				$escaped_like = $wpdb->esc_like( $tbl );
				$check        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $escaped_like ) );
				if ( ! empty( $wpdb->last_error ) ) {
					throw new Exception(
						sprintf(
							/* translators: %s: Database error string. */
							__( 'Database error during table discovery: %s', 'crawlory-technical-seo-dashboard' ),
							$wpdb->last_error
						)
					);
				}
				if ( $check === $tbl ) {
					$existing[] = $tbl;
				}
			}
			return $existing;
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Migrate legacy options and settings.
	 *
	 * @throws Exception If an option update fails.
	 */
	private static function migrate_options() {
		// Special handling for crawlory_settings / tsd_settings to merge individual keys properly
		$existing_crawlory_settings = get_option( 'crawlory_settings', null );
		$legacy_tsd_settings        = get_option( 'tsd_settings', null );

		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-settings.php';
		$defaults = Crawlory_Settings::get_default_settings();

		if ( null !== $legacy_tsd_settings || null !== $existing_crawlory_settings ) {
			$merged_settings = is_array( $existing_crawlory_settings ) ? $existing_crawlory_settings : array();
			$legacy_array    = is_array( $legacy_tsd_settings ) ? $legacy_tsd_settings : array();

			// Apply legacy values for missing keys only, preserving intentional false, zero, or empty values
			foreach ( $legacy_array as $k => $v ) {
				if ( ! array_key_exists( $k, $merged_settings ) && array_key_exists( $k, $defaults ) ) {
					$merged_settings[ $k ] = $v;
				}
			}

			// Apply defaults only to remaining missing keys
			foreach ( $defaults as $k => $v ) {
				if ( ! array_key_exists( $k, $merged_settings ) ) {
					$merged_settings[ $k ] = $v;
				}
			}

			update_option( 'crawlory_settings', $merged_settings );
		}

		// Other mapped options
		$scalar_map = array(
			'tsd_db_version'     => 'crawlory_db_version',
			'tsd_last_scan_id'   => 'crawlory_last_scan_id',
			'tsd_active_scan_id' => 'crawlory_active_scan_id',
		);

		foreach ( $scalar_map as $old_opt => $new_opt ) {
			$old_val = get_option( $old_opt, null );
			if ( null !== $old_val ) {
				$new_val = get_option( $new_opt, null );
				if ( null === $new_val ) {
					$res = update_option( $new_opt, $old_val );
					if ( false === $res ) {
						if ( get_option( $new_opt, null ) === null ) {
							throw new Exception(
								sprintf(
									/* translators: %s: Option name. */
									__( 'Failed to migrate option "%s" to database.', 'crawlory-technical-seo-dashboard' ),
									esc_html( $new_opt )
								)
							);
						}
					}
				}
			}
		}

		// Clean up legacy transients.
		delete_transient( 'tsd_scan_lock' );
		delete_transient( 'tsd_reset_barrier' );
	}

	/**
	 * Correct scheduled-task migration.
	 *
	 * Reads 'scan_schedule' (not 'cron_frequency'), preserves existing setting,
	 * registers custom cron intervals before scheduling, checks results,
	 * and removes legacy continuation events across all scan-ID argument sets.
	 *
	 * @throws Exception If scheduling fails.
	 */
	private static function migrate_cron_events() {
		// 1. Remove legacy background worker continuation events across all argument sets.
		if ( function_exists( '_get_cron_array' ) ) {
			$crons = _get_cron_array();
			if ( is_array( $crons ) ) {
				foreach ( $crons as $timestamp => $hooks ) {
					foreach ( array( 'tsd_process_background_batch', 'tsd_scheduled_scan_event' ) as $legacy_hook ) {
						if ( isset( $hooks[ $legacy_hook ] ) && is_array( $hooks[ $legacy_hook ] ) ) {
							foreach ( $hooks[ $legacy_hook ] as $sig => $data ) {
								$args = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
								wp_unschedule_event( $timestamp, $legacy_hook, $args );
							}
						}
					}
				}
			}
		}

		// Also invoke clear hook for any hook signatures without arguments.
		wp_clear_scheduled_hook( 'tsd_process_background_batch' );
		wp_clear_scheduled_hook( 'tsd_scheduled_scan_event' );

		// 2. Read scan_schedule (valid values: 'disabled', 'daily', 'weekly', 'monthly').
		// Preserve scan_schedule from crawlory_settings or legacy tsd_settings.
		$settings = get_option( 'crawlory_settings', null );
		if ( null === $settings ) {
			$settings = get_option( 'tsd_settings', array() );
		}
		$schedule = ! empty( $settings['scan_schedule'] ) ? $settings['scan_schedule'] : 'disabled';

		if ( 'disabled' === $schedule || empty( $schedule ) ) {
			// If disabled, ensure no scheduled event is running.
			$existing_scheduled = wp_next_scheduled( 'crawlory_scheduled_scan_event' );
			if ( $existing_scheduled ) {
				wp_unschedule_event( $existing_scheduled, 'crawlory_scheduled_scan_event' );
			}
			return;
		}

		// Register custom cron intervals (weekly, monthly) so wp_schedule_event does not reject them.
		if ( class_exists( 'Crawlory_Cron' ) ) {
			add_filter(
				'cron_schedules',
				function( $schedules ) {
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
			);
		}

		// Schedule new crawlory event if not already scheduled.
		if ( ! wp_next_scheduled( 'crawlory_scheduled_scan_event' ) ) {
			$sched_res = wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'crawlory_scheduled_scan_event' );
			if ( false === $sched_res ) {
				throw new Exception( __( 'Failed to schedule crawlory_scheduled_scan_event.', 'crawlory-technical-seo-dashboard' ) );
			}
		}
	}

	/**
	 * Invalidate active worker leases and mark in-flight scans as interrupted.
	 *
	 * Only called during actual table/schema upgrade when legacy tables are renamed.
	 *
	 * @throws Exception If database query fails.
	 */
	private static function invalidate_active_leases() {
		global $wpdb;

		$table_scans = esc_sql( $wpdb->prefix . 'crawlory_scans' );
		$existing    = self::get_existing_tables( array( $wpdb->prefix . 'crawlory_scans' ) );
		if ( empty( $existing ) ) {
			return;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_scans}`
				SET status = 'interrupted',
					worker_token = NULL,
					worker_lease_expires = NULL,
					error_message = %s
				WHERE status = 'running'",
				__( 'Scan interrupted by plugin upgrade to Crawlory v1.3.9. Resume or run a fresh scan.', 'crawlory-technical-seo-dashboard' )
			)
		);

		if ( false === $result ) {
			throw new Exception( __( 'Failed to update active worker leases during upgrade.', 'crawlory-technical-seo-dashboard' ) );
		}
	}

	/**
	 * Reload settings into the singleton Crawlory instance if initialized.
	 */
	private static function reload_settings() {
		if ( class_exists( 'Crawlory' ) ) {
			$plugin = Crawlory::get_instance();
			if ( ! empty( $plugin->settings ) && method_exists( $plugin->settings, 'load' ) ) {
				$plugin->settings->load();
			}
		}
	}
}
