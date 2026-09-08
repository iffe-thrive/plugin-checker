<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLCHK_Rest {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$permission = function () {
			return current_user_can( PLCHK_Settings::get( 'capability', 'manage_options' ) );
		};

		register_rest_route( 'plugin-checker/v1', '/report', array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => function () {
				$last = get_option( PLCHK_OPT_LAST_REPORT );
				if ( ! is_array( $last ) ) {
					return new WP_Error( 'plchk_no_report', __( 'No scan report yet. Run a scan first.', 'plugin-checker' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( $last );
			},
		) );

		register_rest_route( 'plugin-checker/v1', '/scan', array(
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'args'                => array(
				'deep' => array( 'type' => 'boolean', 'default' => false ),
			),
			'callback'            => function ( WP_REST_Request $req ) {
				@set_time_limit( 180 );
				$deep    = (bool) $req->get_param( 'deep' );
				$scanner = new PLCHK_Scanner( array( 'deep_scan' => $deep || (int) PLCHK_Settings::get( 'deep_scan', 0 ) === 1 ) );
				$report  = $scanner->run();
				update_option( PLCHK_OPT_LAST_REPORT, $report, false );
				PLCHK_History::record( $report );
				return rest_ensure_response( $report );
			},
		) );

		register_rest_route( 'plugin-checker/v1', '/history', array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => function () {
				return rest_ensure_response( PLCHK_History::all() );
			},
		) );
	}
}
