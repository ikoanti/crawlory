=== Crawlory - Technical SEO Dashboard ===
Contributors: ikoanti
Donate link: https://crawlory.app
Tags: seo, technical seo, audit, broken links, meta tags
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-contained technical SEO auditing plugin that discovers technical defects, inspects live evidence, and verifies corrections.

== Description ==

Crawlory is a self-contained technical SEO audit dashboard that discovers defects, inspects live evidence, opens the exact editor, and verifies corrections.

Audits server-rendered public HTML across 33 comprehensive checks (19 core rules, 8 priority categories, and 6 secondary categories) without relying on external SaaS crawlers or paid third-party APIs.

= Key Capabilities =
* 33 Comprehensive Checks: Titles, descriptions, H1 hierarchy, indexability, canonical URLs, broken links, image alt texts, OpenGraph, Twitter Cards, schema, and performance.
* Live HTML Evidence: Inspect exact DOM snippets and response headers directly from the dashboard.
* Background Crawling: Non-blocking scans with WP-Cron scheduling, pause/resume, and batch throttling.
* Privacy-Focused: All data stays in your WordPress database. Zero external API calls.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/crawlory` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the Crawlory menu in your admin dashboard to run your first technical SEO audit.

== Frequently Asked Questions ==

= Does Crawlory send data to external servers? =
Audit results, crawl data, and settings are stored locally on your own WordPress hosting server inside your MySQL database. Crawlory makes local HTTP requests to your site's own public pages and resources to inspect them as an anonymous visitor. When checking external links or referenced external assets, the scanner contacts those external destination servers directly to verify their HTTP status codes. No audit data, analytics, or telemetry are ever sent to Crawlory or third-party servers.

= Which post types can Crawlory audit? =
Crawlory can audit posts, pages, WooCommerce products, and any public custom post types registered on your site.

= What outbound requests does Crawlory perform? =
* Local site requests: Crawlory makes HTTP GET requests to your site's own published pages and local static resources (images, scripts, stylesheets) to analyze rendered HTML and response headers. These requests are anonymous and do not pass administrator credentials or cookies.
* External link and resource checks: If enabled, Crawlory sends HTTP GET/HEAD requests to external URLs referenced in your content (such as outbound hyperlinks or externally hosted media) solely to check if they are reachable (detecting 404 broken links or broken redirect chains).
* No other outbound communications exist: Crawlory contains no cloud SaaS connections, telemetry, tracking pixels, licensing pings, or third-party analytics.

== Privacy ==

Crawlory is designed with a local-first privacy model:
* Local Storage: All scan configurations, discovered inventory, audit metrics, extracted links, and detected issues are stored entirely within plugin-owned tables in your local WordPress database (`wp_crawlory_*`).
* Server-Side Scanning: Crawling is performed directly by your WordPress web server using WordPress standard HTTP APIs (`wp_safe_remote_get`). Requests do not include administrative authentication or user session cookies.
* External Link Verification: When verifying outbound links or remote resources referenced in your site's content, your server connects directly to the respective destination servers to inspect HTTP status codes.
* Zero Cloud/SaaS Telemetry: No audit results, site content, or visitor analytics are collected, exported, or transmitted to Crawlory infrastructure or third-party services.

== Changelog ==

= 1.3.11 =
* Compatibility Declaration: Declared tested compatibility up to WordPress 7.1 while maintaining minimum core requirement of WordPress 5.8.
* Submission Report Resolution & Internationalization Comments: Added explanatory /* translators: ... */ comments preceding translatable strings containing runtime placeholders across post meta box, scanner, activator, database, and admin localization arrays, ensuring clear translation context for all placeholders.
* Post Meta Box Escaped Output: Properly structured plural counter strings in class-crawlory-post-meta-box.php to evaluate sprintf(_n(...)) before escaping output with esc_html(), retaining integer counts and zero values while resolving OutputNotEscaped findings.
* Database Query Preparation & Schema Allowlisting: Hardened update_scan() and preserve_queue_row() with strict column schema allowlists, removed unreachable unprepared fallback branches, and directly parameterized queries with explicit SQL NULL handling, preserving atomic worker-token and lease-expiry conditions compatible with WordPress 5.8+.
* Stream Response Annotation: Annotated direct HTTP response streaming handle on php://output in class-crawlory-exporter.php to document stream-specific closure while maintaining nonce verification, manage_options capability checks, and spreadsheet formula-injection sanitization.
* Explicit Queue Insertion Outcome & Startup Failure Protection: Updated Crawlory_DB::add_scan_item() to explicitly distinguish newly inserted items ('inserted'), existing queue entries for the same scan and URL ('existing'), and invalid inputs or database failures ('failed') with structured error messages; verified existing-item SELECT error immediately to abort before INSERT on lookup errors; wrapped selected-URL queueing, count retrieval, and inventory persistence in Throwable handlers, preserving queued items, recording failed startup state when database writes permit without replacing unavailable counts with zero or marking discovery complete, and returning structured error responses without spawning workers; stopped inventory discovery on enqueue failure without advancing the persisted cursor past the failed item; preserved existing queued items and propagated structured errors through caller error handlers.
* Lossless Queue Uniqueness & Original Audit Record Preservation: Preserved immutable, complete original snapshots of all affected queue rows (including the canonical row) in crawlory_scan_items_preserved before modifying or deleting any record; implemented NULL-aware insertion preserving actual SQL NULL for nullable columns (post_title, post_type, slug, worker_token, claimed_at, completed_at, http_status, error_message) while preserving zero, empty string, and null distinctly according to column schema without passing null through %s or %d; verified serialization via wp_json_encode() and json_last_error() while keeping original JSON snapshots unchanged; checked archive lookup errors immediately; ensured retries preserve first original snapshots without overwriting raw_payload on duplicate keys; verified existing archive record validity before accepting preservation; strictly preserved canonical audit outcome integrity by prohibiting borrowing or mutating outcome fields (findings_count, http_status, item_status, completed_at, worker_token), guaranteeing newer clean results with zero findings remain zero even when older duplicates had findings while keeping all historical attempts recoverable; compared original unmerged snapshots for conflict detection.
* Complete Migration Ownership & Database-Level Fencing: Required the explicit lock token acquired by the current migration caller and eliminated shared-lock option token adoption; included wp_options in transaction and locking capability preflights because SELECT ... FOR UPDATE protects ownership, aborting safely with actionable messages without altering WordPress core tables; bounded migration work by both row counts and elapsed execution time, processing oversized duplicate groups in chunks without loading full history into memory; renewed ownership during long-running work and halted safely upon renewal failure; protected all destructive writes and commits against ownership changes using database-level ownership fencing (row locking via SELECT ... FOR UPDATE and fenced multi-table DELETE/UPDATE joins against the active unexpired lock token); handled zero affected rows from fenced canonical updates explicitly by verifying locked ownership and intended persisted values before accepting a no-op; kept the ownership row locked through commit and rolled back unfinished work if ownership or required updates cannot be confirmed; guarded index-creation phase before and after ALTER TABLE against overlapping owners; preserved committed progress across batches on interruption and left schema completion unset until consolidation and index verification both succeed.
* Preservation Table Unique Index Verification & Duplicate Archive Protection: Checked SHOW INDEX and ALTER TABLE errors immediately; verified the actual single-column unique original_item_id definition on the preservation table after creation or upgrade rather than relying on index names; stopped before consolidation or deletion if uniqueness cannot be confirmed; detected existing archive duplicates that prevent unique index creation, leaving all existing preservation records untouched, ensuring repeated migration attempts do not create additional records, and returning an actionable error naming the preservation table and explaining that duplicate archive records require resolution.
* Reliable Deduplication & Counter Integrity: Normalized and deduplicated selected target URLs prior to calculating scan totals; calculated discovered and total counts from distinct persisted inventory (COUNT(DISTINCT url_hash)) and pending counts from actual pending queue rows; guaranteed accurate recount recovery on retried batches following cursor write interruptions without double-counting existing rows.
* Simplified Coordination Cleanup: Eliminated per-scan transient locking dependencies (crawlory_scan_lock and crawlory_active_scan_id) in normal scans in favor of authoritative database worker lease management; removed redundant per-slice legacy cleanup calls from worker error paths; corrected legacy coordination cleanup queries to avoid comparing transient timeout timestamps with scan IDs; prevented race conditions and shared-cache collisions without read-then-delete option operations.
* Ownership-Protected Scan Updates: Required scan ID, matching worker token, and unexpired lease in the atomic UPDATE condition of update_scan(); differentiated database errors, lost or expired ownership, successful changes, and legitimate unchanged data; authoritatively verified unexpired ownership and requested persisted values before accepting zero-affected-row results; preserved intentional non-worker administrator pause and cancel operations through a clearly separate path.
* Consistent Worker Token Propagation & Write Result Verification: Passed worker tokens for all worker-owned state, stage, counter, single-URL audit failure, completion, and reconciliation-failure writes; verified every required update result; halted dependent processing without advancing local stages when a required database write fails or ownership is lost; ran completion side-effects only after confirmed completion persistence.
* Migration Barrier Renewal Verification: Captured and checked barrier-timeout UPDATE results in both renewal branches of renew_lock(); returned failure on database error or lost ownership; authoritatively verified current owner, unexpired lock, barrier token, and required timeout before accepting zero-affected-row no-ops; propagated renewal failure directly to the migration caller.
* Multi-Table Transaction Safety & Fail-Safe Reconciliation: Inspected participating table engines (pages, findings, links, and duplicate groups) prior to destructive writes; aborted reconciliation and persistence with actionable errors when transaction support is unconfirmed or unknown; checked START TRANSACTION, all reads and writes, and COMMIT; reported rollback failures without masking original errors.
* Database Read-Error Handling & Boundary Exception Safety: Replaced ambiguous null-and-error checks with immediate database error checks; handled exceptions from every get_scan_item_counts() query and the final refresh_all_page_findings_counts() call at worker and status boundaries, returning structured error responses; preserved committed audit data, recorded useful error messages, and retained accurate resumable/failed stages; ensured status polling remains strictly read-only without zeroing counts; marked finalization_stage and status completed only after all required reconciliation, counter queries, counter writes, and completion writes succeed; prevented calling get_scan_status() on count query failure.
* Rule 27 Settings Respect: Passed user-configured is_rule_enabled(27) and get_rule_severity(27) into recalculate_orphan_pages() across both single-URL and full-scan finalization paths; resolved existing active Rule 27 findings within checked transactions and refreshed page finding counts when disabled; preserved ignored flags and audit history without generating new orphan findings.
* Synchronized Cross-Page Findings Counts: Refreshed finding counts across all affected pages via set-based SQL after duplicate and orphan changes, including pages indirectly affected by single-URL scans; maintained consistent active non-ignored finding definitions and updated scan totals while checking count queries and counter writes for failures.
* Extractable JavaScript Pluralization: Replaced dynamic string parameters in pluralization with extractable literal singular/plural message pairs using wp.i18n._n() and wp.i18n.sprintf() for H1 counts, character lengths, issue counts, rule occurrences, and drawer history; preserved wp-i18n dependencies and script translation registration.
* Expired Lease Renewal Rejection & Ownership Verification: Required matching ownership and an unexpired existing lease as part of the atomic renewal condition in both scanner worker leases and migration locks; verified zero-row results authoritatively against the database; immediately stopped protected work when renewal fails.
* Consistent UTC Audit Timestamps: Applied uniform UTC timestamp storage across page scans, fetch failures, findings, and scan lifecycle updates using Crawlory_Helper::now_utc(); preserved ambiguous historical timestamps without guessing timezones and determined freshness conservatively from the latest attempt outcome; converted timestamps for display only.
* Measurement Availability & Stale Indicators: Removed false availability checks based solely on default h1_count = 0, eliminating hasH1Data fallbacks in dashboard, editor, and Inspect; displayed Unavailable for HTML measurements on first failed scans; retained successfully evaluated empty titles, descriptions, and zero H1 counts as valid measurements, displaying clear Stale indicators after later failures or content changes.
* Settings Migration Precedence: Preserved existing Crawlory settings, migrated missing keys from legacy tsd_settings, and applied default options only for remaining unconfigured settings without premature default creation on activation.

= 1.3.10 =
* Consolidated Migration & Schema Coordinator: Established single migration coordinator responsible for all schema mutations; updated activation and plugins_loaded hooks to respect migration failures and lock contention without executing uncoordinated fallback table creation outside locks; separated completion verification from runtime scanner readiness checks.
* Migration Lock Ownership & Cache Consistency: Enforced authoritative lock ownership checks with option cache invalidation (wp_cache_delete); implemented atomic conditional lock releases matching the current owner token; added lease renewal (renew_lock) during long migrations and re-verified lock ownership before recording completion; preserved data without destructive drops when legacy and current tables coexist.
* Failure-Safe Audit Persistence: Implemented transactional persistence checking START TRANSACTION, every table write, and COMMIT (returning success only after confirmed commit); added engine transaction support verification with non-transactional fallback; preserved last successful audit data when a subsequent scan attempt fails; prevented updating or returning rolled-back page IDs after newly inserted page rollbacks; re-verified migration/reset barriers and worker lease immediately before persistence.
* Audit Completeness & Dashboard Rendering: Added explicit scan_id column to page records to bind page audits directly to their audit run; evaluated all audit lifecycle states (pending, running, paused, interrupted, cancelled, failed, completed) including duplicate/orphan finalization; handled finalization errors before generic running logic; represented findings count and analysis completeness independently; strictly prohibited displaying Clean unless all required checks completed, result is current, and active findings are zero; preserved clear distinctions between evaluated missing, unavailable measurements, and stale measurements across initial load and AJAX redraws.
* Memoized Schema Inspection: Implemented process-level schema verification memoization to avoid redundant information_schema queries across ordinary requests and batch URL processing while preserving active barrier and lock contention checks; invalidated schema readiness on upgrades and migration retries.
* Submission & Quality Compliance: Preserved Crawlory prefixes, text domain crawlory-technical-seo-dashboard, contributor ikoanti, and secure XML entity parsing without deprecated libxml calls.

= 1.3.9 =
* Database Schema & Upgrade Path: Incremented database schema version to 1.3.9 to account for has_incomplete_resources; ensured schema upgrades execute for existing installations including those previously marked complete for 1.3.8; preflighted and explicitly verified all required tables and columns before recording schema completion; supports fresh installations, upgrades from v1.3.6/v1.3.7, and recovery from incomplete v1.3.8 upgrades without requiring deactivation or deleting audit history.
* Atomic Migration Lock Ownership: Implemented atomic lock acquisition via add_option() and compare-and-swap SQL updates on expired locks; assigned unique UUID owner tokens; verified ownership prior to schema mutations and before releasing the lock to prevent cross-request lock deletion; rechecked completion state after acquiring the lock.
* Mandatory Schema & Migration Readiness: Enforced persistent readiness checks blocking scan starts, resumes, background workers, single-URL audits, and findings commits whenever migration is pending or failed; preserved administrative diagnostics notice with a recoverable "Retry Migration" button; treated table discovery query errors as fatal halts rather than false negatives to prevent data loss when old and new tables coexist.
* Inspect Response & Completeness Calculation: Restored authoritative scan_status retrieval from the page record; derived audit completeness directly from authoritative audit state calculation rather than hardcoding unknown/incomplete; aligned Inspect drawer fields with dashboard and editor findings; surfaced explicit error messages on malformed data.
* Authoritative Audit State & Staleness: Explicitly handled scan_status = 'stale' after WordPress content edits, retaining prior measurements while clearly flagging them as stale; strictly prohibited returning "Clean" status unless an explicit, complete, successful audit occurred; associated pending duplicate and orphan analysis with the active scan ID; properly handled paused, interrupted, cancelled, and failed finalization states; avoided repeated per-row scan status queries via shared context caching.
* Transactional Persistence Consistency: Enforced staged record commits (scan_status = 'processing'), wrapped page audit data, links, and findings updates inside database transactions with rollback protection (mark_page_failed); prevented publishing successful page states before required metadata, links, and findings are durably saved; preserved snapshot on failures and propagated write errors to dashboard, queue, editor, and Inspect.
* Scheduled-Task Cron Migration: Preserved user scan_schedule preferences; checked scheduling and unscheduling outcomes; unscheduled legacy continuation events across all registered scan-ID argument combinations via _get_cron_array(); prevented duplicate cron schedules and avoided invalidating healthy workers during migration retries.
* Translations & Localized Interface: Connected localized translation strings via crawloryData.i18n to JavaScript table rendering, status badges, loading states, tooltips, Inspect drawer, and action buttons; supported proper plural forms for counts, characters, occurrences, and H1 tags; maintained WordPress.org text domain crawlory-technical-seo-dashboard.

= 1.3.8 =
* Atomic Migration Engine: Dedicated migration completion flag (1.3.8) ensuring subsequent requests are harmless no-ops; atomic lock with token ownership and 120-second lease expiration; in-progress migrations reported as unavailable; settings reloaded into singleton instance upon upgrade.
* Failure-Safe Table Operations: Exact schema preflight checks for all 8 table pairs without wildcard escaping issues; coordinated atomic multi-table rename; coexistence safety barrier halting migration to preserve data if old and new tables both exist; persistent administrative error logging; migration barrier transient shielding background scanners from partial schemas.
* Lifecycle Resolution Hardening: Fixed rule ID resolution assignment; restricted finding resolution strictly to rules that explicitly evaluated successfully without defect; preserved findings for disabled, unknown, failed, and inconclusive checks; verified database write returns across updates, inserts, resolutions, and page counters.
* Unified Authoritative Audit State: Server-side state computation wired identically across Dashboard table, Inspect drawer, Post Editor meta box, and CSV exports; prevented false "Clean" badge when duplicate analysis is pending or checks remain incomplete; guarded un-evaluated items from showing false missing or zero counts; marked retained historical metrics as stale following failures.
* Finding View Filters: Aligned query filters across dashboard pagination and CSV exports so that "Ignored", "Resolved", "Persistent", and "New" views display exclusively their intended records.
* Limit-Plus-One Response Guard: Enforced limit-plus-one bounded response transport limits on HTML and XML sitemap downloads, reliably catching truncated streams without treating corrupt partial documents as valid audit evidence.
* Scheduled-Task Migration: Read scan_schedule directly, registered custom cron intervals (weekly, monthly) prior to scheduling, verified scheduling return status, and purged legacy continuation events.
* Compatibility & Submission Cleanup: Removed obsolete global tsd() helper and legacy class aliases in favor of clean Crawlory_ prefix; unified admin menu slug to 'crawlory' with seamless navigational redirects for old links; translated all user-facing JavaScript and administrative status strings; added graceful checks and admin notices for DOM and SimpleXML PHP extensions; preserved official WordPress.org text domain crawlory-technical-seo-dashboard.

= 1.3.7 =
* HTTP Fetcher Contract: Repaired terminal response assignment to ensure Content-Type, headers, X-Robots-Tag, canonical Link header, and HTTP status codes are populated across all outcome branches.
* Oversized Response Protection: Enforced 1MB response size guard and Content-Length header verification to safeguard memory budget.
* Parse Failure Resilience: Prevented HTML parse errors from wiping valid metadata with zeroes or producing false missing-title, missing-description, or missing-H1 findings; preserved existing successful audit metrics.
* HTML Document Title Precision: Updated XPath title extraction to strictly exclude inline SVG <title> elements from SEO document title evaluation.
* XML/Libxml Security: Removed all deprecated libxml_disable_entity_loader() calls across HTML and sitemap parsers; enforced entity expansion suppression via DOMDocument properties, LIBXML_NONET, 5MB sitemap payload limits, pre-parse DTD/ENTITY rejection, and reliable try-finally error restoration.
* WordPress.org Prefix Alignment & Migration: Implemented atomic table migrations (wp_tsd_* to wp_crawlory_*), option migrations, scheduled cron hook realignment, active worker lease clearing, and backward-compatible class/function aliases (TSD_ / tsd()).
* Findings Lifecycle Hardening: Guaranteed that previous findings only resolve when an applicable rule is explicitly evaluated without defect; prevented premature resolution on disabled rules or inconclusive scans.
* Dashboard UI & Concurrency: Added AJAX abort controllers and sequence generation guards to eliminate race conditions on rapid tab filtering; dynamically read user-configured thresholds; corrected badge rendering to prevent false "Clean" indicators on failed, pending, or error responses.

= 1.3.6 =
* Security & Redirect Hardening: Replaced automatic HTTP redirection in fetcher with bounded validation loop enforcing same-site policy on every hop and strict subdirectory boundary matching.
* Nonce & Input Validation: Added strict structural type validation and scalar checks for settings payloads and read-only navigational redirects.
* Static Analysis Hardening: Refactored scanner discovery queries into direct prepared SQL templates.
* Release Packaging: Created standard WordPress.org readme.txt, expanded accurate privacy disclosures, and excluded developer test runners from distribution archive.
* Compatibility Note: Declared tested compatibility up to WordPress 7.1 per WordPress.org directory standards.

= 1.3.5 =
* Security & Nonce Verification: Added direct check_ajax_referer validation across all admin AJAX handlers and sanitized incoming settings payloads.
* Query Parameterization: Fully parameterized dynamic filter queries for CSV exports and paginated page listings with wpdb prepare placeholders.
* Schema Integrity: Applied esc_sql escaping across all table references in database manager, migrations, and uninstaller.
* Optimization: Removed discouraged load_plugin_textdomain call in favor of core automatic translation loading.
* Asset Loading: Streamlined admin asset hook checking without raw query parameter lookups.

= 1.3.4 =
* Security: Parameterized dynamic SQL queries across duplicate manager, scanner inventory, and sitemap pruning.
* Compatibility: Replaced deprecated utf8_decode() in normalizer with iconv/multibyte character count.
* Performance & File System: Streamlined CSV export output buffer handling without direct filesystem calls.
* Internationalization: Added languages/ directory for translation pack compatibility.
* Sanitization: Fixed input sanitization and unslashing for redirect and flash notice parameters.
* Test Hardening: Replaced offloaded fixture URLs, escaped CLI test outputs, and parameterized test assertion queries.

= 1.3.3 =
* Translation Domain Alignment: Unified all internationalization calls and plugin headers to use the literal 'crawlory-technical-seo-dashboard' text domain matching the WordPress.org plugin slug.
* Ordered Multi-Placeholder Translations: Updated translatable strings containing multiple placeholders to use positional specifiers (%1$s, %2$s).
* Translators Context Comments: Added explanatory translators comments immediately preceding translatable strings with runtime variables.
* Functional Identifier Integrity: Preserved existing database tables, admin menu slugs, options, action hooks, AJAX endpoints, and Crawlory_ prefixes.

= 1.3.2 =
* Dashboard Table UX & Readability: Constrained column widths, added keyboard-accessible tooltips, collapsed repeated finding badges, and improved text contrast.
* Unicode & Georgian Support: Integrated Georgian-compatible font stack across tables, monospace URL strings, and tooltips.
* Sticky Actions: Sticky pinning with opaque row-matching background and accessible labels.

= 1.3.1 =
* Inspect Drawer Repair: Resolved duplicate drawer markup issue and fixed DOM focus management.
* Attribute Escaping: Hardened title quoting in data-* attributes.

= 1.3.0 =
* Enterprise Resilience: Compound key schema for resource cache, durable resource checks, and unified server-side reset operation.
