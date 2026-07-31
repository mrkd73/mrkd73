<?php
/**
 * متای پست — کلید دسترسی افزونه‌ها به دادهٔ سفارشی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Post_Meta implements Agent_WP_Tool_Interface {

	public function id() {
		return 'post_meta';
	}

	public function label() {
		return __( 'متای محتوا', 'agent-wp' );
	}

	public function description() {
		return 'Get or set post meta for ANY content (Woo product fields, ACF, custom plugin meta). Modes: get, set, delete. Safe product/SEO keys set immediately; unknown keys and delete need confirmed=true.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'      => array( 'type' => 'string', 'enum' => array( 'get', 'set', 'delete' ) ),
				'post_id'   => array( 'type' => 'integer' ),
				'key'       => array( 'type' => 'string' ),
				'value'     => array( 'description' => 'Any JSON-serializable value for set' ),
				'confirmed' => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'mode', 'post_id' ),
		);
	}

	/**
	 * متاهایی که روی صفحهٔ محتوا/محصول بدون تأیید UI ست می‌شوند.
	 *
	 * @return array<int,string>
	 */
	private static function fast_keys() {
		return apply_filters(
			'agent_wp_post_meta_fastlist',
			array(
				'_price',
				'_regular_price',
				'_sale_price',
				'_sku',
				'_stock',
				'_stock_status',
				'_manage_stock',
				'_thumbnail_id',
				'_product_image_gallery',
				'_virtual',
				'_downloadable',
				'_weight',
				'_length',
				'_width',
				'_height',
				'_tax_status',
				'_tax_class',
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'rank_math_title',
				'rank_math_description',
			)
		);
	}

	private static function is_fast_key( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::fast_keys(), true ) ) {
			return true;
		}
		// پیشوند امن سئو
		if ( 0 === strpos( $key, '_yoast_wpseo_' ) || 0 === strpos( $key, 'rank_math_' ) ) {
			return true;
		}
		return false;
	}

	public function can_run( array $args ) {
		$id = absint( $args['post_id'] ?? 0 );
		return $id && current_user_can( 'edit_post', $id );
	}

	public function snapshot_before( array $args ) {
		$id  = absint( $args['post_id'] ?? 0 );
		$key = sanitize_text_field( $args['key'] ?? '' );
		if ( ! $id || '' === $key ) {
			return array();
		}
		return array(
			'post_id' => $id,
			'key'     => $key,
			'value'   => get_post_meta( $id, $key, true ),
			'existed' => metadata_exists( 'post', $id, $key ),
		);
	}

	public function run( array $args ) {
		$mode = sanitize_key( $args['mode'] ?? 'get' );
		$id   = absint( $args['post_id'] );
		$key  = isset( $args['key'] ) ? sanitize_text_field( $args['key'] ) : '';

		if ( 'get' === $mode ) {
			if ( '' === $key ) {
				$all  = get_post_meta( $id );
				$flat = array();
				foreach ( $all as $k => $vals ) {
					$flat[ $k ] = is_array( $vals ) && 1 === count( $vals ) ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', (array) $vals );
				}
				$keys = array_keys( $flat );
				return Agent_WP_Tool_Result::success(
					sprintf( __( '%d کلید متا برای #%d', 'agent-wp' ), count( $keys ), $id ) . "\n" . implode( ', ', array_slice( $keys, 0, 40 ) ),
					array( 'post_id' => $id, 'meta' => $flat )
				);
			}
			$val = get_post_meta( $id, $key, true );
			return Agent_WP_Tool_Result::success(
				sprintf( '%s = %s', $key, wp_json_encode( $val, JSON_UNESCAPED_UNICODE ) ),
				array(
					'post_id' => $id,
					'key'     => $key,
					'value'   => $val,
				)
			);
		}

		if ( '' === $key ) {
			return Agent_WP_Tool_Result::error( __( 'کلید متا لازم است.', 'agent-wp' ) );
		}

		$needs_confirm = true;
		if ( 'set' === $mode && self::is_fast_key( $key ) ) {
			$needs_confirm = false;
		}
		if ( empty( $args['confirmed'] ) && $needs_confirm && in_array( $mode, array( 'set', 'delete' ), true ) ) {
			$token = Agent_WP_Pending::store(
				'post_meta',
				array_merge( $args, array( 'confirmed' => true ) ),
				array(
					'post_id' => $id,
					'key'     => $key,
					'mode'    => $mode,
				)
			);
			return Agent_WP_Tool_Result::success(
				sprintf( __( 'نیاز به تأیید: %s متای «%s» روی #%d', 'agent-wp' ), $mode, $key, $id ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
				)
			);
		}

		if ( 'delete' === $mode ) {
			delete_post_meta( $id, $key );
			return Agent_WP_Tool_Result::success( sprintf( __( 'متای %s حذف شد.', 'agent-wp' ), $key ), array( 'post_id' => $id, 'key' => $key ) );
		}

		$value = isset( $args['value'] ) ? $args['value'] : '';
		update_post_meta( $id, $key, $value );
		return Agent_WP_Tool_Result::success(
			sprintf( __( 'متای %s ذخیره شد.', 'agent-wp' ), $key ),
			array(
				'post_id' => $id,
				'key'     => $key,
				'value'   => $value,
				'editUrl' => get_edit_post_link( $id, 'raw' ),
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		if ( empty( $before['post_id'] ) || empty( $before['key'] ) ) {
			return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
		}
		if ( empty( $before['existed'] ) ) {
			delete_post_meta( (int) $before['post_id'], $before['key'] );
		} else {
			update_post_meta( (int) $before['post_id'], $before['key'], $before['value'] );
		}
		return Agent_WP_Tool_Result::success( __( 'متا به قبل برگشت.', 'agent-wp' ) );
	}
}
