<?php
/**
 * پل REST وردپرس — پوشش خودکار افزونه‌هایی که REST دارند (Woo، فرم‌ها، …).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Rest implements Agent_WP_Tool_Interface {

	public function id() {
		return 'rest';
	}

	public function label() {
		return __( 'REST API سایت', 'agent-wp' );
	}

	public function description() {
		return 'Call this site WordPress REST API as the current admin. Path like /wp/v2/posts or /wc/v3/products. Use discover(scope=rest) first. Most write methods need confirmed=true; updating the CURRENT page entity (same post/product id) via PUT/PATCH can run immediately.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'method'    => array( 'type' => 'string', 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), 'default' => 'GET' ),
				'path'      => array( 'type' => 'string', 'description' => 'REST route e.g. /wp/v2/pages or /wc/v3/products/12' ),
				'query'     => array( 'type' => 'object', 'description' => 'Query string params' ),
				'body'      => array( 'type' => 'object', 'description' => 'JSON body for write methods' ),
				'confirmed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'path' ),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array(
			'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'path'   => (string) ( $args['path'] ?? '' ),
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		);
	}

	/**
	 * نوشتن روی همان موجودیت صفحهٔ فعلی (بدون DELETE) سریع است.
	 */
	private static function is_page_scoped_safe_write( $method, $path ) {
		if ( ! in_array( $method, array( 'PUT', 'PATCH' ), true ) ) {
			return false;
		}
		if ( ! class_exists( 'Agent_WP_Agent' ) ) {
			return false;
		}
		$page = Agent_WP_Agent::get_request_context( 'page', array() );
		if ( ! is_array( $page ) || empty( $page['postId'] ) ) {
			return false;
		}
		$pid = (int) $page['postId'];
		if ( $pid <= 0 ) {
			return false;
		}
		$patterns = array(
			'#^/wc/v3/products/' . $pid . '/?$#',
			'#^/wp/v2/product/' . $pid . '/?$#',
			'#^/wp/v2/posts/' . $pid . '/?$#',
			'#^/wp/v2/pages/' . $pid . '/?$#',
		);
		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $path ) ) {
				return true;
			}
		}
		return false;
	}

	public function run( array $args ) {
		$method = strtoupper( sanitize_text_field( $args['method'] ?? 'GET' ) );
		$path   = (string) ( $args['path'] ?? '' );
		$path   = '/' . ltrim( $path, '/' );

		if ( '' === $path || '/' === $path ) {
			return Agent_WP_Tool_Result::error( __( 'مسیر REST خالی است.', 'agent-wp' ) );
		}

		// مسیرهای خطرناک هسته
		$blocked = array( '/wp/v2/users/me', '/agent-wp/' );
		foreach ( $blocked as $b ) {
			if ( 0 === strpos( $path, $b ) && false !== strpos( $path, 'application-passwords' ) ) {
				return Agent_WP_Tool_Result::error( __( 'این مسیر REST مسدود است.', 'agent-wp' ) );
			}
		}
		if ( preg_match( '#^/wp/v2/users(/\d+)?$#', $path ) && in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return Agent_WP_Tool_Result::error( __( 'تغییر کاربران از این ابزار مجاز نیست.', 'agent-wp' ) );
		}

		$is_write = in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		$skip_confirm = self::is_page_scoped_safe_write( $method, $path );
		if ( $is_write && empty( $args['confirmed'] ) && ! $skip_confirm ) {
			$token = Agent_WP_Pending::store(
				'rest',
				array_merge( $args, array( 'confirmed' => true ) ),
				array(
					'method' => $method,
					'path'   => $path,
				)
			);
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'نیاز به تأیید: %s %s', 'agent-wp' ), $method, $path ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'method'       => $method,
					'path'         => $path,
				)
			);
		}

		$request = new WP_REST_Request( $method, $path );
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			foreach ( $args['query'] as $k => $v ) {
				$request->set_param( (string) $k, $v );
			}
		}
		if ( $is_write && ! empty( $args['body'] ) && is_array( $args['body'] ) ) {
			$request->set_body_params( $args['body'] );
			$request->set_header( 'Content-Type', 'application/json' );
		}

		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );
		$status   = $response->get_status();

		if ( $status < 200 || $status >= 300 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %d', $status );
			return Agent_WP_Tool_Result::error( $msg, array( 'status' => $status, 'data' => $data ) );
		}

		$encoded = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			$encoded = '';
		}
		if ( strlen( $encoded ) > 8000 ) {
			$encoded = substr( $encoded, 0, 8000 ) . '…';
		}

		return Agent_WP_Tool_Result::success(
			sprintf( __( "REST %s %s → %d\n%s", 'agent-wp' ), $method, $path, $status, $encoded ),
			array(
				'status' => $status,
				'path'   => $path,
				'method' => $method,
				'data'   => $data,
			)
		);
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::error( __( 'Rollback خودکار REST پشتیبانی نمی‌شود؛ در صورت نیاز دستی برگردان.', 'agent-wp' ) );
	}
}
