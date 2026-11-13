<?php
/**
 * Robots.txt Parser and Googlebot Rule Evaluator for Crawlory.
 *
 * Evaluates applicable Googlebot and default (*) directives
 * against any URL on the site.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Robots_Parser {

	/**
	 * Cached robots.txt rules.
	 *
	 * @var array|null
	 */
	private static $cached_rules = null;

	/**
	 * Get and parse site's robots.txt content.
	 *
	 * @return array Parsed rules per user-agent.
	 */
	public static function get_rules() {
		if ( null !== self::$cached_rules ) {
			return self::$cached_rules;
		}

		$cached = get_transient( 'crawlory_robots_txt_rules' );
		if ( false !== $cached && is_array( $cached ) ) {
			self::$cached_rules = $cached;
			return self::$cached_rules;
		}

		$robots_url = home_url( '/robots.txt' );
		$args       = array(
			'timeout'     => 10,
			'redirection' => 2,
			'user-agent'  => 'Crawlory/1.1; (+https://crawlory.app)',
			'sslverify'   => true,
		);

		$content  = '';
		$response = wp_safe_remote_get( $robots_url, $args );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$content = (string) wp_remote_retrieve_body( $response );
		}

		// If remote fetch failed or returned empty, check if WP dynamic robots.txt action is available.
		if ( empty( $content ) && function_exists( 'apply_filters' ) ) {
			ob_start();
			do_action( 'do_robotstxt' );
			$dyn = ob_get_clean();
			if ( ! empty( $dyn ) ) {
				$content = $dyn;
			}
		}

		self::$cached_rules = self::parse_robots_content( $content );
		set_transient( 'crawlory_robots_txt_rules', self::$cached_rules, HOUR_IN_SECONDS * 6 );

		return self::$cached_rules;
	}

	/**
	 * Parse raw robots.txt lines into structured rules.
	 *
	 * @param string $content Raw robots.txt text.
	 * @return array
	 */
	public static function parse_robots_content( $content ) {
		$lines = explode( "\n", (string) $content );
		$rules = array(
			'googlebot' => array(),
			'*'         => array(),
			'sitemaps'  => array(),
		);

		$current_agents = array();

		foreach ( $lines as $line_num => $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( empty( $line ) ) {
				continue;
			}

			if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
				$agent = strtolower( trim( $m[1] ) );
				if ( 'googlebot' === $agent || '*' === $agent ) {
					$current_agents[] = $agent;
				} else {
					$current_agents = array(); // Other agents
				}
				continue;
			}

			if ( preg_match( '/^Sitemap:\s*(.+)$/i', $line, $m ) ) {
				$rules['sitemaps'][] = trim( $m[1] );
				continue;
			}

			if ( ! empty( $current_agents ) ) {
				$type = null;
				$path = '';

				if ( preg_match( '/^Disallow:\s*(.*)$/i', $line, $m ) ) {
					$type = 'disallow';
					$path = trim( $m[1] );
				} elseif ( preg_match( '/^Allow:\s*(.*)$/i', $line, $m ) ) {
					$type = 'allow';
					$path = trim( $m[1] );
				}

				if ( null !== $type ) {
					foreach ( $current_agents as $agent ) {
						$rules[ $agent ][] = array(
							'type' => $type,
							'path' => $path,
							'rule' => $line,
							'line' => $line_num + 1,
						);
					}
				}
			}
		}

		return $rules;
	}

	/**
	 * Evaluate whether a URL is blocked for Googlebot.
	 *
	 * @param string $url URL to test.
	 * @return array Evaluation result with is_blocked and matching_rule.
	 */
	public static function evaluate_url( $url ) {
		$rules = self::get_rules();

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			$path = '/';
		}

		// 1. Check Googlebot-specific rules first.
		if ( ! empty( $rules['googlebot'] ) ) {
			$match = self::match_path( $path, $rules['googlebot'] );
			if ( null !== $match ) {
				return array(
					'is_blocked'    => ( 'disallow' === $match['type'] ),
					'matching_rule' => $match['rule'],
					'rule_type'     => $match['type'],
					'matched_path'  => $match['path'],
					'user_agent'    => 'Googlebot',
				);
			}
		}

		// 2. Fallback to generic (*) rules.
		if ( ! empty( $rules['*'] ) ) {
			$match = self::match_path( $path, $rules['*'] );
			if ( null !== $match ) {
				return array(
					'is_blocked'    => ( 'disallow' === $match['type'] ),
					'matching_rule' => $match['rule'],
					'rule_type'     => $match['type'],
					'matched_path'  => $match['path'],
					'user_agent'    => '*',
				);
			}
		}

		return array(
			'is_blocked'    => false,
			'matching_rule' => null,
			'rule_type'     => 'allow_default',
			'matched_path'  => null,
			'user_agent'    => 'none',
		);
	}

	/**
	 * Match a path against a list of rules using longest-match precedence.
	 *
	 * @param string $path URL path.
	 * @param array  $rule_list List of rules.
	 * @return array|null Matching rule record or null if none matched.
	 */
	private static function match_path( $path, array $rule_list ) {
		$best_match  = null;
		$best_length = -1;

		foreach ( $rule_list as $r ) {
			$rule_path = $r['path'];
			if ( empty( $rule_path ) ) {
				// Empty Disallow: means allow all
				if ( 'disallow' === $r['type'] ) {
					$r['type'] = 'allow';
				}
				$rule_path = '/';
			}

			// Convert robots pattern to regex (supporting * and $).
			$pattern = preg_quote( $rule_path, '/' );
			$pattern = str_replace( '\*', '.*', $pattern );
			if ( substr( $rule_path, -1 ) === '$' ) {
				$pattern = substr( $pattern, 0, -2 ) . '$';
			} else {
				$pattern = '^' . $pattern;
			}

			if ( preg_match( '/' . $pattern . '/', $path ) ) {
				$len = strlen( $r['path'] );
				if ( $len > $best_length ) {
					$best_length = $len;
					$best_match  = $r;
				} elseif ( $len === $best_length && 'allow' === $r['type'] ) {
					// In tie, Allow takes precedence over Disallow in Google's specification
					$best_match = $r;
				}
			}
		}

		return $best_match;
	}
}
