<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLCHK_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_plchk_scan', array( $this, 'handle_scan' ) );
	}

	public function handle_scan() {
		if ( ! current_user_can( PLCHK_Settings::get( 'capability', 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'plugin-checker' ) ), 403 );
		}
		check_ajax_referer( 'plchk_scan', 'nonce' );

		@set_time_limit( 180 );

		$deep    = ! empty( $_POST['deep'] ) || (int) PLCHK_Settings::get( 'deep_scan', 0 ) === 1;
		$scanner = new PLCHK_Scanner( array( 'deep_scan' => $deep ) );
		$report  = $scanner->run();

		update_option( PLCHK_OPT_LAST_REPORT, $report, false );
		PLCHK_History::record( $report );

		wp_send_json_success( array(
			'report' => $report,
			'html'   => $this->render_report_html( $report ),
		) );
	}

	public function render_report_html( $report ) {
		ob_start();
		$s = $report['summary'];
		?>
		<div class="plchk-summary">
			<div class="plchk-stat"><span><?php echo (int) $s['total_plugins']; ?></span><?php esc_html_e( 'Plugins', 'plugin-checker' ); ?></div>
			<div class="plchk-stat"><span><?php echo (int) $s['active_plugins']; ?></span><?php esc_html_e( 'Active', 'plugin-checker' ); ?></div>
			<div class="plchk-stat"><span><?php echo (int) ( $s['total_themes'] ?? 0 ); ?></span><?php esc_html_e( 'Themes', 'plugin-checker' ); ?></div>
			<div class="plchk-stat plchk-error"><span><?php echo (int) $s['errors']; ?></span><?php esc_html_e( 'Errors', 'plugin-checker' ); ?></div>
			<div class="plchk-stat plchk-warn"><span><?php echo (int) $s['warnings']; ?></span><?php esc_html_e( 'Warnings', 'plugin-checker' ); ?></div>
			<div class="plchk-stat"><span><?php echo esc_html( $s['php_version'] ); ?></span>PHP</div>
			<div class="plchk-stat"><span><?php echo esc_html( $s['wp_version'] ); ?></span>WordPress</div>
		</div>

		<h2><?php esc_html_e( 'Per-plugin results', 'plugin-checker' ); ?></h2>
		<table class="widefat striped plchk-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Version', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Status', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Findings', 'plugin-checker' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $report['plugins'] as $file => $row ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $row['name'] ); ?></strong><br><small><?php echo esc_html( $file ); ?></small></td>
					<td><?php echo esc_html( $row['version'] ); ?></td>
					<td><?php echo $row['active']
						? '<span class="plchk-badge plchk-badge-active">' . esc_html__( 'Active', 'plugin-checker' ) . '</span>'
						: '<span class="plchk-badge">' . esc_html__( 'Inactive', 'plugin-checker' ) . '</span>'; ?></td>
					<td>
						<?php if ( empty( $row['issues'] ) ) : ?>
							<span class="plchk-ok">&#10003; <?php esc_html_e( 'No issues detected.', 'plugin-checker' ); ?></span>
						<?php else : ?>
							<ul class="plchk-issues">
							<?php foreach ( $row['issues'] as $issue ) : ?>
								<li class="plchk-issue plchk-<?php echo esc_attr( $issue['severity'] ); ?>">
									<span class="plchk-tag"><?php echo esc_html( $issue['type'] ); ?></span>
									<?php echo esc_html( $issue['message'] ); ?>
								</li>
							<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( ! empty( $row['usage'] ) ) :
							$usage = $row['usage'];
							$total = isset( $usage['posts'] ) ? count( $usage['posts'] ) : 0;
							?>
							<details class="plchk-usage" <?php echo $total ? 'open' : ''; ?>>
								<summary>
									<?php
									if ( ! empty( $usage['labels'] ) ) {
										echo esc_html( implode( ' · ', $usage['labels'] ) );
									} else {
										esc_html_e( 'Usage details', 'plugin-checker' );
									}
									if ( $total >= 50 ) {
										echo ' ' . esc_html__( '(showing 50 most-recent posts)', 'plugin-checker' );
									}
									?>
								</summary>
								<?php if ( ! empty( $usage['shortcodes'] ) ) : ?>
									<p class="plchk-usage-meta"><strong><?php esc_html_e( 'Shortcodes:', 'plugin-checker' ); ?></strong>
									<?php foreach ( $usage['shortcodes'] as $sc ) : ?>
										<code>[<?php echo esc_html( $sc ); ?>]</code>
									<?php endforeach; ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $usage['blocks'] ) ) : ?>
									<p class="plchk-usage-meta"><strong><?php esc_html_e( 'Blocks:', 'plugin-checker' ); ?></strong>
									<?php foreach ( $usage['blocks'] as $b ) : ?>
										<code><?php echo esc_html( $b ); ?></code>
									<?php endforeach; ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $usage['widgets']['names'] ) ) : ?>
									<p class="plchk-usage-meta"><strong><?php esc_html_e( 'Widgets:', 'plugin-checker' ); ?></strong>
									<?php foreach ( array_unique( $usage['widgets']['names'] ) as $w ) : ?>
										<code><?php echo esc_html( $w ); ?></code>
									<?php endforeach; ?>
									</p>
								<?php endif; ?>
								<?php if ( $total ) : ?>
									<table class="plchk-usage-table">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Title', 'plugin-checker' ); ?></th>
												<th><?php esc_html_e( 'Type', 'plugin-checker' ); ?></th>
												<th><?php esc_html_e( 'Actions', 'plugin-checker' ); ?></th>
											</tr>
										</thead>
										<tbody>
										<?php foreach ( $usage['posts'] as $p ) : ?>
											<tr>
												<td><?php echo esc_html( $p['title'] ); ?></td>
												<td><code><?php echo esc_html( $p['type'] ); ?></code></td>
												<td>
													<?php if ( ! empty( $p['link'] ) ) : ?>
														<a href="<?php echo esc_url( $p['link'] ); ?>"><?php esc_html_e( 'Edit', 'plugin-checker' ); ?></a>
													<?php endif; ?>
													<?php if ( ! empty( $p['view'] ) ) : ?>
														| <a href="<?php echo esc_url( $p['view'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'plugin-checker' ); ?></a>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								<?php endif; ?>
							</details>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $report['themes'] ) ) : ?>
		<h2><?php esc_html_e( 'Themes', 'plugin-checker' ); ?></h2>
		<table class="widefat striped plchk-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Version', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Status', 'plugin-checker' ); ?></th>
					<th><?php esc_html_e( 'Findings', 'plugin-checker' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $report['themes'] as $slug => $row ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $row['name'] ); ?></strong>
						<?php if ( ! empty( $row['is_child'] ) ) : ?>
							<span class="plchk-badge"><?php esc_html_e( 'Child', 'plugin-checker' ); ?></span>
						<?php endif; ?>
						<br><small><?php echo esc_html( $slug ); ?></small>
					</td>
					<td><?php echo esc_html( $row['version'] ); ?></td>
					<td><?php echo $row['active']
						? '<span class="plchk-badge plchk-badge-active">' . esc_html__( 'Active', 'plugin-checker' ) . '</span>'
						: '<span class="plchk-badge">' . esc_html__( 'Inactive', 'plugin-checker' ) . '</span>'; ?></td>
					<td>
						<?php if ( empty( $row['issues'] ) ) : ?>
							<span class="plchk-ok">&#10003; <?php esc_html_e( 'No issues detected.', 'plugin-checker' ); ?></span>
						<?php else : ?>
							<ul class="plchk-issues">
							<?php foreach ( $row['issues'] as $issue ) : ?>
								<li class="plchk-issue plchk-<?php echo esc_attr( $issue['severity'] ); ?>">
									<span class="plchk-tag"><?php echo esc_html( $issue['type'] ); ?></span>
									<?php echo esc_html( $issue['message'] ); ?>
								</li>
							<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Conflicts between active plugins and theme', 'plugin-checker' ); ?></h2>
		<?php if ( empty( $report['conflicts'] ) ) : ?>
			<p class="plchk-ok">&#10003; <?php esc_html_e( 'No conflicts detected among active plugins.', 'plugin-checker' ); ?></p>
		<?php else : ?>
			<ul class="plchk-issues">
			<?php foreach ( $report['conflicts'] as $c ) : ?>
				<li class="plchk-issue plchk-<?php echo esc_attr( $c['severity'] ); ?>">
					<span class="plchk-tag"><?php echo esc_html( $c['type'] ); ?></span>
					<?php echo esc_html( $c['message'] ); ?>
				</li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Suggestions', 'plugin-checker' ); ?></h2>
		<?php if ( empty( $report['suggestions'] ) ) : ?>
			<p class="plchk-ok">&#10003; <?php esc_html_e( 'Nothing to suggest — site looks healthy.', 'plugin-checker' ); ?></p>
		<?php else : ?>
			<ul class="plchk-issues">
			<?php foreach ( $report['suggestions'] as $tip ) : ?>
				<li class="plchk-issue plchk-info"><?php echo esc_html( $tip ); ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p class="description"><?php echo esc_html( sprintf( __( 'Scanned at %s.', 'plugin-checker' ), $s['scanned_at'] ) ); ?></p>
		<?php
		return ob_get_clean();
	}
}
