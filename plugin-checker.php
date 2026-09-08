<?php
/**
 * Plugin Name: Plugin Checker (Thrive)
 * Plugin URI:  https://github.com/iffe-thrive/plugin-checker
 * Description: Professional diagnostics for WordPress: scans installed plugins for PHP errors, conflicts, PHP/WP compatibility, deprecated code, abandoned plugins, and general site health. Includes scheduled scans, email reports, WP-CLI, REST API, Site Health integration, dashboard widget, and JSON/CSV export.
 * Version:     1.1.0
 * Author:      IFFE
 * License:     GPL-2.0
 * Text Domain: plugin-checker
 * Requires PHP: 7.2
 * Requires at least: 5.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLCHK_VERSION', '1.1.0' );
define( 'PLCHK_FILE', __FILE__ );
define( 'PLCHK_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLCHK_URL', plugin_dir_url( __FILE__ ) );
define( 'PLCHK_OPT_SETTINGS', 'plchk_settings' );
define( 'PLCHK_OPT_LAST_REPORT', 'plchk_last_report' );
define( 'PLCHK_OPT_HISTORY', 'plchk_history' );
define( 'PLCHK_CRON_HOOK', 'plchk_scheduled_scan' );

require_once PLCHK_DIR . 'includes/class-plchk-scanner.php';
require_once PLCHK_DIR . 'includes/class-plchk-usage.php';
require_once PLCHK_DIR . 'includes/class-plchk-history.php';
require_once PLCHK_DIR . 'includes/class-plchk-settings.php';
require_once PLCHK_DIR . 'includes/class-plchk-admin.php';
require_once PLCHK_DIR . 'includes/class-plchk-ajax.php';
require_once PLCHK_DIR . 'includes/class-plchk-cron.php';
require_once PLCHK_DIR . 'includes/class-plchk-rest.php';
require_once PLCHK_DIR . 'includes/class-plchk-site-health.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PLCHK_DIR . 'includes/class-plchk-cli.php';
}

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'plugin-checker', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	PLCHK_Settings::instance();
	PLCHK_Admin::instance();
	PLCHK_Ajax::instance();
	PLCHK_Cron::instance();
	PLCHK_Rest::instance();
	PLCHK_Site_Health::instance();
} );

// Custom cron schedules.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly (Plugin Checker)', 'plugin-checker' ),
		);
	}
	return $schedules;
} );

register_activation_hook( __FILE__, function () {
	if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Plugin Checker requires PHP 7.2 or higher.', 'plugin-checker' ) );
	}
	// Seed defaults.
	$defaults = PLCHK_Settings::defaults();
	$current  = get_option( PLCHK_OPT_SETTINGS, array() );
	update_option( PLCHK_OPT_SETTINGS, wp_parse_args( $current, $defaults ) );

	PLCHK_Cron::reschedule();
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( PLCHK_CRON_HOOK );
} );
