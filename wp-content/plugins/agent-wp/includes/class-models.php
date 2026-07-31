<?php
/**
 * مخزن مدل‌های AI — فقط سمت سرور؛ کلید رمزنگاری‌شده.
 * درگاه واحد: GapGPT (مدل‌های مختلف با یک کلید + حالت Direct/CDN).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Models {

	const GAPGPT_BASE_DIRECT  = 'https://api.gapgpt.app/v1';
	const GAPGPT_BASE_CDN     = 'https://api.gapapi.com/v1';
	const GAPGPT_KEY_OPTION   = 'agent_wp_gapgpt_key_enc';
	const GAPGPT_MODE_OPTION  = 'agent_wp_gapgpt_endpoint'; // direct | cdn | custom
	const GAPGPT_CUSTOM_BASE_OPTION = 'agent_wp_gapgpt_custom_base';

	/** @deprecated استفاده از get_gapgpt_base() */
	const GAPGPT_BASE = 'https://api.gapgpt.app/v1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_wp_models';
	}

	public static function get_gapgpt_custom_base() {
		$url = esc_url_raw( (string) get_option( self::GAPGPT_CUSTOM_BASE_OPTION, '' ) );
		return $url ? untrailingslashit( $url ) : '';
	}

	public static function get_gapgpt_base() {
		$mode = self::get_gapgpt_mode();
		if ( 'cdn' === $mode ) {
			return self::GAPGPT_BASE_CDN;
		}
		if ( 'custom' === $mode ) {
			$custom = self::get_gapgpt_custom_base();
			return $custom ? $custom : self::GAPGPT_BASE_DIRECT;
		}
		return self::GAPGPT_BASE_DIRECT;
	}

	public static function get_gapgpt_mode() {
		$mode = (string) get_option( self::GAPGPT_MODE_OPTION, 'direct' );
		if ( 'cdn' === $mode ) {
			return 'cdn';
		}
		if ( 'custom' === $mode ) {
			return 'custom';
		}
		return 'direct';
	}

	/**
	 * @param string $mode direct|cdn|custom
	 * @param string $custom_base فقط برای custom
	 */
	public static function set_gapgpt_mode( $mode, $custom_base = '' ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( 'direct', 'cdn', 'custom' ), true ) ) {
			$mode = 'direct';
		}
		if ( 'custom' === $mode ) {
			$custom_base = esc_url_raw( (string) $custom_base );
			if ( '' === $custom_base ) {
				$custom_base = self::get_gapgpt_custom_base();
			}
			if ( '' === $custom_base ) {
				$custom_base = self::GAPGPT_BASE_DIRECT;
			}
			update_option( self::GAPGPT_CUSTOM_BASE_OPTION, untrailingslashit( $custom_base ), false );
		}
		update_option( self::GAPGPT_MODE_OPTION, $mode, false );
		$plain = self::get_gapgpt_key();
		$base  = self::get_gapgpt_base();
		if ( '' === $plain ) {
			return $mode;
		}
		// همگام‌سازی Base همه مدل‌های gapgpt
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id FROM " . self::table() . " WHERE provider = 'gapgpt'", ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				self::update_key_and_base( (int) $row['id'], $plain, $base );
			}
		}
		return $mode;
	}

	/**
	 * ذخیره یکجای API پیش‌فرض: حالت، آدرس سفارشی، کلید، مدل پیش‌فرض.
	 *
	 * @param array{mode?:string,custom_base?:string,api_key?:string,default_model_id?:int} $args
	 * @return array{mode:string,baseUrl:string,keyMask:string,defaultModelId:int,models:array}
	 */
	public static function save_default_api( array $args ) {
		$mode        = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : self::get_gapgpt_mode();
		$custom_base = isset( $args['custom_base'] ) ? (string) $args['custom_base'] : self::get_gapgpt_custom_base();
		$api_key     = isset( $args['api_key'] ) ? trim( (string) $args['api_key'] ) : '';
		$default_id  = isset( $args['default_model_id'] ) ? absint( $args['default_model_id'] ) : 0;

		self::set_gapgpt_mode( $mode, $custom_base );

		if ( '' !== $api_key ) {
			self::ensure_gapgpt_gateway( $api_key );
		} else {
			self::ensure_gapgpt_gateway();
		}

		if ( $default_id && self::get( $default_id ) ) {
			self::set_default( $default_id );
		}

		return array(
			'mode'           => self::get_gapgpt_mode(),
			'baseUrl'        => self::get_gapgpt_base(),
			'customBase'     => self::get_gapgpt_custom_base(),
			'keyMask'        => self::get_gapgpt_key_mask(),
			'keyReady'       => '' !== self::get_gapgpt_key(),
			'defaultModelId' => self::get_default_id(),
			'models'         => self::list_public(),
		);
	}

	/**
	 * دسته‌های کاربردی برای UI (نه فقط برند).
	 *
	 * @return array<string,string> id => label
	 */
	public static function categories() {
		return array(
			'coding'    => __( 'کدنویسی', 'agent-wp' ),
			'reasoning' => __( 'استدلال', 'agent-wp' ),
			'chat'      => __( 'گفتگو عمومی', 'agent-wp' ),
			'mini'      => __( 'سریع و مینی', 'agent-wp' ),
			'image'     => __( 'ساخت تصویر', 'agent-wp' ),
			'video'     => __( 'ساخت ویدیو', 'agent-wp' ),
			'audio'     => __( 'ساخت صوت', 'agent-wp' ),
			'vision'    => __( 'بینایی / چندرسانه‌ای', 'agent-wp' ),
			'other'     => __( 'سایر', 'agent-wp' ),
		);
	}

	/**
	 * متادیتای UI برای دسته ابزارها (تلگرام‌وار).
	 *
	 * @return array<string, array{label:string,hint:string,color:string,emoji:string}>
	 */
	public static function category_ui() {
		$labels = self::categories();
		return array(
			'coding'    => array(
				'label' => $labels['coding'],
				'hint'  => __( 'قالب، افزونه، باگ و بازنویسی کد', 'agent-wp' ),
				'color' => '#3390ec',
				'emoji' => '</>',
			),
			'reasoning' => array(
				'label' => $labels['reasoning'],
				'hint'  => __( 'تحلیل عمیق و تصمیم‌های پیچیده', 'agent-wp' ),
				'color' => '#8e6cc9',
				'emoji' => '◎',
			),
			'chat'      => array(
				'label' => $labels['chat'],
				'hint'  => __( 'مشاوره محتوا، لحن و ایده‌پردازی', 'agent-wp' ),
				'color' => '#31a76c',
				'emoji' => '💬',
			),
			'mini'      => array(
				'label' => $labels['mini'],
				'hint'  => __( 'پاسخ سریع و کم‌هزینه', 'agent-wp' ),
				'color' => '#e6a23c',
				'emoji' => '⚡',
			),
			'image'     => array(
				'label' => $labels['image'],
				'hint'  => __( 'بنر، محصول، کاور و تصویر سایت', 'agent-wp' ),
				'color' => '#e05a8c',
				'emoji' => '🖼',
			),
			'video'     => array(
				'label' => $labels['video'],
				'hint'  => __( 'کلیپ کوتاه تبلیغاتی و آموزشی', 'agent-wp' ),
				'color' => '#d94848',
				'emoji' => '🎬',
			),
			'audio'     => array(
				'label' => $labels['audio'],
				'hint'  => __( 'گفتار، نریشن و صداگذاری', 'agent-wp' ),
				'color' => '#5b6bdb',
				'emoji' => '🎧',
			),
			'vision'    => array(
				'label' => $labels['vision'],
				'hint'  => __( 'تحلیل اسکرین و تصویر', 'agent-wp' ),
				'color' => '#2aa8a0',
				'emoji' => '👁',
			),
			'other'     => array(
				'label' => $labels['other'],
				'hint'  => __( 'سایر مدل‌ها', 'agent-wp' ),
				'color' => '#8a939b',
				'emoji' => '⋯',
			),
		);
	}

	/**
	 * متن پیشنهادی شروع گفتگو بر اساس دسته و موضوع سایت.
	 */
	public static function suggested_prompt( $category, $model_label = '', $topic = '' ) {
		$category = sanitize_key( (string) $category );
		$topic    = trim( (string) $topic );
		if ( '' === $topic ) {
			$topic = get_bloginfo( 'name' );
		}
		$model_label = trim( (string) $model_label );
		$model_bit   = $model_label ? ( ' با مدل «' . $model_label . '»' ) : '';

		$map = array(
			'coding'    => sprintf( 'برای سایت «%1$s»%2$s یک بهبود عملی در کد/قالب/افزونه پیشنهاد بده و اگر تأیید کردم با ابزارها اعمال کن.', $topic, $model_bit ),
			'reasoning' => sprintf( 'برای سایت «%1$s»%2$s یک مسئله مهم (فروش، ساختار، یا تجربه کاربری) را عمیق تحلیل کن و ۳ راهکار اولویت‌دار بده.', $topic, $model_bit ),
			'chat'      => sprintf( 'به‌عنوان مشاور سایت «%1$s»%2$s، ۳ ایده محتوا/کمپین این هفته پیشنهاد بده که با هویت سایت جور باشد.', $topic, $model_bit ),
			'mini'      => sprintf( 'برای سایت «%1$s»%2$s خلاصه و سریع بگو الان مهم‌ترین کار بعدی چیست و چرا.', $topic, $model_bit ),
			'image'     => sprintf( 'برای سایت «%1$s»%2$s تصاویر لازم را با generate_image بساز و در رسانه ذخیره کن. اگر لندینگ/صفحه فرود خواستم: اول هیرو و تصاویر بخش‌ها، بعد برگه لندینگ را با همان URLهای رسانه بساز (placeholder جعلی نگذار).', $topic, $model_bit ),
			'video'     => sprintf( 'برای سایت «%1$s»%2$s یک ویدیو کوتاه واقعی بساز (generate_video). پرامپت قوی بنویس، ذخیره کن و لینک را نشان بده.', $topic, $model_bit ),
			'audio'     => sprintf( 'برای سایت «%1$s»%2$s یک نریشن کوتاه با generate_speech بساز، در رسانه ذخیره کن و پخش را نشان بده.', $topic, $model_bit ),
			'vision'    => sprintf( 'برای سایت «%1$s»%2$s بگو برای بررسی ظاهر صفحه چه چیزهایی را در اسکرین‌شات چک کنم؛ بعد اگر تصویر فرستادم تحلیل کن.', $topic, $model_bit ),
			'other'     => sprintf( 'برای سایت «%1$s»%2$s کمک کن هدف این گفتگو را مشخص کنیم و اولین قدم را پیشنهاد بده.', $topic, $model_bit ),
		);

		return isset( $map[ $category ] ) ? $map[ $category ] : $map['other'];
	}

	public static function category_label( $category ) {
		$cats = self::categories();
		$category = sanitize_key( (string) $category );
		return isset( $cats[ $category ] ) ? $cats[ $category ] : $cats['other'];
	}

	/**
	 * کاتالوگ مدل‌ها از طریق GapGPT — با خانواده برند و دسته کاربردی.
	 *
	 * @return array<int, array{id:string,label:string,family:string,category:string}>
	 */
	public static function gapgpt_catalog() {
		$items = array(
			// کدنویسی
			array( 'id' => 'gpt-4.1', 'label' => 'GPT-4.1', 'family' => 'OpenAI', 'category' => 'coding' ),
			array( 'id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4', 'family' => 'Anthropic Claude', 'category' => 'coding' ),
			array( 'id' => 'claude-3-5-sonnet-latest', 'label' => 'Claude 3.5 Sonnet', 'family' => 'Anthropic Claude', 'category' => 'coding' ),
			array( 'id' => 'deepseek-chat', 'label' => 'DeepSeek Chat', 'family' => 'DeepSeek', 'category' => 'coding' ),
			array( 'id' => 'gpt-5.6-sol', 'label' => 'GPT-5.6 Sol', 'family' => 'GPT-5.6', 'category' => 'coding' ),
			array( 'id' => 'codestral-latest', 'label' => 'Codestral', 'family' => 'Mistral', 'category' => 'coding' ),

			// استدلال
			array( 'id' => 'deepseek-reasoner', 'label' => 'DeepSeek Reasoner', 'family' => 'DeepSeek', 'category' => 'reasoning' ),
			array( 'id' => 'claude-opus-4-20250514', 'label' => 'Claude Opus 4', 'family' => 'Anthropic Claude', 'category' => 'reasoning' ),
			array( 'id' => 'claude-3-opus-latest', 'label' => 'Claude 3 Opus', 'family' => 'Anthropic Claude', 'category' => 'reasoning' ),
			array( 'id' => 'gpt-5.6-terra', 'label' => 'GPT-5.6 Terra', 'family' => 'GPT-5.6', 'category' => 'reasoning' ),

			// گفتگو عمومی
			array( 'id' => 'gpt-4o', 'label' => 'GPT-4o', 'family' => 'OpenAI', 'category' => 'chat' ),
			array( 'id' => 'gpt-4-turbo', 'label' => 'GPT-4 Turbo', 'family' => 'OpenAI', 'category' => 'chat' ),
			array( 'id' => 'gpt-3.5-turbo', 'label' => 'GPT-3.5 Turbo', 'family' => 'OpenAI', 'category' => 'chat' ),
			array( 'id' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro', 'family' => 'Google Gemini', 'category' => 'chat' ),
			array( 'id' => 'gemini-2.0-pro', 'label' => 'Gemini 2.0 Pro', 'family' => 'Google Gemini', 'category' => 'chat' ),
			array( 'id' => 'gemini-1.5-pro', 'label' => 'Gemini 1.5 Pro', 'family' => 'Google Gemini', 'category' => 'chat' ),
			array( 'id' => 'grok-3', 'label' => 'Grok 3', 'family' => 'xAI', 'category' => 'chat' ),
			array( 'id' => 'grok-2', 'label' => 'Grok 2', 'family' => 'xAI', 'category' => 'chat' ),
			array( 'id' => 'mistral-large-latest', 'label' => 'Mistral Large', 'family' => 'Mistral', 'category' => 'chat' ),
			array( 'id' => 'command-r-plus', 'label' => 'Command R+', 'family' => 'Cohere', 'category' => 'chat' ),
			array( 'id' => 'gpt-5.6-luna', 'label' => 'GPT-5.6 Luna', 'family' => 'GPT-5.6', 'category' => 'chat' ),

			// سریع و مینی
			array( 'id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'family' => 'OpenAI', 'category' => 'mini' ),
			array( 'id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 Mini', 'family' => 'OpenAI', 'category' => 'mini' ),
			array( 'id' => 'gpt-4.1-nano', 'label' => 'GPT-4.1 Nano', 'family' => 'OpenAI', 'category' => 'mini' ),
			array( 'id' => 'o3-mini', 'label' => 'o3 Mini', 'family' => 'OpenAI', 'category' => 'mini' ),
			array( 'id' => 'o4-mini', 'label' => 'o4 Mini', 'family' => 'OpenAI', 'category' => 'mini' ),
			array( 'id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash', 'family' => 'Google Gemini', 'category' => 'mini' ),
			array( 'id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash', 'family' => 'Google Gemini', 'category' => 'mini' ),
			array( 'id' => 'gemini-1.5-flash', 'label' => 'Gemini 1.5 Flash', 'family' => 'Google Gemini', 'category' => 'mini' ),
			array( 'id' => 'claude-3-5-haiku-latest', 'label' => 'Claude 3.5 Haiku', 'family' => 'Anthropic Claude', 'category' => 'mini' ),
			array( 'id' => 'mistral-small-latest', 'label' => 'Mistral Small', 'family' => 'Mistral', 'category' => 'mini' ),

			// ساخت تصویر — همه مدل‌های رایج GapGPT (همان POST /v1/images/generations)
			array( 'id' => 'gapgpt/z-image', 'label' => 'Z-Image', 'family' => 'GapGPT', 'category' => 'image' ),
			array( 'id' => 'imagen-4.0-generate-001', 'label' => 'Imagen 4', 'family' => 'Google', 'category' => 'image' ),
			array( 'id' => 'imagen-3.0-generate-002', 'label' => 'Imagen 3', 'family' => 'Google', 'category' => 'image' ),
			array( 'id' => 'dall-e-3', 'label' => 'DALL·E 3', 'family' => 'OpenAI', 'category' => 'image' ),
			array( 'id' => 'dall-e-2', 'label' => 'DALL·E 2', 'family' => 'OpenAI', 'category' => 'image' ),
			array( 'id' => 'gpt-image-1', 'label' => 'GPT Image 1', 'family' => 'OpenAI', 'category' => 'image' ),
			array( 'id' => 'flux-pro', 'label' => 'Flux Pro', 'family' => 'Black Forest', 'category' => 'image' ),
			array( 'id' => 'flux-schnell', 'label' => 'Flux Schnell', 'family' => 'Black Forest', 'category' => 'image' ),
			array( 'id' => 'flux-1.1-pro', 'label' => 'Flux 1.1 Pro', 'family' => 'Black Forest', 'category' => 'image' ),
			array( 'id' => 'stable-diffusion-3', 'label' => 'Stable Diffusion 3', 'family' => 'Stability', 'category' => 'image' ),
			array( 'id' => 'stable-diffusion-xl', 'label' => 'Stable Diffusion XL', 'family' => 'Stability', 'category' => 'image' ),
			array( 'id' => 'midjourney', 'label' => 'Midjourney', 'family' => 'Midjourney', 'category' => 'image' ),
			array( 'id' => 'ideogram-v2', 'label' => 'Ideogram V2', 'family' => 'Ideogram', 'category' => 'image' ),
			array( 'id' => 'seedream', 'label' => 'Seedream', 'family' => 'ByteDance', 'category' => 'image' ),
			array( 'id' => 'nano-banana', 'label' => 'Nano Banana', 'family' => 'Image', 'category' => 'image' ),
			array( 'id' => 'recraft-v3', 'label' => 'Recraft V3', 'family' => 'Recraft', 'category' => 'image' ),
			array( 'id' => 'qwen-image', 'label' => 'Qwen Image', 'family' => 'Qwen', 'category' => 'image' ),

			// ویدیو
			array( 'id' => 'sora', 'label' => 'Sora', 'family' => 'OpenAI', 'category' => 'video' ),
			array( 'id' => 'sora-2', 'label' => 'Sora 2', 'family' => 'OpenAI', 'category' => 'video' ),
			array( 'id' => 'runway-gen3', 'label' => 'Runway Gen-3', 'family' => 'Runway', 'category' => 'video' ),
			array( 'id' => 'kling-video', 'label' => 'Kling Video', 'family' => 'Kling', 'category' => 'video' ),
			array( 'id' => 'luma-ray2', 'label' => 'Luma Ray2', 'family' => 'Luma', 'category' => 'video' ),
			array( 'id' => 'veo-3', 'label' => 'Veo 3', 'family' => 'Google', 'category' => 'video' ),

			// صوت / TTS
			array( 'id' => 'tts-1', 'label' => 'TTS-1', 'family' => 'OpenAI', 'category' => 'audio' ),
			array( 'id' => 'tts-1-hd', 'label' => 'TTS-1 HD', 'family' => 'OpenAI', 'category' => 'audio' ),
			array( 'id' => 'gpt-4o-mini-tts', 'label' => 'GPT-4o Mini TTS', 'family' => 'OpenAI', 'category' => 'audio' ),
			array( 'id' => 'whisper-1', 'label' => 'Whisper (رونویسی)', 'family' => 'OpenAI', 'category' => 'audio' ),
		);

		foreach ( $items as &$item ) {
			if ( empty( $item['category'] ) ) {
				$item['category'] = self::guess_category( $item['id'] );
			}
		}
		unset( $item );

		return $items;
	}

	/**
	 * کاتالوگ + مدل‌های زنده‌ای که از /v1/models بیایند.
	 *
	 * @return array<int, array{id:string,label:string,family:string,category:string}>
	 */
	public static function gapgpt_catalog_merged() {
		$static = self::gapgpt_catalog();
		$by_id  = array();
		foreach ( $static as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$remote = self::fetch_remote_model_ids();
		foreach ( $remote as $id ) {
			$id = sanitize_text_field( $id );
			if ( '' === $id || isset( $by_id[ $id ] ) ) {
				continue;
			}
			$by_id[ $id ] = array(
				'id'       => $id,
				'label'    => self::pretty_model_label( $id ),
				'family'   => self::guess_family( $id ),
				'category' => self::guess_category( $id ),
			);
		}

		return array_values( $by_id );
	}

	/**
	 * برچسب خوانا برای شناسه مدل.
	 */
	public static function pretty_model_label( $id ) {
		$id = (string) $id;
		$map = array(
			'gapgpt/z-image'            => 'Z-Image',
			'imagen-4.0-generate-001'   => 'Imagen 4',
			'imagen-3.0-generate-002'   => 'Imagen 3',
			'dall-e-3'                  => 'DALL·E 3',
			'dall-e-2'                  => 'DALL·E 2',
			'gpt-image-1'               => 'GPT Image 1',
			'flux-pro'                  => 'Flux Pro',
			'flux-schnell'              => 'Flux Schnell',
			'flux-1.1-pro'              => 'Flux 1.1 Pro',
		);
		if ( isset( $map[ $id ] ) ) {
			return $map[ $id ];
		}
		// imagen-4.0-generate-001 → Imagen 4.0
		if ( preg_match( '/^imagen-([\d.]+)/i', $id, $m ) ) {
			return 'Imagen ' . $m[1];
		}
		$short = preg_replace( '#^.*/#', '', $id );
		$short = str_replace( array( '-', '_' ), ' ', (string) $short );
		return $short ? ucwords( $short ) : $id;
	}

	/**
	 * گروه‌بندی کاتالوگ بر اساس دسته کاربردی.
	 *
	 * @return array<string, array{label:string,items:array}>
	 */
	public static function gapgpt_catalog_by_category() {
		$out = array();
		foreach ( self::categories() as $id => $label ) {
			$out[ $id ] = array(
				'label' => $label,
				'items' => array(),
			);
		}
		foreach ( self::gapgpt_catalog_merged() as $item ) {
			$cat = isset( $item['category'] ) ? $item['category'] : self::guess_category( $item['id'] );
			if ( ! isset( $out[ $cat ] ) ) {
				$cat = 'other';
			}
			$out[ $cat ]['items'][] = $item;
		}
		// حذف دسته‌های خالی
		foreach ( $out as $id => $group ) {
			if ( empty( $group['items'] ) ) {
				unset( $out[ $id ] );
			}
		}
		return $out;
	}

	private static function guess_family( $id ) {
		$id = strtolower( (string) $id );
		if ( 0 === strpos( $id, 'gapgpt/' ) || false !== strpos( $id, 'z-image' ) ) {
			return 'GapGPT';
		}
		if ( false !== strpos( $id, 'tts' ) || false !== strpos( $id, 'whisper' ) ) {
			return 'OpenAI Audio';
		}
		if ( false !== strpos( $id, 'veo' ) ) {
			return 'Google';
		}
		if ( 0 === strpos( $id, 'gpt-5.6' ) ) {
			return 'GPT-5.6';
		}
		if ( false !== strpos( $id, 'dall-e' ) || false !== strpos( $id, 'dalle' ) || false !== strpos( $id, 'gpt-image' ) ) {
			return 'OpenAI';
		}
		if ( 0 === strpos( $id, 'gpt-' ) || 0 === strpos( $id, 'o1' ) || 0 === strpos( $id, 'o3' ) || 0 === strpos( $id, 'o4' ) ) {
			return 'OpenAI';
		}
		if ( false !== strpos( $id, 'gemini' ) || false !== strpos( $id, 'imagen' ) ) {
			return 'Google Gemini';
		}
		if ( false !== strpos( $id, 'claude' ) ) {
			return 'Anthropic Claude';
		}
		if ( false !== strpos( $id, 'deepseek' ) ) {
			return 'DeepSeek';
		}
		if ( false !== strpos( $id, 'grok' ) ) {
			return 'xAI';
		}
		if ( false !== strpos( $id, 'mistral' ) || false !== strpos( $id, 'mixtral' ) || false !== strpos( $id, 'codestral' ) ) {
			return 'Mistral';
		}
		if ( false !== strpos( $id, 'command' ) ) {
			return 'Cohere';
		}
		if ( false !== strpos( $id, 'flux' ) ) {
			return 'Black Forest';
		}
		if ( false !== strpos( $id, 'stable-diffusion' ) || false !== strpos( $id, 'sdxl' ) ) {
			return 'Stability';
		}
		return 'سایر (GapGPT)';
	}

	/**
	 * حدس دسته کاربردی از روی شناسه مدل.
	 */
	public static function guess_category( $id ) {
		$id = strtolower( (string) $id );

		if ( preg_match( '/whisper/i', $id ) ) {
			return 'audio';
		}
		if ( preg_match( '/tts-?1|mini-tts|tts$|speech|elevenlabs|audio-speech/i', $id ) ) {
			return 'audio';
		}
		if ( preg_match( '/dall-?e|gpt-image|flux|imagen|stable-diffusion|sdxl|midjourney|ideogram|seedream|qwen-image|nano-banana|grok-.*image|image-gen|z-image|gapgpt\/.*image|recraft|playground|kandinsky|sd3|text-to-image|t2i/i', $id ) ) {
			return 'image';
		}
		if ( preg_match( '/sora|runway|kling|luma|video|gen-?3|hailuo|pika|veo/i', $id ) ) {
			return 'video';
		}
		if ( preg_match( '/code|coder|codex|codestral|devstral|composer|deepseek-chat/i', $id ) ) {
			return 'coding';
		}
		// مینی قبل از استدلال تا o3-mini و flash در «سریع و مینی» بمانند
		if ( preg_match( '/mini|nano|flash|haiku|small|lite|schnell|instant/i', $id ) ) {
			return 'mini';
		}
		if ( preg_match( '/(^|-)(o1|o3|o4)(-|$)|reason|opus|thinking|(^|-)r1(-|$)/i', $id ) ) {
			return 'reasoning';
		}
		if ( preg_match( '/vision/i', $id ) ) {
			return 'vision';
		}
		if ( preg_match( '/gpt-4o$|gpt-4\.1$|sonnet|gemini|grok|mistral-large|command|gpt-5|claude/i', $id ) ) {
			return 'chat';
		}
		return 'other';
	}

	/**
	 * @return string[]
	 */
	public static function fetch_remote_model_ids() {
		$plain = self::get_gapgpt_key();
		if ( '' === $plain ) {
			return array();
		}

		$cache_key = 'agent_wp_gapgpt_remote_models';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = trailingslashit( self::get_gapgpt_base() ) . 'models';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $plain,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return array();
		}

		$ids = array();
		$list = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		foreach ( $list as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$ids[] = (string) $row['id'];
			} elseif ( is_string( $row ) ) {
				$ids[] = $row;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids ) {
			set_transient( $cache_key, $ids, 15 * MINUTE_IN_SECONDS );
		}
		return $ids;
	}

	/**
	 * ذخیره/به‌روزرسانی کلید GapGPT و همگام‌سازی مدل‌های کاتالوگ.
	 *
	 * @param string|null $api_key اگر داده شود، کلید جدید ذخیره می‌شود.
	 */
	public static function ensure_gapgpt_gateway( $api_key = null ) {
		$api_key = is_string( $api_key ) ? trim( $api_key ) : '';
		if ( '' !== $api_key ) {
			update_option( self::GAPGPT_KEY_OPTION, Agent_WP_Crypto::encrypt( $api_key ), false );
			delete_transient( 'agent_wp_gapgpt_remote_models' );
		}

		$plain = self::get_gapgpt_key();
		if ( '' === $plain ) {
			return;
		}

		$base         = self::get_gapgpt_base();
		$catalog      = self::gapgpt_catalog_merged();
		$first_id     = 0;
		$has_default  = (bool) self::get_default_id();

		foreach ( $catalog as $index => $item ) {
			$model_name = $item['id'];
			$cat        = isset( $item['category'] ) ? $item['category'] : self::guess_category( $model_name );
			$can_default = ! in_array( $cat, array( 'image', 'video', 'audio' ), true );
			$existing   = self::find_by_provider_model( 'gapgpt', $model_name );
			if ( $existing ) {
				self::update_key_and_base( (int) $existing['id'], $plain, $base );
				$id = (int) $existing['id'];
			} else {
				$id = self::create(
					array(
						'provider'   => 'gapgpt',
						'model_name' => $model_name,
						'api_key'    => $plain,
						'base_url'   => $base,
						'is_default' => ( ! $has_default && $can_default ),
					)
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				if ( ! $has_default && $can_default ) {
					$has_default = true;
				}
			}

			if ( ! $first_id ) {
				$first_id = (int) $id;
			}
		}

		if ( ! self::get_default_id() && $first_id ) {
			self::set_default( $first_id );
		}
	}

	public static function get_gapgpt_key() {
		$enc = (string) get_option( self::GAPGPT_KEY_OPTION, '' );
		if ( '' === $enc ) {
			return '';
		}
		return (string) Agent_WP_Crypto::decrypt( $enc );
	}

	public static function get_gapgpt_key_mask() {
		return Agent_WP_Crypto::mask( self::get_gapgpt_key() );
	}

	/**
	 * @return array|null
	 */
	public static function find_by_provider_model( $provider, $model_name ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE provider = %s AND model_name = %s ORDER BY id DESC LIMIT 1',
				sanitize_text_field( $provider ),
				sanitize_text_field( $model_name )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function update_key_and_base( $id, $api_key, $base_url ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'api_key_enc' => Agent_WP_Crypto::encrypt( (string) $api_key ),
				'base_url'    => esc_url_raw( $base_url ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function list_public() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, provider, model_name, base_url, is_default, api_key_enc, created_at FROM " . self::table() . " ORDER BY is_default DESC, id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$plain    = Agent_WP_Crypto::decrypt( $row['api_key_enc'] );
			$category = self::guess_category( $row['model_name'] );
			// اگر در کاتالوگ استاتیک دسته صریح دارد، همان را ترجیح بده
			foreach ( self::gapgpt_catalog() as $cat_item ) {
				if ( $cat_item['id'] === $row['model_name'] && ! empty( $cat_item['category'] ) ) {
					$category = $cat_item['category'];
					break;
				}
			}
			$out[] = array(
				'id'            => (int) $row['id'],
				'provider'      => $row['provider'],
				'name'          => $row['model_name'],
				'baseUrl'       => $row['base_url'],
				'isDefault'     => (bool) $row['is_default'],
				'apiKeyMask'    => Agent_WP_Crypto::mask( $plain ),
				'family'        => self::guess_family( $row['model_name'] ),
				'category'      => $category,
				'categoryLabel' => self::category_label( $category ),
				'createdAt'     => $row['created_at'],
			);
		}
		return $out;
	}

	public static function get_decrypted_key( $id ) {
		global $wpdb;
		$enc = $wpdb->get_var( $wpdb->prepare( "SELECT api_key_enc FROM " . self::table() . " WHERE id = %d", $id ) );
		return Agent_WP_Crypto::decrypt( (string) $enc );
	}

	/**
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function get_default_id() {
		global $wpdb;
		$id = $wpdb->get_var( 'SELECT id FROM ' . self::table() . ' WHERE is_default = 1 ORDER BY id DESC LIMIT 1' );
		if ( $id ) {
			return (int) $id;
		}
		$id = $wpdb->get_var( 'SELECT id FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1' );
		return $id ? (int) $id : 0;
	}

	public static function create( array $data ) {
		global $wpdb;

		$provider = sanitize_text_field( $data['provider'] ?? 'gapgpt' );
		if ( '' === $provider ) {
			$provider = 'gapgpt';
		}

		if ( 'custom' !== $provider ) {
			$provider = 'gapgpt';
			if ( empty( $data['base_url'] ) ) {
				$data['base_url'] = self::get_gapgpt_base();
			}
			if ( empty( $data['api_key'] ) ) {
				$data['api_key'] = self::get_gapgpt_key();
			}
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'provider'    => $provider,
				'model_name'  => sanitize_text_field( $data['model_name'] ?? '' ),
				'api_key_enc' => Agent_WP_Crypto::encrypt( (string) ( $data['api_key'] ?? '' ) ),
				'base_url'    => esc_url_raw( $data['base_url'] ?? self::get_gapgpt_base() ),
				'is_default'  => ! empty( $data['is_default'] ) ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'db_insert', __( 'ذخیره مدل ناموفق بود.', 'agent-wp' ) );
		}

		$id = (int) $wpdb->insert_id;
		if ( ! empty( $data['is_default'] ) ) {
			self::set_default( $id );
		}

		return $id;
	}

	public static function set_default( $id ) {
		global $wpdb;
		$wpdb->query( "UPDATE " . self::table() . " SET is_default = 0" );
		$wpdb->update( self::table(), array( 'is_default' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ), array( '%d', '%s' ), array( '%d' ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
