<?php
/**
 * ویجت شناور دستیار روی فرانت و پیشخوان (برای مدیر سایت).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Assistant_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_root' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_root_admin' ), 99 );
	}

	public static function should_show() {
		if ( ! Agent_WP_Assistant::is_enabled()
			|| ! is_user_logged_in()
			|| ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$surface = Agent_WP_Assistant::get_surface();
		if ( 'front' === $surface && is_admin() ) {
			return false;
		}
		if ( 'admin' === $surface && ! is_admin() ) {
			return false;
		}
		return true;
	}

	public static function enqueue() {
		if ( ! self::should_show() || is_admin() ) {
			return;
		}
		self::enqueue_assets( false );
	}

	public static function enqueue_admin() {
		if ( ! self::should_show() ) {
			return;
		}
		// روی صفحه چت اصلی Agent WP ویجت شناور لازم نیست
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'agent-wp' ) ) {
			return;
		}
		self::enqueue_assets( true );
	}

	private static function enqueue_assets( $is_admin_ctx ) {
		$font = AGENT_WP_PATH . 'assets/fonts/iransans/iransans.woff2';
		if ( file_exists( $font ) ) {
			wp_enqueue_style(
				'agent-wp-iransans',
				AGENT_WP_URL . 'assets/css/iransans.css',
				array(),
				AGENT_WP_VERSION
			);
		}

		wp_enqueue_style(
			'agent-wp-assistant',
			AGENT_WP_URL . 'assets/css/assistant.css',
			array(),
			AGENT_WP_VERSION
		);

		wp_enqueue_script(
			'agent-wp-assistant',
			AGENT_WP_URL . 'assets/js/assistant.js',
			array(),
			AGENT_WP_VERSION,
			true
		);

		$page = self::current_page_context( $is_admin_ctx );

		wp_localize_script(
			'agent-wp-assistant',
			'agentWpAssistant',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'agent_wp_admin' ),
				'openCount'     => Agent_WP_Assistant::count_open(),
				'topic'         => Agent_WP_Assistant::get_topic(),
				'siteName'      => get_bloginfo( 'name' ),
				'fabSide'       => Agent_WP_Assistant::get_fab_side(),
				'lastScan'      => (int) get_option( Agent_WP_Assistant::OPTION_LAST_SCAN, 0 ),
				'lastScanLabel' => Agent_WP_Assistant::last_scan_label(),
				'notify'        => Agent_WP_Assistant::notify_enabled(),
				'sound'         => Agent_WP_Assistant::sound_enabled(),
				'pendingTtl'    => Agent_WP_Pending::TTL,
				'isAdmin'       => (bool) $is_admin_ctx,
				'adminChatUrl'  => admin_url( 'admin.php?page=agent-wp-chat' ),
				'page'          => $page,
				'i18n'          => array(
					'title'           => __( 'دستیار', 'agent-wp' ),
					'subtitle'        => __( 'همراه رشد سایت شما', 'agent-wp' ),
					'placeholder'     => __( 'با دستیار حرف بزن… (Enter)', 'agent-wp' ),
					'suggestions'     => __( 'پیشنهادهای امروز', 'agent-wp' ),
					'pageSuggestions' => __( 'برای همین صفحه', 'agent-wp' ),
					'pageAware'       => __( 'صفحه را می‌شناسم', 'agent-wp' ),
					'empty'           => __( 'هنوز پیشنهادی نیست؛ چند لحظه دیگر برمی‌گردم.', 'agent-wp' ),
					'sendFail'        => __( 'ارسال ناموفق بود.', 'agent-wp' ),
					'dismiss'         => __( 'رد کردن', 'agent-wp' ),
					'done'            => __( 'انجام شد', 'agent-wp' ),
					'doIt'            => __( 'انجام بده', 'agent-wp' ),
					'scan'            => __( 'اسکن دوباره', 'agent-wp' ),
					'digest'          => __( 'خلاصه امروز', 'agent-wp' ),
					'copyDigest'      => __( 'کپی خلاصه', 'agent-wp' ),
					'copied'          => __( 'کپی شد', 'agent-wp' ),
					'clearChat'       => __( 'پاک کردن چت', 'agent-wp' ),
					'clearConfirm'    => __( 'تاریخچه چت دستیار پاک شود؟', 'agent-wp' ),
					'edit'            => __( 'ویرایش', 'agent-wp' ),
					'online'          => __( 'مشاور آنلاین', 'agent-wp' ),
					'welcome'         => __( 'سلام. اگر بخواهی، خلاصه کوتاه امروز را می‌گویم — جزئیات فقط وقتی بخواهی.', 'agent-wp' ),
					'historyFail'     => __( 'بارگذاری تاریخچه ناموفق بود.', 'agent-wp' ),
					'filterOpen'      => __( 'امروز', 'agent-wp' ),
					'filterDone'      => __( 'آرشیو', 'agent-wp' ),
					'filterDismissed' => __( 'ردشده', 'agent-wp' ),
					'reopen'          => __( 'باز کردن دوباره', 'agent-wp' ),
					'snooze'          => __( 'بعداً', 'agent-wp' ),
					'confirm'         => __( 'تأیید اجرا', 'agent-wp' ),
					'confirmRisk'     => __( 'متوجه شدم — تأیید', 'agent-wp' ),
					'riskConfirm'     => __( 'تغییر کد قالب یا افزونه — ممکن است سایت خراب شود. ادامه می‌دهید؟', 'agent-wp' ),
					'cancel'          => __( 'لغو', 'agent-wp' ),
					'confirmFail'     => __( 'تأیید ناموفق بود.', 'agent-wp' ),
					'cancelled'       => __( 'لغو شد', 'agent-wp' ),
					'awaitConfirm'    => __( 'در انتظار تأیید', 'agent-wp' ),
					'toolOk'          => __( 'موفق', 'agent-wp' ),
					'toolFail'        => __( 'ناموفق', 'agent-wp' ),
					'autoDone'        => __( 'پیشنهاد مرتبط انجام شد.', 'agent-wp' ),
					'scanToast'       => __( 'بررسی تازه تمام شد', 'agent-wp' ),
					'shortcutHint'    => __( 'Alt+A', 'agent-wp' ),
					'search'          => __( 'جستجو…', 'agent-wp' ),
					'export'          => __( 'خروجی', 'agent-wp' ),
					'markAllDone'     => __( 'همه انجام', 'agent-wp' ),
					'markAllDismiss'  => __( 'همه رد', 'agent-wp' ),
					'minimize'        => __( 'جمع کردن', 'agent-wp' ),
					'allCats'         => __( 'همه', 'agent-wp' ),
					'ttlLeft'         => __( 'مانده', 'agent-wp' ),
					'more'            => __( 'بیشتر', 'agent-wp' ),
					'back'            => __( 'بازگشت', 'agent-wp' ),
					'talk'            => __( 'گفتگو', 'agent-wp' ),
					'askMore'         => __( 'جزئیات بیشتر', 'agent-wp' ),
					'calmEmpty'       => __( 'الان مورد فوری نیست. اگر بخواهی دوباره سبک چک می‌کنم.', 'agent-wp' ),
					'startHere'       => __( 'از اینجا شروع کن', 'agent-wp' ),
					'ideasTab'        => __( 'خلاصه', 'agent-wp' ),
					'chatTab'         => __( 'گفتگو', 'agent-wp' ),
					'openEditor'      => __( 'باز کردن ابزار', 'agent-wp' ),
				),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function current_page_context( $is_admin_ctx ) {
		$post_id     = 0;
		$comment_id  = 0;
		$screen_id   = '';
		$title       = '';
		$post_type   = '';
		$option_page = '';
		$is_new      = false;

		if ( $is_admin_ctx && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$screen_id = (string) $screen->id;
				if ( ! empty( $screen->post_type ) ) {
					$post_type = (string) $screen->post_type;
				}
				if ( ! empty( $screen->action ) && 'add' === $screen->action ) {
					$is_new = true;
				}
			}
			if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( ! $post_id && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ) {
				$post_id = (int) $GLOBALS['post']->ID;
			}
			if ( isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( isset( $_GET['c'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$comment_id = absint( $_GET['c'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$option_page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( false !== strpos( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ), 'post-new.php' ) ) {
				$is_new = true;
			}
		} else {
			if ( is_singular() ) {
				$post_id = (int) get_queried_object_id();
			}
			$title = wp_get_document_title();
		}

		if ( $post_id ) {
			$title = get_the_title( $post_id );
		}

		return Agent_WP_Assistant::live_page_context(
			array(
				'url'        => $is_admin_ctx
					? ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' )
					: ( function_exists( 'home_url' ) ? home_url( add_query_arg( array() ) ) : '' ),
				'screen'     => $screen_id,
				'postId'     => $post_id,
				'isAdmin'    => (bool) $is_admin_ctx,
				'title'      => $title,
				'commentId'  => $comment_id,
				'postType'   => $post_type,
				'optionPage' => $option_page,
				'isNew'      => $is_new,
			)
		);
	}

	public static function render_root() {
		if ( ! self::should_show() || is_admin() ) {
			return;
		}
		echo '<div id="wpa-assistant-root" class="wpa-as" dir="rtl" lang="fa" hidden></div>';
	}

	public static function render_root_admin() {
		if ( ! self::should_show() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'agent-wp' ) ) {
			return;
		}
		echo '<div id="wpa-assistant-root" class="wpa-as wpa-as--admin" dir="rtl" lang="fa" hidden></div>';
	}
}
