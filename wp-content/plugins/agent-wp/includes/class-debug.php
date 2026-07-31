<?php
/**
 * حالت دیباگ سرور — بدون افشای کلید در فرانت.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Debug {

	const OPTION = 'agent_wp_debug';

	public static function enabled() {
		return (bool) get_option( self::OPTION, false );
	}

	public static function set_enabled( $on ) {
		update_option( self::OPTION, $on ? 1 : 0, false );
		return (bool) $on;
	}

	public static function log( $message, $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}
		$line = '[Agent WP] ' . (string) $message;
		if ( $context ) {
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
