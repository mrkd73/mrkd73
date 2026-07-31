<?php
/**
 * مدیریت دسته و برچسب.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Terms implements Agent_WP_Tool_Interface {

	public function id() {
		return 'terms';
	}

	public function label() {
		return __( 'دسته و برچسب', 'agent-wp' );
	}

	public function description() {
		return 'List, create, or assign categories/tags. Modes: list, create, assign. Taxonomies: category, post_tag.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'       => array( 'type' => 'string', 'enum' => array( 'list', 'create', 'assign' ) ),
				'taxonomy'   => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ), 'default' => 'category' ),
				'search'     => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'post_id'    => array( 'type' => 'integer' ),
				'terms'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Term names for assign' ),
				'append'     => array( 'type' => 'boolean', 'default' => true ),
			),
			'required'   => array( 'mode' ),
		);
	}

	public function can_run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? '' );
		if ( 'list' === $mode || 'create' === $mode ) {
			return current_user_can( 'manage_categories' );
		}
		$post_id = absint( $args['post_id'] ?? 0 );
		return $post_id && current_user_can( 'edit_post', $post_id );
	}

	public function snapshot_before( array $args ) {
		if ( 'assign' !== sanitize_key( $args['mode'] ?? '' ) ) {
			return array();
		}
		$post_id  = absint( $args['post_id'] ?? 0 );
		$taxonomy = self::tax( $args );
		$terms    = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		return array(
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'term_ids' => is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ),
		);
	}

	private static function tax( array $args ) {
		$tax = sanitize_key( $args['taxonomy'] ?? 'category' );
		return in_array( $tax, array( 'category', 'post_tag' ), true ) ? $tax : 'category';
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'list' );
		$tax  = self::tax( $args );

		if ( 'list' === $mode ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'number'     => 40,
					'search'     => isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
				)
			);
			if ( is_wp_error( $terms ) ) {
				return Agent_WP_Tool_Result::error( $terms->get_error_message() );
			}
			$list  = array();
			$lines = array();
			foreach ( $terms as $t ) {
				$list[]  = array(
					'id'    => (int) $t->term_id,
					'name'  => $t->name,
					'slug'  => $t->slug,
					'count' => (int) $t->count,
				);
				$lines[] = sprintf( '#%d %s (%d)', $t->term_id, $t->name, $t->count );
			}
			return Agent_WP_Tool_Result::success(
				$lines ? implode( "\n", $lines ) : __( 'موردی نیست.', 'agent-wp' ),
				array( 'terms' => $list, 'taxonomy' => $tax )
			);
		}

		if ( 'create' === $mode ) {
			$name = sanitize_text_field( $args['name'] ?? '' );
			if ( '' === $name ) {
				return Agent_WP_Tool_Result::error( __( 'نام لازم است.', 'agent-wp' ) );
			}
			$r = wp_insert_term( $name, $tax );
			if ( is_wp_error( $r ) ) {
				return Agent_WP_Tool_Result::error( $r->get_error_message() );
			}
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'ترم «%s» ساخته شد (#%d).', 'agent-wp' ), $name, (int) $r['term_id'] ),
				array(
					'termId'   => (int) $r['term_id'],
					'taxonomy' => $tax,
					'name'     => $name,
				)
			);
		}

		$post_id = absint( $args['post_id'] ?? 0 );
		$names   = isset( $args['terms'] ) && is_array( $args['terms'] ) ? $args['terms'] : array();
		$names   = array_filter( array_map( 'sanitize_text_field', $names ) );
		if ( ! $names ) {
			return Agent_WP_Tool_Result::error( __( 'لیست ترم خالی است.', 'agent-wp' ) );
		}
		$append = ! isset( $args['append'] ) || $args['append'];
		$r      = wp_set_object_terms( $post_id, $names, $tax, (bool) $append );
		if ( is_wp_error( $r ) ) {
			return Agent_WP_Tool_Result::error( $r->get_error_message() );
		}
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'ترم‌ها برای پست #%d تنظیم شد.', 'agent-wp' ), $post_id ),
			array(
				'post_id'  => $post_id,
				'taxonomy' => $tax,
				'term_ids' => array_map( 'intval', (array) $r ),
				'editUrl'  => get_edit_post_link( $post_id, 'raw' ),
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
		wp_set_object_terms( (int) $before['post_id'], $before['term_ids'], $before['taxonomy'], false );
		return Agent_WP_Tool_Result::success( __( 'ترم‌ها به حالت قبل برگشت.', 'agent-wp' ) );
	}
}
