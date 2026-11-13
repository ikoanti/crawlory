<?php
/**
 * Dashboard View Template for Crawlory.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap crawlory-dashboard-wrap">
	<header class="crawlory-header">
		<div class="crawlory-header-left">
			<h1 class="crawlory-title">
				<a href="https://crawlory.app" target="_blank" rel="noopener noreferrer" class="crawlory-brand-badge" title="Crawlory.app">
					<span class="dashicons dashicons-chart-bar crawlory-brand-icon"></span>
					<span class="crawlory-brand-name">Crawlory</span><span class="crawlory-brand-tld">.app</span>
				</a>
				<span class="crawlory-title-tagline"><?php esc_html_e( 'Technical SEO Dashboard', 'crawlory-technical-seo-dashboard' ); ?></span>
			</h1>
			<p class="crawlory-subtitle">
				<?php esc_html_e( 'Technical SEO audit crawler. Inspects server-rendered HTML across 19 core checks and 14 expanded priority categories.', 'crawlory-technical-seo-dashboard' ); ?>
			</p>
		</div>
		<div class="crawlory-header-right">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=crawlory-settings' ) ); ?>" class="button crawlory-btn-secondary">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php esc_html_e( 'Settings & Thresholds', 'crawlory-technical-seo-dashboard' ); ?>
			</a>
			<button id="crawlory-btn-export-csv" class="button crawlory-btn-secondary" title="<?php esc_attr_e( 'Export currently filtered findings to CSV', 'crawlory-technical-seo-dashboard' ); ?>">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'Export CSV', 'crawlory-technical-seo-dashboard' ); ?>
			</button>
		</div>
	</header>

	<!-- Scope Notice -->
	<div class="crawlory-notice crawlory-notice-info">
		<div class="crawlory-notice-icon"><span class="dashicons dashicons-info"></span></div>
		<div class="crawlory-notice-content">
			<strong><?php esc_html_e( 'Audit Scope & Visitor-Mode Notice:', 'crawlory-technical-seo-dashboard' ); ?></strong>
			<?php esc_html_e( ' Crawlory fetches public server-rendered HTML as delivered to search engine crawlers without administrative sessions or cookies. Drafts and unsaved editor changes are not included in public scans.', 'crawlory-technical-seo-dashboard' ); ?>
		</div>
	</div>

	<?php
	$interrupted_scan = $this->db->get_interrupted_scan();
	if ( $interrupted_scan ) :
	?>
	<!-- Interrupted Scan Migration/Recovery Banner -->
	<div class="crawlory-notice crawlory-notice-warning" id="crawlory-interrupted-recovery-notice" style="background:#fffbe5; border-left:4px solid #dba617; padding:12px; margin-bottom:15px; border-radius:4px;">
		<div style="display:flex; align-items:flex-start; gap:10px;">
			<span class="dashicons dashicons-warning" style="color:#dba617; font-size:24px; width:24px; height:24px;"></span>
			<div>
				<strong><?php esc_html_e( 'Interrupted Scan Detected (v1.2.0 Upgrade / Safe Recovery):', 'crawlory-technical-seo-dashboard' ); ?></strong>
				<p style="margin:4px 0 8px 0;">
					<?php
					/* translators: 1: Scan ID number, 2: Number of scanned URLs, 3: Total URLs to scan. */
					printf( esc_html__( 'Scan #%1$d stopped before completing full coverage (%2$d of %3$d URLs audited). Incomplete legacy cursors have been safely preserved so no unverified URLs are marked complete. You can resume scanning the remaining items or run a fresh scan.', 'crawlory-technical-seo-dashboard' ), (int) $interrupted_scan->id, (int) $interrupted_scan->scanned_urls, (int) $interrupted_scan->total_urls );
					?>
				</p>
				<button type="button" class="button button-primary button-small crawlory-action-resume-interrupted" data-scan-id="<?php echo esc_attr( $interrupted_scan->id ); ?>">
					<?php esc_html_e( 'Resume Interrupted Scan', 'crawlory-technical-seo-dashboard' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Scan Control Bar -->
	<div class="crawlory-card crawlory-scanner-panel">
		<div class="crawlory-scanner-controls">
			<div class="crawlory-scanner-actions">
				<button id="crawlory-btn-start-scan" class="button button-primary button-hero">
					<span class="dashicons dashicons-controls-play"></span>
					<span class="btn-text"><?php esc_html_e( 'Run Full Site Scan', 'crawlory-technical-seo-dashboard' ); ?></span>
				</button>

				<div class="crawlory-scan-type-dropdown">
					<select id="crawlory-scan-post-type" class="crawlory-select">
						<option value=""><?php esc_html_e( 'All Configured Content Types', 'crawlory-technical-seo-dashboard' ); ?></option>
						<?php foreach ( $post_types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>">
								<?php echo esc_html( $pt->labels->singular_name ? $pt->labels->singular_name : $pt->name ); ?> (<?php echo esc_html( $pt->name ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<button id="crawlory-btn-start-type-scan" class="button crawlory-btn-secondary">
						<?php esc_html_e( 'Scan Selected Type', 'crawlory-technical-seo-dashboard' ); ?>
					</button>
				</div>
			</div>

			<div class="crawlory-scanner-state-buttons" style="display: none;">
				<button id="crawlory-btn-pause-scan" class="button crawlory-btn-warning">
					<span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e( 'Pause', 'crawlory-technical-seo-dashboard' ); ?>
				</button>
				<button id="crawlory-btn-resume-scan" class="button button-primary" style="display: none;">
					<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Resume', 'crawlory-technical-seo-dashboard' ); ?>
				</button>
				<button id="crawlory-btn-cancel-scan" class="button crawlory-btn-danger">
					<span class="dashicons dashicons-no"></span> <?php esc_html_e( 'Cancel', 'crawlory-technical-seo-dashboard' ); ?>
				</button>
			</div>
		</div>

		<!-- Progress Bar Container -->
		<div id="crawlory-progress-container" class="crawlory-progress-container" style="display: none;">
			<div class="crawlory-progress-meta">
				<span id="crawlory-progress-label" class="crawlory-progress-label"><?php esc_html_e( 'Initializing scan...', 'crawlory-technical-seo-dashboard' ); ?></span>
				<span id="crawlory-progress-percentage" class="crawlory-progress-percentage">0%</span>
			</div>
			<div class="crawlory-progress-track">
				<div id="crawlory-progress-fill" class="crawlory-progress-fill" style="width: 0%;"></div>
			</div>
			<div class="crawlory-progress-stats">
				<span><?php esc_html_e( 'Scanned:', 'crawlory-technical-seo-dashboard' ); ?> <strong id="crawlory-stat-scanned-count">0</strong> / <strong id="crawlory-stat-total-target">0</strong></span>
				<span><?php esc_html_e( 'Failed:', 'crawlory-technical-seo-dashboard' ); ?> <strong id="crawlory-stat-failed-count" class="crawlory-text-danger">0</strong></span>
				<span><?php esc_html_e( 'Findings Discovered:', 'crawlory-technical-seo-dashboard' ); ?> <strong id="crawlory-stat-findings-count" class="crawlory-text-warning">0</strong></span>
			</div>

			<!-- Diagnostic Error & Troubleshooting Container -->
			<div id="crawlory-diagnostic-box" class="crawlory-diagnostic-box" style="display: none; margin-top: 15px; padding: 12px; background: #fff5f5; border: 1px solid #ffccd2; border-left: 4px solid #d63638; border-radius: 4px;">
				<div style="display: flex; justify-content: space-between; align-items: center;">
					<strong id="crawlory-diag-title" class="crawlory-text-danger" style="font-size: 13px;"></strong>
					<button type="button" id="crawlory-btn-toggle-diag" class="button button-small" style="margin-left: 10px;"><?php esc_html_e( 'Diagnostics', 'crawlory-technical-seo-dashboard' ); ?></button>
				</div>
				<p id="crawlory-diag-summary" style="margin: 6px 0 0 0; color: #444; font-size: 12px;"></p>
				<div id="crawlory-diag-details" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e0b4b6; font-size: 11px; font-family: monospace; color: #333;">
					<table style="width: 100%; border-collapse: collapse;">
						<tr><td style="width: 140px; font-weight: bold;"><?php esc_html_e( 'Scan ID:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-scan-id">-</td></tr>
						<tr><td style="font-weight: bold;"><?php esc_html_e( 'Operation:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-op">-</td></tr>
						<tr><td style="font-weight: bold;"><?php esc_html_e( 'HTTP Status:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-http-status">-</td></tr>
						<tr><td style="font-weight: bold;"><?php esc_html_e( 'Last Progress:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-last-progress">-</td></tr>
						<tr><td style="font-weight: bold;"><?php esc_html_e( 'Stage:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-stage">-</td></tr>
						<tr><td style="font-weight: bold;"><?php esc_html_e( 'Recovery Action:', 'crawlory-technical-seo-dashboard' ); ?></td><td id="crawlory-diag-action" style="color: #0073aa; font-family: sans-serif;">-</td></tr>
					</table>
				</div>
			</div>
		</div>
	</div>

	<!-- Single URL Direct Audit Panel (Requirement 4) -->
	<div class="crawlory-card crawlory-single-url-panel">
		<div class="crawlory-single-url-header">
			<span class="dashicons dashicons-admin-site-alt3"></span>
			<strong><?php esc_html_e( 'Scan a Specific URL', 'crawlory-technical-seo-dashboard' ); ?></strong>
			<span class="crawlory-subtitle-hint"><?php esc_html_e( 'Audit any public URL on this site immediately without completing a full-site scan.', 'crawlory-technical-seo-dashboard' ); ?></span>
		</div>
		<form id="crawlory-form-single-url-scan" class="crawlory-single-url-form">
			<div class="crawlory-single-url-input-wrap">
				<input type="url" id="crawlory-input-single-url" class="crawlory-input" placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) ); ?>" required />
				<button type="submit" id="crawlory-btn-scan-single-url" class="button button-primary">
					<span class="dashicons dashicons-search"></span>
					<span><?php esc_html_e( 'Scan URL', 'crawlory-technical-seo-dashboard' ); ?></span>
				</button>
			</div>
			<div id="crawlory-single-url-status" class="crawlory-single-url-status" style="display:none;"></div>
		</form>
	</div>

	<!-- Coverage & Provisional Checks Disclosure (Requirement 5) -->
	<div class="crawlory-card crawlory-coverage-banner" id="crawlory-coverage-banner" style="display: none;">
		<div class="crawlory-coverage-content">
			<span class="dashicons dashicons-chart-pie"></span>
			<span id="crawlory-coverage-text"><?php esc_html_e( 'Scan coverage: Calculating...', 'crawlory-technical-seo-dashboard' ); ?></span>
			<span class="crawlory-coverage-provisional-note">
				<strong><?php esc_html_e( 'Provisional Check Notice:', 'crawlory-technical-seo-dashboard' ); ?></strong>
				<?php esc_html_e( ' Duplicate metadata, potential orphan pages, and sitemap comparisons require sitewide data. When coverage is partial, conclusions are provisional. A check is never marked passed solely because other pages have not yet been scanned.', 'crawlory-technical-seo-dashboard' ); ?>
			</span>
		</div>
	</div>

	<!-- Summary Cards -->
	<div class="crawlory-stats-grid">
		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-status="">
			<div class="crawlory-stat-icon crawlory-icon-blue"><span class="dashicons dashicons-admin-links"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-total-urls">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Eligible URLs', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-status="success">
			<div class="crawlory-stat-icon crawlory-icon-green"><span class="dashicons dashicons-yes-alt"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-scanned-urls">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Successfully Scanned', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-has-issues="1">
			<div class="crawlory-stat-icon crawlory-icon-orange"><span class="dashicons dashicons-flag"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-urls-with-findings">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'URLs With Findings', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-severity="error">
			<div class="crawlory-stat-icon crawlory-icon-purple"><span class="dashicons dashicons-warning"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-total-findings">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Active Findings', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-status="failed">
			<div class="crawlory-stat-icon crawlory-icon-red"><span class="dashicons dashicons-dismiss"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-failed-scans">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Failed Scans', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-card-clickable" data-filter-status="stale">
			<div class="crawlory-stat-icon crawlory-icon-gray"><span class="dashicons dashicons-update"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number" id="card-pending-stale">-</div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Pending / Stale', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>

		<div class="crawlory-stat-card crawlory-stat-card-meta">
			<div class="crawlory-stat-icon crawlory-icon-teal"><span class="dashicons dashicons-clock"></span></div>
			<div class="crawlory-stat-details">
				<div class="crawlory-stat-number crawlory-stat-date" id="card-last-scan-time"><?php esc_html_e( 'Never', 'crawlory-technical-seo-dashboard' ); ?></div>
				<div class="crawlory-stat-title"><?php esc_html_e( 'Last Completed Scan', 'crawlory-technical-seo-dashboard' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Change-Tracking & Lifecycle Tabs (Requirement 6) -->
	<div class="crawlory-card crawlory-lifecycle-tabs-card">
		<div class="crawlory-lifecycle-tabs" id="crawlory-lifecycle-tabs">
			<button type="button" class="crawlory-tab-btn active" data-lifecycle=""><?php esc_html_e( 'All Active Findings', 'crawlory-technical-seo-dashboard' ); ?></button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="new">
				<?php esc_html_e( 'New', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-new" id="tab-count-new">0</span>
			</button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="persistent">
				<?php esc_html_e( 'Persistent', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-persistent" id="tab-count-persistent">0</span>
			</button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="resolved">
				<?php esc_html_e( 'Resolved', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-resolved" id="tab-count-resolved">0</span>
			</button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="ignored">
				<?php esc_html_e( 'Ignored', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-ignored" id="tab-count-ignored">0</span>
			</button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="stale">
				<?php esc_html_e( 'Stale Scans', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-stale" id="tab-count-stale">0</span>
			</button>
			<button type="button" class="crawlory-tab-btn" data-lifecycle="failed">
				<?php esc_html_e( 'Failed Scans', 'crawlory-technical-seo-dashboard' ); ?>
				<span class="crawlory-tab-badge badge-failed" id="tab-count-failed">0</span>
			</button>
		</div>
	</div>

	<!-- Rule Quick-Pills Filter Bar -->
	<div class="crawlory-card crawlory-rule-pills-container">
		<div class="crawlory-pills-header">
			<span class="dashicons dashicons-filter"></span>
			<strong><?php esc_html_e( 'Filter by SEO Finding Rule:', 'crawlory-technical-seo-dashboard' ); ?></strong>
			<button id="crawlory-btn-clear-rule-pill" class="crawlory-link-btn" style="display: none;">
				<?php esc_html_e( 'Clear Finding Filter', 'crawlory-technical-seo-dashboard' ); ?>
			</button>
		</div>
		<div class="crawlory-rule-pills" id="crawlory-rule-pills-list">
			<!-- Rendered dynamically by JS with counts -->
		</div>
	</div>

	<!-- Main Filter & Search Toolbar -->
	<div class="crawlory-card crawlory-toolbar">
		<div class="crawlory-toolbar-row">
			<div class="crawlory-search-box">
				<span class="dashicons dashicons-search"></span>
				<input type="text" id="crawlory-filter-search" class="crawlory-input" placeholder="<?php esc_attr_e( 'Search by URL, page title, or post ID...', 'crawlory-technical-seo-dashboard' ); ?>" />
			</div>

			<div class="crawlory-filter-controls">
				<!-- Post Type Filter -->
				<select id="crawlory-filter-post-type" class="crawlory-select">
					<option value=""><?php esc_html_e( 'All Content Types', 'crawlory-technical-seo-dashboard' ); ?></option>
					<?php foreach ( $post_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt->name ); ?>">
							<?php echo esc_html( $pt->labels->singular_name ? $pt->labels->singular_name : $pt->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Severity Filter -->
				<select id="crawlory-filter-severity" class="crawlory-select">
					<option value=""><?php esc_html_e( 'All Severities', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="error"><?php esc_html_e( 'Errors Only', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="warning"><?php esc_html_e( 'Warnings Only', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="info"><?php esc_html_e( 'Review Items Only', 'crawlory-technical-seo-dashboard' ); ?></option>
				</select>

				<!-- Scan Status Filter -->
				<select id="crawlory-filter-scan-status" class="crawlory-select">
					<option value=""><?php esc_html_e( 'All Scan Statuses', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="success"><?php esc_html_e( 'Success (Scanned)', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="stale"><?php esc_html_e( 'Stale (Modified)', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed (HTTP/Fetch Error)', 'crawlory-technical-seo-dashboard' ); ?></option>
				</select>

				<!-- Per Page -->
				<select id="crawlory-filter-per-page" class="crawlory-select">
					<option value="10">10 <?php esc_html_e( 'per page', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="20" selected>20 <?php esc_html_e( 'per page', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="50">50 <?php esc_html_e( 'per page', 'crawlory-technical-seo-dashboard' ); ?></option>
					<option value="100">100 <?php esc_html_e( 'per page', 'crawlory-technical-seo-dashboard' ); ?></option>
				</select>

				<button id="crawlory-btn-reset-filters" class="button crawlory-btn-secondary" title="<?php esc_attr_e( 'Reset all filters', 'crawlory-technical-seo-dashboard' ); ?>">
					<span class="dashicons dashicons-undo"></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Results Table Card -->
	<div class="crawlory-card crawlory-table-card">
		<div class="crawlory-table-header-info">
			<div id="crawlory-results-count" class="crawlory-results-count">
				<?php esc_html_e( 'Loading audit results...', 'crawlory-technical-seo-dashboard' ); ?>
			</div>
			<div class="crawlory-active-filter-badge" id="crawlory-active-filter-badge" style="display: none;"></div>
		</div>

		<div class="crawlory-table-responsive">
			<table class="wp-list-table widefat fixed striped crawlory-table" id="crawlory-audit-table">
				<thead>
					<tr>
						<th class="crawlory-col-title" data-sort="post_title"><?php esc_html_e( 'Content Title & Type', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-url" data-sort="url"><?php esc_html_e( 'Public URL', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-seo-title" data-sort="seo_title_length"><?php esc_html_e( 'SEO Title', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-desc" data-sort="meta_description_length"><?php esc_html_e( 'Meta Description', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-h1" data-sort="h1_count"><?php esc_html_e( 'H1', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-images"><?php esc_html_e( 'Image Alt Issues', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-findings" data-sort="findings_count"><?php esc_html_e( 'Findings', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-time" data-sort="last_scanned_at"><?php esc_html_e( 'Last Scanned', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th class="crawlory-col-actions"><?php esc_html_e( 'Actions', 'crawlory-technical-seo-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody id="crawlory-table-body">
					<tr>
						<td colspan="9" class="crawlory-table-loading">
							<span class="spinner is-active"></span> <?php esc_html_e( 'Loading audit data...', 'crawlory-technical-seo-dashboard' ); ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Pagination Footer -->
		<div class="crawlory-pagination" id="crawlory-pagination" style="display: none;">
			<div class="crawlory-pagination-info" id="crawlory-pagination-info"></div>
			<div class="crawlory-pagination-buttons" id="crawlory-pagination-buttons"></div>
		</div>
	</div>
</div>
