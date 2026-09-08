<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

/**
 * Manage Plugin Checker from WP-CLI.
 */
class PLCHK_CLI {

	/**
	 * Run a scan and print a report.
	 *
	 * ## OPTIONS
	 *
	 * [--deep]
	 * : Scan every PHP file in each plugin (slower, more thorough).
	 *
	 * [--format=<format>]
	 * : table (default) | json | csv | yaml
	 *
	 * [--only-issues]
	 * : Only show plugins that have at least one issue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin-checker scan
	 *     wp plugin-checker scan --deep --format=json
	 *     wp plugin-checker scan --only-issues
	 */
	public function scan( $args, $assoc ) {
		$deep    = isset( $assoc['deep'] );
		$only    = isset( $assoc['only-issues'] );
		$format  = isset( $assoc['format'] ) ? $assoc['format'] : 'table';

		$scanner = new PLCHK_Scanner( array( 'deep_scan' => $deep ) );
		$report  = $scanner->run();
		update_option( PLCHK_OPT_LAST_REPORT, $report, false );
		PLCHK_History::record( $report );

		if ( 'json' === $format ) {
			WP_CLI::print_value( $report, array( 'format' => 'json' ) );
			return;
		}

		$rows = array();
		foreach ( $report['plugins'] as $file => $row ) {
			if ( empty( $row['issues'] ) ) {
				if ( ! $only ) {
					$rows[] = array( 'kind' => 'plugin', 'name' => $row['name'], 'slug' => $file, 'severity' => 'ok', 'type' => '', 'message' => 'No issues detected.' );
				}
				continue;
			}
			foreach ( $row['issues'] as $issue ) {
				$rows[] = array(
					'kind'     => 'plugin',
					'name'     => $row['name'],
					'slug'     => $file,
					'severity' => $issue['severity'],
					'type'     => $issue['type'],
					'message'  => $issue['message'],
				);
			}
		}
		if ( ! empty( $report['themes'] ) ) {
			foreach ( $report['themes'] as $slug => $row ) {
				if ( empty( $row['issues'] ) ) {
					if ( ! $only ) {
						$rows[] = array( 'kind' => 'theme', 'name' => $row['name'], 'slug' => $slug, 'severity' => 'ok', 'type' => '', 'message' => 'No issues detected.' );
					}
					continue;
				}
				foreach ( $row['issues'] as $issue ) {
					$rows[] = array(
						'kind'     => 'theme',
						'name'     => $row['name'],
						'slug'     => $slug,
						'severity' => $issue['severity'],
						'type'     => $issue['type'],
						'message'  => $issue['message'],
					);
				}
			}
		}
		foreach ( $report['conflicts'] as $c ) {
			$rows[] = array( 'kind' => 'conflict', 'name' => '', 'slug' => '', 'severity' => $c['severity'], 'type' => $c['type'], 'message' => $c['message'] );
		}
		foreach ( $report['suggestions'] as $tip ) {
			$rows[] = array( 'kind' => 'suggestion', 'name' => '', 'slug' => '', 'severity' => 'info', 'type' => 'suggestion', 'message' => $tip );
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'kind', 'name', 'slug', 'severity', 'type', 'message' ) );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf(
			'Summary: %d plugins (%d active) · %d errors · %d warnings · PHP %s · WP %s',
			$report['summary']['total_plugins'],
			$report['summary']['active_plugins'],
			$report['summary']['errors'],
			$report['summary']['warnings'],
			$report['summary']['php_version'],
			$report['summary']['wp_version']
		) );

		if ( (int) $report['summary']['errors'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the last saved report.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table | json | yaml (default: json)
	 */
	public function report( $args, $assoc ) {
		$report = get_option( PLCHK_OPT_LAST_REPORT );
		if ( ! is_array( $report ) ) {
			WP_CLI::error( 'No scan report saved yet. Run: wp plugin-checker scan' );
		}
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'json';
		WP_CLI::print_value( $report, array( 'format' => $format ) );
	}
}

WP_CLI::add_command( 'plugin-checker', 'PLCHK_CLI' );
