<?php
/**
 * ویرایش نوشته/برگه موجود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Update_Content implements Agent_WP_Tool_Interface {

	public function id() {
		return 'update_content';
	}

	public function label() {
		return __( 'ویرایش محتوا', 'agent-wp' );
	}

	public function description() {
		return 'Update an existing WordPress post or page (title, content, status, excerpt).';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer', 'description' => 'Post/page ID' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ) ),
				'excerpt' => array( 'type' => 'string' ),
			),
			'required'   => array( 'id' ),
		);
	}

	public function can_run( array $args ) {
		$id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		if ( ! $id ) {
			return false;
		}
		$post = get_post( $id );
		return $post && current_user_can( 'edit_post', $id );
	}

	public function snapshot_before( array $args ) {
		$id   = absint( $args['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return array();
		}
		return array(
			'id'      => $id,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'excerpt' => $post->post_excerpt,
		);
	}

	public function run( array $args ) {
		$id = absint( $args['id'] ?? 0 );
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['content'] );
		}
		if ( isset( $args['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}
		if ( isset( $args['status'] ) ) {
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				$update['post_status'] = $status;
			}
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}

		$post = get_post( $id );
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'محتوا #%d به‌روز شد.', 'agent-wp' ), $id ),
			array(
				'id'      => $id,
				'title'   => $post ? $post->post_title : '',
				'status'  => $post ? $post->post_status : '',
				'editUrl' => get_edit_post_link( $id, 'raw' ),
				'viewUrl' => get_permalink( $id ),
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || empty( $before['id'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'snapshot قبلی موجود نیست.', 'agent-wp' ) );
		}
		$result = wp_update_post(
			array(
				'ID'           => (int) $before['id'],
				'post_title'   => $before['title'],
				'post_content' => $before['content'],
				'post_status'  => $before['status'],
				'post_excerpt' => $before['excerpt'],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}
		return Agent_WP_Tool_Result::success( __( 'محتوا به حالت قبل برگشت.', 'agent-wp' ), array( 'id' => (int) $before['id'] ) );
	}
}
