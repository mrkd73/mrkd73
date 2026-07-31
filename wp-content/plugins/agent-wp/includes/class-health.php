<?php
/**
 * سلامت افزونه — وضعیت درگاه، جداول، Cron، sandbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Health {

	public static function report() {
		global $wpdb;
		$tables = array(
			'chats'       => $wpdb->prefix . 'agent_wp_chats',
			'messages'    => $wpdb->prefix . 'agent_wp_messages',
			'models'      => $wpdb->prefix . 'agent_wp_models',
			'action_log'  => $wpdb->prefix . 'agent_wp_action_log',
			'suggestions' => $wpdb->prefix . 'agent_wp_suggestions',
		);
		$table_ok = array();
		foreach ( $tables as $key => $name ) {
			$table_ok[ $key ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name );
		}

		$key_ok = (bool) Agent_WP_Models::get_gapgpt_key();
		$models = Agent_WP_Models::list_public();
		$cron   = wp_next_scheduled( Agent_WP_Assistant::CRON_HOOK );

		$sandbox = array();
		foreach ( Agent_WP_Fs::roots() as $path ) {
			$sandbox[] = array(
				'path'     => $path,
				'exists'   => is_dir( $path ),
				'writable' => is_dir( $path ) && is_writable( $path ),
			);
		}

		$checks = array(
			'tables'          => $table_ok,
			'gapgptKey'       => $key_ok,
			'modelCount'      => is_array( $models ) ? count( $models ) : 0,
			'assistantOn'     => Agent_WP_Assistant::is_enabled(),
			'assistantQuiet'  => Agent_WP_Assistant::is_quiet_now(),
			'quietEnabled'    => Agent_WP_Assistant::quiet_enabled(),
			'scanInterval'    => Agent_WP_Assistant::get_interval_hours(),
			'lastScan'        => (int) get_option( Agent_WP_Assistant::OPTION_LAST_SCAN, 0 ),
			'lastScanLabel'   => Agent_WP_Assistant::last_scan_label(),
			'openSuggestions' => Agent_WP_Assistant::count_open(),
			'categoryCounts'  => Agent_WP_Assistant::category_counts(),
			'cronNext'        => $cron ? (int) $cron : 0,
			'pendingCount'    => Agent_WP_Pending::count_all(),
			'sandbox'         => $sandbox,
			'multisite'       => is_multisite(),
			'blogId'          => get_current_blog_id(),
			'debug'           => Agent_WP_Debug::enabled(),
			'blockedAttempts' => (int) get_option( 'agent_wp_blocked_attempts', 0 ),
			'version'         => AGENT_WP_VERSION,
			'dbVersion'       => AGENT_WP_DB_VERSION,
			'php'             => PHP_VERSION,
			'wp'              => get_bloginfo( 'version' ),
		);

		$ok = $key_ok && ! in_array( false, $table_ok, true );
		return array(
			'ok'      => $ok,
			'checks'  => $checks,
			'summary' => $ok
				? __( 'Agent WP سالم به نظر می‌رسد.', 'agent-wp' )
				: __( 'چند مورد نیاز به توجه دارد (کلید یا جداول).', 'agent-wp' ),
		);
	}
}
