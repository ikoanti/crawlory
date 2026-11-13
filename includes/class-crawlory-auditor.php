<?php
/**
 * SEO Rule Auditor for Technical SEO Dashboard / Crawlory.
 *
 * Evaluates parsed HTML, URL metrics, headers, directives, and links
 * against the 19 core SEO rules and 14 expanded priority/secondary categories.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Auditor {

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	private $settings;

	/**
	 * Fetcher instance.
	 *
	 * @var Crawlory_Fetcher
	 */
	private $fetcher;

	/**
	 * Map of rule_id => check execution status ('evaluated_successfully', 'not_applicable', 'not_evaluated', 'inconclusive', 'failed').
	 *
	 * @var array
	 */
	public $executed_checks = array();

	/**
	 * Whether the most recent page audit had incomplete resource verifications due to time budget.
	 *
	 * @var bool
	 */
	public $has_incomplete_resources = false;

	/**
	 * In-memory link destination verification cache.
	 *
	 * @var array
	 */
	private $link_cache = array();

	/**
	 * In-memory image resource verification cache.
	 *
	 * @var array
	 */
	private $image_cache = array();

	/**
	 * Database instance for persistent resource caching.
	 *
	 * @var Crawlory_DB|null
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_Settings $settings Settings instance.
	 * @param Crawlory_DB|null  $db Database layer for persistent resource cache.
	 */
	public function __construct( Crawlory_Settings $settings, Crawlory_DB $db = null ) {
		$this->settings = $settings;
		$this->db       = $db;
		$this->fetcher  = new Crawlory_Fetcher( $settings );
	}

	/**
	 * Verify a link or image resource destination with deduplication,
	 * bounded execution budget, and persistent database caching.
	 *
	 * @param string $target Target destination URL.
	 * @param string $type Resource type ('link' or 'image').
	 * @param bool   $is_external Whether target is external.
	 * @param string $target Destination URL.
	 * @param string $type Resource type ('link', 'image', 'internal_link', etc.).
	 * @param bool   $is_external External destination flag.
	 * @param float  $remaining_time Remaining worker seconds.
	 * @param bool   $skip_cache Whether to bypass cache for fresh verification (manual rescan policy).
	 * @return array Verification status array.
	 */
	public function verify_resource_destination( $target, $type = 'link', $is_external = false, $remaining_time = 5.0, $skip_cache = false ) {
		// 1. In-memory deduplication cache (unless skip_cache).
		$mem_cache = ( 'image' === $type ) ? $this->image_cache : $this->link_cache;
		if ( ! $skip_cache && isset( $mem_cache[ $target ] ) ) {
			return $mem_cache[ $target ];
		}

		// 2. Persistent bounded database cache (24 hours TTL, unless skip_cache).
		if ( $this->db && ! $skip_cache ) {
			$cached = $this->db->get_cached_resource( $target, $type, 86400 );
			if ( $cached && ! empty( $cached['http_status'] ) ) {
				$status_code = (int) $cached['http_status'];
				$res = array(
					'url'             => $target,
					'http_status'     => $status_code,
					'is_broken'       => ! empty( $cached['is_broken'] ),
					'is_inconclusive' => ! empty( $cached['is_inconclusive'] ),
					'error'           => ! empty( $cached['error'] ) ? $cached['error'] : null,
					'final_url'       => $target,
					'cached'          => true,
				);
				if ( 'image' === $type ) {
					$this->image_cache[ $target ] = $res;
				} else {
					$this->link_cache[ $target ] = $res;
				}
				return $res;
			}
		}

		// 3. Execution budget safety check. Stop before starting work that cannot fit safely.
		if ( $remaining_time < 2.0 ) {
			$res = array(
				'url'             => $target,
				'http_status'     => null,
				'is_broken'       => false,
				'is_inconclusive' => true,
				'error'           => 'Resource verification skipped: Worker execution budget exhausted.',
				'final_url'       => $target,
				'incomplete'      => true,
			);
			if ( 'image' === $type ) {
				$this->image_cache[ $target ] = $res;
			} else {
				$this->link_cache[ $target ] = $res;
			}
			return $res;
		}

		// 4. Remote verification with bounded timeout.
		$req_timeout = min( 5, max( 2, (int) ( $remaining_time - 1.0 ) ) );
		if ( 'image' === $type ) {
			$chk = $this->fetcher->check_image( $target, $req_timeout );
		} else {
			$chk = $this->fetcher->check_link( $target, $is_external, $req_timeout );
		}

		// 5. Save in persistent cache if conclusive and status is valid.
		if ( $this->db && empty( $chk['is_inconclusive'] ) && ! empty( $chk['http_status'] ) ) {
			$this->db->set_cached_resource(
				$target,
				$type,
				(int) $chk['http_status'],
				! empty( $chk['is_broken'] ),
				false,
				isset( $chk['error'] ) ? $chk['error'] : null
			);
		}

		if ( 'image' === $type ) {
			$this->image_cache[ $target ] = $chk;
		} else {
			$this->link_cache[ $target ] = $chk;
		}

		return $chk;
	}

	/**
	 * Audit a single URL's parsed contents and URL metrics.
	 *
	 * @param array  $parsed_html Result from Crawlory_HTML_Parser::parse().
	 * @param array  $url_analysis Result from Crawlory_URL_Analyzer::analyze().
	 * @param string $url Page absolute URL.
	 * @param string $stored_slug Post slug if applicable.
	 * @param array  $fetch_res Fetch metadata (HTTP status, headers, etc.).
	 * @param array  $extra_context Additional site/coverage context.
	 * @param float  $time_budget Worker execution time budget in seconds.
	 * @return array Array of finding arrays.
	 */
	public function audit_page( array $parsed_html, array $url_analysis, $url, $stored_slug = '', array $fetch_res = array(), array $extra_context = array(), $time_budget = 15.0 ) {
		$findings = array();
		$page_start_time = microtime( true );

		// Thresholds from settings.
		$title_min   = (int) $this->settings->get( 'title_min_length', 30 );
		$title_max   = (int) $this->settings->get( 'title_max_length', 60 );
		$desc_min    = (int) $this->settings->get( 'desc_min_length', 70 );
		$desc_max    = (int) $this->settings->get( 'desc_max_length', 155 );
		$url_max     = (int) $this->settings->get( 'url_max_length', 115 );
		$content_min = (int) $this->settings->get( 'content_min_words', 100 );

		$http_status = isset( $fetch_res['http_status'] ) ? (int) $fetch_res['http_status'] : 200;
		$outcome     = isset( $fetch_res['outcome'] ) ? $fetch_res['outcome'] : ( $http_status >= 400 ? 'http_error' : 'success' );

		// Initialize check statuses for all 33 rules.
		$this->executed_checks          = array();
		$this->has_incomplete_resources = false;
		for ( $i = 1; $i <= 33; $i++ ) {
			if ( ! $this->settings->is_rule_enabled( $i ) ) {
				$this->executed_checks[ $i ] = 'not_applicable';
			} else {
				$this->executed_checks[ $i ] = 'not_evaluated';
			}
		}

		// =========================================================================
		// RULE 21: Published pages returning errors (Priority Category 2)
		// If page itself returns 4xx/5xx or fetch failed, report technical finding and STOP.
		// NEVER run HTML-dependent checks on timeouts, connection failures, or non-HTML!
		// =========================================================================
		if ( 'success' !== $outcome ) {
			if ( 'http_error' === $outcome || $http_status >= 400 ) {
				if ( $this->settings->is_rule_enabled( 21 ) ) {
					$this->executed_checks[ 21 ] = 'evaluated_successfully';
					$findings[] = $this->create_finding(
						21,
						'Published pages returning errors',
						'technical',
						sprintf( 'Published content public URL returned HTTP %d response.', $http_status ),
						sprintf( 'HTTP %d response', $http_status ),
						'HTTP 200 OK',
						array(
							'http_status'  => $http_status,
							'url'          => $url,
							'content_type' => isset( $fetch_res['content_type'] ) ? $fetch_res['content_type'] : '',
							'error'        => isset( $fetch_res['error'] ) ? $fetch_res['error'] : '',
						)
					);
				}
			}

			// Do not run HTML element checks on failed fetches!
			return $findings;
		}

		// Mark core HTML rules as evaluated successfully since response is usable HTML.
		foreach ( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 18, 19, 22, 23, 24, 25, 30, 31, 32, 33 ) as $rid ) {
			if ( $this->settings->is_rule_enabled( $rid ) ) {
				$this->executed_checks[ $rid ] = 'evaluated_successfully';
			}
		}

		$titles       = isset( $parsed_html['titles'] ) ? $parsed_html['titles'] : array();
		$descriptions = isset( $parsed_html['descriptions'] ) ? $parsed_html['descriptions'] : array();
		$h1s          = isset( $parsed_html['h1s'] ) ? $parsed_html['h1s'] : array();
		$images       = isset( $parsed_html['images'] ) ? $parsed_html['images'] : array();
		$headings     = isset( $parsed_html['headings'] ) ? $parsed_html['headings'] : array();
		$canonicals   = isset( $parsed_html['canonicals'] ) ? $parsed_html['canonicals'] : array();
		$robots_meta  = isset( $parsed_html['robots_meta'] ) ? $parsed_html['robots_meta'] : array();
		$links        = isset( $parsed_html['links'] ) ? $parsed_html['links'] : array();

		// =========================================================================
		// RULE 2: Missing or empty SEO title
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 2 ) ) {
			if ( empty( $titles ) ) {
				$findings[] = $this->create_finding(
					2,
					'Missing or empty SEO title',
					'technical',
					'HTML <title> tag is missing from the document head.',
					'tag_missing',
					'Non-empty <title> tag in <head>',
					array( 'status' => 'tag_missing' )
				);
			} else {
				$all_empty = true;
				foreach ( $titles as $t ) {
					if ( empty( $t['is_empty'] ) ) {
						$all_empty = false;
						break;
					}
				}
				if ( $all_empty ) {
					$findings[] = $this->create_finding(
						2,
						'Missing or empty SEO title',
						'technical',
						'HTML <title> tag is present but contains only whitespace or is empty.',
						'whitespace_only',
						'Non-empty <title> tag in <head>',
						array( 'status' => 'whitespace_only', 'raw_value' => $titles[0]['raw'] )
					);
				}
			}
		}

		// =========================================================================
		// RULE 3: Multiple SEO titles
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 3 ) && count( $titles ) > 1 ) {
			$raw_titles = array_column( $titles, 'raw' );
			$findings[] = $this->create_finding(
				3,
				'Multiple SEO titles',
				'technical',
				sprintf( 'Multiple <title> tags detected (%d tags found in document head).', count( $titles ) ),
				sprintf( '%d tags found: %s', count( $titles ), implode( ' | ', $raw_titles ) ),
				'Exactly 1 <title> tag in <head>',
				array( 'count' => count( $titles ), 'titles' => $titles )
			);
		}

		// =========================================================================
		// RULE 1: SEO title above 60 characters
		// RULE 7: SEO title below 30 characters
		// =========================================================================
		foreach ( $titles as $idx => $title_item ) {
			if ( ! empty( $title_item['is_empty'] ) ) {
				continue;
			}

			$t_count = $title_item['char_count'];
			$prefix  = count( $titles ) > 1 ? sprintf( '[Title #%d] ', $idx + 1 ) : '';

			if ( $this->settings->is_rule_enabled( 1 ) && $t_count > $title_max ) {
				$findings[] = $this->create_finding(
					1,
					'SEO title above ' . $title_max . ' characters',
					'editorial',
					sprintf( '%sTitle exceeds %d characters (%d characters).', $prefix, $title_max, $t_count ),
					sprintf( '%d characters: "%s"', $t_count, $title_item['normalized'] ),
					sprintf( '<= %d characters', $title_max ),
					array(
						'title_index' => $idx + 1,
						'char_count'  => $t_count,
						'threshold'   => $title_max,
						'title_text'  => $title_item['normalized'],
					)
				);
			}

			if ( $this->settings->is_rule_enabled( 7 ) && $t_count < $title_min ) {
				$findings[] = $this->create_finding(
					7,
					'SEO title below ' . $title_min . ' characters',
					'editorial',
					sprintf( '%sTitle is below %d characters (%d characters).', $prefix, $title_min, $t_count ),
					sprintf( '%d characters: "%s"', $t_count, $title_item['normalized'] ),
					sprintf( '>= %d characters', $title_min ),
					array(
						'title_index' => $idx + 1,
						'char_count'  => $t_count,
						'threshold'   => $title_min,
						'title_text'  => $title_item['normalized'],
					)
				);
			}
		}

		// =========================================================================
		// RULE 5: Missing or empty meta description
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 5 ) ) {
			if ( empty( $descriptions ) ) {
				$findings[] = $this->create_finding(
					5,
					'Missing or empty meta description',
					'technical',
					'Meta description tag is missing from the document head.',
					'tag_missing',
					'Valid <meta name="description" content="..."> with non-empty text',
					array( 'status' => 'tag_missing' )
				);
			} else {
				$has_valid = false;
				$first_issue_status = $descriptions[0]['status'];

				foreach ( $descriptions as $desc_item ) {
					if ( 'valid' === $desc_item['status'] ) {
						$has_valid = true;
						break;
					}
				}

				if ( ! $has_valid ) {
					$msg = ( 'attribute_missing' === $first_issue_status )
						? 'Meta description tag is present but lacks a content="" attribute.'
						: 'Meta description tag is present but content contains only whitespace or is empty.';

					$findings[] = $this->create_finding(
						5,
						'Missing or empty meta description',
						'technical',
						$msg,
						$first_issue_status,
						'Valid <meta name="description" content="..."> with non-empty text',
						array( 'status' => $first_issue_status, 'descriptions' => $descriptions )
					);
				}
			}
		}

		// =========================================================================
		// RULE 6: Multiple meta descriptions
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 6 ) && count( $descriptions ) > 1 ) {
			$desc_snippets = array();
			foreach ( $descriptions as $d ) {
				$desc_snippets[] = null !== $d['raw'] ? mb_substr( $d['raw'], 0, 50 ) . '...' : '[empty]';
			}
			$findings[] = $this->create_finding(
				6,
				'Multiple meta descriptions',
				'technical',
				sprintf( 'Multiple meta description tags detected (%d tags found in document head).', count( $descriptions ) ),
				sprintf( '%d tags found: %s', count( $descriptions ), implode( ' | ', $desc_snippets ) ),
				'Exactly 1 meta description tag in <head>',
				array( 'count' => count( $descriptions ), 'descriptions' => $descriptions )
			);
		}

		// =========================================================================
		// =========================================================================
		// RULE 4: Meta description above 155 characters
		// RULE 16: Meta description below 70 characters
		// =========================================================================
		foreach ( $descriptions as $idx => $desc_item ) {
			if ( 'valid' !== $desc_item['status'] || empty( $desc_item['normalized'] ) ) {
				continue;
			}

			$d_count = $desc_item['char_count'];
			$prefix  = count( $descriptions ) > 1 ? sprintf( '[Description #%d] ', $idx + 1 ) : '';

			if ( $this->settings->is_rule_enabled( 4 ) && $d_count > $desc_max ) {
				$findings[] = $this->create_finding(
					4,
					'Meta description above ' . $desc_max . ' characters',
					'editorial',
					sprintf( '%sMeta description exceeds %d characters (%d characters).', $prefix, $desc_max, $d_count ),
					sprintf( '%d characters: "%s"', $d_count, $desc_item['normalized'] ),
					sprintf( '<= %d characters', $desc_max ),
					array(
						'desc_index' => $idx + 1,
						'char_count' => $d_count,
						'threshold'  => $desc_max,
						'desc_text'  => $desc_item['normalized'],
					)
				);
			}

			if ( $this->settings->is_rule_enabled( 16 ) && $d_count < $desc_min ) {
				$findings[] = $this->create_finding(
					16,
					'Meta description below ' . $desc_min . ' characters',
					'editorial',
					sprintf( '%sMeta description is below %d characters (%d characters).', $prefix, $desc_min, $d_count ),
					sprintf( '%d characters: "%s"', $d_count, $desc_item['normalized'] ),
					sprintf( '>= %d characters', $desc_min ),
					array(
						'desc_index' => $idx + 1,
						'char_count' => $d_count,
						'threshold'  => $desc_min,
						'desc_text'  => $desc_item['normalized'],
					)
				);
			}
		}

		// =========================================================================
		// RULE 15: SEO title matches H1
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 15 ) && ! empty( $titles ) && ! empty( $h1s ) ) {
			foreach ( $titles as $t_item ) {
				if ( ! empty( $t_item['is_empty'] ) ) {
					continue;
				}
				$norm_t = Crawlory_Normalizer::normalize_for_comparison( $t_item['normalized'] );
				foreach ( $h1s as $h1_item ) {
					if ( ! empty( $h1_item['is_empty'] ) ) {
						continue;
					}
					$norm_h = Crawlory_Normalizer::normalize_for_comparison( $h1_item['normalized'] );
					if ( ! empty( $norm_t ) && $norm_t === $norm_h ) {
						$findings[] = $this->create_finding(
							15,
							'SEO title matches H1',
							'editorial',
							sprintf( 'The SEO title is identical to H1 heading #%d: "%s".', $h1_item['index'], $h1_item['normalized'] ),
							$t_item['normalized'],
							'Consider differentiating title for broader keyword relevance',
							array(
								'title'    => $t_item['normalized'],
								'h1'       => $h1_item['normalized'],
								'h1_index' => $h1_item['index'],
							)
						);
						break 2; // Flag once per page
					}
				}
			}
		}

		// =========================================================================
		// RULE 18: Missing H1
		// RULE 19: Multiple H1s
		// =========================================================================
		if ( empty( $h1s ) ) {
			if ( $this->settings->is_rule_enabled( 18 ) ) {
				$findings[] = $this->create_finding(
					18,
					'Missing H1',
					'structure',
					'No <h1> heading tag was found in the rendered HTML body.',
					'absent',
					'Exactly 1 non-empty <h1> tag',
					array( 'count' => 0, 'status' => 'absent' )
				);
			}
		} else {
			$h1_count = count( $h1s );
			$all_h1_empty = true;
			foreach ( $h1s as $h1_item ) {
				if ( empty( $h1_item['is_empty'] ) ) {
					$all_h1_empty = false;
					break;
				}
			}

			// If all H1s are whitespace/empty, flag under Rule 18 with observed 'empty'
			if ( $all_h1_empty ) {
				if ( $this->settings->is_rule_enabled( 18 ) ) {
					$findings[] = $this->create_finding(
						18,
						'Missing H1',
						'structure',
						'H1 heading is present in DOM but contains no text content or whitespace only.',
						'empty',
						'Exactly 1 non-empty <h1> tag',
						array( 'count' => $h1_count, 'status' => 'empty' )
					);
				}
			}

			if ( $this->settings->is_rule_enabled( 19 ) && $h1_count > 1 ) {
				$h1_texts = array_column( $h1s, 'normalized' );
				$findings[] = $this->create_finding(
					19,
					'Multiple H1s',
					'structure',
					sprintf( 'Multiple <h1> headings detected (%d H1 tags found in document body).', $h1_count ),
					sprintf( '%d tags found: %s', $h1_count, implode( ' | ', $h1_texts ) ),
					'Exactly 1 <h1> tag per page',
					array( 'count' => $h1_count, 'h1s' => $h1s )
				);
			}
		}

		// =========================================================================
		// RULE 10: Missing or empty image alt text
		// Flag rendered images with missing alt attributes or empty/whitespace-only alt text.
		// Report each image separately with distinct alt_status.
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 10 ) && ! empty( $images ) ) {
			foreach ( $images as $img ) {
				$src_display = ! empty( $img['src'] ) ? $img['src'] : '[inline/data-src]';
				if ( 'missing_attribute' === $img['alt_status'] ) {
					$findings[] = $this->create_finding(
						10,
						'Missing or empty image alt text',
						'technical',
						sprintf( 'Image element is missing the required alt attribute: %s', $src_display ),
						'missing_attribute',
						'Valid descriptive alt attribute',
						array(
							'src'        => $img['src'],
							'alt_status' => 'missing_attribute',
						)
					);
				} elseif ( 'empty_alt' === $img['alt_status'] || 'whitespace_only' === $img['alt_status'] ) {
					$findings[] = $this->create_finding(
						10,
						'Missing or empty image alt text',
						'technical',
						sprintf( 'Image element contains empty or whitespace-only alt text: %s', $src_display ),
						'empty_alt',
						'Valid descriptive alt attribute (or dismiss if decorative)',
						array(
							'src'        => $img['src'],
							'alt_status' => 'empty_alt',
						)
					);
				}
			}
		}

		// =========================================================================
		// RULE 8: Non-Latin characters in URLs or slugs
		// RULE 9: Emojis in URLs
		// RULE 11: Spaces in URLs
		// RULE 12: Uppercase characters in URLs
		// RULE 13: URLs above 115 characters
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 8 ) && ! empty( $url_analysis['rule_8_non_latin']['flagged'] ) ) {
			$evidence = $url_analysis['rule_8_non_latin']['evidence'];
			$chars    = implode( ', ', $evidence['affected_characters'] );
			$findings[] = $this->create_finding(
				8,
				'Non-Latin characters in URLs or slugs',
				'url_policy',
				sprintf( 'Detected non-Latin script characters in URL or slug: %s', $chars ),
				$chars,
				'Standard Latin alphanumeric permalink or policy-approved transliteration',
				$evidence
			);
		}

		if ( $this->settings->is_rule_enabled( 9 ) && ! empty( $url_analysis['rule_9_emoji']['flagged'] ) ) {
			$evidence = $url_analysis['rule_9_emoji']['evidence'];
			$emojis   = implode( ' ', $evidence['detected_emojis'] );
			$findings[] = $this->create_finding(
				9,
				'Emojis in URLs',
				'url_policy',
				sprintf( 'Detected emoji sequence in URL: %s', $emojis ),
				$emojis,
				'Clean alphanumeric URL without emojis or pictographs',
				$evidence
			);
		}

		if ( $this->settings->is_rule_enabled( 11 ) && ! empty( $url_analysis['rule_11_spaces']['flagged'] ) ) {
			$evidence = $url_analysis['rule_11_spaces']['evidence'];
			$types    = array_column( $evidence, 'type' );
			$findings[] = $this->create_finding(
				11,
				'Spaces in URLs',
				'url_policy',
				'Detected spaces in URL path or query string (' . implode( ', ', $types ) . ').',
				implode( ', ', $types ),
				'Hyphen-separated URL without literal spaces or %20 encoding',
				$evidence
			);
		}

		if ( $this->settings->is_rule_enabled( 12 ) && ! empty( $url_analysis['rule_12_uppercase']['flagged'] ) ) {
			$evidence = $url_analysis['rule_12_uppercase']['evidence'];
			$chars    = implode( ', ', $evidence['detected_characters'] );
			$findings[] = $this->create_finding(
				12,
				'Uppercase characters in URLs',
				'url_policy',
				sprintf( 'Detected uppercase characters in URL: %s', $chars ),
				$chars,
				'All-lowercase URL slugs',
				$evidence
			);
		}

		if ( $this->settings->is_rule_enabled( 13 ) && ! empty( $url_analysis['rule_13_length_excess']['flagged'] ) ) {
			$evidence = $url_analysis['rule_13_length_excess']['evidence'];
			$findings[] = $this->create_finding(
				13,
				'URLs above ' . $url_max . ' characters',
				'url_policy',
				sprintf( 'Encoded permalink length (%d characters) exceeds %d characters.', $evidence['encoded_length'], $url_max ),
				sprintf( 'Encoded: %d chars | Decoded: %d chars', $evidence['encoded_length'], $evidence['decoded_length'] ),
				sprintf( '<= %d characters', $url_max ),
				$evidence
			);
		}

		// =========================================================================
		// RULE 22: Indexing directives / Noindex conflict (Priority Category 3)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 22 ) ) {
			$has_html_noindex   = false;
			$has_header_noindex = false;
			$x_robots_header    = isset( $fetch_res['x_robots_tag'] ) ? (string) $fetch_res['x_robots_tag'] : '';

			if ( ! empty( $x_robots_header ) && false !== stripos( $x_robots_header, 'noindex' ) ) {
				$has_header_noindex = true;
			}

			foreach ( $robots_meta as $rm ) {
				if ( ! empty( $rm['has_noindex'] ) ) {
					$has_html_noindex = true;
					break;
				}
			}

			// Report noindex presence (review item).
			if ( $has_html_noindex || $has_header_noindex ) {
				$origin = array();
				if ( $has_html_noindex ) {
					$origin[] = 'HTML meta tag (<meta name="robots" content="noindex">)';
				}
				if ( $has_header_noindex ) {
					$origin[] = 'HTTP header (X-Robots-Tag: ' . $x_robots_header . ')';
				}

				$findings[] = $this->create_finding(
					22,
					'Indexing directives / Noindex conflict',
					'indexing',
					sprintf( 'Page carries a noindex directive from: %s.', implode( ' and ', $origin ) ),
					implode( '; ', $origin ),
					'Indexable status (index) unless intentionally excluded',
					array(
						'html_noindex'   => $has_html_noindex,
						'header_noindex' => $has_header_noindex,
						'x_robots_tag'   => $x_robots_header,
						'meta_tags'      => $robots_meta,
					)
				);
			}
		}

		// =========================================================================
		// RULE 23: Robots.txt blocking (Priority Category 4)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 23 ) && class_exists( 'Crawlory_Robots_Parser' ) ) {
			$robots_eval = Crawlory_Robots_Parser::evaluate_url( $url );
			if ( ! empty( $robots_eval['is_blocked'] ) ) {
				$rule_str = $robots_eval['matching_rule'];
				$msg      = sprintf( 'URL is blocked from crawling by Googlebot via robots.txt rule: %s', $rule_str );

				// Check if blocking hides noindex or canonical.
				$hides_directives = array();
				if ( ! empty( $parsed_html['robots_meta'] ) ) {
					$hides_directives[] = 'robots meta tag';
				}
				if ( ! empty( $canonicals ) ) {
					$hides_directives[] = 'canonical declaration';
				}

				if ( ! empty( $hides_directives ) ) {
					$msg .= sprintf( ' (Blocking prevents crawler from seeing page-level %s)', implode( ' and ', $hides_directives ) );
				}

				$findings[] = $this->create_finding(
					23,
					'Robots.txt blocking',
					'indexing',
					$msg,
					$rule_str,
					'URL allowed for Googlebot in robots.txt',
					$robots_eval
				);
			}
		}

		// =========================================================================
		// RULE 24: Canonical problems (Priority Category 5)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 24 ) ) {
			$header_canon = isset( $fetch_res['canonical_header'] ) ? trim( (string) $fetch_res['canonical_header'] ) : '';

			$all_declarations   = array();
			$normalized_targets = array();

			foreach ( $canonicals as $c ) {
				$raw_href = isset( $c['href'] ) ? trim( $c['href'] ) : '';
				if ( empty( $raw_href ) ) {
					$findings[] = $this->create_finding(
						24,
						'Canonical problems',
						'canonical',
						'Canonical tag is present in HTML head but contains an empty href attribute.',
						'href=""',
						'Non-empty canonical URL',
						array( 'issue' => 'empty_href' )
					);
					continue;
				}

				// Check relative target.
				if ( ! preg_match( '/^https?:\/\//i', $raw_href ) ) {
					$abs_href = WP_Http::make_absolute_url( $raw_href, $url );
					$findings[] = $this->create_finding(
						24,
						'Canonical problems',
						'canonical',
						sprintf( 'Canonical declaration uses a relative URL instead of an absolute URL: %s', $raw_href ),
						$raw_href,
						'Absolute canonical URL including scheme and domain',
						array( 'issue' => 'relative_url', 'target' => $raw_href, 'resolved' => $abs_href )
					);
					$target_to_use = $abs_href;
				} else {
					$target_to_use = $raw_href;
				}

				$all_declarations[]   = 'HTML: ' . $target_to_use;
				$normalized_targets[] = untrailingslashit( strtolower( $target_to_use ) );
			}

			if ( ! empty( $header_canon ) ) {
				$abs_header = ! preg_match( '/^https?:\/\//i', $header_canon ) ? WP_Http::make_absolute_url( $header_canon, $url ) : $header_canon;
				$all_declarations[]   = 'Header: ' . $abs_header;
				$normalized_targets[] = untrailingslashit( strtolower( $abs_header ) );
			}

			$distinct_targets = array_values( array_unique( $normalized_targets ) );

			// Check for multiple conflicting canonical declarations.
			// Notice: Matching HTML and HTTP Header canonicals point to identical target and MUST NOT be flagged as conflicting!
			if ( count( $distinct_targets ) > 1 ) {
				$findings[] = $this->create_finding(
					24,
					'Canonical problems',
					'canonical',
					sprintf( 'Multiple conflicting canonical declarations detected (%d distinct targets).', count( $distinct_targets ) ),
					implode( ' | ', $all_declarations ),
					'Single unambiguous canonical declaration',
					array( 'declarations' => $all_declarations, 'distinct_targets' => $distinct_targets )
				);
			} elseif ( count( $distinct_targets ) === 1 ) {
				// Single unambiguous canonical declaration target.
				$single_target      = $distinct_targets[0];
				$normalized_current = untrailingslashit( strtolower( $url ) );

				// Check alternate canonicalization (points to a different valid URL on site).
				// Report as review item rather than automatically treating as an error.
				if ( $single_target !== $normalized_current ) {
					$findings[] = $this->create_finding(
						24,
						'Canonical points to alternate URL',
						'canonical',
						sprintf( 'Canonical declaration points to alternate URL: %s.', $single_target ),
						$single_target,
						'Self-referencing canonical unless intentionally consolidating duplicate URLs',
						array( 'issue' => 'alternate_canonical', 'target' => $single_target, 'current_url' => $url )
					);
				}
			}
		}

		// =========================================================================
		// RULE 25: Redirect chains and loops (Priority Category 6)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 25 ) && ! empty( $fetch_res['is_redirect'] ) ) {
			$trace = $this->fetcher->trace_redirects( $url );
			if ( ! empty( $trace['has_loop'] ) ) {
				$findings[] = $this->create_finding(
					25,
					'Redirect chains and loops',
					'links',
					sprintf( 'Redirect loop detected starting at URL: %s', $url ),
					'Redirect loop',
					'Direct 200 OK or single 301 redirect',
					$trace
				);
			} elseif ( ! empty( $trace['is_limit_exhausted'] ) ) {
				$findings[] = $this->create_finding(
					25,
					'Redirect chains and loops',
					'links',
					sprintf( 'Redirect hop limit exhausted (%d hops) without reaching terminal response.', $trace['hop_count'] ),
					sprintf( '%d hops without terminal response', $trace['hop_count'] ),
					'Single direct 301 hop to 200 OK destination',
					$trace
				);
			} elseif ( ! empty( $trace['has_chain'] ) ) {
				$findings[] = $this->create_finding(
					25,
					'Redirect chains and loops',
					'links',
					sprintf( 'Redirect chain detected (%d hops) leading to %s.', $trace['hop_count'], $trace['final_url'] ),
					sprintf( '%d hops to %s', $trace['hop_count'], $trace['final_url'] ),
					'Single direct 301 hop or 200 OK target',
					$trace
				);
			}
		}

		// =========================================================================
		// RULE 20: Broken internal links (Priority Category 1)
		// RULE 29: Broken external links (Secondary Category 2)
		// Inspect anchor links discovered in the page content
		// =========================================================================
		$internal_links = array();
		$external_links = array();
		foreach ( $links as $l ) {
			if ( ! empty( $l['is_internal'] ) ) {
				$internal_links[] = $l;
			} else {
				$external_links[] = $l;
			}
		}

		// Check internal links (deduplicated by destination URL).
		if ( $this->settings->is_rule_enabled( 20 ) ) {
			if ( empty( $internal_links ) ) {
				$this->executed_checks[ 20 ] = 'evaluated_successfully';
			} else {
				$seen_int             = array();
				$rule_20_inconclusive = false;
				foreach ( $internal_links as $ilink ) {
					$target = $ilink['href'];
					if ( empty( $target ) || isset( $seen_int[ $target ] ) ) {
						continue;
					}
					$seen_int[ $target ] = true;

					$rem_time = max( 0.0, $time_budget - ( microtime( true ) - $page_start_time ) );
					$chk      = $this->verify_resource_destination( $target, 'link', false, $rem_time );

					if ( ! empty( $chk['incomplete'] ) || ! empty( $chk['is_inconclusive'] ) ) {
						$rule_20_inconclusive           = true;
						$this->has_incomplete_resources = true;
					}

					if ( ! empty( $chk['is_broken'] ) ) {
						$findings[] = $this->create_finding(
							20,
							'Broken internal links',
							'links',
							sprintf( 'Internal link points to broken destination (%d): %s (Anchor: "%s")', $chk['http_status'], $target, $ilink['anchor_text'] ),
							sprintf( 'HTTP %d response for %s', $chk['http_status'], $target ),
							'HTTP 200 OK destination',
							array(
								'source_page' => $url,
								'target_url'  => $target,
								'anchor_text' => $ilink['anchor_text'],
								'http_status' => $chk['http_status'],
							)
						);
					}
				}
				$this->executed_checks[ 20 ] = $rule_20_inconclusive ? 'inconclusive' : 'evaluated_successfully';
			}
		}

		// Check external links (deduplicated by destination URL).
		if ( $this->settings->is_rule_enabled( 29 ) ) {
			if ( empty( $external_links ) ) {
				$this->executed_checks[ 29 ] = 'evaluated_successfully';
			} else {
				$seen_ext             = array();
				$rule_29_inconclusive = false;
				foreach ( $external_links as $elink ) {
					$target = $elink['href'];
					if ( empty( $target ) || isset( $seen_ext[ $target ] ) ) {
						continue;
					}
					$seen_ext[ $target ] = true;

					$rem_time = max( 0.0, $time_budget - ( microtime( true ) - $page_start_time ) );
					$chk      = $this->verify_resource_destination( $target, 'link', true, $rem_time );

					if ( ! empty( $chk['incomplete'] ) || ! empty( $chk['is_inconclusive'] ) ) {
						$rule_29_inconclusive           = true;
						$this->has_incomplete_resources = true;
					}

					if ( ! empty( $chk['is_broken'] ) ) {
						$findings[] = $this->create_finding(
							29,
							'Broken external links',
							'links',
							sprintf( 'External outbound link is broken (%d): %s (Anchor: "%s")', $chk['http_status'], $target, $elink['anchor_text'] ),
							sprintf( 'HTTP %d response for %s', $chk['http_status'], $target ),
							'Valid 200 OK external destination',
							array(
								'source_page' => $url,
								'target_url'  => $target,
								'anchor_text' => $elink['anchor_text'],
								'http_status' => $chk['http_status'],
							)
						);
					}
				}
				$this->executed_checks[ 29 ] = $rule_29_inconclusive ? 'inconclusive' : 'evaluated_successfully';
			}
		}

		// =========================================================================
		// RULE 26: Sitemap inconsistencies (Priority Category 7)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 26 ) ) {
			$this->executed_checks[ 26 ] = 'evaluated_successfully';
			$in_sitemap = false;
			if ( class_exists( 'Crawlory_Sitemap_Parser' ) ) {
				$in_sitemap = Crawlory_Sitemap_Parser::check_url_in_sitemap( $url );
			}

			$has_noindex = false;
			foreach ( $robots_meta as $rm ) {
				if ( ! empty( $rm['has_noindex'] ) ) {
					$has_noindex = true;
					break;
				}
			}
			if ( ! empty( $fetch_res['x_robots_tag'] ) && false !== stripos( $fetch_res['x_robots_tag'], 'noindex' ) ) {
				$has_noindex = true;
			}

			// 1. Listed in sitemap with noindex directive.
			if ( $in_sitemap && $has_noindex ) {
				$findings[] = $this->create_finding(
					26,
					'Sitemap inconsistencies',
					'sitemap',
					'Page is included in XML sitemap but carries a noindex directive.',
					'Listed in sitemap with noindex directive',
					'Only indexable URLs should be submitted in XML sitemaps',
					array( 'in_sitemap' => true, 'has_noindex' => true )
				);
			}

			// 2. Listed in sitemap but canonicalizing elsewhere.
			if ( $in_sitemap && ! empty( $distinct_targets ) ) {
				$canon_target = reset( $distinct_targets );
				if ( $canon_target !== untrailingslashit( strtolower( $url ) ) ) {
					$findings[] = $this->create_finding(
						26,
						'Sitemap inconsistencies',
						'sitemap',
						sprintf( 'Page is submitted in XML sitemap but specifies an alternate canonical target: %s.', $canon_target ),
						sprintf( 'Listed in sitemap; canonical -> %s', $canon_target ),
						'Sitemaps should submit only self-canonicalizing canonical URLs',
						array( 'in_sitemap' => true, 'canonical_target' => $canon_target )
					);
				}
			}

			// 3. Listed in sitemap but returning redirect.
			if ( $in_sitemap && ! empty( $fetch_res['is_redirect'] ) ) {
				$findings[] = $this->create_finding(
					26,
					'Sitemap inconsistencies',
					'sitemap',
					sprintf( 'Page is submitted in XML sitemap but redirects to: %s.', $fetch_res['final_url'] ),
					sprintf( 'Redirects to %s', $fetch_res['final_url'] ),
					'Sitemaps should submit only direct 200 OK URLs',
					array( 'in_sitemap' => true, 'redirect_to' => $fetch_res['final_url'] )
				);
			}

			// 4. Eligible published URL absent from inspected sitemaps.
			// Disclose coverage limitations: only report when sitemap discovery was verified complete!
			if ( ! $in_sitemap && ! empty( $extra_context['sitemap_discovery_complete'] ) ) {
				$findings[] = $this->create_finding(
					26,
					'Sitemap inconsistencies',
					'sitemap',
					'Eligible published URL is missing from inspected XML sitemaps.',
					'URL absent from inspected XML sitemaps',
					'Published indexable URLs should be submitted in XML sitemap',
					array( 'in_sitemap' => false, 'sitemap_coverage' => 'complete' )
				);
			}
		}

		// =========================================================================
		// RULE 28: Broken images (Secondary Category 1)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 28 ) ) {
			if ( empty( $images ) ) {
				$this->executed_checks[ 28 ] = 'evaluated_successfully';
			} else {
				$seen_img             = array();
				$rule_28_inconclusive = false;
				foreach ( $images as $img ) {
					$img_src = isset( $img['src'] ) ? trim( $img['src'] ) : '';
					if ( empty( $img_src ) || 0 === strpos( $img_src, 'data:' ) || isset( $seen_img[ $img_src ] ) ) {
						continue;
					}
					$seen_img[ $img_src ] = true;

					$rem_time = max( 0.0, $time_budget - ( microtime( true ) - $page_start_time ) );
					$chk      = $this->verify_resource_destination( $img_src, 'image', false, $rem_time );

					if ( ! empty( $chk['incomplete'] ) || ! empty( $chk['is_inconclusive'] ) ) {
						$rule_28_inconclusive           = true;
						$this->has_incomplete_resources = true;
					}

					if ( ! empty( $chk['is_broken'] ) ) {
						$findings[] = $this->create_finding(
							28,
							'Broken images',
							'technical',
							sprintf( 'Image resource returned error (%d): %s', $chk['http_status'], $img_src ),
							sprintf( 'HTTP %d response for %s', $chk['http_status'], $img_src ),
							'HTTP 200 OK image resource',
							array(
								'image_url'   => $img_src,
								'http_status' => $chk['http_status'],
							)
						);
					}
				}
				$this->executed_checks[ 28 ] = $rule_28_inconclusive ? 'inconclusive' : 'evaluated_successfully';
			}
		}

		// =========================================================================
		// RULE 30: Structured data syntax errors (Secondary Category 3)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 30 ) && ! empty( $parsed_html['structured_data']['syntax_errors'] ) ) {
			foreach ( $parsed_html['structured_data']['syntax_errors'] as $syn_err ) {
				$findings[] = $this->create_finding(
					30,
					'Structured data syntax errors',
					'structured_data',
					sprintf( 'Malformed JSON-LD syntax detected: %s', $syn_err['error'] ),
					$syn_err['error'],
					'Valid JSON-LD schema markup syntax',
					$syn_err
				);
			}
		}

		// =========================================================================
		// RULE 31: Empty or minimal main content (Secondary Category 4)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 31 ) && isset( $parsed_html['main_content']['word_count'] ) ) {
			$wc = (int) $parsed_html['main_content']['word_count'];
			if ( $wc < $content_min ) {
				$findings[] = $this->create_finding(
					31,
					'Empty or minimal main content',
					'editorial',
					sprintf( 'Main body text contains only %d words (configured threshold: %d words).', $wc, $content_min ),
					sprintf( '%d words', $wc ),
					sprintf( '>= %d words', $content_min ),
					array( 'word_count' => $wc, 'threshold' => $content_min )
				);
			}
		}

		// =========================================================================
		// RULE 32: Social preview problems (Secondary Category 5)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 32 ) ) {
			$og = isset( $parsed_html['social_tags']['og'] ) ? $parsed_html['social_tags']['og'] : array();
			$missing_social = array();
			if ( empty( $og['og:title'] ) ) {
				$missing_social[] = 'og:title';
			}
			if ( empty( $og['og:description'] ) ) {
				$missing_social[] = 'og:description';
			}
			if ( empty( $og['og:image'] ) ) {
				$missing_social[] = 'og:image';
			}

			if ( ! empty( $missing_social ) ) {
				$findings[] = $this->create_finding(
					32,
					'Social preview problems (Open Graph / Twitter)',
					'social',
					sprintf( 'Missing essential social preview tags: %s.', implode( ', ', $missing_social ) ),
					implode( ', ', $missing_social ),
					'Complete Open Graph tags (og:title, og:description, og:image)',
					array( 'missing_tags' => $missing_social, 'present_og' => $og )
				);
			}
		}

		// =========================================================================
		// RULE 33: Heading hierarchy issues (Secondary Category 6)
		// =========================================================================
		if ( $this->settings->is_rule_enabled( 33 ) && ! empty( $headings ) ) {
			$prev_level = 0;
			$skipped    = array();
			$empty_h    = array();

			foreach ( $headings as $h_item ) {
				if ( ! empty( $h_item['is_empty'] ) ) {
					$empty_h[] = strtoupper( $h_item['tag'] );
				}

				$cur_level = $h_item['level'];
				if ( $prev_level > 0 && $cur_level > ( $prev_level + 1 ) ) {
					$skipped[] = sprintf( 'H%d to H%d', $prev_level, $cur_level );
				}
				$prev_level = $cur_level;
			}

			if ( ! empty( $skipped ) ) {
				$findings[] = $this->create_finding(
					33,
					'Heading hierarchy issues',
					'structure',
					sprintf( 'Skipped heading levels detected: %s. Headings should descend sequentially for accessibility.', implode( ', ', $skipped ) ),
					implode( ', ', $skipped ),
					'Sequential heading levels without skipping',
					array( 'skipped_levels' => $skipped )
				);
			}

			if ( ! empty( $empty_h ) ) {
				$findings[] = $this->create_finding(
					33,
					'Heading hierarchy issues',
					'structure',
					sprintf( 'Empty heading tags found in document: %s.', implode( ', ', $empty_h ) ),
					implode( ', ', $empty_h ),
					'Non-empty text content in all heading tags',
					array( 'empty_headings' => $empty_h )
				);
			}
		}

		return $findings;
	}

	/**
	 * Helper to create a standardized finding array with full context guidance.
	 *
	 * @param int    $rule_id Rule ID (1-33).
	 * @param string $rule_name Human-readable rule title.
	 * @param string $category Finding category.
	 * @param string $message Finding summary message.
	 * @param string $observed_value Observed value or condition.
	 * @param string $expected_threshold Expected threshold or criteria.
	 * @param array  $context_data Detailed context array.
	 * @return array
	 */
	public function create_finding( $rule_id, $rule_name, $category, $message, $observed_value, $expected_threshold, array $context_data = array() ) {
		$rule_def = $this->settings->get_rule_definitions();
		$def      = isset( $rule_def[ $rule_id ] ) ? $rule_def[ $rule_id ] : array();

		$why        = isset( $def['why_warrants_attention'] ) ? $def['why_warrants_attention'] : ( isset( $def['guidance'] ) ? $def['guidance'] : '' );
		$correction = isset( $def['suggested_correction'] ) ? $def['suggested_correction'] : 'Review and update in WordPress editor.';
		$verify     = isset( $def['how_to_verify'] ) ? $def['how_to_verify'] : 'Rescan URL after updating content.';

		// Derive a stable, element-specific evidence hash so specific findings (e.g. one broken link/image)
		// retain their individual ignored/history state without colliding with other findings for the same rule.
		if ( ! empty( $context_data['target_url'] ) ) {
			$evidence_key = 'target:' . $context_data['target_url'];
		} elseif ( ! empty( $context_data['image_url'] ) ) {
			$evidence_key = 'img:' . $context_data['image_url'];
		} elseif ( ! empty( $context_data['image_src'] ) ) {
			$evidence_key = 'img:' . $context_data['image_src'];
		} elseif ( ! empty( $context_data['destination'] ) ) {
			$evidence_key = 'dest:' . $context_data['destination'];
		} elseif ( ! empty( $context_data['element'] ) ) {
			$evidence_key = 'elem:' . $context_data['element'];
		} else {
			$evidence_key = (string) $rule_id . '|' . (string) $observed_value . '|' . (string) $message;
		}

		return array(
			'rule_id'                => (int) $rule_id,
			'rule_name'              => $rule_name,
			'severity'               => $this->settings->get_rule_severity( $rule_id ),
			'category'               => $category,
			'message'                => $message,
			'observed_value'         => (string) $observed_value,
			'expected_threshold'     => (string) $expected_threshold,
			'why_warrants_attention' => $why,
			'suggested_correction'   => $correction,
			'how_to_verify'          => $verify,
			'evidence_hash'          => md5( $evidence_key ),
			'context_data'           => wp_json_encode( $context_data ),
		);
	}
}
