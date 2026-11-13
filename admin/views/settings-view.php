<?php
/**
 * Settings View Template for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$enabled_types = (array) $settings->get( 'post_types', array( 'page', 'post', 'product' ) );
$rules_config  = (array) $settings->get( 'rules', array() );
?>
<div class="wrap crawlory-settings-wrap">
	<header class="crawlory-header">
		<div class="crawlory-header-left">
			<h1 class="crawlory-title">
				<a href="https://crawlory.app" target="_blank" rel="noopener noreferrer" class="crawlory-brand-badge" title="Crawlory.app">
					<span class="dashicons dashicons-admin-generic crawlory-brand-icon"></span>
					<span class="crawlory-brand-name">Crawlory</span><span class="crawlory-brand-tld">.app</span>
				</a>
				<span class="crawlory-title-tagline"><?php esc_html_e( 'Settings & Scope', 'crawlory-technical-seo-dashboard' ); ?></span>
			</h1>
			<p class="crawlory-subtitle">
				<?php esc_html_e( 'Configure audit scope, editorial thresholds, rule severities, and scan frequency.', 'crawlory-technical-seo-dashboard' ); ?>
			</p>
		</div>
		<div class="crawlory-header-right">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=crawlory' ) ); ?>" class="button crawlory-btn-secondary">
				<span class="dashicons dashicons-arrow-left-alt"></span>
				<?php esc_html_e( 'Back to Dashboard', 'crawlory-technical-seo-dashboard' ); ?>
			</a>
		</div>
	</header>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display flag from redirect; performs no state modification.
	$crawlory_settings_updated = ( isset( $_GET['settings-updated'] ) && is_scalar( $_GET['settings-updated'] ) ) ? sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display flag from redirect; performs no state modification.
	$crawlory_cleared_param    = ( isset( $_GET['cleared'] ) && is_scalar( $_GET['cleared'] ) ) ? sanitize_text_field( wp_unslash( $_GET['cleared'] ) ) : null;
	?>

	<?php if ( ! empty( $crawlory_settings_updated ) ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
			<p><?php esc_html_e( 'Settings saved successfully.', 'crawlory-technical-seo-dashboard' ); ?></p>
		</div>
	<?php endif; ?>
	<?php if ( '1' === $crawlory_cleared_param ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
			<p><?php esc_html_e( 'All audit records, queues, and caches cleared successfully.', 'crawlory-technical-seo-dashboard' ); ?></p>
		</div>
	<?php elseif ( '0' === $crawlory_cleared_param ) : ?>
		<div class="notice notice-error is-dismissible" style="margin-bottom: 20px;">
			<p><?php esc_html_e( 'Failed to clear all audit records. Please verify database permissions and try again.', 'crawlory-technical-seo-dashboard' ); ?></p>
		</div>
	<?php endif; ?>

	<form id="crawlory-settings-form" class="crawlory-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'crawlory_admin_nonce', '_crawlory_nonce' ); ?>
		<input type="hidden" name="action" value="crawlory_save_settings_post" />
		<input type="hidden" name="page" value="crawlory-settings" />

		<!-- Section 1: Content Coverage Scope -->
		<div class="crawlory-card crawlory-settings-card">
			<h2 class="crawlory-settings-section-title">
				<span class="dashicons dashicons-admin-page"></span>
				<?php esc_html_e( '1. Content Coverage & Scope', 'crawlory-technical-seo-dashboard' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Select the publicly viewable post types to include in scans. Drafts, password-protected posts, attachments, and revisions are always safely excluded.', 'crawlory-technical-seo-dashboard' ); ?>
			</p>

			<div class="crawlory-checkbox-grid">
				<?php foreach ( $all_post_types as $pt_name => $pt_obj ) : ?>
					<?php
					if ( in_array( $pt_name, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' ), true ) ) {
						continue;
					}
					$checked   = in_array( $pt_name, $enabled_types, true );
					$is_portf  = Crawlory_Scanner::is_portfolio_post_type( $pt_name );
					$badge_lbl = '';
					if ( 'product' === $pt_name ) {
						$badge_lbl = 'WooCommerce';
					} elseif ( $is_portf ) {
						$badge_lbl = 'Portfolio';
					}
					?>
					<label class="crawlory-checkbox-label">
						<input type="checkbox" name="settings[post_types][]" value="<?php echo esc_attr( $pt_name ); ?>" <?php checked( $checked ); ?> />
						<span class="crawlory-pt-name"><?php echo esc_html( $pt_obj->labels->name ); ?></span>
						<span class="crawlory-pt-slug">(<code><?php echo esc_html( $pt_name ); ?></code>)</span>
						<?php if ( $badge_lbl ) : ?>
							<span class="crawlory-pill-badge crawlory-badge-info"><?php echo esc_html( $badge_lbl ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="crawlory-form-row crawlory-mt-4">
				<label class="crawlory-checkbox-label">
					<input type="checkbox" name="settings[include_homepage]" value="1" <?php checked( $settings->get( 'include_homepage', true ) ); ?> />
					<strong><?php esc_html_e( 'Include static/blog homepage', 'crawlory-technical-seo-dashboard' ); ?></strong>
					<span class="description"><?php esc_html_e( 'Scans your homepage as an independent URL (without inventing a post ID when using latest posts).', 'crawlory-technical-seo-dashboard' ); ?></span>
				</label>
			</div>

			<div class="crawlory-form-row crawlory-mt-4">
				<label for="crawlory-excluded-urls"><strong><?php esc_html_e( 'Excluded URLs / Subpaths', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Enter URL patterns to exclude from scans, one per line (e.g. /cart, /checkout, /my-account).', 'crawlory-technical-seo-dashboard' ); ?></p>
				<textarea id="crawlory-excluded-urls" name="settings[excluded_urls]" rows="3" class="large-text code"><?php echo esc_textarea( $settings->get( 'excluded_urls', '' ) ); ?></textarea>
			</div>
		</div>

		<!-- Section 2: Editorial Thresholds -->
		<div class="crawlory-card crawlory-settings-card">
			<h2 class="crawlory-settings-section-title">
				<span class="dashicons dashicons-editor-spellcheck"></span>
				<?php esc_html_e( '2. Editorial & Policy Length Thresholds', 'crawlory-technical-seo-dashboard' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Configure boundary character counts for titles, meta descriptions, and absolute URL lengths.', 'crawlory-technical-seo-dashboard' ); ?>
			</p>

			<div class="crawlory-grid-3">
				<div class="crawlory-input-group">
					<label for="crawlory-title-min"><strong><?php esc_html_e( 'Title Minimum Characters', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-title-min" name="settings[title_min_length]" value="<?php echo esc_attr( $settings->get( 'title_min_length', 30 ) ); ?>" min="1" max="100" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 30 chars (Rule 7)', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-title-max"><strong><?php esc_html_e( 'Title Maximum Characters', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-title-max" name="settings[title_max_length]" value="<?php echo esc_attr( $settings->get( 'title_max_length', 60 ) ); ?>" min="20" max="200" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 60 chars (Rule 1)', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-url-max"><strong><?php esc_html_e( 'URL Maximum Characters', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-url-max" name="settings[url_max_length]" value="<?php echo esc_attr( $settings->get( 'url_max_length', 115 ) ); ?>" min="30" max="500" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 115 chars (Rule 13)', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-desc-min"><strong><?php esc_html_e( 'Description Minimum Characters', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-desc-min" name="settings[desc_min_length]" value="<?php echo esc_attr( $settings->get( 'desc_min_length', 70 ) ); ?>" min="1" max="150" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 70 chars (Rule 16)', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-desc-max"><strong><?php esc_html_e( 'Description Maximum Characters', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-desc-max" name="settings[desc_max_length]" value="<?php echo esc_attr( $settings->get( 'desc_max_length', 155 ) ); ?>" min="50" max="350" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 155 chars (Rule 4)', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>
			</div>
		</div>

		<!-- Section 3: 19 SEO Rules Configuration -->
		<div class="crawlory-card crawlory-settings-card">
			<h2 class="crawlory-settings-section-title">
				<span class="dashicons dashicons-yes"></span>
				<?php esc_html_e( '3. SEO Rule Checks & Severity Levels', 'crawlory-technical-seo-dashboard' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Enable or disable individual checks and customize the reported severity level (Error, Warning, or Informational).', 'crawlory-technical-seo-dashboard' ); ?>
			</p>

			<table class="widefat striped crawlory-rules-table">
				<thead>
					<tr>
						<th style="width: 50px;"><?php esc_html_e( '#', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th style="width: 70px;"><?php esc_html_e( 'Active', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th><?php esc_html_e( 'Finding Rule & Classification', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th style="width: 140px;"><?php esc_html_e( 'Category', 'crawlory-technical-seo-dashboard' ); ?></th>
						<th style="width: 150px;"><?php esc_html_e( 'Severity', 'crawlory-technical-seo-dashboard' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rule_definitions as $rid => $rdef ) : ?>
						<?php
						$is_enabled = isset( $rules_config[ $rid ]['enabled'] ) ? (bool) $rules_config[ $rid ]['enabled'] : true;
						$cur_sev    = isset( $rules_config[ $rid ]['severity'] ) ? $rules_config[ $rid ]['severity'] : $rdef['default_sev'];
						?>
						<tr>
							<td><strong><?php echo (int) $rid; ?></strong></td>
							<td>
								<input type="checkbox" name="settings[rules][<?php echo (int) $rid; ?>][enabled]" value="1" <?php checked( $is_enabled ); ?> />
							</td>
							<td>
								<strong><?php echo esc_html( $rdef['name'] ); ?></strong>
								<p class="description"><?php echo esc_html( $rdef['description'] ); ?></p>
							</td>
							<td>
								<span class="crawlory-category-badge crawlory-cat-<?php echo esc_attr( $rdef['category'] ); ?>">
									<?php echo esc_html( ucfirst( str_replace( '_', ' ', $rdef['category'] ) ) ); ?>
								</span>
							</td>
							<td>
								<select name="settings[rules][<?php echo (int) $rid; ?>][severity]" class="crawlory-select-sm">
									<option value="error" <?php selected( 'error', $cur_sev ); ?>><?php esc_html_e( 'Error', 'crawlory-technical-seo-dashboard' ); ?></option>
									<option value="warning" <?php selected( 'warning', $cur_sev ); ?>><?php esc_html_e( 'Warning', 'crawlory-technical-seo-dashboard' ); ?></option>
									<option value="info" <?php selected( 'info', $cur_sev ); ?>><?php esc_html_e( 'Info', 'crawlory-technical-seo-dashboard' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Section 4: Performance, Concurrency & Rate Limiting -->
		<div class="crawlory-card crawlory-settings-card">
			<h2 class="crawlory-settings-section-title">
				<span class="dashicons dashicons-performance"></span>
				<?php esc_html_e( '4. Scanner Performance, Batching & Rate Limiting', 'crawlory-technical-seo-dashboard' ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Fine-tune background execution parameters to ensure optimal performance on shared hosting or high-traffic environments.', 'crawlory-technical-seo-dashboard' ); ?>
			</p>

			<div class="crawlory-grid-3">
				<div class="crawlory-input-group">
					<label for="crawlory-batch-size"><strong><?php esc_html_e( 'Batch Size (URLs per request)', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-batch-size" name="settings[batch_size]" value="<?php echo esc_attr( $settings->get( 'batch_size', 20 ) ); ?>" min="1" max="100" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 20 URLs', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-request-delay"><strong><?php esc_html_e( 'Delay Between Fetches (ms)', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-request-delay" name="settings[request_delay_ms]" value="<?php echo esc_attr( $settings->get( 'request_delay_ms', 150 ) ); ?>" min="0" max="3000" step="50" class="small-text" />
					<span class="description"><?php esc_html_e( 'Prevents server CPU spikes. Default: 150ms', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-timeout"><strong><?php esc_html_e( 'HTTP Timeout (seconds)', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-timeout" name="settings[request_timeout]" value="<?php echo esc_attr( $settings->get( 'request_timeout', 15 ) ); ?>" min="5" max="60" class="small-text" />
					<span class="description"><?php esc_html_e( 'Default: 15 seconds', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-scan-schedule"><strong><?php esc_html_e( 'Automated Scan Schedule', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<select id="crawlory-scan-schedule" name="settings[scan_schedule]" class="crawlory-select">
						<option value="disabled" <?php selected( 'disabled', $settings->get( 'scan_schedule', 'disabled' ) ); ?>><?php esc_html_e( 'Disabled (Manual On-Demand Only)', 'crawlory-technical-seo-dashboard' ); ?></option>
						<option value="daily" <?php selected( 'daily', $settings->get( 'scan_schedule' ) ); ?>><?php esc_html_e( 'Daily Automated Scan', 'crawlory-technical-seo-dashboard' ); ?></option>
						<option value="weekly" <?php selected( 'weekly', $settings->get( 'scan_schedule' ) ); ?>><?php esc_html_e( 'Weekly Automated Scan', 'crawlory-technical-seo-dashboard' ); ?></option>
						<option value="monthly" <?php selected( 'monthly', $settings->get( 'scan_schedule' ) ); ?>><?php esc_html_e( 'Monthly Automated Scan', 'crawlory-technical-seo-dashboard' ); ?></option>
					</select>
					<span class="description"><?php esc_html_e( 'Runs in background via WP-Cron.', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label for="crawlory-retention-days"><strong><?php esc_html_e( 'Data Retention (Days)', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<input type="number" id="crawlory-retention-days" name="settings[data_retention_days]" value="<?php echo esc_attr( $settings->get( 'data_retention_days', 90 ) ); ?>" min="0" max="730" class="small-text" />
					<span class="description"><?php esc_html_e( 'Prunes data older than N days. 0 = indefinite.', 'crawlory-technical-seo-dashboard' ); ?></span>
				</div>

				<div class="crawlory-input-group">
					<label><strong><?php esc_html_e( 'Plugin Uninstall Data Cleanup', 'crawlory-technical-seo-dashboard' ); ?></strong></label>
					<label class="crawlory-checkbox-label">
						<input type="checkbox" name="settings[purge_on_uninstall]" value="1" <?php checked( $settings->get( 'purge_on_uninstall', false ) ); ?> />
						<span><?php esc_html_e( 'Drop all custom database tables and purge all settings when this plugin is deleted.', 'crawlory-technical-seo-dashboard' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<!-- Submit Button -->
		<div class="crawlory-settings-actions">
			<button type="submit" id="crawlory-btn-save-settings" class="button button-primary button-hero">
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( 'Save Settings', 'crawlory-technical-seo-dashboard' ); ?>
			</button>
			<span id="crawlory-settings-save-notice" class="crawlory-save-notice" style="display: none;"></span>
		</div>
	</form>

	<!-- Section 5: Reset & Maintenance -->
	<div class="crawlory-card crawlory-settings-card crawlory-danger-zone-card" style="margin-top: 24px; border-top: 3px solid #ef4444; background: #fff;">
		<h2 class="crawlory-settings-section-title" style="color: #b91c1c; display: flex; align-items: center; gap: 8px;">
			<span class="dashicons dashicons-trash"></span>
			<?php esc_html_e( '5. Reset & Data Maintenance', 'crawlory-technical-seo-dashboard' ); ?>
		</h2>
		<p class="description" style="color: #4b5563; line-height: 1.5; margin-bottom: 12px;">
			<?php esc_html_e( 'Permanently wipe all stored Crawlory findings, scanned-page records, duplicate groups, discovered links, sitemap audit records, scan queues, and resource caches. Existing WordPress content, posts, pages, WooCommerce products, media, and Crawlory settings are completely preserved.', 'crawlory-technical-seo-dashboard' ); ?>
		</p>
		<form id="crawlory-clear-data-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px;">
			<?php wp_nonce_field( 'crawlory_admin_nonce', '_crawlory_nonce' ); ?>
			<input type="hidden" name="action" value="crawlory_clear_all_data_post" />
			<input type="hidden" name="page" value="technical-seo-dashboard-settings" />
			
			<div style="margin-bottom: 16px;">
				<label style="display: flex; align-items: flex-start; gap: 8px; font-weight: 500; color: #374151; cursor: pointer;">
					<input type="checkbox" id="crawlory-confirm-clear-checkbox" name="confirm_clear" value="1" required style="margin-top: 3px;" />
					<span><?php esc_html_e( 'I understand this action is irreversible and will permanently delete all scan history, findings, and cached resource verifications.', 'crawlory-technical-seo-dashboard' ); ?></span>
				</label>
			</div>

			<button type="submit" id="crawlory-btn-clear-all-data" class="button crawlory-btn-danger">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear All Audit Data', 'crawlory-technical-seo-dashboard' ); ?>
			</button>
			<span id="crawlory-clear-data-notice" class="crawlory-save-notice" style="display: none; margin-left: 12px;"></span>
		</form>
	</div>
</div>
