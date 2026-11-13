<?php
/**
 * Plugin Name:       Crawlory - Technical SEO Dashboard
 * Plugin URI:        https://crawlory.app
 * Description:       Technical SEO crawler and audit dashboard for WordPress by Crawlory. Scans live server-rendered HTML across 19 core checks and 14 expanded priority categories, identifies issues across posts, pages, WooCommerce products, and custom post types, and provides actionable remediation workflows.
 * Version:           1.3.11
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Irakli Antidze
 * Author URI:        https://irakli.life
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       crawlory-technical-seo-dashboard
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core constants.
define( 'CRAWLORY_VERSION', '1.3.11' );
define( 'CRAWLORY_DB_VERSION', '1.3.11' );
define( 'CRAWLORY_ADMIN_MENU_SLUG', 'crawlory' );
define( 'CRAWLORY_PLUGIN_FILE', __FILE__ );
define( 'CRAWLORY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRAWLORY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CRAWLORY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for Crawlory classes.
 *
 * @param string $class_name Class name to load.
 */
spl_autoload_register( function ( $class_name ) {
	// Handle Crawlory_ prefix (9 chars).
	if ( 0 === strpos( $class_name, 'Crawlory_' ) ) {
		$class_slug    = strtolower( str_replace( '_', '-', substr( $class_name, 9 ) ) );
		$includes_file = CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-' . $class_slug . '.php';
		$admin_file    = CRAWLORY_PLUGIN_DIR . 'admin/class-crawlory-' . $class_slug . '.php';

		if ( file_exists( $includes_file ) ) {
			require_once $includes_file;
		} elseif ( file_exists( $admin_file ) ) {
			require_once $admin_file;
		}
	}
} );

/**
 * Activation callback.
 */
function crawlory_activate_plugin() {
	require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-activator.php';
	Crawlory_Activator::activate();
}
register_activation_hook( __FILE__, 'crawlory_activate_plugin' );

/**
 * Deactivation callback.
 */
function crawlory_deactivate_plugin() {
	require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-deactivator.php';
	Crawlory_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'crawlory_deactivate_plugin' );

/**
 * Plugin core class singleton.
 */
final class Crawlory {

	/**
	 * Single instance of the plugin.
	 *
	 * @var Crawlory|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var Crawlory_Settings
	 */
	public $settings;

	/**
	 * Database instance.
	 *
	 * @var Crawlory_DB
	 */
	public $db;

	/**
	 * Scanner instance.
	 *
	 * @var Crawlory_Scanner
	 */
	public $scanner;

	/**
	 * Admin instance.
	 *
	 * @var Crawlory_Admin
	 */
	public $admin;

	/**
	 * Cron instance.
	 *
	 * @var Crawlory_Cron
	 */
	public $cron;

	/**
	 * Post watcher instance.
	 *
	 * @var Crawlory_Post_Watcher
	 */
	public $post_watcher;

	/**
	 * Per-page meta box instance.
	 *
	 * @var Crawlory_Post_Meta_Box
	 */
	public $meta_box;

	/**
	 * Main instance accessor.
	 *
	 * @return Crawlory
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_components();
		$this->register_hooks();
	}

	/**
	 * Instantiate modules.
	 */
	private function init_components() {
		$this->db           = new Crawlory_DB();
		$this->settings     = new Crawlory_Settings();
		$this->scanner      = new Crawlory_Scanner( $this->db, $this->settings );
		$this->cron         = new Crawlory_Cron( $this->scanner, $this->settings );
		$this->post_watcher = new Crawlory_Post_Watcher( $this->db, $this->settings );
		$this->post_watcher->init();

		if ( is_admin() ) {
			$this->admin    = new Crawlory_Admin( $this->db, $this->settings, $this->scanner );
			$this->meta_box = new Crawlory_Post_Meta_Box( $this->db, $this->settings, $this->scanner );
			$this->meta_box->init();
		}
	}

	/**
	 * Register general hooks.
	 */
	private function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'admin_post_crawlory_retry_migration', array( $this, 'handle_retry_migration' ) );
	}

	/**
	 * Check schema on plugins loaded if needed.
	 */
	public function on_plugins_loaded() {
		// Run safe idempotent migration and schema updates through the single migration coordinator.
		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		Crawlory_Migrator::migrate();

		// Display admin warning if migration encountered an issue.
		if ( is_admin() && get_option( 'crawlory_migration_error' ) ) {
			add_action( 'admin_notices', array( $this, 'render_migration_notice' ) );
		}
	}

	/**
	 * Handle admin action to retry database migration cleanly.
	 */
	public function handle_retry_migration() {
		check_admin_referer( 'crawlory_retry_migration' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'crawlory-technical-seo-dashboard' ) );
		}

		require_once CRAWLORY_PLUGIN_DIR . 'includes/class-crawlory-migrator.php';
		delete_option( 'crawlory_migration_error' );
		Crawlory_Migrator::migrate();

		wp_safe_redirect( add_query_arg( 'page', CRAWLORY_ADMIN_MENU_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Display warning notice if migration encountered an error.
	 */
	public function render_migration_notice() {
		$error = get_option( 'crawlory_migration_error' );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$retry_url = wp_nonce_url( admin_url( 'admin-post.php?action=crawlory_retry_migration' ), 'crawlory_retry_migration' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Crawlory Migration Notice:', 'crawlory-technical-seo-dashboard' ); ?></strong>
				<?php echo esc_html( $error ); ?>
				<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small" style="margin-left: 10px;"><?php esc_html_e( 'Retry Migration', 'crawlory-technical-seo-dashboard' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Init hook.
	 */
	public function on_init() {
		// Initialize cron schedules and handlers.
		$this->cron->init();
		// Initialize post watcher.
		$this->post_watcher->init();
	}
}

/**
 * Returns the main Crawlory plugin instance.
 *
 * @return Crawlory
 */
function crawlory() {
	return Crawlory::get_instance();
}

// Kick off the plugin.
crawlory();
