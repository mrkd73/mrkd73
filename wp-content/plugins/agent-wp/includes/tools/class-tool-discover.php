<?php
/**
 * کشف قابلیت‌های سایت — پایهٔ دسترسی عمومی بدون Tool جدا برای هر افزونه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Discover implements Agent_WP_Tool_Interface {

	public function id() {
		return 'discover';
	}

	public function label() {
		return __( 'کشف سایت', 'agent-wp' );
	}

	public function description() {
		return 'Discover what this WordPress site can do: post types, taxonomies, active plugins, REST API namespaces/routes (sample). Call this first when the user asks about an unknown plugin or custom feature.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'scope'   => array(
					'type'        => 'string',
					'enum'        => array( 'overview', 'post_types', 'taxonomies', 'plugins', 'rest', 'all' ),
					'default'     => 'overview',
				),
				'rest_search' => array( 'type' => 'string', 'description' => 'Filter REST routes containing this text e.g. wc/v3 or yoast' ),
			),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$scope  = sanitize_key( $args['scope'] ?? 'overview' );
		$search = isset( $args['rest_search'] ) ? strtolower( trim( (string) $args['rest_search'] ) ) : '';
		$cache_key = 'agent_wp_discover_' . md5( $scope . '|' . $search );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['message'], $cached['data'] ) ) {
			return Agent_WP_Tool_Result::success( $cached['message'] . "\n(" . __( 'از کش', 'agent-wp' ) . ')', $cached['data'] );
		}

		$data  = array();
		$lines = array();

		if ( in_array( $scope, array( 'overview', 'post_types', 'all' ), true ) ) {
			$types = array();
			foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
				$types[] = array(
					'name'   => $pt->name,
					'label'  => $pt->labels->singular_name ? $pt->labels->singular_name : $pt->label,
					'rest'   => ! empty( $pt->show_in_rest ),
					'base'   => ! empty( $pt->rest_base ) ? $pt->rest_base : $pt->name,
				);
			}
			$data['postTypes'] = $types;
			$lines[]           = __( 'انواع محتوا:', 'agent-wp' );
			foreach ( array_slice( $types, 0, 40 ) as $t ) {
				$lines[] = sprintf( '- %s (%s)%s', $t['name'], $t['label'], $t['rest'] ? ' [REST]' : '' );
			}
		}

		if ( in_array( $scope, array( 'overview', 'taxonomies', 'all' ), true ) ) {
			$taxes = array();
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				$taxes[] = array(
					'name'  => $tax->name,
					'label' => $tax->labels->singular_name ? $tax->labels->singular_name : $tax->label,
					'types' => (array) $tax->object_type,
				);
			}
			$data['taxonomies'] = $taxes;
			$lines[]            = __( 'طبقه‌بندی‌ها:', 'agent-wp' );
			foreach ( array_slice( $taxes, 0, 30 ) as $t ) {
				$lines[] = sprintf( '- %s (%s)', $t['name'], $t['label'] );
			}
		}

		if ( in_array( $scope, array( 'overview', 'plugins', 'all' ), true ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active  = (array) get_option( 'active_plugins', array() );
			$plugins = array();
			foreach ( get_plugins() as $file => $info ) {
				if ( ! in_array( $file, $active, true ) ) {
					continue;
				}
				$plugins[] = array(
					'file'    => $file,
					'name'    => $info['Name'],
					'version' => $info['Version'],
				);
			}
			$data['activePlugins'] = $plugins;
			$lines[]               = __( 'افزونه‌های فعال:', 'agent-wp' );
			foreach ( array_slice( $plugins, 0, 40 ) as $p ) {
				$lines[] = sprintf( '- %s v%s', $p['name'], $p['version'] );
			}
		}

		if ( in_array( $scope, array( 'rest', 'all' ), true ) ) {
			$search = isset( $args['rest_search'] ) ? strtolower( trim( (string) $args['rest_search'] ) ) : '';
			$server = rest_get_server();
			$routes = $server->get_routes();
			$sample = array();
			foreach ( $routes as $route => $handlers ) {
				if ( $search && false === strpos( strtolower( $route ), $search ) ) {
					continue;
				}
				$methods = array();
				foreach ( (array) $handlers as $h ) {
					if ( ! empty( $h['methods'] ) ) {
						if ( is_array( $h['methods'] ) ) {
							$methods = array_merge( $methods, array_keys( $h['methods'] ) );
						} else {
							$methods[] = (string) $h['methods'];
						}
					}
				}
				$methods  = array_values( array_unique( $methods ) );
				$sample[] = array(
					'route'   => $route,
					'methods' => $methods,
				);
				if ( count( $sample ) >= 60 ) {
					break;
				}
			}
			$data['restRoutes'] = $sample;
			$lines[]            = __( 'مسیرهای REST (نمونه):', 'agent-wp' );
			foreach ( array_slice( $sample, 0, 40 ) as $r ) {
				$lines[] = sprintf( '- %s [%s]', $r['route'], implode( ',', $r['methods'] ) );
			}
			$lines[] = __( 'برای کار روی افزونه‌ها از ابزار rest استفاده کن (مثلاً /wc/v3/products).', 'agent-wp' );
		}

		if ( 'overview' === $scope ) {
			$lines[] = __( 'راهنما: برای جزئیات REST بگو scope=rest و rest_search=wc یا yoast. برای هر نوع محتوا از wp_content استفاده کن.', 'agent-wp' );
		}

		$message = implode( "\n", $lines );
		set_transient(
			$cache_key,
			array(
				'message' => $message,
				'data'    => $data,
			),
			5 * MINUTE_IN_SECONDS
		);

		return Agent_WP_Tool_Result::success( $message, $data );
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی.', 'agent-wp' ) );
	}
}
