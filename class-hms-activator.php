<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access
}

/**
 * Class HMS_Activator
 * Handles plugin activation tasks: database tables, roles, default data, and pages.
 */
class HMS_Activator {

	/**
	 * Main activation method.
	 * Creates tables, adds roles, seeds default departments, and creates required pages.
	 */
	public static function activate() {
		self::create_tables();
		HMS_Roles::add_roles();
		self::create_default_departments();
		self::create_pages();
		flush_rewrite_rules();
	}

	/**
	 * Creates all plugin database tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Table names (via HMS_DB)
		$departments  = HMS_DB::departments_table();
		$beds         = HMS_DB::beds_table();
		$patients     = HMS_DB::patients_table();
		$doctors      = HMS_DB::doctors_table();
		$nurses       = HMS_DB::nurses_table();
		$pharmacists  = HMS_DB::pharmacists_table();
		$laboratories = HMS_DB::laboratories_table();
		$appointments = HMS_DB::appointments_table();
		$patient_chat = HMS_DB::patient_chat_table();

		$sql = "
		CREATE TABLE {$departments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT NULL,
			department_type VARCHAR(50) NOT NULL DEFAULT 'department',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug)
		) {$charset_collate};

		CREATE TABLE {$beds} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			department_id BIGINT UNSIGNED NOT NULL,
			bed_number VARCHAR(50) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'available',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$patients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			patient_id VARCHAR(50) NOT NULL,
			card_number VARCHAR(50) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(100) NULL,
			phone VARCHAR(30) NULL,
			date_of_birth DATE NOT NULL,
			gender VARCHAR(10) NULL,
			blood_group VARCHAR(10) NULL,
			address TEXT NULL,
			emergency_contact VARCHAR(100) NULL,
			insurance_details LONGTEXT NULL,
			medical_history LONGTEXT NULL,
			allergies TEXT NULL,
			patient_type VARCHAR(10) NOT NULL DEFAULT 'OPD',
			department_id BIGINT UNSIGNED NULL,
			bed_id BIGINT UNSIGNED NULL,
			admission_date DATE NULL,
			discharge_date DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY patient_id (patient_id),
			UNIQUE KEY card_number (card_number),
			KEY user_id (user_id),
			KEY department_id (department_id),
			KEY bed_id (bed_id),
			KEY last_name (last_name),
			KEY email (email)
		) {$charset_collate};

		CREATE TABLE {$doctors} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			department_id BIGINT UNSIGNED NULL,
			specialization VARCHAR(191) NULL,
			phone VARCHAR(30) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$nurses} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			department_id BIGINT UNSIGNED NULL,
			specialization VARCHAR(191) NULL,
			phone VARCHAR(30) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$pharmacists} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			department_id BIGINT UNSIGNED NULL,
			specialization VARCHAR(191) NULL,
			phone VARCHAR(30) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$laboratories} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			department_id BIGINT UNSIGNED NULL,
			specialization VARCHAR(191) NULL,
			phone VARCHAR(30) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$appointments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			patient_id BIGINT UNSIGNED NOT NULL,
			doctor_id BIGINT UNSIGNED NULL,
			nurse_id BIGINT UNSIGNED NULL,
			department_id BIGINT UNSIGNED NOT NULL,
			appointment_date DATE NOT NULL,
			appointment_time TIME NOT NULL,
			reason TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY patient_id (patient_id),
			KEY doctor_id (doctor_id),
			KEY nurse_id (nurse_id),
			KEY department_id (department_id)
		) {$charset_collate};

		CREATE TABLE {$patient_chat} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			patient_id BIGINT UNSIGNED NOT NULL,
			sender_id BIGINT UNSIGNED NOT NULL,
			message TEXT NOT NULL,
			message_type VARCHAR(50) NOT NULL DEFAULT 'note',
			attachment VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY patient_id (patient_id),
			KEY sender_id (sender_id)
		) {$charset_collate};
		";

		dbDelta( $sql );
	}

	/**
	 * Creates default departments if none exist.
	 */
	private static function create_default_departments() {
		global $wpdb;

		$table = HMS_DB::departments_table();
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count > 0 ) {
			return;
		}

		$departments = array(
			array( 'name' => 'Cardiology', 'slug' => 'cardiology', 'description' => 'Heart and cardiovascular services.', 'department_type' => 'department' ),
			array( 'name' => 'Neurology', 'slug' => 'neurology', 'description' => 'Nervous system disorders.', 'department_type' => 'department' ),
			array( 'name' => 'Orthopedics', 'slug' => 'orthopedics', 'description' => 'Bone and joint treatments.', 'department_type' => 'department' ),
			array( 'name' => 'Pediatrics', 'slug' => 'pediatrics', 'description' => 'Child healthcare.', 'department_type' => 'department' ),
			array( 'name' => 'Emergency', 'slug' => 'emergency', 'description' => '24/7 emergency care.', 'department_type' => 'department' ),
			array( 'name' => 'Radiology', 'slug' => 'radiology', 'description' => 'Imaging and diagnostic services.', 'department_type' => 'laboratory' ),
			array( 'name' => 'Pharmacy', 'slug' => 'pharmacy', 'description' => 'Medication dispensing.', 'department_type' => 'pharmacy' ),
		);

		foreach ( $departments as $dept ) {
			$wpdb->insert(
				$table,
				array(
					'name'            => $dept['name'],
					'slug'            => $dept['slug'],
					'description'     => $dept['description'],
					'department_type' => $dept['department_type'],
					'created_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Creates the necessary WordPress pages for the plugin if they don't exist.
	 */
	private static function create_pages() {
		$pages = array(
			'patient-dashboard' => array(
				'title'      => 'Patient Dashboard',
				'content'    => '[hms_patient_dashboard]',
				'option_key' => 'hms_patient_dashboard_page_id',
			),
			'doctor-dashboard' => array(
				'title'      => 'Doctor Dashboard',
				'content'    => '[hms_doctor_dashboard]',
				'option_key' => 'hms_doctor_dashboard_page_id',
			),
			'add-patient' => array(
				'title'      => 'Add Patient',
				'content'    => '[hms_add_patient]',
				'option_key' => 'hms_add_patient_page_id',
			),
		);

		foreach ( $pages as $slug => $page ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $existing ) {
				$page_data = array(
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => $slug,
				);
				$page_id = wp_insert_post( $page_data );
				if ( $page_id && ! is_wp_error( $page_id ) ) {
					update_option( $page['option_key'], $page_id );
				}
			} else {
				// Update option with existing page ID
				update_option( $page['option_key'], $existing->ID );
			}
		}
	}
}