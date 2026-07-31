<?php
/**
 * جستجو و جایگزینی در محتوا.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Search_Replace implements Agent_WP_Tool_Interface {

	public function id() {
		return 'search_replace';
	}

	public function label() {
		return __( 'جستجو و جایگزینی', 'agent-wp' );
	}

	public function description() {
		return 'Search and replace text inside a post/page content. Requires confirm for apply. Modes: preview, apply.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'search'      => array( 'type' => 'string' ),
				'replace'     => array( 'type' => 'string' ),
				'mode'        => array( 'type' => 'string', 'enum' => array( 'preview', 'apply' ), 'default' => 'preview' ),
				'confirmed'   => array( 'type' => 'boolean', 'description' => 'Must be true for apply' ),
			),
			'required'   => array( 'post_id', 'search' ),
		);
	}

	public function can_run( array $args ) {
		$id = absint( $args['post_id'] ?? 0 );
		return $id && current_user_can( 'edit_post', $id );
	}

	public function snapshot_before( array $args ) {
		$id   = absint( $args['post_id'] ?? 0 );
		$post = get_post( $id );
		return $post ? array( 'id' => $id, 'content' => $post->post_content ) : array();
	}

	public function run( array $args ) {
		$id      = absint( $args['post_id'] );
		$search  = (string) ( $args['search'] ?? '' );
		$replace = (string) ( $args['replace'] ?? '' );
		$mode    = sanitize_key( $args['mode'] ?? 'preview' );
		$post    = get_post( $id );

		if ( ! $post ) {
			return Agent_WP_Tool_Result::error( __( 'محتوا یافت نشد.', 'agent-wp' ) );
		}
		if ( '' === $search ) {
			return Agent_WP_Tool_Result::error( __( 'عبارت جستجو خالی است.', 'agent-wp' ) );
		}

		$count   = 0;
		$new     = str_replace( $search, $replace, $post->post_content, $count );
		$preview = array(
			'post_id' => $id,
			'search'  => $search,
			'replace' => $replace,
			'count'   => $count,
			'title'   => $post->post_title,
		);

		if ( 'apply' !== $mode ) {
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'پیش‌نمایش: %d مورد پیدا شد در «%s». برای اعمال mode=apply و confirmed=true بفرست.', 'agent-wp' ), $count, $post->post_title ),
				array_merge( $preview, array( 'previewOnly' => true ) )
			);
		}

		if ( empty( $args['confirmed'] ) ) {
			$token = Agent_WP_Pending::store(
				'search_replace',
				array_merge( $args, array( 'mode' => 'apply', 'confirmed' => true ) ),
				$preview
			);
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'نیاز به تأیید: جایگزینی %d مورد در «%s».', 'agent-wp' ), $count, $post->post_title ),
				array_merge(
					$preview,
					array(
						'needsConfirm' => true,
						'confirmToken' => $token,
					)
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $new,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}

		return Agent_WP_Tool_Result::success(
			sprintf( __( '%d جایگزینی در #%d انجام شد.', 'agent-wp' ), $count, $id ),
			array_merge(
				$preview,
				array(
					'editUrl' => get_edit_post_link( $id, 'raw' ),
				)
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( empty( $before['id'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'snapshot نیست.', 'agent-wp' ) );
		}
		wp_update_post(
			array(
				'ID'           => (int) $before['id'],
				'post_content' => $before['content'],
			)
		);
		return Agent_WP_Tool_Result::success( __( 'محتوا به قبل برگشت.', 'agent-wp' ) );
	}
}
