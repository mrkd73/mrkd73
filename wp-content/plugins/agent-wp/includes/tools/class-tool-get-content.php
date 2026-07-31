<?php
/**
 * خواندن یک نوشته/برگه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Get_Content implements Agent_WP_Tool_Interface {

	public function id() {
		return 'get_content';
	}

	public function label() {
		return __( 'خواندن محتوا', 'agent-wp' );
	}

	public function description() {
		return 'Get a WordPress post or page by ID (title, content, status, type, urls).';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Post or page ID' ),
			),
			'required'   => array( 'id' ),
		);
	}

	public function can_run( array $args ) {
		return ! empty( $args['id'] ) && current_user_can( 'edit_posts' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$id   = absint( $args['id'] );
		$post = get_post( $id );
		if ( ! $post ) {
			return Agent_WP_Tool_Result::error( __( 'محتوا یافت نشد.', 'agent-wp' ) );
		}
		$data = array(
			'id'      => (int) $post->ID,
			'type'    => $post->post_type,
			'title'   => $post->post_title,
			'status'  => $post->post_status,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'editUrl' => get_edit_post_link( $post->ID, 'raw' ),
			'viewUrl' => get_permalink( $post->ID ),
		);
		$msg = sprintf( "#%d [%s] %s\n%s", $data['id'], $data['status'], $data['title'], mb_substr( wp_strip_all_tags( $data['content'] ), 0, 400 ) );
		return Agent_WP_Tool_Result::success( $msg, $data );
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی — rollback لازم نیست.', 'agent-wp' ) );
	}
}
