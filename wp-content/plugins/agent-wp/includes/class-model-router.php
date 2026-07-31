<?php
/**
 * انتخاب هوشمند مدل GapGPT بر اساس نوع کار کاربر.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Model_Router {

	/**
	 * @return array{modelId:int,modelName:string,task:string,taskLabel:string,reason:string,auto:bool}
	 */
	public static function resolve( $user_text, $force_model_id = 0 ) {
		$force_model_id = (int) $force_model_id;
		if ( $force_model_id > 0 && Agent_WP_Models::get( $force_model_id ) ) {
			$row = Agent_WP_Models::get( $force_model_id );
			$cat = Agent_WP_Models::guess_category( isset( $row['model_name'] ) ? $row['model_name'] : '' );
			// مدل تصویر/ویدیو برای chat/completions مناسب نیست → برو سراغ انتخاب خودکار
			if ( ! in_array( $cat, array( 'image', 'video', 'audio' ), true ) ) {
				return array(
					'modelId'   => $force_model_id,
					'modelName' => (string) $row['model_name'],
					'task'      => 'manual',
					'taskLabel' => __( 'انتخاب دستی', 'agent-wp' ),
					'reason'    => __( 'مدل توسط کاربر انتخاب شد.', 'agent-wp' ),
					'auto'      => false,
				);
			}
		}

		$task  = self::detect_task( (string) $user_text );
		$prefs = self::preferences( $task['id'] );
		$pick  = self::pick_from_available( $prefs );

		if ( ! $pick ) {
			$fallback = Agent_WP_Models::get_default_id();
			$row      = $fallback ? Agent_WP_Models::get( $fallback ) : null;
			return array(
				'modelId'   => $fallback,
				'modelName' => $row ? (string) $row['model_name'] : '',
				'task'      => $task['id'],
				'taskLabel' => $task['label'],
				'reason'    => __( 'مدل پیش‌فرض (کاتالوگ محدود بود).', 'agent-wp' ),
				'auto'      => true,
			);
		}

		return array(
			'modelId'   => (int) $pick['id'],
			'modelName' => (string) $pick['model_name'],
			'task'      => $task['id'],
			'taskLabel' => $task['label'],
			'reason'    => sprintf(
				/* translators: 1: task, 2: model */
				__( 'برای «%1$s» مدل %2$s انتخاب شد.', 'agent-wp' ),
				$task['label'],
				$pick['model_name']
			),
			'auto'      => true,
		);
	}

	/**
	 * @return array{id:string,label:string}
	 */
	public static function detect_task( $text ) {
		$t = mb_strtolower( trim( (string) $text ) );

		$rules = array(
			array(
				'id'    => 'code',
				'label' => __( 'کدنویسی / فایل', 'agent-wp' ),
				'keys'  => array(
					'کد', 'php', 'js', 'javascript', 'css', 'html', 'فایل', 'theme/', 'workspace/',
					'بنویس', 'فانکشن', 'function', 'کلاس', 'hook', 'افزونه بنویس', 'snippet',
					'read_file', 'write_file', 'لیست فایل',
				),
			),
			array(
				'id'    => 'design',
				'label' => __( 'دیزاین / ظاهر', 'agent-wp' ),
				'keys'  => array(
					'دیزاین', 'طراحی', 'ظاهر', 'رنگ', 'فونت', 'استایل', 'ui', 'ux', 'انیمیشن',
					'custom_css', 'سی‌اس‌اس', 'زیبا', 'تلگرام', 'هدر', 'فوتر', 'موبایل',
					'لندینگ', 'landing', 'صفحه فرود', 'هیرو', 'hero', 'بنر صفحه',
				),
			),
			array(
				'id'    => 'shop',
				'label' => __( 'فروشگاه / ووکامرس', 'agent-wp' ),
				'keys'  => array(
					'محصول', 'ووکامرس', 'woocommerce', 'سفارش', 'کوپن', 'قیمت', 'موجودی',
					'سبد', 'فروش', 'woo',
				),
			),
			array(
				'id'    => 'reason',
				'label' => __( 'تحلیل / استدلال', 'agent-wp' ),
				'keys'  => array(
					'تحلیل', 'چرا', 'مقایسه', 'بررسی', 'استراتژی', 'ریسک', 'پیشنهاد بده',
					'مزایا', 'معایب', 'برنامه‌ریزی', 'اولویت', 'معماری',
				),
			),
			array(
				'id'    => 'content',
				'label' => __( 'محتوا / نوشته', 'agent-wp' ),
				'keys'  => array(
					'برگه', 'نوشته', 'مقاله', 'عنوان', 'توضیح', 'متن', 'seo', 'سئو',
					'پیش‌نویس', 'منتشر', 'محتوا', 'پست', 'page', 'post',
				),
			),
			array(
				'id'    => 'ops',
				'label' => __( 'عملیات سریع سایت', 'agent-wp' ),
				'keys'  => array(
					'لیست', 'اطلاعات سایت', 'منو', 'دسته', 'برچسب', 'رسانه', 'افزونه', 'قالب',
					'تنظیمات', 'حذف', 'زباله‌دان', 'جستجو و جایگزینی',
				),
			),
		);

		$best      = null;
		$best_score = 0;
		foreach ( $rules as $rule ) {
			$score = 0;
			foreach ( $rule['keys'] as $key ) {
				$key = mb_strtolower( $key );
				if ( '' !== $key && false !== mb_strpos( $t, $key ) ) {
					$score += mb_strlen( $key ) > 4 ? 2 : 1;
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $rule;
			}
		}

		if ( $best && $best_score > 0 ) {
			return array(
				'id'    => $best['id'],
				'label' => $best['label'],
			);
		}

		return array(
			'id'    => 'chat',
			'label' => __( 'گفتگوی عمومی', 'agent-wp' ),
		);
	}

	/**
	 * اولویت نام مدل‌ها (substring match روی model_name ذخیره‌شده).
	 *
	 * @return string[]
	 */
	private static function preferences( $task ) {
		$map = array(
			'code'    => array(
				'claude-sonnet-4',
				'claude-3-5-sonnet',
				'gpt-4.1',
				'gpt-4o',
				'gemini-2.5-pro',
				'deepseek-chat',
				'gpt-4.1-mini',
			),
			'design'  => array(
				'gemini-2.5-pro',
				'gemini-2.5-flash',
				'gpt-4o',
				'claude-sonnet-4',
				'gpt-4.1',
				'gemini-2.0-flash',
			),
			'shop'    => array(
				'gpt-4o-mini',
				'gpt-4.1-mini',
				'gemini-2.5-flash',
				'gpt-4o',
				'claude-3-5-sonnet',
			),
			'reason'  => array(
				'o3-mini',
				'o4-mini',
				'claude-opus-4',
				'gpt-4.1',
				'claude-sonnet-4',
				'gpt-4o',
				'gemini-2.5-pro',
			),
			'content' => array(
				'gpt-4o',
				'claude-sonnet-4',
				'claude-3-5-sonnet',
				'gpt-4.1',
				'gemini-2.5-flash',
				'gpt-4o-mini',
			),
			'ops'     => array(
				'gpt-4o-mini',
				'gpt-4.1-mini',
				'gpt-4.1-nano',
				'gemini-2.0-flash',
				'gemini-2.5-flash',
				'gpt-4o',
			),
			'chat'    => array(
				'gpt-4o-mini',
				'gpt-4.1-mini',
				'gemini-2.5-flash',
				'gpt-4o',
				'claude-3-5-sonnet',
			),
		);

		return isset( $map[ $task ] ) ? $map[ $task ] : $map['chat'];
	}

	/**
	 * @param string[] $prefs
	 * @return array|null DB row
	 */
	private static function pick_from_available( array $prefs ) {
		$models = self::available_rows();
		if ( ! $models ) {
			return null;
		}

		foreach ( $prefs as $needle ) {
			$needle = strtolower( $needle );
			foreach ( $models as $row ) {
				$name = strtolower( (string) $row['model_name'] );
				if ( false !== strpos( $name, $needle ) ) {
					return $row;
				}
			}
		}

		// امتیاز خانواده‌ای سبک اگر هیچ substring نخورد
		foreach ( $models as $row ) {
			return $row;
		}
		return null;
	}

	/**
	 * @return array<int,array>
	 */
	private static function available_rows() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, model_name, is_default FROM ' . Agent_WP_Models::table() . ' WHERE provider = \'gapgpt\' ORDER BY is_default DESC, id ASC',
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
