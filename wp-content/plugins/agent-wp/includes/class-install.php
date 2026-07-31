<?php
/**
 * نصب/ارتقا جداول.
 * چرا: لاگ اکشن و قابلیت rollback نیاز به persistence پایدار دارد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Install {

	public static function activate() {
		self::create_tables();
		update_option( 'agent_wp_db_version', AGENT_WP_DB_VERSION, false );
		set_transient( 'agent_wp_show_health_notice', 1, DAY_IN_SECONDS );
	}

	public static function maybe_upgrade() {
		$current = get_option( 'agent_wp_db_version', '' );
		if ( (string) $current === (string) AGENT_WP_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'agent_wp_db_version', AGENT_WP_DB_VERSION, false );
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$models  = $wpdb->prefix . 'agent_wp_models';
		$logs    = $wpdb->prefix . 'agent_wp_action_log';
		$chats   = $wpdb->prefix . 'agent_wp_chats';
		$msgs    = $wpdb->prefix . 'agent_wp_messages';

		$sql_models = "CREATE TABLE {$models} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(50) NOT NULL DEFAULT '',
			model_name varchar(191) NOT NULL DEFAULT '',
			api_key_enc longtext NOT NULL,
			base_url varchar(255) NOT NULL DEFAULT '',
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY provider (provider)
		) {$charset};";

		$sql_logs = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool_id varchar(100) NOT NULL DEFAULT '',
			action_name varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			args_json longtext NULL,
			before_json longtext NULL,
			after_json longtext NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			rolled_back_at datetime NULL,
			PRIMARY KEY  (id),
			KEY tool_id (tool_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset};";

		$sql_chats = "CREATE TABLE {$chats} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql_msgs = "CREATE TABLE {$msgs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chat_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(20) NOT NULL DEFAULT 'user',
			content longtext NOT NULL,
			meta_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY chat_id (chat_id),
			KEY role (role)
		) {$charset};";

		$suggestions = $wpdb->prefix . 'agent_wp_suggestions';
		$sql_suggestions = "CREATE TABLE {$suggestions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fingerprint varchar(64) NOT NULL DEFAULT '',
			category varchar(40) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			priority tinyint(3) unsigned NOT NULL DEFAULT 5,
			status varchar(20) NOT NULL DEFAULT 'open',
			meta_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY status (status),
			KEY category (category),
			KEY priority (priority)
		) {$charset};";

		dbDelta( $sql_models );
		dbDelta( $sql_logs );
		dbDelta( $sql_chats );
		dbDelta( $sql_msgs );
		dbDelta( $sql_suggestions );
	}
}
