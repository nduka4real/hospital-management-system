<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stores one-time notices (success/error) in a PHP session-free way using
 * a transient keyed to the visitor, so messages survive the redirect that
 * follows a POST-Redirect-GET form submission.
 */
class HMS_Session {

	private static function key() {
		if ( is_user_logged_in() ) {
			return 'hms_notice_' . get_current_user_id();
		}
		if ( empty( $_COOKIE['hms_guest_id'] ) ) {
			$guest_id = wp_generate_password( 12, false );
			setcookie( 'hms_guest_id', $guest_id, time() + HOUR_IN_SECONDS, COOKIEPATH ?: '/' );
			$_COOKIE['hms_guest_id'] = $guest_id;
		}
		return 'hms_notice_' . sanitize_key( $_COOKIE['hms_guest_id'] );
	}

	public static function set_notice( $type, $message ) {
		set_transient( self::key(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	public static function get_notice() {
		$notice = get_transient( self::key() );
		if ( $notice ) {
			delete_transient( self::key() );
		}
		return $notice;
	}
}
