<?php
/**
 * CSV Exporter with Formula-Injection Protection.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Exporter {

	/**
	 * Database instance.
	 *
	 * @var Crawlory_DB
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_DB $db Database layer.
	 */
	public function __construct( Crawlory_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Sanitize a string against CSV formula injection (CSV Injection / DDE).
	 * If the text begins with =, +, -, @, tab, or carriage return, prefix it with a single quote.
	 *
	 * @param mixed $value Cell value.
	 * @return string Safe cell value.
	 */
	public static function sanitize_csv_cell( $value ) {
		if ( null === $value ) {
			return '';
		}

		$string_val = (string) $value;

		// Check dangerous leading characters: =, +, -, @, \t, \r.
		$dangerous_chars = array( '=', '+', '-', '@', "\t", "\r" );
		if ( ! empty( $string_val ) && in_array( $string_val[0], $dangerous_chars, true ) ) {
			return "'" . $string_val;
		}

		return $string_val;
	}

	/**
	 * Export findings matching filter arguments as a CSV stream.
	 *
	 * @param array $filter_args Filter parameters.
	 */
	public function export_csv( array $filter_args = array() ) {
		global $wpdb;

		// Verify capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		$where_clauses = array( '1=1' );
		$where_params  = array();

		// Filter by post_type.
		if ( ! empty( $filter_args['post_type'] ) ) {
			$where_clauses[] = 'p.post_type = %s';
			$where_params[]  = sanitize_key( $filter_args['post_type'] );
		}

		// Filter by rule_id.
		if ( ! empty( $filter_args['rule_id'] ) ) {
			$where_clauses[] = 'f.rule_id = %d';
			$where_params[]  = absint( $filter_args['rule_id'] );
		}

		// Filter by severity.
		if ( ! empty( $filter_args['severity'] ) ) {
			$where_clauses[] = 'f.severity = %s';
			$where_params[]  = sanitize_key( $filter_args['severity'] );
		}

		// Filter by scan_status.
		if ( ! empty( $filter_args['scan_status'] ) ) {
			$where_clauses[] = 'p.scan_status = %s';
			$where_params[]  = sanitize_key( $filter_args['scan_status'] );
		}

		// Finding status and ignored status.
		if ( ! empty( $filter_args['finding_status'] ) ) {
			if ( 'ignored' === $filter_args['finding_status'] ) {
				$where_clauses[] = 'f.is_ignored = 1';
			} elseif ( 'resolved' === $filter_args['finding_status'] ) {
				$where_clauses[] = "f.finding_status = 'resolved' AND f.is_ignored = 0";
			} elseif ( 'persistent' === $filter_args['finding_status'] ) {
				$where_clauses[] = "f.finding_status IN ('persistent', 'reappeared') AND f.is_ignored = 0";
			} elseif ( 'new' === $filter_args['finding_status'] ) {
				$where_clauses[] = "f.finding_status = 'new' AND f.is_ignored = 0";
			} else {
				$where_clauses[] = 'f.finding_status = %s AND f.is_ignored = 0';
				$where_params[]  = sanitize_key( $filter_args['finding_status'] );
			}
		} elseif ( isset( $filter_args['is_ignored'] ) && '' !== $filter_args['is_ignored'] ) {
			$where_clauses[] = 'f.is_ignored = %d';
			$where_params[]  = absint( $filter_args['is_ignored'] );
			$where_clauses[] = "f.finding_status != 'resolved'";
		} else {
			$where_clauses[] = "f.is_ignored = 0 AND f.finding_status != 'resolved'";
		}

		// Search.
		if ( ! empty( $filter_args['search'] ) ) {
			$search          = '%' . $wpdb->esc_like( trim( $filter_args['search'] ) ) . '%';
			$where_clauses[] = '(p.url LIKE %s OR p.post_title LIKE %s)';
			$where_params[]  = $search;
			$where_params[]  = $search;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$sql = "SELECT
				p.post_id,
				p.post_title,
				p.post_type,
				p.url,
				p.scan_status,
				p.http_status,
				p.fetch_error,
				p.findings_count,
				p.has_incomplete_resources,
				p.last_scanned_at,
				p.last_successful_scanned_at,
				p.last_failed_scanned_at,
				f.rule_id,
				f.rule_name,
				f.severity,
				f.finding_status,
				f.category,
				f.message,
				f.observed_value,
				f.expected_threshold,
				f.context_data,
				f.is_ignored
			FROM {$this->db->findings_table} f
			INNER JOIN {$this->db->pages_table} p ON p.id = f.page_id
			WHERE {$where_sql}
			ORDER BY p.id ASC, f.rule_id ASC";

		if ( ! empty( $where_params ) ) {
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $where_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$filename = 'seo-audit-findings-' . gmdate( 'Y-m-d-His' ) . '.csv';

		// Clean output buffers.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Send HTTP headers.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Microsoft Excel.
		echo "\xEF\xBB\xBF";

		// CSV Column Headers.
		$headers = array(
			'Post ID',
			'Content Title',
			'Content Type',
			'Public URL',
			'Page Audit State',
			'Rule #',
			'Finding Name',
			'Category',
			'Severity',
			'Finding Status',
			'Finding Message',
			'Observed Value',
			'Expected Criteria',
			'Context Details',
			'Is Ignored',
			'Last Scanned',
		);

		fputcsv( $output, array_map( array( __CLASS__, 'sanitize_csv_cell' ), $headers ) );

		if ( ! empty( $results ) ) {
			$active_scan    = $this->db->get_active_scan();
			$shared_context = array( 'active_scan' => $active_scan );

			foreach ( $results as $row ) {
				$context_summary = '';
				if ( ! empty( $row['context_data'] ) ) {
					$ctx = json_decode( $row['context_data'], true );
					if ( is_array( $ctx ) ) {
						// Flatten for readable CSV cell.
						$parts = array();
						foreach ( $ctx as $k => $v ) {
							if ( is_scalar( $v ) ) {
								$parts[] = "{$k}: {$v}";
							}
						}
						$context_summary = implode( '; ', $parts );
					}
				}

				$audit_state = Crawlory_DB::compute_page_audit_state( $row, $shared_context );
				$state_label = $audit_state['label'] . ( ! empty( $audit_state['is_stale'] ) ? ' (Stale)' : '' );

				$csv_row = array(
					self::sanitize_csv_cell( $row['post_id'] ),
					self::sanitize_csv_cell( $row['post_title'] ),
					self::sanitize_csv_cell( $row['post_type'] ),
					self::sanitize_csv_cell( $row['url'] ),
					self::sanitize_csv_cell( $state_label ),
					self::sanitize_csv_cell( $row['rule_id'] ),
					self::sanitize_csv_cell( $row['rule_name'] ),
					self::sanitize_csv_cell( $row['category'] ),
					self::sanitize_csv_cell( $row['severity'] ),
					self::sanitize_csv_cell( isset( $row['finding_status'] ) ? $row['finding_status'] : 'new' ),
					self::sanitize_csv_cell( $row['message'] ),
					self::sanitize_csv_cell( $row['observed_value'] ),
					self::sanitize_csv_cell( $row['expected_threshold'] ),
					self::sanitize_csv_cell( $context_summary ),
					self::sanitize_csv_cell( $row['is_ignored'] ? 'Yes' : 'No' ),
					self::sanitize_csv_cell( $row['last_scanned_at'] ),
				);

				fputcsv( $output, $csv_row );
			}
		}

		if ( is_resource( $output ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream handle is opened strictly on php://output for HTTP response streaming, not a local filesystem file.
			fclose( $output );
		}

		exit;
	}
}
