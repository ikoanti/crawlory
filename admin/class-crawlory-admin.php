<?php
/**
 * Admin interface and AJAX endpoints for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Admin {

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
	 * Scanner instance.
	 *
	 * @var Crawlory_Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_DB       $db Database layer.
	 * @param Crawlory_Settings $settings Settings.
	 * @param Crawlory_Scanner  $scanner Scanner.
	 */
	public function __construct( Crawlory_DB $db, Crawlory_Settings $settings, Crawlory_Scanner $scanner ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->scanner  = $scanner;

		$this->register_hooks();
	}

	/**
	 * Shared admin menu slug.
	 */
	const MENU_SLUG = 'crawlory';

	/**
	 * Register admin hooks.
	 */
	private function register_hooks() {
		add_action( 'admin_init', array( $this, 'handle_legacy_admin_redirect' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_crawlory_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
		add_action( 'wp_ajax_crawlory_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_crawlory_get_scan_progress', array( $this, 'ajax_get_scan_progress' ) );
		add_action( 'wp_ajax_crawlory_batch_scan', array( $this, 'ajax_batch_scan' ) );
		add_action( 'wp_ajax_crawlory_pause_scan', array( $this, 'ajax_pause_scan' ) );
		add_action( 'wp_ajax_crawlory_resume_scan', array( $this, 'ajax_resume_scan' ) );
		add_action( 'wp_ajax_crawlory_cancel_scan', array( $this, 'ajax_cancel_scan' ) );
		add_action( 'wp_ajax_crawlory_rescan_url', array( $this, 'ajax_rescan_url' ) );
		add_action( 'wp_ajax_crawlory_get_page_findings', array( $this, 'ajax_get_page_findings' ) );
		add_action( 'wp_ajax_crawlory_toggle_ignore_finding', array( $this, 'ajax_toggle_ignore_finding' ) );
		add_action( 'wp_ajax_crawlory_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_crawlory_clear_all_data', array( $this, 'ajax_clear_all_data' ) );

		// Export CSV endpoint.
		add_action( 'admin_post_crawlory_export_csv', array( $this, 'handle_export_csv' ) );

		// Admin post endpoints (non-JS form fallbacks).
		add_action( 'admin_post_crawlory_save_settings_post', array( $this, 'handle_post_save_settings' ) );
		add_action( 'admin_post_crawlory_clear_all_data_post', array( $this, 'handle_post_clear_all_data' ) );
	}

	/**
	 * Seamlessly redirect legacy page=technical-seo-dashboard requests to crawlory.
	 */
	public function handle_legacy_admin_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigational redirect for legacy URL routing; performs no state mutation.
		if ( isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ) {
			$page_param = sanitize_key( wp_unslash( $_GET['page'] ) );
			if ( 'technical-seo-dashboard' === $page_param ) {
				$redirect_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
				if ( ! empty( $_GET['filter_url'] ) && is_scalar( $_GET['filter_url'] ) ) {
					$redirect_url = add_query_arg( 'filter_url', rawurlencode( sanitize_text_field( wp_unslash( $_GET['filter_url'] ) ) ), $redirect_url );
				}
				if ( ! empty( $_GET['search'] ) && is_scalar( $_GET['search'] ) ) {
					$redirect_url = add_query_arg( 'search', rawurlencode( sanitize_text_field( wp_unslash( $_GET['search'] ) ) ), $redirect_url );
				}
				wp_safe_redirect( $redirect_url );
				exit;
			} elseif ( 'technical-seo-dashboard-settings' === $page_param ) {
				wp_safe_redirect( admin_url( 'admin.php?page=crawlory-settings' ) );
				exit;
			}
		}

		// Fallback / recovery for URL submitted via GET to admin.php?settings[...] without page param.
		// Never mutate settings or sync cron on GET requests (removes CSRF vulnerability).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigational fallback redirect; performs no state mutation.
		if ( ! isset( $_GET['page'] ) && isset( $_GET['settings'] ) && is_admin() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=crawlory-settings' ) );
			exit;
		}
	}

	/**
	 * Register admin menu items.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Crawlory - Technical SEO Dashboard', 'crawlory-technical-seo-dashboard' ),
			__( 'Crawlory', 'crawlory-technical-seo-dashboard' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-chart-bar',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Crawlory Audit Dashboard', 'crawlory-technical-seo-dashboard' ),
			__( 'Dashboard', 'crawlory-technical-seo-dashboard' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Crawlory Settings', 'crawlory-technical-seo-dashboard' ),
			__( 'Settings', 'crawlory-technical-seo-dashboard' ),
			'manage_options',
			'crawlory-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue styles and scripts on plugin admin screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_crawlory_screen = ( false !== strpos( $hook_suffix, 'crawlory' ) )
			|| ( false !== strpos( $hook_suffix, 'technical-seo-dashboard' ) )
			|| ( $screen && ( false !== strpos( $screen->id, 'crawlory' ) || false !== strpos( $screen->id, 'technical-seo-dashboard' ) ) );

		if ( ! $is_crawlory_screen ) {
			return;
		}

		// Dependency alert: inform administrator gracefully if DOM or SimpleXML is not loaded.
		if ( ! extension_loaded( 'dom' ) || ! extension_loaded( 'simplexml' ) ) {
			add_action( 'admin_notices', array( $this, 'render_extension_notice' ) );
		}

		wp_enqueue_style(
			'crawlory-admin-css',
			CRAWLORY_PLUGIN_URL . 'admin/css/crawlory-admin.css',
			array(),
			CRAWLORY_VERSION
		);

		wp_enqueue_script(
			'crawlory-admin-js',
			CRAWLORY_PLUGIN_URL . 'admin/js/crawlory-admin.js',
			array( 'jquery', 'wp-i18n' ),
			CRAWLORY_VERSION,
			true
		);

		wp_set_script_translations( 'crawlory-admin-js', 'crawlory-technical-seo-dashboard' );

		$active_scan = $this->db->get_active_scan();

		wp_localize_script(
			'crawlory-admin-js',
			'crawloryData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'crawlory_admin_nonce' ),
				'exportNonce'     => wp_create_nonce( 'crawlory_export_csv_nonce' ),
				'exportUrl'       => admin_url( 'admin-post.php?action=crawlory_export_csv' ),
				'activeScanId'    => ( $active_scan && in_array( $active_scan->status, array( 'running', 'paused' ), true ) ) ? (int) $active_scan->id : 0,
				'activeScanStatus'=> $active_scan ? $active_scan->status : '',
				'ruleDefinitions' => Crawlory_Settings::get_rule_definitions(),
				'thresholds'      => array(
					'title_min' => (int) $this->settings->get( 'title_min_length', 30 ),
					'title_max' => (int) $this->settings->get( 'title_max_length', 60 ),
					'desc_min'  => (int) $this->settings->get( 'desc_min_length', 70 ),
					'desc_max'  => (int) $this->settings->get( 'desc_max_length', 155 ),
				),
				'i18n'            => array(
					'confirmClear'         => __( 'Are you sure you want to permanently clear all audit findings and page data? This cannot be undone.', 'crawlory-technical-seo-dashboard' ),
					'confirmCancel'        => __( 'Are you sure you want to cancel the active scan?', 'crawlory-technical-seo-dashboard' ),
					'scanning'             => __( 'Scanning in progress...', 'crawlory-technical-seo-dashboard' ),
					'paused'               => __( 'Scan paused', 'crawlory-technical-seo-dashboard' ),
					'completed'            => __( 'Scan completed successfully!', 'crawlory-technical-seo-dashboard' ),
					'error'                => __( 'An error occurred during scan execution.', 'crawlory-technical-seo-dashboard' ),
					'loadingData'          => __( 'Loading audit data...', 'crawlory-technical-seo-dashboard' ),
					'failedLoad'           => __( 'Failed to load data.', 'crawlory-technical-seo-dashboard' ),
					'connectionError'      => __( 'An error occurred while connecting to the server.', 'crawlory-technical-seo-dashboard' ),
					'noMatchingUrls'       => __( 'No matching URLs found. Try adjusting your filters or running a scan.', 'crawlory-technical-seo-dashboard' ),
					/* translators: 1: Number of displayed URLs, 2: Total number of matching URLs. */
					'showingCount'         => __( 'Showing %1$d of %2$d URLs', 'crawlory-technical-seo-dashboard' ),
					'clean'                => __( 'Clean', 'crawlory-technical-seo-dashboard' ),
					'failed'               => __( 'Failed', 'crawlory-technical-seo-dashboard' ),
					'unscanned'            => __( 'Unscanned', 'crawlory-technical-seo-dashboard' ),
					'interrupted'          => __( 'Interrupted', 'crawlory-technical-seo-dashboard' ),
					'incomplete'           => __( 'Incomplete', 'crawlory-technical-seo-dashboard' ),
					'stale'                => __( 'Stale', 'crawlory-technical-seo-dashboard' ),
					'pendingAnalysis'      => __( 'Pending Analysis', 'crawlory-technical-seo-dashboard' ),
					'analysisPaused'       => __( 'Analysis Paused', 'crawlory-technical-seo-dashboard' ),
					'analysisInterrupted'  => __( 'Analysis Interrupted', 'crawlory-technical-seo-dashboard' ),
					'analysisCancelled'    => __( 'Analysis Cancelled', 'crawlory-technical-seo-dashboard' ),
					'finalizationFailed'   => __( 'Finalization Failed', 'crawlory-technical-seo-dashboard' ),
					'missing'              => __( 'Missing', 'crawlory-technical-seo-dashboard' ),
					'unavailable'          => __( 'Unavailable', 'crawlory-technical-seo-dashboard' ),
					'needsRescan'          => __( 'Needs rescan', 'crawlory-technical-seo-dashboard' ),
					'staleTag'             => __( '(Stale)', 'crawlory-technical-seo-dashboard' ),
					'notEvaluated'         => __( 'Not evaluated', 'crawlory-technical-seo-dashboard' ),
					'chars'                => __( 'chars', 'crawlory-technical-seo-dashboard' ),
					'char'                 => __( 'char', 'crawlory-technical-seo-dashboard' ),
					'issues'               => __( 'issues', 'crawlory-technical-seo-dashboard' ),
					'issue'                => __( 'issue', 'crawlory-technical-seo-dashboard' ),
					'h1'                   => __( 'H1', 'crawlory-technical-seo-dashboard' ),
					'h1s'                  => __( 'H1s', 'crawlory-technical-seo-dashboard' ),
					'occurrence'           => __( 'occurrence', 'crawlory-technical-seo-dashboard' ),
					'occurrences'          => __( 'occurrences', 'crawlory-technical-seo-dashboard' ),
					'inspect'              => __( 'Inspect', 'crawlory-technical-seo-dashboard' ),
					'rescan'               => __( 'Rescan', 'crawlory-technical-seo-dashboard' ),
					'rescanUrl'            => __( 'Rescan URL', 'crawlory-technical-seo-dashboard' ),
					'rescanning'           => __( 'Rescanning...', 'crawlory-technical-seo-dashboard' ),
					'loadingFindings'      => __( 'Loading findings...', 'crawlory-technical-seo-dashboard' ),
					'retry'                => __( 'Retry', 'crawlory-technical-seo-dashboard' ),
					'dismissIgnore'        => __( 'Dismiss / Ignore', 'crawlory-technical-seo-dashboard' ),
					'restoreFinding'       => __( 'Restore Finding', 'crawlory-technical-seo-dashboard' ),
					'cancelScan'           => __( 'Cancel Scan', 'crawlory-technical-seo-dashboard' ),
					'pauseScan'            => __( 'Pause Scan', 'crawlory-technical-seo-dashboard' ),
					'resumeScan'           => __( 'Resume Scan', 'crawlory-technical-seo-dashboard' ),
					'pageAudit'            => __( 'Page Audit', 'crawlory-technical-seo-dashboard' ),
					'noIssuesFound'        => __( 'No issues were found in the last complete scan.', 'crawlory-technical-seo-dashboard' ),
					'noSavedFindings'      => __( 'No saved findings for this URL.', 'crawlory-technical-seo-dashboard' ),
					'noActiveIssues'       => __( 'No active issues for this URL.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %s: Human-readable relative time (e.g. 5 minutes ago) or formatted date. */
					'lastScannedPrefix'    => __( 'Last scanned: %s.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %s: Optional failure reason or empty string. */
					'scanFailedNotice'     => __( 'The last scan of this URL failed%s. Saved findings may be incomplete or out of date.', 'crawlory-technical-seo-dashboard' ),
					'scanStaleNotice'      => __( 'This content changed after its last scan, so saved findings may be out of date. Rescan to refresh them.', 'crawlory-technical-seo-dashboard' ),
					'scanPendingNotice'    => __( 'This URL has not been scanned yet.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %s: Current scan status name (e.g. running, pending). */
					'scanStatusNotice'     => __( 'The last scan status for this URL is "%s". Saved findings may be incomplete.', 'crawlory-technical-seo-dashboard' ),
					'staleTag'             => __( '(Stale)', 'crawlory-technical-seo-dashboard' ),
					'errorTimeout'         => __( 'The request timed out. Check your connection and try again.', 'crawlory-technical-seo-dashboard' ),
					'errorParser'          => __( 'The server returned a malformed response, so the findings could not be read.', 'crawlory-technical-seo-dashboard' ),
					'errorAuth'            => __( 'Your session or security token has expired, or you do not have permission to view this data. Reload the page and sign in again.', 'crawlory-technical-seo-dashboard' ),
					'errorNetwork'         => __( 'Network error: the server could not be reached. Check your connection and try again.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: HTTP status response code (e.g. 500). */
					'errorServer'          => __( 'The server reported an error (HTTP %d). Please try again shortly.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: HTTP status response code. */
					'errorUnexpected'      => __( 'Unexpected response from the server (HTTP %d).', 'crawlory-technical-seo-dashboard' ),
					'errorNoPageId'        => __( 'This row has no valid Crawlory audit page ID, so its findings cannot be loaded. Refresh the dashboard and try again.', 'crawlory-technical-seo-dashboard' ),
					'errorCouldNotLoad'    => __( 'The findings could not be loaded.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: Number of ignored findings. */
					'ignoredSuffix'        => __( '%d ignored', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: Number of resolved findings. */
					'resolvedSuffix'       => __( '%d resolved', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: Total count of historical findings. */
					'historyHeading'       => __( 'History — ignored & resolved (%d)', 'crawlory-technical-seo-dashboard' ),
					/* translators: %s: Comma-separated summary of historical items (e.g. "1 ignored, 2 resolved"). */
					'historyNotePrefix'    => __( 'History (not counted above): %s.', 'crawlory-technical-seo-dashboard' ),
					/* translators: %d: Count of additional findings. */
					'moreFindings'         => __( '+%d more', 'crawlory-technical-seo-dashboard' ),
					'editInWp'             => __( 'Edit in WordPress', 'crawlory-technical-seo-dashboard' ),
					/* translators: %s: Title of the post or page being edited. */
					'editTitleInWp'        => __( 'Edit “%s” in WordPress', 'crawlory-technical-seo-dashboard' ),
					'findingIgnored'       => __( 'Finding ignored.', 'crawlory-technical-seo-dashboard' ),
					'findingRestored'      => __( 'Finding restored.', 'crawlory-technical-seo-dashboard' ),
					'rescanComplete'       => __( 'Rescan complete. Findings refreshed.', 'crawlory-technical-seo-dashboard' ),
					'rescanFailed'         => __( 'The rescan failed.', 'crawlory-technical-seo-dashboard' ),
					'rescanProgress'       => __( 'Rescanning this URL… this can take up to a minute.', 'crawlory-technical-seo-dashboard' ),
					'noValidUrl'           => __( 'This item has no valid URL to rescan.', 'crawlory-technical-seo-dashboard' ),
					'whyWarrantsAttention' => __( 'Why it warrants attention:', 'crawlory-technical-seo-dashboard' ),
					'suggestedCorrection'  => __( 'Suggested correction:', 'crawlory-technical-seo-dashboard' ),
					'howToVerify'          => __( 'How to verify:', 'crawlory-technical-seo-dashboard' ),
					'observed'             => __( 'Observed:', 'crawlory-technical-seo-dashboard' ),
					'expected'             => __( 'Expected:', 'crawlory-technical-seo-dashboard' ),
					'lifecycle'            => __( 'Lifecycle:', 'crawlory-technical-seo-dashboard' ),
					'matchingUrls'         => __( 'Matching URLs:', 'crawlory-technical-seo-dashboard' ),
				),
			)
		);
	}

	/**
	 * Render notice if required PHP extensions are missing.
	 */
	public function render_extension_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		if ( ! extension_loaded( 'dom' ) ) {
			$missing[] = 'PHP DOM';
		}
		if ( ! extension_loaded( 'simplexml' ) ) {
			$missing[] = 'PHP SimpleXML';
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Crawlory Configuration Warning:', 'crawlory-technical-seo-dashboard' ); ?></strong>
				<?php
				/* translators: %s: Comma-separated list of missing PHP extensions. */
				printf( esc_html__( 'The following recommended PHP extensions are not enabled on this server: %s. HTML analysis and XML sitemap parsing will be impaired until these extensions are enabled.', 'crawlory-technical-seo-dashboard' ), esc_html( implode( ', ', $missing ) ) );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render main dashboard page.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		$post_types       = Crawlory_Scanner::get_eligible_post_types();
		$rule_definitions = Crawlory_Settings::get_rule_definitions();

		include CRAWLORY_PLUGIN_DIR . 'admin/views/dashboard-view.php';
		include CRAWLORY_PLUGIN_DIR . 'admin/views/inspect-modal-view.php';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		$settings         = $this->settings;
		$all_post_types   = get_post_types( array( 'public' => true ), 'objects' );
		$rule_definitions = Crawlory_Settings::get_rule_definitions();

		include CRAWLORY_PLUGIN_DIR . 'admin/views/settings-view.php';
	}

	/**
	 * Verify AJAX request security.
	 */
	private function verify_security() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : ( isset( $_REQUEST['_crawlory_nonce'] ) ? '_crawlory_nonce' : 'nonce' );
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
	}

	/**
	 * Sanitize raw settings input array.
	 *
	 * @param array $input Raw input array.
	 * @return array
	 */
	public function sanitize_settings_post( array $input ) {
		return $this->settings->sanitize_settings( $input );
	}

	/**
	 * AJAX: Get stats and paginated page list.
	 */
	public function ajax_get_dashboard_data() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$page           = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page       = isset( $_POST['per_page'] ) ? min( 100, max( 5, absint( $_POST['per_page'] ) ) ) : 20;
		$orderby        = isset( $_POST['orderby'] ) ? sanitize_key( $_POST['orderby'] ) : 'findings_count';
		$order          = isset( $_POST['order'] ) && 'ASC' === strtoupper( sanitize_key( $_POST['order'] ) ) ? 'ASC' : 'DESC';
		$post_type      = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';
		$rule_id        = isset( $_POST['rule_id'] ) ? absint( $_POST['rule_id'] ) : 0;
		$severity       = isset( $_POST['severity'] ) ? sanitize_key( $_POST['severity'] ) : '';
		$scan_status    = isset( $_POST['scan_status'] ) ? sanitize_key( $_POST['scan_status'] ) : '';
		$finding_status = isset( $_POST['finding_status'] ) ? sanitize_key( $_POST['finding_status'] ) : '';
		$search         = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$pages_data = $this->db->get_pages(
			array(
				'page'           => $page,
				'per_page'       => $per_page,
				'orderby'        => $orderby,
				'order'          => $order,
				'post_type'      => $post_type,
				'rule_id'        => $rule_id,
				'severity'       => $severity,
				'scan_status'    => $scan_status,
				'finding_status' => $finding_status,
				'search'         => $search,
			)
		);

		$stats    = $this->db->get_dashboard_stats();
		$coverage = $this->db->get_scan_coverage();

		wp_send_json_success(
			array(
				'stats'    => $stats,
				'pages'    => $pages_data,
				'coverage' => $coverage,
			)
		);
	}

	/**
	 * AJAX: Start a scan.
	 */
	public function ajax_start_scan() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_type   = isset( $_POST['scan_type'] ) ? sanitize_key( $_POST['scan_type'] ) : 'full';
		$target_type = isset( $_POST['target_type'] ) ? sanitize_key( $_POST['target_type'] ) : null;

		$res = $this->scanner->start_scan( $scan_type, $target_type );
		if ( ! empty( $res['success'] ) ) {
			wp_send_json_success( $res );
		} else {
			wp_send_json_error( $res );
		}
	}

	/**
	 * AJAX: Get read-only scan progress status.
	 * Fast (<10ms) read-only query that never crawls, parses sitemaps, or recalculates duplicates.
	 */
	public function ajax_get_scan_progress() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => 'Missing scan ID.' ) );
		}

		$status = $this->scanner->get_scan_status( $scan_id );
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: Run batch iteration.
	 */
	public function ajax_batch_scan() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		if ( ! $scan_id ) {
			wp_send_json_error( array( 'message' => 'Missing scan ID.' ) );
		}

		$res = $this->scanner->run_batch( $scan_id );
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Pause scan.
	 */
	public function ajax_pause_scan() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$this->scanner->pause_scan( $scan_id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Resume scan.
	 */
	public function ajax_resume_scan() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$this->scanner->resume_scan( $scan_id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Cancel scan.
	 */
	public function ajax_cancel_scan() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$this->scanner->cancel_scan( $scan_id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Rescan a single URL.
	 */
	public function ajax_rescan_url() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'Invalid URL supplied.' ) );
		}

		$res = $this->scanner->rescan_single_url( $url, $post_id );
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Get findings for inspect drawer.
	 */
	public function ajax_get_page_findings() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		// NOTE: page_id is the Crawlory audit page ID, not the WordPress post ID.
		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		if ( ! $page_id ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_page_id',
					'message' => __( 'Missing or invalid audit page ID.', 'crawlory-technical-seo-dashboard' ),
				),
				400
			);
		}

		$page = $this->db->get_page_by_id( $page_id );
		if ( ! $page ) {
			wp_send_json_error(
				array(
					'code'    => 'page_not_found',
					'message' => __( 'This audit record no longer exists. Refresh the dashboard and try again.', 'crawlory-technical-seo-dashboard' ),
				),
				404
			);
		}

		$findings = $this->db->get_findings_for_page( $page_id );

		// Decode context data for frontend consumption.
		foreach ( $findings as &$f ) {
			if ( ! empty( $f['context_data'] ) ) {
				$decoded      = json_decode( $f['context_data'], true );
				$f['context'] = is_array( $decoded ) ? $decoded : array();
			} else {
				$f['context'] = array();
			}
		}
		unset( $f );

		$post_id   = isset( $page->post_id ) ? (int) $page->post_id : 0;
		$edit_link = '';
		if ( $post_id > 0 && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			$maybe_edit = get_edit_post_link( $post_id, 'raw' );
			$edit_link  = $maybe_edit ? esc_url_raw( $maybe_edit ) : '';
		}

		$scan_status = isset( $page->scan_status ) ? (string) $page->scan_status : 'pending';
		$audit_state = Crawlory_DB::compute_page_audit_state( $page );

		// Retrieve authoritative completeness and freshness directly from compute_page_audit_state.
		$completeness = isset( $audit_state['completeness'] ) ? $audit_state['completeness'] : 'incomplete';
		$freshness    = isset( $audit_state['freshness'] ) ? $audit_state['freshness'] : 'current';

		wp_send_json_success(
			array(
				'page'     => array(
					'id'                         => (int) $page->id,
					'post_id'                    => $post_id,
					'url'                        => isset( $page->url ) ? esc_url_raw( $page->url ) : '',
					'post_title'                 => isset( $page->post_title ) ? (string) $page->post_title : '',
					'edit_link'                  => $edit_link,
					'scan_status'                => $scan_status,
					'audit_state'                => $audit_state,
					'completeness'               => $completeness,
					'freshness'                  => $freshness,
					'fetch_error'                => ! empty( $page->fetch_error ) ? (string) $page->fetch_error : '',
					'http_status'                => isset( $page->http_status ) ? (int) $page->http_status : 0,
					'seo_title'                  => isset( $page->seo_title ) ? (string) $page->seo_title : '',
					'seo_title_length'           => isset( $page->seo_title_length ) ? (int) $page->seo_title_length : 0,
					'meta_description'           => isset( $page->meta_description ) ? (string) $page->meta_description : '',
					'meta_description_length'    => isset( $page->meta_description_length ) ? (int) $page->meta_description_length : 0,
					'h1_count'                   => isset( $page->h1_count ) ? (int) $page->h1_count : 0,
					'last_scanned_at'            => isset( $page->last_scanned_at ) ? (string) $page->last_scanned_at : '',
					'last_successful_scanned_at' => isset( $page->last_successful_scanned_at ) ? (string) $page->last_successful_scanned_at : '',
				),
				'findings' => $findings,
				'summary'  => Crawlory_DB::summarize_findings( $findings ),
			)
		);
	}


	/**
	 * AJAX: Toggle ignore status of a finding.
	 */
	public function ajax_toggle_ignore_finding() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$finding_id = isset( $_POST['finding_id'] ) ? absint( $_POST['finding_id'] ) : 0;
		$ignore     = ! empty( $_POST['ignore'] );

		if ( ! $finding_id ) {
			wp_send_json_error( array( 'message' => 'Missing finding ID.' ) );
		}

		$res = $this->db->toggle_ignore_finding( $finding_id, $ignore, get_current_user_id() );
		if ( ! $res ) {
			wp_send_json_error(
				array(
					'code'    => 'finding_not_found',
					'message' => __( 'This finding no longer exists. Reload the drawer and try again.', 'crawlory-technical-seo-dashboard' ),
				),
				404
			);
		}
		wp_send_json_success( array( 'updated' => $res ) );
	}

	/**
	 * AJAX: Save settings.
	 */
	public function ajax_save_settings() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw settings array is validated and sanitized field-by-field in Crawlory_Settings::sanitize_settings().
		$raw_settings  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
		$settings_data = $this->sanitize_settings_post( $raw_settings );
		$this->settings->save( $settings_data );

		// Sync WP-Cron schedule.
		$cron = new Crawlory_Cron( $this->scanner, $this->settings );
		$cron->sync_schedule();

		wp_send_json_success( array( 'message' => 'Settings saved successfully.' ) );
	}


	/**
	 * Shared server-side reset operation used by both AJAX and native POST fallback.
	 *
	 * - Establishes a reset barrier blocking concurrent worker commits.
	 * - Cancels/invalidates active jobs and their credentials.
	 * - Clears locks, transients, and queued WP-Cron continuation events.
	 * - Clears all 8 plugin-owned tables.
	 * - Releases the reset barrier only after verified outcome.
	 * - Preserves WordPress content, media, taxonomy, third-party SEO, and Crawlory settings.
	 *
	 * @return array Status result array.
	 */
	public function perform_clear_all_data() {
		// 1. Establish reset barrier transient (5 minutes safety window).
		set_transient( 'crawlory_reset_barrier', 1, 300 );

		// 2. Invalidate active scans in database.
		$active = $this->db->get_active_scan();
		if ( $active ) {
			$this->db->update_scan(
				$active->id,
				array(
					'status'             => 'cancelled',
					'error_message'      => 'Audit data reset by administrator.',
					'worker_token'       => null,
					'worker_lease_expires' => null,
					'finished_at'        => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			wp_clear_scheduled_hook( 'crawlory_process_background_batch', array( (int) $active->id ) );
		}

		// 3. Clear all locks and transients.
		delete_transient( 'crawlory_scan_lock' );
		delete_option( 'crawlory_active_scan_id' );
		delete_option( 'crawlory_last_scan_id' );
		wp_clear_scheduled_hook( 'crawlory_process_background_batch' );

		// 4. Clear all 8 database tables.
		$cleared = $this->db->clear_all_data();

		// 5. Release reset barrier.
		delete_transient( 'crawlory_reset_barrier' );

		if ( ! $cleared ) {
			return array(
				'success' => false,
				'message' => __( 'One or more audit tables could not be cleared. Please check database permissions and retry.', 'crawlory-technical-seo-dashboard' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'All audit findings, page records, links, queues, and caches cleared successfully.', 'crawlory-technical-seo-dashboard' ),
		);
	}

	/**
	 * AJAX: Clear all data.
	 */
	public function ajax_clear_all_data() {
		$nonce_field = isset( $_REQUEST['nonce'] ) ? 'nonce' : '_crawlory_nonce';
		check_ajax_referer( 'crawlory_admin_nonce', $nonce_field );
		$this->verify_security();

		$result = $this->perform_clear_all_data();
		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Action: Handle CSV export.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		$nonce = '';
		if ( ! empty( $_REQUEST['_crawlory_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_crawlory_nonce'] ) );
		} elseif ( ! empty( $_REQUEST['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
		} elseif ( ! empty( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}

		if ( ! wp_verify_nonce( $nonce, 'crawlory_export_csv_nonce' ) && ! wp_verify_nonce( $nonce, 'crawlory_admin_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed. The link you followed may have expired.', 'crawlory-technical-seo-dashboard' ), '', array( 'response' => 403 ) );
		}

		$filter_args = array(
			'post_type'      => isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '',
			'rule_id'        => isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0,
			'severity'       => isset( $_GET['severity'] ) ? sanitize_key( $_GET['severity'] ) : '',
			'scan_status'    => isset( $_GET['scan_status'] ) ? sanitize_key( $_GET['scan_status'] ) : '',
			'finding_status' => isset( $_GET['finding_status'] ) ? sanitize_key( $_GET['finding_status'] ) : '',
			'search'         => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
		);

		if ( isset( $_GET['is_ignored'] ) && '' !== $_GET['is_ignored'] ) {
			$filter_args['is_ignored'] = absint( $_GET['is_ignored'] );
		}

		$exporter = new Crawlory_Exporter( $this->db );
		$exporter->export_csv( $filter_args );
	}

	/**
	 * Action: Handle native POST save settings fallback.
	 */
	public function handle_post_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		check_admin_referer( 'crawlory_admin_nonce', '_crawlory_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw settings array is validated and sanitized field-by-field in Crawlory_Settings::sanitize_settings().
		$raw_settings  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
		$settings_data = $this->sanitize_settings_post( $raw_settings );
		$this->settings->save( $settings_data );

		// Sync WP-Cron schedule.
		$cron = new Crawlory_Cron( $this->scanner, $this->settings );
		$cron->sync_schedule();

		$redirect_url = add_query_arg(
			array(
				'page'             => 'technical-seo-dashboard-settings',
				'settings-updated' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Action: Handle native POST clear all data fallback.
	 */
	public function handle_post_clear_all_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		check_admin_referer( 'crawlory_admin_nonce', '_crawlory_nonce' );

		$res = $this->perform_clear_all_data();

		$redirect_url = add_query_arg(
			array(
				'page'    => 'technical-seo-dashboard-settings',
				'cleared' => $res['success'] ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
