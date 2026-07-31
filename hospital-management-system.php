<?php
/**
 * Plugin Name: Hospital Management System
 * Plugin URI:  https://github.com/nduka4real/hospital-management-system/
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

define( 'HMS_VERSION', '1.0.0' );
define( 'HMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'HMS_URL', plugin_dir_url( __FILE__ ) );
define( 'HMS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Core includes
 */
require_once HMS_PATH . 'includes/class-hms-db.php';
require_once HMS_PATH . 'includes/class-hms-session.php';
require_once HMS_PATH . 'includes/class-hms-roles.php';
require_once HMS_PATH . 'includes/class-hms-activator.php';
require_once HMS_PATH . 'includes/class-hms-deactivator.php';
require_once HMS_PATH . 'includes/class-hms-departments.php';
require_once HMS_PATH . 'includes/class-hms-patients.php';
require_once HMS_PATH . 'includes/class-hms-doctors.php';
require_once HMS_PATH . 'includes/class-hms-appointments.php';
require_once HMS_PATH . 'includes/class-hms-auth.php';
require_once HMS_PATH . 'includes/class-hms-frontend-admin.php';
require_once HMS_PATH . 'includes/class-hms-shortcodes.php';
require_once HMS_PATH . 'includes/class-hms-assets.php';

register_activation_hook( __FILE__, array( 'HMS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HMS_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 */
function hms_run_plugin() {
	HMS_Assets::init();
	HMS_Auth::init();
	HMS_Patients::init();
	HMS_Doctors::init();
	HMS_Appointments::init();
	HMS_Departments::init();
	HMS_Frontend_Admin::init();
	HMS_Shortcodes::init();
}
add_action( 'plugins_loaded', 'hms_run_plugin' );

/**
 * Load text domain.
 */
function hms_load_textdomain() {
	load_plugin_textdomain( 'hms', false, dirname( HMS_BASENAME ) . '/languages' );
}
add_action( 'init', 'hms_load_textdomain' );
