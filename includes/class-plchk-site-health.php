<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Surface Plugin Checker findings inside WordPress's built-in Site Health screen.
 */
class PLCHK_Site_Health {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	public function register_tests( $tests ) {
		$tests['direct']['plchk_plugin_health'] = array(
			'label' => __( 'Plugin Checker findings', 'plugin-checker' ),
			'test'  => array( $this, 'test' ),
		);
		return $tests;
	}

	public function test() {
		$report = get_option( PLCHK_OPT_LAST_REPORT );

		$result = array(
			'label'       => __( 'Plugin Checker: no issues detected', 'plugin-checker' ),
			'status'      => 'good',
			'badge'       => array( 'label' => __( 'Plugins', 'plugin-checker' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'Plugin Checker did not detect any errors, conflicts, or compatibility issues in installed plugins.', 'plugin-checker' ) . '</p>',
			'actions'     => sprintf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=plugin-checker' ) ),
				esc_html__( 'Open Plugin Checker', 'plugin-checker' )
			),
			'test'        => 'plchk_plugin_health',
		);

		if ( ! is_array( $report ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Plugin Checker has not run yet', 'plugin-checker' );
			$result['description'] = '<p>' . esc_html__( 'Run a Plugin Checker scan to see whether installed plugins have PHP errors, conflicts, or compatibility issues.', 'plugin-checker' ) . '</p>';
			return $result;
		}

		$errors   = (int) $report['summary']['errors'];
		$warnings = (int) $report['summary']['warnings'];

		if ( $errors > 0 ) {
			$result['status']      = 'critical';
			$result['label']       = sprintf( __( 'Plugin Checker found %d error(s)', 'plugin-checker' ), $errors );
			$result['badge']['color'] = 'red';
			$result['description'] = '<p>' . sprintf(
				esc_html__( '%1$d error(s) and %2$d warning(s) were detected. Open the report for details.', 'plugin-checker' ),
				$errors, $warnings
			) . '</p>';
		} elseif ( $warnings > 0 ) {
			$result['status']      = 'recommended';
			$result['label']       = sprintf( __( 'Plugin Checker found %d warning(s)', 'plugin-checker' ), $warnings );
			$result['badge']['color'] = 'orange';
			$result['description'] = '<p>' . sprintf(
				esc_html__( '%d warning(s) were detected. Open the report for details.', 'plugin-checker' ),
				$warnings
			) . '</p>';
		}

		return $result;
	}
}
