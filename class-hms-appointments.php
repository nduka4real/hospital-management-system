<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Appointments {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_booking' ) );
		add_action( 'init', array( __CLASS__, 'handle_status_update' ) );
		add_action( 'wp_ajax_hms_get_doctors', array( __CLASS__, 'ajax_get_doctors' ) );
		add_action( 'wp_ajax_nopriv_hms_get_doctors', array( __CLASS__, 'ajax_get_doctors' ) );
	}

	/**
	 * AJAX: return doctors for a given department, used to populate the
	 * doctor dropdown on the appointment booking form.
	 */
	public static function ajax_get_doctors() {
		check_ajax_referer( 'hms_ajax', 'nonce' );
		$department_id = isset( $_POST['department_id'] ) ? (int) $_POST['department_id'] : 0;
		$doctors = $department_id ? HMS_Doctors::get_all( $department_id ) : array();

		$options = array();
		foreach ( $doctors as $doctor ) {
			$user = get_userdata( $doctor->user_id );
			$options[] = array(
				'id'   => $doctor->id,
				'name' => $user ? $user->display_name : sprintf( __( 'Doctor #%d', 'hms' ), $doctor->id ),
			);
		}
		wp_send_json_success( $options );
	}

	public static function handle_booking() {
		if ( empty( $_POST['hms_action'] ) || 'book_appointment' !== $_POST['hms_action'] ) {
			return;
		}
		if ( ! isset( $_POST['hms_appointment_nonce'] ) || ! wp_verify_nonce( $_POST['hms_appointment_nonce'], 'hms_appointment' ) ) {
			HMS_Session::set_notice( 'error', __( 'Security check failed, please try again.', 'hms' ) );
			return;
		}

		// Anyone booking an appointment must first be a registered patient.
		if ( ! is_user_logged_in() || ! HMS_Roles::current_user_is_patient() ) {
			HMS_Session::set_notice( 'error', __( 'Please register as a patient before booking an appointment.', 'hms' ) );
			return;
		}

		$patient = HMS_Patients::get_by_user_id( get_current_user_id() );
		if ( ! $patient ) {
			HMS_Session::set_notice( 'error', __( 'We could not find your patient record. Please contact Records.', 'hms' ) );
			return;
		}

		$department_id = isset( $_POST['department_id'] ) ? (int) $_POST['department_id'] : 0;
		$doctor_id     = isset( $_POST['doctor_id'] ) ? (int) $_POST['doctor_id'] : 0;
		$date          = sanitize_text_field( $_POST['appointment_date'] ?? '' );
		$time          = sanitize_text_field( $_POST['appointment_time'] ?? '' );
		$reason        = sanitize_textarea_field( $_POST['reason'] ?? '' );

		if ( ! $department_id || ! $date || ! $time ) {
			HMS_Session::set_notice( 'error', __( 'Please choose a department, date and time.', 'hms' ) );
			return;
		}

		global $wpdb;
		$wpdb->insert( HMS_DB::appointments_table(), array(
			'patient_id'        => $patient->id,
			'doctor_id'         => $doctor_id ? $doctor_id : null,
			'department_id'     => $department_id,
			'appointment_date'  => $date,
			'appointment_time'  => $time,
			'reason'            => $reason,
			'status'            => 'pending',
			'created_at'        => current_time( 'mysql' ),
		) );

		HMS_Session::set_notice( 'success', __( 'Your appointment request has been submitted. You will be notified once it is confirmed.', 'hms' ) );
		wp_safe_redirect( HMS_Auth::get_dashboard_url() );
		exit;
	}

	public static function handle_status_update() {
		if ( empty( $_POST['hms_action'] ) || 'update_appointment_status' !== $_POST['hms_action'] ) {
			return;
		}
		if ( ! isset( $_POST['hms_status_nonce'] ) || ! wp_verify_nonce( $_POST['hms_status_nonce'], 'hms_status' ) ) {
			return;
		}
		if ( ! HMS_Roles::current_user_can_manage() && ! HMS_Roles::current_user_is_doctor() ) {
			return;
		}

		$id     = isset( $_POST['appointment_id'] ) ? (int) $_POST['appointment_id'] : 0;
		$status = sanitize_key( $_POST['status'] ?? '' );
		$allowed_status = array( 'pending', 'confirmed', 'completed', 'cancelled' );

		if ( $id && in_array( $status, $allowed_status, true ) ) {
			global $wpdb;
			$wpdb->update( HMS_DB::appointments_table(), array( 'status' => $status ), array( 'id' => $id ) );
			HMS_Session::set_notice( 'success', __( 'Appointment updated.', 'hms' ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url() );
		exit;
	}

	public static function get_all( $args = array() ) {
		global $wpdb;
		$table = HMS_DB::appointments_table();
		$where = array( '1=1' );

		if ( ! empty( $args['patient_id'] ) ) {
			$where[] = $wpdb->prepare( 'patient_id = %d', $args['patient_id'] );
		}
		if ( ! empty( $args['doctor_id'] ) ) {
			$where[] = $wpdb->prepare( 'doctor_id = %d', $args['doctor_id'] );
		}
		if ( ! empty( $args['department_id'] ) ) {
			$where[] = $wpdb->prepare( 'department_id = %d', $args['department_id'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY appointment_date DESC, appointment_time DESC';
		return $wpdb->get_results( $sql );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = HMS_DB::appointments_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( HMS_DB::appointments_table(), array( 'id' => (int) $id ) );
	}
}
