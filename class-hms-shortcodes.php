<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Shortcodes {

	public static function init() {
		add_shortcode( 'hms_patient_register', array( __CLASS__, 'registration' ) );
		add_shortcode( 'hms_login', array( __CLASS__, 'login' ) );
		add_shortcode( 'hms_patient_dashboard', array( __CLASS__, 'dashboard' ) );
		add_shortcode( 'hms_book_appointment', array( __CLASS__, 'book_appointment' ) );
		add_shortcode( 'hms_admin_panel', array( __CLASS__, 'admin_panel' ) );
		add_shortcode( 'hms_department_list', array( __CLASS__, 'department_list' ) );
	}

	private static function render( $template, $vars = array() ) {
		$file = HMS_PATH . 'templates/' . $template . '.php';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		extract( $vars ); // phpcs:ignore -- controlled internal template variables.
		ob_start();
		include $file;
		return ob_get_clean();
	}

	public static function registration( $atts ) {
		if ( is_user_logged_in() ) {
			return self::render( 'already-logged-in' );
		}
		return self::render( 'patient-registration-form' );
	}

	public static function login( $atts ) {
		if ( is_user_logged_in() ) {
			return self::render( 'already-logged-in' );
		}
		return self::render( 'login-form' );
	}

	public static function dashboard( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::render( 'please-login' );
		}
		return self::render( 'patient-dashboard' );
	}

	public static function book_appointment( $atts ) {
		if ( ! is_user_logged_in() || ! HMS_Roles::current_user_is_patient() ) {
			return self::render( 'please-register-to-book' );
		}
		return self::render( 'appointment-booking-form' );
	}

	public static function admin_panel( $atts ) {
		if ( ! is_user_logged_in() ) {
			return self::render( 'please-login' );
		}
		if ( ! HMS_Roles::current_user_can_manage() ) {
			return '<div class="hms-notice hms-notice-error">' . esc_html__( 'You do not have permission to access the hospital admin panel.', 'hms' ) . '</div>';
		}
		return self::render( 'admin-panel' );
	}

	public static function department_list( $atts ) {
		return self::render( 'department-list' );
	}
}
