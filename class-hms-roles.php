<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Roles {

	const CAP_MANAGE = 'manage_hospital';

	public static function add_roles() {

		add_role( 'hms_patient', __( 'Patient', 'hms' ), array(
			'read' => true,
		) );

		add_role( 'hms_doctor', __( 'Doctor', 'hms' ), array(
			'read'                  => true,
			'view_hms_appointments' => true,
		) );

		add_role( 'hms_hospital_admin', __( 'Hospital Admin', 'hms' ), array(
			'read'             => true,
			self::CAP_MANAGE   => true,
			'view_hms_appointments' => true,
		) );

		// Give site administrators full hospital management capability too.
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP_MANAGE ) ) {
			$admin->add_cap( self::CAP_MANAGE );
		}
	}

	public static function remove_roles() {
		remove_role( 'hms_patient' );
		remove_role( 'hms_doctor' );
		remove_role( 'hms_hospital_admin' );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP_MANAGE );
		}
	}

	public static function current_user_can_manage() {
		return is_user_logged_in() && current_user_can( self::CAP_MANAGE );
	}

	public static function current_user_is_patient() {
		$user = wp_get_current_user();
		return is_user_logged_in() && in_array( 'hms_patient', (array) $user->roles, true );
	}

	public static function current_user_is_doctor() {
		$user = wp_get_current_user();
		return is_user_logged_in() && in_array( 'hms_doctor', (array) $user->roles, true );
	}
}
