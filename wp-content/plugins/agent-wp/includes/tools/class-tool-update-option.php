<?php
/**
 * خواندن/نوشتن option — allowlist سریع؛ سایر کلیدها با تأیید (پوشش تنظیمات افزونه‌ها).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Update_Option implements Agent_WP_Tool_Interface {

	public function id() {
		return 'update_option';
	}

	public function label() {
		return __( 'تنظیمات (options)', 'agent-wp' );
	}

	public function description() {
		return 'Get or set WordPress options including plugin settings keys. Modes: get, set. Allowlisted keys run immediately; other keys require confirmed=true. siteurl/home/active_plugins are blocked.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'      => array( 'type' => 'string', 'enum' => array( 'get', 'set' ), 'default' => 'set' ),
				'key'       => array( 'type' => 'string' ),
				'value'     => array( 'description' => 'String or JSON-compatible value' ),
				'confirmed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'key' ),
		);
	}

	private static function allowlist() {
		$keys = array(
			'blogname',
			'blogdescription',
			'date_format',
			'time_format',
			'start_of_week',
			'timezone_string',
			'permalink_structure',
			'default_comment_status',
			'default_ping_status',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
		);
		return apply_filters( 'agent_wp_option_allowlist', $keys );
	}

	private static function blocked() {
		return apply_filters(
			'agent_wp_option_blocklist',
			array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'cron', 'users_can_register', 'admin_email', 'recently_edited', 'rewrite_rules' )
		);
	}

	public function can_run( array $args ) {
		$key = isset( $args['key'] ) ? sanitize_text_field( $args['key'] ) : '';
		return '' !== $key && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		$key = sanitize_text_field( $args['key'] ?? '' );
		return array(
			'key'   => $key,
			'value' => get_option( $key ),
		);
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'set' );
		$key  = sanitize_text_field( $args['key'] ?? '' );

		if ( '' === $key ) {
			return Agent_WP_Tool_Result::error( __( 'کلید option لازم است.', 'agent-wp' ) );
		}

		if ( 'get' === $mode ) {
			$val = get_option( $key, null );
			return Agent_WP_Tool_Result::success(
				sprintf( '%s = %s', $key, wp_json_encode( $val, JSON_UNESCAPED_UNICODE ) ),
				array(
					'key'   => $key,
					'value' => $val,
				)
			);
		}

		if ( in_array( $key, self::blocked(), true ) ) {
			return Agent_WP_Tool_Result::error( __( 'تغییر این option مسدود است.', 'agent-wp' ) );
		}

		$safe = in_array( $key, self::allowlist(), true );
		if ( ! $safe && empty( $args['confirmed'] ) ) {
			$token = Agent_WP_Pending::store(
				'update_option',
				array_merge( $args, array( 'mode' => 'set', 'confirmed' => true ) ),
				array(
					'key'   => $key,
					'value' => isset( $args['value'] ) ? $args['value'] : null,
				)
			);
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'نیاز به تأیید: تغییر option «%s» (کلید افزونه/سفارشی).', 'agent-wp' ), $key ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'key'          => $key,
				)
			);
		}

		$value = $args['value'] ?? '';
		if ( in_array( $key, array( 'page_on_front', 'page_for_posts', 'posts_per_page', 'start_of_week' ), true ) ) {
			$value = absint( $value );
		} elseif ( is_string( $value ) && $safe ) {
			$value = sanitize_text_field( $value );
		}

		update_option( $key, $value );
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'تنظیم «%s» به‌روز شد.', 'agent-wp' ), $key ),
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || empty( $before['key'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'snapshot موجود نیست.', 'agent-wp' ) );
		}
		update_option( $before['key'], $before['value'] );
		return Agent_WP_Tool_Result::success( __( 'تنظیم به مقدار قبل برگشت.', 'agent-wp' ), $before );
	}
}
