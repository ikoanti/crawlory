<?php
/**
 * DOM-based HTML Parser for Technical SEO Dashboard / Crawlory.
 *
 * Extracts SEO title, meta description, H1, heading hierarchy,
 * canonical tags, robots meta directives, anchor links, images,
 * Open Graph / Twitter cards, structured data (JSON-LD), and main content.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_HTML_Parser {

	/**
	 * Parse HTML response into structured SEO audit elements.
	 *
	 * @param string $html Raw HTML string from HTTP response.
	 * @param string $page_url The URL being audited (used for resolving relative links/images).
	 * @return array Parsed audit structure.
	 */
	public static function parse( $html, $page_url = '' ) {
		$result = array(
			'titles'          => array(),
			'descriptions'    => array(),
			'h1s'             => array(),
			'headings'        => array(), // Full heading sequence (h1..h6)
			'canonicals'      => array(), // <link rel="canonical">
			'robots_meta'     => array(), // <meta name="robots|googlebot" content="...">
			'links'           => array(), // <a href="...">
			'images'          => array(), // <img> with src, srcset, lazy attributes
			'social_tags'     => array(
				'og'      => array(),
				'twitter' => array(),
			),
			'structured_data' => array(
				'scripts_count' => 0,
				'valid_json'    => array(),
				'syntax_errors' => array(),
				'schemas'       => array(),
			),
			'main_content'    => array(
				'word_count'    => 0,
				'raw_text'      => '',
			),
			'is_valid_html'   => true,
			'parse_error'     => null,
		);

		if ( empty( $html ) || ! is_string( $html ) ) {
			$result['is_valid_html'] = false;
			$result['parse_error']   = 'Empty or non-string HTML payload.';
			return $result;
		}

		// Graceful check for PHP DOM extension.
		if ( ! class_exists( 'DOMDocument' ) || ! extension_loaded( 'dom' ) ) {
			$result['is_valid_html'] = false;
			$result['parse_error']   = __( 'PHP DOM extension is required for HTML parsing but is not available on this server.', 'crawlory-technical-seo-dashboard' );
			return $result;
		}

		$dom = new DOMDocument();
		$dom->substituteEntities = false;
		$dom->resolveExternals   = false;

		$previous_errors = libxml_use_internal_errors( true );
		try {
			libxml_clear_errors();
			// Ensure UTF-8 parsing by prepending a charset meta tag if not present.
			$html_with_charset = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
			$loaded = @$dom->loadHTML( $html_with_charset, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}

		if ( ! $loaded ) {
			$result['is_valid_html'] = false;
			$result['parse_error']   = 'DOMDocument failed to load HTML string.';
			return $result;
		}

		$xpath = new DOMXPath( $dom );

		// 1. Extract HTML <title> elements (strictly excluding SVG <title> tags).
		$title_nodes = $xpath->query( '//head//title' );
		if ( ! $title_nodes || 0 === $title_nodes->length ) {
			$title_nodes = $xpath->query( '//*[local-name()="title" and not(ancestor::*[local-name()="svg"])]' );
		}

		if ( $title_nodes && $title_nodes->length > 0 ) {
			foreach ( $title_nodes as $node ) {
				$raw_text   = $node->textContent;
				$normalized = Crawlory_Normalizer::normalize_text( $raw_text );
				$result['titles'][] = array(
					'raw'             => $raw_text,
					'normalized'      => $normalized,
					'char_count'      => Crawlory_Normalizer::character_count( $normalized ),
					'is_empty'        => ( '' === $normalized ),
				);
			}
		}

		// 2. Extract <meta name="description"> tags.
		$meta_nodes = $xpath->query( "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='description']" );
		if ( $meta_nodes && $meta_nodes->length > 0 ) {
			foreach ( $meta_nodes as $node ) {
				$has_content_attr = $node->hasAttribute( 'content' );
				$raw_content      = $has_content_attr ? $node->getAttribute( 'content' ) : null;
				$normalized       = null !== $raw_content ? Crawlory_Normalizer::normalize_text( $raw_content ) : '';

				$status = 'valid';
				if ( ! $has_content_attr ) {
					$status = 'attribute_missing';
				} elseif ( '' === $normalized ) {
					$status = 'whitespace_only';
				}

				$result['descriptions'][] = array(
					'has_content_attr' => $has_content_attr,
					'raw'              => $raw_content,
					'normalized'       => $normalized,
					'char_count'       => Crawlory_Normalizer::character_count( $normalized ),
					'status'           => $status,
				);
			}
		}

		// 3. Extract Canonical tags: <link rel="canonical" href="...">
		$canonical_nodes = $xpath->query( "//link[translate(@rel, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='canonical']" );
		if ( $canonical_nodes && $canonical_nodes->length > 0 ) {
			foreach ( $canonical_nodes as $node ) {
				$href = $node->hasAttribute( 'href' ) ? trim( $node->getAttribute( 'href' ) ) : '';
				$result['canonicals'][] = array(
					'href'     => $href,
					'is_empty' => ( '' === $href ),
				);
			}
		}

		// 4. Extract Robots meta tags: <meta name="robots|googlebot" content="...">
		$robots_nodes = $xpath->query( "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='robots' or translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='googlebot']" );
		if ( $robots_nodes && $robots_nodes->length > 0 ) {
			foreach ( $robots_nodes as $node ) {
				$name_attr    = strtolower( (string) $node->getAttribute( 'name' ) );
				$content_attr = (string) $node->getAttribute( 'content' );
				$directives   = array_map( 'trim', explode( ',', strtolower( $content_attr ) ) );

				$result['robots_meta'][] = array(
					'name'        => $name_attr,
					'content'     => $content_attr,
					'directives'  => $directives,
					'has_noindex' => in_array( 'noindex', $directives, true ),
					'has_nofollow'=> in_array( 'nofollow', $directives, true ),
				);
			}
		}

		// 5. Extract Headings (h1..h6 in sequential document order).
		$heading_nodes = $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' );
		$h1_index      = 1;
		if ( $heading_nodes && $heading_nodes->length > 0 ) {
			foreach ( $heading_nodes as $node ) {
				$tag        = strtolower( $node->nodeName );
				$level      = (int) substr( $tag, 1 );
				$raw_text   = $node->textContent;
				$normalized = Crawlory_Normalizer::normalize_text( $raw_text );

				$item = array(
					'tag'        => $tag,
					'level'      => $level,
					'raw'        => $raw_text,
					'normalized' => $normalized,
					'char_count' => Crawlory_Normalizer::character_count( $normalized ),
					'is_empty'   => ( '' === $normalized ),
				);

				$result['headings'][] = $item;

				if ( 'h1' === $tag ) {
					$h1_item = $item;
					$h1_item['index'] = $h1_index++;
					$result['h1s'][]  = $h1_item;
				}
			}
		}

		// 6. Extract <img> elements (supporting src, srcset, data-src, lazy-loading).
		$img_nodes = $xpath->query( '//img' );
		if ( $img_nodes && $img_nodes->length > 0 ) {
			foreach ( $img_nodes as $node ) {
				$src          = $node->getAttribute( 'src' );
				$srcset       = $node->getAttribute( 'srcset' );
				$data_src     = $node->getAttribute( 'data-src' );
				$data_lazy    = $node->getAttribute( 'data-lazy-src' );
				$data_orig    = $node->getAttribute( 'data-original' );
				$effective_src = $src ? $src : ( $data_src ? $data_src : ( $data_lazy ? $data_lazy : $data_orig ) );

				// Resolve relative image URL if page_url provided.
				if ( ! empty( $page_url ) && ! empty( $effective_src ) && ! preg_match( '/^https?:\/\//i', $effective_src ) && 0 !== strpos( $effective_src, '//' ) && 0 !== strpos( $effective_src, 'data:' ) ) {
					$resolved_img = WP_Http::make_absolute_url( $effective_src, $page_url );
					if ( $resolved_img ) {
						$effective_src = $resolved_img;
					}
				}

				$has_alt_attr = $node->hasAttribute( 'alt' );
				$raw_alt      = $has_alt_attr ? $node->getAttribute( 'alt' ) : null;
				$norm_alt     = null !== $raw_alt ? Crawlory_Normalizer::normalize_text( $raw_alt ) : '';

				$alt_status = 'valid';
				if ( ! $has_alt_attr ) {
					$alt_status = 'missing_attribute';
				} elseif ( '' === $norm_alt ) {
					$alt_status = 'empty_alt';
				}

				$result['images'][] = array(
					'src'                     => $effective_src,
					'raw_src'                 => $src,
					'srcset'                  => $srcset,
					'has_alt_attr'            => $has_alt_attr,
					'raw_alt'                 => $raw_alt,
					'normalized_alt'          => $norm_alt,
					'alt_status'              => $alt_status,
					'is_decorative_candidate' => ( 'empty_alt' === $alt_status ),
				);
			}
		}

		// 7. Extract Links (<a href="...">).
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$a_nodes     = $xpath->query( '//a[@href]' );
		if ( $a_nodes && $a_nodes->length > 0 ) {
			foreach ( $a_nodes as $node ) {
				$href = trim( $node->getAttribute( 'href' ) );
				if ( empty( $href ) || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
					continue;
				}

				// Resolve absolute URL.
				$abs_url = $href;
				if ( ! empty( $page_url ) && ! preg_match( '/^https?:\/\//i', $href ) && 0 !== strpos( $href, '//' ) ) {
					$abs = WP_Http::make_absolute_url( $href, $page_url );
					if ( $abs ) {
						$abs_url = $abs;
					}
				}

				$parsed_target = wp_parse_url( $abs_url );
				$target_host   = ! empty( $parsed_target['host'] ) ? strtolower( $parsed_target['host'] ) : '';
				$is_internal   = empty( $target_host ) || ( preg_replace( '/^www\./', '', $target_host ) === preg_replace( '/^www\./', '', $site_host ) );

				$anchor_text = Crawlory_Normalizer::normalize_text( $node->textContent );
				$rel         = (string) $node->getAttribute( 'rel' );

				$result['links'][] = array(
					'href'        => $abs_url,
					'raw_href'    => $href,
					'anchor_text' => $anchor_text,
					'rel'         => $rel,
					'is_internal' => $is_internal,
				);
			}
		}

		// 8. Extract Open Graph & Twitter Cards.
		$og_nodes = $xpath->query( "//meta[starts-with(@property, 'og:') or starts-with(@name, 'og:')]" );
		if ( $og_nodes && $og_nodes->length > 0 ) {
			foreach ( $og_nodes as $node ) {
				$prop = $node->hasAttribute( 'property' ) ? strtolower( $node->getAttribute( 'property' ) ) : strtolower( $node->getAttribute( 'name' ) );
				$val  = (string) $node->getAttribute( 'content' );
				$result['social_tags']['og'][ $prop ] = trim( $val );
			}
		}

		$twitter_nodes = $xpath->query( "//meta[starts-with(@name, 'twitter:')]" );
		if ( $twitter_nodes && $twitter_nodes->length > 0 ) {
			foreach ( $twitter_nodes as $node ) {
				$name = strtolower( (string) $node->getAttribute( 'name' ) );
				$val  = (string) $node->getAttribute( 'content' );
				$result['social_tags']['twitter'][ $name ] = trim( $val );
			}
		}

		// 9. Extract Structured Data (JSON-LD).
		$json_nodes = $xpath->query( "//script[@type='application/ld+json']" );
		if ( $json_nodes && $json_nodes->length > 0 ) {
			$result['structured_data']['scripts_count'] = $json_nodes->length;
			foreach ( $json_nodes as $node ) {
				$raw_json = trim( $node->textContent );
				if ( empty( $raw_json ) ) {
					continue;
				}

				$decoded = json_decode( $raw_json, true );
				if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
					$result['structured_data']['syntax_errors'][] = array(
						'error'    => json_last_error_msg(),
						'snippet'  => mb_substr( $raw_json, 0, 100 ),
					);
				} else {
					$result['structured_data']['valid_json'][] = $decoded;

					// Extract schemas.
					$items = isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
					foreach ( $items as $item ) {
						if ( isset( $item['@type'] ) ) {
							$type = is_array( $item['@type'] ) ? implode( ',', $item['@type'] ) : (string) $item['@type'];
							$result['structured_data']['schemas'][] = array(
								'type' => $type,
								'data' => $item,
							);
						}
					}
				}
			}
		}

		// 10. Extract Main Content (evaluate separately from nav, sidebar, footer).
		$main_nodes = $xpath->query( '//main | //article | //*[@id="content"] | //*[@class="entry-content"]' );
		$main_text  = '';
		if ( $main_nodes && $main_nodes->length > 0 ) {
			foreach ( $main_nodes as $mn ) {
				$main_text .= ' ' . $mn->textContent;
			}
		} else {
			// Fallback: strip nav, header, footer, aside, script, style from body.
			$body_nodes = $xpath->query( '//body' );
			if ( $body_nodes && $body_nodes->length > 0 ) {
				$body_clone = $body_nodes->item( 0 )->cloneNode( true );
				$unwanted   = $xpath->query( './/nav | .//header | .//footer | .//aside | .//script | .//style', $body_clone );
				foreach ( $unwanted as $u ) {
					if ( $u->parentNode ) {
						$u->parentNode->removeChild( $u );
					}
				}
				$main_text = $body_clone->textContent;
			}
		}

		$cleaned_main = preg_replace( '/\s+/', ' ', trim( $main_text ) );
		$words        = ! empty( $cleaned_main ) ? preg_split( '/\s+/u', $cleaned_main ) : array();
		$result['main_content']['raw_text']   = mb_substr( $cleaned_main, 0, 300 );
		$result['main_content']['word_count'] = count( array_filter( $words ) );

		return $result;
	}
}
