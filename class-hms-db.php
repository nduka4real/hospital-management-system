<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central place for table names and small DB helpers.
 */
class HMS_DB {

	public static function departments_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_departments';
	}

	public static function beds_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_beds';
	}

	public static function patients_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_patients';
	}

	public static function doctors_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_doctors';
	}

	public static function nurses_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_nurses';
	}

	public static function pharmacists_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_pharmacists';
	}

	public static function laboratories_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_laboratories';
	}

	public static function appointments_table() {
		global $wpdb;
		return $wpdb->prefix . 'hms_appointments';
	}

	/**
	 * Generate the next sequential hospital card number, e.g. HMS-000123
	 */
	public static function generate_card_number() {
		global $wpdb;
		$table = self::patients_table();
		
		// Get the maximum card number to handle gaps properly
		$max_num = $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING(card_number, 5) AS UNSIGNED)) FROM {$table}" );
		$next = $max_num ? (int) $max_num + 1 : 1;
		$card = 'HMS-' . str_pad( $next, 6, '0', STR_PAD_LEFT );

		// Ensure uniqueness in case of gaps/deletions.
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE card_number = %s", $card ) ) ) {
			$next++;
			$card = 'HMS-' . str_pad( $next, 6, '0', STR_PAD_LEFT );
		}
		return $card;
	}
}