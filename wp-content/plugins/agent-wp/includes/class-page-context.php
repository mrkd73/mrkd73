<?php
/**
 * شناخت زندهٔ صفحه‌ای که کاربر الان در وردپرس/ووکامرس روی آن است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Page_Context {

	/**
	 * غنی‌سازی context خام با label، kind، و snapshot موجودیت.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function enrich( array $raw ) {
		// ورودی باید از normalize_page_context آمده باشد — دوباره normalize نکن (حلقه)
		$ctx = array(
			'url'        => isset( $raw['url'] ) ? (string) $raw['url'] : '',
			'screen'     => isset( $raw['screen'] ) ? (string) $raw['screen'] : '',
			'postId'     => isset( $raw['postId'] ) ? absint( $raw['postId'] ) : 0,
			'isAdmin'    => ! empty( $raw['isAdmin'] ),
			'title'      => isset( $raw['title'] ) ? (string) $raw['title'] : '',
			'postType'   => isset( $raw['postType'] ) ? (string) $raw['postType'] : '',
			'commentId'  => isset( $raw['commentId'] ) ? absint( $raw['commentId'] ) : 0,
			'optionPage' => isset( $raw['optionPage'] ) ? (string) $raw['optionPage'] : '',
			'isNew'      => ! empty( $raw['isNew'] ),
		);

		$screen = (string) $ctx['screen'];
		$url    = (string) $ctx['url'];
		$kind   = self::detect_kind( $ctx );
		$label  = self::label_for_kind( $kind, $ctx );

		// postType از screen / query اگر postId نبود
		if ( empty( $ctx['postType'] ) ) {
			if ( ! empty( $raw['postType'] ) ) {
				$ctx['postType'] = sanitize_key( (string) $raw['postType'] );
			} elseif ( preg_match( '/post_type=([a-z0-9_-]+)/i', $url, $m ) ) {
				$ctx['postType'] = sanitize_key( $m[1] );
			} elseif ( $screen && false !== strpos( $screen, 'product' ) ) {
				$ctx['postType'] = 'product';
			}
		}

		$option_page = '';
		if ( ! empty( $raw['optionPage'] ) ) {
			$option_page = sanitize_key( (string) $raw['optionPage'] );
		} elseif ( preg_match( '/[?&]page=([a-z0-9_-]+)/i', $url, $m ) ) {
			$option_page = sanitize_key( $m[1] );
		}

		$entity = self::entity_snapshot( $ctx, $kind );

		$ctx['kind']        = $kind;
		$ctx['label']       = $label;
		$ctx['optionPage']  = $option_page;
		$ctx['entity']      = $entity;
		$ctx['capabilities'] = self::capabilities_for( $kind, $ctx );
		$ctx['hint']        = self::action_hint( $kind, $ctx );

		return $ctx;
	}

	/**
	 * متن آماده برای system prompt.
	 *
	 * @param array<string,mixed> $ctx enriched
	 */
	public static function prompt_block( array $ctx ) {
		if ( empty( $ctx['kind'] ) ) {
			$ctx = self::enrich( $ctx );
		}
		$lines   = array();
		$lines[] = '=== صفحهٔ زندهٔ کاربر (اجباری) ===';
		$lines[] = 'نوع: ' . $ctx['kind'] . ' — ' . $ctx['label'];
		if ( ! empty( $ctx['screen'] ) ) {
			$lines[] = 'screen: ' . $ctx['screen'];
		}
		if ( ! empty( $ctx['url'] ) ) {
			$lines[] = 'url: ' . $ctx['url'];
		}
		if ( ! empty( $ctx['postId'] ) ) {
			$lines[] = 'entityId/postId: ' . (int) $ctx['postId']
				. ( ! empty( $ctx['postType'] ) ? ' (post_type=' . $ctx['postType'] . ')' : '' );
		}
		if ( ! empty( $ctx['commentId'] ) ) {
			$lines[] = 'commentId: ' . (int) $ctx['commentId'];
		}
		if ( ! empty( $ctx['optionPage'] ) ) {
			$lines[] = 'option/page slug: ' . $ctx['optionPage'];
		}
		if ( ! empty( $ctx['entity'] ) && is_array( $ctx['entity'] ) ) {
			$lines[] = 'وضعیت فعلی فیلدها:';
			foreach ( $ctx['entity'] as $k => $v ) {
				if ( is_array( $v ) ) {
					$v = wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
				}
				$v = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v;
				if ( mb_strlen( $v ) > 180 ) {
					$v = mb_substr( $v, 0, 180 ) . '…';
				}
				$lines[] = '  - ' . $k . ': ' . $v;
			}
		}
		if ( ! empty( $ctx['capabilities'] ) ) {
			$lines[] = 'کارهای ممکن روی همین صفحه: ' . implode( '، ', $ctx['capabilities'] );
		}
		$lines[] = '';
		$lines[] = 'قواعد اجرا روی این صفحه:';
		$lines[] = '1) کاربر الان همین‌جاست. دستورهایش را روی همین موجودیت اعمال کن — نپرس کدام محصول/نوشته.';
		$lines[] = '2) اگر postId/commentId داری همان را بفرست؛ اگر جا افتاد سیستم خودش تزریق می‌کند.';
		$lines[] = '3) سریع بدون تأیید UI: woo_products update، wp_content/update_content، terms، media set_featured، متای allowlist، REST PUT/PATCH روی همین id.';
		$lines[] = '4) با تأیید UI: تغییر فایل قالب/افزونه، custom_css، optionهای حساس، trash، DELETE، متای ناشناخته.';
		$lines[] = '5) برای تصویر: generate_image سپس media(set_featured) یا woo_products با featuredImageId.';
		$lines[] = '6) بعد از اجرا خلاصهٔ کوتاه بگو چه چیزی روی همین صفحه عوض شد.';
		if ( ! empty( $ctx['hint'] ) ) {
			$lines[] = 'راهنما: ' . $ctx['hint'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,mixed> $ctx
	 */
	public static function detect_kind( array $ctx ) {
		$screen = (string) ( $ctx['screen'] ?? '' );
		$url    = (string) ( $ctx['url'] ?? '' );
		$type   = (string) ( $ctx['postType'] ?? '' );
		$post_id = (int) ( $ctx['postId'] ?? 0 );

		if ( $post_id && ! $type ) {
			$p = get_post( $post_id );
			$type = $p ? (string) $p->post_type : '';
		}

		if ( ! empty( $ctx['commentId'] ) || false !== strpos( $screen, 'comment' ) || false !== strpos( $url, 'comment' ) ) {
			return 'comment';
		}
		if ( 'product' === $type || false !== strpos( $screen, 'product' ) || false !== strpos( $url, 'post_type=product' ) ) {
			if ( false !== strpos( $url, 'post-new.php' ) || false !== strpos( $screen, 'product-add' ) || ( isset( $ctx['isNew'] ) && $ctx['isNew'] ) ) {
				return 'product_new';
			}
			return 'product_edit';
		}
		if ( 'page' === $type || false !== strpos( $screen, 'page' ) && false === strpos( $screen, 'plugins' ) ) {
			if ( false !== strpos( $url, 'post-new.php' ) ) {
				return 'page_new';
			}
			if ( $post_id || false !== strpos( $screen, 'page' ) ) {
				return false !== strpos( $url, 'edit.php' ) ? 'page_list' : 'page_edit';
			}
		}
		if ( 'post' === $type || ( $screen && ( 'post' === $screen || 'edit-post' === $screen || false !== strpos( $screen, 'post' ) ) ) ) {
			if ( false !== strpos( $url, 'post-new.php' ) ) {
				return 'post_new';
			}
			if ( false !== strpos( $url, 'edit.php' ) && false === strpos( $url, 'post_type=' ) ) {
				return 'post_list';
			}
			if ( $post_id ) {
				return 'post_edit';
			}
		}
		if ( false !== strpos( $screen, 'woocommerce' ) || false !== strpos( $url, 'wc-settings' ) || false !== strpos( $url, 'wc-admin' ) ) {
			return 'woo_settings';
		}
		if ( false !== strpos( $screen, 'theme' ) || false !== strpos( $url, 'themes.php' ) || false !== strpos( $url, 'customize.php' ) ) {
			return 'theme';
		}
		if ( false !== strpos( $url, 'options-' ) || false !== strpos( $screen, 'options-' ) || false !== strpos( $url, 'settings.php' ) ) {
			return 'wp_settings';
		}
		if ( false !== strpos( $screen, 'plugins' ) || false !== strpos( $url, 'plugins.php' ) ) {
			return 'plugins';
		}
		if ( false !== strpos( $screen, 'nav-menus' ) || false !== strpos( $url, 'nav-menus.php' ) ) {
			return 'menus';
		}
		if ( false !== strpos( $screen, 'upload' ) || false !== strpos( $url, 'upload.php' ) || false !== strpos( $url, 'media-new' ) ) {
			return 'media';
		}
		if ( false !== strpos( $screen, 'dashboard' ) || false !== strpos( $url, 'index.php' ) ) {
			return 'dashboard';
		}
		if ( empty( $ctx['isAdmin'] ) ) {
			return 'front';
		}
		return 'admin_other';
	}

	private static function label_for_kind( $kind, array $ctx ) {
		$map = array(
			'product_new'  => 'افزودن محصول ووکامرس',
			'product_edit' => 'ویرایش محصول ووکامرس',
			'post_new'     => 'افزودن نوشته',
			'post_edit'    => 'ویرایش نوشته',
			'post_list'    => 'لیست نوشته‌ها',
			'page_new'     => 'افزودن برگه',
			'page_edit'    => 'ویرایش برگه',
			'page_list'    => 'لیست برگه‌ها',
			'comment'      => 'دیدگاه‌ها',
			'woo_settings' => 'تنظیمات ووکامرس',
			'theme'        => 'قالب / سفارشی‌سازی',
			'wp_settings'  => 'تنظیمات وردپرس',
			'plugins'      => 'افزونه‌ها',
			'menus'        => 'فهرست‌ها',
			'media'        => 'رسانه',
			'dashboard'    => 'پیشخوان',
			'front'        => 'فرانت سایت',
			'admin_other'  => 'صفحهٔ پیشخوان',
		);
		$base = isset( $map[ $kind ] ) ? $map[ $kind ] : 'صفحه';
		if ( ! empty( $ctx['title'] ) && in_array( $kind, array( 'product_edit', 'post_edit', 'page_edit' ), true ) ) {
			return $base . ' — «' . $ctx['title'] . '»';
		}
		return $base;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function entity_snapshot( array $ctx, $kind ) {
		$post_id = (int) ( $ctx['postId'] ?? 0 );
		if ( $post_id && in_array( $kind, array( 'product_edit', 'product_new', 'post_edit', 'post_new', 'page_edit', 'page_new' ), true ) ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array();
			}
			$out = array(
				'id'      => $post_id,
				'title'   => get_the_title( $post ),
				'status'  => $post->post_status,
				'excerpt' => (string) $post->post_excerpt,
				'hasThumb'=> (bool) get_post_thumbnail_id( $post_id ),
				'thumbId' => (int) get_post_thumbnail_id( $post_id ),
			);
			$content = wp_strip_all_tags( (string) $post->post_content );
			$out['contentPreview'] = mb_substr( $content, 0, 220 );
			if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
				$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
				$out['tags'] = is_array( $tags ) ? $tags : array();
			}
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$p = wc_get_product( $post_id );
				if ( $p ) {
					$out['price']         = $p->get_regular_price();
					$out['salePrice']     = $p->get_sale_price();
					$out['sku']           = $p->get_sku();
					$out['stockStatus']   = $p->get_stock_status();
					$out['stockQty']      = $p->get_stock_quantity();
					$out['manageStock']   = $p->get_manage_stock();
					$out['galleryIds']    = $p->get_gallery_image_ids();
					$out['productType']   = $p->get_type();
					$out['shortDescription'] = $p->get_short_description();
					$cats = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
					$out['categories'] = is_array( $cats ) && ! is_wp_error( $cats ) ? $cats : array();
					$ptags = wp_get_post_terms( $post_id, 'product_tag', array( 'fields' => 'names' ) );
					$out['productTags'] = is_array( $ptags ) && ! is_wp_error( $ptags ) ? $ptags : array();
				}
			}
			return $out;
		}

		if ( ! empty( $ctx['commentId'] ) ) {
			$c = get_comment( (int) $ctx['commentId'] );
			if ( $c ) {
				return array(
					'commentId' => (int) $c->comment_ID,
					'author'    => $c->comment_author,
					'status'    => wp_get_comment_status( $c ),
					'preview'   => mb_substr( wp_strip_all_tags( $c->comment_content ), 0, 200 ),
					'postId'    => (int) $c->comment_post_ID,
				);
			}
		}

		if ( 'woo_settings' === $kind && function_exists( 'wc_get_base_location' ) ) {
			return array(
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'country'  => wc_get_base_location(),
			);
		}

		return array();
	}

	/**
	 * @return array<int,string>
	 */
	private static function capabilities_for( $kind, array $ctx ) {
		switch ( $kind ) {
			case 'product_edit':
			case 'product_new':
				return array(
					'عنوان (wp_content/update یا woo_products)',
					'قیمت / قیمت فروش / SKU / موجودی (woo_products update)',
					'تصویر شاخص (generate_image + set_featured)',
					'گالری (woo_products galleryIds)',
					'دسته و برچسب محصول',
					'توضیح کوتاه و محتوا',
				);
			case 'post_edit':
			case 'post_new':
			case 'page_edit':
			case 'page_new':
				return array(
					'عنوان و محتوا و خلاصه',
					'تصویر شاخص',
					'برچسب/دسته',
					'وضعیت انتشار',
				);
			case 'comment':
				return array( 'پاسخ پیشنهادی', 'تغییر وضعیت دیدگاه', 'خلاصه دیدگاه‌های در انتظار' );
			case 'theme':
				return array( 'custom_css', 'خواندن/نوشتن فایل قالب با تأیید', 'لیست قالب‌ها' );
			case 'wp_settings':
				return array( 'update_site_title', 'update_option (با تأیید برای غیرمجازها)' );
			case 'woo_settings':
				return array( 'REST /wc/v3/settings', 'update_option ووکامرس با احتیاط' );
			default:
				return array( 'discover', 'ابزارهای مرتبط با همین صفحه' );
		}
	}

	private static function action_hint( $kind, array $ctx ) {
		if ( in_array( $kind, array( 'product_edit', 'product_new' ), true ) ) {
			$id = (int) ( $ctx['postId'] ?? 0 );
			return $id
				? "کاربر روی محصول #{$id} است. دستورهای قیمت/عنوان/تصویر/موجودی/گالری را مستقیم روی همین id بزن."
				: 'کاربر در افزودن محصول است؛ اگر postId هنوز نیست بعد از ایجاد پیش‌نویس همان را بگیر و ادامه بده.';
		}
		if ( in_array( $kind, array( 'post_edit', 'page_edit' ), true ) && ! empty( $ctx['postId'] ) ) {
			return 'دستورها را روی postId=' . (int) $ctx['postId'] . ' اجرا کن.';
		}
		return '';
	}
}
