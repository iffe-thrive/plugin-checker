<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Detects where each active plugin is used across posts/pages/custom post types
 * by tracing registered shortcodes and Gutenberg blocks back to their owning plugin,
 * then scanning post_content for matches.
 */
class PLCHK_Usage {

	/** @var int Max post rows returned per plugin. */
	private $post_cap = 50;

	/**
	 * Build a per-plugin usage report.
	 *
	 * @param string[] $active_plugin_files E.g. ['woocommerce/woocommerce.php', ...]
	 * @return array [ plugin_file => [ 'shortcodes' => [], 'blocks' => [], 'posts' => [ [id,title,type,link] ] ] ]
	 */
	public function build( $active_plugin_files ) {
		if ( empty( $active_plugin_files ) ) {
			return array();
		}

		$plugin_dirs = $this->plugin_dir_map( $active_plugin_files );
		if ( empty( $plugin_dirs ) ) {
			return array();
		}

		$shortcode_map = $this->map_shortcodes( $plugin_dirs );
		$block_map     = $this->map_blocks( $plugin_dirs );

		$scope_map  = $this->classify_scope( $plugin_dirs );
		$widget_map = $this->map_widgets( $plugin_dirs );
		$option_pages = $this->find_option_registered_pages( $active_plugin_files );

		$out = array();
		foreach ( $active_plugin_files as $file ) {
			$shortcodes = isset( $shortcode_map[ $file ] ) ? array_values( array_unique( $shortcode_map[ $file ] ) ) : array();
			$blocks     = isset( $block_map[ $file ] )     ? array_values( array_unique( $block_map[ $file ] ) )     : array();
			$scope      = isset( $scope_map[ $file ] )     ? $scope_map[ $file ]                                     : array();
			$widgets    = isset( $widget_map[ $file ] )    ? $widget_map[ $file ]                                    : array( 'names' => array(), 'placed' => 0 );

			$posts = ( $shortcodes || $blocks )
				? $this->find_posts( $shortcodes, $blocks, $this->post_cap )
				: array();

			// Merge pages the plugin registered in its own options (WC cart/checkout/etc.),
			// deduped by post ID so we don't list the same page twice.
			if ( ! empty( $option_pages[ $file ] ) ) {
				$seen = array();
				foreach ( $posts as $p ) { $seen[ $p['id'] ] = true; }
				foreach ( $option_pages[ $file ] as $extra ) {
					if ( isset( $seen[ $extra['id'] ] ) ) { continue; }
					$posts[] = $extra;
					$seen[ $extra['id'] ] = true;
				}
			}

			// Compose a human-readable "where it runs" summary.
			$labels = array();
			if ( ! empty( $posts ) ) {
				$labels[] = sprintf(
					_n( 'Used in %d post/page', 'Used in %d posts/pages', count( $posts ), 'plugin-checker' ),
					count( $posts )
				);
			}
			if ( ! empty( $widgets['placed'] ) ) {
				$labels[] = sprintf(
					_n( 'Placed in %d widget area', 'Placed in %d widget areas', $widgets['placed'], 'plugin-checker' ),
					$widgets['placed']
				);
			}
			if ( ! empty( $scope['frontend'] ) ) {
				$labels[] = __( 'Runs site-wide on every front-end page', 'plugin-checker' );
			}
			if ( ! empty( $scope['rest'] ) ) {
				$labels[] = __( 'Registers REST API endpoints', 'plugin-checker' );
			}
			if ( ! empty( $scope['ajax'] ) ) {
				$labels[] = __( 'Handles AJAX requests', 'plugin-checker' );
			}
			if ( ! empty( $scope['cpt'] ) ) {
				$labels[] = __( 'Registers custom post type(s)', 'plugin-checker' );
			}
			if ( empty( $labels ) && ! empty( $scope['admin'] ) ) {
				$labels[] = __( 'Admin-only — no front-end footprint detected', 'plugin-checker' );
			}
			if ( empty( $labels ) ) {
				$labels[] = __( 'No user-visible integration detected — plugin may be inactive-in-practice or purely a helper library.', 'plugin-checker' );
			}

			$out[ $file ] = array(
				'shortcodes' => $shortcodes,
				'blocks'     => $blocks,
				'widgets'    => $widgets,
				'scope'      => $scope,
				'posts'      => $posts,
				'labels'     => $labels,
			);
		}

		return $out;
	}

