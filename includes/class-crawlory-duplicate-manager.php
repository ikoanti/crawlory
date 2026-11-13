<?php
/**
 * Cross-page duplicate title and meta description manager.
 *
 * Implements Rule 14 (Duplicate SEO titles) and Rule 17 (Duplicate meta descriptions).
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Duplicate_Manager {

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
	 * Constructor.
	 *
	 * @param Crawlory_DB       $db Database manager.
	 * @param Crawlory_Settings $settings Settings.
	 */
	public function __construct( Crawlory_DB $db, Crawlory_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	/**
	 * Recalculate duplicate titles and meta descriptions across all successfully scanned pages.
	 * Refresh duplicate groups and findings.
	 *
	 * @return array Counts of duplicate title and description groups.
	 */
	public function recalculate_all_duplicates() {
		global $wpdb;

		$participating_tables = array( $this->db->pages_table, $this->db->findings_table, $this->db->dup_table );
		$supports_tx = $this->db->supports_transactions( $participating_tables );
		if ( true !== $supports_tx ) {
			throw new Exception( __( 'Cannot safely recalculate duplicates: database tables do not support confirmed transactions.', 'crawlory-technical-seo-dashboard' ) );
		}

		$tx_started = $wpdb->query( 'START TRANSACTION' );
		if ( false === $tx_started || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to start transaction for duplicate recalculation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}

		try {
			$title_groups_count = 0;
			$desc_groups_count  = 0;

			// 1. Process Rule 14: Duplicate SEO Titles.
			if ( $this->settings->is_rule_enabled( 14 ) ) {
				$title_groups_count = $this->recalculate_type( 'title', 14, 'Duplicate SEO titles', 'normalized_title', 'title_duplicate_group_id' );
			} else {
				// If rule is disabled, clear existing findings for Rule 14.
				$del_f = $wpdb->delete( $this->db->findings_table, array( 'rule_id' => 14 ) );
				if ( false === $del_f || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to remove Rule 14 findings from database: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$del_g = $wpdb->delete( $this->db->dup_table, array( 'group_type' => 'title' ) );
				if ( false === $del_g || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to clear title duplicate groups: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$upd_p = $wpdb->query( "UPDATE {$this->db->pages_table} SET title_duplicate_group_id = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $upd_p || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to reset title duplicate group IDs on pages: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
			}

			// 2. Process Rule 17: Duplicate Meta Descriptions.
			if ( $this->settings->is_rule_enabled( 17 ) ) {
				$desc_groups_count = $this->recalculate_type( 'description', 17, 'Duplicate meta descriptions', 'normalized_description', 'desc_duplicate_group_id' );
			} else {
				// If rule is disabled, clear existing findings for Rule 17.
				$del_f2 = $wpdb->delete( $this->db->findings_table, array( 'rule_id' => 17 ) );
				if ( false === $del_f2 || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to remove Rule 17 findings from database: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$del_g2 = $wpdb->delete( $this->db->dup_table, array( 'group_type' => 'description' ) );
				if ( false === $del_g2 || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to clear description duplicate groups: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$upd_p2 = $wpdb->query( "UPDATE {$this->db->pages_table} SET desc_duplicate_group_id = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( false === $upd_p2 || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to reset description duplicate group IDs on pages: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
			}

			// 3. Recount total findings on all pages.
			$this->refresh_all_page_findings_counts();

			$commit_res = $wpdb->query( 'COMMIT' );
			if ( false === $commit_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to commit transaction for duplicate recalculation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			return array(
				'title_groups' => $title_groups_count,
				'desc_groups'  => $desc_groups_count,
			);
		} catch ( Throwable $t ) {
			$rb_res = $wpdb->query( 'ROLLBACK' );
			if ( false === $rb_res || ! empty( $wpdb->last_error ) ) {
				throw new Exception(
					sprintf(
						/* translators: 1: original error message, 2: rollback error */
						__( 'Duplicate recalculation failed (%1$s) and rollback also failed (%2$s).', 'crawlory-technical-seo-dashboard' ),
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
	 * Recalculate duplicate groups for a specific field ('title' or 'description') with reconciliation.
	 *
	 * Preserves finding history, timestamps, and ignored states without deleting and recreating records.
	 *
	 * @param string $group_type 'title' or 'description'.
	 * @param int    $rule_id 14 or 17.
	 * @param string $rule_name Human-readable rule title.
	 * @param string $column 'normalized_title' or 'normalized_description'.
	 * @param string $group_id_col 'title_duplicate_group_id' or 'desc_duplicate_group_id'.
	 * @return int Number of duplicate groups found.
	 */
	private function recalculate_type( $group_type, $rule_id, $rule_name, $column, $group_id_col ) {
		global $wpdb;

		$allowed_columns = array( 'normalized_title', 'normalized_description' );
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return 0;
		}
		$safe_column = esc_sql( $column );

		$allowed_group_cols = array( 'title_duplicate_group_id', 'desc_duplicate_group_id' );
		if ( ! in_array( $group_id_col, $allowed_group_cols, true ) ) {
			return 0;
		}
		$safe_group_id_col = esc_sql( $group_id_col );

		$now      = Crawlory_Helper::now_utc();
		$severity = $this->settings->get_rule_severity( $rule_id );
		$label    = ( 'title' === $group_type ) ? 'SEO title' : 'meta description';

		// Find duplicate values that appear on more than 1 distinct URL where scan_status = 'success'.
		$sql_find_dups = "SELECT {$safe_column} as val, COUNT(*) as url_count
			FROM {$this->db->pages_table}
			WHERE scan_status = 'success'
				AND {$safe_column} IS NOT NULL
				AND {$safe_column} != ''
			GROUP BY {$safe_column}
			HAVING url_count > 1";

		$dup_groups = $wpdb->get_results( $sql_find_dups, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $dup_groups || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to query duplicate groups: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}
		$dup_groups = (array) $dup_groups;

		$active_group_ids  = array();
		$processed_hashes  = array(); // Map of page_id => array of active evidence_hashes

		if ( ! empty( $dup_groups ) ) {
			foreach ( $dup_groups as $group ) {
				$val       = $group['val'];
				$url_count = (int) $group['url_count'];
				$norm_hash = Crawlory_Normalizer::get_comparison_hash( $val );

				// Check or upsert duplicate group record.
				$existing_grp = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$this->db->dup_table} WHERE group_type = %s AND normalized_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$group_type,
						$norm_hash
					)
				);
				if ( ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to query existing duplicate group record: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}

				if ( $existing_grp ) {
					$group_id = (int) $existing_grp->id;
					$upd_grp  = $wpdb->update(
						$this->db->dup_table,
						array(
							'normalized_value' => $val,
							'url_count'        => $url_count,
							'updated_at'       => $now,
						),
						array( 'id' => $group_id )
					);
					if ( false === $upd_grp || ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to update duplicate group record: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
				} else {
					$ins_grp = $wpdb->insert(
						$this->db->dup_table,
						array(
							'group_type'       => $group_type,
							'normalized_hash'  => $norm_hash,
							'normalized_value' => $val,
							'url_count'        => $url_count,
							'updated_at'       => $now,
						)
					);
					if ( false === $ins_grp || ! empty( $wpdb->last_error ) || empty( $wpdb->insert_id ) ) {
						throw new Exception( __( 'Failed to create duplicate group record: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
					$group_id = (int) $wpdb->insert_id;
				}

				$active_group_ids[] = $group_id;

				// Fetch all matching pages in this duplicate group.
				$matching_pages = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, post_id, post_title, post_type, url FROM {$this->db->pages_table} WHERE scan_status = 'success' AND {$safe_column} = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$val
					),
					ARRAY_A
				);
				if ( false === $matching_pages || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to query matching pages for duplicate group: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
				$matching_pages = (array) $matching_pages;

				$page_ids = array_column( $matching_pages, 'id' );

				// Update pages with duplicate group ID.
				if ( ! empty( $page_ids ) ) {
					$clean_page_ids = array_map( 'intval', $page_ids );
					$placeholders   = implode( ', ', array_fill( 0, count( $clean_page_ids ), '%d' ) );
					$query_params   = array_merge( array( $group_id ), $clean_page_ids );
					$upd_p_grp      = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$this->db->pages_table} SET {$safe_group_id_col} = %d WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
							$query_params
						)
					);
					if ( false === $upd_p_grp || ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to update pages with duplicate group ID: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}
				}

				// Reconcile finding for each page in group.
				foreach ( $matching_pages as $p ) {
					$p_id          = (int) $p['id'];
					$evidence_hash = md5( $rule_id . ':' . $group_type . ':' . $norm_hash );

					if ( ! isset( $processed_hashes[ $p_id ] ) ) {
						$processed_hashes[ $p_id ] = array();
					}
					$processed_hashes[ $p_id ][] = $evidence_hash;

					$msg = sprintf(
						'Duplicate %s shared across %d distinct URLs.',
						$label,
						$url_count
					);

					// Lightweight context data referencing group_id instead of storing unbounded arrays of URLs.
					$context_data = array(
						'group_id'   => $group_id,
						'group_type' => $group_type,
						'value'      => $val,
						'total_urls' => $url_count,
					);

					$existing_finding = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM {$this->db->findings_table} WHERE page_id = %d AND rule_id = %d AND evidence_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$p_id,
							$rule_id,
							$evidence_hash
						)
					);
					if ( ! empty( $wpdb->last_error ) ) {
						throw new Exception( __( 'Failed to query existing duplicate finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
					}

					if ( $existing_finding ) {
						$status = 'persistent';
						$reappear_at = null;
						if ( 'resolved' === $existing_finding->finding_status ) {
							$status = 'reappeared';
							$reappear_at = $now;
						}

						$update_data = array(
							'severity'               => $severity,
							'finding_status'         => $status,
							'check_status'           => 'evaluated_successfully',
							'message'                => $msg,
							'observed_value'         => sprintf( '"%s" (matches %d other URLs)', $val, $url_count - 1 ),
							'expected_threshold'     => 'Unique ' . ( 'title' === $group_type ? 'title' : 'description' ) . ' per URL',
							'context_data'           => wp_json_encode( $context_data ),
							'last_checked_at'        => $now,
							'resolved_at'            => null,
						);
						if ( $reappear_at ) {
							$update_data['reappeared_at'] = $reappear_at;
						}

						$upd_f = $wpdb->update(
							$this->db->findings_table,
							$update_data,
							array( 'id' => (int) $existing_finding->id )
						);
						if ( false === $upd_f || ! empty( $wpdb->last_error ) ) {
							throw new Exception( __( 'Failed to update duplicate finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
						}
					} else {
						// Check if this exact duplicate finding was previously ignored.
						$is_ignored = 0;
						$prev_ignored = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id FROM {$this->db->findings_table} WHERE page_id = %d AND evidence_hash = %s AND is_ignored = 1 LIMIT 1",
								$p_id,
								$evidence_hash
							)
						);
						if ( ! empty( $wpdb->last_error ) ) {
							throw new Exception( __( 'Failed to query previously ignored duplicate finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
						}
						if ( $prev_ignored ) {
							$is_ignored = 1;
						}

						$ins_f = $wpdb->insert(
							$this->db->findings_table,
							array(
								'page_id'                => $p_id,
								'rule_id'                => $rule_id,
								'rule_name'              => $rule_name,
								'severity'               => $severity,
								'category'               => 'technical',
								'finding_status'         => 'new',
								'check_status'           => 'evaluated_successfully',
								'evidence_hash'          => $evidence_hash,
								'message'                => $msg,
								'observed_value'         => sprintf( '"%s" (matches %d other URLs)', $val, $url_count - 1 ),
								'expected_threshold'     => 'Unique ' . ( 'title' === $group_type ? 'title' : 'description' ) . ' per URL',
								'why_warrants_attention' => 'Duplicate ' . $label . 's prevent search engines from distinguishing unique page intent, causing ranking cannibalization.',
								'suggested_correction'   => 'Craft a unique, descriptive ' . $label . ' accurately reflecting this specific page content.',
								'how_to_verify'          => 'Update the ' . $label . ' and rescan to ensure it is unique across the site.',
								'context_data'           => wp_json_encode( $context_data ),
								'is_ignored'             => $is_ignored,
								'first_detected_at'      => $now,
								'last_checked_at'        => $now,
								'created_at'             => $now,
							)
						);
						if ( false === $ins_f || ! empty( $wpdb->last_error ) ) {
							throw new Exception( __( 'Failed to insert duplicate finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
						}
					}
				}
			}
		}

		// Reconcile: Mark old findings for this rule_id that are no longer duplicate as resolved.
		$existing_rule_findings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, page_id, evidence_hash FROM {$this->db->findings_table} WHERE rule_id = %d AND finding_status != 'resolved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rule_id
			),
			ARRAY_A
		);
		if ( false === $existing_rule_findings || ! empty( $wpdb->last_error ) ) {
			throw new Exception( __( 'Failed to query existing findings for duplicate rule reconciliation: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
		}
		$existing_rule_findings = (array) $existing_rule_findings;

		foreach ( $existing_rule_findings as $erf ) {
			$p_id = (int) $erf['page_id'];
			$eh   = $erf['evidence_hash'];

			if ( ! isset( $processed_hashes[ $p_id ] ) || ! in_array( $eh, $processed_hashes[ $p_id ], true ) ) {
				$res_res = $wpdb->update(
					$this->db->findings_table,
					array(
						'finding_status'  => 'resolved',
						'check_status'    => 'evaluated_successfully',
						'resolved_at'     => $now,
						'last_checked_at' => $now,
					),
					array( 'id' => (int) $erf['id'] )
				);
				if ( false === $res_res || ! empty( $wpdb->last_error ) ) {
					throw new Exception( __( 'Failed to resolve inactive duplicate finding: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
				}
			}
		}

		// Clear group_id column on pages that are no longer in any active duplicate group.
		if ( ! empty( $active_group_ids ) ) {
			$clean_grps_in = array_map( 'intval', $active_group_ids );
			$placeholders  = implode( ', ', array_fill( 0, count( $clean_grps_in ), '%d' ) );
			$res_clr_p     = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->db->pages_table} SET {$safe_group_id_col} = NULL WHERE {$safe_group_id_col} NOT IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$clean_grps_in
				)
			);
			if ( false === $res_clr_p || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to clear inactive duplicate group IDs on pages: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}

			$delete_params = array_merge( array( $group_type ), $clean_grps_in );
			$res_del_grp   = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->db->dup_table} WHERE group_type = %s AND id NOT IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$delete_params
				)
			);
			if ( false === $res_del_grp || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to delete inactive duplicate groups: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}
		} else {
			$res_rst_p = $wpdb->query( "UPDATE {$this->db->pages_table} SET {$safe_group_id_col} = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $res_rst_p || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to reset duplicate group IDs on pages: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}
			$res_del_all = $wpdb->delete( $this->db->dup_table, array( 'group_type' => $group_type ) );
			if ( false === $res_del_all || ! empty( $wpdb->last_error ) ) {
				throw new Exception( __( 'Failed to clear duplicate group table: ', 'crawlory-technical-seo-dashboard' ) . $wpdb->last_error );
			}
		}

		return count( $dup_groups );
	}

	/**
	 * Recount active findings count on all pages in the database.
	 *
	 * Strictly delegates to Crawlory_DB set-based counter implementation.
	 */
	public function refresh_all_page_findings_counts() {
		$this->db->refresh_all_page_findings_counts();
	}
}
