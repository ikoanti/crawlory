<?php
/**
 * Safe HTTP Fetcher for Technical SEO Dashboard / Crawlory.
 *
 * Performs visitor-mode requests with strict domain validation,
 * redirect tracing, header capture, and SSRF protections.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Fetcher {

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_Settings $settings Settings instance.
	 */
	public function __construct( Crawlory_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Centralized strict URL validation for page audit destinations.
	 *
	 * Enforces supported HTTP(S) destinations, validates hostname, port,
	 * and site path boundaries, rejects embedded credentials and unsupported ports,
	 * and enforces strict same-site boundary.
	 *
	 * @param string $url URL to validate.
	 * @param bool   $strict_internal Whether destination must be on the current site.
	 * @return array Array with 'valid' (bool) and 'error' (string).
	 */
	public static function validate_audit_url( $url, $strict_internal = true ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return array( 'valid' => false, 'error' => __( 'Missing or invalid URL.', 'crawlory-technical-seo-dashboard' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return array( 'valid' => false, 'error' => __( 'Malformed URL structure.', 'crawlory-technical-seo-dashboard' ) );
		}

		// Supported schemes: ONLY http and https.
		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return array( 'valid' => false, 'error' => __( 'Unsupported URL scheme. Only HTTP and HTTPS are permitted.', 'crawlory-technical-seo-dashboard' ) );
		}

		// Reject embedded user/password credentials.
		if ( ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
			return array( 'valid' => false, 'error' => __( 'URLs containing embedded credentials are not allowed.', 'crawlory-technical-seo-dashboard' ) );
		}

		// SSRF protection: reject private, loopback, or reserved IP ranges.
		$host = strtolower( trim( $parsed['host'] ) );
		if ( 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false && filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			return array( 'valid' => false, 'error' => __( 'Private or internal IP addresses cannot be audited.', 'crawlory-technical-seo-dashboard' ) );
		}

		// Port validation: standard 80, 443, or the current site's configured port.
		$site_port     = wp_parse_url( home_url(), PHP_URL_PORT );
		$target_port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );
		$allowed_ports = array( 80, 443 );
		if ( ! empty( $site_port ) ) {
			$allowed_ports[] = (int) $site_port;
		}
		if ( ! in_array( $target_port, $allowed_ports, true ) ) {
			return array(
				'valid' => false,
				'error' => sprintf(
					/* translators: %d: Network port number. */
					__( 'Unsupported port (%d) rejected.', 'crawlory-technical-seo-dashboard' ),
					$target_port
				),
			);
		}

		// Reject administrative / sensitive routes.
		$path = isset( $parsed['path'] ) ? strtolower( $parsed['path'] ) : '';
		$blocked_routes = array( '/wp-admin', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php' );
		foreach ( $blocked_routes as $blocked ) {
			if ( 0 === strpos( $path, $blocked ) || false !== strpos( $path, $blocked ) ) {
				return array( 'valid' => false, 'error' => __( 'Administrative and login routes cannot be audited.', 'crawlory-technical-seo-dashboard' ) );
			}
		}

		if ( $strict_internal ) {
			// Compare exact host with home_url host without loose www stripping.
			$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$target_host = strtolower( (string) $parsed['host'] );

			if ( $site_host !== $target_host ) {
				return array(
					'valid' => false,
					'error' => sprintf(
						/* translators: 1: Destination host, 2: WordPress site host. */
						__( 'Destination host "%1$s" does not match this WordPress site host "%2$s".', 'crawlory-technical-seo-dashboard' ),
						$target_host,
						$site_host
					),
				);
			}

			// Site path boundary check.
			$site_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
			if ( ! empty( $site_path ) && '/' !== $site_path ) {
				$site_path       = '/' . trim( $site_path, '/' );
				$normalized_path = '/' . ltrim( $path, '/' );
				if ( $normalized_path !== $site_path && 0 !== strpos( $normalized_path, $site_path . '/' ) ) {
					return array( 'valid' => false, 'error' => __( 'Destination is outside WordPress site path boundary.', 'crawlory-technical-seo-dashboard' ) );
				}
			}
		}

		return array( 'valid' => true, 'error' => '' );
	}

	/**
	 * Verify if a URL is a valid, eligible internal URL of this WordPress site.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	public static function is_internal_url( $url ) {
		$validation = self::validate_audit_url( $url, true );
		return $validation['valid'];
	}

	/**
	 * Fetch live public visitor HTML response for a URL.
	 *
	 * @param string $url Absolute URL to scan.
	 * @param int    $retry_count Optional retry count for temporary failures.
	 * @return array Fetch result with explicit outcome classification.
	 */
	public function fetch( $url, $retry_count = 0 ) {
		$result = array(
			'success'          => false,
			'outcome'          => 'unknown', // 'success', 'http_error', 'timeout', 'blocked', 'rate_limited', 'non_html', 'unusable_response', 'parse_error', 'redirect_rejected', 'validation_error'
			'http_status'      => null,
			'requested_url'    => $url,
			'final_url'        => $url,
			'is_redirect'      => false,
			'redirect_hops'    => array(),
			'headers'          => array(),
			'x_robots_tag'     => null,
			'canonical_header' => null,
			'content_type'     => null,
			'html'             => '',
			'error'            => null,
			'is_html'          => false,
			'is_timeout'       => false,
			'is_blocked'       => false,
			'is_rate_limited'  => false,
			'is_temporary'     => false,
		);

		// Centralized strict URL validation.
		$val = self::validate_audit_url( $url, true );
		if ( ! $val['valid'] ) {
			$result['outcome'] = 'validation_error';
			$result['error']   = $val['error'];
			return $result;
		}

		$timeout         = (int) $this->settings->get( 'request_timeout', 15 );
		$max_bytes       = (int) apply_filters( 'crawlory_max_response_size', 1048576 ); // 1MB default evaluation limit.
		$transport_limit = $max_bytes + 1; // Limit-plus-one: reliably catches truncated documents beyond evaluation limit.

		// Build safe visitor request arguments without admin session or cookies.
		$args = array(
			'timeout'             => $timeout,
			'redirection'         => 0, // Bounded validation loop handles redirects manually.
			'httpversion'         => '1.1',
			'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (compatible; Crawlory/1.2; +https://crawlory.app; site=' . home_url() . ')',
			'blocking'            => true,
			'headers'             => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.5',
				'Cache-Control'   => 'no-cache',
			),
			'cookies'             => array(), // Explicitly empty: No admin authentication passed!
			'sslverify'           => true,    // TLS verification enabled.
			'limit_response_size' => $transport_limit, // Limit-plus-one transport bound.
		);

		$max_redirects = 5;
		$current_url   = $url;
		$redirect_hops = array();
		$seen_urls     = array( $url => true );
		$response      = null;

		for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
			// Enforce same-site validation on every single hop of a page audit.
			$val = self::validate_audit_url( $current_url, true );
			if ( ! $val['valid'] ) {
				$result['outcome']   = ( 0 === $hop ) ? 'validation_error' : 'redirect_rejected';
				$result['error']     = ( 0 === $hop ) ? $val['error'] : sprintf( 'Redirect to invalid or external URL rejected: %s (%s)', $current_url, $val['error'] );
				$result['final_url'] = $current_url;
				return $result;
			}

			$args_filtered = apply_filters( 'crawlory_fetch_request_args', $args, $current_url );
			$start_time    = microtime( true );
			$response      = wp_safe_remote_get( $current_url, $args_filtered );
			$duration      = round( microtime( true ) - $start_time, 3 );

			if ( is_wp_error( $response ) ) {
				$error_msg       = $response->get_error_message();
				$result['error'] = $error_msg;

				if ( false !== stripos( $error_msg, 'timed out' ) || false !== stripos( $error_msg, 'cURL error 28' ) ) {
					$result['outcome']      = 'timeout';
					$result['is_timeout']   = true;
					$result['is_temporary'] = true;
				} else {
					$result['outcome'] = 'connection_failure';
				}

				if ( $result['is_temporary'] && $retry_count < 1 && 0 === $hop ) {
					usleep( 500000 ); // 500ms
					return $this->fetch( $url, $retry_count + 1 );
				}

				$result['final_url'] = $current_url;
				return $result;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$headers  = wp_remote_retrieve_headers( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && ! empty( $location ) ) {
				// Normalize relative Location header to absolute.
				if ( ! preg_match( '/^https?:\/\//i', $location ) ) {
					$location = WP_Http::make_absolute_url( $location, $current_url );
				}

				$redirect_hops[] = array(
					'from'   => $current_url,
					'to'     => $location,
					'status' => $code,
				);

				if ( isset( $seen_urls[ $location ] ) ) {
					$result['outcome']       = 'http_error';
					$result['error']         = 'Redirect loop detected.';
					$result['http_status']   = $code;
					$result['final_url']     = $location;
					$result['is_redirect']   = true;
					$result['redirect_hops'] = $redirect_hops;
					return $result;
				}

				if ( $hop >= $max_redirects ) {
					$result['outcome']       = 'http_error';
					$result['error']         = 'Maximum redirect hops exceeded.';
					$result['http_status']   = $code;
					$result['final_url']     = $location;
					$result['is_redirect']   = true;
					$result['redirect_hops'] = $redirect_hops;
					return $result;
				}

				// Validate redirect destination policy before making next request.
				$val_redirect = self::validate_audit_url( $location, true );
				if ( ! $val_redirect['valid'] ) {
					$result['outcome']       = 'redirect_rejected';
					$result['error']         = sprintf( 'Redirect to invalid or external URL rejected: %s (%s)', $location, $val_redirect['error'] );
					$result['http_status']   = $code;
					$result['final_url']     = $location;
					$result['is_redirect']   = true;
					$result['redirect_hops'] = $redirect_hops;
					return $result;
				}

				$current_url                = $location;
				$seen_urls[ $current_url ] = true;
				continue;
			}

			// Terminal response reached.
			$result['final_url']     = $current_url;
			$result['is_redirect']   = ! empty( $redirect_hops );
			$result['redirect_hops'] = $redirect_hops;
			break;
		}

		// Ensure terminal response exists.
		if ( ! $response || is_wp_error( $response ) ) {
			$result['outcome']     = 'connection_failure';
			$result['error']       = is_wp_error( $response ) ? $response->get_error_message() : 'No response received from server.';
			$result['http_status'] = $code ? $code : null;
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$result['http_status'] = $code;

		// Extract and normalize response headers.
		$raw_headers = wp_remote_retrieve_headers( $response );
		$result['headers'] = is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ? $raw_headers->getAll() : (array) $raw_headers;

		// Extract Content-Type.
		$raw_content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$content_type     = '';
		if ( ! empty( $raw_content_type ) ) {
			$content_type = strtolower( trim( explode( ';', (string) $raw_content_type )[0] ) );
		}
		$result['content_type'] = $content_type ? $content_type : null;

		// Extract X-Robots-Tag header.
		$raw_x_robots = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		if ( is_array( $raw_x_robots ) ) {
			$raw_x_robots = implode( ', ', $raw_x_robots );
		}
		$result['x_robots_tag'] = ! empty( $raw_x_robots ) ? trim( (string) $raw_x_robots ) : null;

		// Extract HTTP Link canonical header.
		$link_header = wp_remote_retrieve_header( $response, 'link' );
		$result['canonical_header'] = self::extract_canonical_from_link_header( $link_header );

		// Rate limit / Cloudflare / WAF detection.
		if ( 429 === $code ) {
			$result['outcome']         = 'rate_limited';
			$result['is_rate_limited'] = true;
			$result['is_temporary']    = true;
			$result['error']           = 'Rate limited by server (HTTP 429).';
			return $result;
		} elseif ( 403 === $code ) {
			$result['outcome']    = 'blocked';
			$result['is_blocked'] = true;
			$result['error']      = 'Access blocked or forbidden (HTTP 403).';
			return $result;
		} elseif ( in_array( $code, array( 500, 502, 503, 504 ), true ) ) {
			$result['is_temporary'] = true;
			if ( $retry_count < 1 ) {
				usleep( 500000 );
				return $this->fetch( $url, $retry_count + 1 );
			}
			$result['outcome'] = 'http_error';
			$result['error']   = sprintf( 'Server error HTTP %d response returned.', $code );
			return $result;
		} elseif ( $code >= 400 ) {
			$result['outcome'] = 'http_error';
			$result['error']   = sprintf( 'HTTP %d error response returned.', $code );
			return $result;
		}

		// Check response size before loading full body into memory.
		$content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
		$max_bytes      = (int) apply_filters( 'crawlory_max_response_size', 1048576 ); // 1MB default.
		if ( $content_length > $max_bytes ) {
			$result['outcome'] = 'oversized_response';
			$result['error']   = sprintf( 'Response Content-Length (%d bytes) exceeds maximum allowed size (%d bytes).', $content_length, $max_bytes );
			$result['is_html'] = false;
			return $result;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $max_bytes ) {
			$result['outcome'] = 'oversized_response';
			$result['error']   = sprintf( 'Response body size (%d bytes) exceeds maximum allowed evaluation limit (%d bytes). Document truncated and skipped.', strlen( $body ), $max_bytes );
			$result['is_html'] = false;
			return $result;
		}

		// Content-Type validation.
		if ( false === stripos( $content_type, 'text/html' ) && false === stripos( $content_type, 'application/xhtml+xml' ) ) {
			$result['outcome'] = 'non_html';
			$result['error']   = sprintf( 'Non-HTML Content-Type returned: %s. Page skipped from HTML audit.', $content_type ? $content_type : 'unknown' );
			$result['is_html'] = false;
			return $result;
		}

		// Empty / unusable body check.
		if ( '' === trim( $body ) ) {
			$result['outcome'] = 'unusable_response';
			$result['error']   = 'Empty or zero-byte response body returned by server.';
			return $result;
		}

		$result['is_html'] = true;

		if ( 200 !== $code ) {
			$result['outcome'] = 'http_error';
			$result['error']   = sprintf( 'HTTP %d response returned.', $code );
			return $result;
		}

		$result['outcome'] = 'success';
		$result['success'] = true;
		$result['html']    = $body;

		return $result;
	}

	/**
	 * Extract canonical URL from HTTP Link header.
	 *
	 * Supports RFC 5988 / RFC 8288 format: <url>; rel="canonical"
	 *
	 * @param string|array $link_header Raw Link header string or array.
	 * @return string|null Extracted canonical URL or null if not present.
	 */
	public static function extract_canonical_from_link_header( $link_header ) {
		if ( empty( $link_header ) ) {
			return null;
		}

		$headers = is_array( $link_header ) ? $link_header : array( $link_header );
		foreach ( $headers as $hdr ) {
			$entries = explode( ',', (string) $hdr );
			foreach ( $entries as $entry ) {
				if ( preg_match( '/<([^>]+)>;\s*rel=["\']?canonical["\']?/i', trim( $entry ), $matches ) ) {
					return trim( $matches[1] );
				}
			}
		}

		return null;
	}

	/**
	 * Trace redirect hops for a URL step-by-step to detect redirect chains and loops.
	 *
	 * @param string $initial_url The starting URL.
	 * @param int    $max_hops Maximum hops to trace.
	 * @return array Array containing hops, final_url, has_chain, has_loop, and status_code.
	 */
	public function trace_redirects( $initial_url, $max_hops = 6 ) {
		$hops       = array();
		$seen_urls  = array();
		$curr_url   = $initial_url;
		$has_loop   = false;
		$final_code = null;

		for ( $i = 0; $i < $max_hops; $i++ ) {
			$seen_urls[] = $curr_url;

			$args = array(
				'timeout'     => 10,
				'redirection' => 0, // Do NOT follow automatically, inspect each hop
				'httpversion' => '1.1',
				'user-agent'  => 'Crawlory/1.1; (+https://crawlory.app)',
				'cookies'     => array(),
				'sslverify'   => true,
			);

			$response = wp_safe_remote_get( $curr_url, $args );
			if ( is_wp_error( $response ) ) {
				$hops[] = array(
					'url'    => $curr_url,
					'status' => 'error',
					'error'  => $response->get_error_message(),
				);
				break;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$final_code = $code;

			$hops[] = array(
				'url'      => $curr_url,
				'status'   => $code,
				'location' => $location,
			);

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && ! empty( $location ) ) {
				// Normalize relative location header to absolute.
				if ( ! preg_match( '/^https?:\/\//i', $location ) ) {
					$location = WP_Http::make_absolute_url( $location, $curr_url );
				}

				// Check loop.
				if ( in_array( $location, $seen_urls, true ) ) {
					$has_loop = true;
					$curr_url = $location;
					break;
				}

				// Validate redirect hop destination safety.
				if ( ! wp_http_validate_url( $location ) ) {
					$hops[] = array(
						'url'    => $location,
						'status' => 'rejected',
						'error'  => 'Invalid redirect destination URL rejected.',
					);
					break;
				}

				$curr_url = $location;
			} else {
				// Terminal response reached.
				break;
			}
		}

		$is_chain       = ( count( $hops ) > 2 ); // More than 1 redirect hop before terminal
		$is_exhausted   = ( count( $hops ) >= $max_hops && in_array( $final_code, array( 301, 302, 303, 307, 308 ), true ) && ! $has_loop );

		return array(
			'initial_url'        => $initial_url,
			'final_url'          => $curr_url,
			'hops'               => $hops,
			'hop_count'          => count( $hops ),
			'has_chain'          => $is_chain,
			'has_loop'           => $has_loop,
			'is_limit_exhausted' => $is_exhausted,
			'final_status'       => $final_code,
		);
	}

	/**
	 * Check status of a single link destination with strict SSRF protections and bounded timeout.
	 *
	 * @param string $url URL to check.
	 * @param bool   $is_external Whether the link is external.
	 * @param int    $timeout Optional custom timeout in seconds.
	 * @return array Status array.
	 */
	public function check_link( $url, $is_external = false, $timeout = 0 ) {
		$result = array(
			'url'             => $url,
			'http_status'     => null,
			'is_broken'       => false,
			'is_inconclusive' => false,
			'error'           => null,
			'final_url'       => $url,
		);

		// Validate URL format.
		if ( ! wp_http_validate_url( $url ) ) {
			$result['is_inconclusive'] = true;
			$result['error']           = 'Invalid URL scheme or format';
			return $result;
		}

		// For internal links, ensure host matches.
		if ( ! $is_external && ! self::is_internal_url( $url ) ) {
			$result['is_inconclusive'] = true;
			$result['error']           = 'Cross-domain internal link check blocked';
			return $result;
		}

		$effective_timeout = $timeout > 0 ? (int) $timeout : ( $is_external ? 5 : 8 );

		$args = array(
			'timeout'             => $effective_timeout,
			'redirection'         => 3,
			'httpversion'         => '1.1',
			'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (compatible; Crawlory/1.2; +https://crawlory.app)',
			'cookies'             => array(),
			'sslverify'           => true,
			'limit_response_size' => 262144, // 256KB max response for resource inspection
		);

		// Try HEAD first to save bandwidth.
		$response = wp_safe_remote_head( $url, $args );

		// If HEAD returned 405 Method Not Allowed or error, fallback to bounded GET.
		if ( is_wp_error( $response ) || ( ! is_wp_error( $response ) && 405 === wp_remote_retrieve_response_code( $response ) ) ) {
			$args['headers'] = array( 'Range' => 'bytes=0-1024' ); // Bounded request
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			$result['error'] = $msg;

			// External timeouts, connection resets, or bot challenges are inconclusive.
			if ( $is_external ) {
				$result['is_inconclusive'] = true;
			} else {
				if ( false !== stripos( $msg, 'timed out' ) ) {
					$result['is_inconclusive'] = true;
				} else {
					$result['is_broken'] = true;
				}
			}
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$result['http_status'] = $code;

		// Handle status classification.
		if ( 404 === $code || 410 === $code ) {
			$result['is_broken'] = true;
		} elseif ( in_array( $code, array( 403, 429, 503, 504 ), true ) ) {
			// Access blocks, rate limits, Cloudflare challenges: inconclusive.
			$result['is_inconclusive'] = true;
		} elseif ( $code >= 500 ) {
			$result['is_broken'] = ! $is_external; // On internal sites 500 is a persistent failure
		}

		return $result;
	}

	/**
	 * Check image resource status.
	 *
	 * @param string $img_url Image URL.
	 * @param int    $timeout Optional custom timeout in seconds.
	 * @return array Image status result.
	 */
	public function check_image( $img_url, $timeout = 0 ) {
		return $this->check_link( $img_url, ! self::is_internal_url( $img_url ), $timeout );
	}
}
