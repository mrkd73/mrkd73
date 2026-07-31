<?php
/**
 * خواندن فایل از sandbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Read_File implements Agent_WP_Tool_Interface {

	public function id() {
		return 'read_file';
	}

	public function label() {
		return __( 'خواندن فایل', 'agent-wp' );
	}

	public function description() {
		return 'Read a text/code file from theme/, parent/, themes/, plugin/, mu-plugin/, or workspace/. Max 1MB.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path' => array(
					'type'        => 'string',
					'description' => 'Virtual path e.g. theme/style.css or plugin/woocommerce/woocommerce.php',
				),
			),
			'required'   => array( 'path' ),
		);
	}

	public function can_run( array $args ) {
		return ! empty( $args['path'] ) && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$result = Agent_WP_Fs::read( $args['path'] );
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}
		$preview = $result['content'];
		if ( mb_strlen( $preview ) > 12000 ) {
			$preview = mb_substr( $preview, 0, 12000 ) . "\n…";
		}
		return Agent_WP_Tool_Result::success(
			sprintf( __( "فایل %s (%d بایت):\n%s", 'agent-wp' ), $result['path'], $result['size'], $preview ),
			$result
		);
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی.', 'agent-wp' ) );
	}
}
