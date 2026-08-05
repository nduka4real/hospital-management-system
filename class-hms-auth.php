<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Auth {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_login' ) );
		add_action( 'init', array( __CLASS__, 'handle_logout' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function handle_login() {
		if ( empty( $_POST['hms_action'] ) || 'hms_login' !== $_POST['hms_action'] ) {
			return;
		}
		if ( ! isset( $_POST['hms_login_nonce'] ) || ! wp_verify_nonce( $_POST['hms_login_nonce'], 'hms_login' ) ) {
			HMS_Session::set_notice( 'error', __( 'Security check failed, please try again.', 'hms' ) );
			return;
		}

		$creds = array(
			'user_login'    => sanitize_user( $_POST['username'] ?? '' ),
			'user_password' => $_POST['password'] ?? '',
			'remember'      => true,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			HMS_Session::set_notice( 'error', __( 'Incorrect username or password.', 'hms' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url() );
			exit;
		}

		wp_set_current_user( $user->ID );
		$redirect = self::redirect_for_user( $user );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_logout() {
		if ( isset( $_GET['hms_logout'] ) && is_user_logged_in() ) {
			wp_logout();
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_a( $user, 'WP_User' ) ) {
			return self::redirect_for_user( $user );
		}
		return $redirect_to;
	}

	private static function redirect_for_user( $user ) {
		$roles = (array) $user->roles;

		if ( in_array( 'hms_hospital_admin', $roles, true ) || user_can( $user, HMS_Roles::CAP_MANAGE ) ) {
			return self::get_admin_panel_url();
		}
		if ( in_array( 'hms_doctor', $roles, true ) ) {
			return self::get_dashboard_url();
		}
		if ( in_array( 'hms_patient', $roles, true ) ) {
			return self::get_dashboard_url();
		}
		return admin_url();
	}

	public static function get_dashboard_url() {
		$id = get_option( 'hms_dashboard_page_id' );
		return $id ? get_permalink( $id ) : home_url();
	}

	public static function get_login_url() {
		$id = get_option( 'hms_login_page_id' );
		return $id ? get_permalink( $id ) : wp_login_url();
	}

	public static function get_register_url() {
		$id = get_option( 'hms_register_page_id' );
		return $id ? get_permalink( $id ) : home_url();
	}

	public static function get_appointment_url() {
		$id = get_option( 'hms_appointment_page_id' );
		return $id ? get_permalink( $id ) : home_url();
	}

	public static function get_admin_panel_url() {
		$id = get_option( 'hms_admin_page_id' );
		return $id ? get_permalink( $id ) : home_url();
	}

	public static function get_logout_url() {
		return add_query_arg( 'hms_logout', '1', home_url( '/' ) );
	}
}
