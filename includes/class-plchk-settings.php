<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLCHK_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function defaults() {
		return array(
			'schedule'         => 'weekly',        // off | daily | weekly
			'email_enabled'    => 0,
			'email_recipients' => get_option( 'admin_email' ),
			'email_only_on_issues' => 1,
			'deep_scan'        => 0,               // scan every PHP file, not just main
			'history_limit'    => 20,
			'fatal_window_days' => 7,
			'capability'       => 'manage_options',
		);
	}

	public static function get( $key = null, $default = null ) {
		$opts = get_option( PLCHK_OPT_SETTINGS, self::defaults() );
		$opts = wp_parse_args( $opts, self::defaults() );
		if ( null === $key ) {
			return $opts;
		}
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function register() {
		register_setting( 'plchk_settings_group', PLCHK_OPT_SETTINGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
			'default'           => self::defaults(),
		) );
	}

	public function sanitize( $input ) {
		$out = self::defaults();

		$out['schedule'] = in_array( ( $input['schedule'] ?? '' ), array( 'off', 'daily', 'weekly' ), true )
			? $input['schedule'] : 'weekly';

		$out['email_enabled']        = ! empty( $input['email_enabled'] ) ? 1 : 0;
		$out['email_only_on_issues'] = ! empty( $input['email_only_on_issues'] ) ? 1 : 0;
		$out['deep_scan']            = ! empty( $input['deep_scan'] ) ? 1 : 0;

		$emails = array();
		if ( ! empty( $input['email_recipients'] ) ) {
			foreach ( preg_split( '/[\s,]+/', $input['email_recipients'] ) as $addr ) {
				$addr = trim( $addr );
				if ( $addr && is_email( $addr ) ) {
					$emails[] = $addr;
				}
			}
		}
		$out['email_recipients'] = $emails ? implode( ',', $emails ) : get_option( 'admin_email' );

		$hl = isset( $input['history_limit'] ) ? absint( $input['history_limit'] ) : 20;
		$out['history_limit'] = max( 1, min( 200, $hl ) );

		$fw = isset( $input['fatal_window_days'] ) ? absint( $input['fatal_window_days'] ) : 7;
		$out['fatal_window_days'] = max( 1, min( 365, $fw ) );

		// Capability stays admin-only in this release; expose later if needed.
		$out['capability'] = 'manage_options';

		// Reschedule cron on save.
		PLCHK_Cron::reschedule( $out['schedule'] );

		return $out;
	}
}
