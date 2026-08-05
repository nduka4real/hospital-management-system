<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles all form submissions from the [hms_admin_panel] shortcode
 * and public patient self?registration.
 */
class HMS_Frontend_Admin {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'route' ) );
	}

	/**
	 * Route POST requests by hms_action.
	 */
	public static function route() {
		if ( empty( $_POST['hms_action'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['hms_action'] );
		$allowed = array(
			'create_department', 'update_department', 'delete_department',
			'create_bed', 'delete_bed', 'set_bed_status',
			'create_doctor', 'update_doctor', 'delete_doctor',
			'create_nurse', 'update_nurse', 'delete_nurse',
			'create_pharmacist', 'update_pharmacist', 'delete_pharmacist',
			'create_laboratory', 'update_laboratory', 'delete_laboratory',
			'create_patient', 'update_patient', 'delete_patient',
			'delete_appointment', 'update_appointment',
			'assign_doctor', 'assign_nurse',
			'public_register_patient',
		);

		if ( ! in_array( $action, $allowed, true ) ) {
			return;
		}

		// Public registration: separate nonce, no admin capability.
		if ( $action === 'public_register_patient' ) {
			if ( ! isset( $_POST['hms_register_nonce'] ) || ! wp_verify_nonce( $_POST['hms_register_nonce'], 'hms_register' ) ) {
				HMS_Session::set_notice( 'error', __( 'Security check failed.', 'hms' ) );
				self::redirect_back();
			}
		} else {
			// Admin actions: require manage capability and admin nonce.
			if ( ! HMS_Roles::current_user_can_manage() ) {
				HMS_Session::set_notice( 'error', __( 'Permission denied.', 'hms' ) );
				self::redirect_back();
			}
			if ( ! isset( $_POST['hms_admin_nonce'] ) || ! wp_verify_nonce( $_POST['hms_admin_nonce'], 'hms_admin_action' ) ) {
				HMS_Session::set_notice( 'error', __( 'Security check failed.', 'hms' ) );
				self::redirect_back();
			}
		}

		// Call the action method.
		$method = 'action_' . $action;
		if ( method_exists( __CLASS__, $method ) ) {
			self::$method();
		}
		self::redirect_back();
	}

	private static function redirect_back() {
		$url = wp_get_referer() ? wp_get_referer() : HMS_Auth::get_admin_panel_url();
		wp_safe_redirect( $url );
		exit;
	}

	/* ====== Helpers ====== */

	/** Generate a unique patient_id (e.g., PAT-0001). */
	private static function generate_patient_id() {
		$option = 'hms_patient_id_counter';
		$counter = (int) get_option( $option, 0 );
		$counter++;
		update_option( $option, $counter );
		return 'PAT-' . str_pad( $counter, 4, '0', STR_PAD_LEFT );
	}

	/** Generate a unique username. */
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

	/** Check if a record exists in a table. */
	private static function record_exists( $table, $id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $id ) );
	}

	/* ====== Public Patient Registration ====== */

	private static function action_public_register_patient() {
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

		if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $dob ) ) {
			HMS_Session::set_notice( 'error', __( 'First name, last name, email, and date of birth are required.', 'hms' ) );
			return;
		}
		if ( email_exists( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'An account with this email already exists.', 'hms' ) );
			return;
		}

		$username = self::generate_username( $first_name, $last_name, $email );
		if ( empty( $password ) ) {
			$password = wp_generate_password( 12 );
		}
		$user_id = wp_insert_user( array(
			'user_login'    => $username,
			'user_email'    => $email,
			'user_pass'     => $password,
			'first_name'    => $first_name,
			'last_name'     => $last_name,
			'display_name'  => trim( $first_name . ' ' . $last_name ),
			'role'          => 'hms_patient',
		) );
		if ( is_wp_error( $user_id ) ) {
			HMS_Session::set_notice( 'error', $user_id->get_error_message() );
			return;
		}

		$patient_id   = self::generate_patient_id();
		$card_number  = HMS_DB::generate_card_number();
		if ( empty( $card_number ) ) {
			wp_delete_user( $user_id );
			HMS_Session::set_notice( 'error', __( 'Failed to generate card number.', 'hms' ) );
			return;
		}

		$bed_id = null;
		if ( 'IPD' === $type && $department_id ) {
			$beds = HMS_Departments::get_available_beds( $department_id );
			if ( ! empty( $beds ) ) {
				$bed_id = $beds[0]->id;
				HMS_Departments::set_bed_status( $bed_id, 'occupied' );
			}
		}

		global $wpdb;
		$result = $wpdb->insert( HMS_DB::patients_table(), array(
			'patient_id'        => $patient_id,
			'user_id'           => $user_id,
			'card_number'       => $card_number,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email,
			'phone'             => $phone,
			'gender'            => $gender,
			'date_of_birth'     => $dob,
			'address'           => $address,
			'blood_group'       => $blood,
			'emergency_contact' => $emergency,
			'patient_type'      => $type,
			'department_id'     => $department_id ? $department_id : null,
			'bed_id'            => $bed_id,
			'admission_date'    => ( 'IPD' === $type ) ? current_time( 'Y-m-d' ) : null,
			'status'            => 'active',
			'created_at'        => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create patient record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Patient Insert Error: ' . $wpdb->last_error );
			}
			wp_delete_user( $user_id );
			HMS_Session::set_notice( 'error', $error );
			return;
		}

		if ( empty( $_POST['password'] ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}
		HMS_Session::set_notice( 'success', __( 'Registration successful! You can now log in.', 'hms' ) );
	}

	/* ====== Departments ====== */

	private static function action_create_department() {
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		if ( empty( $name ) ) {
			HMS_Session::set_notice( 'error', __( 'Department name is required.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::create(
			$name,
			sanitize_textarea_field( $_POST['description'] ?? '' ),
			sanitize_key( $_POST['department_type'] ?? 'department' )
		);
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Department created.', 'hms' ) );
		}
	}

	private static function action_update_department() {
		$id = (int) ( $_POST['department_id'] ?? 0 );
		if ( ! $id || ! HMS_Departments::get( $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid department.', 'hms' ) );
			return;
		}
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		if ( empty( $name ) ) {
			HMS_Session::set_notice( 'error', __( 'Department name is required.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::update(
			$id,
			$name,
			sanitize_textarea_field( $_POST['description'] ?? '' ),
			sanitize_key( $_POST['department_type'] ?? 'department' )
		);
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Department updated.', 'hms' ) );
		}
	}

	private static function action_delete_department() {
		$id = (int) ( $_POST['department_id'] ?? 0 );
		if ( ! $id || ! HMS_Departments::get( $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid department.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::delete( $id );
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Department deleted.', 'hms' ) );
		}
	}

	/* ====== Beds ====== */

	private static function action_create_bed() {
		$dept = (int) ( $_POST['department_id'] ?? 0 );
		$num  = sanitize_text_field( $_POST['bed_number'] ?? '' );
		if ( ! $dept || empty( $num ) ) {
			HMS_Session::set_notice( 'error', __( 'Department and bed number are required.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::create_bed( $dept, $num );
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Bed added.', 'hms' ) );
		}
	}

	private static function action_delete_bed() {
		$id = (int) ( $_POST['bed_id'] ?? 0 );
		if ( ! $id ) {
			HMS_Session::set_notice( 'error', __( 'Invalid bed.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::delete_bed( $id );
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Bed removed.', 'hms' ) );
		}
	}

	private static function action_set_bed_status() {
		$id = (int) ( $_POST['bed_id'] ?? 0 );
		$status = sanitize_key( $_POST['status'] ?? 'available' );
		if ( ! $id ) {
			HMS_Session::set_notice( 'error', __( 'Invalid bed.', 'hms' ) );
			return;
		}
		$result = HMS_Departments::set_bed_status( $id, $status );
		if ( is_wp_error( $result ) ) {
			HMS_Session::set_notice( 'error', $result->get_error_message() );
		} else {
			HMS_Session::set_notice( 'success', __( 'Bed status updated.', 'hms' ) );
		}
	}

	/* ====== Doctors (direct $wpdb) ====== */

	private static function action_create_doctor() {
		$email = sanitize_email( $_POST['email'] ?? '' );
		$name  = sanitize_text_field( $_POST['name'] ?? '' );
		$department_id = (int) ( $_POST['department_id'] ?? 0 );
		$specialization = sanitize_text_field( $_POST['specialization'] ?? '' );
		$phone = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( empty( $name ) || empty( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Name and email are required.', 'hms' ) );
			return;
		}
		if ( ! is_email( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid email address.', 'hms' ) );
			return;
		}

		global $wpdb;
		$table = HMS_DB::doctors_table();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d", $user->ID ) );
			if ( $exists ) {
				HMS_Session::set_notice( 'error', __( 'A doctor record already exists for this user.', 'hms' ) );
				return;
			}
			$user_id = $user->ID;
			if ( ! in_array( 'hms_doctor', (array) $user->roles, true ) ) {
				$user->add_role( 'hms_doctor' );
			}
		} else {
			$password = wp_generate_password( 12 );
			$username = self::generate_username( $name, '', $email );
			$user_id = wp_insert_user( array(
				'user_login'    => $username,
				'user_email'    => $email,
				'user_pass'     => $password,
				'display_name'  => $name,
				'role'          => 'hms_doctor',
			) );
			if ( is_wp_error( $user_id ) ) {
				HMS_Session::set_notice( 'error', $user_id->get_error_message() );
				return;
			}
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$result = $wpdb->insert( $table, array(
			'user_id'        => $user_id,
			'department_id'  => $department_id ? $department_id : null,
			'specialization' => $specialization,
			'phone'          => $phone,
			'created_at'     => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create doctor record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Doctor Insert Error: ' . $wpdb->last_error );
			}
			HMS_Session::set_notice( 'error', $error );
			return;
		}
		HMS_Session::set_notice( 'success', __( 'Doctor added.', 'hms' ) );
	}

	private static function action_update_doctor() {
		$id = (int) ( $_POST['doctor_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::doctors_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid doctor.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::doctors_table(),
			array(
				'department_id'  => (int) ( $_POST['department_id'] ?? 0 ) ?: null,
				'specialization' => sanitize_text_field( $_POST['specialization'] ?? '' ),
				'phone'          => sanitize_text_field( $_POST['phone'] ?? '' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update doctor.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Doctor updated.', 'hms' ) );
		}
	}

	private static function action_delete_doctor() {
		$id = (int) ( $_POST['doctor_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::doctors_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid doctor.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::doctors_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete doctor.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Doctor removed.', 'hms' ) );
		}
	}

	/* ====== Nurses (direct $wpdb) ====== */

	private static function action_create_nurse() {
		$email = sanitize_email( $_POST['email'] ?? '' );
		$name  = sanitize_text_field( $_POST['name'] ?? '' );
		$department_id = (int) ( $_POST['department_id'] ?? 0 );
		$specialization = sanitize_text_field( $_POST['specialization'] ?? '' );
		$phone = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( empty( $name ) || empty( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Name and email are required.', 'hms' ) );
			return;
		}
		if ( ! is_email( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid email address.', 'hms' ) );
			return;
		}

		global $wpdb;
		$table = HMS_DB::nurses_table();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d", $user->ID ) );
			if ( $exists ) {
				HMS_Session::set_notice( 'error', __( 'A nurse record already exists for this user.', 'hms' ) );
				return;
			}
			$user_id = $user->ID;
			if ( ! in_array( 'hms_nurse', (array) $user->roles, true ) ) {
				$user->add_role( 'hms_nurse' );
			}
		} else {
			$password = wp_generate_password( 12 );
			$username = self::generate_username( $name, '', $email );
			$user_id = wp_insert_user( array(
				'user_login'    => $username,
				'user_email'    => $email,
				'user_pass'     => $password,
				'display_name'  => $name,
				'role'          => 'hms_nurse',
			) );
			if ( is_wp_error( $user_id ) ) {
				HMS_Session::set_notice( 'error', $user_id->get_error_message() );
				return;
			}
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$result = $wpdb->insert( $table, array(
			'user_id'        => $user_id,
			'department_id'  => $department_id ? $department_id : null,
			'specialization' => $specialization,
			'phone'          => $phone,
			'created_at'     => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create nurse record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Nurse Insert Error: ' . $wpdb->last_error );
			}
			HMS_Session::set_notice( 'error', $error );
			return;
		}
		HMS_Session::set_notice( 'success', __( 'Nurse added.', 'hms' ) );
	}

	private static function action_update_nurse() {
		$id = (int) ( $_POST['nurse_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::nurses_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid nurse.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::nurses_table(),
			array(
				'department_id'  => (int) ( $_POST['department_id'] ?? 0 ) ?: null,
				'specialization' => sanitize_text_field( $_POST['specialization'] ?? '' ),
				'phone'          => sanitize_text_field( $_POST['phone'] ?? '' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update nurse.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Nurse updated.', 'hms' ) );
		}
	}

	private static function action_delete_nurse() {
		$id = (int) ( $_POST['nurse_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::nurses_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid nurse.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::nurses_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete nurse.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Nurse removed.', 'hms' ) );
		}
	}

	/* ====== Pharmacists (direct $wpdb) ====== */

	private static function action_create_pharmacist() {
		$email = sanitize_email( $_POST['email'] ?? '' );
		$name  = sanitize_text_field( $_POST['name'] ?? '' );
		$department_id = (int) ( $_POST['department_id'] ?? 0 );
		$specialization = sanitize_text_field( $_POST['specialization'] ?? '' );
		$phone = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( empty( $name ) || empty( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Name and email are required.', 'hms' ) );
			return;
		}
		if ( ! is_email( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid email address.', 'hms' ) );
			return;
		}

		global $wpdb;
		$table = HMS_DB::pharmacists_table();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d", $user->ID ) );
			if ( $exists ) {
				HMS_Session::set_notice( 'error', __( 'A pharmacist record already exists for this user.', 'hms' ) );
				return;
			}
			$user_id = $user->ID;
			if ( ! in_array( 'hms_pharmacist', (array) $user->roles, true ) ) {
				$user->add_role( 'hms_pharmacist' );
			}
		} else {
			$password = wp_generate_password( 12 );
			$username = self::generate_username( $name, '', $email );
			$user_id = wp_insert_user( array(
				'user_login'    => $username,
				'user_email'    => $email,
				'user_pass'     => $password,
				'display_name'  => $name,
				'role'          => 'hms_pharmacist',
			) );
			if ( is_wp_error( $user_id ) ) {
				HMS_Session::set_notice( 'error', $user_id->get_error_message() );
				return;
			}
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$result = $wpdb->insert( $table, array(
			'user_id'        => $user_id,
			'department_id'  => $department_id ? $department_id : null,
			'specialization' => $specialization,
			'phone'          => $phone,
			'created_at'     => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create pharmacist record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Pharmacist Insert Error: ' . $wpdb->last_error );
			}
			HMS_Session::set_notice( 'error', $error );
			return;
		}
		HMS_Session::set_notice( 'success', __( 'Pharmacist added.', 'hms' ) );
	}

	private static function action_update_pharmacist() {
		$id = (int) ( $_POST['pharmacist_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::pharmacists_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid pharmacist.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::pharmacists_table(),
			array(
				'department_id'  => (int) ( $_POST['department_id'] ?? 0 ) ?: null,
				'specialization' => sanitize_text_field( $_POST['specialization'] ?? '' ),
				'phone'          => sanitize_text_field( $_POST['phone'] ?? '' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update pharmacist.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Pharmacist updated.', 'hms' ) );
		}
	}

	private static function action_delete_pharmacist() {
		$id = (int) ( $_POST['pharmacist_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::pharmacists_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid pharmacist.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::pharmacists_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete pharmacist.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Pharmacist removed.', 'hms' ) );
		}
	}

	/* ====== Laboratory Staff (direct $wpdb) ====== */

	private static function action_create_laboratory() {
		$email = sanitize_email( $_POST['email'] ?? '' );
		$name  = sanitize_text_field( $_POST['name'] ?? '' );
		$department_id = (int) ( $_POST['department_id'] ?? 0 );
		$specialization = sanitize_text_field( $_POST['specialization'] ?? '' );
		$phone = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( empty( $name ) || empty( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Name and email are required.', 'hms' ) );
			return;
		}
		if ( ! is_email( $email ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid email address.', 'hms' ) );
			return;
		}

		global $wpdb;
		$table = HMS_DB::laboratories_table();
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id = %d", $user->ID ) );
			if ( $exists ) {
				HMS_Session::set_notice( 'error', __( 'A laboratory record already exists for this user.', 'hms' ) );
				return;
			}
			$user_id = $user->ID;
			if ( ! in_array( 'hms_laboratory', (array) $user->roles, true ) ) {
				$user->add_role( 'hms_laboratory' );
			}
		} else {
			$password = wp_generate_password( 12 );
			$username = self::generate_username( $name, '', $email );
			$user_id = wp_insert_user( array(
				'user_login'    => $username,
				'user_email'    => $email,
				'user_pass'     => $password,
				'display_name'  => $name,
				'role'          => 'hms_laboratory',
			) );
			if ( is_wp_error( $user_id ) ) {
				HMS_Session::set_notice( 'error', $user_id->get_error_message() );
				return;
			}
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$result = $wpdb->insert( $table, array(
			'user_id'        => $user_id,
			'department_id'  => $department_id ? $department_id : null,
			'specialization' => $specialization,
			'phone'          => $phone,
			'created_at'     => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create laboratory record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Laboratory Insert Error: ' . $wpdb->last_error );
			}
			HMS_Session::set_notice( 'error', $error );
			return;
		}
		HMS_Session::set_notice( 'success', __( 'Lab staff added.', 'hms' ) );
	}

	private static function action_update_laboratory() {
		$id = (int) ( $_POST['laboratory_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::laboratories_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid lab staff.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::laboratories_table(),
			array(
				'department_id'  => (int) ( $_POST['department_id'] ?? 0 ) ?: null,
				'specialization' => sanitize_text_field( $_POST['specialization'] ?? '' ),
				'phone'          => sanitize_text_field( $_POST['phone'] ?? '' ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update lab staff.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Lab staff updated.', 'hms' ) );
		}
	}

	private static function action_delete_laboratory() {
		$id = (int) ( $_POST['laboratory_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::laboratories_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid lab staff.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::laboratories_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete lab staff.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Lab staff removed.', 'hms' ) );
		}
	}

	/* ====== Patients (Admin) ====== */

	private static function action_create_patient() {
		$existing_user_id = ! empty( $_POST['existing_user_id'] ) ? (int) $_POST['existing_user_id'] : 0;
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

		if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $dob ) ) {
			HMS_Session::set_notice( 'error', __( 'First name, last name, email, and date of birth are required.', 'hms' ) );
			return;
		}

		// Determine user ID.
		if ( $existing_user_id ) {
			$user = get_user_by( 'id', $existing_user_id );
			if ( ! $user ) {
				HMS_Session::set_notice( 'error', __( 'Selected user does not exist.', 'hms' ) );
				return;
			}
			$user_id = $user->ID;
			if ( ! in_array( 'hms_patient', (array) $user->roles, true ) ) {
				$user->add_role( 'hms_patient' );
			}
		} else {
			if ( email_exists( $email ) ) {
				HMS_Session::set_notice( 'error', __( 'An account with this email already exists.', 'hms' ) );
				return;
			}
			$username = self::generate_username( $first_name, $last_name, $email );
			if ( empty( $password ) ) {
				$password = wp_generate_password( 12 );
			}
			$user_id = wp_insert_user( array(
				'user_login'    => $username,
				'user_email'    => $email,
				'user_pass'     => $password,
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'display_name'  => trim( $first_name . ' ' . $last_name ),
				'role'          => 'hms_patient',
			) );
			if ( is_wp_error( $user_id ) ) {
				HMS_Session::set_notice( 'error', $user_id->get_error_message() );
				return;
			}
			if ( empty( $_POST['password'] ) ) {
				wp_new_user_notification( $user_id, null, 'user' );
			}
		}

		// Check for existing patient record.
		global $wpdb;
		$patients_table = HMS_DB::patients_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $patients_table WHERE user_id = %d", $user_id ) );
		if ( $existing ) {
			HMS_Session::set_notice( 'error', __( 'A patient record already exists for this user.', 'hms' ) );
			return;
		}

		$patient_id   = self::generate_patient_id();
		$card_number  = HMS_DB::generate_card_number();
		if ( empty( $card_number ) ) {
			HMS_Session::set_notice( 'error', __( 'Failed to generate card number.', 'hms' ) );
			return;
		}

		$bed_id = null;
		if ( 'IPD' === $type && $department_id ) {
			$beds = HMS_Departments::get_available_beds( $department_id );
			if ( ! empty( $beds ) ) {
				$bed_id = $beds[0]->id;
				HMS_Departments::set_bed_status( $bed_id, 'occupied' );
			}
		}

		$result = $wpdb->insert( $patients_table, array(
			'patient_id'        => $patient_id,
			'user_id'           => $user_id,
			'card_number'       => $card_number,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email,
			'phone'             => $phone,
			'gender'            => $gender,
			'date_of_birth'     => $dob,
			'address'           => $address,
			'blood_group'       => $blood,
			'emergency_contact' => $emergency,
			'patient_type'      => $type,
			'department_id'     => $department_id ? $department_id : null,
			'bed_id'            => $bed_id,
			'admission_date'    => ( 'IPD' === $type ) ? current_time( 'Y-m-d' ) : null,
			'status'            => 'active',
			'created_at'        => current_time( 'mysql' ),
		) );

		if ( ! $result ) {
			$error = __( 'Failed to create patient record.', 'hms' );
			if ( ! empty( $wpdb->last_error ) ) {
				$error .= ' ' . $wpdb->last_error;
				error_log( 'HMS Patient Insert Error: ' . $wpdb->last_error );
			}
			HMS_Session::set_notice( 'error', $error );
			return;
		}
		HMS_Session::set_notice( 'success', __( 'Patient added successfully.', 'hms' ) );
	}

	private static function action_update_patient() {
		$id = (int) ( $_POST['patient_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::patients_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid patient.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::patients_table(),
			array(
				'patient_type'      => ( 'IPD' === ( $_POST['patient_type'] ?? '' ) ) ? 'IPD' : 'OPD',
				'department_id'     => (int) ( $_POST['department_id'] ?? 0 ) ?: null,
				'gender'            => sanitize_text_field( $_POST['gender'] ?? '' ),
				'phone'             => sanitize_text_field( $_POST['phone'] ?? '' ),
				'address'           => sanitize_textarea_field( $_POST['address'] ?? '' ),
				'blood_group'       => sanitize_text_field( $_POST['blood_group'] ?? '' ),
				'emergency_contact' => sanitize_text_field( $_POST['emergency_contact'] ?? '' ),
				'status'            => sanitize_key( $_POST['status'] ?? 'active' ),
				'discharge_date'    => ! empty( $_POST['discharge_date'] ) ? sanitize_text_field( $_POST['discharge_date'] ) : null,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update patient.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Patient record updated.', 'hms' ) );
		}
	}

	private static function action_delete_patient() {
		$id = (int) ( $_POST['patient_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::patients_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid patient.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::patients_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete patient.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Patient record deleted.', 'hms' ) );
		}
	}

	/* ====== Appointments ====== */

	private static function action_delete_appointment() {
		$id = (int) ( $_POST['appointment_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::appointments_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid appointment.', 'hms' ) );
			return;
		}
		global $wpdb;
		$result = $wpdb->delete( HMS_DB::appointments_table(), array( 'id' => $id ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to delete appointment.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Appointment deleted.', 'hms' ) );
		}
	}

	private static function action_update_appointment() {
		$id = (int) ( $_POST['appointment_id'] ?? 0 );
		if ( ! $id || ! self::record_exists( HMS_DB::appointments_table(), $id ) ) {
			HMS_Session::set_notice( 'error', __( 'Invalid appointment.', 'hms' ) );
			return;
		}
		$appointment_date = sanitize_text_field( $_POST['appointment_date'] ?? '' );
		$appointment_time = sanitize_text_field( $_POST['appointment_time'] ?? '' );
		$status           = sanitize_key( $_POST['status'] ?? 'pending' );
		$department_id    = (int) ( $_POST['department_id'] ?? 0 );

		if ( empty( $appointment_date ) || empty( $appointment_time ) ) {
			HMS_Session::set_notice( 'error', __( 'Date and time are required.', 'hms' ) );
			return;
		}

		global $wpdb;
		$result = $wpdb->update(
			HMS_DB::appointments_table(),
			array(
				'appointment_date' => $appointment_date,
				'appointment_time' => $appointment_time,
				'status'           => $status,
				'department_id'    => $department_id ? $department_id : null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to update appointment.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Appointment updated.', 'hms' ) );
		}
	}

	private static function action_assign_doctor() {
		$appointment_id = (int) ( $_POST['appointment_id'] ?? 0 );
		$doctor_id      = (int) ( $_POST['doctor_id'] ?? 0 );
		if ( ! $appointment_id || ! $doctor_id ) {
			HMS_Session::set_notice( 'error', __( 'Appointment and doctor are required.', 'hms' ) );
			return;
		}
		global $wpdb;
		$app_table = HMS_DB::appointments_table();
		$doc_table = HMS_DB::doctors_table();
		if ( ! self::record_exists( $app_table, $appointment_id ) ) {
			HMS_Session::set_notice( 'error', __( 'Appointment not found.', 'hms' ) );
			return;
		}
		if ( ! self::record_exists( $doc_table, $doctor_id ) ) {
			HMS_Session::set_notice( 'error', __( 'Doctor not found.', 'hms' ) );
			return;
		}
		$result = $wpdb->update( $app_table, array( 'doctor_id' => $doctor_id ), array( 'id' => $appointment_id ), array( '%d' ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to assign doctor.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Doctor assigned.', 'hms' ) );
		}
	}

	private static function action_assign_nurse() {
		$appointment_id = (int) ( $_POST['appointment_id'] ?? 0 );
		$nurse_id       = (int) ( $_POST['nurse_id'] ?? 0 );
		if ( ! $appointment_id || ! $nurse_id ) {
			HMS_Session::set_notice( 'error', __( 'Appointment and nurse are required.', 'hms' ) );
			return;
		}
		global $wpdb;
		$app_table = HMS_DB::appointments_table();
		$nur_table = HMS_DB::nurses_table();
		if ( ! self::record_exists( $app_table, $appointment_id ) ) {
			HMS_Session::set_notice( 'error', __( 'Appointment not found.', 'hms' ) );
			return;
		}
		if ( ! self::record_exists( $nur_table, $nurse_id ) ) {
			HMS_Session::set_notice( 'error', __( 'Nurse not found.', 'hms' ) );
			return;
		}
		$result = $wpdb->update( $app_table, array( 'nurse_id' => $nurse_id ), array( 'id' => $appointment_id ), array( '%d' ), array( '%d' ) );
		if ( $result === false ) {
			HMS_Session::set_notice( 'error', __( 'Failed to assign nurse.', 'hms' ) );
		} else {
			HMS_Session::set_notice( 'success', __( 'Nurse assigned.', 'hms' ) );
		}
	}
}