<?php
/**
 * Unicode and HTML text normalizer for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Normalizer {

	/**
	 * Normalize text according to SEO specification:
	 * 1. Decode HTML entities (e.g., &amp;, &quot;, &#39;, &nbsp;).
	 * 2. Replace non-breaking spaces with standard spaces.
	 * 3. Normalize all consecutive whitespace characters to a single space.
	 * 4. Trim leading and trailing whitespace.
	 *
	 * @param string|null $text Raw input text.
	 * @return string Normalized text.
	 */
	public static function normalize_text( $text ) {
		if ( null === $text || '' === $text ) {
			return '';
		}

		// Decode all HTML entities to UTF-8 characters.
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Convert non-breaking space (U+00A0) and zero-width spaces to regular space or clean.
		$decoded = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x8B" ), array( ' ', ' ', '' ), $decoded );

		// Collapse any sequence of whitespace into a single ASCII space.
		$collapsed = preg_replace( '/\s+/u', ' ', $decoded );

		if ( null === $collapsed ) {
			// Fallback in case of invalid UTF-8 sequence.
			$collapsed = preg_replace( '/\s+/', ' ', $decoded );
		}

		return trim( (string) $collapsed );
	}

	/**
	 * Count characters in a string in a Unicode-aware manner.
	 * Returns grapheme cluster count if available, otherwise mb_strlen UTF-8.
	 *
	 * @param string $text Text to measure.
	 * @return int Number of characters.
	 */
	public static function character_count( $text ) {
		if ( '' === $text ) {
			return 0;
		}

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		if ( function_exists( 'iconv_strlen' ) ) {
			$len = @iconv_strlen( $text, 'UTF-8' );
			if ( false !== $len ) {
				return $len;
			}
		}

		$chars = preg_match_all( '/./us', $text );
		if ( false !== $chars ) {
			return $chars;
		}

		return strlen( $text );
	}

	/**
	 * Prepare string for duplicate comparison and exact matching:
	 * Normalized + Unicode case-folded (lowercase).
	 * Does NOT strip punctuation or words.
	 *
	 * @param string|null $text Raw text.
	 * @return string Comparison-ready string.
	 */
	public static function normalize_for_comparison( $text ) {
		$normalized = self::normalize_text( $text );
		if ( '' === $normalized ) {
			return '';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $normalized, 'UTF-8' );
		}

		return strtolower( $normalized );
	}

	/**
	 * Checks if two strings are an exact normalized match.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 * @return bool True if normalized representations match.
	 */
	public static function are_normalized_equal( $str1, $str2 ) {
		$norm1 = self::normalize_for_comparison( $str1 );
		$norm2 = self::normalize_for_comparison( $str2 );

		if ( '' === $norm1 || '' === $norm2 ) {
			return false;
		}

		return $norm1 === $norm2;
	}

	/**
	 * Generate a consistent 32-character hash for duplicate group indexing.
	 *
	 * @param string $comparison_string Case-folded normalized string.
	 * @return string MD5 hash.
	 */
	public static function get_comparison_hash( $comparison_string ) {
		return md5( $comparison_string );
	}
}
