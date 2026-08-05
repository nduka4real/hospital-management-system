<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Assets {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'hms_patient_register' )
			&& ! has_shortcode( $post->post_content, 'hms_login' )
			&& ! has_shortcode( $post->post_content, 'hms_patient_dashboard' )
			&& ! has_shortcode( $post->post_content, 'hms_book_appointment' )
			&& ! has_shortcode( $post->post_content, 'hms_admin_panel' )
			&& ! has_shortcode( $post->post_content, 'hms_department_list' ) ) {
			return;
		}

		wp_enqueue_style( 'hms-style', HMS_URL . 'assets/css/hms-style.css', array(), HMS_VERSION );
		wp_enqueue_script( 'hms-script', HMS_URL . 'assets/js/hms-scripts.js', array( 'jquery' ), HMS_VERSION, true );

		wp_localize_script( 'hms-script', 'HMS_Data', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'hms_ajax' ),
		) );
	}
}
