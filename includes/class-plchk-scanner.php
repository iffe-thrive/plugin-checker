<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Core scanner. Runs all checks against installed plugins.
 */
class PLCHK_Scanner {

	/** @var array Cached plugins-api results per slug */
	private $api_cache = array();

	/** @var bool Scan every PHP file in the plugin, not just the main entry file. */
	private $deep_scan = false;

	public function __construct( $args = array() ) {
		if ( ! empty( $args['deep_scan'] ) ) {
			$this->deep_scan = true;
		}
	}

	/**
	 * Run the full scan and return a structured report.
	 *
	 * @return array
	 */
	public function run() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins       = get_plugins();
		$active        = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$all_active    = array_unique( array_merge( $active, $network_active ) );

		$php_version = PHP_VERSION;
		$wp_version  = get_bloginfo( 'version' );

		$themes        = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();
		$active_theme  = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$active_stylesheet = $active_theme ? $active_theme->get_stylesheet() : '';
		$active_template   = $active_theme ? $active_theme->get_template()   : '';

		$results = array(
			'summary'     => array(
				'php_version'    => $php_version,
				'wp_version'     => $wp_version,
				'total_plugins'  => count( $plugins ),
				'active_plugins' => count( $all_active ),
				'total_themes'   => count( $themes ),
				'active_theme'   => $active_theme ? $active_theme->get( 'Name' ) : '',
				'issues'         => 0,
				'warnings'       => 0,
				'errors'         => 0,
				'scanned_at'     => current_time( 'mysql' ),
			),
			'plugins'     => array(),
			'themes'      => array(),
			'conflicts'   => array(),
			'suggestions' => array(),
		);

		foreach ( $plugins as $file => $data ) {
			$row = $this->scan_plugin( $file, $data, $all_active, $php_version, $wp_version );
			$results['plugins'][ $file ] = $row;
			$this->tally( $results['summary'], $row['issues'] );
		}

		foreach ( $themes as $slug => $theme ) {
			$is_active = ( $slug === $active_stylesheet || $slug === $active_template );
			$row = $this->scan_theme( $slug, $theme, $is_active, $php_version, $wp_version );
			$results['themes'][ $slug ] = $row;
			$this->tally( $results['summary'], $row['issues'] );
		}

		$results['conflicts']   = $this->detect_conflicts( $plugins, $all_active, $active_stylesheet, $active_template );
		$results['suggestions'] = $this->general_suggestions( $plugins, $all_active, $php_version, $wp_version );

		// Attach content-usage info (which posts/pages/CPTs use each active plugin).
		$usage_map = ( new PLCHK_Usage() )->build( $all_active );
		foreach ( $usage_map as $file => $usage ) {
			if ( isset( $results['plugins'][ $file ] ) ) {
				$results['plugins'][ $file ]['usage'] = $usage;
			}
		}

