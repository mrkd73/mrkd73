<?php
/**
 * CSS سفارشی قالب — تغییر ظاهر نیاز به تأیید دارد + rollback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Custom_Css implements Agent_WP_Tool_Interface {

	public function id() {
		return 'custom_css';
	}

	public function label() {
		return __( 'CSS سفارشی', 'agent-wp' );
	}

	public function description() {
		return 'Read or update WordPress Additional CSS (Customizer). Modes: get, set, append. Changing CSS (set/append) ALWAYS needs user confirm in UI because it changes site appearance.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'      => array( 'type' => 'string', 'enum' => array( 'get', 'set', 'append' ), 'default' => 'get' ),
				'css'       => array( 'type' => 'string', 'description' => 'CSS code when mode is set or append' ),
				'confirmed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'mode' ),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'edit_theme_options' );
	}

	public function snapshot_before( array $args ) {
		return array(
			'css' => wp_get_custom_css(),
		);
	}

	public function run( array $args ) {
		$mode    = isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : 'get';
		$current = (string) wp_get_custom_css();

		if ( 'get' === $mode ) {
			$preview = $current !== '' ? $current : __( '(خالی)', 'agent-wp' );
			return Agent_WP_Tool_Result::success(
				__( 'CSS سفارشی فعلی:', 'agent-wp' ) . "\n" . $preview,
				array( 'css' => $current )
			);
		}

		$css = isset( $args['css'] ) ? (string) $args['css'] : '';
		if ( 'append' === $mode ) {
			$css = rtrim( $current ) . "\n\n" . ltrim( $css );
		}

		if ( empty( $args['confirmed'] ) ) {
			$preview = mb_substr( trim( $css ), 0, 280 );
			if ( mb_strlen( trim( $css ) ) > 280 ) {
				$preview .= '…';
			}
			$warning = __( 'اخطار: ظاهر سایت با CSS سفارشی تغییر می‌کند. اگر رنگ یا استایل اشتباه باشد، از تاریخچه اکشن می‌توانید برگردانید.', 'agent-wp' );
			$token   = Agent_WP_Pending::store(
				'custom_css',
				array_merge( $args, array( 'confirmed' => true ) ),
				array(
					'mode'    => $mode,
					'bytes'   => strlen( $css ),
					'preview' => $preview,
					'warning' => $warning,
				)
			);
			return Agent_WP_Tool_Result::success(
				__( 'برای ادامه تأیید کنید.', 'agent-wp' ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'warning'      => $warning,
					'highRisk'     => true,
					'preview'      => $preview,
					'mode'         => $mode,
					'editUrl'      => admin_url( 'customize.php?autofocus[section]=custom_css' ),
				)
			);
		}

		$result = wp_update_custom_css_post( $css );
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}

		$live = (string) wp_get_custom_css();
		return Agent_WP_Tool_Result::success(
			__( 'CSS سفارشی ذخیره شد.', 'agent-wp' ),
			array(
				'css'       => $css,
				'bytes'     => strlen( $css ),
				'verified'  => trim( $live ) === trim( $css ) || ( '' !== trim( $css ) && false !== strpos( $live, trim( $css ) ) ),
				'liveBytes' => strlen( $live ),
				'editUrl'   => admin_url( 'customize.php?autofocus[section]=custom_css' ),
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		$css    = isset( $before['css'] ) ? (string) $before['css'] : '';
		$result = wp_update_custom_css_post( $css );
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}
		return Agent_WP_Tool_Result::success( __( 'CSS سفارشی به حالت قبل برگشت.', 'agent-wp' ) );
	}
}
