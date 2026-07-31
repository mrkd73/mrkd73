<?php
/**
 * اسکن زنده سایت و تولید پیشنهادهای مدیریتی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Assistant_Scan {

	public static function run( $force = false ) {
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			return;
		}
		if ( ! $force && Agent_WP_Assistant::is_quiet_now() ) {
			return;
		}

		Agent_WP_Assistant::wake_snoozed();

		if ( Agent_WP_Assistant::module_enabled( 'content' ) ) {
			self::scan_content();
		}
		if ( Agent_WP_Assistant::module_enabled( 'design' ) ) {
			self::scan_design();
		}
		if ( Agent_WP_Assistant::module_enabled( 'seo' ) ) {
			self::scan_seo_basics();
		}
		if ( Agent_WP_Assistant::module_enabled( 'technical' ) ) {
			self::scan_technical();
		}
		if ( Agent_WP_Assistant::module_enabled( 'growth' ) ) {
			self::scan_growth();
		}
		if ( Agent_WP_Assistant::module_enabled( 'cleanup' ) ) {
			self::scan_cleanup();
		}
		if ( Agent_WP_Assistant::module_enabled( 'foresight' ) ) {
			self::scan_foresight();
		}
		if ( Agent_WP_Assistant::module_enabled( 'quality' ) ) {
			self::scan_quality();
			self::scan_duplicates();
			self::scan_plugin_updates();
			self::scan_menu_links();
		}

		update_option( Agent_WP_Assistant::OPTION_LAST_SCAN, time(), false );
	}

	private static function topic_hint() {
		return Agent_WP_Assistant::get_topic();
	}

	private static function scan_content() {
		$topic = self::topic_hint();

		$empty_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 8,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $empty_pages as $p ) {
			$content = trim( wp_strip_all_tags( $p->post_content ) );
			if ( mb_strlen( $content ) < 40 ) {
				Agent_WP_Assistant::upsert_suggestion(
					'empty_page_' . $p->ID,
					'content',
					sprintf( __( 'صفحه «%s» تقریباً خالی است', 'agent-wp' ), $p->post_title ),
					sprintf( __( 'برای موضوع «%s» چند پاراگراف مفید بنویس یا بخش‌های کلیدی اضافه کن.', 'agent-wp' ), $topic ),
					8,
					array( 'postId' => (int) $p->ID, 'action' => 'edit' )
				);
			}
			if ( ! has_post_thumbnail( $p->ID ) && 'publish' === $p->post_status ) {
				Agent_WP_Assistant::upsert_suggestion(
					'no_thumb_page_' . $p->ID,
					'design',
					sprintf( __( 'تصویر شاخص برای «%s» ندارید', 'agent-wp' ), $p->post_title ),
					__( 'یک تصویر مرتبط انتخاب کنید تا ظاهر حرفه‌ای‌تر شود.', 'agent-wp' ),
					5,
					array( 'postId' => (int) $p->ID, 'action' => 'media' )
				);
			}
		}

		$drafts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'draft',
				'posts_per_page' => 5,
				'date_query'     => array(
					array( 'before' => '7 days ago' ),
				),
			)
		);
		foreach ( $drafts as $d ) {
			Agent_WP_Assistant::upsert_suggestion(
				'old_draft_' . $d->ID,
				'content',
				sprintf( __( 'پیش‌نویس قدیمی: «%s»', 'agent-wp' ), $d->post_title ),
				__( 'یا کامل و منتشرش کن، یا اگر دیگر لازم نیست حذف/بایگانی کن.', 'agent-wp' ),
				6,
				array( 'postId' => (int) $d->ID, 'action' => 'review' )
			);
		}

		$posts_count = (int) wp_count_posts( 'post' )->publish;
		if ( $posts_count < 3 ) {
			Agent_WP_Assistant::upsert_suggestion(
				'few_posts',
				'growth',
				__( 'محتوای وبلاگ کم است', 'agent-wp' ),
				sprintf( __( 'با موضوع «%s» ۲–۳ نوشته آموزشی/معرفی اضافه کنید تا سایت زنده بماند.', 'agent-wp' ), $topic ),
				7,
				array( 'action' => 'add' )
			);
		}
	}

	private static function scan_design() {
		$css = (string) wp_get_custom_css();
		if ( '' === trim( $css ) ) {
			Agent_WP_Assistant::upsert_suggestion(
				'no_custom_css',
				'design',
				__( 'هنوز CSS سفارشی ندارید', 'agent-wp' ),
				__( 'یک لایه استایل کوچک برای دکمه‌ها/لینک‌ها اضافه کنید تا هویت بصری قوی‌تر شود.', 'agent-wp' ),
				4,
				array( 'action' => 'design' )
			);
		}

		$theme = wp_get_theme();
		Agent_WP_Assistant::upsert_suggestion(
			'theme_review_' . get_stylesheet(),
			'design',
			sprintf( __( 'بازبینی ظاهر قالب «%s»', 'agent-wp' ), $theme->get( 'Name' ) ),
			__( 'موبایل، هدر و فوتر را یک‌بار مرور کنید؛ اگر شلوغ است ساده کنید.', 'agent-wp' ),
			3,
			array( 'action' => 'review' )
		);
	}

	private static function scan_seo_basics() {
		$tagline = (string) get_bloginfo( 'description' );
		if ( '' === trim( $tagline ) ) {
			Agent_WP_Assistant::upsert_suggestion(
				'empty_tagline',
				'seo',
				__( 'معرفی کوتاه سایت خالی است', 'agent-wp' ),
				__( 'یک شعار/توضیح ۱ جمله‌ای در تنظیمات بنویسید تا هویت سایت مشخص شود.', 'agent-wp' ),
				7,
				array( 'action' => 'edit' )
			);
		}

		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front ) {
			$p = get_post( $front );
			if ( $p && mb_strlen( trim( wp_strip_all_tags( $p->post_content ) ) ) < 80 ) {
				Agent_WP_Assistant::upsert_suggestion(
					'thin_homepage',
					'seo',
					__( 'صفحه اصلی محتوایش نازک است', 'agent-wp' ),
					__( 'بخش معرفی، خدمات و دعوت به اقدام را غنی‌تر کنید.', 'agent-wp' ),
					9,
					array( 'postId' => $front, 'action' => 'edit' )
				);
			}
		}
	}

	private static function scan_technical() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$inactive = count( $all ) - count( $active );
		if ( $inactive >= 3 ) {
			Agent_WP_Assistant::upsert_suggestion(
				'inactive_plugins',
				'cleanup',
				sprintf( __( '%d افزونه غیرفعال دارید', 'agent-wp' ), $inactive ),
				__( 'افزونه‌های بلااستفاده را حذف کنید تا امنیت و سرعت بهتر شود.', 'agent-wp' ),
				6,
				array( 'action' => 'delete' )
			);
		}

		$menus = wp_get_nav_menus();
		if ( ! $menus ) {
			Agent_WP_Assistant::upsert_suggestion(
				'no_menu',
				'technical',
				__( 'منوی ناوبری ساخته نشده', 'agent-wp' ),
				__( 'یک منوی اصلی با لینک‌های مهم بسازید تا کاربر گم نشود.', 'agent-wp' ),
				7,
				array( 'action' => 'add' )
			);
		}
	}

	private static function scan_growth() {
		$topic = self::topic_hint();
		$day   = gmdate( 'z' ); // پیشنهاد چرخشی روزانه
		$ideas = array(
			sprintf( __( 'یک FAQ کوتاه درباره «%s» به سایت اضافه کنید.', 'agent-wp' ), $topic ),
			sprintf( __( 'یک صفحه «درباره ما» قوی‌تر حول «%s» بنویسید.', 'agent-wp' ), $topic ),
			__( 'یک CTA واضح در صفحه اصلی بگذارید (تماس / خرید / درخواست مشاوره).', 'agent-wp' ),
			__( '۳ پست شبکه‌اجتماعی از محتوای موجود سایت استخراج کنید.', 'agent-wp' ),
			__( 'نظرات مشتریان یا نمونه‌کار را اگر ندارید، اضافه کنید.', 'agent-wp' ),
		);
		$idea = $ideas[ $day % count( $ideas ) ];
		Agent_WP_Assistant::upsert_suggestion(
			'daily_growth_' . gmdate( 'Y-m-d' ),
			'growth',
			__( 'پیشنهاد رشد امروز', 'agent-wp' ),
			$idea,
			8,
			array( 'action' => 'add', 'daily' => true )
		);
	}

	private static function scan_cleanup() {
		$revisions = get_posts(
			array(
				'post_type'      => 'revision',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		// شمارش تقریبی برگه‌های خصوصی قدیمی
		$private = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'private',
				'posts_per_page' => 5,
			)
		);
		foreach ( $private as $p ) {
			Agent_WP_Assistant::upsert_suggestion(
				'private_' . $p->ID,
				'cleanup',
				sprintf( __( 'محتوای خصوصی: «%s»', 'agent-wp' ), $p->post_title ),
				__( 'اگر دیگر لازم نیست حذف یا منتشرش کنید تا انبار محتوا خلوت شود.', 'agent-wp' ),
				4,
				array( 'postId' => (int) $p->ID, 'action' => 'delete' )
			);
		}
	}

	private static function scan_foresight() {
		$topic = self::topic_hint();
		$week  = (int) gmdate( 'W' );
		$ideas = array(
			sprintf( __( 'برای فصل بعد یک لندینگ حول «%s» طراحی کنید.', 'agent-wp' ), $topic ),
			__( 'لیست ۱۰ کلمه کلیدی هدف را مشخص کنید و برای هر کدام یک صفحه/نوشته برنامه‌ریزی کنید.', 'agent-wp' ),
			__( 'نسخه موبایل را با ۳ کاربر واقعی تست کنید و اصطکاک‌ها را یادداشت کنید.', 'agent-wp' ),
			__( 'یک قیف ساده: بازدید → تماس/خرید را تعریف و در سایت پیاده کنید.', 'agent-wp' ),
		);
		$idea = $ideas[ $week % count( $ideas ) ];
		Agent_WP_Assistant::upsert_suggestion(
			'foresight_w' . $week,
			'foresight',
			__( 'آینده‌نگری این هفته', 'agent-wp' ),
			$idea,
			7,
			array( 'action' => 'foresight' )
		);
	}

	private static function scan_quality() {
		// نوشته‌های منتشرشده بدون عنوان واقعی
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $p ) {
			$title = trim( get_the_title( $p ) );
			if ( '' === $title || 'بدون عنوان' === $title || '(no title)' === strtolower( $title ) ) {
				Agent_WP_Assistant::upsert_suggestion(
					'no_title_' . $p->ID,
					'content',
					sprintf( __( 'محتوا #%d عنوان ندارد', 'agent-wp' ), $p->ID ),
					__( 'یک عنوان واضح و جذاب بنویسید تا در نتایج و منو درست دیده شود.', 'agent-wp' ),
					8,
					array( 'postId' => (int) $p->ID, 'action' => 'edit' )
				);
			}
		}

		// تصاویر پیوست اخیر بدون alt
		$media = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 15,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$missing_alt = 0;
		foreach ( $media as $m ) {
			$alt = get_post_meta( $m->ID, '_wp_attachment_image_alt', true );
			if ( '' === trim( (string) $alt ) ) {
				$missing_alt++;
			}
		}
		if ( $missing_alt >= 3 ) {
			Agent_WP_Assistant::upsert_suggestion(
				'images_missing_alt',
				'seo',
				sprintf( __( '%d تصویر بدون متن جایگزین (alt)', 'agent-wp' ), $missing_alt ),
				__( 'برای دسترسی‌پذیری و سئو، alt توصیفی به تصاویر مهم اضافه کنید.', 'agent-wp' ),
				6,
				array( 'action' => 'edit', 'count' => $missing_alt )
			);
		}

		// منو بدون لینک
		$menus = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) || ! $items ) {
				Agent_WP_Assistant::upsert_suggestion(
					'empty_menu_' . $menu->term_id,
					'technical',
					sprintf( __( 'منوی «%s» خالی است', 'agent-wp' ), $menu->name ),
					__( 'صفحات مهم را به منو اضافه کنید تا مسیر کاربر کامل شود.', 'agent-wp' ),
					7,
					array( 'action' => 'add', 'menuId' => (int) $menu->term_id )
				);
			}
		}

		// پیش‌نویس‌های کهنه (بیش از ۱۴ روز)
		$stale = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'draft',
				'posts_per_page' => 8,
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ),
					),
				),
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);
		foreach ( $stale as $p ) {
			Agent_WP_Assistant::upsert_suggestion(
				'stale_draft_' . $p->ID,
				'cleanup',
				sprintf( __( 'پیش‌نویس کهنه: %s', 'agent-wp' ), get_the_title( $p ) ? get_the_title( $p ) : '#' . $p->ID ),
				__( 'یا تکمیل و منتشر کنید، یا اگر زائد است حذف/بایگانی کنید.', 'agent-wp' ),
				5,
				array( 'postId' => (int) $p->ID, 'action' => 'edit' )
			);
		}

		// محتوای منتشرشده خیلی کوتاه / خالی
		$thin = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 12,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $thin as $p ) {
			$plain = trim( wp_strip_all_tags( (string) $p->post_content ) );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );
			if ( $len < 40 ) {
				Agent_WP_Assistant::upsert_suggestion(
					'thin_content_' . $p->ID,
					'content',
					sprintf( __( 'محتوای خیلی کوتاه: %s', 'agent-wp' ), get_the_title( $p ) ),
					__( 'متن مفیدی اضافه کنید یا صفحه را به لینک/ریدایرکت معنادار تبدیل کنید.', 'agent-wp' ),
					6,
					array( 'postId' => (int) $p->ID, 'action' => 'edit' )
				);
			}
		}
	}

	private static function scan_duplicates() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT post_title, COUNT(*) AS c, MIN(ID) AS id
			FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_title <> ''
			GROUP BY post_title
			HAVING c > 1
			LIMIT 8",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $r ) {
			Agent_WP_Assistant::upsert_suggestion(
				'dup_title_' . md5( $r['post_title'] ),
				'content',
				sprintf( __( 'عنوان تکراری: %s', 'agent-wp' ), $r['post_title'] ),
				sprintf(
					/* translators: %d count */
					__( '%d محتوا با عنوان یکسان — یکی را متمایز یا ادغام کنید.', 'agent-wp' ),
					(int) $r['c']
				),
				6,
				array( 'postId' => (int) $r['id'], 'action' => 'edit' )
			);
		}
	}

	private static function scan_plugin_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		if ( ! is_array( $updates ) || ! $updates ) {
			return;
		}
		$n = count( $updates );
		Agent_WP_Assistant::upsert_suggestion(
			'plugin_updates',
			'technical',
			sprintf( __( '%d افزونه به‌روزرسانی دارد', 'agent-wp' ), $n ),
			__( 'پس از پشتیبان، افزونه‌ها را به‌روز کنید تا امنیت و سازگاری حفظ شود.', 'agent-wp' ),
			8,
			array( 'action' => 'update', 'count' => $n )
		);
	}

	private static function scan_menu_links() {
		$menus = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$bad = 0;
			foreach ( $items as $item ) {
				$url = isset( $item->url ) ? trim( (string) $item->url ) : '';
				if ( '' === $url || '#' === $url ) {
					$bad++;
				}
			}
			if ( $bad > 0 ) {
				Agent_WP_Assistant::upsert_suggestion(
					'menu_bad_links_' . $menu->term_id,
					'technical',
					sprintf( __( 'منوی «%s»: لینک ناقص', 'agent-wp' ), $menu->name ),
					sprintf(
						/* translators: %d bad links */
						__( '%d آیتم بدون آدرس معتبر — مسیر کاربر را اصلاح کنید.', 'agent-wp' ),
						$bad
					),
					7,
					array( 'action' => 'edit', 'menuId' => (int) $menu->term_id )
				);
			}
		}
	}
}
