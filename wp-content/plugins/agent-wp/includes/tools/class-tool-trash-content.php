<?php
/**
 * انتقال محتوا به زباله‌دان (با rollback = بازیابی).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Trash_Content implements Agent_WP_Tool_Interface {

	public function id() {
		return 'trash_content';
	}

	public function label() {
		return __( 'حذف به زباله‌دان', 'agent-wp' );
	}

	public function description() {
		return 'Move a post/page to trash. Prefer this over permanent delete.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id' ),
		);
	}

	public function can_run( array $args ) {
		$id = absint( $args['id'] ?? 0 );
		return $id && current_user_can( 'delete_post', $id );
	}

	public function snapshot_before( array $args ) {
		$id   = absint( $args['id'] ?? 0 );
		$post = get_post( $id );
		return $post ? array( 'id' => $id, 'status' => $post->post_status ) : array();
	}

	public function run( array $args ) {
		$id = absint( $args['id'] );
		$post = get_post( $id );
		if ( ! $post ) {
			return Agent_WP_Tool_Result::error( __( 'محتوا یافت نشد.', 'agent-wp' ) );
		}

		if ( empty( $args['confirmed'] ) ) {
			$token = Agent_WP_Pending::store(
				'trash_content',
				array_merge( $args, array( 'confirmed' => true ) ),
				array(
					'id'     => $id,
					'title'  => $post->post_title,
					'type'   => $post->post_type,
					'status' => $post->post_status,
				)
			);
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'نیاز به تأیید: «%s» (#%d) به زباله‌دان برود؟', 'agent-wp' ), $post->post_title, $id ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'id'           => $id,
					'title'        => $post->post_title,
				)
			);
		}

		$r = wp_trash_post( $id );
		if ( ! $r ) {
			return Agent_WP_Tool_Result::error( __( 'انتقال به زباله‌دان ناموفق بود.', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'محتوا #%d به زباله‌دان رفت.', 'agent-wp' ), $id ),
			array( 'id' => $id )
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		$id     = isset( $before['id'] ) ? (int) $before['id'] : 0;
		if ( ! $id ) {
			return Agent_WP_Tool_Result::error( __( 'شناسه در لاگ نیست.', 'agent-wp' ) );
		}
		$r = wp_untrash_post( $id );
		if ( ! $r ) {
			return Agent_WP_Tool_Result::error( __( 'بازیابی ناموفق بود.', 'agent-wp' ) );
		}
		if ( ! empty( $before['status'] ) && 'trash' !== $before['status'] ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => sanitize_key( $before['status'] ),
				)
			);
		}
		return Agent_WP_Tool_Result::success( __( 'محتوا از زباله‌دان بازیابی شد.', 'agent-wp' ), array( 'id' => $id ) );
	}
}
