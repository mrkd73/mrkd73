<?php
/**
 * پاکسازی هنگام حذف افزونه.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'agent_wp_assistant_enabled',
	'agent_wp_assistant_topic',
	'agent_wp_assistant_last_scan',
	'agent_wp_assistant_fab_side',
	'agent_wp_assistant_interval',
	'agent_wp_assistant_quiet',
	'agent_wp_assistant_notify',
	'agent_wp_assistant_sound',
	'agent_wp_assistant_surface',
	'agent_wp_assistant_note',
	'agent_wp_assistant_max_open',
	'agent_wp_assistant_modules',
	'agent_wp_assistant_digest_mail',
	'agent_wp_debug',
	'agent_wp_blocked_attempts',
	'agent_wp_pending_actions',
	'agent_wp_db_version',
	'agent_wp_gapgpt_endpoint',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

$tables = array(
	$wpdb->prefix . 'agent_wp_chats',
	$wpdb->prefix . 'agent_wp_messages',
	$wpdb->prefix . 'agent_wp_models',
	$wpdb->prefix . 'agent_wp_action_log',
	$wpdb->prefix . 'agent_wp_suggestions',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$ts = wp_next_scheduled( 'agent_wp_assistant_scan' );
while ( $ts ) {
	wp_unschedule_event( $ts, 'agent_wp_assistant_scan' );
	$ts = wp_next_scheduled( 'agent_wp_assistant_scan' );
}

$ts = wp_next_scheduled( 'agent_wp_assistant_digest_mail' );
while ( $ts ) {
	wp_unschedule_event( $ts, 'agent_wp_assistant_digest_mail' );
	$ts = wp_next_scheduled( 'agent_wp_assistant_digest_mail' );
}
