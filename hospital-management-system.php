<?php
/**
 * Plugin Name: Hospital Management System
 * Plugin URI:  https://example.com/hospital-management-system
 * Description: A complete front-end hospital management system for WordPress. Register patients as WP users, manage departments/wards, beds, doctors and appointments, and administer the whole hospital from the front end using shortcodes - no wp-admin required.
 * Version:     1.0.0
 * Author:      Hospital Management System
 * Text Domain: hms
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ============================================================
// 1. Environment Checks
// ============================================================
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>'
			. sprintf( esc_html__( 'Hospital Management System requires PHP 7.4 or higher. You are running PHP %s. Please upgrade.', 'hms' ), PHP_VERSION )
			. '</p></div>';
	} );
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '5.6', '<' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>'
			. sprintf( esc_html__( 'Hospital Management System requires WordPress 5.6 or higher. You are running %s. Please upgrade.', 'hms' ), get_bloginfo( 'version' ) )
			. '</p></div>';
	} );
	return;
}

// ============================================================
// 2. Constants
// ============================================================
define( 'HMS_VERSION', '1.0.0' );
define( 'HMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'HMS_URL', plugin_dir_url( __FILE__ ) );
define( 'HMS_BASENAME', plugin_basename( __FILE__ ) );
define( 'HMS_MIN_PHP', '7.4' );
define( 'HMS_MIN_WP', '5.6' );

// ============================================================
// 3. Core Includes
// ============================================================
$includes = array(
	'class-hms-db',
	'class-hms-session',
	'class-hms-roles',
	'class-hms-activator',
	'class-hms-deactivator',
	'class-hms-departments',
	'class-hms-patients',
	'class-hms-doctors',
	'class-hms-appointments',
	'class-hms-auth',
	'class-hms-frontend-admin',
	'class-hms-shortcodes',
	'class-hms-assets',
);

foreach ( $includes as $file ) {
	require_once HMS_PATH . "includes/{$file}.php";
}

// ============================================================
// 4. Activation / Deactivation Hooks
// ============================================================
register_activation_hook( __FILE__, array( 'HMS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HMS_Deactivator', 'deactivate' ) );

// ============================================================
// 5. Main Plugin Class (encapsulated boot)
// ============================================================
final class HMS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var HMS_Plugin
	 */
	private static $instance = null;

	/**
	 * Flag to prevent double initialization.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Get the single instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {
		// Hook into WordPress
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
		add_action( 'init', array( $this, 'load_textdomain' ), 5 );
	}

	/**
	 * Initialize all plugin components.
	 */
	public function init() {
		// Prevent double init
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		// Check if all required classes exist
		if ( ! class_exists( 'HMS_Assets' ) ||
			 ! class_exists( 'HMS_Auth' ) ||
			 ! class_exists( 'HMS_Patients' ) ||
			 ! class_exists( 'HMS_Doctors' ) ||
			 ! class_exists( 'HMS_Appointments' ) ||
			 ! class_exists( 'HMS_Departments' ) ||
			 ! class_exists( 'HMS_Frontend_Admin' ) ||
			 ! class_exists( 'HMS_Shortcodes' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_class_notice' ) );
			return;
		}

		// Boot components
		HMS_Assets::init();
		HMS_Auth::init();
		HMS_Patients::init();
		HMS_Doctors::init();
		HMS_Appointments::init();
		HMS_Departments::init();
		HMS_Frontend_Admin::init();
		HMS_Shortcodes::init();

		// Allow other plugins to hook after init
		do_action( 'hms_plugin_loaded' );
	}

	/**
	 * Load translation files.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'hms', false, dirname( HMS_BASENAME ) . '/languages' );
	}

	/**
	 * Admin notice for missing core classes.
	 */
	public function missing_class_notice() {
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Hospital Management System could not initialize because one or more core classes are missing. Please reinstall the plugin.', 'hms' )
			. '</p></div>';
	}
}

// ============================================================
// 6. Bootstrap the Plugin
// ============================================================
HMS_Plugin::get_instance();