<?php
/**
 * Tool تغییر عنوان سایت — با rollback به عنوان قبلی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Update_Site_Title implements Agent_WP_Tool_Interface {

	public function id() {
		return 'update_site_title';
	}

	public function label() {
		return __( 'تغییر عنوان سایت', 'agent-wp' );
	}

	public function description() {
		return 'Update the WordPress site title (blogname).';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title' => array( 'type' => 'string' ),
			),
			'required'   => array( 'title' ),
		);
	}

	public function can_run( array $args ) {
		$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		return '' !== $title && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array(
			'title' => (string) get_option( 'blogname', '' ),
		);
	}

	public function run( array $args ) {
		$title = sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return Agent_WP_Tool_Result::error( __( 'عنوان سایت خالی است.', 'agent-wp' ) );
		}

		$before = (string) get_option( 'blogname', '' );
		update_option( 'blogname', $title );

		return Agent_WP_Tool_Result::success(
			sprintf(
				__( 'عنوان سایت از «%1$s» به «%2$s» تغییر کرد.', 'agent-wp' ),
				$before ? $before : '—',
				$title
			),
			array(
				'before' => $before,
				'after'  => $title,
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}

		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || ! array_key_exists( 'title', $before ) ) {
			return Agent_WP_Tool_Result::error( __( 'عنوان قبلی در لاگ نیست.', 'agent-wp' ) );
		}

		$old = (string) $before['title'];
		update_option( 'blogname', $old );

		return Agent_WP_Tool_Result::success(
			sprintf( __( 'عنوان سایت به «%s» برگشت.', 'agent-wp' ), $old ? $old : '—' ),
			array( 'title' => $old )
		);
	}
}
