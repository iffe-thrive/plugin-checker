<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Store recent scan summaries so we can show a trend.
 */
class PLCHK_History {

	public static function record( $report ) {
		$limit = (int) PLCHK_Settings::get( 'history_limit', 20 );
		$hist  = (array) get_option( PLCHK_OPT_HISTORY, array() );

		$hist[] = array(
			'time'     => time(),
			'errors'   => (int) $report['summary']['errors'],
			'warnings' => (int) $report['summary']['warnings'],
			'total'    => (int) $report['summary']['total_plugins'],
			'active'   => (int) $report['summary']['active_plugins'],
		);

		if ( count( $hist ) > $limit ) {
			$hist = array_slice( $hist, -$limit );
		}
		update_option( PLCHK_OPT_HISTORY, $hist, false );
	}

	public static function all() {
		return (array) get_option( PLCHK_OPT_HISTORY, array() );
	}

	public static function clear() {
		delete_option( PLCHK_OPT_HISTORY );
	}
}
