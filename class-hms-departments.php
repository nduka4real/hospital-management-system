<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Departments {

	public static function init() {
		// Reserved for future hooks (e.g. REST routes).
	}

	public static function get_all() {
		global $wpdb;
		$table = HMS_DB::departments_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = HMS_DB::departments_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function create( $name, $description = '', $type = 'department' ) {
		global $wpdb;
		$table = HMS_DB::departments_table();
		$wpdb->insert( $table, array(
			'name'            => sanitize_text_field( $name ),
			'slug'            => sanitize_title( $name ),
			'description'     => sanitize_textarea_field( $description ),
			'department_type' => sanitize_key( $type ),
			'created_at'      => current_time( 'mysql' ),
		) );
		return $wpdb->insert_id;
	}

	public static function update( $id, $name, $description = '', $type = 'department' ) {
		global $wpdb;
		$table = HMS_DB::departments_table();
		return $wpdb->update( $table, array(
			'name'            => sanitize_text_field( $name ),
			'slug'            => sanitize_title( $name ),
			'description'     => sanitize_textarea_field( $description ),
			'department_type' => sanitize_key( $type ),
		), array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = HMS_DB::departments_table();
		return $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}

	/* ---------------- Beds ---------------- */

	public static function get_beds( $department_id = 0 ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		if ( $department_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE department_id = %d ORDER BY bed_number ASC", $department_id ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY department_id ASC, bed_number ASC" );
	}

	public static function get_bed( $id ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_available_beds( $department_id ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE department_id = %d AND status = 'available' ORDER BY bed_number ASC", $department_id ) );
	}

	public static function create_bed( $department_id, $bed_number ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		$wpdb->insert( $table, array(
			'department_id' => (int) $department_id,
			'bed_number'    => sanitize_text_field( $bed_number ),
			'status'        => 'available',
			'created_at'    => current_time( 'mysql' ),
		) );
		return $wpdb->insert_id;
	}

	public static function set_bed_status( $bed_id, $status ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		return $wpdb->update( $table, array( 'status' => sanitize_key( $status ) ), array( 'id' => (int) $bed_id ) );
	}

	public static function delete_bed( $id ) {
		global $wpdb;
		$table = HMS_DB::beds_table();
		return $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}
}
