<?php
/**
 * Post lifecycle watcher for Technical SEO Dashboard.
 *
 * Marks results stale or cleans up data when posts are modified,
 * trashed, or published.
 *
 * @package Crawlory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crawlory_Post_Watcher {

	/**
	 * Database instance.
	 *
	 * @var Crawlory_DB
	 */
	private $db;

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Crawlory_DB       $db Database layer.
	 * @param Crawlory_Settings $settings Settings.
	 */
	public function __construct( Crawlory_DB $db, Crawlory_Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	/**
	 * Register WordPress action hooks.
	 */
	public function init() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition_status' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ) );
	}

	/**
	 * Fired when a post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return;
		}

		// Ignore auto-saves, revisions, and bulk edits.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// Only monitor configured public post types.
		$post_types = (array) $this->settings->get( 'post_types', array( 'page', 'post', 'product' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		// If published, mark existing scan result as stale.
		if ( 'publish' === $post->post_status && empty( $post->post_password ) ) {
			$this->db->mark_post_stale( $post_id );
		}
	}

	/**
	 * Fired on status transition.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post Post object.
	 */
	public function on_transition_status( $new_status, $old_status, $post ) {
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return;
		}

		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// If unpublishing (e.g. from publish to draft/trash/private), remove from audit pages.
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->db->delete_post_page( $post->ID );
		}
	}

	/**
	 * Fired before post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete_post( $post_id ) {
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		if ( ! Crawlory_Migrator::is_ready() ) {
			return;
		}

		$this->db->delete_post_page( $post_id );
	}

	/**
	 * Fired when post is moved to trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_trash_post( $post_id ) {
		$this->db->delete_post_page( $post_id );
	}
}
