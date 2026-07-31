<?php
/**
 * Tool ساخت برگه وردپرس — با امکان rollback (حذف برگه ساخته‌شده).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Create_Page implements Agent_WP_Tool_Interface {

	public function id() {
		return 'create_page';
	}

	public function label() {
		return __( 'ساخت برگه', 'agent-wp' );
	}

	public function description() {
		return 'Create a WordPress page (default status: draft).';
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
		return '' !== $title && current_user_can( 'publish_pages' );
	}

	public function snapshot_before( array $args ) {
		return array(
			'page_id' => 0,
		);
	}

	public function run( array $args ) {
		$title   = sanitize_text_field( $args['title'] ?? '' );
		$content = isset( $args['content'] ) ? wp_kses_post( (string) $args['content'] ) : '';
		$status  = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending' ), true ) ) {
			$status = 'draft';
		}

		if ( '' === $title ) {
			return Agent_WP_Tool_Result::error( __( 'عنوان برگه خالی است.', 'agent-wp' ) );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return Agent_WP_Tool_Result::error( $page_id->get_error_message() );
		}

		$edit_link = get_edit_post_link( $page_id, 'raw' );
		$view_link = get_permalink( $page_id );

		return Agent_WP_Tool_Result::success(
			sprintf(
				/* translators: 1: page title, 2: status */
				__( 'برگه «%1$s» به‌صورت %2$s ساخته شد.', 'agent-wp' ),
				$title,
				'draft' === $status ? __( 'پیش‌نویس', 'agent-wp' ) : __( 'منتشرشده', 'agent-wp' )
			),
			array(
				'pageId'   => (int) $page_id,
				'title'    => $title,
				'status'   => $status,
				'editUrl'  => $edit_link ? $edit_link : '',
				'viewUrl'  => $view_link ? $view_link : '',
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}

		$after = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $after ) || empty( $after['pageId'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'شناسه برگه در لاگ نیست.', 'agent-wp' ) );
		}

		$page_id = (int) $after['pageId'];
		$post    = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return Agent_WP_Tool_Result::error( __( 'برگه دیگر وجود ندارد.', 'agent-wp' ) );
		}

		$deleted = wp_delete_post( $page_id, true );
		if ( ! $deleted ) {
			return Agent_WP_Tool_Result::error( __( 'حذف برگه ناموفق بود.', 'agent-wp' ) );
		}

		return Agent_WP_Tool_Result::success(
			__( 'برگه ساخته‌شده حذف شد (rollback).', 'agent-wp' ),
			array( 'pageId' => $page_id )
		);
	}
}
