<?php
/**
 * منوی ناوبری وردپرس.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Menus implements Agent_WP_Tool_Interface {

	public function id() {
		return 'menus';
	}

	public function label() {
		return __( 'منوها', 'agent-wp' );
	}

	public function description() {
		return 'List navigation menus/items or add a custom link / page item to a menu. Modes: list_menus, list_items, add_item.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'     => array( 'type' => 'string', 'enum' => array( 'list_menus', 'list_items', 'add_item' ) ),
				'menu_id'  => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'url'      => array( 'type' => 'string' ),
				'page_id'  => array( 'type' => 'integer' ),
				'type'     => array( 'type' => 'string', 'enum' => array( 'custom', 'page' ), 'default' => 'custom' ),
			),
			'required'   => array( 'mode' ),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'edit_theme_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'list_menus' );

		if ( 'list_menus' === $mode ) {
			$menus = wp_get_nav_menus();
			$list  = array();
			$lines = array();
			foreach ( $menus as $menu ) {
				$list[]  = array(
					'id'    => (int) $menu->term_id,
					'name'  => $menu->name,
					'count' => (int) $menu->count,
				);
				$lines[] = sprintf( '#%d %s (%d آیتم)', $menu->term_id, $menu->name, $menu->count );
			}
			return Agent_WP_Tool_Result::success(
				$lines ? implode( "\n", $lines ) : __( 'منویی نیست.', 'agent-wp' ),
				array( 'menus' => $list )
			);
		}

		$menu_id = absint( $args['menu_id'] ?? 0 );
		if ( ! $menu_id ) {
			return Agent_WP_Tool_Result::error( __( 'menu_id لازم است.', 'agent-wp' ) );
		}

		if ( 'list_items' === $mode ) {
			$items = wp_get_nav_menu_items( $menu_id );
			$list  = array();
			$lines = array();
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					$list[]  = array(
						'id'    => (int) $item->ID,
						'title' => $item->title,
						'url'   => $item->url,
						'type'  => $item->type,
					);
					$lines[] = sprintf( '#%d %s', $item->ID, $item->title );
				}
			}
			return Agent_WP_Tool_Result::success(
				$lines ? implode( "\n", $lines ) : __( 'آیتمی نیست.', 'agent-wp' ),
				array( 'items' => $list, 'menu_id' => $menu_id )
			);
		}

		// add_item
		$type  = sanitize_key( $args['type'] ?? 'custom' );
		$title = sanitize_text_field( $args['title'] ?? '' );
		$item  = array(
			'menu-item-title'  => $title,
			'menu-item-status' => 'publish',
		);

		if ( 'page' === $type ) {
			$page_id = absint( $args['page_id'] ?? 0 );
			if ( ! $page_id || 'page' !== get_post_type( $page_id ) ) {
				return Agent_WP_Tool_Result::error( __( 'page_id نامعتبر است.', 'agent-wp' ) );
			}
			if ( '' === $title ) {
				$title = get_the_title( $page_id );
			}
			$item['menu-item-title']     = $title;
			$item['menu-item-object']    = 'page';
			$item['menu-item-object-id'] = $page_id;
			$item['menu-item-type']      = 'post_type';
		} else {
			$url = esc_url_raw( $args['url'] ?? '' );
			if ( '' === $title || '' === $url ) {
				return Agent_WP_Tool_Result::error( __( 'title و url لازم است.', 'agent-wp' ) );
			}
			$item['menu-item-type'] = 'custom';
			$item['menu-item-url']  = $url;
		}

		$new_id = wp_update_nav_menu_item( $menu_id, 0, $item );
		if ( is_wp_error( $new_id ) ) {
			return Agent_WP_Tool_Result::error( $new_id->get_error_message() );
		}

		return Agent_WP_Tool_Result::success(
			sprintf( __( 'آیتم «%s» به منو #%d اضافه شد.', 'agent-wp' ), $title, $menu_id ),
			array(
				'menu_id' => $menu_id,
				'itemId'  => (int) $new_id,
				'title'   => $title,
				'editUrl' => admin_url( 'nav-menus.php?action=edit&menu=' . $menu_id ),
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$after = json_decode( (string) $row['after_json'], true );
		if ( ! empty( $after['itemId'] ) ) {
			wp_delete_post( (int) $after['itemId'], true );
			return Agent_WP_Tool_Result::success( __( 'آیتم منو حذف شد (rollback).', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
	}
}