		return $results;
	}

	/**
	 * Add an issue list's severities to the running summary counters.
	 */
	private function tally( &$summary, $issues ) {
		foreach ( $issues as $issue ) {
			$summary['issues']++;
			if ( 'error' === $issue['severity'] ) {
				$summary['errors']++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$summary['warnings']++;
			}
		}
	}

	/**
	 * Scan a theme.
	 *
	 * @param string   $slug      Theme stylesheet slug.
	 * @param WP_Theme $theme
	 * @param bool     $is_active Whether this is the active theme (or its parent).
	 */
	private function scan_theme( $slug, $theme, $is_active, $php_version, $wp_version ) {
		$dir = $theme->get_stylesheet_directory();

		$row = array(
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'requires_php' => (string) $theme->get( 'RequiresPHP' ),
			'requires_wp'  => (string) $theme->get( 'RequiresWP' ),
			'template'     => $theme->get_template(),
			'stylesheet'   => $theme->get_stylesheet(),
			'is_child'     => $theme->parent() ? true : false,
			'active'       => (bool) $is_active,
			'issues'       => array(),
		);

		// 1. Errors reported by WP itself (missing style.css, missing parent, etc.).
		if ( method_exists( $theme, 'errors' ) ) {
			$errs = $theme->errors();
			if ( $errs && is_wp_error( $errs ) ) {
				foreach ( $errs->get_error_messages() as $msg ) {
					$row['issues'][] = array(
						'severity' => 'error',
						'type'     => 'theme_error',
						'message'  => (string) $msg,
					);
				}
			}
		}

		// 2. Child theme with missing/inactive parent.
		if ( $row['is_child'] ) {
			$parent = $theme->parent();
			if ( ! $parent || ! $parent->exists() ) {
				$row['issues'][] = array(
					'severity' => 'error',
					'type'     => 'missing_parent',
					'message'  => sprintf( __( 'Child theme references a parent template "%s" that is not installed.', 'plugin-checker' ), $row['template'] ),
				);
			}
		}

		// 3. PHP compatibility.
		if ( ! empty( $row['requires_php'] ) && version_compare( $php_version, $row['requires_php'], '<' ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'php_compat',
				'message'  => sprintf( __( 'Requires PHP %1$s but server runs PHP %2$s.', 'plugin-checker' ), $row['requires_php'], $php_version ),
			);
		}

		// 4. WordPress compatibility.
		if ( ! empty( $row['requires_wp'] ) && version_compare( $wp_version, $row['requires_wp'], '<' ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'wp_compat',
				'message'  => sprintf( __( 'Requires WordPress %1$s but site runs %2$s.', 'plugin-checker' ), $row['requires_wp'], $wp_version ),
			);
		}

		// 5 & 6. Lint + deprecated PHP function scan.
		// Active themes always get deep scanned; inactive themes get functions.php only unless deep_scan is on.
		$files_to_scan = array();
		$functions_php = trailingslashit( $dir ) . 'functions.php';
		if ( is_readable( $functions_php ) ) {
			$files_to_scan[] = $functions_php;
		}
		if ( $is_active || $this->deep_scan ) {
			$files_to_scan = $this->collect_php_files( $dir, 1500 );
		}
		$files_to_scan = array_unique( $files_to_scan );

		$reported_deprecated = array();
		foreach ( $files_to_scan as $target ) {
			$lint = $this->lint_file( $target );
			if ( true !== $lint ) {
				$row['issues'][] = array(
					'severity' => 'error',
					'type'     => 'syntax',
					'message'  => sprintf(
						__( 'PHP syntax error in %1$s: %2$s', 'plugin-checker' ),
						str_replace( WP_CONTENT_DIR . '/themes/', '', $target ),
						$lint
					),
				);
			}
			foreach ( $this->find_deprecated( $target, $php_version ) as $d ) {
				if ( isset( $reported_deprecated[ $d['name'] ] ) ) {
					continue;
				}
				$reported_deprecated[ $d['name'] ] = true;
				$row['issues'][] = array(
					'severity' => 'warning',
					'type'     => 'deprecated',
					'message'  => sprintf(
						__( 'Uses deprecated/removed PHP function "%1$s" in %2$s (line %3$d).', 'plugin-checker' ),
						$d['name'],
						str_replace( WP_CONTENT_DIR . '/themes/', '', $target ),
						$d['line']
					),
				);
			}
		}

		// 7. Recent fatal errors traced to this theme's directory.
		$fatal = $this->find_recent_fatals( $dir );
		if ( ! empty( $fatal ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'fatal',
				'message'  => sprintf( __( 'Recent PHP fatal error linked to this theme: %s', 'plugin-checker' ), $fatal ),
			);
		}

		// 8. Theme update / abandoned / tested-up-to via themes API.
		$remote = $this->theme_api_info( $slug );
		if ( is_array( $remote ) ) {
			if ( ! empty( $remote['version'] ) && version_compare( $row['version'], $remote['version'], '<' ) ) {
				$row['issues'][] = array(
					'severity' => 'warning',
					'type'     => 'update',
					'message'  => sprintf( __( 'Update available: %1$s → %2$s.', 'plugin-checker' ), $row['version'], $remote['version'] ),
				);
			}
			if ( ! empty( $remote['last_updated'] ) ) {
				$ts = strtotime( $remote['last_updated'] );
				if ( $ts && ( time() - $ts ) > ( 2 * YEAR_IN_SECONDS ) ) {
					$row['issues'][] = array(
						'severity' => 'warning',
						'type'     => 'abandoned',
						'message'  => sprintf( __( 'Not updated in over 2 years (last: %s).', 'plugin-checker' ), date_i18n( get_option( 'date_format' ), $ts ) ),
					);
				}
			}
		}

		return $row;
	}

	/**
	 * Fetch theme info from the WordPress.org themes API (cached).
	 */
	private function theme_api_info( $slug ) {
		$cache_key = 'plchk_theme_api_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return 'none' === $cached ? null : $cached;
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		$res = themes_api( 'theme_information', array(
			'slug'   => $slug,
			'fields' => array( 'sections' => false, 'tags' => false ),
		) );
		if ( is_wp_error( $res ) || ! is_object( $res ) ) {
			set_transient( $cache_key, 'none', 6 * HOUR_IN_SECONDS );
			return null;
		}
		$info = array(
			'version'      => isset( $res->version ) ? $res->version : '',
			'last_updated' => isset( $res->last_updated ) ? $res->last_updated : '',
			'requires'     => isset( $res->requires ) ? $res->requires : '',
			'requires_php' => isset( $res->requires_php ) ? $res->requires_php : '',
		);
		set_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * Scan an individual plugin file.
	 */
	private function scan_plugin( $file, $data, $all_active, $php_version, $wp_version ) {
		$abs_file = WP_PLUGIN_DIR . '/' . $file;
		$dir      = dirname( $abs_file );
		$slug     = dirname( $file );
		if ( '.' === $slug ) {
			$slug = basename( $file, '.php' );
		}

		$row = array(
			'name'         => $data['Name'],
			'version'      => $data['Version'],
			'author'       => wp_strip_all_tags( $data['Author'] ),
			'requires_php' => isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '',
			'requires_wp'  => isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : '',
			'active'       => in_array( $file, $all_active, true ),
			'slug'         => $slug,
			'issues'       => array(),
		);

		// 1. PHP version compatibility
		if ( ! empty( $row['requires_php'] ) && version_compare( $php_version, $row['requires_php'], '<' ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'php_compat',
				'message'  => sprintf(
					/* translators: 1: required PHP, 2: current PHP */
					__( 'Requires PHP %1$s but server runs PHP %2$s.', 'plugin-checker' ),
					$row['requires_php'],
					$php_version
				),
			);
		}

		// 2. WordPress version compatibility
		if ( ! empty( $row['requires_wp'] ) && version_compare( $wp_version, $row['requires_wp'], '<' ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'wp_compat',
				'message'  => sprintf(
					__( 'Requires WordPress %1$s but site runs %2$s.', 'plugin-checker' ),
					$row['requires_wp'],
					$wp_version
				),
			);
		}

		// 3 & 4. Syntax + deprecated scan. Deep scan walks all PHP files in the plugin dir.
		$files_to_scan = array( $abs_file );
		if ( $this->deep_scan && is_dir( $dir ) ) {
			$files_to_scan = $this->collect_php_files( $dir, 1500 );
		}

		$reported_deprecated = array(); // one report per fn per plugin
		foreach ( $files_to_scan as $target ) {
			$lint = $this->lint_file( $target );
			if ( true !== $lint ) {
				$row['issues'][] = array(
					'severity' => 'error',
					'type'     => 'syntax',
					'message'  => sprintf(
						__( 'PHP syntax error in %1$s: %2$s', 'plugin-checker' ),
						str_replace( WP_PLUGIN_DIR . '/', '', $target ),
						$lint
					),
				);
			}

			foreach ( $this->find_deprecated( $target, $php_version ) as $d ) {
				if ( isset( $reported_deprecated[ $d['name'] ] ) ) {
					continue;
				}
				$reported_deprecated[ $d['name'] ] = true;
				$row['issues'][] = array(
					'severity' => 'warning',
					'type'     => 'deprecated',
					'message'  => sprintf(
						__( 'Uses deprecated/removed PHP function "%1$s" in %2$s (line %3$d).', 'plugin-checker' ),
						$d['name'],
						str_replace( WP_PLUGIN_DIR . '/', '', $target ),
						$d['line']
					),
				);
			}
		}

		// 5. Recent PHP fatal errors captured for this plugin path.
		$fatals = $this->find_recent_fatals( $dir );
		if ( ! empty( $fatals ) ) {
			$row['issues'][] = array(
				'severity' => 'error',
				'type'     => 'fatal',
				'message'  => sprintf( __( 'Recent PHP fatal error linked to this plugin: %s', 'plugin-checker' ), $fatals ),
			);
		}

		// 6. Update available / abandoned check (via plugins_api, cached).
		$remote = $this->plugin_api_info( $slug );
		if ( is_array( $remote ) ) {
			if ( ! empty( $remote['version'] ) && version_compare( $data['Version'], $remote['version'], '<' ) ) {
				$row['issues'][] = array(
					'severity' => 'warning',
					'type'     => 'update',
					'message'  => sprintf( __( 'Update available: %1$s → %2$s.', 'plugin-checker' ), $data['Version'], $remote['version'] ),
				);
			}
			if ( ! empty( $remote['last_updated'] ) ) {
				$updated_ts = strtotime( $remote['last_updated'] );
				if ( $updated_ts && ( time() - $updated_ts ) > ( 2 * YEAR_IN_SECONDS ) ) {
					$row['issues'][] = array(
						'severity' => 'warning',
						'type'     => 'abandoned',
						'message'  => sprintf( __( 'Not updated in over 2 years (last: %s). Consider replacing.', 'plugin-checker' ), date_i18n( get_option( 'date_format' ), $updated_ts ) ),
					);
				}
			}
			if ( ! empty( $remote['tested'] ) && version_compare( $remote['tested'], $wp_version, '<' ) ) {
				$row['issues'][] = array(
					'severity' => 'warning',
					'type'     => 'untested',
					'message'  => sprintf( __( 'Not tested with WordPress %1$s (tested up to %2$s).', 'plugin-checker' ), $wp_version, $remote['tested'] ),
				);
			}
		}

		return $row;
	}

	/**
	 * Extract *top-level* global function names and class/interface/trait names
	 * from PHP source. Class methods, anonymous functions, closures, arrow
	 * functions, and `Foo::class` references are all correctly ignored.
	 * Namespaced symbols are returned as fully-qualified names, so
	 * `Acme\utils\init()` doesn't collide with a global `init()`.
	 *
	 * @return array{functions: string[], classes: string[]}
	 */
	private function extract_top_level_symbols( $code ) {
		$out = array( 'functions' => array(), 'classes' => array() );
		if ( ! function_exists( 'token_get_all' ) ) {
			return $out;
		}

		try {
			$tokens = @token_get_all( $code );
		} catch ( \Throwable $e ) {
			return $out;
		}
		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return $out;
		}

		$depth     = 0;
		$namespace = '';
		$n         = count( $tokens );

		$is_ws_or_comment = function ( $tok ) {
			return is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		};

		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];

			if ( is_string( $t ) ) {
				if ( '{' === $t ) { $depth++; }
				elseif ( '}' === $t ) { $depth--; }
				continue;
			}

			// Curly-open T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES also count.
			if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
				$depth++;
				continue;
			}

			if ( T_NAMESPACE === $t[0] && 0 === $depth ) {
				$ns = '';
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$nxt = $tokens[ $j ];
					if ( is_string( $nxt ) ) {
						if ( '{' === $nxt || ';' === $nxt ) { break; }
						continue;
					}
					// T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED added in PHP 8.
					$kinds = array( T_STRING, T_NS_SEPARATOR );
					if ( defined( 'T_NAME_QUALIFIED' ) ) { $kinds[] = T_NAME_QUALIFIED; }
					if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) { $kinds[] = T_NAME_FULLY_QUALIFIED; }
					if ( in_array( $nxt[0], $kinds, true ) ) {
						$ns .= $nxt[1];
					}
				}
				$namespace = trim( $ns, '\\' );
				continue;
			}

			if ( 0 !== $depth ) {
				continue; // only care about top-level declarations
			}

			// Top-level function.
			if ( T_FUNCTION === $t[0] ) {
				// Skip anonymous functions/closures: `function (` with no name.
				$name = null;
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$nxt = $tokens[ $j ];
					if ( $is_ws_or_comment( $nxt ) ) { continue; }
					if ( is_array( $nxt ) && '&' === substr( $nxt[1], 0, 1 ) ) { continue; }
					if ( is_string( $nxt ) && '&' === $nxt ) { continue; }
					if ( is_array( $nxt ) && T_STRING === $nxt[0] ) {
						$name = $nxt[1];
					}
					break;
				}
				if ( $name ) {
					$out['functions'][] = $namespace ? $namespace . '\\' . $name : $name;
				}
				continue;
			}

			// Top-level class/interface/trait/enum.
			if ( T_CLASS === $t[0] || T_INTERFACE === $t[0] || T_TRAIT === $t[0] || ( defined( 'T_ENUM' ) && T_ENUM === $t[0] ) ) {
				// Ignore `Foo::class` (previous non-ws token is T_DOUBLE_COLON).
				$prev = $i - 1;
				while ( $prev >= 0 && $is_ws_or_comment( $tokens[ $prev ] ) ) { $prev--; }
				if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
					continue;
				}
				// Ignore anonymous class: `new class {`
				if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_NEW === $tokens[ $prev ][0] ) {
					continue;
				}

				for ( $j = $i + 1; $j < $n; $j++ ) {
					$nxt = $tokens[ $j ];
					if ( $is_ws_or_comment( $nxt ) ) { continue; }
					if ( is_array( $nxt ) && T_STRING === $nxt[0] ) {
						$out['classes'][] = $namespace ? $namespace . '\\' . $nxt[1] : $nxt[1];
					}
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Recursively collect PHP files under a directory, capped for safety.
	 */
	private function collect_php_files( $dir, $cap = 1500 ) {
		$out = array();
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() && strtolower( $f->getExtension() ) === 'php' ) {
					$out[] = $f->getPathname();
					if ( count( $out ) >= $cap ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Ignore unreadable subdirs; return what we have.
		}
		return $out;
	}

	/**
	 * Lint a PHP file using `php -l` if available; otherwise a tokenizer fallback.
	 *
	 * @return true|string true on success, error message on failure.
	 */
	private function lint_file( $file ) {
		if ( ! is_readable( $file ) ) {
			return __( 'Main plugin file is not readable.', 'plugin-checker' );
		}

		$code = file_get_contents( $file );
		if ( false === $code ) {
			return __( 'Unable to read main plugin file.', 'plugin-checker' );
		}

		// Tokenizer fallback — safe, no shell exec required.
		if ( function_exists( 'token_get_all' ) ) {
			try {
				$prev = error_reporting( 0 );
				$tokens = @token_get_all( $code, TOKEN_PARSE );
				error_reporting( $prev );
				if ( false === $tokens ) {
					return __( 'Parse error detected by tokenizer.', 'plugin-checker' );
				}
			} catch ( \ParseError $e ) {
				return $e->getMessage();
			} catch ( \Throwable $e ) {
				return $e->getMessage();
			}
		}

		return true;
	}

	/**
	 * Find deprecated/removed PHP functions in a file.
	 */
	private function find_deprecated( $file, $php_version ) {
		$deprecated_map = array(
			'each'             => '7.2',
			'create_function'  => '7.2',
			'mcrypt_encrypt'   => '7.2',
			'mcrypt_decrypt'   => '7.2',
			'mysql_query'      => '7.0',
			'mysql_connect'    => '7.0',
			'split'            => '7.0',
			'ereg'             => '7.0',
			'ereg_replace'     => '7.0',
			'eregi'            => '7.0',
			'money_format'     => '7.4',
			'get_magic_quotes_gpc' => '7.4',
			'utf8_encode'      => '8.2',
			'utf8_decode'      => '8.2',
		);

		if ( ! is_readable( $file ) ) {
			return array();
		}
		$code = file_get_contents( $file );
		if ( false === $code ) {
			return array();
		}

		$found = array();
		foreach ( $deprecated_map as $fn => $since ) {
			if ( version_compare( $php_version, $since, '<' ) ) {
				continue;
			}
			if ( preg_match_all( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $match ) {
					$line = substr_count( substr( $code, 0, $match[1] ), "\n" ) + 1;
					$found[] = array( 'name' => $fn, 'line' => $line );
					break; // one report per function is enough
				}
			}
		}
		return $found;
	}

	/**
	 * Detect the most recent PHP fatal error linked to a plugin directory
	 * by scanning debug.log — but only within the configured time window,
	 * so already-fixed historical entries aren't re-reported forever.
	 */
	private function find_recent_fatals( $plugin_dir ) {
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( ! file_exists( $log ) || ! is_readable( $log ) ) {
			return '';
		}
		$size = filesize( $log );
		if ( ! $size ) {
			return '';
		}

		$window_days = 7;
		if ( class_exists( 'PLCHK_Settings' ) ) {
			$window_days = (int) PLCHK_Settings::get( 'fatal_window_days', 7 );
		}
		$cutoff = time() - ( $window_days * DAY_IN_SECONDS );

		// Read only the last ~500KB.
		$read = min( $size, 512 * 1024 );
		$fh   = fopen( $log, 'r' );
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, -$read, SEEK_END );
		$chunk = fread( $fh, $read );
		fclose( $fh );
		if ( empty( $chunk ) ) {
			return '';
		}
		$needle = str_replace( '\\', '/', $plugin_dir );
		$lines  = preg_split( '/\r\n|\r|\n/', $chunk );

		foreach ( array_reverse( $lines ) as $ln ) {
			$norm = str_replace( '\\', '/', $ln );
			if ( false === stripos( $norm, $needle ) ) {
				continue;
			}
			if ( ! preg_match( '/(Fatal error|Uncaught|Parse error)/i', $norm ) ) {
				continue;
			}

			$ts = $this->parse_log_timestamp( $ln );
			if ( null === $ts ) {
				// No timestamp we can parse — skip rather than false-alarm.
				continue;
			}
			if ( $ts < $cutoff ) {
				// Older than the window — treat as historical/already fixed.
				continue;
			}

			$hit = trim( $ln );
			if ( strlen( $hit ) > 260 ) {
				$hit = substr( $hit, 0, 260 ) . '…';
			}
			return sprintf(
				'[%s] %s',
				date_i18n( 'Y-m-d H:i', $ts ),
				$hit
			);
		}
		return '';
	}

	/**
	 * Parse the leading timestamp in a WordPress debug.log line.
	 * Format: "[13-May-2025 10:23:45 UTC] PHP Fatal error: …"
	 * Returns a Unix timestamp, or null if unparseable.
	 */
	private function parse_log_timestamp( $line ) {
		if ( ! preg_match( '/^\[([^\]]+)\]/', $line, $m ) ) {
			return null;
		}
		$raw = trim( $m[1] );
		// strtotime handles "13-May-2025 10:23:45 UTC" natively.
		$ts = strtotime( $raw );
		return $ts ?: null;
	}

	/**
	 * Fetch plugin info from the WordPress.org plugins API (cached).
	 */
	private function plugin_api_info( $slug ) {
		if ( isset( $this->api_cache[ $slug ] ) ) {
			return $this->api_cache[ $slug ];
		}
		$transient_key = 'plchk_api_' . md5( $slug );
		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			$this->api_cache[ $slug ] = $cached;
			return $cached;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$res = plugins_api( 'plugin_information', array(
			'slug'   => $slug,
			'fields' => array( 'sections' => false, 'tags' => false, 'contributors' => false ),
		) );

		if ( is_wp_error( $res ) || ! is_object( $res ) ) {
			set_transient( $transient_key, 'none', 6 * HOUR_IN_SECONDS );
			$this->api_cache[ $slug ] = null;
			return null;
		}

		$info = array(
			'version'      => isset( $res->version ) ? $res->version : '',
			'last_updated' => isset( $res->last_updated ) ? $res->last_updated : '',
			'tested'       => isset( $res->tested ) ? $res->tested : '',
			'requires'     => isset( $res->requires ) ? $res->requires : '',
			'requires_php' => isset( $res->requires_php ) ? $res->requires_php : '',
		);

		set_transient( $transient_key, $info, 12 * HOUR_IN_SECONDS );
		$this->api_cache[ $slug ] = $info;
		return $info;
	}

	/**
	 * Detect conflicts across active plugins: duplicate function/class names,
	 * and known-incompatible combinations.
	 */
	private function detect_conflicts( $plugins, $all_active, $active_stylesheet = '', $active_template = '' ) {
		$conflicts = array();

		// Known duplicate-purpose plugins that commonly conflict when both active.
		$known_conflicts = array(
			array( 'w3-total-cache/w3-total-cache.php', 'wp-super-cache/wp-cache.php' ),
			array( 'w3-total-cache/w3-total-cache.php', 'wp-rocket/wp-rocket.php' ),
			array( 'wp-super-cache/wp-cache.php',       'wp-rocket/wp-rocket.php' ),
			array( 'wordfence/wordfence.php',           'better-wp-security/better-wp-security.php' ),
			array( 'wordfence/wordfence.php',           'sucuri-scanner/sucuri.php' ),
			array( 'wp-smushit/wp-smush.php',           'ewww-image-optimizer/ewww-image-optimizer.php' ),
			array( 'yoast-seo/wp-seo.php',              'wordpress-seo/wp-seo.php' ),
			array( 'wordpress-seo/wp-seo.php',          'seo-by-rank-math/rank-math.php' ),
			array( 'wordpress-seo/wp-seo.php',          'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
			array( 'classic-editor/classic-editor.php', 'gutenberg/gutenberg.php' ),
		);

		foreach ( $known_conflicts as $pair ) {
			if ( in_array( $pair[0], $all_active, true ) && in_array( $pair[1], $all_active, true ) ) {
				$conflicts[] = array(
					'severity' => 'warning',
					'type'     => 'known_pair',
					'message'  => sprintf(
						__( 'Known conflict: "%1$s" and "%2$s" perform overlapping tasks. Deactivate one.', 'plugin-checker' ),
						isset( $plugins[ $pair[0] ]['Name'] ) ? $plugins[ $pair[0] ]['Name'] : $pair[0],
						isset( $plugins[ $pair[1] ]['Name'] ) ? $plugins[ $pair[1] ]['Name'] : $pair[1]
					),
				);
			}
		}

		// Duplicate declared function/class names across active plugins + active theme (functions.php).
		$symbol_map = array();
		$sources    = array();
		foreach ( $all_active as $file ) {
			$sources[ 'plugin:' . $file ] = WP_PLUGIN_DIR . '/' . $file;
		}
		$theme_slugs = array_unique( array_filter( array( $active_stylesheet, $active_template ) ) );
		foreach ( $theme_slugs as $tslug ) {
			$fn = trailingslashit( WP_CONTENT_DIR . '/themes/' . $tslug ) . 'functions.php';
			if ( is_readable( $fn ) ) {
				$sources[ 'theme:' . $tslug ] = $fn;
			}
		}

		foreach ( $sources as $label => $abs ) {
			if ( ! is_readable( $abs ) ) {
				continue;
			}
			$code = file_get_contents( $abs );
			if ( false === $code ) {
				continue;
			}
			$symbols = $this->extract_top_level_symbols( $code );
			foreach ( $symbols['functions'] as $fn ) {
				$symbol_map[ 'function:' . strtolower( $fn ) ][] = $label;
			}
			foreach ( $symbols['classes'] as $cls ) {
				$symbol_map[ 'class:' . strtolower( $cls ) ][] = $label;
			}
		}
		foreach ( $symbol_map as $sym => $srcs ) {
			$srcs = array_unique( $srcs );
			if ( count( $srcs ) > 1 ) {
				list( $kind, $name ) = explode( ':', $sym, 2 );
				$conflicts[] = array(
					'severity' => 'error',
					'type'     => 'symbol_collision',
					'message'  => sprintf(
						__( 'Duplicate %1$s "%2$s" declared by: %3$s.', 'plugin-checker' ),
						$kind,
						$name,
						implode( ', ', $srcs )
					),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * General site-wide suggestions.
	 */
	private function general_suggestions( $plugins, $all_active, $php_version, $wp_version ) {
		$tips = array();

		if ( version_compare( $php_version, '8.1', '<' ) ) {
			$tips[] = sprintf( __( 'Your server runs PHP %s. Upgrading to PHP 8.1+ improves speed and security.', 'plugin-checker' ), $php_version );
		}
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			$tips[] = __( 'Enable WP_DEBUG_LOG in wp-config.php while investigating errors — Plugin Checker scans debug.log for plugin fatals.', 'plugin-checker' );
		}
		if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			$tips[] = __( 'WP_DEBUG_DISPLAY is enabled — disable it on production so errors do not leak to visitors.', 'plugin-checker' );
		}
		$inactive = count( $plugins ) - count( $all_active );
		if ( $inactive > 5 ) {
			$tips[] = sprintf( __( 'You have %d inactive plugins. Delete anything unused — inactive plugins can still be a security surface.', 'plugin-checker' ), $inactive );
		}
		if ( count( $all_active ) > 30 ) {
			$tips[] = sprintf( __( '%d active plugins is high — consolidate where possible to reduce conflicts and load time.', 'plugin-checker' ), count( $all_active ) );
		}
		if ( ! function_exists( 'opcache_get_status' ) || ! @opcache_get_status( false ) ) {
			$tips[] = __( 'PHP OPcache does not appear to be enabled — enabling it dramatically improves WordPress performance.', 'plugin-checker' );
		}

		return $tips;
	}
}
