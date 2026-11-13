<?php
/**
 * Inspect Drawer / Modal View for Technical SEO Dashboard.
 *
 * This partial is the ONLY place the Inspect drawer markup is rendered.
 * Do not duplicate these IDs in dashboard-view.php (see tests/test-inspect-drawer.php).
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Inspection Slide-over Drawer / Modal -->
<div id="crawlory-inspect-drawer-backdrop" class="crawlory-drawer-backdrop" style="display: none;" aria-hidden="true"></div>

<div id="crawlory-inspect-drawer" class="crawlory-drawer" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="crawlory-drawer-page-title" aria-describedby="crawlory-drawer-page-url" tabindex="-1">
	<div class="crawlory-drawer-header">
		<div class="crawlory-drawer-title-area">
			<div class="crawlory-drawer-brand-row">
				<a href="https://crawlory.app" target="_blank" rel="noopener noreferrer" class="crawlory-brand-badge crawlory-brand-badge-sm" title="Crawlory.app">
					<span class="crawlory-brand-name">Crawlory</span><span class="crawlory-brand-tld">.app</span>
				</a>
				<span class="crawlory-drawer-badge" id="crawlory-drawer-status-badge"></span>
			</div>
			<h2 class="crawlory-drawer-title" id="crawlory-drawer-page-title"><?php esc_html_e( 'Page Audit Findings', 'crawlory-technical-seo-dashboard' ); ?></h2>
			<a id="crawlory-drawer-page-url" class="crawlory-drawer-url" href="#" target="_blank" rel="noopener noreferrer"></a>
		</div>
		<button type="button" id="crawlory-drawer-close" class="crawlory-drawer-close" aria-label="<?php esc_attr_e( 'Close inspection drawer', 'crawlory-technical-seo-dashboard' ); ?>" title="<?php esc_attr_e( 'Close inspection drawer', 'crawlory-technical-seo-dashboard' ); ?>">
			<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
		</button>
	</div>

	<div class="crawlory-drawer-quick-actions">
		<a id="crawlory-drawer-action-view" href="#" target="_blank" rel="noopener noreferrer" class="button crawlory-btn-secondary crawlory-is-hidden">
			<span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'View Public Page', 'crawlory-technical-seo-dashboard' ); ?>
		</a>
		<a id="crawlory-drawer-action-edit" href="#" target="_blank" rel="noopener noreferrer" class="button crawlory-btn-secondary crawlory-is-hidden">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Edit in WordPress', 'crawlory-technical-seo-dashboard' ); ?>
		</a>
		<button type="button" id="crawlory-drawer-action-rescan" class="button button-primary">
			<span class="dashicons dashicons-update" aria-hidden="true"></span> <span id="crawlory-drawer-rescan-label"><?php esc_html_e( 'Rescan URL', 'crawlory-technical-seo-dashboard' ); ?></span>
		</button>
	</div>

	<!-- Rescan / action progress and result messages -->
	<div id="crawlory-drawer-status" class="crawlory-drawer-status crawlory-is-hidden" role="status" aria-live="polite"></div>

	<div class="crawlory-drawer-body" id="crawlory-drawer-body">
		<!-- Active Findings Count Summary (excludes ignored and resolved findings) -->
		<div class="crawlory-drawer-summary-box">
			<div class="crawlory-drawer-summary-item">
				<span class="crawlory-summary-val" id="crawlory-drawer-error-count">&ndash;</span>
				<span class="crawlory-summary-lbl"><?php esc_html_e( 'Errors', 'crawlory-technical-seo-dashboard' ); ?></span>
			</div>
			<div class="crawlory-drawer-summary-item">
				<span class="crawlory-summary-val" id="crawlory-drawer-warning-count">&ndash;</span>
				<span class="crawlory-summary-lbl"><?php esc_html_e( 'Warnings', 'crawlory-technical-seo-dashboard' ); ?></span>
			</div>
			<div class="crawlory-drawer-summary-item">
				<span class="crawlory-summary-val" id="crawlory-drawer-info-count">&ndash;</span>
				<span class="crawlory-summary-lbl"><?php esc_html_e( 'Info Notices', 'crawlory-technical-seo-dashboard' ); ?></span>
			</div>
		</div>
		<p class="crawlory-drawer-history-note" id="crawlory-drawer-history-note"></p>

		<!-- Findings List Accordion -->
		<div id="crawlory-drawer-findings-list" class="crawlory-drawer-findings-list" aria-live="polite" aria-busy="false">
			<div class="crawlory-drawer-loading">
				<span class="spinner is-active"></span> <?php esc_html_e( 'Loading findings...', 'crawlory-technical-seo-dashboard' ); ?>
			</div>
		</div>
	</div>
</div>
