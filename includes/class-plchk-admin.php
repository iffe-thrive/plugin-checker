<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLCHK_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_bar_menu',        array( $this, 'admin_bar' ), 100 );
		add_action( 'wp_dashboard_setup',    array( $this, 'dashboard_widget' ) );
		add_action( 'admin_init',            array( $this, 'handle_export' ) );
		add_action( 'admin_post_plchk_clear_debug_log', array( $this, 'handle_clear_debug_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLCHK_FILE ), array( $this, 'row_actions' ) );
	}

	private function cap() {
		return PLCHK_Settings::get( 'capability', 'manage_options' );
	}

	public function menu() {
		$cap = $this->cap();

		add_menu_page(
			__( 'Plugin Checker', 'plugin-checker' ),
			__( 'Plugin Checker', 'plugin-checker' ),
			$cap,
			'plugin-checker',
			array( $this, 'render_page' ),
			'dashicons-shield-alt',
			76
		);

		add_submenu_page(
			'plugin-checker',
			__( 'Scan', 'plugin-checker' ),
			__( 'Scan', 'plugin-checker' ),
			$cap,
			'plugin-checker',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'plugin-checker',
			__( 'Settings', 'plugin-checker' ),
			__( 'Settings', 'plugin-checker' ),
			$cap,
			'plugin-checker-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'plugin-checker',
			__( 'History', 'plugin-checker' ),
			__( 'History', 'plugin-checker' ),
			$cap,
			'plugin-checker-history',
			array( $this, 'render_history' )
		);
	}

	public function row_actions( $links ) {
		$url = admin_url( 'admin.php?page=plugin-checker' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Scan', 'plugin-checker' ) . '</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( strpos( (string) $hook, 'plugin-checker' ) === false ) {
			return;
		}
		wp_enqueue_style( 'plchk-admin', PLCHK_URL . 'assets/admin.css', array(), PLCHK_VERSION );
		wp_enqueue_script( 'plchk-admin', PLCHK_URL . 'assets/admin.js', array( 'jquery' ), PLCHK_VERSION, true );
		wp_localize_script( 'plchk-admin', 'PLCHK', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'plchk_scan' ),
			'i18n'  => array(
				'scanning' => __( 'Scanning plugins…', 'plugin-checker' ),
				'failed'   => __( 'Scan failed.', 'plugin-checker' ),
			),
		) );
	}

	public function admin_bar( $bar ) {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$report = get_option( PLCHK_OPT_LAST_REPORT );
		if ( ! is_array( $report ) ) {
			return;
		}
		$errors   = (int) ( $report['summary']['errors'] ?? 0 );
		$warnings = (int) ( $report['summary']['warnings'] ?? 0 );
		$total    = $errors + $warnings;
		if ( ! $total ) {
			return;
		}
		$class = $errors ? 'plchk-bar-error' : 'plchk-bar-warn';
		$title = sprintf(
			/* translators: 1: errors, 2: warnings */
			__( 'Plugin Checker: %1$d errors, %2$d warnings', 'plugin-checker' ),
			$errors,
			$warnings
		);
		$bar->add_node( array(
			'id'    => 'plchk-bar',
			'title' => '<span class="ab-icon dashicons dashicons-shield-alt" style="top:2px"></span><span class="ab-label ' . esc_attr( $class ) . '">' . esc_html( $title ) . '</span>',
			'href'  => admin_url( 'admin.php?page=plugin-checker' ),
			'meta'  => array( 'title' => $title ),
		) );
	}

	public function dashboard_widget() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'plchk_dashboard',
			__( 'Plugin Checker', 'plugin-checker' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$report = get_option( PLCHK_OPT_LAST_REPORT );
		if ( ! is_array( $report ) ) {
			echo '<p>' . esc_html__( 'No scan has run yet.', 'plugin-checker' ) . ' ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=plugin-checker' ) ) . '">' . esc_html__( 'Run one now.', 'plugin-checker' ) . '</a></p>';
			return;
		}
		$s = $report['summary'];
		echo '<ul class="plchk-widget">';
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['errors'],   esc_html__( 'errors', 'plugin-checker' ) );
		printf( '<li><strong>%d</strong> %s</li>', (int) $s['warnings'], esc_html__( 'warnings', 'plugin-checker' ) );
		printf( '<li><strong>%d</strong> / %d %s</li>', (int) $s['active_plugins'], (int) $s['total_plugins'], esc_html__( 'plugins active', 'plugin-checker' ) );
		printf( '<li>%s: %s</li>', esc_html__( 'Last scan', 'plugin-checker' ), esc_html( $s['scanned_at'] ) );
		echo '</ul>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=plugin-checker' ) ) . '">' . esc_html__( 'Open report', 'plugin-checker' ) . '</a></p>';
	}

	public function render_page() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$last = get_option( PLCHK_OPT_LAST_REPORT );
		?>
		<div class="wrap plchk-wrap">
			<h1>
				<?php esc_html_e( 'Plugin Checker', 'plugin-checker' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=plugin-checker&plchk_export=json' ), 'plchk_export' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Export JSON', 'plugin-checker' ); ?></a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=plugin-checker&plchk_export=csv' ), 'plchk_export' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'plugin-checker' ); ?></a>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Scans every installed plugin for PHP errors, conflicts, PHP/WP compatibility, deprecated code, and other health issues.', 'plugin-checker' ); ?>
			</p>

			<p>
				<button class="button button-primary" id="plchk-run">
					<?php esc_html_e( 'Run Scan', 'plugin-checker' ); ?>
				</button>
				<label style="margin-left:12px">
					<input type="checkbox" id="plchk-deep" <?php checked( PLCHK_Settings::get( 'deep_scan' ), 1 ); ?> />
					<?php esc_html_e( 'Deep scan (all PHP files)', 'plugin-checker' ); ?>
				</label>
				<span class="spinner" id="plchk-spinner" style="float:none;"></span>
			</p>

			<div id="plchk-report">
				<?php
				if ( is_array( $last ) ) {
					echo PLCHK_Ajax::instance()->render_report_html( $last ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		</div>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$opts     = PLCHK_Settings::get();
		$next     = wp_next_scheduled( PLCHK_CRON_HOOK );
		$log_path = WP_CONTENT_DIR . '/debug.log';
		$log_size = ( file_exists( $log_path ) && is_readable( $log_path ) ) ? filesize( $log_path ) : 0;

		if ( ! empty( $_GET['plchk_notice'] ) ) {
			$notice = sanitize_key( wp_unslash( $_GET['plchk_notice'] ) );
			if ( 'log_cleared' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'debug.log has been cleared.', 'plugin-checker' ) . '</p></div>';
			} elseif ( 'log_missing' === $notice ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No debug.log file was found — nothing to clear.', 'plugin-checker' ) . '</p></div>';
			} elseif ( 'log_error' === $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not clear debug.log — file is not writable by the web server.', 'plugin-checker' ) . '</p></div>';
			}
		}
		?>
		<div class="wrap plchk-wrap">
			<h1><?php esc_html_e( 'Plugin Checker — Settings', 'plugin-checker' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'plchk_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic scans', 'plugin-checker' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[schedule]">
								<option value="off"    <?php selected( $opts['schedule'], 'off' ); ?>><?php esc_html_e( 'Off', 'plugin-checker' ); ?></option>
								<option value="daily"  <?php selected( $opts['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'plugin-checker' ); ?></option>
								<option value="weekly" <?php selected( $opts['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'plugin-checker' ); ?></option>
							</select>
							<?php if ( $next ) : ?>
								<p class="description">
									<?php printf( esc_html__( 'Next run: %s', 'plugin-checker' ), esc_html( date_i18n( 'Y-m-d H:i', $next ) ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email report', 'plugin-checker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[email_enabled]" value="1" <?php checked( $opts['email_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Email the report after each scheduled scan', 'plugin-checker' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[email_only_on_issues]" value="1" <?php checked( $opts['email_only_on_issues'], 1 ); ?> />
								<?php esc_html_e( 'Only when the scan finds issues', 'plugin-checker' ); ?>
							</label>
							<br><br>
							<input type="text" class="regular-text" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[email_recipients]" value="<?php echo esc_attr( $opts['email_recipients'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated email addresses.', 'plugin-checker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deep scan', 'plugin-checker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[deep_scan]" value="1" <?php checked( $opts['deep_scan'], 1 ); ?> />
								<?php esc_html_e( 'Scan every PHP file in each plugin (slower, more thorough)', 'plugin-checker' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Recent-fatal window', 'plugin-checker' ); ?></th>
						<td>
							<input type="number" min="1" max="365" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[fatal_window_days]" value="<?php echo esc_attr( $opts['fatal_window_days'] ); ?>" />
							<?php esc_html_e( 'days', 'plugin-checker' ); ?>
							<p class="description"><?php esc_html_e( 'Only fatals in debug.log newer than this many days will be reported. Older entries (from bugs already fixed) are ignored.', 'plugin-checker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'History size', 'plugin-checker' ); ?></th>
						<td>
							<input type="number" min="1" max="200" name="<?php echo esc_attr( PLCHK_OPT_SETTINGS ); ?>[history_limit]" value="<?php echo esc_attr( $opts['history_limit'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How many past scan summaries to retain.', 'plugin-checker' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Debug log', 'plugin-checker' ); ?></h2>
			<?php if ( $log_size > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: file path, 2: file size */
						esc_html__( '%1$s currently uses %2$s.', 'plugin-checker' ),
						'<code>' . esc_html( $log_path ) . '</code>',
						esc_html( size_format( $log_size ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire debug.log file? This cannot be undone.', 'plugin-checker' ) ); ?>');">
					<input type="hidden" name="action" value="plchk_clear_debug_log">
					<?php wp_nonce_field( 'plchk_clear_debug_log' ); ?>
					<?php submit_button( __( 'Clear debug.log', 'plugin-checker' ), 'delete', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'Wipes the log to zero bytes. Useful once you have fixed the reported fatals and want the next scan to be truly current.', 'plugin-checker' ); ?></p>
			<?php elseif ( file_exists( $log_path ) ) : ?>
				<p><?php esc_html_e( 'debug.log exists but is already empty.', 'plugin-checker' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No debug.log file exists yet.', 'plugin-checker' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_clear_debug_log() {
		if ( ! current_user_can( $this->cap() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plugin-checker' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'plchk_clear_debug_log' );

		$log_path = WP_CONTENT_DIR . '/debug.log';
		$notice   = 'log_missing';

		if ( file_exists( $log_path ) ) {
			$notice = 'log_error';
			// Best-effort: truncate to zero bytes without deleting the inode,
			// so WP keeps writing to the same file.
			$fh = @fopen( $log_path, 'w' );
			if ( $fh ) {
				@ftruncate( $fh, 0 );
				@fclose( $fh );
				clearstatcache( true, $log_path );
				if ( is_readable( $log_path ) && filesize( $log_path ) === 0 ) {
					$notice = 'log_cleared';
				}
			}
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'plugin-checker-settings', 'plchk_notice' => $notice ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function render_history() {
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		$hist = array_reverse( PLCHK_History::all() );
		?>
		<div class="wrap plchk-wrap">
			<h1><?php esc_html_e( 'Plugin Checker — Scan History', 'plugin-checker' ); ?></h1>
			<?php if ( empty( $hist ) ) : ?>
				<p><?php esc_html_e( 'No scans recorded yet.', 'plugin-checker' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'plugin-checker' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'plugin-checker' ); ?></th>
							<th><?php esc_html_e( 'Warnings', 'plugin-checker' ); ?></th>
							<th><?php esc_html_e( 'Active / Total', 'plugin-checker' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $hist as $h ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', $h['time'] ) ); ?></td>
							<td><?php echo (int) $h['errors']; ?></td>
							<td><?php echo (int) $h['warnings']; ?></td>
							<td><?php echo (int) $h['active']; ?> / <?php echo (int) $h['total']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_export() {
		if ( empty( $_GET['plchk_export'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( $this->cap() ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'plchk_export' ) ) {
			return;
		}

		$report = get_option( PLCHK_OPT_LAST_REPORT );
		if ( ! is_array( $report ) ) {
			wp_die( esc_html__( 'No scan report available. Run a scan first.', 'plugin-checker' ) );
		}

		$format = sanitize_key( wp_unslash( $_GET['plchk_export'] ) );
		$stamp  = gmdate( 'Ymd-His' );

		if ( 'json' === $format ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="plugin-checker-' . $stamp . '.json"' );
			echo wp_json_encode( $report, JSON_PRETTY_PRINT );
			exit;
		}

		if ( 'csv' === $format ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="plugin-checker-' . $stamp . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'kind', 'slug', 'name', 'version', 'active', 'severity', 'type', 'message' ) );
			foreach ( $report['plugins'] as $file => $row ) {
				if ( empty( $row['issues'] ) ) {
					fputcsv( $out, array( 'plugin', $file, $row['name'], $row['version'], $row['active'] ? 'yes' : 'no', 'ok', '', '' ) );
					continue;
				}
				foreach ( $row['issues'] as $issue ) {
					fputcsv( $out, array( 'plugin', $file, $row['name'], $row['version'], $row['active'] ? 'yes' : 'no', $issue['severity'], $issue['type'], $issue['message'] ) );
				}
			}
			if ( ! empty( $report['themes'] ) ) {
				foreach ( $report['themes'] as $slug => $row ) {
					if ( empty( $row['issues'] ) ) {
						fputcsv( $out, array( 'theme', $slug, $row['name'], $row['version'], $row['active'] ? 'yes' : 'no', 'ok', '', '' ) );
						continue;
					}
					foreach ( $row['issues'] as $issue ) {
						fputcsv( $out, array( 'theme', $slug, $row['name'], $row['version'], $row['active'] ? 'yes' : 'no', $issue['severity'], $issue['type'], $issue['message'] ) );
					}
				}
			}
			foreach ( $report['conflicts'] as $c ) {
				fputcsv( $out, array( 'conflict', '', '', '', '', $c['severity'], $c['type'], $c['message'] ) );
			}
			foreach ( $report['suggestions'] as $tip ) {
				fputcsv( $out, array( 'suggestion', '', '', '', '', 'info', 'suggestion', $tip ) );
			}
			fclose( $out );
			exit;
		}
	}
}
