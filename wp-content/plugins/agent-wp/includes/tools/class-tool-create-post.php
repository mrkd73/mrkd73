<?php
/**
 * Tool ساخت نوشته (پست) — با rollback (حذف نوشته).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Create_Post implements Agent_WP_Tool_Interface {

	public function id() {
		return 'create_post';
	}

	public function label() {
		return __( 'ساخت نوشته', 'agent-wp' );
	}

	public function description() {
		return 'Create a WordPress blog post (default status: draft).';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending' ) ),
			),
			'required'   => array( 'title' ),
		);
	}

	public function can_run( array $args ) {
		$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		return '' !== $title && current_user_can( 'publish_posts' );
	}

	public function snapshot_before( array $args ) {
		return array( 'post_id' => 0 );
	}

	public function run( array $args ) {
		$title   = sanitize_text_field( $args['title'] ?? '' );
		$content = isset( $args['content'] ) ? wp_kses_post( (string) $args['content'] ) : '';
		$status  = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) {
			$status = 'draft';
		}

		if ( '' === $title ) {
			return Agent_WP_Tool_Result::error( __( 'عنوان نوشته خالی است.', 'agent-wp' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return Agent_WP_Tool_Result::error( $post_id->get_error_message() );
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		$view_link = get_permalink( $post_id );

		return Agent_WP_Tool_Result::success(
			sprintf(
				__( 'نوشته «%1$s» به‌صورت %2$s ساخته شد.', 'agent-wp' ),
				$title,
				'draft' === $status ? __( 'پیش‌نویس', 'agent-wp' ) : __( 'منتشرشده', 'agent-wp' )
			),
			array(
				'postId'  => (int) $post_id,
				'title'   => $title,
				'status'  => $status,
				'editUrl' => $edit_link ? $edit_link : '',
				'viewUrl' => $view_link ? $view_link : '',
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}

		$after = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $after ) || empty( $after['postId'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'شناسه نوشته در لاگ نیست.', 'agent-wp' ) );
		}

		$post_id = (int) $after['postId'];
		$post    = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return Agent_WP_Tool_Result::error( __( 'نوشته دیگر وجود ندارد.', 'agent-wp' ) );
		}

		if ( ! wp_delete_post( $post_id, true ) ) {
			return Agent_WP_Tool_Result::error( __( 'حذف نوشته ناموفق بود.', 'agent-wp' ) );
		}

		return Agent_WP_Tool_Result::success(
			__( 'نوشته ساخته‌شده حذف شد (rollback).', 'agent-wp' ),
			array( 'postId' => $post_id )
		);
	}
}
