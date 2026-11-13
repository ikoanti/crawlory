<?php
/**
 * XML Sitemap Parser and Inspector for Crawlory.
 *
 * Discovers sitemap indexes and child sitemaps, extracts listed URLs,
 * and caches them for site-wide consistency checking.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Sitemap_Parser {

	/**
	 * Database instance.
	 *
	 * @var Crawlory_DB
	 */
	private $db;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param Crawlory_DB|null $db Database instance.
	 */
	public function __construct( Crawlory_DB $db = null ) {
		$this->db = $db ? $db : new Crawlory_DB();
	}

	/**
	 * Discover and inspect all XML sitemaps for the current site.
	 *
	 * Handles sitemap indexes and child sitemaps, applies bounded requests,
	 * safe XML parsing, tracks discovery failures and incomplete coverage,
	 * respects remaining time budget, and refreshes membership cleanly.
	 *
	 * @param int   $max_child_sitemaps Safety bound for child sitemaps.
	 * @param float $budget Execution time budget in seconds (default 15.0).
	 * @param array $initial_queue Resumed queue if continuing from previous slice.
	 * @return array Discovered URLs and inspection stats.
	 */
	public function inspect_sitemaps( $max_child_sitemaps = 20, $budget = 15.0, array $initial_queue = array() ) {
		// Graceful dependency check for SimpleXML extension.
		if ( ! extension_loaded( 'simplexml' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return array(
				'inspected'       => array(),
				'failed'          => array(
					array(
						'url'    => '',
						'status' => 0,
						'error'  => __( 'PHP SimpleXML extension is required for XML sitemap parsing but is not available on this server.', 'crawlory-technical-seo-dashboard' ),
					),
				),
				'discovered_urls' => array(),
				'is_incomplete'   => false,
			);
		}

		$start_time = microtime( true );
		$candidate_sitemaps = ! empty( $initial_queue ) ? $initial_queue : array();

		if ( empty( $candidate_sitemaps ) ) {
			// 1. Check robots.txt declared sitemaps.
			if ( class_exists( 'Crawlory_Robots_Parser' ) ) {
				$robots_rules = Crawlory_Robots_Parser::get_rules();
				if ( ! empty( $robots_rules['sitemaps'] ) ) {
					foreach ( $robots_rules['sitemaps'] as $sm ) {
						$candidate_sitemaps[] = esc_url_raw( $sm );
					}
				}
			}

			// 2. Standard WordPress and SEO plugin sitemap endpoints.
			$candidate_sitemaps[] = home_url( '/wp-sitemap.xml' );
			$candidate_sitemaps[] = home_url( '/sitemap_index.xml' );
			$candidate_sitemaps[] = home_url( '/sitemap.xml' );

			$candidate_sitemaps = array_unique( $candidate_sitemaps );
		}

		$discovered_urls = array();
		$inspected_files = array();
		$failed_sitemaps = array();
		$queue           = $candidate_sitemaps;
		$child_count     = 0;

		while ( ! empty( $queue ) && $child_count < $max_child_sitemaps ) {
			// Check execution budget before starting remote request.
			$elapsed   = microtime( true ) - $start_time;
			$remaining = $budget - $elapsed;
			if ( $remaining < 2.5 ) {
				// Budget exhausted: break so remaining queue can be resumed.
				break;
			}

			$sm_url = array_shift( $queue );
			if ( in_array( $sm_url, $inspected_files, true ) ) {
				continue;
			}

			// Validate sitemap URL format.
			if ( ! wp_http_validate_url( $sm_url ) ) {
				continue;
			}

			$inspected_files[] = $sm_url;

			$sitemap_max_bytes = 5242880; // 5MB evaluation limit.
			$transport_limit   = $sitemap_max_bytes + 1; // Limit-plus-one to reliably detect truncated documents.

			$req_timeout = min( 8, max( 2, (int) ( $remaining - 1.0 ) ) );
			$args = array(
				'timeout'             => $req_timeout,
				'redirection'         => 3,
				'user-agent'          => 'Crawlory/1.3; (+https://crawlory.app; site=' . home_url() . ')',
				'cookies'             => array(),
				'sslverify'           => true,
				'limit_response_size' => $transport_limit,
			);

			$response = wp_safe_remote_get( $sm_url, $args );
			$status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( is_wp_error( $response ) || 200 !== $status_code ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => $status_code,
					'error'  => is_wp_error( $response ) ? $response->get_error_message() : sprintf( 'HTTP %d', $status_code ),
				);
				continue;
			}

			// Preflight Content-Length.
			$content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
			if ( $content_length > $sitemap_max_bytes ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => 200,
					'error'  => sprintf( 'Sitemap response Content-Length (%d bytes) exceeds maximum allowed size (5MB).', $content_length ),
				);
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( empty( $body ) || strlen( $body ) < 10 ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => 200,
					'error'  => 'Empty or truncated sitemap response body',
				);
				continue;
			}

			// Reject oversized or truncated sitemaps (>5MB) to protect worker memory budget.
			if ( strlen( $body ) > $sitemap_max_bytes ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => 200,
					'error'  => sprintf( 'Sitemap response body (%d bytes) exceeds maximum allowed evaluation limit (5MB). Document truncated and skipped.', strlen( $body ) ),
				);
				continue;
			}

			// Pre-parse XXE protection: reject external entities and DTD declarations.
			if ( preg_match( '/<!ENTITY|<!DOCTYPE[^>]+(?:SYSTEM|PUBLIC)/i', $body ) ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => 200,
					'error'  => 'Disallowed XML DOCTYPE or ENTITY declaration detected.',
				);
				continue;
			}

			// Safe XML parsing with LIBXML_NONET and try-finally error restoration.
			$prev_errors = libxml_use_internal_errors( true );
			try {
				libxml_clear_errors();
				$xml = @simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $prev_errors );
			}

			if ( false === $xml ) {
				$failed_sitemaps[] = array(
					'url'    => $sm_url,
					'status' => 200,
					'error'  => 'XML parsing failure or malformed sitemap syntax',
				);
				continue;
			}

			$root_name = strtolower( $xml->getName() );

			// Check if sitemapindex.
			if ( 'sitemapindex' === $root_name ) {
				foreach ( $xml->sitemap as $child_node ) {
					if ( isset( $child_node->loc ) ) {
						$child_url = esc_url_raw( trim( (string) $child_node->loc ) );
						if ( ! empty( $child_url ) && ! in_array( $child_url, $inspected_files, true ) && ! in_array( $child_url, $queue, true ) ) {
							$queue[] = $child_url;
						}
					}
				}
			} elseif ( 'urlset' === $root_name ) {
				$child_count++;
				$sitemap_member_urls   = array();
				$sitemap_member_hashes = array();

				foreach ( $xml->url as $url_node ) {
					if ( isset( $url_node->loc ) ) {
						$loc = esc_url_raw( trim( (string) $url_node->loc ) );
						if ( ! empty( $loc ) ) {
							$sitemap_member_urls[]   = $loc;
							$sitemap_member_hashes[] = md5( $loc );

							$discovered_urls[ $loc ] = array(
								'sitemap_url'  => $sm_url,
								'url'          => $loc,
								'lastmod'      => isset( $url_node->lastmod ) ? (string) $url_node->lastmod : null,
								'discovered_at'=> current_time( 'mysql' ),
							);
						}
					}
				}

				// Persist URLs for this sitemap with proper signature (array of strings and source sitemap URL).
				if ( $this->db ) {
					$this->db->save_sitemap_urls( $sitemap_member_urls, $sm_url, 200, 'valid' );
					// Prune obsolete memberships for this refreshed sitemap.
					$this->db->prune_stale_sitemap_urls( $sm_url, $sitemap_member_hashes );
				}
			}
		}

		$is_incomplete = ( ! empty( $failed_sitemaps ) || ! empty( $queue ) );

		return array(
			'inspected_sitemaps' => $inspected_files,
			'failed_sitemaps'    => $failed_sitemaps,
			'is_incomplete'      => $is_incomplete,
			'remaining_queue'    => $queue,
			'total_urls'         => count( $discovered_urls ),
			'urls'               => array_keys( $discovered_urls ),
		);
	}

	/**
	 * Check if a URL exists in the inspected sitemaps cache.
	 *
	 * @param string $url Page URL.
	 * @return bool
	 */
	public function is_url_in_sitemap( $url ) {
		if ( $this->db ) {
			return $this->db->is_url_in_sitemap( $url );
		}
		return false;
	}

	/**
	 * Backward compatibility static accessor.
	 *
	 * @param string      $url Page URL.
	 * @param Crawlory_DB|null $db Database instance.
	 * @return bool
	 */
	public static function check_url_in_sitemap( $url, Crawlory_DB $db = null ) {
		$instance = new self( $db );
		return $instance->is_url_in_sitemap( $url );
	}

	/**
	 * Parse XML string safely and extract URL locations.
	 *
	 * Uses LIBXML_NONET, XXE entity suppression, and safe simplexml parsing.
	 *
	 * @param string $xml_string XML content.
	 * @param string $sitemap_url Source sitemap URL.
	 * @return array Array of URL strings.
	 */
	public function parse_xml_string( $xml_string, $sitemap_url = '' ) {
		if ( empty( $xml_string ) || ! is_string( $xml_string ) || strlen( $xml_string ) > 5242880 ) {
			return array();
		}

		// Pre-parse XXE protection: reject external entities and DTD declarations.
		if ( preg_match( '/<!ENTITY|<!DOCTYPE[^>]+(?:SYSTEM|PUBLIC)/i', $xml_string ) ) {
			return array();
		}

		$prev_errors = libxml_use_internal_errors( true );
		try {
			libxml_clear_errors();
			$xml = @simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_errors );
		}

		if ( false === $xml ) {
			return array();
		}

		$urls = array();
		$root_name = strtolower( $xml->getName() );
		if ( 'urlset' === $root_name ) {
			foreach ( $xml->url as $url_node ) {
				if ( isset( $url_node->loc ) ) {
					$loc = esc_url_raw( trim( (string) $url_node->loc ) );
					if ( ! empty( $loc ) ) {
						$urls[] = $loc;
					}
				}
			}
		} elseif ( 'sitemapindex' === $root_name ) {
			foreach ( $xml->sitemap as $sm_node ) {
				if ( isset( $sm_node->loc ) ) {
					$loc = esc_url_raw( trim( (string) $sm_node->loc ) );
					if ( ! empty( $loc ) ) {
						$urls[] = $loc;
					}
				}
			}
		}

		return $urls;
	}
}
