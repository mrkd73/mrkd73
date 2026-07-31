<?php
/**
 * CRUD عمومی برای هر post_type (پوشش CPTهای افزونه‌ها بدون Tool اختصاصی).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Wp_Content implements Agent_WP_Tool_Interface {

	public function id() {
		return 'wp_content';
	}

	public function label() {
		return __( 'محتوای عمومی', 'agent-wp' );
	}

	public function description() {
		return 'Universal content API for ANY post type (product, course, form, custom CPT from plugins). Modes: list, get, create, update, trash. Prefer this over plugin-specific tools.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'      => array( 'type' => 'string', 'enum' => array( 'list', 'get', 'create', 'update', 'trash' ) ),
				'post_type' => array( 'type' => 'string', 'default' => 'post' ),
				'id'        => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'excerpt'   => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
				'search'    => array( 'type' => 'string' ),
				'limit'     => array( 'type' => 'integer', 'default' => 10 ),
				'meta'      => array( 'type' => 'object', 'description' => 'Key/value post meta to set on create/update' ),
				'confirmed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'mode' ),
		);
	}

	public function can_run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? '' );
		$type = sanitize_key( $args['post_type'] ?? 'post' );
		if ( 'list' === $mode || 'get' === $mode ) {
			return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
		}
		if ( 'create' === $mode ) {
			$pto = get_post_type_object( $type );
			return $pto && current_user_can( $pto->cap->create_posts );
		}
		$id = absint( $args['id'] ?? 0 );
		if ( ! $id ) {
			return false;
		}
		if ( 'trash' === $mode ) {
			return current_user_can( 'delete_post', $id );
		}
		return current_user_can( 'edit_post', $id );
	}

	public function snapshot_before( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? '' );
		$id   = absint( $args['id'] ?? 0 );
		if ( ! $id || ! in_array( $mode, array( 'update', 'trash' ), true ) ) {
			return array();
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return array();
		}
		return array(
			'id'      => $id,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'status'  => $post->post_status,
			'type'    => $post->post_type,
		);
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'list' );
		$type = sanitize_key( $args['post_type'] ?? 'post' );
		if ( '' === $type ) {
			$type = 'post';
		}

		if ( 'list' === $mode ) {
			$limit = isset( $args['limit'] ) ? max( 1, min( 50, absint( $args['limit'] ) ) ) : 10;
			$q     = array(
				'post_type'      => $type,
				'posts_per_page' => $limit,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			);
			if ( ! empty( $args['search'] ) ) {
				$q['s'] = sanitize_text_field( $args['search'] );
			}
			$posts = get_posts( $q );
			$list  = array();
			$lines = array();
			foreach ( $posts as $p ) {
				$list[]  = array(
					'id'     => (int) $p->ID,
					'title'  => get_the_title( $p ),
					'status' => $p->post_status,
					'type'   => $p->post_type,
				);
				$lines[] = sprintf( '#%d %s [%s]', $p->ID, get_the_title( $p ), $p->post_status );
			}
			return Agent_WP_Tool_Result::success(
				$lines ? implode( "\n", $lines ) : __( 'موردی نیست.', 'agent-wp' ),
				array( 'items' => $list, 'post_type' => $type )
			);
		}

		if ( 'get' === $mode ) {
			$id   = absint( $args['id'] ?? 0 );
			$post = get_post( $id );
			if ( ! $post ) {
				return Agent_WP_Tool_Result::error( __( 'یافت نشد.', 'agent-wp' ) );
			}
			$meta = get_post_meta( $id );
			$flat = array();
			foreach ( $meta as $k => $vals ) {
				if ( 0 === strpos( $k, '_' ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}
				$flat[ $k ] = is_array( $vals ) && 1 === count( $vals ) ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', (array) $vals );
			}
			$data = array(
				'id'      => (int) $post->ID,
				'type'    => $post->post_type,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'meta'    => $flat,
				'editUrl' => get_edit_post_link( $id, 'raw' ),
				'viewUrl' => get_permalink( $id ),
			);
			$preview = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 400 );
			return Agent_WP_Tool_Result::success(
				sprintf( "#%d [%s/%s] %s\n%s", $data['id'], $data['type'], $data['status'], $data['title'], $preview ),
				$data
			);
		}

		if ( 'trash' === $mode ) {
			$id   = absint( $args['id'] ?? 0 );
			$post = get_post( $id );
			if ( ! $post ) {
				return Agent_WP_Tool_Result::error( __( 'یافت نشد.', 'agent-wp' ) );
			}
			if ( empty( $args['confirmed'] ) ) {
				$token = Agent_WP_Pending::store(
					'wp_content',
					array_merge( $args, array( 'confirmed' => true ) ),
					array(
						'id'    => $id,
						'title' => $post->post_title,
						'type'  => $post->post_type,
					)
				);
				return Agent_WP_Tool_Result::success(
					sprintf( __( 'نیاز به تأیید: حذف «%s» (#%d) به زباله‌دان؟', 'agent-wp' ), $post->post_title, $id ),
					array(
						'needsConfirm' => true,
						'confirmToken' => $token,
						'id'           => $id,
					)
				);
			}
			if ( ! wp_trash_post( $id ) ) {
				return Agent_WP_Tool_Result::error( __( 'انتقال به زباله‌دان ناموفق بود.', 'agent-wp' ) );
			}
			return Agent_WP_Tool_Result::success( sprintf( __( '#%d به زباله‌دان رفت.', 'agent-wp' ), $id ), array( 'id' => $id ) );
		}

		if ( 'create' === $mode ) {
			$title = sanitize_text_field( $args['title'] ?? '' );
			if ( '' === $title ) {
				return Agent_WP_Tool_Result::error( __( 'عنوان لازم است.', 'agent-wp' ) );
			}
			if ( ! post_type_exists( $type ) ) {
				return Agent_WP_Tool_Result::error( __( 'این post_type ثبت نشده. اول discover بزن.', 'agent-wp' ) );
			}
			$status = sanitize_key( $args['status'] ?? 'draft' );
			if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				$status = 'draft';
			}
			$id = wp_insert_post(
				array(
					'post_type'    => $type,
					'post_title'   => $title,
					'post_content' => isset( $args['content'] ) ? wp_kses_post( (string) $args['content'] ) : '',
					'post_excerpt' => isset( $args['excerpt'] ) ? sanitize_textarea_field( $args['excerpt'] ) : '',
					'post_status'  => $status,
					'post_author'  => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return Agent_WP_Tool_Result::error( $id->get_error_message() );
			}
			$this->apply_meta( (int) $id, $args );
			return Agent_WP_Tool_Result::success(
				sprintf( __( '«%s» (%s) #%d ساخته شد.', 'agent-wp' ), $title, $type, $id ),
				array(
					'id'      => (int) $id,
					'type'    => $type,
					'status'  => $status,
					'editUrl' => get_edit_post_link( $id, 'raw' ),
					'viewUrl' => get_permalink( $id ),
				)
			);
		}

		// update
		$id = absint( $args['id'] ?? 0 );
		if ( ! $id || ! get_post( $id ) ) {
			return Agent_WP_Tool_Result::error( __( 'شناسه نامعتبر است.', 'agent-wp' ) );
		}
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['content'] );
		}
		if ( isset( $args['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}
		if ( isset( $args['status'] ) ) {
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, array( 'draft', 'publish', 'pending', 'private', 'future' ), true ) ) {
				$update['post_status'] = $status;
			}
		}
		$r = wp_update_post( $update, true );
		if ( is_wp_error( $r ) ) {
			return Agent_WP_Tool_Result::error( $r->get_error_message() );
		}
		$this->apply_meta( $id, $args );
		$post = get_post( $id );
		return Agent_WP_Tool_Result::success(
			sprintf( __( '#%d به‌روز شد.', 'agent-wp' ), $id ),
			array(
				'id'      => $id,
				'title'   => $post ? $post->post_title : '',
				'status'  => $post ? $post->post_status : '',
				'type'    => $post ? $post->post_type : '',
				'editUrl' => get_edit_post_link( $id, 'raw' ),
				'viewUrl' => get_permalink( $id ),
			)
		);
	}

	private function apply_meta( $post_id, array $args ) {
		if ( empty( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
			return;
		}
		foreach ( $args['meta'] as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		$after  = json_decode( (string) $row['after_json'], true );
		$args   = json_decode( (string) $row['args_json'], true );
		$mode   = is_array( $args ) ? ( $args['mode'] ?? '' ) : '';

		if ( 'create' === $mode && ! empty( $after['id'] ) ) {
			wp_delete_post( (int) $after['id'], true );
			return Agent_WP_Tool_Result::success( __( 'آیتم ساخته‌شده حذف شد.', 'agent-wp' ) );
		}
		if ( 'trash' === $mode && ! empty( $before['id'] ) ) {
			wp_untrash_post( (int) $before['id'] );
			if ( ! empty( $before['status'] ) ) {
				wp_update_post(
					array(
						'ID'          => (int) $before['id'],
						'post_status' => $before['status'],
					)
				);
			}
			return Agent_WP_Tool_Result::success( __( 'از زباله‌دان بازیابی شد.', 'agent-wp' ) );
		}
		if ( 'update' === $mode && ! empty( $before['id'] ) ) {
			wp_update_post(
				array(
					'ID'           => (int) $before['id'],
					'post_title'   => $before['title'],
					'post_content' => $before['content'],
					'post_excerpt' => $before['excerpt'],
					'post_status'  => $before['status'],
				)
			);
			return Agent_WP_Tool_Result::success( __( 'محتوا به قبل برگشت.', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
	}
}