	/**
	 * Inspect global hooks to determine each plugin's runtime scope.
	 * Returns [ plugin_file => [ 'frontend' => bool, 'admin' => bool, 'rest' => bool, 'ajax' => bool, 'cpt' => bool ] ]
	 */
	private function classify_scope( $plugin_dirs ) {
		global $wp_filter;
		$out = array();

		$frontend_hooks = array( 'wp_head', 'wp_footer', 'wp_body_open', 'wp_enqueue_scripts', 'template_redirect', 'template_include', 'get_header', 'get_footer', 'wp', 'the_content' );
		$admin_hooks    = array( 'admin_init', 'admin_menu', 'admin_enqueue_scripts', 'admin_head', 'admin_footer', 'admin_notices', 'network_admin_menu' );
		$rest_hooks     = array( 'rest_api_init' );

		foreach ( $frontend_hooks as $h ) {
			foreach ( $this->plugins_hooked( $h, $plugin_dirs ) as $file ) {
				$out[ $file ]['frontend'] = true;
			}
		}
		foreach ( $admin_hooks as $h ) {
			foreach ( $this->plugins_hooked( $h, $plugin_dirs ) as $file ) {
				$out[ $file ]['admin'] = true;
			}
		}
		foreach ( $rest_hooks as $h ) {
			foreach ( $this->plugins_hooked( $h, $plugin_dirs ) as $file ) {
				$out[ $file ]['rest'] = true;
			}
		}

		// AJAX handlers — hooks are dynamic (wp_ajax_<action>, wp_ajax_nopriv_<action>).
		if ( isset( $wp_filter ) && is_array( $wp_filter ) ) {
			foreach ( $wp_filter as $hook_name => $hook_obj ) {
				if ( strpos( $hook_name, 'wp_ajax_' ) !== 0 ) {
					continue;
				}
				foreach ( $this->plugins_hooked( $hook_name, $plugin_dirs ) as $file ) {
					$out[ $file ]['ajax'] = true;
				}
			}
		}

		// Custom post types — inspect registered types and their source callbacks.
		if ( function_exists( 'get_post_types' ) ) {
			$builtins = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
			foreach ( get_post_types( array(), 'objects' ) as $pt ) {
				if ( in_array( $pt->name, $builtins, true ) ) {
					continue;
				}
				// The registrar is usually a callback on 'init' — cross-check callbacks of init hook.
				// Best-effort: match plugin dir against the post type's rewrite/labels only if we can find it.
			}
			// Fallback: any plugin that hooks into 'init' AND registers a class named like *_post_type* is heuristic; skip.
			foreach ( $this->plugins_hooked( 'init', $plugin_dirs ) as $file ) {
				// Only credit as CPT if the plugin's main file mentions register_post_type().
				$abs = wp_normalize_path( WP_PLUGIN_DIR . '/' . $file );
				$dir = dirname( $abs );
				$candidates = array( $abs );
				$fn         = $dir . '/functions.php';
				if ( is_file( $fn ) ) { $candidates[] = $fn; }
				foreach ( $candidates as $cand ) {
					if ( is_readable( $cand ) ) {
						$src = @file_get_contents( $cand );
						if ( $src && false !== strpos( $src, 'register_post_type' ) ) {
							$out[ $file ]['cpt'] = true;
							break;
						}
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Given a hook name, return the set of plugin files whose code is attached to it.
	 */
	private function plugins_hooked( $hook, $plugin_dirs ) {
		global $wp_filter;
		$hits = array();
		if ( empty( $wp_filter[ $hook ] ) ) {
			return $hits;
		}
		$obj = $wp_filter[ $hook ];
		$callbacks_by_priority = isset( $obj->callbacks ) ? $obj->callbacks : $obj; // WP_Hook or legacy array
		if ( ! is_array( $callbacks_by_priority ) ) {
			return $hits;
		}
		foreach ( $callbacks_by_priority as $priority => $cbs ) {
			if ( ! is_array( $cbs ) ) { continue; }
			foreach ( $cbs as $cb ) {
				if ( empty( $cb['function'] ) ) { continue; }
				$file = $this->callback_file( $cb['function'] );
				if ( ! $file ) { continue; }
				$owner = $this->file_owner( $file, $plugin_dirs );
				if ( $owner ) {
					$hits[ $owner ] = true;
				}
			}
		}
		return array_keys( $hits );
	}

	/**
	 * Map registered widget classes to plugins and count sidebar placements.
	 */
	private function map_widgets( $plugin_dirs ) {
		$out = array();
		if ( empty( $GLOBALS['wp_widget_factory'] ) || ! is_object( $GLOBALS['wp_widget_factory'] ) ) {
			return $out;
		}
		$factory = $GLOBALS['wp_widget_factory'];
		if ( empty( $factory->widgets ) ) {
			return $out;
		}

		$widget_owners = array(); // id_base => plugin_file
		foreach ( $factory->widgets as $widget_obj ) {
			if ( ! is_object( $widget_obj ) ) { continue; }
			try {
				$r    = new ReflectionClass( $widget_obj );
				$file = $r->getFileName();
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! $file ) { continue; }
			$owner = $this->file_owner( $file, $plugin_dirs );
			if ( ! $owner ) { continue; }
			$id_base = isset( $widget_obj->id_base ) ? $widget_obj->id_base : '';
			$name    = isset( $widget_obj->name )    ? $widget_obj->name    : $id_base;
			if ( ! $id_base ) { continue; }

			if ( ! isset( $out[ $owner ] ) ) {
				$out[ $owner ] = array( 'names' => array(), 'placed' => 0 );
			}
			$out[ $owner ]['names'][] = $name;
			$widget_owners[ $id_base ] = $owner;
		}

		if ( $widget_owners && function_exists( 'wp_get_sidebars_widgets' ) ) {
			$sidebars = (array) wp_get_sidebars_widgets();
			foreach ( $sidebars as $sidebar_id => $widgets ) {
				if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
					continue;
				}
				foreach ( $widgets as $widget_id ) {
					foreach ( $widget_owners as $id_base => $owner ) {
						if ( 0 === strpos( $widget_id, $id_base . '-' ) || $widget_id === $id_base ) {
							$out[ $owner ]['placed']++;
							break;
						}
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Map each active plugin's slug directory to its plugin file.
	 * Skips single-file plugins (they can't own a directory of source files).
	 */
	private function plugin_dir_map( $active_plugin_files ) {
		$map = array();
		foreach ( $active_plugin_files as $file ) {
			$slug = dirname( $file );
			if ( '.' === $slug || '' === $slug ) {
				continue;
			}
			$abs = wp_normalize_path( WP_PLUGIN_DIR . '/' . $slug );
			$map[ $abs . '/' ] = $file;
		}
		return $map;
	}

	/**
	 * Attribute each registered shortcode to a plugin by reflecting on its callback file.
	 */
	private function map_shortcodes( $plugin_dirs ) {
		$map = array();
		if ( empty( $GLOBALS['shortcode_tags'] ) || ! is_array( $GLOBALS['shortcode_tags'] ) ) {
			return $map;
		}
		foreach ( $GLOBALS['shortcode_tags'] as $tag => $callback ) {
			$file = $this->callback_file( $callback );
			if ( ! $file ) {
				continue;
			}
			$owner = $this->file_owner( $file, $plugin_dirs );
			if ( $owner ) {
				$map[ $owner ][] = $tag;
			}
		}
		return $map;
	}

	/**
	 * Attribute each registered block type to a plugin.
	 */
	private function map_blocks( $plugin_dirs ) {
		$map = array();
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $map;
		}
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry ) {
			return $map;
		}
		foreach ( $registry->get_all_registered() as $name => $block ) {
			$file = null;
			if ( ! empty( $block->render_callback ) ) {
				$file = $this->callback_file( $block->render_callback );
			}
			// Fallback: use the block.json's source directory if available.
			if ( ! $file && isset( $block->source ) && is_string( $block->source ) ) {
				$file = $block->source;
			}
			if ( ! $file ) {
				continue;
			}
			$owner = $this->file_owner( $file, $plugin_dirs );
			if ( $owner ) {
				$map[ $owner ][] = $name;
			}
		}
		return $map;
	}

	/**
	 * Resolve any callable to the file path where it's defined.
	 */
	private function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) ) {
				if ( strpos( $callback, '::' ) !== false ) {
					list( $cls, $method ) = explode( '::', $callback, 2 );
					if ( class_exists( $cls ) ) {
						$r = new ReflectionMethod( $cls, $method );
						return $r->getFileName() ?: null;
					}
				} elseif ( function_exists( $callback ) ) {
					$r = new ReflectionFunction( $callback );
					return $r->getFileName() ?: null;
				}
			} elseif ( is_array( $callback ) && count( $callback ) === 2 ) {
				$obj_or_cls = $callback[0];
				$method     = $callback[1];
				if ( is_object( $obj_or_cls ) ) {
					$r = new ReflectionMethod( get_class( $obj_or_cls ), $method );
					return $r->getFileName() ?: null;
				}
				if ( is_string( $obj_or_cls ) && class_exists( $obj_or_cls ) ) {
					$r = new ReflectionMethod( $obj_or_cls, $method );
					return $r->getFileName() ?: null;
				}
			} elseif ( $callback instanceof Closure ) {
				$r = new ReflectionFunction( $callback );
				return $r->getFileName() ?: null;
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$r = new ReflectionMethod( $callback, '__invoke' );
				return $r->getFileName() ?: null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Given an absolute file path, return which plugin (from plugin_dirs) owns it.
	 */
	private function file_owner( $file, $plugin_dirs ) {
		$file = wp_normalize_path( $file );
		foreach ( $plugin_dirs as $dir => $plugin_file ) {
			if ( 0 === strpos( $file, $dir ) ) {
				return $plugin_file;
			}
		}
		return null;
	}

	/**
	 * Some plugins register "pages of record" via options rather than by embedding
	 * shortcodes/blocks into post_content (WooCommerce's cart, checkout, my-account,
	 * shop, terms; EDD's checkout/purchase-history; bbPress's forum root; etc.).
	 * This method looks them up and returns them so they appear in the usage list.
	 */
	private function find_option_registered_pages( $active_plugin_files ) {
		$known = array(
			'woocommerce/woocommerce.php' => array(
				'woocommerce_shop_page_id',
				'woocommerce_cart_page_id',
				'woocommerce_checkout_page_id',
				'woocommerce_myaccount_page_id',
				'woocommerce_terms_page_id',
				'woocommerce_refund_returns_page_id',
			),
			'easy-digital-downloads/easy-digital-downloads.php' => array(
				'purchase_page',
				'success_page',
				'failure_page',
				'purchase_history_page',
				'login_redirect_page',
			),
			'bbpress/bbpress.php' => array(
				'_bbp_root_slug_custom_slug',
				'_bbp_page_on_front',
			),
			'buddypress/bp-loader.php' => array(
				'bp-pages',
			),
			'wpforms-lite/wpforms.php' => array(),
			'gravityforms/gravityforms.php' => array(),
		);

		$result = array();
		foreach ( $active_plugin_files as $file ) {
			if ( empty( $known[ $file ] ) ) {
				continue;
			}
			$ids = array();
			foreach ( $known[ $file ] as $opt ) {
				$val = get_option( $opt );
				// Handle scalar IDs and arrays (BuddyPress 'bp-pages' is [component => id]).
				if ( is_numeric( $val ) && (int) $val > 0 ) {
					$ids[] = (int) $val;
				} elseif ( is_array( $val ) ) {
					foreach ( $val as $v ) {
						if ( is_numeric( $v ) && (int) $v > 0 ) {
							$ids[] = (int) $v;
						}
					}
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( empty( $ids ) ) {
				continue;
			}

			$posts = $this->fetch_posts_by_ids( $ids );
			if ( ! empty( $posts ) ) {
				$result[ $file ] = $posts;
			}
		}
		return $result;
	}

	/**
	 * Fetch post rows by IDs (used for option-registered pages).
	 */
	private function fetch_posts_by_ids( $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT ID, post_title, post_type
		        FROM {$wpdb->posts}
		        WHERE ID IN ($placeholders)
		          AND post_status NOT IN ('trash','auto-draft')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r->ID,
				'title' => '' !== $r->post_title ? $r->post_title : sprintf( __( '(no title, #%d)', 'plugin-checker' ), (int) $r->ID ),
				'type'  => $r->post_type,
				'link'  => get_edit_post_link( (int) $r->ID, 'raw' ),
				'view'  => get_permalink( (int) $r->ID ),
			);
		}
		return $out;
	}

	/**
	 * Look up posts of any type that reference the given shortcodes or block names.
	 * Returns an array of ['id','title','type','link'] rows, hard-capped.
	 */
	private function find_posts( $shortcodes, $blocks, $cap ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		foreach ( $shortcodes as $sc ) {
			$clauses[] = 'post_content LIKE %s';
			$params[]  = '%[' . $wpdb->esc_like( $sc ) . '%';
		}
		foreach ( $blocks as $b ) {
			$clauses[] = 'post_content LIKE %s';
			$params[]  = '%wp:' . $wpdb->esc_like( $b ) . ' %';
			$clauses[] = 'post_content LIKE %s';
			$params[]  = '%wp:' . $wpdb->esc_like( $b ) . '\n%';
			$clauses[] = 'post_content LIKE %s';
			$params[]  = '%wp:' . $wpdb->esc_like( $b ) . ' /%';
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		$statuses = "'publish','draft','private','pending','future'";
		$sql = "SELECT ID, post_title, post_type
		        FROM {$wpdb->posts}
		        WHERE post_status IN ($statuses)
		        AND ( " . implode( ' OR ', $clauses ) . " )
		        ORDER BY post_modified DESC
		        LIMIT %d";

		$params[] = (int) $cap;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r->ID,
				'title' => $r->post_title !== '' ? $r->post_title : sprintf( __( '(no title, #%d)', 'plugin-checker' ), (int) $r->ID ),
				'type'  => $r->post_type,
				'link'  => get_edit_post_link( (int) $r->ID, 'raw' ),
				'view'  => get_permalink( (int) $r->ID ),
			);
		}
		return $out;
	}
}
