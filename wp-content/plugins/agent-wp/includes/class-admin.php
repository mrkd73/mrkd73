<?php
/**
 * ثبت منوی پیشخوان و بارگذاری دارایی‌های صفحه چت.
 * چرا جدا: لایه ادمین از منطق آینده Agent جدا بماند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Admin {

	const MENU_SLUG = 'agent-wp-chat';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_admin_chrome' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_health_notice' ) );
	}

	public static function activation_health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_transient( 'agent_wp_show_health_notice' ) ) {
			return;
		}
		delete_transient( 'agent_wp_show_health_notice' );
		$report = Agent_WP_Health::report();
		$class  = $report['ok'] ? 'notice notice-success is-dismissible' : 'notice notice-warning is-dismissible';
		echo '<div class="' . esc_attr( $class ) . '"><p><strong>Agent WP:</strong> ' . esc_html( $report['summary'] ) . '</p></div>';
	}

	public static function register_menu() {
		add_menu_page(
			__( 'ایجنت وردپرس', 'agent-wp' ),
			__( 'ایجنت وردپرس', 'agent-wp' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-chat',
			3
		);
	}

	/**
	 * چرا: فونت باینری را یک‌بار از افزونه موجود سایت کپی می‌کنیم تا وابسته به شل نباشیم.
	 */
	private static function ensure_iransans_font() {
		$dst_dir = AGENT_WP_PATH . 'assets/fonts/iransans/';
		$dst     = $dst_dir . 'iransans.woff2';
		if ( file_exists( $dst ) ) {
			return;
		}

		$src = WP_PLUGIN_DIR . '/persian-woocommerce/assets/fonts/iransans/iransans.woff2';
		if ( ! file_exists( $src ) ) {
			return;
		}

		if ( ! is_dir( $dst_dir ) ) {
			wp_mkdir_p( $dst_dir );
		}

		@copy( $src, $dst );
	}

	public static function is_chat_screen( $hook_suffix = '' ) {
		if ( $hook_suffix ) {
			return false !== strpos( $hook_suffix, self::MENU_SLUG );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && isset( $screen->id ) && false !== strpos( $screen->id, self::MENU_SLUG );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::is_chat_screen( $hook_suffix ) ) {
			return;
		}

		self::ensure_iransans_font();

		// ایران‌سنس برای ظاهر فارسی
		wp_enqueue_style(
			'agent-wp-iransans',
			AGENT_WP_URL . 'assets/css/iransans.css',
			array(),
			AGENT_WP_VERSION
		);

		wp_enqueue_style(
			'agent-wp-chat',
			AGENT_WP_URL . 'assets/css/chat.css',
			array( 'agent-wp-iransans' ),
			AGENT_WP_VERSION
		);

		wp_enqueue_script(
			'agent-wp-chat',
			AGENT_WP_URL . 'assets/js/chat.js',
			array(),
			AGENT_WP_VERSION,
			true
		);

		// دادهٔ اولیه سمت سرور تا اولین فریم خالی نماند (بدون انتظار AJAX).
		$initial_chats    = Agent_WP_Chats::ensure_default_for_user();
		$initial_chat_id  = ! empty( $initial_chats[0]['id'] ) ? (int) $initial_chats[0]['id'] : 0;
		$initial_messages = $initial_chat_id ? Agent_WP_Chats::list_messages( $initial_chat_id ) : array();
		if ( is_wp_error( $initial_messages ) ) {
			$initial_messages = array();
		}

		wp_localize_script(
			'agent-wp-chat',
			'agentWpChat',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'agent_wp_admin' ),
				'initialChats'     => $initial_chats,
				'initialChatId'    => $initial_chat_id,
				'initialMessages'  => $initial_messages,
				'providerPresets'  => array(
					'gapgpt' => array(
						'label'    => 'GapGPT',
						'baseUrl'  => Agent_WP_Models::get_gapgpt_base(),
						'model'    => 'gpt-4o',
						'models'   => wp_list_pluck( Agent_WP_Models::gapgpt_catalog_merged(), 'id' ),
						'keyHint'  => Agent_WP_Models::get_gapgpt_key_mask() ? Agent_WP_Models::get_gapgpt_key_mask() : 'sk-...',
						'free'     => false,
						'helpHtml' => 'همه مدل‌ها از GapGPT؛ Direct یا CDN.',
					),
				),
				'defaultModelId'   => Agent_WP_Models::get_default_id(),
				'preferAutoModel'  => true,
				'gapgptKeyReady'   => '' !== Agent_WP_Models::get_gapgpt_key(),
				'gapgptMode'       => Agent_WP_Models::get_gapgpt_mode(),
				'gapgptBaseUrl'    => Agent_WP_Models::get_gapgpt_base(),
				'gapgptCustomBase' => Agent_WP_Models::get_gapgpt_custom_base(),
				'siteTopic'        => Agent_WP_Assistant::get_topic() ? Agent_WP_Assistant::get_topic() : get_bloginfo( 'name' ),
				'toolsCatalog'     => Agent_WP_Models::gapgpt_catalog_by_category(),
				'toolsCategoryUi'  => Agent_WP_Models::category_ui(),
				'i18n'             => array(
					'placeholder'     => __( 'پیام', 'agent-wp' ),
					'online'          => __( 'آنلاین', 'agent-wp' ),
					'emptyTitle'      => __( 'هنوز پیامی نیست', 'agent-wp' ),
					'emptyHint'       => __( 'یک پیام بفرست تا ظاهر چت زنده شود.', 'agent-wp' ),
					'assistantName'   => __( 'دستیار', 'agent-wp' ),
					'mockReply'       => __( 'فعلاً فقط ظاهر فعال است. منطق هوش مصنوعی بعداً وصل می‌شود.', 'agent-wp' ),
					'saveOk'          => __( 'مدل روی سرور ذخیره شد.', 'agent-wp' ),
					'saveFail'        => __( 'ذخیره مدل ناموفق بود.', 'agent-wp' ),
					'noModels'        => __( 'مدلی انتخاب نشده', 'agent-wp' ),
					'addFromSettings' => __( 'مدل‌ها را از تنظیمات اضافه کنید', 'agent-wp' ),
					'loadFail'        => __( 'بارگذاری گفتگوها ناموفق بود.', 'agent-wp' ),
					'creditLabel'     => __( 'اعتبار باقی‌مانده', 'agent-wp' ),
					'creditLow'       => __( 'اعتبار کم است؛ از پنل GapGPT شارژ کنید.', 'agent-wp' ),
					'creditFail'      => __( 'خواندن اعتبار ناموفق بود.', 'agent-wp' ),
					'creditRefresh'   => __( 'بروزرسانی اعتبار', 'agent-wp' ),
					'tokens'          => __( 'توکن', 'agent-wp' ),
				),
			)
		);
	}

	/**
	 * چرا: شلوغی کروم پیشخوان وردپرس حس تلگرام را خراب می‌کند.
	 */
	public static function hide_admin_chrome() {
		if ( ! self::is_chat_screen() ) {
			return;
		}
		?>
		<style id="agent-wp-admin-reset">
			/*
			 * چرا: padding صفر برای فیت شدن چت؛ ولی margin افقی #wpcontent را دست نزن
			 * تا با جمع/باز شدن منوی پیشخوان، عرض چت خودش بچسبد و بزرگ/کوچک شود.
			 */
			#wpcontent {
				padding: 0 !important;
			}
			#wpbody,
			#wpbody-content {
				padding: 0 !important;
				margin: 0 !important;
				float: none;
				width: auto !important;
				max-width: 100%;
			}
			#wpbody-content > .wrap {
				margin: 0;
				max-width: 100%;
			}
			#wpa-app {
				font-family: IRANSans, Tahoma, sans-serif !important;
			}
			#wpa-app button,
			#wpa-app input,
			#wpa-app select,
			#wpa-app textarea,
			#wpa-app optgroup,
			#wpa-app option,
			#wpa-app label,
			#wpa-app .button,
			#wpa-app .button-primary,
			#wpa-app .button-secondary {
				font-family: IRANSans, Tahoma, sans-serif !important;
			}
			#wpa-app textarea,
			#wpa-app input[type="text"],
			#wpa-app input[type="search"],
			#wpa-app input[type="password"],
			#wpa-app input[type="url"] {
				box-shadow: none;
			}
			#wpfooter,
			#screen-meta,
			#screen-meta-links,
			.notice,
			.update-nag,
			.updated,
			.error,
			.is-dismissible {
				display: none !important;
			}
			/* موبایل پیشخوان: منو اورلی است؛ margin افقی صفر می‌ماند */
			@media screen and (max-width: 782px) {
				#wpbody-content {
					padding-bottom: 0 !important;
				}
			}
		</style>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require AGENT_WP_PATH . 'admin/views/chat-page.php';
	}
}
