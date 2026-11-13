<?php
/**
 * Image helper and WordPress attachment mapper for Technical SEO Dashboard.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Image_Helper {

	/**
	 * Map an image URL to a WordPress media library attachment ID and edit link.
	 *
	 * @param string $image_url Full URL of the image.
	 * @return array Array containing attachment_id and edit_url (or nulls).
	 */
	public static function get_attachment_info( $image_url ) {
		$info = array(
			'attachment_id' => 0,
			'edit_url'      => null,
		);

		if ( empty( $image_url ) || ! function_exists( 'attachment_url_to_postid' ) ) {
			return $info;
		}

		// 1. Try direct URL lookup.
		$attachment_id = attachment_url_to_postid( $image_url );

		// 2. If not found and image has thumbnail dimensions (e.g., photo-300x200.jpg), strip dimensions.
		if ( ! $attachment_id ) {
			$stripped_url = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/i', '', $image_url );
			if ( $stripped_url && $stripped_url !== $image_url ) {
				$attachment_id = attachment_url_to_postid( $stripped_url );
			}
		}

		// 3. If found, generate admin edit URL.
		if ( $attachment_id > 0 ) {
			$info['attachment_id'] = $attachment_id;
			$info['edit_url']      = admin_url( 'post.php?post=' . $attachment_id . '&action=edit' );
		}

		return $info;
	}
}
