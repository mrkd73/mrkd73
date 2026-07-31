<?php
/**
 * لیست افزونه‌ها و قالب‌ها — خواندنی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Plugins_Themes implements Agent_WP_Tool_Interface {

	public function id() {
		return 'plugins_themes';
	}

	public function label() {
		return __( 'افزونه‌ها و قالب‌ها', 'agent-wp' );
	}

	public function description() {
		return 'List installed plugins and themes (read-only). Modes: plugins, themes, both.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode' => array( 'type' => 'string', 'enum' => array( 'plugins', 'themes', 'both' ), 'default' => 'both' ),
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
		$mode = sanitize_key( $args['mode'] ?? 'both' );
		$data = array();
		$msg  = array();

		if ( 'plugins' === $mode || 'both' === $mode ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all      = get_plugins();
			$active   = (array) get_option( 'active_plugins', array() );
			$plugins  = array();
			$lines    = array();
			foreach ( $all as $file => $info ) {
				$is_on     = in_array( $file, $active, true );
				$plugins[] = array(
					'file'    => $file,
					'name'    => $info['Name'],
					'version' => $info['Version'],
					'active'  => $is_on,
				);
				$lines[] = sprintf( '%s %s v%s', $is_on ? '[ON]' : '[OFF]', $info['Name'], $info['Version'] );
			}
			$data['plugins'] = $plugins;
			$msg[]           = __( 'افزونه‌ها:', 'agent-wp' ) . "\n" . implode( "\n", array_slice( $lines, 0, 40 ) );
		}

		if ( 'themes' === $mode || 'both' === $mode ) {
			$themes  = wp_get_themes();
			$current = get_stylesheet();
			$list    = array();
			$lines   = array();
			foreach ( $themes as $slug => $theme ) {
				$list[]  = array(
					'slug'    => $slug,
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'active'  => $slug === $current,
				);
				$lines[] = sprintf( '%s %s v%s', $slug === $current ? '[ACTIVE]' : '', $theme->get( 'Name' ), $theme->get( 'Version' ) );
			}
			$data['themes'] = $list;
			$msg[]          = __( 'قالب‌ها:', 'agent-wp' ) . "\n" . implode( "\n", $lines );
		}

		return Agent_WP_Tool_Result::success( implode( "\n\n", $msg ), $data );
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی.', 'agent-wp' ) );
	}
}
