<?php
/**
 * رسانه: لیست و تنظیم تصویر شاخص.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Media implements Agent_WP_Tool_Interface {

	public function id() {
		return 'media';
	}

	public function label() {
		return __( 'رسانه', 'agent-wp' );
	}

	public function description() {
		return 'List media library items or set/remove featured image on a post/page. Modes: list, set_featured, remove_featured.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'    => array( 'type' => 'string', 'enum' => array( 'list', 'set_featured', 'remove_featured' ) ),
				'search'  => array( 'type' => 'string' ),
				'limit'   => array( 'type' => 'integer', 'default' => 10 ),
				'post_id' => array( 'type' => 'integer' ),
				'media_id'=> array( 'type' => 'integer' ),
			),
			'required'   => array( 'mode' ),
		);
	}

	public function can_run( array $args ) {
		$mode = isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : '';
		if ( 'list' === $mode ) {
			return current_user_can( 'upload_files' );
		}
		$post_id = absint( $args['post_id'] ?? 0 );
		return $post_id && current_user_can( 'edit_post', $post_id );
	}

	public function snapshot_before( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? '' );
		if ( 'set_featured' !== $mode && 'remove_featured' !== $mode ) {
			return array();
		}
		$post_id = absint( $args['post_id'] ?? 0 );
		return array(
			'post_id'     => $post_id,
			'thumbnailId' => (int) get_post_thumbnail_id( $post_id ),
		);
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'list' );

		if ( 'list' === $mode ) {
			$limit = isset( $args['limit'] ) ? max( 1, min( 30, absint( $args['limit'] ) ) ) : 10;
			$q     = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			if ( ! empty( $args['search'] ) ) {
				$q['s'] = sanitize_text_field( $args['search'] );
			}
			$items = get_posts( $q );
			$list  = array();
			$lines = array();
			foreach ( $items as $att ) {
				$url     = wp_get_attachment_url( $att->ID );
				$list[]  = array(
					'id'    => (int) $att->ID,
					'title' => get_the_title( $att ),
					'url'   => $url ? $url : '',
					'mime'  => $att->post_mime_type,
				);
				$lines[] = sprintf( '#%d %s', $att->ID, get_the_title( $att ) );
			}
			if ( ! $lines ) {
				return Agent_WP_Tool_Result::success( __( 'رسانه‌ای یافت نشد.', 'agent-wp' ), array( 'media' => array() ) );
			}
			return Agent_WP_Tool_Result::success( __( 'رسانه‌ها:', 'agent-wp' ) . "\n" . implode( "\n", $lines ), array( 'media' => $list ) );
		}

		$post_id = absint( $args['post_id'] ?? 0 );
		if ( 'remove_featured' === $mode ) {
			delete_post_thumbnail( $post_id );
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'تصویر شاخص پست #%d حذف شد.', 'agent-wp' ), $post_id ),
				array( 'post_id' => $post_id, 'thumbnailId' => 0 )
			);
		}

		$media_id = absint( $args['media_id'] ?? 0 );
		if ( ! $media_id || 'attachment' !== get_post_type( $media_id ) ) {
			return Agent_WP_Tool_Result::error( __( 'شناسه رسانه نامعتبر است.', 'agent-wp' ) );
		}
		set_post_thumbnail( $post_id, $media_id );
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'تصویر شاخص پست #%d روی رسانه #%d تنظیم شد.', 'agent-wp' ), $post_id, $media_id ),
			array(
				'post_id'      => $post_id,
				'thumbnailId'  => $media_id,
				'editUrl'      => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || empty( $before['post_id'] ) ) {
			return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
		}
		$post_id = (int) $before['post_id'];
		$thumb   = (int) ( $before['thumbnailId'] ?? 0 );
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
		} else {
			delete_post_thumbnail( $post_id );
		}
		return Agent_WP_Tool_Result::success( __( 'تصویر شاخص به حالت قبل برگشت.', 'agent-wp' ) );
	}
}
