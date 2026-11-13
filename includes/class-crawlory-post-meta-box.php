<?php
/**
 * Per-Page Audit Panel for Crawlory.
 *
 * Adds "Crawlory SEO Audit" panel to WordPress editing screens for all
 * supported public post types (pages, posts, WooCommerce products, portfolios).
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Post_Meta_Box {

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
	 * @param Crawlory_DB       $db Database.
	 * @param Crawlory_Settings $settings Settings.
	 * @param Crawlory_Scanner  $scanner Scanner.
	 */
	public function __construct( Crawlory_DB $db, Crawlory_Settings $settings, Crawlory_Scanner $scanner ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->scanner  = $scanner;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_ajax_crawlory_editor_scan_url', array( $this, 'ajax_scan_url' ) );
	}

	/**
	 * Register meta box on all public and eligible post types.
	 */
	public function register_meta_box() {
		$post_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );

		foreach ( $post_types as $pt ) {
			add_meta_box(
				'crawlory_seo_audit_panel',
				__( 'Crawlory SEO Audit', 'crawlory-technical-seo-dashboard' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Enqueue styles and scripts on post edit screens.
	 *
	 * @param string $hook Admin screen hook.
	 */
	public function enqueue_editor_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
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

		wp_localize_script(
			'crawlory-admin-js',
			'crawloryAdminData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'crawlory_admin_nonce' ),
				'dashboard' => admin_url( 'admin.php?page=' . ( defined( 'CRAWLORY_ADMIN_MENU_SLUG' ) ? CRAWLORY_ADMIN_MENU_SLUG : 'technical-seo-dashboard' ) ),
				'strings'   => array(
					'scanning'        => __( 'Scanning URL...', 'crawlory-technical-seo-dashboard' ),
					'scanComplete'    => __( 'Scan completed successfully.', 'crawlory-technical-seo-dashboard' ),
					'scanFailed'      => __( 'Scan failed. Check details below.', 'crawlory-technical-seo-dashboard' ),
					'rescan'          => __( 'Rescan', 'crawlory-technical-seo-dashboard' ),
					'scanNow'         => __( 'Scan this URL', 'crawlory-technical-seo-dashboard' ),
				),
			)
		);
	}

	/**
	 * Render the Crawlory SEO Audit meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$public_url = get_permalink( $post->ID );
		$is_published = ( 'publish' === $post->post_status && empty( $post->post_password ) );

		// If unpublished or password protected:
		if ( ! $is_published ) {
			?>
			<div class="crawlory-editor-box unpublished-notice">
				<div class="crawlory-notice-box info">
					<span class="dashicons dashicons-info"></span>
					<div>
						<strong><?php esc_html_e( 'Public Scanning Unavailable', 'crawlory-technical-seo-dashboard' ); ?></strong>
						<p><?php
						/* translators: %s: Post status name (e.g. draft, private). */
						printf( esc_html__( 'This content item is currently in "%s" status. Crawlory scans only public visitor-facing content without administrative sessions or cookies. Draft or private items cannot be scanned publicly. Once published, you can audit its saved public version directly from this panel.', 'crawlory-technical-seo-dashboard' ), esc_html( $post->post_status ) );
						?></p>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		// Retrieve existing page audit record from database.
		$page_record = $this->db->get_page_by_url( $public_url );
		$findings    = array();
		if ( $page_record ) {
			$findings = $this->db->get_findings_for_page( $page_record->id );
		}

		?>
		<div class="crawlory-editor-box" id="crawlory-editor-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-url="<?php echo esc_url( $public_url ); ?>">
			<?php echo $this->render_panel_inner( $post, $page_record, $findings, $public_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}

	/**
	 * Render internal contents of editor panel.
	 *
	 * @param WP_Post     $post Post object.
	 * @param object|null $page_record Page DB record.
	 * @param array       $findings Array of finding records.
	 * @param string      $public_url Public URL.
	 * @return string Rendered HTML.
	 */
	public function render_panel_inner( $post, $page_record, array $findings, $public_url ) {
		$audit_state           = $page_record ? Crawlory_DB::compute_page_audit_state( $page_record ) : array( 'state' => 'pending', 'label' => __( 'Unscanned', 'crawlory-technical-seo-dashboard' ), 'is_stale' => false, 'is_evaluated' => false, 'css_class' => 'crawlory-badge-pending', 'has_html_measurements' => false );
		$is_stale              = ! empty( $audit_state['is_stale'] );
		$is_evaluated          = ! empty( $audit_state['is_evaluated'] );
		$has_html_measurements = ! empty( $audit_state['has_html_measurements'] );
		$dash_slug             = defined( 'CRAWLORY_ADMIN_MENU_SLUG' ) ? CRAWLORY_ADMIN_MENU_SLUG : 'crawlory';

		ob_start();
		?>
		<!-- Header Bar -->
		<div class="crawlory-panel-header">
			<div class="crawlory-panel-url-info">
				<div class="crawlory-panel-brand-bar">
					<a href="https://crawlory.app" target="_blank" rel="noopener noreferrer" class="crawlory-brand-badge crawlory-brand-badge-sm" title="Crawlory.app">
						<span class="dashicons dashicons-chart-bar crawlory-brand-icon"></span>
						<span class="crawlory-brand-name">Crawlory</span><span class="crawlory-brand-tld">.app</span>
					</a>
					<span class="crawlory-url-label"><?php esc_html_e( 'Public URL:', 'crawlory-technical-seo-dashboard' ); ?></span>
					<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer" class="crawlory-exact-url">
						<?php echo esc_html( $public_url ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				</div>
				<p class="crawlory-note-unsaved">
					<em><?php esc_html_e( 'Scans evaluate the saved public version of this page. Unsaved edits in the editor are not included.', 'crawlory-technical-seo-dashboard' ); ?></em>
				</p>
			</div>
			<div class="crawlory-panel-actions">
				<button type="button" class="button button-primary" id="crawlory-editor-scan-btn">
					<span class="dashicons dashicons-search"></span>
					<span class="btn-text"><?php echo $page_record ? esc_html__( 'Rescan', 'crawlory-technical-seo-dashboard' ) : esc_html__( 'Scan this URL', 'crawlory-technical-seo-dashboard' ); ?></span>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $dash_slug . '&search=' . rawurlencode( $public_url ) ) ); ?>" class="button button-secondary" target="_blank">
					<?php esc_html_e( 'View in Dashboard', 'crawlory-technical-seo-dashboard' ); ?>
				</a>
			</div>
		</div>

		<div id="crawlory-editor-progress" class="crawlory-progress-bar-container" style="display:none;">
			<div class="crawlory-progress-bar"><div class="crawlory-progress-fill"></div></div>
			<span class="crawlory-progress-status"><?php esc_html_e( 'Scanning public URL...', 'crawlory-technical-seo-dashboard' ); ?></span>
		</div>

		<!-- Status Badges & Last Scanned -->
		<div class="crawlory-panel-status-bar">
			<span class="crawlory-badge <?php echo esc_attr( $audit_state['css_class'] ); ?>"><?php echo esc_html( $audit_state['label'] ); ?></span>

			<?php if ( $is_stale ) : ?>
				<span class="crawlory-badge badge-stale"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'Stale (retained prior value; rescan to refresh)', 'crawlory-technical-seo-dashboard' ); ?></span>
			<?php endif; ?>

			<?php if ( $page_record && ! empty( $page_record->last_successful_scanned_at ) ) : ?>
				<span class="crawlory-scan-meta">
					<span class="dashicons dashicons-calendar-alt"></span>
					<?php
					/* translators: %s: Relative human-readable time difference (e.g. 5 minutes ago). */
					printf( esc_html__( 'Last scan: %s', 'crawlory-technical-seo-dashboard' ), esc_html( human_time_diff( strtotime( $page_record->last_successful_scanned_at ), time() ) . ' ago' ) );
					?>
				</span>
			<?php elseif ( ! $page_record ) : ?>
				<span class="crawlory-scan-meta empty"><?php esc_html_e( 'Not yet scanned.', 'crawlory-technical-seo-dashboard' ); ?></span>
			<?php endif; ?>

			<?php if ( $page_record && ! empty( $page_record->last_failed_scanned_at ) && ( empty( $page_record->last_successful_scanned_at ) || strtotime( $page_record->last_failed_scanned_at ) >= strtotime( $page_record->last_successful_scanned_at ) ) ) : ?>
				<span class="crawlory-badge badge-error"><?php
				/* translators: 1: Date and time of failed scan, 2: Error message or reason. */
				printf( esc_html__( 'Latest attempt failed: %1$s (%2$s)', 'crawlory-technical-seo-dashboard' ), esc_html( $page_record->last_failed_scanned_at ), esc_html( $page_record->fetch_error ? $page_record->fetch_error : 'HTTP Error' ) );
				?></span>
			<?php endif; ?>
		</div>

		<?php if ( $page_record ) : ?>
			<!-- Summary Pill Counters -->
			<?php
			$errors_count   = 0;
			$warnings_count = 0;
			$info_count     = 0;
			foreach ( $findings as $f ) {
				if ( ! empty( $f['is_ignored'] ) || ( isset( $f['finding_status'] ) && 'resolved' === $f['finding_status'] ) ) {
					continue;
				}
				if ( 'error' === $f['severity'] ) {
					$errors_count++;
				} elseif ( 'warning' === $f['severity'] ) {
					$warnings_count++;
				} else {
					$info_count++;
				}
			}
			?>
			<div class="crawlory-pill-counters">
				<div class="pill-stat errors">
					<span class="stat-num"><?php echo esc_html( $errors_count ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Errors', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>
				<div class="pill-stat warnings">
					<span class="stat-num"><?php echo esc_html( $warnings_count ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Warnings', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>
				<div class="pill-stat info">
					<span class="stat-num"><?php echo esc_html( $info_count ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Review items', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>
			</div>

			<!-- Key Extracted Values -->
			<div class="crawlory-extracted-metrics">
				<div class="metric-row">
					<strong><?php esc_html_e( 'SEO Title:', 'crawlory-technical-seo-dashboard' ); ?></strong>
					<span class="metric-val"><?php
					if ( ! $has_html_measurements ) {
						echo '<span style="color:#94a3b8;">&mdash;</span>';
					} elseif ( ! empty( $page_record->seo_title ) ) {
						echo esc_html( $page_record->seo_title );
						if ( $is_stale ) {
							echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
						}
					} else {
						echo '<em>' . esc_html__( '[Missing/Empty]', 'crawlory-technical-seo-dashboard' ) . '</em>';
						if ( $is_stale ) {
							echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
						}
					}
					?></span>
					<span class="metric-badge"><?php
					if ( $has_html_measurements ) {
						$title_len   = (int) $page_record->seo_title_length;
						$title_label = sprintf(
							/* translators: %d: Number of characters in the SEO title. */
							_n( '%d char', '%d chars', $title_len, 'crawlory-technical-seo-dashboard' ),
							$title_len
						);
						echo esc_html( $title_label );
						if ( $is_stale ) {
							echo ' ' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' );
						}
					} else {
						echo '&mdash;';
					}
					?></span>
				</div>
				<div class="metric-row">
					<strong><?php esc_html_e( 'Meta Description:', 'crawlory-technical-seo-dashboard' ); ?></strong>
					<span class="metric-val"><?php
					if ( ! $has_html_measurements ) {
						echo '<span style="color:#94a3b8;">&mdash;</span>';
					} elseif ( ! empty( $page_record->meta_description ) ) {
						echo esc_html( $page_record->meta_description );
						if ( $is_stale ) {
							echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
						}
					} else {
						echo '<em>' . esc_html__( '[Missing/Empty]', 'crawlory-technical-seo-dashboard' ) . '</em>';
						if ( $is_stale ) {
							echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
						}
					}
					?></span>
					<span class="metric-badge"><?php
					if ( $has_html_measurements ) {
						$desc_len   = (int) $page_record->meta_description_length;
						$desc_label = sprintf(
							/* translators: %d: Number of characters in the meta description. */
							_n( '%d char', '%d chars', $desc_len, 'crawlory-technical-seo-dashboard' ),
							$desc_len
						);
						echo esc_html( $desc_label );
						if ( $is_stale ) {
							echo ' ' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' );
						}
					} else {
						echo '&mdash;';
					}
					?></span>
				</div>
				<div class="metric-row">
					<strong><?php esc_html_e( 'H1 Headings:', 'crawlory-technical-seo-dashboard' ); ?></strong>
					<span class="metric-val">
						<?php
						if ( ! $has_html_measurements ) {
							echo '<span style="color:#94a3b8;">&mdash;</span>';
						} else {
							$h1_list = ! empty( $page_record->h1_contents ) ? json_decode( $page_record->h1_contents, true ) : array();
							if ( ! empty( $h1_list ) && is_array( $h1_list ) ) {
								echo esc_html( implode( ' | ', $h1_list ) );
								if ( $is_stale ) {
									echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
								}
							} else {
								echo '<em>' . esc_html__( '[None]', 'crawlory-technical-seo-dashboard' ) . '</em>';
								if ( $is_stale ) {
									echo ' <span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' ) . '</span>';
								}
							}
						}
						?>
					</span>
					<span class="metric-badge"><?php
					if ( $has_html_measurements ) {
						$h1_cnt   = isset( $page_record->h1_count ) ? (int) $page_record->h1_count : 0;
						$h1_label = sprintf(
							/* translators: %d: Number of H1 heading tags found on the page. */
							_n( '%d H1', '%d H1s', $h1_cnt, 'crawlory-technical-seo-dashboard' ),
							$h1_cnt
						);
						echo esc_html( $h1_label );
						if ( $is_stale ) {
							echo ' ' . esc_html__( '(Stale)', 'crawlory-technical-seo-dashboard' );
						}
					} else {
						echo '&mdash;';
					}
					?></span>
				</div>
				<?php if ( ! empty( $page_record->canonical_url ) ) : ?>
				<div class="metric-row">
					<strong><?php esc_html_e( 'Canonical URL:', 'crawlory-technical-seo-dashboard' ); ?></strong>
					<span class="metric-val"><?php echo esc_html( $page_record->canonical_url ); ?></span>
					<span class="metric-badge"><?php echo ( untrailingslashit( $page_record->canonical_url ) === untrailingslashit( $public_url ) ) ? esc_html__( 'Self', 'crawlory-technical-seo-dashboard' ) : esc_html__( 'Points elsewhere', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<!-- Findings Section -->
			<div class="crawlory-findings-section">
				<h4><?php esc_html_e( 'Audit Findings & Correction Guidance', 'crawlory-technical-seo-dashboard' ); ?></h4>
				<?php if ( empty( $findings ) ) : ?>
					<p class="crawlory-no-findings"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'No technical SEO defects found for this URL.', 'crawlory-technical-seo-dashboard' ); ?></p>
				<?php else : ?>
					<div class="crawlory-findings-accordion">
						<?php foreach ( $findings as $find ) :
							$f_status = isset( $find['finding_status'] ) ? $find['finding_status'] : 'new';
							if ( 'resolved' === $f_status ) {
								continue;
							}
							$sev = $find['severity'];
						?>
							<div class="crawlory-finding-card sev-<?php echo esc_attr( $sev ); ?>">
								<div class="finding-card-head">
									<span class="badge badge-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( strtoupper( $sev ) ); ?></span>
									<span class="finding-title"><?php echo esc_html( $find['rule_name'] ); ?></span>
									<?php if ( ! empty( $find['is_ignored'] ) ) : ?>
										<span class="badge badge-ignored"><?php esc_html_e( 'Ignored', 'crawlory-technical-seo-dashboard' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="finding-card-body">
									<p class="finding-msg"><?php echo esc_html( $find['message'] ); ?></p>
									<?php if ( ! empty( $find['observed_value'] ) ) : ?>
										<div class="finding-evidence">
											<strong><?php esc_html_e( 'Evidence:', 'crawlory-technical-seo-dashboard' ); ?></strong>
											<code><?php echo esc_html( $find['observed_value'] ); ?></code>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $find['why_warrants_attention'] ) ) : ?>
										<div class="finding-guidance">
											<strong><?php esc_html_e( 'Why it warrants attention:', 'crawlory-technical-seo-dashboard' ); ?></strong>
											<p><?php echo esc_html( $find['why_warrants_attention'] ); ?></p>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $find['suggested_correction'] ) ) : ?>
										<div class="finding-correction">
											<strong><?php esc_html_e( 'Suggested correction:', 'crawlory-technical-seo-dashboard' ); ?></strong>
											<p><?php echo esc_html( $find['suggested_correction'] ); ?></p>
										</div>
									<?php endif; ?>
									<?php if ( ! empty( $find['how_to_verify'] ) ) : ?>
										<div class="finding-verify">
											<strong><?php esc_html_e( 'How to verify:', 'crawlory-technical-seo-dashboard' ); ?></strong>
											<p><?php echo esc_html( $find['how_to_verify'] ); ?></p>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

		<?php else : ?>
			<div class="crawlory-empty-state">
				<p><?php esc_html_e( 'Click "Scan this URL" to run a real-time visitor-mode technical SEO audit on this published page.', 'crawlory-technical-seo-dashboard' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for scanning a single URL from editor screen or central dashboard.
	 *
	 * Enforces server-side authorization:
	 * - Requires edit capability for specific content item.
	 * - Requires admin capability for arbitrary URL scans.
	 * - Verifies URL / post-ID relationship and rejects mismatched IDs.
	 * - Enforces content-type eligibility and excludes drafts/private items.
	 * - Centralized strict URL validation with same-site boundaries.
	 */
	public function ajax_scan_url() {
		check_ajax_referer( 'crawlory_admin_nonce', 'nonce' );

		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		// 1. Authorization checks.
		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to audit this content item.', 'crawlory-technical-seo-dashboard' ) ), 403 );
			}
		} else {
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'crawlory_manage_seo_audit' ) ) {
				wp_send_json_error( array( 'message' => __( 'Arbitrary URL scanning requires administrative privileges.', 'crawlory-technical-seo-dashboard' ) ), 403 );
			}
		}

		if ( empty( $url ) && $post_id > 0 ) {
			$url = get_permalink( $post_id );
		}

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid URL provided.', 'crawlory-technical-seo-dashboard' ) ), 400 );
		}

		// 2. Centralized URL validation.
		$val = Crawlory_Fetcher::validate_audit_url( $url, true );
		if ( ! $val['valid'] ) {
			wp_send_json_error( array( 'message' => $val['error'] ), 400 );
		}

		// 3. Verify post ID and URL relationship.
		$post = null;
		if ( $post_id > 0 ) {
			$expected_permalink = get_permalink( $post_id );
			if ( empty( $expected_permalink ) || untrailingslashit( $expected_permalink ) !== untrailingslashit( $url ) ) {
				wp_send_json_error( array( 'message' => __( 'Submitted post ID and URL do not match.', 'crawlory-technical-seo-dashboard' ) ), 400 );
			}

			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
				wp_send_json_error( array( 'message' => __( 'Only published, public, non-password-protected content can be audited.', 'crawlory-technical-seo-dashboard' ) ), 400 );
			}

			$eligible_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );
			if ( ! in_array( $post->post_type, $eligible_types, true ) ) {
				wp_send_json_error( array( 'message' => __( 'This post type is excluded from SEO auditing by configuration.', 'crawlory-technical-seo-dashboard' ) ), 400 );
			}
		} else {
			// Resolve URL to post_id if possible to enforce capability if mapped.
			$resolved_id = url_to_postid( $url );
			if ( $resolved_id > 0 ) {
				if ( ! current_user_can( 'edit_post', $resolved_id ) && ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( array( 'message' => __( 'You do not have permission to audit the resolved content item.', 'crawlory-technical-seo-dashboard' ) ), 403 );
				}
				$post_id = $resolved_id;
				$post    = get_post( $resolved_id );
			}
		}

		// Check configured URL exclusions.
		if ( $this->scanner->is_url_excluded( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'This URL matches configured audit exclusion patterns.', 'crawlory-technical-seo-dashboard' ) ), 400 );
		}

		// Perform scan.
		$result = $this->scanner->rescan_single_url( $url, $post_id );

		// Fetch fresh page and findings data.
		$page_record = $this->db->get_page_by_url( $url );
		$findings    = $page_record ? $this->db->get_findings_for_page( $page_record->id ) : array();

		// Generate updated panel HTML so UI can update in-place without page reload.
		$panel_html = '';
		if ( $post ) {
			$panel_html = $this->render_panel_inner( $post, $page_record, $findings, $url );
		}

		wp_send_json_success(
			array(
				'message'     => ! empty( $result['success'] ) ? __( 'URL audit complete.', 'crawlory-technical-seo-dashboard' ) : __( 'Scan attempt recorded.', 'crawlory-technical-seo-dashboard' ),
				'success'     => true,
				'page_record' => $page_record,
				'findings'    => $findings,
				'html'        => $panel_html,
				'scan_result' => $result,
			)
		);
	}
}
