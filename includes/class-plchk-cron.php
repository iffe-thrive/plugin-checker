<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLCHK_Cron {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( PLCHK_CRON_HOOK, array( $this, 'run' ) );
	}

	public static function reschedule( $schedule = null ) {
		if ( null === $schedule ) {
			$schedule = PLCHK_Settings::get( 'schedule', 'weekly' );
		}
		wp_clear_scheduled_hook( PLCHK_CRON_HOOK );
		if ( 'off' === $schedule ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, PLCHK_CRON_HOOK );
	}

	public function run() {
		$opts    = PLCHK_Settings::get();
		$scanner = new PLCHK_Scanner( array( 'deep_scan' => ! empty( $opts['deep_scan'] ) ) );
		$report  = $scanner->run();

		update_option( PLCHK_OPT_LAST_REPORT, $report, false );
		PLCHK_History::record( $report );

		if ( empty( $opts['email_enabled'] ) ) {
			return;
		}
		$has_issues = ( (int) $report['summary']['errors'] + (int) $report['summary']['warnings'] ) > 0;
		if ( ! empty( $opts['email_only_on_issues'] ) && ! $has_issues ) {
			return;
		}

		$this->send_report_email( $report, $opts['email_recipients'] );
	}

	private function send_report_email( $report, $recipients ) {
		$to = array_filter( array_map( 'trim', explode( ',', (string) $recipients ) ) );
		if ( empty( $to ) ) {
			return;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			'[%s] Plugin Checker: %d errors, %d warnings',
			$site,
			(int) $report['summary']['errors'],
			(int) $report['summary']['warnings']
		);

		$lines   = array();
		$lines[] = sprintf( 'Site: %s (%s)', $site, home_url() );
		$lines[] = sprintf( 'Scanned: %s', $report['summary']['scanned_at'] );
		$lines[] = sprintf( 'PHP %s · WordPress %s', $report['summary']['php_version'], $report['summary']['wp_version'] );
		$lines[] = sprintf( '%d plugins (%d active) · %d errors · %d warnings',
			$report['summary']['total_plugins'],
			$report['summary']['active_plugins'],
			$report['summary']['errors'],
			$report['summary']['warnings']
		);
		$lines[] = '';

		foreach ( $report['plugins'] as $file => $row ) {
			if ( empty( $row['issues'] ) ) {
				continue;
			}
			$lines[] = sprintf( '## %s (%s)', $row['name'], $file );
			foreach ( $row['issues'] as $issue ) {
				$lines[] = sprintf( '  [%s] %s — %s', strtoupper( $issue['severity'] ), $issue['type'], $issue['message'] );
			}
			$lines[] = '';
		}

		if ( ! empty( $report['themes'] ) ) {
			foreach ( $report['themes'] as $slug => $row ) {
				if ( empty( $row['issues'] ) ) {
					continue;
				}
				$lines[] = sprintf( '## Theme: %s (%s)', $row['name'], $slug );
				foreach ( $row['issues'] as $issue ) {
					$lines[] = sprintf( '  [%s] %s — %s', strtoupper( $issue['severity'] ), $issue['type'], $issue['message'] );
				}
				$lines[] = '';
			}
		}

		if ( ! empty( $report['conflicts'] ) ) {
			$lines[] = '## Conflicts';
			foreach ( $report['conflicts'] as $c ) {
				$lines[] = sprintf( '  [%s] %s', strtoupper( $c['severity'] ), $c['message'] );
			}
			$lines[] = '';
		}
		if ( ! empty( $report['suggestions'] ) ) {
			$lines[] = '## Suggestions';
			foreach ( $report['suggestions'] as $tip ) {
				$lines[] = '  - ' . $tip;
			}
			$lines[] = '';
		}

		$lines[] = 'Full report: ' . admin_url( 'admin.php?page=plugin-checker' );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
