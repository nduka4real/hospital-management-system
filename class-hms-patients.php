<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Patients {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_registration' ) );
	}

	/* ---------------- Registration ---------------- */

	public static function handle_registration() {
		if ( empty( $_POST['hms_action'] ) || 'register_patient' !== $_POST['hms_action'] ) {
			return;
		}
		if ( ! isset( $_POST['hms_register_nonce'] ) || ! wp_verify_nonce( $_POST['hms_register_nonce'], 'hms_register' ) ) {
			HMS_Session::set_notice( 'error', __( 'Security check failed, please try again.', 'hms' ) );
			return;
		}

		$first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
		$email      = sanitize_email( $_POST['email'] ?? '' );
		$phone      = sanitize_text_field( $_POST['phone'] ?? '' );
		$gender     = sanitize_text_field( $_POST['gender'] ?? '' );
		$dob        = sanitize_text_field( $_POST['dob'] ?? '' );
		$address    = sanitize_textarea_field( $_POST['address'] ?? '' );
		$blood      = sanitize_text_field( $_POST['blood_group'] ?? '' );
		$emergency  = sanitize_text_field( $_POST['emergency_contact'] ?? '' );
		$type       = ( isset( $_POST['patient_type'] ) && 'IPD' === $_POST['patient_type'] ) ? 'IPD' : 'OPD';
		$department_id = isset( $_POST['department_id'] ) ? (int) $_POST['department_id'] : 0;
		$password   = $_POST['password'] ?? '';

		if ( empty( $first_name ) || empty( $email ) || empty( $password ) ) {
			HMS_Session::set_notice( 'error', __( 'Please fill in your name, email and a password.', 'hms' ) );
			return;
		}

		if ( email_exists( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'An account with this email already exists. Please log in instead.', 'hms' ) );
			return;
		}

		$username = self::generate_username( $first_name, $last_name, $email );

		$user_id = wp_insert_user( array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
			'role'       => 'hms_patient',
		) );

		if ( is_wp_error( $user_id ) ) {
			HMS_Session::set_notice( 'error', $user_id->get_error_message() );
			return;
		}

		// Bed assignment for IPD patients, if a department + available bed exists.
		$bed_id = null;
		if ( 'IPD' === $type && $department_id ) {
			$beds = HMS_Departments::get_available_beds( $department_id );
			if ( ! empty( $beds ) ) {
				$bed_id = $beds[0]->id;
				HMS_Departments::set_bed_status( $bed_id, 'occupied' );
			}
		}

		$card_number = HMS_DB::generate_card_number();

		global $wpdb;
		$wpdb->insert( HMS_DB::patients_table(), array(
			'user_id'           => $user_id,
			'card_number'       => $card_number,
			'patient_type'      => $type,
			'department_id'     => $department_id ? $department_id : null,
			'bed_id'            => $bed_id,
			'gender'            => $gender,
			'dob'               => $dob ? $dob : null,
			'phone'             => $phone,
			'address'           => $address,
			'blood_group'       => $blood,
			'emergency_contact' => $emergency,
			'admission_date'    => ( 'IPD' === $type ) ? current_time( 'mysql', false ) : null,
			'status'            => 'active',
			'created_at'        => current_time( 'mysql' ),
		) );

		wp_new_user_notification( $user_id, null, 'user' );

		// Log the new patient straight in and send them to their dashboard.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		$redirect = HMS_Auth::get_dashboard_url();
		if ( isset( $_POST['hms_redirect_to'] ) ) {
			$redirect = esc_url_raw( $_POST['hms_redirect_to'] );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function generate_username( $first, $last, $email ) {
		$base = sanitize_user( strtolower( $first . '.' . $last ), true );
		if ( empty( $base ) ) {
			$base = sanitize_user( strtolower( current( explode( '@', $email ) ) ), true );
		}
		$username = $base;
		$i = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			$i++;
		}
		return $username;
	}

	/* ---------------- Data access ---------------- */

	public static function get_by_user_id( $user_id ) {
		global $wpdb;
		$table = HMS_DB::patients_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = HMS_DB::patients_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = HMS_DB::patients_table();
		$where = array( '1=1' );

		if ( ! empty( $args['patient_type'] ) ) {
			$where[] = $wpdb->prepare( 'patient_type = %s', $args['patient_type'] );
		}
		if ( ! empty( $args['department_id'] ) ) {
			$where[] = $wpdb->prepare( 'department_id = %d', $args['department_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( 'card_number LIKE %s', $like );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';
		return $wpdb->get_results( $sql );
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$table  = HMS_DB::patients_table();
		$fields = array();

		$allowed = array( 'patient_type', 'department_id', 'bed_id', 'gender', 'dob', 'phone', 'address', 'blood_group', 'emergency_contact', 'admission_date', 'discharge_date', 'status' );
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ];
			}
		}
		if ( empty( $fields ) ) {
			return false;
		}
		return $wpdb->update( $table, $fields, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$patient = self::get( $id );
		if ( $patient && $patient->bed_id ) {
			HMS_Departments::set_bed_status( $patient->bed_id, 'available' );
		}
		$table = HMS_DB::patients_table();
		return $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}
}
