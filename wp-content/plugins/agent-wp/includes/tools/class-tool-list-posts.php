<?php
/**
 * لیست نوشته‌ها / انواع پست.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_List_Posts implements Agent_WP_Tool_Interface {

	public function id() {
		return 'list_posts';
	}

	public function label() {
		return __( 'لیست نوشته‌ها', 'agent-wp' );
	}

	public function description() {
		return 'List WordPress posts (or other post types) with optional search.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'description' => 'post, page, or custom type', 'default' => 'post' ),
				'search'    => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string', 'default' => 'any' ),
				'limit'     => array( 'type' => 'integer', 'default' => 10 ),
			),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'edit_posts' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 10;
		$limit = max( 1, min( 50, $limit ) );
		$type  = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( '' === $type ) {
			$type = 'post';
		}

		$query = array(
			'post_type'      => $type,
			'post_status'    => isset( $args['status'] ) && 'any' !== $args['status']
				? sanitize_key( $args['status'] )
				: array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}

		$posts = get_posts( $query );
		$list  = array();
		$lines = array();
		foreach ( $posts as $post ) {
			$item    = array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
				'type'   => $post->post_type,
			);
			$list[]  = $item;
			$lines[] = sprintf( '#%d %s [%s]', $item['id'], $item['title'], $item['status'] );
		}

		if ( ! $lines ) {
			return Agent_WP_Tool_Result::success( __( 'موردی یافت نشد.', 'agent-wp' ), array( 'posts' => array() ) );
		}

		return Agent_WP_Tool_Result::success(
			__( 'نتایج:', 'agent-wp' ) . "\n" . implode( "\n", $lines ),
			array( 'posts' => $list )
		);
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'خواندنی — rollback لازم نیست.', 'agent-wp' ) );
	}
}
