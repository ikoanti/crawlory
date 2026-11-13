<?php
/**
 * Settings manager for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Settings {

	/**
	 * Option key in wp_options.
	 */
	const OPTION_KEY = 'crawlory_settings';

	/**
	 * Cached settings.
	 *
	 * @var array
	 */
	private $settings = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load();
	}

	/**
	 * Load settings from database.
	 */
	public function load() {
		$saved          = get_option( self::OPTION_KEY, array() );
		$defaults       = self::get_default_settings();
		$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( null === $this->settings ) {
			$this->load();
		}
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * Update all settings.
	 *
	 * @param array $new_settings New settings array.
	 * @return bool
	 */
	public function save( array $new_settings ) {
		$sanitized = $this->sanitize_settings( $new_settings );
		$this->settings = $sanitized;
		return update_option( self::OPTION_KEY, $sanitized );
	}

	/**
	 * Get default settings array.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'post_types'           => array( 'page', 'post', 'product' ),
			'include_homepage'     => true,
			'batch_size'           => 20,
			'request_delay_ms'     => 150,
			'max_retries'          => 2,
			'request_timeout'      => 15,
			'scan_schedule'        => 'disabled',
			'excluded_urls'        => '',
			'title_min_length'     => 30,
			'title_max_length'     => 60,
			'desc_min_length'      => 70,
			'desc_max_length'      => 155,
			'url_max_length'       => 115,
			'data_retention_days'  => 90,
			'purge_on_uninstall'   => false,
			'rules'                => self::get_default_rules_config(),
		);
	}

	/**
	 * Default configurations for all 19 SEO rules.
	 *
	 * @return array
	 */
	public static function get_default_rules_config() {
		return array(
			1  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'editorial' ),
			2  => array( 'enabled' => true, 'severity' => 'error',   'category' => 'technical' ),
			3  => array( 'enabled' => true, 'severity' => 'error',   'category' => 'technical' ),
			4  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'editorial' ),
			5  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'technical' ),
			6  => array( 'enabled' => true, 'severity' => 'error',   'category' => 'technical' ),
			7  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'editorial' ),
			8  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'url_policy' ),
			9  => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'url_policy' ),
			10 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'technical' ),
			11 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'url_policy' ),
			12 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'url_policy' ),
			13 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'url_policy' ),
			14 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'technical' ),
			15 => array( 'enabled' => true, 'severity' => 'info',    'category' => 'editorial' ),
			16 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'editorial' ),
			17 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'technical' ),
			18 => array( 'enabled' => true, 'severity' => 'error',   'category' => 'structure' ),
			19 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'structure' ),
			// Expanded Priority Categories (Rules 20 - 27)
			20 => array( 'enabled' => true, 'severity' => 'error',   'category' => 'links' ),
			21 => array( 'enabled' => true, 'severity' => 'error',   'category' => 'technical' ),
			22 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'indexing' ),
			23 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'indexing' ),
			24 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'canonical' ),
			25 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'links' ),
			26 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'sitemap' ),
			27 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'structure' ),
			// Secondary Categories (Rules 28 - 33)
			28 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'technical' ),
			29 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'links' ),
			30 => array( 'enabled' => true, 'severity' => 'warning', 'category' => 'structured_data' ),
			31 => array( 'enabled' => true, 'severity' => 'info',    'category' => 'editorial' ),
			32 => array( 'enabled' => true, 'severity' => 'info',    'category' => 'social' ),
			33 => array( 'enabled' => true, 'severity' => 'info',    'category' => 'structure' ),
		);
	}

	/**
	 * Definition and metadata for all 19 SEO rules.
	 *
	 * @return array
	 */
	public static function get_rule_definitions() {
		return array(
			1 => array(
				'id'                     => 1,
				'name'                   => 'SEO title above 60 characters',
				'description'            => 'Flag each non-empty title containing more than 60 characters, including spaces.',
				'guidance'               => 'Search engines typically truncate page titles in desktop SERPs beyond 60 characters (~600px). Keep titles concise and front-load primary target keywords.',
				'why_warrants_attention' => 'Search engines truncate titles beyond 60 characters in desktop SERPs, reducing click-through rates.',
				'suggested_correction'   => 'Shorten the title in your SEO plugin or theme header to 60 characters or fewer while keeping primary keywords front-loaded.',
				'how_to_verify'          => 'Rescan the page to confirm character count is 60 or below.',
				'category'               => 'editorial',
				'default_sev'            => 'warning',
			),
			2 => array(
				'id'                     => 2,
				'name'                   => 'Missing or empty SEO title',
				'description'            => 'Flag a missing title element or a title containing only whitespace. Distinguish missing from empty.',
				'guidance'               => 'Every indexed HTML document must have a non-empty <title> tag in the <head>. Search engines will invent arbitrary titles if none is provided.',
				'why_warrants_attention' => 'A missing or empty title prevents search engines from displaying an accurate link in search results, severely harming rankings and clicks.',
				'suggested_correction'   => 'Add a unique, descriptive page title in your WordPress editor or SEO plugin.',
				'how_to_verify'          => 'Inspect document head for <title> tag, then rescan URL.',
				'category'               => 'technical',
				'default_sev'            => 'error',
			),
			3 => array(
				'id'                     => 3,
				'name'                   => 'Multiple SEO titles',
				'description'            => 'Flag more than one HTML title element in the document head, even if their contents are identical.',
				'guidance'               => 'Multiple <title> tags in <head> indicate conflicting theme templates or plugins. Search engines may arbitrarily select one or ignore both.',
				'why_warrants_attention' => 'Multiple title tags create ambiguity. Search engines may arbitrarily choose one or ignore both, leading to unpredictable snippets.',
				'suggested_correction'   => 'Check for conflicts between your active SEO plugin and theme header.php file to ensure only one title is output.',
				'how_to_verify'          => 'View page source and verify exactly one <title> element exists, then rescan.',
				'category'               => 'technical',
				'default_sev'            => 'error',
			),
			4 => array(
				'id'                     => 4,
				'name'                   => 'Meta description above 155 characters',
				'description'            => 'Flag each non-empty description containing more than 155 characters, including spaces.',
				'guidance'               => 'Descriptions over 155-160 characters are typically truncated in search snippets with an ellipsis (...). Focus on the core value proposition in the first 140 characters.',
				'why_warrants_attention' => 'Meta descriptions over 155 characters are truncated with an ellipsis (...) in SERPs, potentially cutting off key calls to action.',
				'suggested_correction'   => 'Edit the meta description to under 155 characters while keeping the primary value proposition concise.',
				'how_to_verify'          => 'Rescan the URL and verify character count is <= 155.',
				'category'               => 'editorial',
				'default_sev'            => 'warning',
			),
			5 => array(
				'id'                     => 5,
				'name'                   => 'Missing or empty meta description',
				'description'            => 'Flag a missing description tag, a missing content attribute, or whitespace-only content. Distinguish these cases.',
				'guidance'               => 'A missing or empty meta description allows search engines to pull arbitrary snippet text from page copy, which often yields suboptimal click-through rates.',
				'why_warrants_attention' => 'Without a meta description, search engines generate random excerpts from page text that may not effectively entice searchers.',
				'suggested_correction'   => 'Write a compelling, relevant meta description in your SEO plugin.',
				'how_to_verify'          => 'Inspect page HTML for <meta name="description"> tag and rescan.',
				'category'               => 'technical',
				'default_sev'            => 'warning',
			),
			6 => array(
				'id'                     => 6,
				'name'                   => 'Multiple meta descriptions',
				'description'            => 'Flag more than one meta description tag, even if their contents are identical.',
				'guidance'               => 'Duplicate meta description tags create ambiguity for search crawlers and typically indicate an active SEO plugin conflicting with theme header output.',
				'why_warrants_attention' => 'Duplicate meta description tags confuse search crawlers and indicate theme or plugin header output duplication.',
				'suggested_correction'   => 'Disable redundant description output in your theme or SEO plugin settings.',
				'how_to_verify'          => 'Check HTML head to confirm only one meta description tag is present, then rescan.',
				'category'               => 'technical',
				'default_sev'            => 'error',
			),
			7 => array(
				'id'                     => 7,
				'name'                   => 'SEO title below 30 characters',
				'description'            => 'Flag non-empty titles containing fewer than 30 characters. Empty titles belong under rule 2.',
				'guidance'               => 'Short titles under 30 characters underutilize valuable search snippet space and often lack sufficient topical specificity for competitive ranking.',
				'why_warrants_attention' => 'Titles under 30 characters underutilize snippet preview space and often lack sufficient topical specificity for competitive ranking.',
				'suggested_correction'   => 'Expand the title to at least 30 characters by adding branding or primary topic details.',
				'how_to_verify'          => 'Rescan the page and verify character count is >= 30.',
				'category'               => 'editorial',
				'default_sev'            => 'warning',
			),
			8 => array(
				'id'                     => 8,
				'name'                   => 'Non-Latin characters in URLs or slugs',
				'description'            => 'Detect non-Latin letters in the decoded URL path and stored slug. Show the affected characters and location.',
				'guidance'               => 'Non-Latin characters (such as Cyrillic, Georgian, Arabic, or Han scripts) become heavily percent-encoded in copied links and external systems. If your domain policy mandates ASCII or transliterated slugs, update the permalink.',
				'why_warrants_attention' => 'Non-Latin characters become heavily percent-encoded in copied links and external systems, which can make links fragile.',
				'suggested_correction'   => 'If your site policy requires ASCII URLs, update the post slug to transliterated or Latin characters.',
				'how_to_verify'          => 'Inspect permalink and slug in WordPress editor, then rescan.',
				'category'               => 'url_policy',
				'default_sev'            => 'warning',
			),
			9 => array(
				'id'                     => 9,
				'name'                   => 'Emojis in URLs',
				'description'            => 'Detect emoji characters and sequences in the decoded URL path or query string.',
				'guidance'               => 'Emojis in URLs cause encoding issues in email clients, analytics platforms, and external backlink indexing. URLs should remain clean alphanumeric paths.',
				'why_warrants_attention' => 'Emojis in URLs cause encoding issues in email clients, analytics platforms, and external backlink indexing.',
				'suggested_correction'   => 'Remove emoji characters from the post slug in WordPress.',
				'how_to_verify'          => 'Rescan the URL to confirm emoji characters are absent.',
				'category'               => 'url_policy',
				'default_sev'            => 'warning',
			),
			10 => array(
				'id'                     => 10,
				'name'                   => 'Missing or empty image alt text',
				'description'            => 'Identify rendered images with missing alt attributes or empty/whitespace-only alt text. Report each image separately.',
				'guidance'               => 'Screen readers and image search crawlers rely on the alt attribute. Purely decorative images may intentionally use empty alt="" which can be dismissed after review.',
				'why_warrants_attention' => 'Missing alt attributes hurt web accessibility for visually impaired visitors and eliminate image search visibility.',
				'suggested_correction'   => 'Add descriptive alt text to the image in the WordPress Media Library or Block Editor.',
				'how_to_verify'          => 'Check image element markup and rescan page.',
				'category'               => 'technical',
				'default_sev'            => 'warning',
			),
			11 => array(
				'id'                     => 11,
				'name'                   => 'Spaces in URLs',
				'description'            => 'Detect literal spaces and percent-encoded spaces (%20 or +) in the URL path or query string.',
				'guidance'               => 'Spaces in URLs break sharing on social networks and create fragile permalinks. Use hyphens (-) to separate words in slugs.',
				'why_warrants_attention' => 'Spaces in URLs create fragile permalinks that break when shared across social media or messaging platforms.',
				'suggested_correction'   => 'Replace spaces in the post slug with hyphens (-).',
				'how_to_verify'          => 'Confirm permalink contains no literal spaces or %20 encoding, then rescan.',
				'category'               => 'url_policy',
				'default_sev'            => 'warning',
			),
			12 => array(
				'id'                     => 12,
				'name'                   => 'Uppercase characters in URLs',
				'description'            => 'Detect uppercase letters in the decoded path or query string. (Ignores hex characters in percent-encoding syntax).',
				'guidance'               => 'Web servers and caching layers may treat URLs with uppercase characters as distinct URLs or cause duplicate content issues. Standardize on lowercase slugs.',
				'why_warrants_attention' => 'Uppercase letters in URLs can cause duplicate content issues and caching misses on case-sensitive servers.',
				'suggested_correction'   => 'Update the permalink slug to all-lowercase characters in WordPress editor.',
				'how_to_verify'          => 'Confirm permalink is lowercase, then rescan.',
				'category'               => 'url_policy',
				'default_sev'            => 'warning',
			),
			13 => array(
				'id'                     => 13,
				'name'                   => 'URLs above 115 characters',
				'description'            => 'Flag a full absolute permalink containing more than 115 characters, including its scheme and hostname.',
				'guidance'               => 'Excessively long URLs are harder to read in search results, more prone to truncation in social sharing, and often indicate bloated taxonomy structures.',
				'why_warrants_attention' => 'Excessively long URLs are harder to read, more prone to truncation, and often indicate bloated taxonomy structures.',
				'suggested_correction'   => 'Shorten the post slug to remove unnecessary stop words.',
				'how_to_verify'          => 'Rescan URL and verify permalink length is <= 115 characters.',
				'category'               => 'url_policy',
				'default_sev'            => 'warning',
			),
			14 => array(
				'id'                     => 14,
				'name'                   => 'Duplicate SEO titles',
				'description'            => 'Identify matching non-empty titles across distinct successfully scanned URLs in the selected scope.',
				'guidance'               => 'Duplicate titles cause internal keyword cannibalization and force search engines to guess which page is the canonical authority for the topic.',
				'why_warrants_attention' => 'Duplicate titles cause internal keyword cannibalization and force search engines to guess which page is canonical.',
				'suggested_correction'   => 'Differentiate the SEO title of each affected page to reflect its unique topic or product.',
				'how_to_verify'          => 'Rescan affected URLs to verify unique titles are indexed.',
				'category'               => 'technical',
				'default_sev'            => 'warning',
			),
			15 => array(
				'id'                     => 15,
				'name'                   => 'SEO title matches H1',
				'description'            => 'Compare the SEO title with H1 text. Flag normalized exact matches and identify which H1 matched.',
				'guidance'               => 'Informational notice: Having an SEO title identical to your H1 is common, but diversifying them slightly allows you to target secondary keywords in the title while keeping the H1 conversational for visitors.',
				'why_warrants_attention' => 'Matching SEO title and H1 is not inherently defective, but slight variation enables secondary keyword targeting.',
				'suggested_correction'   => 'Consider differentiating the SEO title for broader search query relevance while keeping H1 focused for readers.',
				'how_to_verify'          => 'Rescan URL after modifying title or H1.',
				'category'               => 'editorial',
				'default_sev'            => 'info',
			),
			16 => array(
				'id'                     => 16,
				'name'                   => 'Meta description below 70 characters',
				'description'            => 'Flag non-empty descriptions containing fewer than 70 characters. Empty descriptions belong under rule 5.',
				'guidance'               => 'Descriptions under 70 characters underutilize snippet preview real estate and miss opportunities to include persuasive calls to action.',
				'why_warrants_attention' => 'Short descriptions under 70 characters underutilize SERP snippet space and miss opportunities for calls to action.',
				'suggested_correction'   => 'Expand the description in your SEO plugin to between 70 and 155 characters.',
				'how_to_verify'          => 'Rescan the page and verify description length >= 70 characters.',
				'category'               => 'editorial',
				'default_sev'            => 'warning',
			),
			17 => array(
				'id'                     => 17,
				'name'                   => 'Duplicate meta descriptions',
				'description'            => 'Identify matching non-empty descriptions across distinct successfully scanned URLs in the selected scope.',
				'guidance'               => 'When pages share identical descriptions, search engines are more likely to ignore them and generate algorithmic snippets instead.',
				'why_warrants_attention' => 'Identical descriptions across multiple pages lead search engines to ignore them and generate automatic excerpts.',
				'suggested_correction'   => 'Craft a distinct, custom meta description for each page.',
				'how_to_verify'          => 'Rescan affected pages to confirm unique meta descriptions.',
				'category'               => 'technical',
				'default_sev'            => 'warning',
			),
			18 => array(
				'id'                     => 18,
				'name'                   => 'Missing H1',
				'description'            => 'Flag pages with no H1 element containing meaningful text. Distinguish absent H1 elements from empty H1 elements.',
				'guidance'               => 'Every HTML document should have exactly one clear, descriptive <h1> heading that establishes the primary subject of the page content.',
				'why_warrants_attention' => 'The H1 tag serves as the primary topical signal for visitors and screen readers; missing H1 weakens page context.',
				'suggested_correction'   => 'Add a single, clear <h1> heading in your page content or template.',
				'how_to_verify'          => 'Inspect page DOM for <h1> tag, then rescan.',
				'category'               => 'structure',
				'default_sev'            => 'error',
			),
			19 => array(
				'id'                     => 19,
				'name'                   => 'Multiple H1s',
				'description'            => 'Flag more than one H1 element in the page body, including empty elements. Show the text of every H1 found.',
				'guidance'               => 'While HTML5 permits multiple H1s, best practice for clear document outline and accessibility is reserving H1 for the page title and using H2-H6 for sections.',
				'why_warrants_attention' => 'Multiple H1s create confusion for search engines and screen readers regarding the primary subject of the page.',
				'suggested_correction'   => 'Retain one primary H1 for the main page title and convert secondary headings to H2 or H3 elements.',
				'how_to_verify'          => 'Inspect page markup to confirm exactly one <h1> heading exists, then rescan.',
				'category'               => 'structure',
				'default_sev'            => 'warning',
			),
			// Priority Category 1: Broken Internal Links
			20 => array(
				'id'                     => 20,
				'name'                   => 'Broken internal links (404/410)',
				'description'            => 'Identify internal links returning confirmed 404 or 410 responses. Show source page, destination, anchor text, and response status.',
				'guidance'               => 'Broken internal links degrade user navigation and drain search crawler budget on dead ends.',
				'why_warrants_attention' => 'Internal 404s break visitor journeys and stop search engine spiders from discovering deeper pages and distributing PageRank.',
				'suggested_correction'   => 'Update or remove the link href on the source page, or set up a 301 redirect to a relevant live page.',
				'how_to_verify'          => 'Click the destination link or test HTTP response status, then rescan the source URL.',
				'category'               => 'links',
				'default_sev'            => 'error',
			),
			// Priority Category 2: Published Pages Returning Errors
			21 => array(
				'id'                     => 21,
				'name'                   => 'Published page returning HTTP error (4xx/5xx)',
				'description'            => 'Identify published content whose public URL returns a 4xx client or 5xx server error response.',
				'guidance'               => 'Published WordPress items must return an HTTP 200 OK status to be indexed by search engines.',
				'why_warrants_attention' => 'Pages returning errors lose organic ranking immediately and deliver broken user experiences.',
				'suggested_correction'   => 'Check theme templates, PHP logs, permalink structure, or trashing status. If intentionally taken down, set to Draft.',
				'how_to_verify'          => 'Open the URL in a logged-out private browser window and verify HTTP 200 response.',
				'category'               => 'technical',
				'default_sev'            => 'error',
			),
			// Priority Category 3: Indexing Directives
			22 => array(
				'id'                     => 22,
				'name'                   => 'Indexing directives / Noindex conflict',
				'description'            => 'Extract robots meta tags and X-Robots-Tag headers. Report noindex and conflicting directives, including origin.',
				'guidance'               => 'A noindex directive instructs search engines not to index the page. Verify if this is intentional or an accidental configuration.',
				'why_warrants_attention' => 'Unintentional noindex completely blocks search ranking. Conflicting directives between HTML tag and HTTP header cause unpredictable crawler behavior.',
				'suggested_correction'   => 'If the page should rank, remove noindex in your SEO plugin or template header. Ensure HTML and HTTP headers agree.',
				'how_to_verify'          => 'Inspect HTML head and HTTP headers for X-Robots-Tag, then rescan URL.',
				'category'               => 'indexing',
				'default_sev'            => 'warning',
			),
			// Priority Category 4: Robots.txt Blocking
			23 => array(
				'id'                     => 23,
				'name'                   => 'Robots.txt blocking',
				'description'            => 'Evaluate applicable Googlebot rules in robots.txt and show matching rule. Identify when blocking hides noindex or canonical directives.',
				'guidance'               => 'When robots.txt disallows crawling, search engines cannot see page-level noindex or canonical directives, and may still index the URL without snippet information.',
				'why_warrants_attention' => 'Robots.txt blocks prevent crawlers from rendering the page or understanding link relationships.',
				'suggested_correction'   => 'Review your /robots.txt file. Allow crawling if the page should be indexed, or use noindex without robots.txt blocking if removing from index.',
				'how_to_verify'          => 'Test URL with Google Search Console Robots Testing tool or fetch /robots.txt directly.',
				'category'               => 'indexing',
				'default_sev'            => 'warning',
			),
			// Priority Category 5: Canonical Problems
			24 => array(
				'id'                     => 24,
				'name'                   => 'Canonical problems',
				'description'            => 'Detect missing, empty, malformed, multiple, or conflicting canonical declarations, or targets pointing to errors/redirects/noindex.',
				'guidance'               => 'The canonical tag specifies the single master URL for indexing. It must point to a live, indexable, 200 OK version of the page.',
				'why_warrants_attention' => 'Broken or conflicting canonicals lead to duplicate content indexing, URL cannibalization, or canonical tag rejection by Google.',
				'suggested_correction'   => 'Ensure exactly one self-referencing or preferred canonical URL is declared, and verify the destination returns HTTP 200 without noindex.',
				'how_to_verify'          => 'Inspect <link rel="canonical"> in page head and verify target URL accessibility, then rescan.',
				'category'               => 'canonical',
				'default_sev'            => 'warning',
			),
			// Priority Category 6: Redirect Chains and Loops
			25 => array(
				'id'                     => 25,
				'name'                   => 'Redirect chains and loops',
				'description'            => 'Record redirect hops (>1 hop chain) and redirect loops. Identify internal links pointing to redirects and suggest the final destination.',
				'guidance'               => 'Redirect chains increase page latency, consume crawl budget, and can cause tracking drop-offs.',
				'why_warrants_attention' => 'Redirect loops cause total page failure. Redirect chains waste crawler bandwidth and dilute link equity.',
				'suggested_correction'   => 'Update internal links directly to the final 200 OK target URL. Consolidate redirect rules into a single 301 hop.',
				'how_to_verify'          => 'Trace URL hops with an HTTP header inspection tool to confirm a direct 1-step response.',
				'category'               => 'links',
				'default_sev'            => 'warning',
			),
			// Priority Category 7: Sitemap Inconsistencies
			26 => array(
				'id'                     => 26,
				'name'                   => 'Sitemap inconsistencies',
				'description'            => 'Inspect XML sitemaps for URLs that redirect, return errors, carry noindex, or canonicalize elsewhere. Report eligible URLs absent from sitemaps.',
				'guidance'               => 'Sitemaps should only include clean, indexable, canonical 200 OK URLs.',
				'why_warrants_attention' => 'Submitting non-indexable URLs in XML sitemaps produces Google Search Console coverage errors and sends conflicting indexing signals.',
				'suggested_correction'   => 'Exclude noindexed, redirecting, or 404 URLs from sitemap settings, or resolve the page issues.',
				'how_to_verify'          => 'Review your XML sitemap in a browser, confirm all entries return 200 OK and index, then rescan.',
				'category'               => 'sitemap',
				'default_sev'            => 'warning',
			),
			// Priority Category 8: Potential Orphan Pages
			27 => array(
				'id'                     => 27,
				'name'                   => 'Potential orphan page',
				'description'            => 'Compare WordPress content inventory with discovered internal links. Report published pages with 0 discovered incoming internal links.',
				'guidance'               => 'Orphan pages have no incoming HTML links from other pages on the site, making them difficult for users and search spiders to reach.',
				'why_warrants_attention' => 'Orphan pages receive zero link authority, risk being deindexed, and are hidden from visitors.',
				'suggested_correction'   => 'Add contextual links to this page from relevant parent pages, blog articles, category hubs, or site navigation.',
				'how_to_verify'          => 'Add internal links pointing to this URL and perform a scan to verify incoming links are discovered.',
				'category'               => 'structure',
				'default_sev'            => 'warning',
			),
			// Secondary Category 1: Broken Images
			28 => array(
				'id'                     => 28,
				'name'                   => 'Broken images',
				'description'            => 'Identify confirmed failures (404/5xx) for image resources in src, srcset, or lazy-loading attributes.',
				'guidance'               => 'Broken images cause ugly visual gaps, hurt user engagement, and trigger browser console errors.',
				'why_warrants_attention' => 'Visitors leave pages with broken imagery, and image search ranking is lost.',
				'suggested_correction'   => 'Replace or upload the missing image file, or update the image block in the WordPress editor.',
				'how_to_verify'          => 'Visit the image URL directly to verify HTTP 200, then rescan the page.',
				'category'               => 'technical',
				'default_sev'            => 'warning',
			),
			// Secondary Category 2: Broken External Links
			29 => array(
				'id'                     => 29,
				'name'                   => 'Broken external links',
				'description'            => 'Check external destinations with bounded requests. Report confirmed 404/410 failures while treating bot challenges as inconclusive.',
				'guidance'               => 'Outbound links to dead external resources diminish content credibility and frustrate readers.',
				'why_warrants_attention' => 'High numbers of dead external links signal stale, unmaintained content.',
				'suggested_correction'   => 'Update the outbound link to a current working reference or remove the hyperlink.',
				'how_to_verify'          => 'Click the outbound link in a browser, verify destination live status, and rescan.',
				'category'               => 'links',
				'default_sev'            => 'warning',
			),
			// Secondary Category 3: Structured Data Problems
			30 => array(
				'id'                     => 30,
				'name'                   => 'Structured data syntax errors',
				'description'            => 'Detect malformed JSON-LD in script tags. Distinguish JSON syntax errors from rich-result qualification.',
				'guidance'               => 'JSON-LD schema markup must be syntactically valid JSON to be parsed by search engines.',
				'why_warrants_attention' => 'Syntax errors in JSON-LD cause search engines to completely discard schema markup, losing rich snippet enhancements.',
				'suggested_correction'   => 'Validate schema output using Google Rich Results Test or JSON validator to correct syntax errors.',
				'how_to_verify'          => 'Run the page through Google Rich Results Test tool after fixing JSON-LD.',
				'category'               => 'structured_data',
				'default_sev'            => 'warning',
			),
			// Secondary Category 4: Empty or Minimal Content
			31 => array(
				'id'                     => 31,
				'name'                   => 'Empty or minimal main content',
				'description'            => 'Evaluate main content text separately from navigation and footer against configurable thresholds.',
				'guidance'               => 'Thin content pages often struggle to rank for competitive search queries unless they serve a specific transactional function.',
				'why_warrants_attention' => 'Search engines prioritize comprehensive content that thoroughly addresses visitor intent.',
				'suggested_correction'   => 'Expand the page content with detailed explanations, relevant FAQs, or media, or consolidate thin pages.',
				'how_to_verify'          => 'Add substantive content in WordPress editor and rescan.',
				'category'               => 'editorial',
				'default_sev'            => 'info',
			),
			// Secondary Category 5: Social Preview Problems
			32 => array(
				'id'                     => 32,
				'name'                   => 'Social preview problems (Open Graph / Twitter)',
				'description'            => 'Detect missing or empty Open Graph titles, descriptions, and preview images.',
				'guidance'               => 'Social preview tags control how links appear when shared on LinkedIn, Facebook, X (Twitter), and Slack.',
				'why_warrants_attention' => 'Missing social cards result in low click-through rates and generic link snippets when shared.',
				'suggested_correction'   => 'Set a dedicated social image (1200x630px) and social title/description in your SEO plugin.',
				'how_to_verify'          => 'Test with Facebook Sharing Debugger or Twitter Card Validator, then rescan.',
				'category'               => 'social',
				'default_sev'            => 'info',
			),
			// Secondary Category 6: Heading Hierarchy Issues
			33 => array(
				'id'                     => 33,
				'name'                   => 'Heading hierarchy issues',
				'description'            => 'Detect empty headings or skipped heading levels (e.g. H1 directly followed by H3).',
				'guidance'               => 'Heading tags should form a logical outline for accessibility and machine understanding.',
				'why_warrants_attention' => 'Skipped heading levels violate WCAG accessibility guidelines and confuse screen reader users.',
				'suggested_correction'   => 'Structure headings sequentially (H1 -> H2 -> H3) without skipping levels for visual styling.',
				'how_to_verify'          => 'Check the document outline in the WordPress editor or inspect headings, then rescan.',
				'category'               => 'structure',
				'default_sev'            => 'info',
			),
		);
	}

	/**
	 * Sanitize user input settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( array $input ) {
		$output = self::get_default_settings();

		// Reject malformed input shape.
		if ( empty( $input ) || ! is_array( $input ) ) {
			return $output;
		}

		// Post types.
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$clean_types = array();
			foreach ( $input['post_types'] as $pt ) {
				if ( is_string( $pt ) ) {
					$clean_pt = sanitize_key( $pt );
					if ( ! empty( $clean_pt ) ) {
						$clean_types[] = $clean_pt;
					}
				}
			}
			if ( ! empty( $clean_types ) ) {
				$output['post_types'] = array_values( array_unique( $clean_types ) );
			}
		}

		// Homepage flag.
		$output['include_homepage'] = ! empty( $input['include_homepage'] );

		// Batch size.
		if ( isset( $input['batch_size'] ) && is_scalar( $input['batch_size'] ) ) {
			$batch = absint( $input['batch_size'] );
			$output['batch_size'] = max( 1, min( 100, $batch ) );
		}

		// Request delay.
		if ( isset( $input['request_delay_ms'] ) && is_scalar( $input['request_delay_ms'] ) ) {
			$delay = absint( $input['request_delay_ms'] );
			$output['request_delay_ms'] = max( 0, min( 5000, $delay ) );
		}

		// Request timeout.
		if ( isset( $input['request_timeout'] ) && is_scalar( $input['request_timeout'] ) ) {
			$timeout = absint( $input['request_timeout'] );
			$output['request_timeout'] = max( 5, min( 60, $timeout ) );
		}

		// Schedule.
		$valid_schedules = array( 'disabled', 'daily', 'weekly', 'monthly' );
		if ( isset( $input['scan_schedule'] ) && is_string( $input['scan_schedule'] ) && in_array( $input['scan_schedule'], $valid_schedules, true ) ) {
			$output['scan_schedule'] = $input['scan_schedule'];
		}

		// Excluded URLs.
		if ( isset( $input['excluded_urls'] ) && is_string( $input['excluded_urls'] ) ) {
			$output['excluded_urls'] = sanitize_textarea_field( $input['excluded_urls'] );
		}

		// Editorial thresholds.
		if ( isset( $input['title_min_length'] ) && is_scalar( $input['title_min_length'] ) ) {
			$output['title_min_length'] = max( 1, absint( $input['title_min_length'] ) );
		}
		if ( isset( $input['title_max_length'] ) && is_scalar( $input['title_max_length'] ) ) {
			$output['title_max_length'] = max( 10, absint( $input['title_max_length'] ) );
		}
		if ( isset( $input['desc_min_length'] ) && is_scalar( $input['desc_min_length'] ) ) {
			$output['desc_min_length'] = max( 1, absint( $input['desc_min_length'] ) );
		}
		if ( isset( $input['desc_max_length'] ) && is_scalar( $input['desc_max_length'] ) ) {
			$output['desc_max_length'] = max( 20, absint( $input['desc_max_length'] ) );
		}
		if ( isset( $input['url_max_length'] ) && is_scalar( $input['url_max_length'] ) ) {
			$output['url_max_length'] = max( 30, absint( $input['url_max_length'] ) );
		}

		// Retention and cleanup.
		if ( isset( $input['data_retention_days'] ) && is_scalar( $input['data_retention_days'] ) ) {
			$output['data_retention_days'] = absint( $input['data_retention_days'] );
		}
		$output['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		// Rules config.
		$default_rules = self::get_default_rules_config();
		$valid_sevs    = array( 'error', 'warning', 'info' );
		$rules_out     = array();

		foreach ( $default_rules as $rule_id => $def ) {
			$rule_input = ( isset( $input['rules'] ) && is_array( $input['rules'] ) && isset( $input['rules'][ $rule_id ] ) && is_array( $input['rules'][ $rule_id ] ) )
				? $input['rules'][ $rule_id ]
				: array();

			$is_enabled = isset( $rule_input['enabled'] ) ? (bool) $rule_input['enabled'] : false;

			$sev = ( isset( $rule_input['severity'] ) && is_string( $rule_input['severity'] ) && in_array( $rule_input['severity'], $valid_sevs, true ) )
				? $rule_input['severity']
				: $def['severity'];

			$rules_out[ $rule_id ] = array(
				'enabled'  => $is_enabled,
				'severity' => $sev,
				'category' => $def['category'],
			);
		}
		$output['rules'] = $rules_out;

		return $output;
	}

	/**
	 * Check if a specific rule is enabled.
	 *
	 * @param int $rule_id Rule ID (1-19).
	 * @return bool
	 */
	public function is_rule_enabled( $rule_id ) {
		$rules = $this->get( 'rules', array() );
		return ! empty( $rules[ $rule_id ]['enabled'] );
	}

	/**
	 * Get severity for a rule.
	 *
	 * @param int $rule_id Rule ID (1-19).
	 * @return string ('error', 'warning', 'info')
	 */
	public function get_rule_severity( $rule_id ) {
		$rules = $this->get( 'rules', array() );
		if ( ! empty( $rules[ $rule_id ]['severity'] ) ) {
			return $rules[ $rule_id ]['severity'];
		}
		$defaults = self::get_default_rules_config();
		return isset( $defaults[ $rule_id ]['severity'] ) ? $defaults[ $rule_id ]['severity'] : 'warning';
	}
}
