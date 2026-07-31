<?php
/**
 * Tool نمونه برای اثبات معماری لاگ/rollback — بدون تغییر سایت.
 * چرا ping: زیرساخت را تست می‌کند بدون ریسک داده.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Ping implements Agent_WP_Tool_Interface {

	public function id() {
		return 'ping';
	}

	public function label() {
		return __( 'پینگ سیستم', 'agent-wp' );
	}

	public function can_run( array $args ) {
		return true;
	}

	public function snapshot_before( array $args ) {
		return array(
			'token' => get_option( 'agent_wp_ping_token', '' ),
		);
	}

	public function run( array $args ) {
		$token = 'ping_' . wp_generate_password( 8, false );
		update_option( 'agent_wp_ping_token', $token, false );
		return Agent_WP_Tool_Result::success(
			__( 'پینگ ثبت شد.', 'agent-wp' ),
			array( 'token' => $token )
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}

		$before = json_decode( (string) $row['before_json'], true );
		$token  = is_array( $before ) && isset( $before['token'] ) ? (string) $before['token'] : '';
		update_option( 'agent_wp_ping_token', $token, false );

		return Agent_WP_Tool_Result::success(
			__( 'پینگ به حالت قبل برگشت.', 'agent-wp' ),
			array( 'token' => $token ),
			(int) $log_id
		);
	}
}
