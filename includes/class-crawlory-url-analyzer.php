<?php
/**
 * URL and Slug Analyzer for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_URL_Analyzer {

	/**
	 * Analyze URL and slug for SEO rules:
	 * Rule 8: Non-Latin characters in URLs or slugs
	 * Rule 9: Emojis in URLs
	 * Rule 11: Spaces in URLs
	 * Rule 12: Uppercase characters in URLs
	 * Rule 13: URLs above 115 characters
	 *
	 * @param string      $url Absolute permalink.
	 * @param string|null $stored_slug Stored post_name / slug.
	 * @param int         $max_length_threshold Max URL length threshold (default 115).
	 * @return array Analysis findings.
	 */
	public static function analyze( $url, $stored_slug = null, $max_length_threshold = 115 ) {
		$result = array(
			'url_clean'             => '',
			'url_encoded'           => '',
			'url_decoded'           => '',
			'length_encoded'        => 0,
			'length_decoded'        => 0,
			'rule_8_non_latin'      => array( 'flagged' => false, 'evidence' => array() ),
			'rule_9_emoji'          => array( 'flagged' => false, 'evidence' => array() ),
			'rule_11_spaces'        => array( 'flagged' => false, 'evidence' => array() ),
			'rule_12_uppercase'     => array( 'flagged' => false, 'evidence' => array() ),
			'rule_13_length_excess' => array( 'flagged' => false, 'evidence' => array() ),
		);

		if ( empty( $url ) ) {
			return $result;
		}

		// 1. Strip fragments and scan-added parameters for length evaluation.
		$clean_url = self::strip_fragments_and_scan_params( $url );
		$result['url_clean'] = $clean_url;

		// 2. Parse URL components.
		$parsed = wp_parse_url( $clean_url );
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$query  = isset( $parsed['query'] ) ? $parsed['query'] : '';

		// 3. Encoded vs Decoded URL & Rule 13 (Length check).
		// Per specification: Evaluate URL length using the encoded absolute permalink.
		// Display decoded length as supplementary information.
		$encoded_url = $clean_url;
		$decoded_url = rawurldecode( $clean_url );

		$length_encoded = Crawlory_Normalizer::character_count( $encoded_url );
		$length_decoded = Crawlory_Normalizer::character_count( $decoded_url );

		$result['url_encoded']    = $encoded_url;
		$result['url_decoded']    = $decoded_url;
		$result['length_encoded'] = $length_encoded;
		$result['length_decoded'] = $length_decoded;

		if ( $length_encoded > $max_length_threshold ) {
			$result['rule_13_length_excess'] = array(
				'flagged'  => true,
				'evidence' => array(
					'encoded_length' => $length_encoded,
					'decoded_length' => $length_decoded,
					'threshold'      => $max_length_threshold,
					'url'            => $encoded_url,
				),
			);
		}

		// 4. Rule 11: Spaces in URLs (literal spaces and %20 in path or query).
		$space_evidence = array();
		if ( false !== strpos( $clean_url, ' ' ) ) {
			$space_evidence[] = array(
				'type'     => 'literal_space',
				'location' => ( false !== strpos( $path, ' ' ) ) ? 'path' : 'query',
			);
		}
		if ( false !== stripos( $clean_url, '%20' ) ) {
			$space_evidence[] = array(
				'type'     => 'percent_encoded_space (%20)',
				'location' => ( false !== stripos( $path, '%20' ) ) ? 'path' : 'query',
			);
		}
		if ( ! empty( $space_evidence ) ) {
			$result['rule_11_spaces'] = array(
				'flagged'  => true,
				'evidence' => $space_evidence,
			);
		}

		// 5. Decode path and query once for character checks.
		$decoded_path  = rawurldecode( $path );
		$decoded_query = rawurldecode( $query );

		// 6. Rule 12: Uppercase characters in URLs (decoded path or query).
		// Note: By evaluating the decoded path and query, uppercase hex in %-encoding
		// (e.g. %2F, %3A, %C3%A9) does not trigger uppercase flags unless the decoded
		// character itself is an uppercase letter.
		$uppercase_matches = array();
		if ( preg_match_all( '/\p{Lu}/u', $decoded_path, $matches ) ) {
			foreach ( $matches[0] as $char ) {
				$uppercase_matches[] = array(
					'character' => $char,
					'location'  => 'path',
				);
			}
		}
		if ( preg_match_all( '/\p{Lu}/u', $decoded_query, $matches ) ) {
			foreach ( $matches[0] as $char ) {
				$uppercase_matches[] = array(
					'character' => $char,
					'location'  => 'query',
				);
			}
		}

		if ( ! empty( $uppercase_matches ) ) {
			$unique_chars = array_unique( array_column( $uppercase_matches, 'character' ) );
			$result['rule_12_uppercase'] = array(
				'flagged'  => true,
				'evidence' => array(
					'detected_characters' => array_values( $unique_chars ),
					'occurrences'         => $uppercase_matches,
				),
			);
		}

		// 7. Rule 9: Emojis in URLs (decoded path or query).
		// Comprehensive emoji regex matching pictographs, emoticons, symbols, modifiers, ZWJ sequences.
		$emoji_pattern = '/[\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2B50}\x{200D}\x{FE0F}]/u';

		$emoji_matches = array();
		if ( preg_match_all( $emoji_pattern, $decoded_path, $m_path ) ) {
			foreach ( $m_path[0] as $em ) {
				if ( "\x{200D}" !== $em && "\x{FE0F}" !== $em ) {
					$emoji_matches[] = array( 'emoji' => $em, 'location' => 'path' );
				}
			}
		}
		if ( preg_match_all( $emoji_pattern, $decoded_query, $m_query ) ) {
			foreach ( $m_query[0] as $em ) {
				if ( "\x{200D}" !== $em && "\x{FE0F}" !== $em ) {
					$emoji_matches[] = array( 'emoji' => $em, 'location' => 'query' );
				}
			}
		}

		if ( ! empty( $emoji_matches ) ) {
			$unique_emojis = array_unique( array_column( $emoji_matches, 'emoji' ) );
			$result['rule_9_emoji'] = array(
				'flagged'  => true,
				'evidence' => array(
					'detected_emojis' => array_values( $unique_emojis ),
					'occurrences'     => $emoji_matches,
				),
			);
		}

		// 8. Rule 8: Non-Latin characters in URLs or slugs.
		// Specification:
		// "Detect non-Latin letters in the decoded URL path and stored slug. Show the affected characters and location."
		// "Treat non-Latin letters separately from punctuation, digits, accented Latin letters, and emojis. Do not flag every non-ASCII character as non-Latin."
		// How to identify:
		// A character is a non-Latin letter if:
		// - It matches \p{L} (Unicode letter)
		// - It does NOT match \p{Latin} (Latin script)
		// Accented Latin characters (e.g., é, ü, ñ, ø, å) match \p{Latin} and are NOT flagged.
		// Emojis, digits, and punctuation do NOT match \p{L} and are NOT flagged.
		$non_latin_findings = array();

		// Check decoded path.
		$path_letters = self::extract_non_latin_letters( $decoded_path );
		if ( ! empty( $path_letters ) ) {
			$non_latin_findings[] = array(
				'location'   => 'decoded_url_path',
				'characters' => $path_letters,
			);
		}

		// Check stored slug if provided.
		if ( ! empty( $stored_slug ) ) {
			$decoded_slug = rawurldecode( $stored_slug );
			$slug_letters = self::extract_non_latin_letters( $decoded_slug );
			if ( ! empty( $slug_letters ) ) {
				$non_latin_findings[] = array(
					'location'   => 'stored_slug',
					'characters' => $slug_letters,
				);
			}
		}

		if ( ! empty( $non_latin_findings ) ) {
			$all_chars = array();
			foreach ( $non_latin_findings as $item ) {
				$all_chars = array_merge( $all_chars, $item['characters'] );
			}
			$unique_chars = array_values( array_unique( $all_chars ) );

			// Identify script classifications across all detected characters.
			$detected_scripts = array();
			foreach ( $unique_chars as $char ) {
				$script = self::identify_unicode_script( $char );
				if ( ! in_array( $script, $detected_scripts, true ) ) {
					$detected_scripts[] = $script;
				}
			}

			$result['rule_8_non_latin'] = array(
				'flagged'  => true,
				'evidence' => array(
					'affected_characters' => $unique_chars,
					'detected_scripts'    => $detected_scripts,
					'locations'           => $non_latin_findings,
				),
			);
		}

		$result['has_non_latin'] = ! empty( $result['rule_8_non_latin']['flagged'] );

		return $result;
	}

	/**
	 * Extract non-Latin letters from a string.
	 * Identifies all Unicode letters (\p{L}) that do not belong to the Latin script (\p{Latin}).
	 * Accented Latin letters (é, ü, ñ, ø, å, ç) are Latin and NOT returned.
	 * Covers ALL non-Latin alphabets: Georgian, Cyrillic, Greek, Arabic, Hebrew,
	 * Han/Chinese, Japanese, Korean, Devanagari, Thai, Armenian, etc.
	 *
	 * @param string $string Input string.
	 * @return array
	 */
	public static function extract_non_latin_letters( $string ) {
		if ( empty( $string ) ) {
			return array();
		}

		$non_latin = array();

		// Match all Unicode letters.
		if ( preg_match_all( '/\p{L}/u', $string, $matches ) ) {
			foreach ( $matches[0] as $letter ) {
				// Check if this letter belongs to the Latin script.
				// In PCRE, \p{Latin} matches Latin script letters (including all accents).
				if ( ! preg_match( '/\p{Latin}/u', $letter ) ) {
					$non_latin[] = $letter;
				}
			}
		}

		return array_values( array_unique( $non_latin ) );
	}

	/**
	 * Identify the Unicode script of a given character or string.
	 *
	 * @param string $char Unicode character.
	 * @return string Script name.
	 */
	public static function identify_unicode_script( $char ) {
		$scripts = array(
			'Georgian'                 => '/\p{Georgian}/u',
			'Cyrillic'                 => '/\p{Cyrillic}/u',
			'Greek'                    => '/\p{Greek}/u',
			'Arabic'                   => '/\p{Arabic}/u',
			'Hebrew'                   => '/\p{Hebrew}/u',
			'Han (Chinese)'            => '/\p{Han}/u',
			'Japanese'                 => '/[\p{Hiragana}\p{Katakana}]/u',
			'Korean'                   => '/\p{Hangul}/u',
			'Devanagari (Hindi)'       => '/\p{Devanagari}/u',
			'Thai'                     => '/\p{Thai}/u',
			'Armenian'                 => '/\p{Armenian}/u',
			'Bengali'                  => '/\p{Bengali}/u',
			'Tamil'                    => '/\p{Tamil}/u',
		);

		foreach ( $scripts as $name => $pattern ) {
			if ( preg_match( $pattern, $char ) ) {
				return $name;
			}
		}

		return 'Other Non-Latin';
	}

	/**
	 * Strip fragment (#...) and scan-added parameters from URL.
	 *
	 * @param string $url Raw URL.
	 * @return string Clean URL.
	 */
	public static function strip_fragments_and_scan_params( $url ) {
		// Strip fragment.
		$fragment_pos = strpos( $url, '#' );
		if ( false !== $fragment_pos ) {
			$url = substr( $url, 0, $fragment_pos );
		}

		// Remove scan parameters like crawlory_scan, preview, etc.
		$parsed = wp_parse_url( $url );
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_args );
			unset( $query_args['crawlory_scan'], $query_args['crawlory_batch'], $query_args['preview_id'], $query_args['preview_nonce'] );

			$new_query = http_build_query( $query_args );
			$scheme    = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
			$host      = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$port      = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$path      = isset( $parsed['path'] ) ? $parsed['path'] : '';

			$url = $scheme . $host . $port . $path . ( ! empty( $new_query ) ? '?' . $new_query : '' );
		}

		return $url;
	}
}
