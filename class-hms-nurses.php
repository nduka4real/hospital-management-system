<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Nurses {

	public static function init() {
		// Reserved for future hooks.
	}

	public static function get_all( $department_id = 0 ) {
		global $wpdb;
		$table = HMS_DB::nurses_table();
		
		if ( $department_id > 0 ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE department_id = %d ORDER BY id DESC", $department_id ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = HMS_DB::nurses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_by_user_id( $user_id ) {
		global $wpdb;
		$table = HMS_DB::nurses_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Create a nurse: either link an existing WP user, or create a new one.
	 */
	public static function create( $args ) {
		// Validate required fields
		if ( empty( $args['name'] ) && empty( $args['email'] ) && empty( $args['user_id'] ) ) {
			return new WP_Error( 'hms_nurse_error', __( 'Name, email, or user ID is required to create a nurse.', 'hms' ) );
		}

		$user_id = ! empty( $args['user_id'] ) ? (int) $args['user_id'] : 0;

		if ( ! $user_id && ! empty( $args['email'] ) ) {
			$existing_user = email_exists( $args['email'] );
			if ( $existing_user ) {
				$user_id = $existing_user;
				$user = get_user_by( 'id', $user_id );
				if ( $user ) {
					$user->set_role( 'hms_nurse' );
				}
			} else {
				// Generate username from name
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
					'role'         => 'hms_nurse',
				) );
				
				if ( is_wp_error( $user_id ) ) {
					return $user_id;
				}
				
				// Send user notification with password
				wp_new_user_notification( $user_id, null, 'user' );
			}
		}

		if ( ! $user_id ) {
			return new WP_Error( 'hms_nurse_error', __( 'A user account or email is required to create a nurse.', 'hms' ) );
		}

		// Check if nurse already exists for this user
		global $wpdb;
		$table = HMS_DB::nurses_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );
		if ( $existing ) {
			return new WP_Error( 'hms_nurse_exists', __( 'A nurse record already exists for this user.', 'hms' ) );
		}

		$wpdb->insert( $table, array(
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
				$value = $data[ $field ];
				if ( $field === 'department_id' ) {
					$value = (int) $value;
				} else {
					$value = sanitize_text_field( $value );
				}
				$fields[ $field ] = $value;
			}
		}
		
		if ( empty( $fields ) ) {
			return false;
		}
		
		return $wpdb->update( HMS_DB::nurses_table(), $fields, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( HMS_DB::nurses_table(), array( 'id' => (int) $id ) );
	}
}