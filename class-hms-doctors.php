<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Doctors {

	public static function init() {
		// Reserved for future hooks.
	}

	public static function get_all( $department_id = 0 ) {
		global $wpdb;
		$table = HMS_DB::doctors_table();
		if ( $department_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE department_id = %d ORDER BY id DESC", $department_id ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = HMS_DB::doctors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_by_user_id( $user_id ) {
		global $wpdb;
		$table = HMS_DB::doctors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Create a doctor: either link an existing WP user, or create a new one.
	 */
	public static function create( $args ) {
		$user_id = ! empty( $args['user_id'] ) ? (int) $args['user_id'] : 0;

		if ( ! $user_id && ! empty( $args['email'] ) ) {
			if ( email_exists( $args['email'] ) ) {
				$user_id = email_exists( $args['email'] );
				$user = get_user_by( 'id', $user_id );
				$user->set_role( 'hms_doctor' );
			} else {
				$username = sanitize_user( strtolower( str_replace( ' ', '.', $args['name'] ) ), true );
				$orig = $username;
				$i = 1;
				while ( username_exists( $username ) ) {
					$username = $orig . $i;
					$i++;
				}
				$password = wp_generate_password( 12 );
				$user_id  = wp_insert_user( array(
					'user_login'   => $username,
					'user_email'   => sanitize_email( $args['email'] ),
					'user_pass'    => $password,
					'display_name' => sanitize_text_field( $args['name'] ),
					'role'         => 'hms_doctor',
				) );
				if ( is_wp_error( $user_id ) ) {
					return $user_id;
				}
				wp_new_user_notification( $user_id, null, 'user' );
			}
		}

		if ( ! $user_id ) {
			return new WP_Error( 'hms_doctor_error', __( 'A user account or email is required to create a doctor.', 'hms' ) );
		}

		global $wpdb;
		$wpdb->insert( HMS_DB::doctors_table(), array(
			'user_id'        => $user_id,
			'department_id'  => ! empty( $args['department_id'] ) ? (int) $args['department_id'] : null,
			'specialization' => sanitize_text_field( $args['specialization'] ?? '' ),
			'phone'          => sanitize_text_field( $args['phone'] ?? '' ),
			'created_at'     => current_time( 'mysql' ),
		) );
		return $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$fields = array();
		$allowed = array( 'department_id', 'specialization', 'phone' );
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ];
			}
		}
		if ( empty( $fields ) ) {
			return false;
		}
		return $wpdb->update( HMS_DB::doctors_table(), $fields, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( HMS_DB::doctors_table(), array( 'id' => (int) $id ) );
	}
}
