<?php
/**
 * WooCommerce — لیست / خواندن / به‌روزرسانی محصول.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Woo_Products implements Agent_WP_Tool_Interface {

	public function id() {
		return 'woo_products';
	}

	public function label() {
		return __( 'محصولات ووکامرس', 'agent-wp' );
	}

	public function description() {
		return 'WooCommerce products. Modes: list (default), get, update. Update can set title, content, shortDescription, regularPrice, salePrice, sku, stockStatus, stockQuantity, manageStock, categories, tags, featuredImageId, galleryIds, status. Use when user is on a product screen and gives field instructions.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'             => array(
					'type' => 'string',
					'enum' => array( 'list', 'get', 'update' ),
				),
				'product_id'       => array( 'type' => 'integer' ),
				'search'           => array( 'type' => 'string' ),
				'limit'            => array( 'type' => 'integer', 'default' => 10 ),
				'status'           => array( 'type' => 'string', 'default' => 'any' ),
				'title'            => array( 'type' => 'string' ),
				'content'          => array( 'type' => 'string' ),
				'shortDescription' => array( 'type' => 'string' ),
				'regularPrice'     => array( 'type' => 'string' ),
				'salePrice'        => array( 'type' => 'string' ),
				'sku'              => array( 'type' => 'string' ),
				'stockStatus'      => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'stockQuantity'    => array( 'type' => 'integer' ),
				'manageStock'      => array( 'type' => 'boolean' ),
				'categories'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'tags'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'featuredImageId'  => array( 'type' => 'integer' ),
				'galleryIds'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'postStatus'       => array( 'type' => 'string' ),
			),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'list' );
		if ( 'update' !== $mode ) {
			return array();
		}
		$id = absint( $args['product_id'] ?? 0 );
		if ( ! $id || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return array();
		}
		return array(
			'product_id'       => $id,
			'title'            => $p->get_name(),
			'regularPrice'     => $p->get_regular_price(),
			'salePrice'        => $p->get_sale_price(),
			'sku'              => $p->get_sku(),
			'stockStatus'      => $p->get_stock_status(),
			'stockQuantity'    => $p->get_stock_quantity(),
			'manageStock'      => $p->get_manage_stock(),
			'featuredImageId'  => $p->get_image_id(),
			'galleryIds'       => $p->get_gallery_image_ids(),
			'shortDescription' => $p->get_short_description(),
			'content'          => $p->get_description(),
			'postStatus'       => get_post_status( $id ),
		);
	}

	public function run( array $args ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return Agent_WP_Tool_Result::error( __( 'ووکامرس فعال نیست.', 'agent-wp' ) );
		}

		$mode = sanitize_key( $args['mode'] ?? 'list' );
		if ( 'get' === $mode ) {
			return self::get_one( $args );
		}
		if ( 'update' === $mode ) {
			return self::update_one( $args );
		}
		return self::list_many( $args );
	}

	private static function list_many( array $args ) {
		$limit = isset( $args['limit'] ) ? max( 1, min( 50, absint( $args['limit'] ) ) ) : 10;
		$query = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'post_status'    => ( ! empty( $args['status'] ) && 'any' !== $args['status'] )
				? sanitize_key( $args['status'] )
				: array( 'publish', 'draft', 'pending', 'private', 'auto-draft' ),
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
			$product = wc_get_product( $post->ID );
			$price   = $product ? $product->get_price() : '';
			$sku     = $product ? $product->get_sku() : '';
			$stock   = $product ? $product->get_stock_status() : '';
			$item    = array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
				'price'  => $price,
				'sku'    => $sku,
				'stock'  => $stock,
			);
			$list[]  = $item;
			$lines[] = sprintf( '#%d %s [%s] قیمت:%s', $item['id'], $item['title'], $item['status'], $price !== '' ? $price : '—' );
		}

		if ( ! $lines ) {
			return Agent_WP_Tool_Result::success( __( 'محصولی یافت نشد.', 'agent-wp' ), array( 'products' => array() ) );
		}

		return Agent_WP_Tool_Result::success(
			__( 'محصولات:', 'agent-wp' ) . "\n" . implode( "\n", $lines ),
			array( 'products' => $list )
		);
	}

	private static function get_one( array $args ) {
		$id = absint( $args['product_id'] ?? 0 );
		if ( ! $id ) {
			return Agent_WP_Tool_Result::error( __( 'product_id لازم است.', 'agent-wp' ) );
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return Agent_WP_Tool_Result::error( __( 'محصول یافت نشد.', 'agent-wp' ) );
		}
		$data = self::product_array( $p );
		$msg  = sprintf(
			"#%d %s | قیمت:%s | فروش:%s | SKU:%s | موجودی:%s (%s)",
			$data['id'],
			$data['title'],
			$data['regularPrice'] !== '' ? $data['regularPrice'] : '—',
			$data['salePrice'] !== '' ? $data['salePrice'] : '—',
			$data['sku'] !== '' ? $data['sku'] : '—',
			null !== $data['stockQuantity'] ? (string) $data['stockQuantity'] : '—',
			$data['stockStatus']
		);
		return Agent_WP_Tool_Result::success( $msg, array( 'product' => $data ) );
	}

	private static function update_one( array $args ) {
		$id = absint( $args['product_id'] ?? 0 );
		if ( ! $id ) {
			return Agent_WP_Tool_Result::error( __( 'product_id لازم است.', 'agent-wp' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Agent_WP_Tool_Result::error( __( 'اجازهٔ ویرایش این محصول را نداری.', 'agent-wp' ) );
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return Agent_WP_Tool_Result::error( __( 'محصول یافت نشد.', 'agent-wp' ) );
		}

		$changed = array();

		if ( isset( $args['title'] ) && '' !== trim( (string) $args['title'] ) ) {
			$p->set_name( sanitize_text_field( (string) $args['title'] ) );
			$changed[] = 'title';
		}
		if ( isset( $args['content'] ) ) {
			$p->set_description( wp_kses_post( (string) $args['content'] ) );
			$changed[] = 'content';
		}
		if ( isset( $args['shortDescription'] ) ) {
			$p->set_short_description( wp_kses_post( (string) $args['shortDescription'] ) );
			$changed[] = 'shortDescription';
		}
		if ( isset( $args['regularPrice'] ) ) {
			$p->set_regular_price( wc_format_decimal( (string) $args['regularPrice'] ) );
			$changed[] = 'regularPrice';
		}
		if ( array_key_exists( 'salePrice', $args ) ) {
			$sale = (string) $args['salePrice'];
			$p->set_sale_price( '' === $sale ? '' : wc_format_decimal( $sale ) );
			$changed[] = 'salePrice';
		}
		if ( isset( $args['sku'] ) ) {
			$p->set_sku( sanitize_text_field( (string) $args['sku'] ) );
			$changed[] = 'sku';
		}
		if ( isset( $args['manageStock'] ) ) {
			$p->set_manage_stock( (bool) $args['manageStock'] );
			$changed[] = 'manageStock';
		}
		if ( isset( $args['stockQuantity'] ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $args['stockQuantity'] );
			$changed[] = 'stockQuantity';
		}
		if ( isset( $args['stockStatus'] ) ) {
			$status = sanitize_key( (string) $args['stockStatus'] );
			if ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$p->set_stock_status( $status );
				$changed[] = 'stockStatus';
			}
		}
		if ( isset( $args['featuredImageId'] ) ) {
			$img = absint( $args['featuredImageId'] );
			if ( $img && 'attachment' === get_post_type( $img ) ) {
				$p->set_image_id( $img );
				$changed[] = 'featuredImageId';
			}
		}
		if ( isset( $args['galleryIds'] ) && is_array( $args['galleryIds'] ) ) {
			$ids = array();
			foreach ( $args['galleryIds'] as $gid ) {
				$gid = absint( $gid );
				if ( $gid && 'attachment' === get_post_type( $gid ) ) {
					$ids[] = $gid;
				}
			}
			$p->set_gallery_image_ids( $ids );
			$changed[] = 'galleryIds';
		}

		$p->save();

		if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$names = array_filter( array_map( 'sanitize_text_field', $args['categories'] ) );
			if ( $names ) {
				wp_set_object_terms( $id, $names, 'product_cat', false );
				$changed[] = 'categories';
			}
		}
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$names = array_filter( array_map( 'sanitize_text_field', $args['tags'] ) );
			if ( $names ) {
				wp_set_object_terms( $id, $names, 'product_tag', false );
				$changed[] = 'tags';
			}
		}
		if ( isset( $args['postStatus'] ) ) {
			$st = sanitize_key( (string) $args['postStatus'] );
			if ( in_array( $st, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $st,
					)
				);
				$changed[] = 'postStatus';
			}
		}

		if ( ! $changed ) {
			return Agent_WP_Tool_Result::error( __( 'هیچ فیلدی برای به‌روزرسانی ارسال نشد.', 'agent-wp' ) );
		}

		$fresh = wc_get_product( $id );
		return Agent_WP_Tool_Result::success(
			sprintf(
				/* translators: 1: product id 2: fields */
				__( 'محصول #%1$d به‌روز شد (%2$s).', 'agent-wp' ),
				$id,
				implode( ', ', $changed )
			),
			array(
				'productId' => $id,
				'changed'   => $changed,
				'product'   => $fresh ? self::product_array( $fresh ) : array(),
			)
		);
	}

	/**
	 * @param \WC_Product $p
	 * @return array<string,mixed>
	 */
	private static function product_array( $p ) {
		$id = $p->get_id();
		$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
		$tags = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
		return array(
			'id'               => $id,
			'title'            => $p->get_name(),
			'status'           => get_post_status( $id ),
			'type'             => $p->get_type(),
			'regularPrice'     => $p->get_regular_price(),
			'salePrice'        => $p->get_sale_price(),
			'price'            => $p->get_price(),
			'sku'              => $p->get_sku(),
			'stockStatus'      => $p->get_stock_status(),
			'stockQuantity'    => $p->get_stock_quantity(),
			'manageStock'      => $p->get_manage_stock(),
			'featuredImageId'  => (int) $p->get_image_id(),
			'galleryIds'       => array_map( 'intval', (array) $p->get_gallery_image_ids() ),
			'shortDescription' => $p->get_short_description(),
			'content'          => $p->get_description(),
			'categories'       => ( is_array( $cats ) && ! is_wp_error( $cats ) ) ? $cats : array(),
			'tags'             => ( is_array( $tags ) && ! is_wp_error( $tags ) ) ? $tags : array(),
			'editUrl'          => get_edit_post_link( $id, 'raw' ),
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row || empty( $row['before_json'] ) ) {
			return Agent_WP_Tool_Result::error( __( 'نقطه بازگشت نیست.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( ! is_array( $before ) || empty( $before['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
			return Agent_WP_Tool_Result::error( __( 'دادهٔ بازگشت نامعتبر.', 'agent-wp' ) );
		}
		$p = wc_get_product( (int) $before['product_id'] );
		if ( ! $p ) {
			return Agent_WP_Tool_Result::error( __( 'محصول یافت نشد.', 'agent-wp' ) );
		}
		if ( isset( $before['title'] ) ) {
			$p->set_name( $before['title'] );
		}
		if ( array_key_exists( 'regularPrice', $before ) ) {
			$p->set_regular_price( $before['regularPrice'] );
		}
		if ( array_key_exists( 'salePrice', $before ) ) {
			$p->set_sale_price( $before['salePrice'] );
		}
		if ( array_key_exists( 'sku', $before ) ) {
			$p->set_sku( $before['sku'] );
		}
		if ( array_key_exists( 'stockStatus', $before ) ) {
			$p->set_stock_status( $before['stockStatus'] );
		}
		if ( array_key_exists( 'stockQuantity', $before ) ) {
			$p->set_stock_quantity( $before['stockQuantity'] );
		}
		if ( array_key_exists( 'manageStock', $before ) ) {
			$p->set_manage_stock( (bool) $before['manageStock'] );
		}
		if ( array_key_exists( 'featuredImageId', $before ) ) {
			$p->set_image_id( (int) $before['featuredImageId'] );
		}
		if ( array_key_exists( 'galleryIds', $before ) && is_array( $before['galleryIds'] ) ) {
			$p->set_gallery_image_ids( $before['galleryIds'] );
		}
		if ( array_key_exists( 'shortDescription', $before ) ) {
			$p->set_short_description( $before['shortDescription'] );
		}
		if ( array_key_exists( 'content', $before ) ) {
			$p->set_description( $before['content'] );
		}
		$p->save();
		if ( ! empty( $before['postStatus'] ) ) {
			wp_update_post(
				array(
					'ID'          => (int) $before['product_id'],
					'post_status' => sanitize_key( $before['postStatus'] ),
				)
			);
		}
		return Agent_WP_Tool_Result::success( __( 'محصول به حالت قبل برگشت.', 'agent-wp' ) );
	}
}
