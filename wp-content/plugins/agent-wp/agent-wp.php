<?php
/**
 * Plugin Name: Agent WP
 * Plugin URI: https://example.com/agent-wp
 * Description: ایجنت وردپرس — چت اکشن‌محور + دستیار زنده شناور روی سایت.
 * Version: 0.20.1
 * Author: Work in Progress
 * Text Domain: agent-wp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_WP_VERSION', '0.20.1' );
define( 'AGENT_WP_DB_VERSION', '4' );
define( 'AGENT_WP_FILE', __FILE__ );
define( 'AGENT_WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'AGENT_WP_URL', plugin_dir_url( __FILE__ ) );

require_once AGENT_WP_PATH . 'includes/class-crypto.php';
require_once AGENT_WP_PATH . 'includes/class-install.php';
require_once AGENT_WP_PATH . 'includes/class-models.php';
require_once AGENT_WP_PATH . 'includes/class-chats.php';
require_once AGENT_WP_PATH . 'includes/class-action-log.php';
require_once AGENT_WP_PATH . 'includes/class-fs.php';
require_once AGENT_WP_PATH . 'includes/class-pending.php';
require_once AGENT_WP_PATH . 'includes/class-debug.php';
require_once AGENT_WP_PATH . 'includes/class-health.php';
require_once AGENT_WP_PATH . 'includes/class-gapgpt.php';
require_once AGENT_WP_PATH . 'includes/class-model-router.php';
require_once AGENT_WP_PATH . 'includes/class-llm.php';
require_once AGENT_WP_PATH . 'includes/class-agent.php';
require_once AGENT_WP_PATH . 'includes/class-assistant.php';
require_once AGENT_WP_PATH . 'includes/class-assistant-scan.php';
require_once AGENT_WP_PATH . 'includes/class-assistant-frontend.php';
require_once AGENT_WP_PATH . 'includes/class-page-context.php';
require_once AGENT_WP_PATH . 'includes/class-editor-assist.php';
require_once AGENT_WP_PATH . 'includes/tools/interface-tool.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-result.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-registry.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-ping.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-create-page.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-create-post.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-update-site-title.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-site-info.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-list-pages.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-list-posts.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-get-content.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-update-content.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-trash-content.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-update-option.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-list-files.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-read-file.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-write-file.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-custom-css.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-media.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-generate-image.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-generate-speech.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-generate-video.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-transcribe-audio.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-terms.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-menus.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-search-replace.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-plugins-themes.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-woo-products.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-discover.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-wp-content.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-post-meta.php';
require_once AGENT_WP_PATH . 'includes/tools/class-tool-rest.php';
require_once AGENT_WP_PATH . 'includes/class-ajax.php';
require_once AGENT_WP_PATH . 'includes/class-admin.php';

/**
 * چرا bootstrap نازک: هسته امنیتی/Tool/لاگ جدا از UI می‌ماند تا رشد بعدی بدون بازنویسی باشد.
 */
final class Agent_WP_Plugin {

	public static function init() {
		Agent_WP_Install::maybe_upgrade();
		Agent_WP_Models::ensure_gapgpt_gateway();

		$registry = Agent_WP_Tool_Registry::instance();
		$registry->register( new Agent_WP_Tool_Ping() );
		$registry->register( new Agent_WP_Tool_Discover() );
		$registry->register( new Agent_WP_Tool_Site_Info() );
		$registry->register( new Agent_WP_Tool_Wp_Content() );
		$registry->register( new Agent_WP_Tool_List_Pages() );
		$registry->register( new Agent_WP_Tool_List_Posts() );
		$registry->register( new Agent_WP_Tool_Get_Content() );
		$registry->register( new Agent_WP_Tool_Create_Page() );
		$registry->register( new Agent_WP_Tool_Create_Post() );
		$registry->register( new Agent_WP_Tool_Update_Content() );
		$registry->register( new Agent_WP_Tool_Trash_Content() );
		$registry->register( new Agent_WP_Tool_Post_Meta() );
		$registry->register( new Agent_WP_Tool_Update_Site_Title() );
		$registry->register( new Agent_WP_Tool_Update_Option() );
		$registry->register( new Agent_WP_Tool_Rest() );
		$registry->register( new Agent_WP_Tool_List_Files() );
		$registry->register( new Agent_WP_Tool_Read_File() );
		$registry->register( new Agent_WP_Tool_Write_File() );
		$registry->register( new Agent_WP_Tool_Custom_Css() );
		$registry->register( new Agent_WP_Tool_Media() );
		$registry->register( new Agent_WP_Tool_Generate_Image() );
		$registry->register( new Agent_WP_Tool_Generate_Speech() );
		$registry->register( new Agent_WP_Tool_Generate_Video() );
		$registry->register( new Agent_WP_Tool_Transcribe_Audio() );
		$registry->register( new Agent_WP_Tool_Terms() );
		$registry->register( new Agent_WP_Tool_Menus() );
		$registry->register( new Agent_WP_Tool_Search_Replace() );
		$registry->register( new Agent_WP_Tool_Plugins_Themes() );
		$registry->register( new Agent_WP_Tool_Woo_Products() );

		Agent_WP_Ajax::init();
		Agent_WP_Admin::init();
		Agent_WP_Assistant::init();
		Agent_WP_Assistant_Frontend::init();
		Agent_WP_Editor_Assist::init();
	}
}

register_activation_hook( __FILE__, array( 'Agent_WP_Install', 'activate' ) );
add_action( 'plugins_loaded', array( 'Agent_WP_Plugin', 'init' ) );
