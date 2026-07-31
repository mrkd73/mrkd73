<?php
/**
 * لیست فایل‌های sandbox (قالب / workspace).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_List_Files implements Agent_WP_Tool_Interface {

	public function id() {
		return 'list_files';
	}

	public function label() {
		return __( 'لیست فایل‌ها', 'agent-wp' );
	}

	public function description() {
		return 'List files under theme/, parent/, themes/, plugin/, plugins/, mu-plugin/, or workspace/. Depth up to 5.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'  => array(
					'type'        => 'string',
					'description' => 'e.g. theme/, themes/, plugin/, plugin/woocommerce/, workspace/',
					'default'     => 'theme/',
				),
				'depth' => array( 'type' => 'integer', 'default' => 2 ),
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
		$path  = isset( $args['path'] ) ? (string) $args['path'] : 'theme/';
		$depth = isset( $args['depth'] ) ? absint( $args['depth'] ) : 2;
		$list  = Agent_WP_Fs::list_dir( $path, $depth );
		if ( ! $list ) {
			return Agent_WP_Tool_Result::success( __( 'فایلی یافت نشد.', 'agent-wp' ), array( 'files' => array() ) );
		}
		$lines = array();
		foreach ( array_slice( $list, 0, 80 ) as $item ) {
			$lines[] = sprintf( '%s %s', $item['type'] === 'dir' ? '[dir]' : '[file]', $item['path'] );
		}
		return Agent_WP_Tool_Result::success( implode( "\n", $lines ), array( 'files' => $list ) );
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی.', 'agent-wp' ) );
	}
}
