<?php
/**
 * Tool لیست برگه‌های وردپرس — خواندنی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_List_Pages implements Agent_WP_Tool_Interface {

	public function id() {
		return 'list_pages';
	}

	public function label() {
		return __( 'لیست برگه‌ها', 'agent-wp' );
	}

	public function description() {
		return 'List WordPress pages with optional search.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search' => array( 'type' => 'string' ),
				'limit'  => array( 'type' => 'integer', 'default' => 10 ),
			),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'edit_pages' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 10;
		$limit = max( 1, min( 50, $limit ) );
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		$query = array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$query['s'] = $search;
		}

		$pages = get_posts( $query );
		$list  = array();
		$lines = array();
		foreach ( $pages as $page ) {
			$item = array(
				'id'     => (int) $page->ID,
				'title'  => get_the_title( $page ),
				'status' => $page->post_status,
				'editUrl'=> get_edit_post_link( $page->ID, 'raw' ),
				'viewUrl'=> get_permalink( $page->ID ),
			);
			$list[]  = $item;
			$lines[] = sprintf( '#%d %s [%s]', $item['id'], $item['title'], $item['status'] );
		}

		if ( ! $lines ) {
			return Agent_WP_Tool_Result::success( __( 'برگه‌ای یافت نشد.', 'agent-wp' ), array( 'pages' => array() ) );
		}

		return Agent_WP_Tool_Result::success(
			__( 'آخرین برگه‌ها:', 'agent-wp' ) . "\n" . implode( "\n", $lines ),
			array( 'pages' => $list )
		);
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'این اکشن فقط خواندنی است.', 'agent-wp' ) );
	}
}
