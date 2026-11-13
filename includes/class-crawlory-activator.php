<?php
/**
 * Fired during plugin activation.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Activator {

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		self::check_requirements();

		// Delegate all schema creation and database migrations to the single migration coordinator.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		$migrated = Crawlory_Migrator::migrate();

		if ( ! $migrated ) {
			// Migration or schema creation encountered an error or lock contention.
			// Do not run fallback creation outside the lock; preserve failure state.
			return false;
		}

		self::set_default_options();

		return true;
	}

	/**
	 * Verify environment requirements.
	 */
	private static function check_requirements() {
		global $wp_version;

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( CRAWLORY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Technical SEO Dashboard requires PHP version 7.4 or higher.', 'crawlory-technical-seo-dashboard' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}

		if ( version_compare( $wp_version, '5.8', '<' ) ) {
			deactivate_plugins( CRAWLORY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Crawlory requires WordPress version 5.8 or higher.', 'crawlory-technical-seo-dashboard' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}

		if ( ! extension_loaded( 'dom' ) ) {
			deactivate_plugins( CRAWLORY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Crawlory requires the PHP DOM extension to parse HTML documents.', 'crawlory-technical-seo-dashboard' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}

		if ( ! extension_loaded( 'simplexml' ) ) {
			deactivate_plugins( CRAWLORY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'Crawlory requires the PHP SimpleXML extension to parse XML sitemaps.', 'crawlory-technical-seo-dashboard' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Create or update custom database tables using dbDelta.
	 *
	 * @param string|null $lock_token Optional migration coordinator lock token.
	 */
	public static function create_or_update_tables( $lock_token = null ) {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Table 1: Scans.
		$table_scans = $wpdb->prefix . 'crawlory_scans';
		$sql_scans   = "CREATE TABLE {$table_scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(30) NOT NULL DEFAULT 'pending',
			scan_type varchar(30) NOT NULL DEFAULT 'full',
			target_type varchar(50) DEFAULT NULL,
			total_urls int(10) unsigned NOT NULL DEFAULT '0',
			scanned_urls int(10) unsigned NOT NULL DEFAULT '0',
			failed_urls int(10) unsigned NOT NULL DEFAULT '0',
			findings_count int(10) unsigned NOT NULL DEFAULT '0',
			batch_size int(10) unsigned NOT NULL DEFAULT '20',
			cursor_offset int(10) unsigned NOT NULL DEFAULT '0',
			last_examined_id bigint(20) unsigned NOT NULL DEFAULT '0',
			examined_urls int(10) unsigned NOT NULL DEFAULT '0',
			excluded_urls int(10) unsigned NOT NULL DEFAULT '0',
			discovered_urls int(10) unsigned NOT NULL DEFAULT '0',
			pending_urls int(10) unsigned NOT NULL DEFAULT '0',
			processing_urls int(10) unsigned NOT NULL DEFAULT '0',
			discovery_status varchar(30) NOT NULL DEFAULT 'pending',
			finalization_stage varchar(30) NOT NULL DEFAULT 'none',
			finalization_error text DEFAULT NULL,
			auth_token_hash varchar(64) DEFAULT NULL,
			auth_token_expires datetime DEFAULT NULL,
			target_urls longtext DEFAULT NULL,
			current_step varchar(50) NOT NULL DEFAULT 'crawl',
			worker_token varchar(64) DEFAULT NULL,
			worker_lease_expires datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			finished_at datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY worker_lease (worker_lease_expires),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Table 2: Pages / URLs.
		$table_pages = $wpdb->prefix . 'crawlory_pages';
		$sql_pages   = "CREATE TABLE {$table_pages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT '0',
			post_type varchar(50) NOT NULL DEFAULT 'page',
			post_title text NOT NULL,
			http_status smallint(5) DEFAULT NULL,
			final_url text DEFAULT NULL,
			is_redirect tinyint(1) NOT NULL DEFAULT '0',
			redirect_destination text DEFAULT NULL,
			redirect_hops int(10) unsigned NOT NULL DEFAULT '0',
			content_type varchar(100) DEFAULT NULL,
			scan_status varchar(30) NOT NULL DEFAULT 'pending',
			fetch_error text DEFAULT NULL,
			seo_title text DEFAULT NULL,
			seo_title_length int(10) unsigned NOT NULL DEFAULT '0',
			meta_description text DEFAULT NULL,
			meta_description_length int(10) unsigned NOT NULL DEFAULT '0',
			h1_count int(10) unsigned NOT NULL DEFAULT '0',
			h1_contents longtext DEFAULT NULL,
			canonical_url text DEFAULT NULL,
			canonical_status varchar(50) DEFAULT NULL,
			robots_meta varchar(255) DEFAULT NULL,
			x_robots_tag varchar(255) DEFAULT NULL,
			is_noindex tinyint(1) NOT NULL DEFAULT '0',
			is_robots_blocked tinyint(1) NOT NULL DEFAULT '0',
			robots_blocking_rule varchar(255) DEFAULT NULL,
			in_sitemap tinyint(1) NOT NULL DEFAULT '0',
			has_incomplete_resources tinyint(1) NOT NULL DEFAULT '0',
			scan_id bigint(20) unsigned NOT NULL DEFAULT '0',
			internal_incoming_links_count int(10) unsigned NOT NULL DEFAULT '0',
			broken_internal_links_count int(10) unsigned NOT NULL DEFAULT '0',
			is_orphan tinyint(1) NOT NULL DEFAULT '0',
			image_alt_findings_count int(10) unsigned NOT NULL DEFAULT '0',
			findings_count int(10) unsigned NOT NULL DEFAULT '0',
			normalized_title text DEFAULT NULL,
			normalized_description text DEFAULT NULL,
			title_duplicate_group_id bigint(20) unsigned DEFAULT NULL,
			desc_duplicate_group_id bigint(20) unsigned DEFAULT NULL,
			last_scanned_at datetime DEFAULT NULL,
			last_successful_scanned_at datetime DEFAULT NULL,
			last_failed_scanned_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY post_id (post_id),
			KEY post_type (post_type),
			KEY scan_status (scan_status),
			KEY findings_count (findings_count),
			KEY is_noindex (is_noindex),
			KEY is_orphan (is_orphan),
			KEY scan_id (scan_id),
			KEY last_scanned_at (last_scanned_at)
		) {$charset_collate};";

		// Table 3: Findings.
		$table_findings = $wpdb->prefix . 'crawlory_findings';
		$sql_findings   = "CREATE TABLE {$table_findings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL,
			rule_id smallint(5) unsigned NOT NULL,
			rule_name varchar(100) NOT NULL,
			severity varchar(20) NOT NULL DEFAULT 'warning',
			category varchar(50) NOT NULL DEFAULT 'technical',
			finding_status varchar(20) NOT NULL DEFAULT 'new',
			check_status varchar(30) NOT NULL DEFAULT 'evaluated_successfully',
			evidence_hash char(32) NOT NULL DEFAULT '',
			message text NOT NULL,
			observed_value longtext DEFAULT NULL,
			expected_threshold varchar(100) DEFAULT NULL,
			why_warrants_attention text DEFAULT NULL,
			suggested_correction text DEFAULT NULL,
			how_to_verify text DEFAULT NULL,
			context_data longtext DEFAULT NULL,
			is_ignored tinyint(1) NOT NULL DEFAULT '0',
			ignored_at datetime DEFAULT NULL,
			ignored_by bigint(20) unsigned DEFAULT NULL,
			first_detected_at datetime NOT NULL,
			last_checked_at datetime NOT NULL,
			resolved_at datetime DEFAULT NULL,
			reappeared_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY rule_id (rule_id),
			KEY severity (severity),
			KEY category (category),
			KEY finding_status (finding_status),
			KEY check_status (check_status),
			KEY evidence_hash (evidence_hash),
			KEY is_ignored (is_ignored)
		) {$charset_collate};";

		// Table 4: Duplicate Groups.
		$table_dup = $wpdb->prefix . 'crawlory_duplicate_groups';
		$sql_dup   = "CREATE TABLE {$table_dup} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_type varchar(20) NOT NULL,
			normalized_hash char(32) NOT NULL,
			normalized_value text NOT NULL,
			url_count int(10) unsigned NOT NULL DEFAULT '0',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_hash (group_type, normalized_hash)
		) {$charset_collate};";

		// Table 5: Links.
		$table_links = $wpdb->prefix . 'crawlory_links';
		$sql_links   = "CREATE TABLE {$table_links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_page_id bigint(20) unsigned NOT NULL,
			source_url text NOT NULL,
			target_url text NOT NULL,
			target_url_hash char(32) NOT NULL,
			anchor_text text DEFAULT NULL,
			is_internal tinyint(1) NOT NULL DEFAULT '1',
			http_status smallint(5) DEFAULT NULL,
			status_type varchar(30) NOT NULL DEFAULT 'pending',
			redirect_final_url text DEFAULT NULL,
			redirect_hops int(10) unsigned NOT NULL DEFAULT '0',
			last_checked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_page_id (source_page_id),
			KEY target_url_hash (target_url_hash),
			KEY status_type (status_type),
			KEY is_internal (is_internal)
		) {$charset_collate};";

		// Table 6: Sitemaps.
		$table_sitemaps = $wpdb->prefix . 'crawlory_sitemaps';
		$sql_sitemaps   = "CREATE TABLE {$table_sitemaps} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sitemap_url text NOT NULL,
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			http_status smallint(5) DEFAULT '200',
			discovery_status varchar(30) NOT NULL DEFAULT 'valid',
			last_checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY sitemap_url (sitemap_url(191)),
			KEY discovery_status (discovery_status)
		) {$charset_collate};";

		// Table 7: Durable Scan Items Queue.
		$table_scan_items = $wpdb->prefix . 'crawlory_scan_items';
		$sql_scan_items   = "CREATE TABLE {$table_scan_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT '0',
			post_title text DEFAULT NULL,
			post_type varchar(50) DEFAULT NULL,
			slug varchar(200) DEFAULT NULL,
			item_status varchar(30) NOT NULL DEFAULT 'pending',
			worker_token varchar(64) DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			http_status smallint(5) DEFAULT NULL,
			findings_count int(10) unsigned NOT NULL DEFAULT '0',
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_url_hash (scan_id, url_hash),
			KEY scan_status (scan_id, item_status),
			KEY scan_id (scan_id),
			KEY url_hash (url_hash)
		) {$charset_collate};";

		// Table 8: Persistent Deduplicated Resource Cache.
		$table_resource_cache = $wpdb->prefix . 'crawlory_resource_cache';
		$sql_resource_cache   = "CREATE TABLE {$table_resource_cache} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			resource_type varchar(30) NOT NULL DEFAULT 'link',
			http_status smallint(5) DEFAULT NULL,
			is_broken tinyint(1) NOT NULL DEFAULT '0',
			is_inconclusive tinyint(1) NOT NULL DEFAULT '0',
			error_message text DEFAULT NULL,
			last_checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY resource_url_type (url_hash, resource_type),
			KEY resource_type (resource_type)
		) {$charset_collate};";

		// If scan items table already exists, handle duplicate preservation and consolidation BEFORE dbDelta attempts to alter/add index.
		// For fresh installations where table does not yet exist, dbDelta will create it directly with the unique index.
		$table_scan_items = $wpdb->prefix . 'crawlory_scan_items';
		$existing_table   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_scan_items ) ) );
		if ( $existing_table === $table_scan_items ) {
			self::migrate_scan_items_schema( $lock_token );
		}

		dbDelta( $sql_scans );
		dbDelta( $sql_pages );
		dbDelta( $sql_findings );
		dbDelta( $sql_dup );
		dbDelta( $sql_links );
		dbDelta( $sql_sitemaps );
		dbDelta( $sql_scan_items );
		dbDelta( $sql_resource_cache );

		// Ensure has_incomplete_resources column exists in crawlory_pages even if dbDelta skips column modification.
		$has_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table_pages,
				'has_incomplete_resources'
			)
		);
		if ( 'has_incomplete_resources' !== $has_col ) {
			$col_check = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_pages}` LIKE 'has_incomplete_resources'" );
			if ( empty( $col_check ) ) {
				$wpdb->query( "ALTER TABLE `{$table_pages}` ADD COLUMN `has_incomplete_resources` tinyint(1) NOT NULL DEFAULT '0' AFTER `in_sitemap`" );
			}
		}

		// Ensure scan_id column exists in crawlory_pages even if dbDelta skips column modification.
		$has_scan_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table_pages,
				'scan_id'
			)
		);
		if ( 'scan_id' !== $has_scan_col ) {
			$scan_col_check = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_pages}` LIKE 'scan_id'" );
			if ( empty( $scan_col_check ) ) {
				$wpdb->query( "ALTER TABLE `{$table_pages}` ADD COLUMN `scan_id` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `has_incomplete_resources`, ADD KEY `scan_id` (`scan_id`)" );
			}
		}

		// Run safe migration and reconciliation for existing scans, queue uniqueness, and cache schema.
		self::migrate_scan_items_schema( $lock_token );
		self::migrate_resource_cache_schema();
		self::migrate_interrupted_scans();
	}

	/**
	 * Verify that all required database tables and columns are present.
	 *
	 * @return bool True if schema is complete, false otherwise.
	 */
	public static function verify_schema_ready() {
		global $wpdb;

		$required_tables = array(
			$wpdb->prefix . 'crawlory_scans',
			$wpdb->prefix . 'crawlory_pages',
			$wpdb->prefix . 'crawlory_findings',
			$wpdb->prefix . 'crawlory_duplicate_groups',
			$wpdb->prefix . 'crawlory_links',
			$wpdb->prefix . 'crawlory_sitemaps',
			$wpdb->prefix . 'crawlory_scan_items',
			$wpdb->prefix . 'crawlory_resource_cache',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $required_tables ), '%s' ) );
		$query = $wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$required_tables
		);
		$found = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $found ) || count( $found ) < count( $required_tables ) ) {
			// Fallback check using SHOW TABLES.
			$found = array();
			foreach ( $required_tables as $tbl ) {
				$check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tbl ) ) );
				if ( $check === $tbl ) {
					$found[] = $tbl;
				}
			}
		}

		foreach ( $required_tables as $tbl ) {
			if ( ! in_array( $tbl, $found, true ) ) {
				return false;
			}
		}

		// Verify has_incomplete_resources column in crawlory_pages.
		$table_pages = $wpdb->prefix . 'crawlory_pages';
		$col_check   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table_pages,
				'has_incomplete_resources'
			)
		);
		if ( 'has_incomplete_resources' !== $col_check ) {
			$col_fallback = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_pages}` LIKE 'has_incomplete_resources'" );
			if ( empty( $col_fallback ) ) {
				return false;
			}
		}

		// Verify scan_id column in crawlory_pages.
		$scan_col_check = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				$table_pages,
				'scan_id'
			)
		);
		if ( 'scan_id' !== $scan_col_check ) {
			$scan_col_fallback = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_pages}` LIKE 'scan_id'" );
			if ( empty( $scan_col_fallback ) ) {
				return false;
			}
		}

		// Verify scan_url_hash unique constraint on crawlory_scan_items.
		$table_scan_items  = $wpdb->prefix . 'crawlory_scan_items';
		$scan_indices      = $wpdb->get_results( "SHOW INDEX FROM `{$table_scan_items}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_uniq_scan_url = false;
		if ( ! empty( $scan_indices ) ) {
			$indexed_cols = array();
			foreach ( $scan_indices as $idx ) {
				if ( 0 === (int) $idx['Non_unique'] ) {
					$key_name = $idx['Key_name'];
					if ( ! isset( $indexed_cols[ $key_name ] ) ) {
						$indexed_cols[ $key_name ] = array();
					}
					$indexed_cols[ $key_name ][] = $idx['Column_name'];
				}
			}
			foreach ( $indexed_cols as $key => $cols ) {
				if ( count( $cols ) === 2 && in_array( 'scan_id', $cols, true ) && in_array( 'url_hash', $cols, true ) ) {
					$has_uniq_scan_url = true;
					break;
				}
			}
		}
		if ( ! $has_uniq_scan_url ) {
			return false;
		}

		return true;
	}

	/**
	 * Durably preserve an immutable, complete snapshot of a queue row into the preservation table.
	 *
	 * Checks JSON serialization and database write results. Re-verifies record presence.
	 * Safe to retry without duplicating archive records via unique index on original_item_id.
	 * Uses NULL-aware insertion preserving SQL NULL for nullable columns without overwriting
	 * existing original snapshot raw payloads.
	 *
	 * @param object $row Row object to preserve.
	 * @param int    $canonical_id Selected canonical row ID for this duplicate group.
	 * @param string $table_preserved Table name of preservation archive.
	 * @throws Exception If serialization, insertion, lookup, or verification fails.
	 */
	private static function preserve_queue_row( $row, $canonical_id, $table_preserved ) {
		global $wpdb;

		if ( empty( $row ) || empty( $row->id ) ) {
			throw new Exception( __( 'Cannot preserve invalid or empty queue row.', 'crawlory-technical-seo-dashboard' ) );
		}

		// 1. Check archive lookup errors immediately.
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, original_item_id, scan_id, url_hash, raw_payload FROM `{$table_preserved}` WHERE original_item_id = %d LIMIT 1",
				(int) $row->id
			)
		);
		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception(
				sprintf(
					/* translators: 1: row ID, 2: database error */
					__( 'Database lookup failed checking archive for queue row #%1$d: %2$s', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id,
					$wpdb->last_error
				)
			);
		}

		// Verify an existing archive record is valid before treating it as successful preservation.
		// Make retries preserve the first original snapshot.
		if ( $existing_row ) {
			if ( (int) $existing_row->original_item_id === (int) $row->id
				&& (int) $existing_row->scan_id === (int) $row->scan_id
				&& (string) $existing_row->url_hash === (string) $row->url_hash
				&& ! empty( $existing_row->raw_payload ) ) {

				$decoded = json_decode( $existing_row->raw_payload, true );
				if ( is_array( $decoded ) && isset( $decoded['id'] ) && (int) $decoded['id'] === (int) $row->id ) {
					return; // Validly preserved; keep first original snapshot intact.
				}
			}

			throw new Exception(
				sprintf(
					/* translators: %d: row ID */
					__( 'Corrupt or mismatched archive record found for queue row #%d. Preservation cannot be confirmed.', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id
				)
			);
		}

		// 2. Complete serialization check (keeping original JSON snapshot unchanged).
		$raw_json = wp_json_encode( $row );
		if ( false === $raw_json || json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception(
				sprintf(
					/* translators: 1: row ID, 2: JSON error message */
					__( 'Serialization failed preserving queue row #%1$d: %2$s', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id,
					json_last_error_msg()
				)
			);
		}

		// 3. NULL-aware insertion with explicit SQL NULL expressions for nullable columns.
		// Schema preserves zero (0), empty string (""), and null distinctly:
		// - post_id: bigint NOT NULL DEFAULT 0 -> %d
		// - post_title, post_type, slug: nullable -> NULL if null, %s if string (including "")
		// - worker_token, error_message: nullable -> NULL if null, %s if string (including "")
		// - claimed_at, completed_at: nullable datetime -> NULL if null/empty/'0000-00-00 00:00:00', %s if valid datetime string
		// - http_status: nullable smallint -> NULL if null/'', %d if integer (including 0)
		// - findings_count: int NOT NULL DEFAULT 0 -> %d (including 0)
		$now_utc = Crawlory_Helper::now_utc();

		$fields = array(
			'original_item_id'  => array( 'format' => '%d', 'value' => (int) $row->id ),
			'canonical_item_id' => array( 'format' => '%d', 'value' => (int) $canonical_id ),
			'scan_id'           => array( 'format' => '%d', 'value' => (int) $row->scan_id ),
			'url'               => array( 'format' => '%s', 'value' => (string) $row->url ),
			'url_hash'          => array( 'format' => '%s', 'value' => (string) $row->url_hash ),
			'post_id'           => array( 'format' => '%d', 'value' => ( null !== $row->post_id && '' !== $row->post_id ) ? (int) $row->post_id : 0 ),
			'post_title'        => ( null === $row->post_title ) ? null : array( 'format' => '%s', 'value' => (string) $row->post_title ),
			'post_type'         => ( null === $row->post_type ) ? null : array( 'format' => '%s', 'value' => (string) $row->post_type ),
			'slug'              => ( null === $row->slug ) ? null : array( 'format' => '%s', 'value' => (string) $row->slug ),
			'item_status'       => array( 'format' => '%s', 'value' => (string) $row->item_status ),
			'worker_token'      => ( null === $row->worker_token ) ? null : array( 'format' => '%s', 'value' => (string) $row->worker_token ),
			'claimed_at'        => ( empty( $row->claimed_at ) || '0000-00-00 00:00:00' === $row->claimed_at ) ? null : array( 'format' => '%s', 'value' => (string) $row->claimed_at ),
			'completed_at'      => ( empty( $row->completed_at ) || '0000-00-00 00:00:00' === $row->completed_at ) ? null : array( 'format' => '%s', 'value' => (string) $row->completed_at ),
			'http_status'       => ( null === $row->http_status || '' === $row->http_status ) ? null : array( 'format' => '%d', 'value' => (int) $row->http_status ),
			'findings_count'    => array( 'format' => '%d', 'value' => ( null !== $row->findings_count && '' !== $row->findings_count ) ? (int) $row->findings_count : 0 ),
			'error_message'     => ( null === $row->error_message ) ? null : array( 'format' => '%s', 'value' => (string) $row->error_message ),
			'created_at'        => array( 'format' => '%s', 'value' => (string) $row->created_at ),
			'preserved_at'      => array( 'format' => '%s', 'value' => $now_utc ),
			'raw_payload'       => array( 'format' => '%s', 'value' => $raw_json ),
		);

		// Schema allowlist for preservation table columns.
		$schema_allowlist = array(
			'original_item_id'  => true,
			'canonical_item_id' => true,
			'scan_id'           => true,
			'url'               => true,
			'url_hash'          => true,
			'post_id'           => true,
			'post_title'        => true,
			'post_type'         => true,
			'slug'              => true,
			'item_status'       => true,
			'worker_token'      => true,
			'claimed_at'        => true,
			'completed_at'      => true,
			'http_status'       => true,
			'findings_count'    => true,
			'error_message'     => true,
			'created_at'        => true,
			'preserved_at'      => true,
			'raw_payload'       => true,
		);

		$col_names    = array();
		$placeholders = array();
		$params       = array();

		foreach ( $fields as $col => $spec ) {
			if ( ! isset( $schema_allowlist[ $col ] ) ) {
				continue;
			}
			$col_names[] = "`{$col}`";
			if ( null === $spec ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = $spec['format'];
				$params[]       = $spec['value'];
			}
		}

		$cols_sql = implode( ', ', $col_names );
		$vals_sql = implode( ', ', $placeholders );

		// Do not overwrite raw_payload through ON DUPLICATE KEY UPDATE: preserve first original snapshot.
		$insert_sql = "INSERT INTO `{$table_preserved}` ({$cols_sql}) VALUES ({$vals_sql}) ON DUPLICATE KEY UPDATE canonical_item_id = VALUES(canonical_item_id)";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Schema-allowlisted columns, trusted table identifier, and parameterized values with explicit SQL NULLs.
		$ins_res = $wpdb->query( $wpdb->prepare( $insert_sql, $params ) );

		if ( false === $ins_res || ! empty( $wpdb->last_error ) ) {
			throw new Exception(
				sprintf(
					/* translators: 1: row ID, 2: error message */
					__( 'Database write failed preserving queue row #%1$d: %2$s', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id,
					$wpdb->last_error ? $wpdb->last_error : 'Insert failed'
				)
			);
		}

		// 4. Re-confirm durable presence and validity in preservation table.
		$confirm_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, original_item_id, scan_id, url_hash, raw_payload FROM `{$table_preserved}` WHERE original_item_id = %d LIMIT 1",
				(int) $row->id
			)
		);
		if ( ! empty( $wpdb->last_error ) ) {
			throw new Exception(
				sprintf(
					/* translators: 1: row ID, 2: database error */
					__( 'Database error verifying preservation of queue row #%1$d: %2$s', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id,
					$wpdb->last_error
				)
			);
		}
		if ( empty( $confirm_row ) || (int) $confirm_row->original_item_id !== (int) $row->id || empty( $confirm_row->raw_payload ) ) {
			throw new Exception(
				sprintf(
					/* translators: %d: row ID */
					__( 'Preservation verification failed for queue row #%d: record missing or incomplete after write.', 'crawlory-technical-seo-dashboard' ),
					(int) $row->id
				)
			);
		}
	}

	/**
	 * Safe migration for scan items queue table uniqueness constraint.
	 *
	 * Enforces one queue item per (scan_id, url_hash) atomically with lossless, recoverable
	 * duplicate row preservation into crawlory_scan_items_preserved prior to consolidation.
	 *
	 * Preserves immutable complete snapshots of all affected rows (including canonical row)
	 * before modifying or deleting anything. Keeps canonical audit outcome internally consistent
	 * without borrowing outcome fields. Protects destructive writes with database-level ownership
	 * fencing and handles oversized groups in bounded chunks.
	 *
	 * @param string|null $lock_token Required migration coordinator lock token for destructive work.
	 * @throws Exception On any database failure, loss of lock ownership, or unverified step.
	 */
	public static function migrate_scan_items_schema( $lock_token = null ) {
		global $wpdb;

		$table_scan_items = $wpdb->prefix . 'crawlory_scan_items';
		$table_preserved  = $wpdb->prefix . 'crawlory_scan_items_preserved';

		// Check if table exists before inspecting indices.
		$table_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_scan_items ) ) );
		if ( $table_check !== $table_scan_items ) {
			return;
		}

		// Inspect existing indices to determine if unique constraint already exists on (scan_id, url_hash).
		$indices = $wpdb->get_results( "SHOW INDEX FROM `{$table_scan_items}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $indices || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to inspect scan items table indexes: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		$has_unique_scan_url = false;
		if ( ! empty( $indices ) ) {
			$indexed_cols = array();
			foreach ( $indices as $idx ) {
				if ( 0 === (int) $idx['Non_unique'] ) {
					$key_name = $idx['Key_name'];
					if ( ! isset( $indexed_cols[ $key_name ] ) ) {
						$indexed_cols[ $key_name ] = array();
					}
					$indexed_cols[ $key_name ][] = $idx['Column_name'];
				}
			}
			foreach ( $indexed_cols as $key => $cols ) {
				if ( count( $cols ) === 2 && in_array( 'scan_id', $cols, true ) && in_array( 'url_hash', $cols, true ) ) {
					$has_unique_scan_url = true;
					break;
				}
			}
		}

		if ( $has_unique_scan_url ) {
			return;
		}

		// Require the token acquired by the current migration caller. Do not adopt another worker's token by reading shared lock.
		if ( empty( $lock_token ) || ! is_string( $lock_token ) ) {
			throw new Exception( __( 'Queue schema migration aborted: valid coordinator lock token is required for destructive consolidation.', 'crawlory-technical-seo-dashboard' ) );
		}

		// 1. Ensure durable preservation table exists prior to transactional consolidation, with unique original_item_id.
		$charset_collate = $wpdb->get_charset_collate();
		$sql_preserved   = "CREATE TABLE IF NOT EXISTS `{$table_preserved}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			original_item_id bigint(20) unsigned NOT NULL,
			canonical_item_id bigint(20) unsigned NOT NULL DEFAULT '0',
			scan_id bigint(20) unsigned NOT NULL,
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT '0',
			post_title text DEFAULT NULL,
			post_type varchar(50) DEFAULT NULL,
			slug varchar(200) DEFAULT NULL,
			item_status varchar(30) NOT NULL DEFAULT 'pending',
			worker_token varchar(64) DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			http_status smallint(5) DEFAULT NULL,
			findings_count int(10) unsigned NOT NULL DEFAULT '0',
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			preserved_at datetime NOT NULL,
			raw_payload longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY original_item_id (original_item_id),
			KEY canonical_item_id (canonical_item_id),
			KEY scan_id (scan_id),
			KEY url_hash (url_hash)
		) {$charset_collate};";

		$create_res = $wpdb->query( $sql_preserved ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $create_res || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to create scan items preservation table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		$check_preserved = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_preserved ) ) );
		if ( $check_preserved !== $table_preserved ) {
			throw new Exception( __( 'Preservation table verification failed: table could not be created.', 'crawlory-technical-seo-dashboard' ) );
		}

		// Ensure original_item_id is UNIQUE on preservation table to make retries strictly idempotent without duplicates.
		// Check SHOW INDEX errors immediately and verify the actual unique original_item_id definition, not just index name.
		$pres_indices = $wpdb->get_results( "SHOW INDEX FROM `{$table_preserved}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $pres_indices || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to inspect preservation table indexes: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		$has_unique_original_item_id = false;
		$has_nonuniq_orig            = false;
		if ( ! empty( $pres_indices ) ) {
			$indexed_by_key = array();
			foreach ( $pres_indices as $idx ) {
				$kname = $idx['Key_name'];
				if ( ! isset( $indexed_by_key[ $kname ] ) ) {
					$indexed_by_key[ $kname ] = array(
						'non_unique' => (int) $idx['Non_unique'],
						'cols'       => array(),
					);
				}
				$indexed_by_key[ $kname ]['cols'][ (int) $idx['Seq_in_index'] ] = $idx['Column_name'];
			}

			foreach ( $indexed_by_key as $kname => $info ) {
				if ( 0 === $info['non_unique'] && count( $info['cols'] ) === 1 && in_array( 'original_item_id', $info['cols'], true ) ) {
					$has_unique_original_item_id = true;
					break;
				} elseif ( 1 === $info['non_unique'] && 'original_item_id' === $kname ) {
					$has_nonuniq_orig = true;
				}
			}
		}

		if ( ! $has_unique_original_item_id ) {
			// Check if existing archive duplicates exist in table_preserved that would prevent adding a unique index.
			$archive_dupes = $wpdb->get_results(
				"SELECT original_item_id, COUNT(*) as cnt
				FROM `{$table_preserved}`
				GROUP BY original_item_id
				HAVING cnt > 1"
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( false === $archive_dupes || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to check preservation table for duplicate records: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			if ( ! empty( $archive_dupes ) ) {
				// Leave all existing preservation records untouched, stop before consolidation or deletion,
				// and ensure repeated migration attempts do not create additional records.
				throw new Exception(
					sprintf(
						/* translators: 1: duplicate group count, 2: preservation table name */
						__( 'Cannot create unique index on preservation table: %1$d duplicate original_item_id groups exist in "%2$s". Existing preservation records have been left untouched. Please resolve duplicate archive records in "%2$s" before migration can proceed.', 'crawlory-technical-seo-dashboard' ),
						count( $archive_dupes ),
						$table_preserved
					)
				);
			}

			// Drop non-unique key if present, checking ALTER TABLE errors immediately.
			if ( $has_nonuniq_orig ) {
				$drop_res = $wpdb->query( "ALTER TABLE `{$table_preserved}` DROP KEY `original_item_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $drop_res || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to drop non-unique original_item_id index on preservation table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
			}

			// Add unique index, checking ALTER TABLE errors immediately.
			$alter_res = $wpdb->query( "ALTER TABLE `{$table_preserved}` ADD UNIQUE KEY `original_item_id` (`original_item_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $alter_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to add unique original_item_id index to preservation table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			// Re-verify actual unique original_item_id definition after upgrade.
			$post_pres_indices = $wpdb->get_results( "SHOW INDEX FROM `{$table_preserved}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $post_pres_indices || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to re-inspect preservation table indexes: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			$has_unique_original_item_id = false;
			if ( ! empty( $post_pres_indices ) ) {
				$post_indexed_by_key = array();
				foreach ( $post_pres_indices as $idx ) {
					$kname = $idx['Key_name'];
					if ( ! isset( $post_indexed_by_key[ $kname ] ) ) {
						$post_indexed_by_key[ $kname ] = array(
							'non_unique' => (int) $idx['Non_unique'],
							'cols'       => array(),
						);
					}
					$post_indexed_by_key[ $kname ]['cols'][ (int) $idx['Seq_in_index'] ] = $idx['Column_name'];
				}

				foreach ( $post_indexed_by_key as $kname => $info ) {
					if ( 0 === $info['non_unique'] && count( $info['cols'] ) === 1 && in_array( 'original_item_id', $info['cols'], true ) ) {
						$has_unique_original_item_id = true;
						break;
					}
				}
			}

			// Stop before consolidation if uniqueness cannot be confirmed.
			if ( ! $has_unique_original_item_id ) {
				throw new Exception( __( 'Verification failed: unique original_item_id index could not be confirmed on preservation table. Consolidation stopped.', 'crawlory-technical-seo-dashboard' ) );
			}
		}

		// 2. Verify confirmed transaction and locking capability across all participating tables.
		// Include $wpdb->options because SELECT ... FOR UPDATE on that table protects ownership.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-db.php';
		$crawlory_db          = new Crawlory_DB();
		$participating_tables = array( $table_scan_items, $table_preserved, $wpdb->options );
		$supports_tx          = $crawlory_db->supports_transactions( $participating_tables );

		if ( null === $supports_tx ) {
			throw new Exception(
				sprintf(
					/* translators: %s: list of tables */
					__( 'Cannot safely consolidate duplicate queue items: transaction and row-locking support could not be determined for participating tables (%s). Processing aborted to prevent data loss.', 'crawlory-technical-seo-dashboard' ),
					implode( ', ', $participating_tables )
				)
			);
		}

		if ( true !== $supports_tx ) {
			throw new Exception(
				sprintf(
					/* translators: %s: list of tables */
					__( 'Cannot safely consolidate duplicate queue items: participating tables (%s) do not support confirmed transactions and row-level locking (InnoDB/NDB required). Automatic alteration of WordPress core tables is not permitted. Please convert non-transactional tables to InnoDB manually.', 'crawlory-technical-seo-dashboard' ),
					implode( ', ', $participating_tables )
				)
			);
		}

		// 3. Process duplicate groups in bounded batches bounded by rows and elapsed time.
		$run_start_time        = microtime( true );
		$max_execution_time    = (int) ini_get( 'max_execution_time' );
		$time_budget           = ( $max_execution_time > 0 ) ? max( 5, min( 15, $max_execution_time - 5 ) ) : 15;
		$max_rows_per_run      = 500;
		$total_rows_processed  = 0;
		$group_batch_size      = 25;
		$chunk_limit           = 50;

		$hit_limits = false;

		while ( true ) {
			// Renew lock lease before each batch to prevent expiration during long work.
			if ( ! Crawlory_Migrator::renew_lock( $lock_token, 120 ) ) {
				throw new Exception( __( 'Processing stopped because migration lock renewal failed or ownership was lost.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Query next batch of duplicate groups.
			$duplicate_groups = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT scan_id, url_hash, COUNT(*) as cnt
					FROM `{$table_scan_items}`
					GROUP BY scan_id, url_hash
					HAVING cnt > 1
					LIMIT %d",
					$group_batch_size
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( false === $duplicate_groups || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to query duplicate queue groups: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			if ( empty( $duplicate_groups ) ) {
				break; // All duplicate groups resolved.
			}

			foreach ( $duplicate_groups as $group ) {
				// Renew ownership before starting group.
				if ( ! Crawlory_Migrator::renew_lock( $lock_token, 120 ) ) {
					throw new Exception( __( 'Processing stopped because migration lock renewal failed or ownership was lost.', 'crawlory-technical-seo-dashboard' ) );
				}

				// Check elapsed time and row bounds.
				if ( ( microtime( true ) - $run_start_time ) > $time_budget || $total_rows_processed >= $max_rows_per_run ) {
					$hit_limits = true;
					break 2;
				}

				// Handle oversized groups in bounded chunks without loading their entire history into memory:
				// 1) Find the single winning canonical row for this group via indexed SQL ordering.
				// Order: completed > failed > processing > pending, then newest completed_at, then highest id.
				// Note: DO NOT sort by findings_count; keep canonical audit outcome internally consistent!
				$canonical = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM `{$table_scan_items}`
						WHERE scan_id = %d AND url_hash = %s
						ORDER BY
						  CASE item_status
						    WHEN 'completed' THEN 4
						    WHEN 'failed' THEN 3
						    WHEN 'processing' THEN 2
						    WHEN 'pending' THEN 1
						    ELSE 0
						  END DESC,
						  completed_at DESC,
						  id DESC
						LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						(int) $group->scan_id,
						$group->url_hash
					)
				);

				if ( ! $canonical || empty( $canonical->id ) ) {
					continue;
				}

				$canonical_id   = (int) $canonical->id;
				$orig_canonical = clone $canonical;
				$conflict_logs  = array();
				$adopted_post   = false;

				// Checked transaction for consolidating this group.
				$tx_res = $wpdb->query( 'START TRANSACTION' );
				if ( false === $tx_res || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to start database transaction for queue consolidation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}

				try {
					// Database-level ownership fencing serialization: lock migration lock option row FOR UPDATE.
					$lock_row = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
							Crawlory_Migrator::LOCK_OPTION
						)
					);
					if ( ! $lock_row ) {
						throw new Exception( __( 'Ownership fence failed: migration lock record missing.', 'crawlory-technical-seo-dashboard' ) );
					}
					$lock_data = maybe_unserialize( $lock_row->option_value );
					if ( ! is_array( $lock_data ) || empty( $lock_data['token'] ) || $lock_data['token'] !== $lock_token ) {
						throw new Exception( __( 'Ownership fence failed: migration lock owned by another process.', 'crawlory-technical-seo-dashboard' ) );
					}
					if ( ! isset( $lock_data['expires'] ) || (int) $lock_data['expires'] <= time() ) {
						throw new Exception( __( 'Ownership fence failed: migration lock has expired.', 'crawlory-technical-seo-dashboard' ) );
					}

					// Preserve immutable original snapshot of canonical row BEFORE any modifications or deletions.
					self::preserve_queue_row( $orig_canonical, $canonical_id, $table_preserved );

					// Process duplicate rows for this group in chunks of $chunk_limit to handle oversized groups safely.
					while ( true ) {
						$dup_chunk = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT * FROM `{$table_scan_items}`
								WHERE scan_id = %d AND url_hash = %s AND id != %d
								ORDER BY id ASC
								LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								(int) $group->scan_id,
								$group->url_hash,
								$canonical_id,
								$chunk_limit
							)
						);

						if ( false === $dup_chunk || ! empty( $wpdb->last_error ) ) {
							throw new Exception( __( 'Failed to inspect duplicate queue chunk: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
						}

						if ( empty( $dup_chunk ) ) {
							break; // No more duplicates for this group.
						}

						$chunk_ids = array();
						foreach ( $dup_chunk as $dup ) {
							$orig_dup    = clone $dup;
							$chunk_ids[] = (int) $dup->id;

							// Durably preserve complete duplicate row snapshot BEFORE deletion.
							self::preserve_queue_row( $orig_dup, $canonical_id, $table_preserved );

							// Adopt missing post identity metadata only if canonical lacks it.
							// NEVER borrow or alter outcome fields (findings_count, http_status, item_status, etc.).
							if ( ( empty( $canonical->post_id ) || 0 === (int) $canonical->post_id ) && ! empty( $orig_dup->post_id ) && (int) $orig_dup->post_id > 0 ) {
								$canonical->post_id    = (int) $orig_dup->post_id;
								$canonical->post_title = $orig_dup->post_title;
								$canonical->post_type  = $orig_dup->post_type;
								$canonical->slug       = $orig_dup->slug;
								$adopted_post          = true;
							}

							// Compare original snapshots when detecting conflicts, before any merge.
							// Preserve intentional zero, empty, and null values distinctly.
							$has_conflict     = false;
							$conflict_details = array();

							// Distinguish findings count comparison with strict intentional zero handling:
							$c_findings = ( null !== $orig_canonical->findings_count && '' !== $orig_canonical->findings_count ) ? (int) $orig_canonical->findings_count : null;
							$d_findings = ( null !== $orig_dup->findings_count && '' !== $orig_dup->findings_count ) ? (int) $orig_dup->findings_count : null;
							if ( $c_findings !== $d_findings ) {
								$has_conflict       = true;
								$conflict_details[] = sprintf( 'findings: %s vs %s', null !== $d_findings ? $d_findings : 'null', null !== $c_findings ? $c_findings : 'null' );
							}

							// Distinguish HTTP status comparison (null, 0, and status codes distinctly):
							$c_http = ( null !== $orig_canonical->http_status && '' !== $orig_canonical->http_status ) ? (int) $orig_canonical->http_status : null;
							$d_http = ( null !== $orig_dup->http_status && '' !== $orig_dup->http_status ) ? (int) $orig_dup->http_status : null;
							if ( $c_http !== $d_http ) {
								$has_conflict       = true;
								$conflict_details[] = sprintf( 'http: %s vs %s', null !== $d_http ? $d_http : 'null', null !== $c_http ? $c_http : 'null' );
							}

							// Distinguish item_status:
							if ( $orig_canonical->item_status !== $orig_dup->item_status ) {
								$has_conflict       = true;
								$conflict_details[] = sprintf( 'status: %s vs %s', $orig_dup->item_status, $orig_canonical->item_status );
							}

							// Distinguish post_id:
							$c_pid = ( null !== $orig_canonical->post_id && '' !== $orig_canonical->post_id ) ? (int) $orig_canonical->post_id : null;
							$d_pid = ( null !== $orig_dup->post_id && '' !== $orig_dup->post_id ) ? (int) $orig_dup->post_id : null;
							if ( $c_pid !== $d_pid ) {
								$has_conflict       = true;
								$conflict_details[] = sprintf( 'post_id: %s vs %s', null !== $d_pid ? $d_pid : 'null', null !== $c_pid ? $c_pid : 'null' );
							}

							// Distinguish error_message:
							$c_err = ( null !== $orig_canonical->error_message && '' !== $orig_canonical->error_message ) ? (string) $orig_canonical->error_message : null;
							$d_err = ( null !== $orig_dup->error_message && '' !== $orig_dup->error_message ) ? (string) $orig_dup->error_message : null;
							if ( $c_err !== $d_err && null !== $d_err ) {
								$has_conflict       = true;
								$conflict_details[] = sprintf( 'err: %s', substr( $d_err, 0, 80 ) );
							}

							if ( $has_conflict ) {
								$conflict_logs[] = sprintf(
									'Row #%d (%s)',
									(int) $dup->id,
									implode( ', ', $conflict_details )
								);
							}
						}

						// Fenced deletion of duplicate rows: database-level ownership fencing requires active unexpired lock.
						$del_placeholders = implode( ', ', array_fill( 0, count( $chunk_ids ), '%d' ) );
						$del_query        = $wpdb->prepare(
							"DELETE t FROM `{$table_scan_items}` t
							INNER JOIN `{$wpdb->options}` lk
							  ON lk.option_name = %s
							  AND lk.option_value LIKE %s
							  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
							WHERE t.id IN ($del_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							array_merge(
								array(
									Crawlory_Migrator::LOCK_OPTION,
									'%' . $wpdb->esc_like( $lock_token ) . '%',
									time(),
								),
								$chunk_ids
							)
						);

						$del_res = $wpdb->query( $del_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						if ( false === $del_res || (int) $del_res !== count( $chunk_ids ) || ! empty( $wpdb->last_error ) ) {
							throw new Exception(
								sprintf(
									/* translators: %s: Error message or reason why fenced deletion failed. */
									__( 'Fenced delete failed for duplicate rows: %s', 'crawlory-technical-seo-dashboard' ),
									$wpdb->last_error ? $wpdb->last_error : 'Ownership lost or row count mismatch'
								)
							);
						}

						$total_rows_processed += count( $chunk_ids );

						// Check bounds within chunk loop:
						if ( ( microtime( true ) - $run_start_time ) > $time_budget || $total_rows_processed >= $max_rows_per_run ) {
							$hit_limits = true;
							break;
						}
					}

					// Update canonical record ONLY if post identity was adopted or conflicts need to be recorded.
					// Outcome fields (findings_count, http_status, item_status) are preserved unmodified!
					$final_error = ! empty( $orig_canonical->error_message ) ? $orig_canonical->error_message : null;
					if ( ! empty( $conflict_logs ) ) {
						$prefix      = ! empty( $final_error ) ? $final_error . ' | ' : '';
						$final_error = $prefix . 'Merged duplicate history: ' . implode( '; ', $conflict_logs );
					}

					if ( $adopted_post || ! empty( $conflict_logs ) ) {
						$expected_post_id    = null !== $canonical->post_id ? (int) $canonical->post_id : 0;
						$expected_post_title = $canonical->post_title;
						$expected_post_type  = $canonical->post_type;
						$expected_slug       = $canonical->slug;
						$expected_error      = $final_error;

						$upd_fenced = $wpdb->query(
							$wpdb->prepare(
								"UPDATE `{$table_scan_items}` t
								INNER JOIN `{$wpdb->options}` lk
								  ON lk.option_name = %s
								  AND lk.option_value LIKE %s
								  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(lk.option_value, 's:7:\"expires\";i:', -1), ';', 1) AS UNSIGNED) > %d
								SET t.post_id = %d,
								    t.post_title = %s,
								    t.post_type = %s,
								    t.slug = %s,
								    t.error_message = %s
								WHERE t.id = %d",
								Crawlory_Migrator::LOCK_OPTION,
								'%' . $wpdb->esc_like( $lock_token ) . '%',
								time(),
								$expected_post_id,
								$expected_post_title,
								$expected_post_type,
								$expected_slug,
								$expected_error,
								$canonical_id
							)
						);

						if ( false === $upd_fenced || ! empty( $wpdb->last_error ) ) {
							throw new Exception( __( 'Fenced update of canonical record failed: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
						}

						// Handle zero affected rows from the fenced canonical UPDATE explicitly:
						// verify ownership and intended persisted values before accepting a no-op.
						if ( 0 === (int) $upd_fenced ) {
							$cur_lock = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
									Crawlory_Migrator::LOCK_OPTION
								)
							);
							if ( ! $cur_lock || empty( $cur_lock->option_value ) ) {
								throw new Exception( __( 'Fenced update produced 0 rows and migration lock row is missing.', 'crawlory-technical-seo-dashboard' ) );
							}
							$cur_lock_data = maybe_unserialize( $cur_lock->option_value );
							if ( ! is_array( $cur_lock_data ) || empty( $cur_lock_data['token'] ) || $cur_lock_data['token'] !== $lock_token ) {
								throw new Exception( __( 'Fenced update produced 0 rows because migration lock ownership was lost.', 'crawlory-technical-seo-dashboard' ) );
							}
							if ( ! isset( $cur_lock_data['expires'] ) || (int) $cur_lock_data['expires'] <= time() ) {
								throw new Exception( __( 'Fenced update produced 0 rows because migration lock has expired.', 'crawlory-technical-seo-dashboard' ) );
							}

							$current_canonical = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT post_id, post_title, post_type, slug, error_message FROM `{$table_scan_items}` WHERE id = %d",
									$canonical_id
								)
							);
							if ( ! $current_canonical ) {
								throw new Exception( __( 'Canonical row missing during zero-affected-rows verification.', 'crawlory-technical-seo-dashboard' ) );
							}

							$post_id_matches = ( (int) $current_canonical->post_id === $expected_post_id );
							$title_matches   = ( (string) $current_canonical->post_title === (string) $expected_post_title );
							$type_matches    = ( (string) $current_canonical->post_type === (string) $expected_post_type );
							$slug_matches    = ( (string) $current_canonical->slug === (string) $expected_slug );
							$error_matches   = ( (string) $current_canonical->error_message === (string) $expected_error );

							if ( ! ( $post_id_matches && $title_matches && $type_matches && $slug_matches && $error_matches ) ) {
								throw new Exception( __( 'Fenced canonical update affected 0 rows and persisted values do not match intended state.', 'crawlory-technical-seo-dashboard' ) );
							}
						}
					}

					// Keep the ownership row locked through commit: re-verify locked ownership row right before commit.
					$commit_lock = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
							Crawlory_Migrator::LOCK_OPTION
						)
					);
					if ( ! $commit_lock || empty( $commit_lock->option_value ) ) {
						throw new Exception( __( 'Ownership fence lost before transaction commit: lock record missing.', 'crawlory-technical-seo-dashboard' ) );
					}
					$commit_lock_data = maybe_unserialize( $commit_lock->option_value );
					if ( ! is_array( $commit_lock_data ) || empty( $commit_lock_data['token'] ) || $commit_lock_data['token'] !== $lock_token ) {
						throw new Exception( __( 'Ownership fence lost before transaction commit: lock owned by another process.', 'crawlory-technical-seo-dashboard' ) );
					}
					if ( ! isset( $commit_lock_data['expires'] ) || (int) $commit_lock_data['expires'] <= time() ) {
						throw new Exception( __( 'Ownership fence lost before transaction commit: lock expired.', 'crawlory-technical-seo-dashboard' ) );
					}

					// Commit transaction for this group.
					$commit_res = $wpdb->query( 'COMMIT' );
					if ( false === $commit_res || ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to commit transaction for duplicate queue consolidation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
				} catch ( Throwable $t ) {
					$rb_res = $wpdb->query( 'ROLLBACK' );
					if ( false === $rb_res || ! empty( $wpdb->last_error ) ) {
						throw new Exception(
							sprintf(
								/* translators: 1: Original failure error message, 2: Database rollback error message. */
								__( 'Queue consolidation failed (%1$s) and rollback also failed (%2$s).', 'crawlory-technical-seo-dashboard' ),
								$t->getMessage(),
								$wpdb->last_error ? $wpdb->last_error : 'Unknown rollback failure'
							),
							0,
							$t
						);
					}
					throw $t;
				}

				if ( $hit_limits ) {
					break 2;
				}
			}
		}

		// 4. Verify duplicate resolution and handle continuation.
		$remaining_dupes = $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT scan_id, url_hash, COUNT(*) as cnt
				FROM `{$table_scan_items}`
				GROUP BY scan_id, url_hash
				HAVING cnt > 1
			) dup_sub"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $remaining_dupes || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to verify duplicate resolution in queue table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		if ( (int) $remaining_dupes > 0 ) {
			if ( $hit_limits ) {
				throw new Exception( __( 'Queue duplicate consolidation reached batch resource limit. Committed progress preserved. Please rerun migration to continue.', 'crawlory-technical-seo-dashboard' ) );
			}
			throw new Exception(
				sprintf(
					/* translators: %d: Number of duplicate queue groups remaining unconsolidated. */
					__( 'Cannot create unique index: %d duplicate queue groups remain unconsolidated.', 'crawlory-technical-seo-dashboard' ),
					(int) $remaining_dupes
				)
			);
		}

		// 5. Guard the index-creation phase against overlapping migration owners.
		if ( ! Crawlory_Migrator::verify_lock_ownership( $lock_token ) ) {
			throw new Exception( __( 'Processing stopped before index creation because worker ownership was lost.', 'crawlory-technical-seo-dashboard' ) );
		}
		if ( ! Crawlory_Migrator::renew_lock( $lock_token, 180 ) ) {
			throw new Exception( __( 'Processing stopped before index creation because migration lock renewal failed.', 'crawlory-technical-seo-dashboard' ) );
		}

		// Check if index already exists.
		$indices = $wpdb->get_results( "SHOW INDEX FROM `{$table_scan_items}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $indices || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to inspect scan items indexes before index creation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		$has_unique_scan_url = false;
		if ( ! empty( $indices ) ) {
			$indexed_cols = array();
			foreach ( $indices as $idx ) {
				if ( 0 === (int) $idx['Non_unique'] ) {
					$key_name = $idx['Key_name'];
					if ( ! isset( $indexed_cols[ $key_name ] ) ) {
						$indexed_cols[ $key_name ] = array();
					}
					$indexed_cols[ $key_name ][] = $idx['Column_name'];
				}
			}
			foreach ( $indexed_cols as $key => $cols ) {
				if ( count( $cols ) === 2 && in_array( 'scan_id', $cols, true ) && in_array( 'url_hash', $cols, true ) ) {
					$has_unique_scan_url = true;
					break;
				}
			}
		}

		if ( ! $has_unique_scan_url ) {
			$alter_res = $wpdb->query( "ALTER TABLE `{$table_scan_items}` ADD UNIQUE KEY `scan_url_hash` (`scan_id`, `url_hash`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $alter_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to add unique index to scan items queue table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			// Re-verify the index is present and unique.
			$post_indices = $wpdb->get_results( "SHOW INDEX FROM `{$table_scan_items}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$confirmed    = false;
			if ( ! empty( $post_indices ) ) {
				$post_indexed_cols = array();
				foreach ( $post_indices as $idx ) {
					if ( 0 === (int) $idx['Non_unique'] ) {
						$key_name = $idx['Key_name'];
						if ( ! isset( $post_indexed_cols[ $key_name ] ) ) {
							$post_indexed_cols[ $key_name ] = array();
						}
						$post_indexed_cols[ $key_name ][] = $idx['Column_name'];
					}
				}
				foreach ( $post_indexed_cols as $key => $cols ) {
					if ( count( $cols ) === 2 && in_array( 'scan_id', $cols, true ) && in_array( 'url_hash', $cols, true ) ) {
						$confirmed = true;
						break;
					}
				}
			}

			if ( ! $confirmed ) {
				throw new Exception( __( 'Verification failed: unique index scan_url_hash was not confirmed on scan items table.', 'crawlory-technical-seo-dashboard' ) );
			}

			// Re-verify lock ownership after index creation to guard against overlapping migration owners.
			if ( ! Crawlory_Migrator::verify_lock_ownership( $lock_token ) ) {
				throw new Exception( __( 'Unique index was created, but worker ownership was lost during the operation. Migration cannot be marked complete.', 'crawlory-technical-seo-dashboard' ) );
			}
		}
	}

	/**
	 * Safe migration for resource cache table and malformed records.
	 * Drops legacy single url_hash unique index if present and removes corrupted records.
	 */
	public static function migrate_resource_cache_schema() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'crawlory_resource_cache' );

		// Invalidate / remove malformed cache records where http_status is 0 or negative or error_message indicates array serialization defect.
		$wpdb->query( "DELETE FROM {$table} WHERE http_status IS NULL OR http_status <= 0 OR error_message LIKE 'Array%' OR error_message LIKE 'a:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Check if old UNIQUE KEY url_hash exists without resource_type compound.
		$indices = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $indices ) ) {
			$has_old_unique = false;
			foreach ( $indices as $idx ) {
				if ( 'url_hash' === $idx['Key_name'] && 0 === (int) $idx['Non_unique'] ) {
					$has_old_unique = true;
					break;
				}
			}
			if ( $has_old_unique ) {
				$wpdb->query( "ALTER TABLE {$table} DROP INDEX url_hash" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY resource_url_type (url_hash, resource_type)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	/**
	 * Safe migration for existing interrupted scans from legacy versions.
	 * Detects scans left in 'running' state with expired leases and safely marks them
	 * as 'interrupted' rather than falsely complete or abandoned.
	 */
	public static function migrate_interrupted_scans() {
		global $wpdb;
		$table_scans = esc_sql( $wpdb->prefix . 'crawlory_scans' );

		// Query table safely.
		$now_utc = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name constructed from esc_sql( $wpdb->prefix . 'crawlory_scans' ).
				"UPDATE {$table_scans}
				SET status = 'interrupted',
					error_message = %s
				WHERE status = 'running'
					AND (worker_lease_expires IS NULL OR worker_lease_expires < %s)",
				__( 'Scan was interrupted during a previous session or plugin update. Click "Resume" to continue or run a fresh scan.', 'crawlory-technical-seo-dashboard' ),
				$now_utc
			)
		);
	}

	/**
	 * Set default options if not configured and no legacy settings exist to migrate.
	 */
	public static function set_default_options() {
		$existing = get_option( 'crawlory_settings', null );
		$legacy   = get_option( 'tsd_settings', null );
		if ( null === $existing && null === $legacy ) {
			require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-settings.php';
			update_option( 'crawlory_settings', Crawlory_Settings::get_default_settings() );
		}
	}
}
