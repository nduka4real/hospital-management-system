<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HMS_Deactivator {

	/**
	 * We intentionally keep DB tables and pages on deactivation so hospital
	 * data is never lost by accident. Roles are left in place too so
	 * existing patient/doctor accounts keep working if the plugin is
	 * reactivated. Uninstall.php (if added) would be the place to purge data.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
