/**
 * Crawlory - Admin & Editor JavaScript
 * Handles live scanning, asynchronous batching, filters, lifecycle tracking,
 * inspect drawer, per-page editor panel, and settings.
 *
 * @package Crawlory
 */

(function($) {
	'use strict';

	var Crawlory = {
		activeScanId: 0,
		isScanning: false,
		isPaused: false,
		currentPage: 1,
		perPage: 20,
		orderBy: 'findings_count',
		order: 'DESC',
		selectedRuleId: 0,
		selectedSeverity: '',
		selectedPostType: '',
		selectedScanStatus: '',
		selectedFindingStatus: '',
		selectedHasIssues: null,
		searchTerm: '',
		ruleMap: {},

		/**
		 * Localized string translation helper.
		 */
		t: function(key, fallback) {
			if (typeof crawloryData !== 'undefined' && crawloryData.i18n && typeof crawloryData.i18n[key] !== 'undefined') {
				return crawloryData.i18n[key];
			}
			return fallback || '';
		},

		/**
		 * Fallback pluralization helper for counts.
		 */
		n: function(count, singularKey, pluralKey, singularFallback, pluralFallback) {
			var num = parseInt(count, 10);
			if (isNaN(num)) {
				num = 0;
			}
			var sing = this.t(singularKey, singularFallback || singularKey);
			var plur = this.t(pluralKey, pluralFallback || pluralKey);
			return (num === 1) ? sing : plur;
		},

		init: function() {
			this.bindEvents();
			this.bindTooltipEvents();
			if ($('#crawlory-audit-table').length) {
				// Parse URL search parameters for pre-filtered views (e.g. from editor panel)
				try {
					var urlParams = new URLSearchParams(window.location.search);
					var filterUrl = urlParams.get('filter_url') || urlParams.get('search');
					if (filterUrl) {
						this.searchTerm = decodeURIComponent(filterUrl);
						$('#crawlory-filter-search').val(this.searchTerm);
					}
				} catch (e) {}

				this.loadDashboardData();
				if (typeof crawloryData !== 'undefined' && crawloryData.activeScanId && crawloryData.activeScanStatus === 'running') {
					this.activeScanId = crawloryData.activeScanId;
					this.resumeScanUI();
				}
			}
			this.bindEditorPanelEvents();
		},

		bindEvents: function() {
			var self = this;

			// Scan button events
			$('#crawlory-btn-start-scan').on('click', function(e) {
				e.preventDefault();
				self.startScan('full');
			});

			$('#crawlory-btn-start-type-scan').on('click', function(e) {
				e.preventDefault();
				var targetType = $('#crawlory-scan-post-type').val();
				if (!targetType) {
					self.startScan('full');
				} else {
					self.startScan('post_type', targetType);
				}
			});

			$('#crawlory-btn-pause-scan').on('click', function(e) {
				e.preventDefault();
				self.pauseScan();
			});

			$('#crawlory-btn-resume-scan').on('click', function(e) {
				e.preventDefault();
				self.resumeScan();
			});

			$('#crawlory-btn-cancel-scan').on('click', function(e) {
				e.preventDefault();
				if (confirm(typeof crawloryData !== 'undefined' && crawloryData.i18n ? crawloryData.i18n.confirmCancel : 'Cancel current scan?')) {
					self.cancelScan();
				}
			});

			// Toggle diagnostics drawer
			$('#crawlory-btn-toggle-diag').on('click', function(e) {
				e.preventDefault();
				$('#crawlory-diag-details').slideToggle(150);
			});

			// Resume interrupted scan
			$(document).on('click', '.crawlory-action-resume-interrupted', function(e) {
				e.preventDefault();
				var scanId = parseInt($(this).data('scan-id'), 10);
				if (scanId > 0) {
					self.resumeInterruptedScan(scanId);
				}
			});

			// Single URL Direct Audit Form (Requirement 4)
			$('#crawlory-form-single-url-scan').on('submit', function(e) {
				e.preventDefault();
				var url = $('#crawlory-input-single-url').val();
				if (!url) return;

				var $btn = $('#crawlory-btn-scan-single-url');
				var $status = $('#crawlory-single-url-status');
				$btn.prop('disabled', true);
				$status.show().html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Auditing public URL...');

				$.ajax({
					url: (typeof crawloryData !== 'undefined' ? crawloryData.ajaxUrl : ajaxurl),
					type: 'POST',
					data: {
						action: 'crawlory_editor_scan_url',
						nonce: (typeof crawloryData !== 'undefined' ? crawloryData.nonce : ''),
						url: url
					},
					success: function(resp) {
						$btn.prop('disabled', false);
						if (resp.success) {
							var p = resp.data.page_record;
							var count = resp.data.findings ? resp.data.findings.length : 0;
							var msg = '<strong>Scan Complete!</strong> ' + count + ' findings recorded.';
							if (p && p.post_id > 0) {
								msg += ' Mapped to WordPress item: <em>' + self.escapeHtml(p.post_title) + '</em>.';
							}
							$status.html('<span class="crawlory-text-success">' + msg + '</span>');
							self.loadDashboardData();
						} else {
							var err = (resp.data && resp.data.message) ? resp.data.message : 'Scan failed.';
							$status.html('<span class="crawlory-text-danger">' + self.escapeHtml(err) + '</span>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$status.html('<span class="crawlory-text-danger">Request error communicating with server.</span>');
					}
				});
			});

			// Finding Lifecycle Tabs (Requirement 6)
			$('#crawlory-lifecycle-tabs').on('click', '.crawlory-tab-btn', function() {
				$('#crawlory-lifecycle-tabs .crawlory-tab-btn').removeClass('active');
				$(this).addClass('active');

				var lifecycle = $(this).data('lifecycle');
				if (lifecycle === 'stale') {
					self.selectedScanStatus = 'stale';
					self.selectedFindingStatus = '';
				} else if (lifecycle === 'failed') {
					self.selectedScanStatus = 'failed';
					self.selectedFindingStatus = '';
				} else {
					self.selectedScanStatus = '';
					self.selectedFindingStatus = lifecycle || '';
				}

				self.currentPage = 1;
				self.loadDashboardData();
			});

			// Filter toolbar events
			$('#crawlory-filter-search').on('keyup', self.debounce(function() {
				self.searchTerm = $(this).val();
				self.currentPage = 1;
				self.loadDashboardData();
			}, 300));

			$('#crawlory-filter-post-type').on('change', function() {
				self.selectedPostType = $(this).val();
				self.currentPage = 1;
				self.loadDashboardData();
			});

			$('#crawlory-filter-severity').on('change', function() {
				self.selectedSeverity = $(this).val();
				self.currentPage = 1;
				self.loadDashboardData();
			});

			$('#crawlory-filter-scan-status').on('change', function() {
				self.selectedScanStatus = $(this).val();
				self.currentPage = 1;
				self.loadDashboardData();
			});

			$('#crawlory-filter-per-page').on('change', function() {
				self.perPage = parseInt($(this).val(), 10);
				self.currentPage = 1;
				self.loadDashboardData();
			});

			$('#crawlory-btn-reset-filters').on('click', function(e) {
				e.preventDefault();
				self.resetFilters();
			});

			// Summary card click filtering
			$('.crawlory-card-clickable').on('click', function() {
				var status = $(this).data('filter-status');
				var severity = $(this).data('filter-severity');
				var hasIssues = $(this).data('filter-has-issues');

				self.resetFiltersState();
				if (typeof status !== 'undefined') {
					self.selectedScanStatus = status;
					$('#crawlory-filter-scan-status').val(status);
				}
				if (typeof severity !== 'undefined') {
					self.selectedSeverity = severity;
					$('#crawlory-filter-severity').val(severity);
				}
				if (typeof hasIssues !== 'undefined') {
					self.selectedHasIssues = hasIssues ? true : null;
				}

				$('.crawlory-card-clickable').removeClass('crawlory-stat-card-active');
				$(this).addClass('crawlory-stat-card-active');

				self.currentPage = 1;
				self.loadDashboardData();
			});

			// Rule Pill Click
			$(document).on('click', '.crawlory-pill', function() {
				var rid = parseInt($(this).data('rule-id'), 10);
				if (self.selectedRuleId === rid) {
					self.selectedRuleId = 0;
					$('.crawlory-pill').removeClass('crawlory-pill-active');
					$('#crawlory-btn-clear-rule-pill').hide();
				} else {
					self.selectedRuleId = rid;
					$('.crawlory-pill').removeClass('crawlory-pill-active');
					$(this).addClass('crawlory-pill-active');
					$('#crawlory-btn-clear-rule-pill').show();
				}
				self.currentPage = 1;
				self.loadDashboardData();
			});

			$('#crawlory-btn-clear-rule-pill').on('click', function(e) {
				e.preventDefault();
				self.selectedRuleId = 0;
				$('.crawlory-pill').removeClass('crawlory-pill-active');
				$(this).hide();
				self.currentPage = 1;
				self.loadDashboardData();
			});

			// Table Finding Badge Click (Preserve and support rule filtering from badges)
			$(document).on('click', '.crawlory-badge-clickable[data-rule-id]', function(e) {
				e.preventDefault();
				var rid = parseInt($(this).data('rule-id'), 10);
				if (!rid) return;
				if (self.selectedRuleId === rid) {
					self.selectedRuleId = 0;
					$('.crawlory-pill').removeClass('crawlory-pill-active');
					$('#crawlory-btn-clear-rule-pill').hide();
				} else {
					self.selectedRuleId = rid;
					$('.crawlory-pill').removeClass('crawlory-pill-active');
					$('.crawlory-pill[data-rule-id="' + rid + '"]').addClass('crawlory-pill-active');
					$('#crawlory-btn-clear-rule-pill').show();
				}
				self.currentPage = 1;
				self.loadDashboardData();
			});

			// Sorting in table header
			$('#crawlory-audit-table th[data-sort]').on('click', function() {
				var sortField = $(this).data('sort');
				if (self.orderBy === sortField) {
					self.order = (self.order === 'ASC') ? 'DESC' : 'ASC';
				} else {
					self.orderBy = sortField;
					self.order = 'DESC';
				}
				$('#crawlory-audit-table th').removeClass('sorted asc desc');
				$(this).addClass('sorted ' + self.order.toLowerCase());
				self.loadDashboardData();
			});

			// Single URL Rescan button in table
			$(document).on('click', '.crawlory-action-rescan', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var url = $btn.data('url');
				var postId = parseInt($btn.data('post-id'), 10) || 0;

				$btn.prop('disabled', true).text('Scanning...');
				self.rescanUrl(url, postId, function() {
					$btn.prop('disabled', false).text('Rescan');
					self.loadDashboardData();
				});
			});

			/* ---- Inspect drawer events (delegated: table rows are rendered dynamically) ---- */
			$(document).on('click', '.crawlory-action-inspect', function(e) {
				e.preventDefault();
				self.handleInspectClick($(this));
			});

			$(document).on('click', '#crawlory-drawer-close, #crawlory-inspect-drawer-backdrop', function(e) {
				e.preventDefault();
				self.closeInspectDrawer();
			});

			$(document).on('click', '#crawlory-drawer-action-rescan', function(e) {
				e.preventDefault();
				self.rescanInspected();
			});

			$(document).on('click', '#crawlory-inspect-drawer .crawlory-drawer-retry', function(e) {
				e.preventDefault();
				var st = self.inspect;
				if (st.open && st.pageId > 0) {
					self.loadInspectFindings(st.pageId);
				}
			});

			// Never follow a placeholder link (View / Edit are hidden when unavailable).
			$(document).on('click', '#crawlory-drawer-action-view, #crawlory-drawer-action-edit', function(e) {
				var href = $(this).attr('href');
				if (!href || href === '#') {
					e.preventDefault();
				}
			});

			// Escape closes the drawer; Tab is kept inside it while open.
			$(document).on('keydown', function(e) {
				if (!self.inspect.open) return;
				if (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27) {
					e.preventDefault();
					self.closeInspectDrawer();
					return;
				}
				if (e.key === 'Tab' || e.keyCode === 9) {
					self.trapDrawerFocus(e);
				}
			});

			// If focus escapes the dialog (e.g. via the mouse), pull it back in.
			$(document).on('focusin', function(e) {
				if (!self.inspect.open) return;
				var drawer = document.getElementById('crawlory-inspect-drawer');
				if (drawer && e.target !== drawer && !$.contains(drawer, e.target)) {
					drawer.focus();
				}
			});

			// Toggle Ignore / Restore finding (inside the drawer)
			$(document).on('click', '#crawlory-inspect-drawer .crawlory-btn-toggle-ignore', function(e) {
				e.preventDefault();
				self.toggleFindingIgnore($(this));
			});

			// Export CSV
			$('#crawlory-btn-export-csv').on('click', function(e) {
				e.preventDefault();
				var nonce = (typeof crawloryData !== 'undefined' && crawloryData.exportNonce) ? crawloryData.exportNonce : ((typeof crawloryData !== 'undefined' && crawloryData.nonce) ? crawloryData.nonce : '');
				var baseUrl = (typeof crawloryData !== 'undefined' && crawloryData.exportUrl) ? crawloryData.exportUrl : (typeof crawloryData !== 'undefined' && crawloryData.ajaxUrl ? crawloryData.ajaxUrl.replace('admin-ajax.php', 'admin-post.php?action=crawlory_export_csv') : 'admin-post.php?action=crawlory_export_csv');
				var exportUrl = baseUrl
					+ '&_crawlory_nonce=' + encodeURIComponent(nonce)
					+ '&nonce=' + encodeURIComponent(nonce)
					+ '&post_type=' + encodeURIComponent(self.selectedPostType || '')
					+ '&rule_id=' + encodeURIComponent(self.selectedRuleId || 0)
					+ '&severity=' + encodeURIComponent(self.selectedSeverity || '')
					+ '&scan_status=' + encodeURIComponent(self.selectedScanStatus || '')
					+ '&finding_status=' + encodeURIComponent(self.selectedFindingStatus || '')
					+ '&search=' + encodeURIComponent(self.searchTerm || '');
				window.location.href = exportUrl;
			});

			// Settings Form Save (AJAX intercept)
			$('#crawlory-settings-form').on('submit', function(e) {
				e.preventDefault();
				self.saveSettings();
			});

			$('#crawlory-btn-save-settings').on('click', function(e) {
				e.preventDefault();
				self.saveSettings();
			});

			// Clear All Data Form & Button (AJAX intercept)
			$('#crawlory-clear-data-form').on('submit', function(e) {
				e.preventDefault();
				self.clearAllAuditData();
			});

			$('#crawlory-btn-clear-all-data').on('click', function(e) {
				e.preventDefault();
				self.clearAllAuditData();
			});
		},

		bindEditorPanelEvents: function() {
			var self = this;
			$(document).off('click', '#crawlory-editor-scan-btn').on('click', '#crawlory-editor-scan-btn', function(e) {
				e.preventDefault();
				var $panel = $('#crawlory-editor-panel');
				if (!$panel.length) return;

				var postId = parseInt($panel.data('post-id'), 10);
				var url = $panel.data('url');
				var $btn = $(this);
				var $progress = $('#crawlory-editor-progress');

				$btn.prop('disabled', true);
				$progress.show();

				$.ajax({
					url: (typeof crawloryAdminData !== 'undefined' ? crawloryAdminData.ajaxUrl : (typeof crawloryData !== 'undefined' ? crawloryData.ajaxUrl : ajaxurl)),
					type: 'POST',
					data: {
						action: 'crawlory_editor_scan_url',
						nonce: (typeof crawloryAdminData !== 'undefined' ? crawloryAdminData.nonce : (typeof crawloryData !== 'undefined' ? crawloryData.nonce : '')),
						post_id: postId,
						url: url
					},
					success: function(resp) {
						$btn.prop('disabled', false);
						$progress.hide();
						if (resp.success) {
							if (resp.data && resp.data.html) {
								$panel.html(resp.data.html);
							}
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'Scan failed.');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$progress.hide();
						alert('Server error occurred during scan.');
					}
				});
			});
		},

		startScan: function(scanType, targetType) {
			var self = this;
			if (self.isScanning) return;

			$('#crawlory-btn-start-scan, #crawlory-btn-start-type-scan').prop('disabled', true);
			$('#crawlory-progress-container').show();
			$('#crawlory-progress-fill').css('width', '0%');
			$('#crawlory-progress-percentage').text('0%');
			$('#crawlory-progress-label').text('Initializing scan...');
			$('#crawlory-diagnostic-box').hide();
			$('.crawlory-scanner-state-buttons').show();
			$('#crawlory-btn-pause-scan').show();
			$('#crawlory-btn-resume-scan').hide();

			self.isScanning = true;
			self.isPaused = false;

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_start_scan',
					nonce: crawloryData.nonce,
					scan_type: scanType,
					target_type: targetType || ''
				},
				success: function(resp) {
					if (!resp.success) {
						alert(resp.data && resp.data.message ? resp.data.message : 'Failed to start scan.');
						self.resetScanUI();
						return;
					}

					self.activeScanId = resp.data.scan_id;
					$('#crawlory-stat-total-target').text(resp.data.total_urls || 0);
					self.startPolling();
				},
				error: function() {
					alert('Connection error occurred while starting scan.');
					self.resetScanUI();
				}
			});
		},

		resumeScanUI: function() {
			this.isScanning = true;
			this.isPaused = false;
			$('#crawlory-btn-start-scan, #crawlory-btn-start-type-scan').prop('disabled', true);
			$('#crawlory-progress-container').show();
			$('.crawlory-scanner-state-buttons').show();
			$('#crawlory-btn-pause-scan').show();
			$('#crawlory-btn-resume-scan').hide();
			this.startPolling();
		},

		resumeInterruptedScan: function(scanId) {
			var self = this;
			self.activeScanId = scanId;
			$('#crawlory-interrupted-recovery-notice').slideUp();
			self.resumeScanUI();
			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_resume_scan',
					nonce: crawloryData.nonce,
					scan_id: scanId
				},
				success: function() {
					self.startPolling();
				}
			});
		},

		isPolling: false,
		pollTimer: null,
		pollFailureCount: 0,
		lastProgressData: null,

		startPolling: function() {
			var self = this;
			if (self.pollTimer) {
				clearTimeout(self.pollTimer);
				self.pollTimer = null;
			}
			self.isPolling = false;
			self.pollFailureCount = 0;
			self.pollScanProgress();
		},

		stopPolling: function() {
			var self = this;
			if (self.pollTimer) {
				clearTimeout(self.pollTimer);
				self.pollTimer = null;
			}
			self.isPolling = false;
		},

		pollScanProgress: function() {
			var self = this;
			if (!self.isScanning || self.isPaused || !self.activeScanId) {
				return;
			}

			// Prevent overlapping polling requests
			if (self.isPolling) {
				return;
			}
			self.isPolling = true;

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				timeout: 8000,
				data: {
					action: 'crawlory_get_scan_progress',
					nonce: crawloryData.nonce,
					scan_id: self.activeScanId
				},
				success: function(resp) {
					self.isPolling = false;
					if (!resp.success || !resp.data) {
						self.handlePollFailure(resp, 'parsererror', 'Invalid response from status check.');
						return;
					}

					var data = resp.data;
					self.pollFailureCount = 0;
					self.lastProgressData = data;
					$('#crawlory-diagnostic-box').hide();

					var pct = (typeof data.percent !== 'undefined') ? data.percent : 0;
					if (data.total_urls > 0 && (data.scanned_urls || 0) < data.total_urls && !data.is_complete && data.status !== 'completed') {
						pct = Math.min(99, Math.floor(((data.scanned_urls || 0) / data.total_urls) * 100));
					}
					$('#crawlory-progress-percentage').text(pct + '%');
					$('#crawlory-progress-fill').css('width', pct + '%');
					$('#crawlory-stat-scanned-count').text(data.scanned_urls || 0);
					$('#crawlory-stat-total-target').text(data.total_urls || 0);
					$('#crawlory-stat-failed-count').text(data.failed_urls || 0);
					$('#crawlory-stat-findings-count').text(data.findings_count || 0);

					var stageLabel = 'Auditing URLs (' + (data.scanned_urls || 0) + ' of ' + (data.total_urls || 0) + ')...';
					if (data.finalization_stage && data.finalization_stage !== 'pending' && data.finalization_stage !== 'completed') {
						if (data.finalization_stage === 'sitemaps') {
							stageLabel = 'Finalizing results: Inspecting XML Sitemaps...';
						} else if (data.finalization_stage === 'duplicates') {
							stageLabel = 'Finalizing results: Reconciling duplicate titles & descriptions...';
						} else if (data.finalization_stage === 'orphans') {
							stageLabel = 'Finalizing results: Recalculating orphan pages...';
						} else if (data.finalization_stage === 'retention') {
							stageLabel = 'Finalizing results: Pruning retention history...';
						}
					}
					$('#crawlory-progress-label').text(stageLabel);

					if (data.is_complete || data.status === 'completed') {
						self.stopPolling();
						$('#crawlory-progress-label').text(self.t('completed', 'Scan completed successfully!'));
						setTimeout(function() {
							self.resetScanUI();
							self.loadDashboardData();
						}, 1200);
						return;
					}

					if (data.status === 'failed') {
						self.stopPolling();
						self.showDiagnostics({
							title: 'Scan Encountered An Error',
							operation: 'Finalization Stage: ' + (data.finalization_stage || data.current_step || 'auditing'),
							status: 'Failed',
							summary: data.finalization_error || 'A background processing stage failed to complete.',
							action: 'Click "Resume" to retry the failed finalization stage or restart a fresh scan.'
						});
						$('#crawlory-progress-label').text('Scan halted: ' + (data.finalization_error || 'Error encountered'));
						$('#crawlory-btn-pause-scan').hide();
						$('#crawlory-btn-resume-scan').show();
						return;
					}

					if (data.status === 'paused') {
						self.stopPolling();
						self.isPaused = true;
						$('#crawlory-btn-pause-scan').hide();
						$('#crawlory-btn-resume-scan').show();
						$('#crawlory-progress-label').text(self.t('paused', 'Scan paused.'));
						return;
					}

					if (data.status === 'cancelled') {
						self.stopPolling();
						self.resetScanUI();
						self.loadDashboardData();
						return;
					}

					// Poll status read-only approximately every 2.5 seconds
					self.pollTimer = setTimeout(function() {
						self.pollScanProgress();
					}, 2500);
				},
				error: function(jqXHR, textStatus, errorThrown) {
					self.isPolling = false;
					self.handlePollFailure(jqXHR, textStatus, errorThrown);
				}
			});
		},

		handlePollFailure: function(jqXHR, textStatus, errorThrown) {
			var self = this;
			self.pollFailureCount++;

			var httpStatus = (jqXHR && jqXHR.status) ? jqXHR.status : 0;
			var title = 'Temporary Status Check Interruption';
			var summary = '';
			var action = 'Background processing continues independently. Re-checking progress...';

			if (textStatus === 'timeout') {
				title = 'Status Request Timed Out';
				summary = 'The server did not respond within the allocated read-only status window.';
				action = 'Server may be experiencing temporary load. Retrying status check with backoff.';
			} else if (httpStatus === 401 || httpStatus === 403) {
				title = 'Session Expired or Permission Denied';
				summary = 'Your administrative login session may have expired (HTTP ' + httpStatus + ').';
				action = 'Refresh this browser tab or log back in. Background workers will continue running.';
			} else if (httpStatus >= 500) {
				title = 'Server Error (HTTP ' + httpStatus + ')';
				summary = 'Web server returned an internal error response during status reading.';
				action = 'Check server PHP error logs. Background scan queue will remain preserved.';
			} else if (textStatus === 'parsererror') {
				title = 'Invalid Server Response';
				summary = 'The server returned an unexpected response format instead of JSON.';
				action = 'Check if a PHP warning or notice is being output by the server.';
			} else if (httpStatus === 0) {
				title = 'Connection Interrupted';
				summary = 'Could not establish connection to WordPress server.';
				action = 'Check network connectivity. Polling will automatically resume once reconnected.';
			} else {
				summary = 'Encountered unexpected status error: ' + (errorThrown || textStatus);
			}

			self.showDiagnostics({
				title: title,
				operation: 'Read-only Status Polling (crawlory_get_scan_progress)',
				status: httpStatus ? 'HTTP ' + httpStatus : textStatus,
				summary: summary,
				action: action
			});

			$('#crawlory-progress-label').text(title + ' (Retrying...)');

			// Bounded exponential backoff: 3s, 4.5s, 6.7s, up to 30s max
			var backoffDelay = Math.min(30000, Math.round(2500 * Math.pow(1.5, Math.min(6, self.pollFailureCount))));
			if (self.isScanning && !self.isPaused) {
				self.pollTimer = setTimeout(function() {
					self.pollScanProgress();
				}, backoffDelay);
			}
		},

		showDiagnostics: function(info) {
			var self = this;
			$('#crawlory-diagnostic-box').show();
			$('#crawlory-diag-title').text(info.title || 'Diagnostic Information');
			$('#crawlory-diag-summary').text(info.summary || '');
			$('#crawlory-diag-scan-id').text(self.activeScanId || '-');
			$('#crawlory-diag-op').text(info.operation || 'Status Check');
			$('#crawlory-diag-http-status').text(info.status || '-');
			var lastProg = self.lastProgressData ? (self.lastProgressData.scanned_urls + ' of ' + self.lastProgressData.total_urls + ' URLs (' + (self.lastProgressData.percent || 0) + '%)') : 'None yet';
			$('#crawlory-diag-last-progress').text(lastProg);
			var stage = self.lastProgressData ? (self.lastProgressData.finalization_stage || self.lastProgressData.current_step || 'auditing') : 'Unknown';
			$('#crawlory-diag-stage').text(stage);
			$('#crawlory-diag-action').text(info.action || 'No action needed.');
		},

		runNextBatch: function() {
			this.startPolling();
		},

		pauseScan: function() {
			var self = this;
			self.isPaused = true;
			self.stopPolling();
			$('#crawlory-btn-pause-scan').hide();
			$('#crawlory-btn-resume-scan').show();
			$('#crawlory-progress-label').text('Scan paused by user.');

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_pause_scan',
					nonce: crawloryData.nonce,
					scan_id: self.activeScanId
				}
			});
		},

		resumeScan: function() {
			var self = this;
			self.isPaused = false;
			$('#crawlory-btn-pause-scan').show();
			$('#crawlory-btn-resume-scan').hide();
			$('#crawlory-progress-label').text('Resuming scan...');

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_resume_scan',
					nonce: crawloryData.nonce,
					scan_id: self.activeScanId
				},
				success: function() {
					self.startPolling();
				}
			});
		},

		cancelScan: function() {
			var self = this;
			self.isScanning = false;
			self.isPaused = false;
			self.stopPolling();

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_cancel_scan',
					nonce: crawloryData.nonce,
					scan_id: self.activeScanId
				},
				complete: function() {
					self.resetScanUI();
					self.loadDashboardData();
				}
			});
		},

		resetScanUI: function() {
			this.isScanning = false;
			this.isPaused = false;
			this.activeScanId = 0;
			this.stopPolling();
			$('#crawlory-btn-start-scan, #crawlory-btn-start-type-scan').prop('disabled', false);
			$('.crawlory-scanner-state-buttons').hide();
		},

		loadDashboardData: function() {
			var self = this;
			var $tbody = $('#crawlory-table-body');
			$tbody.html('<tr><td colspan="9" class="crawlory-table-loading"><span class="spinner is-active"></span> ' + self.escapeHtml(self.t('loadingData', 'Loading audit data...')) + '</td></tr>');

			// Cancel previous in-flight request to avoid race condition redraws.
			if (self.dashboardXhr && typeof self.dashboardXhr.abort === 'function') {
				self.dashboardXhr.abort();
			}
			self.dashboardReqSeq = (self.dashboardReqSeq || 0) + 1;
			var reqSeq = self.dashboardReqSeq;

			self.dashboardXhr = $.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_get_dashboard_data',
					nonce: crawloryData.nonce,
					page: self.currentPage,
					per_page: self.perPage,
					orderby: self.orderBy,
					order: self.order,
					post_type: self.selectedPostType,
					rule_id: self.selectedRuleId,
					severity: self.selectedSeverity,
					scan_status: self.selectedScanStatus,
					finding_status: self.selectedFindingStatus,
					search: self.searchTerm
				},
				success: function(resp) {
					if (reqSeq !== self.dashboardReqSeq) {
						return; // Discard superseded response.
					}
					if (!resp.success) {
						$tbody.html('<tr><td colspan="9">' + self.escapeHtml(self.t('failedLoad', 'Failed to load data.')) + '</td></tr>');
						return;
					}

					self.updateStatsCards(resp.data.stats);
					self.updateCoverageBanner(resp.data.coverage);
					self.renderRulePills(resp.data.stats.rule_counts);
					self.renderTable(resp.data.pages);
				},
				error: function(xhr, status) {
					if (status === 'abort' || reqSeq !== self.dashboardReqSeq) {
						return; // Ignore aborted requests and old generations.
					}
					$tbody.html('<tr><td colspan="9">' + self.escapeHtml(self.t('connectionError', 'An error occurred while connecting to the server.')) + '</td></tr>');
				}
			});
		},

		updateStatsCards: function(stats) {
			$('#card-total-urls').text(this.formatNumber(stats.total_urls));
			$('#card-scanned-urls').text(this.formatNumber(stats.scanned_urls));
			$('#card-urls-with-findings').text(this.formatNumber(stats.urls_with_findings));
			$('#card-total-findings').text(this.formatNumber(stats.total_findings));
			$('#card-failed-scans').text(this.formatNumber(stats.failed_urls));
			$('#card-pending-stale').text(this.formatNumber(stats.pending_urls));

			if (stats.last_scan && stats.last_scan.finished_at) {
				$('#card-last-scan-time').text(stats.last_scan.finished_at);
			} else {
				$('#card-last-scan-time').text('Never');
			}

			// Lifecycle Tab Badges
			$('#tab-count-new').text(this.formatNumber(stats.new_findings));
			$('#tab-count-persistent').text(this.formatNumber(stats.persistent_findings));
			$('#tab-count-resolved').text(this.formatNumber(stats.resolved_findings));
			$('#tab-count-ignored').text(this.formatNumber(stats.ignored_findings));
			$('#tab-count-stale').text(this.formatNumber(stats.pending_urls));
			$('#tab-count-failed').text(this.formatNumber(stats.failed_urls));
		},

		updateCoverageBanner: function(coverage) {
			if (!coverage || typeof coverage.coverage_pct === 'undefined') return;

			var text = 'Site Scan Coverage: <strong>' + coverage.coverage_pct + '%</strong> (' + this.formatNumber(coverage.scanned_pages) + ' of ' + this.formatNumber(coverage.total_pages) + ' eligible URLs scanned)';
			$('#crawlory-coverage-text').html(text);
			$('#crawlory-coverage-banner').show();
		},

		renderRulePills: function(ruleCounts) {
			var self = this;
			var $container = $('#crawlory-rule-pills-list');
			$container.empty();

			var defs = crawloryData.ruleDefinitions;
			$.each(defs, function(rid, def) {
				var count = (ruleCounts && ruleCounts[rid]) ? ruleCounts[rid] : 0;
				var isActive = (self.selectedRuleId === parseInt(rid, 10));

				var $pill = $('<div class="crawlory-pill' + (isActive ? ' crawlory-pill-active' : '') + '" data-rule-id="' + rid + '"></div>');
				$pill.append('<span>#' + rid + ' ' + self.escapeHtml(def.name) + '</span>');
				$pill.append('<span class="crawlory-pill-badge">' + count + '</span>');

				$container.append($pill);
			});
		},

		renderTable: function(pagesData) {
			var self = this;
			var $tbody = $('#crawlory-table-body');
			$tbody.empty();

			if (!pagesData.items || pagesData.items.length === 0) {
				$tbody.html('<tr><td colspan="9" style="text-align: center; padding: 30px; color: #64748b;">' + self.escapeHtml(self.t('noMatchingUrls', 'No matching URLs found. Try adjusting your filters or running a scan.')) + '</td></tr>');
				$('#crawlory-pagination').hide();
				$('#crawlory-results-count').text(self.t('showingCount', 'Showing %1$d of %2$d URLs').replace('%1$d', 0).replace('%2$d', 0));
				return;
			}

			$('#crawlory-results-count').text(self.t('showingCount', 'Showing %1$d of %2$d URLs').replace('%1$d', pagesData.items.length).replace('%2$d', pagesData.total));

			$.each(pagesData.items, function(idx, page) {
				var $tr = $('<tr></tr>');

				// Col 1: Title & Type (Up to 3 lines before truncation, accessible tooltip)
				var rawPostTitle = page.post_title ? String(page.post_title) : '(Untitled)';
				var postTitleEscaped = self.escapeHtml(rawPostTitle);
				var titleHtml = '<div class="crawlory-title-cell-wrap">';
				titleHtml += '<strong class="crawlory-cell-title crawlory-preview-three-line crawlory-has-tooltip" tabindex="0" data-tooltip="' + postTitleEscaped + '" title="' + postTitleEscaped + '">' + postTitleEscaped + '</strong>';
				titleHtml += '<div class="crawlory-cpt-badge-row"><span class="crawlory-cpt-badge">' + self.escapeHtml(page.post_type) + '</span></div>';
				titleHtml += '</div>';
				$tr.append('<td class="crawlory-col-title-cell">' + titleHtml + '</td>');

				// Col 2: URL (Constrained cell, ellipsis, dark readable link, accessible tooltip, preserved link destination)
				var rawUrl = page.url ? String(page.url) : '';
				var safePageUrl = self.safeUrl(rawUrl);
				var escapedUrl = self.escapeHtml(rawUrl);
				var urlLinkAttr = safePageUrl
					? '<a class="crawlory-url-link crawlory-has-tooltip" href="' + self.escapeHtml(safePageUrl) + '" target="_blank" rel="noopener noreferrer" data-tooltip="' + escapedUrl + '" title="' + escapedUrl + '">' + escapedUrl + '</a>'
					: '<span class="crawlory-url-link crawlory-has-tooltip" tabindex="0" data-tooltip="' + escapedUrl + '" title="' + escapedUrl + '">' + escapedUrl + '</span>';
				var urlHtml = '<div class="crawlory-url-cell-wrap">' + urlLinkAttr + '</div>';
				$tr.append('<td class="crawlory-col-url-cell">' + urlHtml + '</td>');

				// Col 3: SEO Title (Preview limited to 2 lines, char badge below, dynamic thresholds, accessible tooltip)
				var auditState = page.audit_state || {};
				var isEvaluated = !!auditState.is_evaluated;
				var hasMeasurements = !!auditState.has_html_measurements;
				var isStale = !!auditState.is_stale;

				var rawSeoTitle = page.seo_title ? String(page.seo_title) : '';
				var seoTitlePreview = '';
				var seoTitleTooltip = '';
				var titleLenClass = '';

				if (!hasMeasurements) {
					seoTitlePreview = '<span style="color:#94a3b8;">&mdash;</span>';
					seoTitleTooltip = self.t('unavailable', 'Unavailable');
					titleLenClass = 'crawlory-count-pending';
				} else if (rawSeoTitle) {
					var staleText = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					seoTitlePreview = self.escapeHtml(rawSeoTitle) + (isStale ? '<span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' + self.escapeHtml(staleText) + '</span>' : '');
					seoTitleTooltip = rawSeoTitle + staleText;
					var tMin = (crawloryData.thresholds && crawloryData.thresholds.title_min) ? crawloryData.thresholds.title_min : 30;
					var tMax = (crawloryData.thresholds && crawloryData.thresholds.title_max) ? crawloryData.thresholds.title_max : 60;
					titleLenClass = (page.seo_title_length < tMin || page.seo_title_length > tMax) ? 'crawlory-count-warn' : 'crawlory-count-ok';
				} else {
					var staleText = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					seoTitlePreview = '<em class="crawlory-text-missing">' + self.escapeHtml(self.t('missing', 'Missing')) + '</em>' + (isStale ? '<span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' + self.escapeHtml(staleText) + '</span>' : '');
					seoTitleTooltip = self.t('missing', 'Missing') + staleText;
					titleLenClass = 'crawlory-count-err';
				}

				var seoTitleHtml = '<div class="crawlory-meta-preview-wrap">';
				seoTitleHtml += '<div class="crawlory-preview-text crawlory-preview-two-line crawlory-has-tooltip" tabindex="0" data-tooltip="' + self.escapeHtml(seoTitleTooltip) + '" title="' + self.escapeHtml(seoTitleTooltip) + '">' + seoTitlePreview + '</div>';
				if (hasMeasurements) {
					var staleTag = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					var titleLen = parseInt(page.seo_title_length, 10) || 0;
					var charText = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
						? wp.i18n.sprintf( wp.i18n._n( '%d char', '%d chars', titleLen, 'crawlory-technical-seo-dashboard' ), titleLen )
						: (titleLen + ' ' + (titleLen === 1 ? 'char' : 'chars'));
					seoTitleHtml += '<div class="crawlory-count-tag-row"><span class="crawlory-count-tag ' + titleLenClass + '">' + self.escapeHtml(charText) + (staleTag ? ' ' + self.escapeHtml(staleTag) : '') + '</span></div>';
				} else {
					seoTitleHtml += '<div class="crawlory-count-tag-row"><span class="crawlory-count-tag" style="background:#f1f5f9; color:#94a3b8;">&mdash;</span></div>';
				}
				seoTitleHtml += '</div>';
				$tr.append('<td class="crawlory-col-seo-title-cell">' + seoTitleHtml + '</td>');

				// Col 4: Meta Description (Preview limited to 2 lines, char badge below, dynamic thresholds, accessible tooltip)
				var rawDesc = page.meta_description ? String(page.meta_description) : '';
				var descPreview = '';
				var descTooltip = '';
				var descLenClass = '';

				if (!hasMeasurements) {
					descPreview = '<span style="color:#94a3b8;">&mdash;</span>';
					descTooltip = self.t('unavailable', 'Unavailable');
					descLenClass = 'crawlory-count-pending';
				} else if (rawDesc) {
					var descStaleText = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					descPreview = self.escapeHtml(rawDesc) + (isStale ? '<span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' + self.escapeHtml(descStaleText) + '</span>' : '');
					descTooltip = rawDesc + descStaleText;
					var dMin = (crawloryData.thresholds && crawloryData.thresholds.desc_min) ? crawloryData.thresholds.desc_min : 70;
					var dMax = (crawloryData.thresholds && crawloryData.thresholds.desc_max) ? crawloryData.thresholds.desc_max : 155;
					descLenClass = (page.meta_description_length < dMin || page.meta_description_length > dMax) ? 'crawlory-count-warn' : 'crawlory-count-ok';
				} else {
					var descStaleText = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					descPreview = '<em class="crawlory-text-missing">' + self.escapeHtml(self.t('missing', 'Missing')) + '</em>' + (isStale ? '<span class="crawlory-badge-stale" style="font-size:11px;color:#d97706;font-style:italic;">' + self.escapeHtml(descStaleText) + '</span>' : '');
					descTooltip = self.t('missing', 'Missing') + descStaleText;
					descLenClass = 'crawlory-count-warn';
				}

				var descHtml = '<div class="crawlory-meta-preview-wrap">';
				descHtml += '<div class="crawlory-preview-text crawlory-preview-two-line crawlory-has-tooltip" tabindex="0" data-tooltip="' + self.escapeHtml(descTooltip) + '" title="' + self.escapeHtml(descTooltip) + '">' + descPreview + '</div>';
				if (hasMeasurements) {
					var descStaleTag = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					var descLen = parseInt(page.meta_description_length, 10) || 0;
					var descCharText = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
						? wp.i18n.sprintf( wp.i18n._n( '%d char', '%d chars', descLen, 'crawlory-technical-seo-dashboard' ), descLen )
						: (descLen + ' ' + (descLen === 1 ? 'char' : 'chars'));
					descHtml += '<div class="crawlory-count-tag-row"><span class="crawlory-count-tag ' + descLenClass + '">' + self.escapeHtml(descCharText) + (descStaleTag ? ' ' + self.escapeHtml(descStaleTag) : '') + '</span></div>';
				} else {
					descHtml += '<div class="crawlory-count-tag-row"><span class="crawlory-count-tag" style="background:#f1f5f9; color:#94a3b8;">&mdash;</span></div>';
				}
				descHtml += '</div>';
				$tr.append('<td class="crawlory-col-desc-cell">' + descHtml + '</td>');

				// Col 5: H1
				var h1Html = '';
				if (!hasMeasurements) {
					h1Html = '<span class="crawlory-count-tag" style="background:#f1f5f9; color:#94a3b8;">&mdash;</span>';
				} else {
					var h1Count = parseInt(page.h1_count, 10);
					if (isNaN(h1Count)) {
						if (page.h1_contents) {
							try {
								var parsed = (typeof page.h1_contents === 'string') ? JSON.parse(page.h1_contents) : page.h1_contents;
								h1Count = Array.isArray(parsed) ? parsed.length : 0;
							} catch (e) {
								h1Count = 0;
							}
						} else {
							h1Count = 0;
						}
					}
					var h1Class = (h1Count === 1) ? 'crawlory-count-ok' : ((h1Count === 0) ? 'crawlory-count-err' : 'crawlory-count-warn');
					var h1StaleTag = isStale ? (' ' + self.t('staleTag', '(Stale)')) : '';
					var h1Text = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
						? wp.i18n.sprintf( wp.i18n._n( '%d H1', '%d H1s', h1Count, 'crawlory-technical-seo-dashboard' ), h1Count )
						: (h1Count + ' ' + (h1Count === 1 ? 'H1' : 'H1s'));
					h1Html = '<span class="crawlory-count-tag ' + h1Class + '">' + self.escapeHtml(h1Text) + (h1StaleTag ? ' ' + self.escapeHtml(h1StaleTag) : '') + '</span>';
				}
				$tr.append('<td class="crawlory-col-h1-cell">' + h1Html + '</td>');

				// Col 6: Images
				var imgHtml = '';
				if (hasMeasurements) {
					var altCount = parseInt(page.image_alt_findings_count, 10) || 0;
					if (altCount > 0) {
						var issueText = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
							? wp.i18n.sprintf( wp.i18n._n( '%d issue', '%d issues', altCount, 'crawlory-technical-seo-dashboard' ), altCount )
							: (altCount + ' ' + (altCount === 1 ? 'issue' : 'issues'));
						imgHtml = '<span class="crawlory-count-tag crawlory-count-warn">' + self.escapeHtml(issueText) + '</span>';
					} else {
						imgHtml = '<span class="crawlory-count-tag crawlory-count-ok">0</span>';
					}
				} else {
					imgHtml = '<span class="crawlory-count-tag" style="background:#f1f5f9; color:#94a3b8;">&mdash;</span>';
				}
				$tr.append('<td class="crawlory-col-images-cell">' + imgHtml + '</td>');

				// Col 7: Finding Badges (Grouped by Rule ID with occurrence count, max 4 + "+N more")
				var badgesHtml = '<div class="crawlory-badge-list">';
				var renderStatusBadge = function() {
					if (page.audit_state) {
						var cls = page.audit_state.css_class || 'crawlory-badge-warn';
						var lbl = page.audit_state.label || self.t('incomplete', 'Incomplete');
						var isStaleBadge = !!page.audit_state.is_stale;
						var icon = (page.audit_state.state === 'clean') ? '<span class="dashicons dashicons-yes-alt" style="font-size:13px; width:13px; height:13px; margin-right:3px; vertical-align:middle;"></span>' : '';
						var staleSpan = (isStaleBadge && page.audit_state.state !== 'stale') ? (' <span class="crawlory-badge crawlory-badge-warn" style="font-size:10px; padding:1px 4px; margin-left:3px;">' + self.escapeHtml(self.t('stale', 'Stale')) + '</span>') : '';
						return '<span class="crawlory-badge ' + self.escapeHtml(cls) + '">' + icon + self.escapeHtml(lbl) + '</span>' + staleSpan;
					}
					var httpCode = parseInt(page.http_status, 10);
					if (page.scan_status === 'failed' || (httpCode >= 400 && !isNaN(httpCode))) {
						var errLabel = (httpCode >= 400) ? ('HTTP ' + httpCode) : self.t('failed', 'Failed');
						return '<span class="crawlory-badge crawlory-badge-error">' + self.escapeHtml(errLabel) + '</span>';
					}
					if (!page.last_scanned_at || page.scan_status === 'pending') {
						return '<span class="crawlory-badge crawlory-badge-pending">' + self.escapeHtml(self.t('unscanned', 'Unscanned')) + '</span>';
					}
					if (page.scan_status === 'interrupted') {
						return '<span class="crawlory-badge crawlory-badge-warn">' + self.escapeHtml(self.t('interrupted', 'Interrupted')) + '</span>';
					}
					if (page.scan_status === 'stale') {
						return '<span class="crawlory-badge crawlory-badge-warn">' + self.escapeHtml(self.t('stale', 'Stale')) + '</span>';
					}
					if (page.scan_status === 'success') {
						return '<span class="crawlory-badge crawlory-badge-clean"><span class="dashicons dashicons-yes-alt" style="font-size:13px; width:13px; height:13px; margin-right:3px; vertical-align:middle;"></span>' + self.escapeHtml(self.t('clean', 'Clean')) + '</span>';
					}
					return '<span class="crawlory-badge crawlory-badge-warn">' + self.escapeHtml(self.t('incomplete', 'Incomplete')) + '</span>';
				};

				if (page.findings && page.findings.length > 0) {
					var groupedRules = {};
					var ruleOrder = [];

					$.each(page.findings, function(fidx, f) {
						var rid = parseInt(f.rule_id, 10);
						if (isNaN(rid) || rid <= 0) return;
						// Exclude resolved and ignored findings from active dashboard badges unless specifically filtered.
						if (!self.selectedFindingStatus && (f.finding_status === 'resolved' || parseInt(f.is_ignored, 10) === 1)) {
							return;
						}

						if (!groupedRules[rid]) {
							var def = (crawloryData.ruleDefinitions && crawloryData.ruleDefinitions[rid]) ? crawloryData.ruleDefinitions[rid] : null;
							var rName = f.rule_name || (def ? def.name : ('Rule ' + rid));
							groupedRules[rid] = {
								rule_id: rid,
								rule_name: rName,
								severity: f.severity || 'warning',
								count: 0
							};
							ruleOrder.push(rid);
						}
						groupedRules[rid].count++;
						if (f.severity === 'error') {
							groupedRules[rid].severity = 'error';
						} else if (f.severity === 'warning' && groupedRules[rid].severity !== 'error') {
							groupedRules[rid].severity = 'warning';
						}
					});

					if (ruleOrder.length === 0) {
						badgesHtml += renderStatusBadge();
					} else {
						var maxVisibleBadges = 4;
						var visibleRids = ruleOrder.slice(0, maxVisibleBadges);
						var remainingRuleCount = ruleOrder.length - maxVisibleBadges;

						$.each(visibleRids, function(i, rid) {
							var grp = groupedRules[rid];
							var badgeClass = 'crawlory-badge-' + self.cssToken(grp.severity);
							var occCount = parseInt(grp.count, 10) || 0;
							var occLabel = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
								? wp.i18n.sprintf( wp.i18n._n( '%d occurrence', '%d occurrences', occCount, 'crawlory-technical-seo-dashboard' ), occCount )
								: (occCount + ' ' + (occCount === 1 ? 'occurrence' : 'occurrences'));
							var accessibleLabel = 'Rule #' + grp.rule_id + ': ' + grp.rule_name + ' (' + occLabel + ')';
							var badgeDisplay = '#' + grp.rule_id + (grp.count > 1 ? (' \u00D7 ' + grp.count) : '');

							badgesHtml += '<button type="button" class="crawlory-badge ' + badgeClass + ' crawlory-badge-clickable" data-rule-id="' + grp.rule_id + '" aria-label="' + self.escapeHtml(accessibleLabel) + '" title="' + self.escapeHtml(accessibleLabel) + '">' + self.escapeHtml(badgeDisplay) + '</button>';
						});

						if (remainingRuleCount > 0) {
							var moreAriaLabel = 'View ' + remainingRuleCount + ' more in Inspect drawer';
							var moreBadgeText = self.t('moreFindings', '+%d more').replace('%d', remainingRuleCount);
							badgesHtml += '<button type="button" class="crawlory-badge crawlory-badge-more crawlory-action-inspect" data-page-id="' + self.escapeHtml(parseInt(page.id, 10) || 0) + '" data-post-id="' + self.escapeHtml(parseInt(page.post_id, 10) || 0) + '" data-url="' + self.escapeHtml(page.url) + '" data-title="' + self.escapeHtml(page.post_title) + '" data-edit-url="' + (page.edit_link ? self.escapeHtml(page.edit_link) : '') + '" aria-label="' + self.escapeHtml(moreAriaLabel) + '" title="' + self.escapeHtml(moreAriaLabel) + '">' + self.escapeHtml(moreBadgeText) + '</button>';
						}
					}
				} else {
					badgesHtml += renderStatusBadge();
				}
				badgesHtml += '</div>';
				$tr.append('<td class="crawlory-col-findings-cell">' + badgesHtml + '</td>');

				// Col 8: Last Scanned Time (Date and Time on separate lines)
				var timeHtml = '';
				if (page.last_scanned_at) {
					var parts = String(page.last_scanned_at).trim().split(' ');
					if (parts.length >= 2) {
						timeHtml = '<span class="crawlory-date-text">' + self.escapeHtml(parts[0]) + '</span><span class="crawlory-time-text">' + self.escapeHtml(parts.slice(1).join(' ')) + '</span>';
					} else {
						timeHtml = '<span class="crawlory-date-text">' + self.escapeHtml(page.last_scanned_at) + '</span>';
					}
				} else {
					timeHtml = '<span class="crawlory-date-pending">' + self.escapeHtml(self.t('pending', 'Pending')) + '</span>';
				}
				$tr.append('<td class="crawlory-col-time-cell">' + timeHtml + '</td>');

				// Col 9: Actions (Inspect, Rescan, Edit with accessible label)
				var actionsHtml = '<div class="crawlory-actions-cell">';
				actionsHtml += '<button type="button" class="button button-small crawlory-action-inspect" data-page-id="' + self.escapeHtml(parseInt(page.id, 10) || 0) + '" data-post-id="' + self.escapeHtml(parseInt(page.post_id, 10) || 0) + '" data-url="' + self.escapeHtml(page.url) + '" data-title="' + self.escapeHtml(page.post_title) + '" data-edit-url="' + (page.edit_link ? self.escapeHtml(page.edit_link) : '') + '">' + self.escapeHtml(self.t('inspect', 'Inspect')) + '</button>';
				actionsHtml += '<button type="button" class="button button-small crawlory-action-rescan" data-url="' + self.escapeHtml(page.url) + '" data-post-id="' + self.escapeHtml(parseInt(page.post_id, 10) || 0) + '" title="' + self.escapeHtml(self.t('rescanUrl', 'Rescan this URL')) + '">' + self.escapeHtml(self.t('rescan', 'Rescan')) + '</button>';
				if (self.safeUrl(page.edit_link)) {
					var editLabel = page.post_title ? self.t('editTitleInWp', 'Edit “%s” in WordPress').replace('%s', self.escapeHtml(page.post_title)) : self.t('editInWp', 'Edit in WordPress');
					actionsHtml += '<a href="' + self.escapeHtml(page.edit_link) + '" target="_blank" rel="noopener noreferrer" class="button button-small crawlory-action-edit" title="' + editLabel + '" aria-label="' + editLabel + '"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' + editLabel + '</span></a>';
				}
				actionsHtml += '</div>';
				$tr.append('<td class="crawlory-col-actions">' + actionsHtml + '</td>');

				$tbody.append($tr);
			});

			self.renderPagination(pagesData);
		},

		renderPagination: function(pagesData) {
			var self = this;
			var $container = $('#crawlory-pagination');
			var $info = $('#crawlory-pagination-info');
			var $buttons = $('#crawlory-pagination-buttons');

			if (pagesData.pages <= 1) {
				$container.hide();
				return;
			}

			$container.show();
			$info.text('Page ' + pagesData.page + ' of ' + pagesData.pages);
			$buttons.empty();

			if (pagesData.page > 1) {
				var $prev = $('<button type="button" class="button crawlory-btn-secondary">Previous</button>');
				$prev.on('click', function() {
					self.currentPage--;
					self.loadDashboardData();
				});
				$buttons.append($prev);
			}

			if (pagesData.page < pagesData.pages) {
				var $next = $('<button type="button" class="button crawlory-btn-secondary">Next</button>');
				$next.on('click', function() {
					self.currentPage++;
					self.loadDashboardData();
				});
				$buttons.append($next);
			}
		},

		rescanUrl: function(url, postId, callback) {
			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'crawlory_rescan_url',
					nonce: crawloryData.nonce,
					url: url,
					post_id: postId
				},
				complete: function() {
					if (typeof callback === 'function') {
						callback();
					}
				}
			});
		},

		/**
		 * Keyboard- and hover-accessible floating tooltip system.
		 * Uses viewport fixed positioning to prevent clipping by table overflow containers.
		 */
		bindTooltipEvents: function() {
			var self = this;
			var $tip = $('#crawlory-table-tooltip');
			if (!$tip.length) {
				$tip = $('<div id="crawlory-table-tooltip" role="tooltip" aria-hidden="true"></div>').appendTo('body');
			}

			function showTooltip(el) {
				var text = $(el).attr('data-tooltip') || $(el).attr('title');
				if (!text || text === 'Missing' || text === '(Untitled)') return;

				$tip.text(text).addClass('crawlory-tooltip-visible').attr('aria-hidden', 'false');

				var rect = el.getBoundingClientRect();
				var tipWidth = $tip.outerWidth();
				var tipHeight = $tip.outerHeight();

				var top = rect.top - tipHeight - 8;
				var left = rect.left;

				// Flip below if not enough room on top
				if (top < 10) {
					top = rect.bottom + 8;
				}
				// Adjust if bleeding off the right of viewport
				if (left + tipWidth > window.innerWidth - 16) {
					left = Math.max(16, window.innerWidth - tipWidth - 16);
				}
				if (left < 16) {
					left = 16;
				}

				$tip.css({ top: top + 'px', left: left + 'px' });
			}

			function hideTooltip() {
				$tip.removeClass('crawlory-tooltip-visible').attr('aria-hidden', 'true');
			}

			$(document).on('mouseenter focusin', '.crawlory-has-tooltip', function() {
				showTooltip(this);
			});

			$(document).on('mouseleave focusout', '.crawlory-has-tooltip', function() {
				hideTooltip();
			});

			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' || e.keyCode === 27) {
					hideTooltip();
				}
			});
		},

		/* ------------------------------------------------------------------
		 * Inspect drawer
		 *
		 * A single drawer (#crawlory-inspect-drawer, rendered by
		 * admin/views/inspect-modal-view.php) is driven by this.inspect state.
		 *
		 * - pageId is the CRAWLORY audit page ID (crawlory_pages.id), never the WP post ID.
		 * - sessionId changes every time the drawer opens or closes; requestId changes
		 *   for every findings request. Any async callback that does not match both is
		 *   stale and must not touch the UI.
		 * ------------------------------------------------------------------ */

		inspect: {
			open: false,
			sessionId: 0,
			requestId: 0,
			pageId: 0,
			postId: 0,
			url: '',
			title: '',
			editUrl: '',
			trigger: null,
			triggerPageId: 0,
			xhr: null,
			rescanning: false
		},

		/**
		 * Return the URL only if it is an absolute http(s) URL, otherwise ''.
		 */
		safeUrl: function(u) {
			if (typeof u !== 'string') return '';
			u = u.trim();
			if (!/^https?:\/\//i.test(u)) return '';
			try {
				var parsed = new URL(u);
				return (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? u : '';
			} catch (e) {
				return '';
			}
		},

		/**
		 * Make a value safe for use inside a CSS class token.
		 */
		cssToken: function(v) {
			return String(v == null ? '' : v).toLowerCase().replace(/[^a-z0-9_-]/g, '');
		},

		handleInspectClick: function($btn) {
			var pageId = parseInt($btn.attr('data-page-id'), 10);
			var postId = parseInt($btn.attr('data-post-id'), 10);
			this.openInspectDrawer(
				pageId,
				$btn.attr('data-url') || '',
				$btn.attr('data-title') || '',
				$btn.attr('data-edit-url') || '',
				isNaN(postId) ? 0 : postId,
				$btn[0]
			);
		},

		openInspectDrawer: function(pageId, url, title, editUrl, postId, trigger) {
			var self = this;
			var st = self.inspect;

			self.abortInspectRequests();
			st.sessionId++;
			st.requestId++;

			st.pageId = parseInt(pageId, 10);
			if (!(st.pageId > 0)) {
				st.pageId = 0;
			}
			st.postId = parseInt(postId, 10) > 0 ? parseInt(postId, 10) : 0;
			st.url = self.safeUrl(url);
			st.editUrl = self.safeUrl(editUrl);
			st.title = title ? String(title) : '';
			st.rescanning = false;
			if (trigger) {
				st.trigger = trigger.jquery ? trigger[0] : trigger;
				st.triggerPageId = st.pageId;
			}

			self.renderDrawerHeader();
			self.setDrawerBadge('');
			self.resetDrawerCounters();
			self.setDrawerStatus('', '');
			self.setRescanButtonBusy(false);

			st.open = true;
			$('html, body').addClass('crawlory-drawer-open');
			$('#crawlory-inspect-drawer-backdrop').show();
			$('#crawlory-inspect-drawer').show();

			// Move keyboard focus into the dialog.
			var drawerEl = document.getElementById('crawlory-inspect-drawer');
			if (drawerEl && typeof drawerEl.focus === 'function') {
				drawerEl.focus();
			}

			if (!st.pageId) {
				self.renderDrawerError('This row has no valid Crawlory audit page ID, so its findings cannot be loaded. Refresh the dashboard and try again.', false);
				return;
			}

			self.loadInspectFindings(st.pageId);
		},

		closeInspectDrawer: function() {
			var st = this.inspect;

			// Invalidate every pending UI update (findings, rescan, ignore/restore).
			st.sessionId++;
			st.requestId++;
			this.abortInspectRequests();
			var wasOpen = st.open;
			st.open = false;
			st.rescanning = false;

			$('#crawlory-inspect-drawer').hide();
			$('#crawlory-inspect-drawer-backdrop').hide();
			$('html, body').removeClass('crawlory-drawer-open');

			if (!wasOpen) return;

			// Restore focus to the Inspect button that opened the drawer. The table may have been
			// re-rendered while the drawer was open, so fall back to the button for the same page.
			var el = st.trigger;
			if (!el || !document.body.contains(el)) {
				var $again = $('.crawlory-action-inspect').filter(function() {
					return parseInt($(this).attr('data-page-id'), 10) === st.triggerPageId;
				});
				el = $again.length ? $again[0] : null;
			}
			if (el && typeof el.focus === 'function') {
				el.focus();
			}
			st.trigger = null;
		},

		abortInspectRequests: function() {
			var st = this.inspect;
			if (st.xhr && typeof st.xhr.abort === 'function') {
				try { st.xhr.abort(); } catch (e) {}
			}
			st.xhr = null;
		},

		trapDrawerFocus: function(e) {
			var drawer = document.getElementById('crawlory-inspect-drawer');
			if (!drawer) return;

			var focusable = $(drawer).find('a[href], button, input, select, textarea, [tabindex]').filter(function() {
				if (this.disabled) return false;
				if (this.getAttribute('tabindex') === '-1') return false;
				return !$(this).closest('.crawlory-is-hidden, [hidden]').length;
			});

			if (!focusable.length) {
				e.preventDefault();
				drawer.focus();
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			var active = document.activeElement;
			var inside = $.contains(drawer, active);

			if (e.shiftKey) {
				if (!inside || active === first || active === drawer) {
					e.preventDefault();
					last.focus();
				}
			} else if (!inside || active === last) {
				e.preventDefault();
				first.focus();
			}
		},

		renderDrawerHeader: function() {
			var st = this.inspect;
			$('#crawlory-drawer-page-title').text(st.title || this.t('pageAudit', 'Page Audit'));

			var $url = $('#crawlory-drawer-page-url');
			if (st.url) {
				$url.attr('href', st.url).text(st.url).show();
			} else {
				$url.removeAttr('href').text('').hide();
			}

			var $view = $('#crawlory-drawer-action-view');
			if (st.url) {
				$view.attr('href', st.url).removeClass('crawlory-is-hidden');
			} else {
				$view.attr('href', '#').addClass('crawlory-is-hidden');
			}

			var $edit = $('#crawlory-drawer-action-edit');
			if (st.editUrl) {
				$edit.attr('href', st.editUrl).removeClass('crawlory-is-hidden');
			} else {
				$edit.attr('href', '#').addClass('crawlory-is-hidden');
			}

			$('#crawlory-drawer-action-rescan').prop('disabled', !st.url);
		},

		setDrawerBadge: function(scanStatus, auditState) {
			var $b = $('#crawlory-drawer-status-badge');
			if (auditState && auditState.label) {
				var cls = auditState.css_class || ('crawlory-drawer-badge-' + this.cssToken(auditState.state || scanStatus));
				var text = auditState.label;
				if (auditState.is_stale) {
					text += ' ' + this.t('staleTag', '(Stale)');
				}
				$b.text(text).attr('class', 'crawlory-drawer-badge ' + cls);
				return;
			}
			var labels = {
				success: this.t('clean', 'Clean'),
				failed: this.t('failed', 'Failed'),
				stale: this.t('stale', 'Stale'),
				pending: this.t('pending', 'Pending'),
				interrupted: this.t('interrupted', 'Interrupted'),
				incomplete: this.t('incomplete', 'Incomplete')
			};
			if (!scanStatus) {
				$b.text('').attr('class', 'crawlory-drawer-badge');
				return;
			}
			$b.text(labels[scanStatus] || scanStatus).attr('class', 'crawlory-drawer-badge crawlory-drawer-badge-' + this.cssToken(scanStatus));
		},

		resetDrawerCounters: function() {
			$('#crawlory-drawer-error-count, #crawlory-drawer-warning-count, #crawlory-drawer-info-count').text('\u2013');
			$('#crawlory-drawer-history-note').text('');
		},

		/**
		 * Show a status/progress message in the drawer. type: '', 'progress', 'success', 'error'.
		 */
		setDrawerStatus: function(message, type) {
			var $s = $('#crawlory-drawer-status');
			if (!message) {
				$s.addClass('crawlory-is-hidden').attr('class', 'crawlory-drawer-status crawlory-is-hidden').empty();
				return;
			}
			$s.attr('class', 'crawlory-drawer-status' + (type ? ' crawlory-drawer-status-' + this.cssToken(type) : ''));
			$s.empty();
			if (type === 'progress') {
				$s.append('<span class="spinner is-active"></span>');
			}
			$s.append($('<span class="crawlory-drawer-status-text"></span>').text(message));
		},

		setRescanButtonBusy: function(busy) {
			var $btn = $('#crawlory-drawer-action-rescan');
			$btn.prop('disabled', busy || !this.inspect.url);
			if (busy) {
				$btn.attr('aria-busy', 'true');
				$('#crawlory-drawer-rescan-label').text(this.t('rescanning', 'Rescanning\u2026'));
			} else {
				$btn.removeAttr('aria-busy');
				$('#crawlory-drawer-rescan-label').text(this.t('rescanUrl', 'Rescan URL'));
			}
		},

		renderDrawerLoading: function() {
			var $list = $('#crawlory-drawer-findings-list');
			$list.attr('aria-busy', 'true').empty()
				.append('<div class="crawlory-drawer-loading"><span class="spinner is-active"></span> ' + this.escapeHtml(this.t('loadingFindings', 'Loading findings...')) + '</div>');
		},

		renderDrawerError: function(message, canRetry) {
			var $list = $('#crawlory-drawer-findings-list');
			var $box = $('<div class="crawlory-drawer-error" role="alert"></div>');
			$box.append($('<p class="crawlory-drawer-error-text"></p>').text(message));
			if (canRetry) {
				$box.append('<button type="button" class="button button-primary crawlory-drawer-retry">' + this.escapeHtml(this.t('retry', 'Retry')) + '</button>');
			}
			$list.attr('aria-busy', 'false').empty().append($box);
		},

		describeAjaxError: function(xhr, textStatus) {
			var status = xhr ? xhr.status : 0;
			var serverMsg = '';
			try {
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					serverMsg = String(xhr.responseJSON.data.message);
				}
			} catch (e) {}

			if (textStatus === 'timeout') {
				return this.t('errorTimeout', 'The request timed out. Check your connection and try again.');
			}
			if (textStatus === 'parsererror') {
				return this.t('errorParser', 'The server returned a malformed response, so the findings could not be read.');
			}
			if (status === 401 || status === 403 || (status === 400 && xhr && xhr.responseText === '-1')) {
				return this.t('errorAuth', 'Your session or security token has expired, or you do not have permission to view this data. Reload the page and sign in again.') + (serverMsg ? ' (' + serverMsg + ')' : '');
			}
			if (!status) {
				return this.t('errorNetwork', 'Network error: the server could not be reached. Check your connection and try again.');
			}
			if (serverMsg) {
				return serverMsg;
			}
			if (status >= 500) {
				return this.t('errorServer', 'The server reported an error (HTTP %d). Please try again shortly.').replace('%d', status);
			}
			return this.t('errorUnexpected', 'Unexpected response from the server (HTTP %d).').replace('%d', status);
		},

		/**
		 * Load findings for the drawer. Superseded and post-close responses are discarded.
		 *
		 * @param {number} pageId Crawlory audit page ID.
		 * @param {Object} opts   { silent: true } to refresh in place without the loading state.
		 */
		loadInspectFindings: function(pageId, opts) {
			var self = this;
			var st = self.inspect;
			opts = opts || {};
			pageId = parseInt(pageId, 10);

			if (!st.open || !(pageId > 0)) return;

			self.abortInspectRequests();
			var requestId = ++st.requestId;
			var sessionId = st.sessionId;

			function isCurrent() {
				return st.open && st.requestId === requestId && st.sessionId === sessionId && st.pageId === pageId;
			}

			if (!opts.silent) {
				self.resetDrawerCounters();
				self.renderDrawerLoading();
			}

			var xhr = $.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: 30000,
				data: {
					action: 'crawlory_get_page_findings',
					nonce: crawloryData.nonce,
					page_id: pageId
				},
				success: function(resp) {
					if (!isCurrent()) return;

					if (!resp || typeof resp !== 'object' || typeof resp.success === 'undefined') {
						self.renderDrawerError(self.t('errorParser', 'The server returned a malformed response, so the findings could not be read.'), true);
						return;
					}
					if (!resp.success) {
						var msg = (resp.data && resp.data.message) ? String(resp.data.message) : self.t('errorCouldNotLoad', 'The findings could not be loaded.');
						var code = resp.data && resp.data.code;
						self.renderDrawerError(msg, code !== 'page_not_found');
						return;
					}
					if (!resp.data || !Array.isArray(resp.data.findings)) {
						self.renderDrawerError(self.t('errorParser', 'The server returned a malformed response, so the findings could not be read.'), true);
						return;
					}

					self.renderDrawerFindings(resp.data, opts.silent);
				},
				error: function(jqXHR, textStatus) {
					if (textStatus === 'abort' || !isCurrent()) return;
					self.renderDrawerError(self.describeAjaxError(jqXHR, textStatus), true);
				},
				complete: function() {
					if (st.requestId === requestId) {
						st.xhr = null;
					}
				}
			});

			// Only keep the handle if this is still the latest request (a mock/sync transport may have completed already).
			if (st.requestId === requestId && xhr) {
				st.xhr = xhr;
			}
		},

		/**
		 * Merge authoritative page details from the server into the drawer state.
		 */
		applyDrawerPageInfo: function(page) {
			var st = this.inspect;
			if (!page || typeof page !== 'object') return;

			var url = this.safeUrl(page.url);
			if (url) st.url = url;
			if (page.post_title) st.title = String(page.post_title);
			if (parseInt(page.post_id, 10) > 0) st.postId = parseInt(page.post_id, 10);
			// The server decides whether an edit link exists and is permitted; an empty value hides the button.
			if (typeof page.edit_link !== 'undefined') {
				st.editUrl = this.safeUrl(page.edit_link);
			}
			this.renderDrawerHeader();
			this.setRescanButtonBusy(st.rescanning);
		},

		summarizeFindings: function(findings) {
			var s = { active_total: 0, active_error: 0, active_warning: 0, active_info: 0, ignored: 0, resolved: 0 };
			$.each(findings, function(i, f) {
				if (parseInt(f.is_ignored, 10)) { s.ignored++; return; }
				if (f.finding_status === 'resolved') { s.resolved++; return; }
				s.active_total++;
				if (f.severity === 'error') s.active_error++;
				else if (f.severity === 'warning') s.active_warning++;
				else s.active_info++;
			});
			return s;
		},

		renderDrawerFindings: function(data, preserveScroll) {
			var self = this;
			var $list = $('#crawlory-drawer-findings-list');
			var $body = $('#crawlory-drawer-body');
			var scrollTop = preserveScroll ? $body.scrollTop() : 0;
			var findings = data.findings;
			var page = (data.page && typeof data.page === 'object') ? data.page : {};

			self.applyDrawerPageInfo(page);
			self.setDrawerBadge(page.scan_status || '', page.audit_state);

			// Active totals exclude ignored and resolved findings; those are shown as history.
			var summary = self.summarizeFindings(findings);
			$('#crawlory-drawer-error-count').text(summary.active_error);
			$('#crawlory-drawer-warning-count').text(summary.active_warning);
			$('#crawlory-drawer-info-count').text(summary.active_info);

			var historyParts = [];
			if (summary.ignored) {
				var ignoredText = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
					? wp.i18n.sprintf( wp.i18n._n( '%d ignored', '%d ignored', summary.ignored, 'crawlory-technical-seo-dashboard' ), summary.ignored )
					: (summary.ignored + ' ignored');
				historyParts.push(ignoredText);
			}
			if (summary.resolved) {
				var resolvedText = (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n._n === 'function' && typeof wp.i18n.sprintf === 'function')
					? wp.i18n.sprintf( wp.i18n._n( '%d resolved', '%d resolved', summary.resolved, 'crawlory-technical-seo-dashboard' ), summary.resolved )
					: (summary.resolved + ' resolved');
				historyParts.push(resolvedText);
			}
			$('#crawlory-drawer-history-note').text(historyParts.length ? self.t('historyNotePrefix', 'History (not counted above): %s.').replace('%s', historyParts.join(', ')) : '');

			$list.attr('aria-busy', 'false').empty();

			// Scan-state notice. Only a successful scan is treated as usable; anything else is flagged.
			var scanStatus = page.scan_status || '';
			var notice = '';
			if (scanStatus === 'failed') {
				notice = self.t('scanFailedNotice', 'The last scan of this URL failed%s. Saved findings may be incomplete or out of date.').replace('%s', (page.fetch_error ? ' (' + page.fetch_error + ')' : ''));
			} else if (scanStatus === 'stale') {
				notice = self.t('scanStaleNotice', 'This content changed after its last scan, so saved findings may be out of date. Rescan to refresh them.');
			} else if (scanStatus === 'pending') {
				notice = self.t('scanPendingNotice', 'This URL has not been scanned yet.');
			} else if (scanStatus && scanStatus !== 'success') {
				notice = self.t('scanStatusNotice', 'The last scan status for this URL is "%s". Saved findings may be incomplete.').replace('%s', scanStatus);
			}
			if (notice) {
				$list.append($('<div class="crawlory-drawer-notice" role="note"></div>').text(notice));
			}

			var active = [];
			var history = [];
			$.each(findings, function(i, f) {
				if (parseInt(f.is_ignored, 10) || f.finding_status === 'resolved') {
					history.push(f);
				} else {
					active.push(f);
				}
			});

			if (findings.length === 0) {
				var emptyText;
				if (scanStatus === 'success' && page.completeness === 'complete' && (!page.audit_state || !page.audit_state.is_stale)) {
					emptyText = self.t('noIssuesFound', 'No issues were found in the last complete scan.');
				} else if (page.audit_state && page.audit_state.is_stale) {
					emptyText = self.t('scanStaleNotice', 'Audit results are stale. Rescan to refresh.');
				} else {
					// Completeness is unknown (or the scan did not succeed): do not claim a clean bill of health.
					emptyText = self.t('noSavedFindings', 'No saved findings for this URL.');
					if (scanStatus === 'success' && page.last_scanned_at) {
						emptyText += ' ' + self.t('lastScannedPrefix', 'Last scanned: %s.').replace('%s', page.last_scanned_at);
					}
				}
				$list.append($('<div class="crawlory-drawer-empty"></div>').text(emptyText));
			} else if (active.length === 0) {
				$list.append($('<div class="crawlory-drawer-empty"></div>').text(self.t('noActiveIssues', 'No active issues for this URL.')));
			}

			$.each(active, function(i, f) {
				$list.append(self.buildFindingItem(f));
			});

			if (history.length) {
				$list.append($('<h3 class="crawlory-drawer-history-heading"></h3>').text(self.t('historyHeading', 'History — ignored & resolved (%d)').replace('%d', history.length)));
				$.each(history, function(i, f) {
					$list.append(self.buildFindingItem(f));
				});
			}

			if (preserveScroll) {
				$body.scrollTop(scrollTop);
			} else {
				$body.scrollTop(0);
			}
		},

		buildFindingItem: function(f) {
			var self = this;
			var defs = (typeof crawloryData !== 'undefined' && crawloryData.ruleDefinitions) ? crawloryData.ruleDefinitions : {};
			var def = defs[f.rule_id] ? defs[f.rule_id] : {};
			var isIgnored = parseInt(f.is_ignored, 10) ? 1 : 0;
			var status = f.finding_status || 'new';
			var severity = f.severity || 'info';

			var $item = $('<div class="crawlory-finding-item' + (isIgnored ? ' crawlory-finding-ignored' : '') + (status === 'resolved' ? ' crawlory-finding-resolved' : '') + '"></div>');

			var headerHtml = '<div class="crawlory-finding-header">';
			headerHtml += '<span class="crawlory-finding-rule-name">#' + self.escapeHtml(f.rule_id) + ' ' + self.escapeHtml(f.rule_name) + '</span>';
			headerHtml += '<div>';
			headerHtml += '<span class="crawlory-badge crawlory-badge-' + self.cssToken(severity) + '">' + self.escapeHtml(String(severity).toUpperCase()) + '</span>';
			if (isIgnored) {
				headerHtml += '<span class="crawlory-badge crawlory-badge-lifecycle crawlory-badge-ignored">IGNORED</span>';
			}
			headerHtml += '<span class="crawlory-badge crawlory-badge-lifecycle crawlory-badge-' + self.cssToken(status) + '">' + self.escapeHtml(String(status).toUpperCase()) + '</span>';
			headerHtml += '</div></div>';
			$item.append(headerHtml);

			$item.append('<div class="crawlory-finding-message">' + self.escapeHtml(f.message) + '</div>');

			var evHtml = '<div class="crawlory-evidence-box">';
			evHtml += '<div class="crawlory-evidence-row"><span class="crawlory-evidence-label">' + self.escapeHtml(self.t('observed', 'Observed:')) + '</span><span class="crawlory-evidence-value">' + self.escapeHtml(f.observed_value) + '</span></div>';
			evHtml += '<div class="crawlory-evidence-row"><span class="crawlory-evidence-label">' + self.escapeHtml(self.t('expected', 'Expected:')) + '</span><span class="crawlory-evidence-value">' + self.escapeHtml(f.expected_threshold) + '</span></div>';

			if (f.first_detected_at) {
				var firstMsg = self.t('firstDetected', 'First detected: %s').replace('%s', self.escapeHtml(f.first_detected_at));
				var lastMsg = f.last_checked_at ? (' | ' + self.t('lastChecked', 'Last checked: %s').replace('%s', self.escapeHtml(f.last_checked_at))) : '';
				evHtml += '<div class="crawlory-evidence-row"><span class="crawlory-evidence-label">' + self.escapeHtml(self.t('lifecycle', 'Lifecycle:')) + '</span><span class="crawlory-evidence-value">' + firstMsg + lastMsg + '</span></div>';
			}

			if (f.context && Array.isArray(f.context.other_urls) && f.context.other_urls.length > 0) {
				evHtml += '<div class="crawlory-evidence-row" style="margin-top: 8px;"><span class="crawlory-evidence-label">' + self.escapeHtml(self.t('matchingUrls', 'Matching URLs:')) + '</span><div class="crawlory-evidence-value" style="display:flex; flex-direction:column; gap:4px;">';
				$.each(f.context.other_urls, function(ouIdx, ou) {
					var ouUrl = self.safeUrl(ou && ou.url);
					var ouEdit = self.safeUrl(ou && ou.edit_link);
					evHtml += '<div>\u2022 ' + (ouUrl
						? '<a href="' + self.escapeHtml(ouUrl) + '" target="_blank" rel="noopener noreferrer">' + self.escapeHtml(ouUrl) + '</a>'
						: self.escapeHtml(ou && ou.url));
					if (ouEdit) {
						evHtml += ' (<a href="' + self.escapeHtml(ouEdit) + '" target="_blank" rel="noopener noreferrer">' + self.escapeHtml(self.t('editInWp', 'Edit')) + '</a>)';
					}
					evHtml += '</div>';
				});
				evHtml += '</div></div>';
			}

			evHtml += '</div>';
			$item.append(evHtml);

			var whyText = f.why_warrants_attention || def.why_warrants_attention;
			if (whyText) {
				$item.append('<div class="crawlory-box-why"><strong>' + self.escapeHtml(self.t('whyWarrantsAttention', 'Why it warrants attention:')) + '</strong> ' + self.escapeHtml(whyText) + '</div>');
			}
			var corrText = f.suggested_correction || def.suggested_correction;
			if (corrText) {
				$item.append('<div class="crawlory-box-correction"><strong>' + self.escapeHtml(self.t('suggestedCorrection', 'Suggested correction:')) + '</strong> ' + self.escapeHtml(corrText) + '</div>');
			}
			var verText = f.how_to_verify || def.how_to_verify;
			if (verText) {
				$item.append('<div class="crawlory-box-verify"><strong>' + self.escapeHtml(self.t('howToVerify', 'How to verify:')) + '</strong> ' + self.escapeHtml(verText) + '</div>');
			}

			// Resolved findings that were never ignored are history only; nothing to ignore or restore.
			if (isIgnored || status !== 'resolved') {
				var footerHtml = '<div class="crawlory-finding-footer">';
				footerHtml += '<button type="button" class="button button-small crawlory-btn-toggle-ignore" data-finding-id="' + self.escapeHtml(parseInt(f.id, 10) || 0) + '" data-ignore="' + (isIgnored ? 0 : 1) + '">';
				footerHtml += isIgnored ? self.escapeHtml(self.t('restoreFinding', 'Restore Finding')) : self.escapeHtml(self.t('dismissIgnore', 'Dismiss / Ignore'));
				footerHtml += '</button></div>';
				$item.append(footerHtml);
			}

			return $item;
		},

		/**
		 * Ignore or restore a finding, then refresh the drawer (including summary counts) and the dashboard.
		 */
		toggleFindingIgnore: function($btn) {
			var self = this;
			var st = self.inspect;
			var findingId = parseInt($btn.attr('data-finding-id'), 10);
			var ignoreState = parseInt($btn.attr('data-ignore'), 10) ? 1 : 0;
			if (!(findingId > 0) || $btn.prop('disabled')) return;

			var sessionId = st.sessionId;
			var pageId = st.pageId;
			function isCurrent() {
				return st.open && st.sessionId === sessionId && st.pageId === pageId;
			}

			$btn.prop('disabled', true);
			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: 30000,
				data: {
					action: 'crawlory_toggle_ignore_finding',
					nonce: crawloryData.nonce,
					finding_id: findingId,
					ignore: ignoreState
				},
				success: function(resp) {
					if (resp && resp.success && resp.data && resp.data.updated) {
						self.loadDashboardData();
						if (isCurrent()) {
							self.setDrawerStatus(ignoreState ? self.t('findingIgnored', 'Finding ignored.') : self.t('findingRestored', 'Finding restored.'), 'success');
							self.loadInspectFindings(pageId, { silent: true });
						}
						return;
					}
					if (isCurrent()) {
						var msg = (resp && resp.data && resp.data.message) ? String(resp.data.message) : self.t('errorCouldNotLoad', 'The finding could not be updated.');
						self.setDrawerStatus(msg, 'error');
						$btn.prop('disabled', false);
					}
				},
				error: function(jqXHR, textStatus) {
					if (!isCurrent()) return;
					self.setDrawerStatus('Could not update the finding. ' + self.describeAjaxError(jqXHR, textStatus), 'error');
					$btn.prop('disabled', false);
				}
			});
		},

		/**
		 * Rescan the URL shown in the drawer. Refreshes the drawer and dashboard only after a confirmed success.
		 */
		rescanInspected: function() {
			var self = this;
			var st = self.inspect;
			if (!st.open || st.rescanning) return;
			if (!st.url) {
				self.setDrawerStatus(self.t('noValidUrl', 'This item has no valid URL to rescan.'), 'error');
				return;
			}

			var sessionId = st.sessionId;
			function isCurrent() {
				return st.open && st.sessionId === sessionId;
			}

			st.rescanning = true;
			self.setRescanButtonBusy(true);
			self.setDrawerStatus(self.t('rescanProgress', 'Rescanning this URL\u2026 this can take up to a minute.'), 'progress');

			function finish() {
				if (!isCurrent()) return;
				st.rescanning = false;
				self.setRescanButtonBusy(false);
			}

			$.ajax({
				url: crawloryData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: 90000,
				data: {
					action: 'crawlory_rescan_url',
					nonce: crawloryData.nonce,
					url: st.url,
					post_id: st.postId
				},
				success: function(resp) {
					var result = (resp && resp.success && resp.data) ? resp.data : null;
					var confirmed = !!(result && result.success === true);
					var returnedPageId = result ? parseInt(result.page_id, 10) : 0;

					if (confirmed || returnedPageId > 0) {
						// The audit record changed on the server (success, or a recorded failed scan): refresh the dashboard.
						self.loadDashboardData();
					}
					if (!isCurrent()) return;
					finish();

					if (confirmed) {
						if (returnedPageId > 0) {
							st.pageId = returnedPageId;
						}
						self.setDrawerStatus(self.t('rescanComplete', 'Rescan complete. Findings refreshed.'), 'success');
						self.loadInspectFindings(st.pageId, { silent: true });
						return;
					}

					var msg = self.t('rescanFailed', 'The rescan failed.');
					if (result && result.error) {
						msg = self.t('rescanFailed', 'The rescan failed.') + ' ' + result.error;
					} else if (resp && resp.data && resp.data.message) {
						msg = self.t('rescanFailed', 'The rescan failed.') + ' ' + resp.data.message;
					}
					self.setDrawerStatus(msg, 'error');
					if (returnedPageId > 0) {
						self.loadInspectFindings(st.pageId, { silent: true });
					}
				},
				error: function(jqXHR, textStatus) {
					if (!isCurrent()) return;
					finish();
					self.setDrawerStatus('The rescan could not be completed. ' + self.describeAjaxError(jqXHR, textStatus), 'error');
				}
			});
		},

		resetFilters: function() {
			this.resetFiltersState();
			$('#crawlory-filter-search').val('');
			$('#crawlory-filter-post-type').val('');
			$('#crawlory-filter-severity').val('');
			$('#crawlory-filter-scan-status').val('');
			$('#crawlory-lifecycle-tabs .crawlory-tab-btn').removeClass('active');
			$('#crawlory-lifecycle-tabs .crawlory-tab-btn[data-lifecycle=""]').addClass('active');
			$('.crawlory-card-clickable').removeClass('crawlory-stat-card-active');
			$('.crawlory-pill').removeClass('crawlory-pill-active');
			$('#crawlory-btn-clear-rule-pill').hide();
			this.currentPage = 1;
			this.loadDashboardData();
		},

		resetFiltersState: function() {
			this.selectedRuleId = 0;
			this.selectedSeverity = '';
			this.selectedPostType = '';
			this.selectedScanStatus = '';
			this.selectedFindingStatus = '';
			this.selectedHasIssues = null;
			this.searchTerm = '';
		},

		escapeHtml: function(str) {
			if (str === null || typeof str === 'undefined' || str === '') return '';
			// Escape quotes as well so values are safe inside HTML attributes (e.g. data-title="...").
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		},

		formatNumber: function(num) {
			return (num || 0).toLocaleString();
		},

		debounce: function(func, wait) {
			var timeout;
			return function() {
				var context = this, args = arguments;
				clearTimeout(timeout);
				timeout = setTimeout(function() {
					func.apply(context, args);
				}, wait);
			};
		},

		saveSettings: function() {
			var self = this;
			var $form = $('#crawlory-settings-form');
			if (!$form.length) return;

			var $btn = $('#crawlory-btn-save-settings');
			var $notice = $('#crawlory-settings-save-notice');

			$btn.prop('disabled', true).addClass('updating-message');
			$notice.removeClass('crawlory-text-danger').addClass('crawlory-text-success')
				.html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0; vertical-align:middle;"></span> Saving settings...')
				.show();

			var formData = $form.serialize();
			var nonceVal = (typeof crawloryData !== 'undefined' && crawloryData.nonce) ? crawloryData.nonce : ($('#_crawlory_nonce').val() || '');
			var postData = formData + '&action=crawlory_save_settings&nonce=' + encodeURIComponent(nonceVal);

			$.ajax({
				url: (typeof crawloryData !== 'undefined' && crawloryData.ajaxUrl) ? crawloryData.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : 'admin-ajax.php'),
				type: 'POST',
				data: postData,
				success: function(resp) {
					$btn.prop('disabled', false).removeClass('updating-message');
					if (resp && resp.success) {
						var msg = (resp.data && resp.data.message) ? resp.data.message : 'Settings saved successfully.';
						$notice.removeClass('crawlory-text-danger').addClass('crawlory-text-success')
							.html('<span class="dashicons dashicons-yes-alt" style="color:#15803d; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(msg))
							.show();
						setTimeout(function() {
							$notice.fadeOut(400);
						}, 4000);
					} else {
						var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to save settings.';
						$notice.removeClass('crawlory-text-success').addClass('crawlory-text-danger')
							.html('<span class="dashicons dashicons-warning" style="color:#b91c1c; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(err))
							.show();
					}
				},
				error: function(xhr, status, error) {
					$btn.prop('disabled', false).removeClass('updating-message');
					var errText = 'Error communicating with server (' + (error || status || 'network') + ').';
					$notice.removeClass('crawlory-text-success').addClass('crawlory-text-danger')
						.html('<span class="dashicons dashicons-warning" style="color:#b91c1c; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(errText))
						.show();
				}
			});
		},

		clearAllAuditData: function() {
			var self = this;
			var $checkbox = $('#crawlory-confirm-clear-checkbox');
			if ($checkbox.length && !$checkbox.is(':checked')) {
				alert('Please check the confirmation box to proceed with clearing all audit data.');
				$checkbox.focus();
				return;
			}

			var confirmMsg = (typeof crawloryData !== 'undefined' && crawloryData.i18n && crawloryData.i18n.confirmClear)
				? crawloryData.i18n.confirmClear
				: 'Are you sure you want to permanently clear all audit findings, crawled pages, and resource caches? This cannot be undone.';

			if (!window.confirm(confirmMsg)) {
				return;
			}

			var $btn = $('#crawlory-btn-clear-all-data');
			var $notice = $('#crawlory-clear-data-notice').length ? $('#crawlory-clear-data-notice') : $('#crawlory-settings-save-notice');

			$btn.prop('disabled', true);
			$notice.removeClass('crawlory-text-danger').addClass('crawlory-text-success')
				.html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0; vertical-align:middle;"></span> Removing audit data...')
				.show();

			var nonceVal = (typeof crawloryData !== 'undefined' && crawloryData.nonce) ? crawloryData.nonce : ($('#_crawlory_nonce').val() || '');

			$.ajax({
				url: (typeof crawloryData !== 'undefined' && crawloryData.ajaxUrl) ? crawloryData.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : 'admin-ajax.php'),
				type: 'POST',
				data: {
					action: 'crawlory_clear_all_data',
					nonce: nonceVal
				},
				success: function(resp) {
					$btn.prop('disabled', false);
					if (resp && resp.success) {
						var msg = (resp.data && resp.data.message) ? resp.data.message : 'All audit records cleared successfully.';
						$notice.removeClass('crawlory-text-danger').addClass('crawlory-text-success')
							.html('<span class="dashicons dashicons-yes-alt" style="color:#15803d; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(msg))
							.show();

						if ($checkbox.length) {
							$checkbox.prop('checked', false);
						}

						// Reset all counters and refresh UI
						$('#crawlory-stat-pages-scanned, #crawlory-stat-total-issues, #crawlory-stat-errors, #crawlory-stat-warnings, #crawlory-stat-inconclusive').text('0');
						if (typeof self.resetScanUI === 'function') {
							self.resetScanUI();
						}
						if ($('#crawlory-audit-table').length && typeof self.loadDashboardData === 'function') {
							self.loadDashboardData();
						}

						setTimeout(function() {
							$notice.fadeOut(400);
						}, 5000);
					} else {
						var err = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to clear data. Please verify administrative permissions.';
						$notice.removeClass('crawlory-text-success').addClass('crawlory-text-danger')
							.html('<span class="dashicons dashicons-warning" style="color:#b91c1c; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(err))
							.show();
					}
				},
				error: function(xhr, status, error) {
					$btn.prop('disabled', false);
					var errText = (xhr.status === 403 || xhr.status === 401)
						? 'Session or nonce expired. Please refresh the page and try again.'
						: 'Error communicating with server (' + (error || status || 'network') + ').';
					$notice.removeClass('crawlory-text-success').addClass('crawlory-text-danger')
						.html('<span class="dashicons dashicons-warning" style="color:#b91c1c; vertical-align:middle; margin-right:4px;"></span> ' + self.escapeHtml(errText))
						.show();
				}
			});
		}
	};

	$(document).ready(function() {
		Crawlory.init();
	});

	window.Crawlory = Crawlory;
	window.TSD = Crawlory;
})(jQuery);
