<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Wipe options.
delete_option( 'plchk_settings' );
delete_option( 'plchk_last_report' );
delete_option( 'plchk_history' );

// Wipe cached plugin-api transients (best-effort).
global $wpdb;
if ( isset( $wpdb ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_plchk_api_%' OR option_name LIKE '_transient_timeout_plchk_api_%' OR option_name LIKE '_transient_plchk_theme_api_%' OR option_name LIKE '_transient_timeout_plchk_theme_api_%'" );
}

// Clear scheduled scans.
wp_clear_scheduled_hook( 'plchk_scheduled_scan' );
