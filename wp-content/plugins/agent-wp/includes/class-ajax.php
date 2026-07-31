<?php
/**
 * AJAX امن برای مدل‌ها / Tool / لاگ.
 * چرا: API Key هرگز به فرانت برنمی‌گردد؛ فقط mask.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Ajax {

	public static function init() {
		$actions = array(
			'agent_wp_list_models',
			'agent_wp_save_model',
			'agent_wp_delete_model',
			'agent_wp_set_default_model',
			'agent_wp_set_gapgpt_endpoint',
			'agent_wp_save_gapgpt_api',
			'agent_wp_sync_gapgpt_models',
			'agent_wp_gapgpt_account',
			'agent_wp_gapgpt_test',
			'agent_wp_list_logs',
			'agent_wp_tool_stats',
			'agent_wp_run_tool',
			'agent_wp_rollback',
			'agent_wp_confirm_pending',
			'agent_wp_cancel_pending',
			'agent_wp_list_chats',
			'agent_wp_create_chat',
			'agent_wp_rename_chat',
			'agent_wp_chat_flag',
			'agent_wp_get_messages',
			'agent_wp_send_message',
			'agent_wp_cancel_request',
			'agent_wp_regenerate',
			'agent_wp_edit_message',
			'agent_wp_delete_message',
			'agent_wp_delete_chat',
			'agent_wp_assistant_get',
			'agent_wp_assistant_set',
			'agent_wp_assistant_history',
			'agent_wp_assistant_digest',
			'agent_wp_assistant_purge',
			'agent_wp_assistant_clear_chat',
			'agent_wp_assistant_export',
			'agent_wp_assistant_bulk',
			'agent_wp_assistant_snooze',
			'agent_wp_assistant_suggestions',
			'agent_wp_assistant_suggestion_status',
			'agent_wp_assistant_scan',
			'agent_wp_assistant_chat',
			'agent_wp_health',
			'agent_wp_export_chat',
			'agent_wp_set_debug',
			'agent_wp_purge_pending',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( 'agent_wp_', 'handle_', $action ) ) );
		}
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی کافی نیست.', 'agent-wp' ) ), 403 );
		}
		check_ajax_referer( 'agent_wp_admin', 'nonce' );
	}

	public static function handle_list_models() {
		self::guard();
		wp_send_json_success( array( 'models' => Agent_WP_Models::list_public() ) );
	}

	public static function handle_save_model() {
		self::guard();

		$provider   = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'gapgpt';
		$model_name = isset( $_POST['model_name'] ) ? sanitize_text_field( wp_unslash( $_POST['model_name'] ) ) : '';
		$api_key    = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		$base_url   = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';

		if ( '' === $model_name ) {
			wp_send_json_error( array( 'message' => __( 'نام مدل لازم است.', 'agent-wp' ) ), 400 );
		}

		// درگاه واحد GapGPT
		$provider = 'gapgpt';
		$base_url = Agent_WP_Models::get_gapgpt_base();
		if ( '' !== $api_key ) {
			Agent_WP_Models::ensure_gapgpt_gateway( $api_key );
		} else {
			$api_key = Agent_WP_Models::get_gapgpt_key();
		}
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'API Key گپ‌جی‌پی‌تی لازم است.', 'agent-wp' ) ), 400 );
		}

		$existing = Agent_WP_Models::find_by_provider_model( 'gapgpt', $model_name );
		if ( $existing ) {
			Agent_WP_Models::update_key_and_base( (int) $existing['id'], $api_key, $base_url );
			Agent_WP_Models::set_default( (int) $existing['id'] );
			$result = (int) $existing['id'];
		} else {
			$result = Agent_WP_Models::create(
				array(
					'provider'   => $provider,
					'model_name' => $model_name,
					'api_key'    => $api_key,
					'base_url'   => $base_url,
					'is_default' => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}
		}

		$log_id = Agent_WP_Action_Log::start(
			'system',
			'save_model',
			array(
				'provider'   => $provider,
				'model_name' => $model_name,
			),
			array()
		);
		Agent_WP_Action_Log::complete( $log_id, array( 'modelId' => (int) $result ) );

		wp_send_json_success(
			array(
				'message' => __( 'مدل GapGPT ذخیره شد.', 'agent-wp' ),
				'models'  => Agent_WP_Models::list_public(),
			)
		);
	}

	public static function handle_delete_model() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! Agent_WP_Models::delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'حذف مدل ناموفق بود.', 'agent-wp' ) ), 400 );
		}
		wp_send_json_success( array( 'models' => Agent_WP_Models::list_public() ) );
	}

	public static function handle_set_default_model() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! Agent_WP_Models::get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'مدل یافت نشد.', 'agent-wp' ) ), 400 );
		}
		Agent_WP_Models::set_default( $id );
		wp_send_json_success( array( 'models' => Agent_WP_Models::list_public() ) );
	}

	public static function handle_set_gapgpt_endpoint() {
		self::guard();
		$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'direct';
		$custom_base = isset( $_POST['custom_base'] ) ? (string) wp_unslash( $_POST['custom_base'] ) : '';
		$mode        = Agent_WP_Models::set_gapgpt_mode( $mode, $custom_base );
		Agent_WP_Models::ensure_gapgpt_gateway();
		wp_send_json_success(
			array(
				'mode'       => $mode,
				'baseUrl'    => Agent_WP_Models::get_gapgpt_base(),
				'customBase' => Agent_WP_Models::get_gapgpt_custom_base(),
				'models'     => Agent_WP_Models::list_public(),
				'message'    => 'custom' === $mode
					? __( 'آدرس API سفارشی فعال شد.', 'agent-wp' )
					: ( 'cdn' === $mode
						? __( 'حالت CDN فعال شد (api.gapapi.com).', 'agent-wp' )
						: __( 'حالت مستقیم فعال شد (api.gapgpt.app).', 'agent-wp' ) ),
			)
		);
	}

	public static function handle_save_gapgpt_api() {
		self::guard();
		$api_key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'کلید جدید را وارد کنید.', 'agent-wp' ) ), 400 );
		}

		$result = Agent_WP_Models::save_default_api(
			array(
				'api_key' => $api_key,
			)
		);

		$result['message'] = __( 'کلید پیش‌فرض ذخیره شد.', 'agent-wp' );
		wp_send_json_success( $result );
	}

	public static function handle_sync_gapgpt_models() {
		self::guard();
		delete_transient( 'agent_wp_gapgpt_remote_models' );
		Agent_WP_Models::ensure_gapgpt_gateway();
		wp_send_json_success(
			array(
				'models'       => Agent_WP_Models::list_public(),
				'toolsCatalog' => Agent_WP_Models::gapgpt_catalog_by_category(),
				'baseUrl'      => Agent_WP_Models::get_gapgpt_base(),
				'mode'         => Agent_WP_Models::get_gapgpt_mode(),
				'message'      => __( 'مدل‌های GapGPT همگام شدند.', 'agent-wp' ),
			)
		);
	}

	public static function handle_gapgpt_account() {
		self::guard();
		$force     = ! empty( $_POST['force'] );
		$cache_key = 'agent_wp_gapgpt_account';
		if ( $force ) {
			delete_transient( $cache_key );
			delete_option( 'agent_wp_gapgpt_last_quota' );
		} else {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				wp_send_json_success( array( 'account' => $cached ) );
			}
		}
		$account = Agent_WP_Gapgpt::fetch_account( $force );
		// همیشه حساب را برگردان تا UI موجودی/اخطار را نشان دهد
		if ( ! empty( $account['ok'] ) || ! empty( $account['display'] ) ) {
			set_transient( $cache_key, $account, 2 * MINUTE_IN_SECONDS );
			wp_send_json_success( array( 'account' => $account ) );
		}
		wp_send_json_error(
			array(
				'message' => isset( $account['message'] )
					? Agent_WP_Gapgpt::humanize_api_error( $account['message'] )
					: __( 'خواندن اعتبار ناموفق بود.', 'agent-wp' ),
				'account' => $account,
			),
			400
		);
	}

	public static function handle_list_logs() {
		self::guard();
		wp_send_json_success( array( 'logs' => Agent_WP_Action_Log::recent( 30 ) ) );
	}

	public static function handle_run_tool() {
		self::guard();
		$tool_id = isset( $_POST['tool_id'] ) ? sanitize_key( wp_unslash( $_POST['tool_id'] ) ) : '';
		$args    = array();
		if ( ! empty( $_POST['args'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['args'] ), true );
			if ( is_array( $decoded ) ) {
				$args = $decoded;
			}
		}

		$result = Agent_WP_Tool_Registry::instance()->execute( $tool_id, $args );
		if ( $result->ok ) {
			wp_send_json_success( $result->to_array() );
		}
		wp_send_json_error( $result->to_array(), 400 );
	}

	public static function handle_rollback() {
		self::guard();
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$result = Agent_WP_Tool_Registry::instance()->rollback( $log_id );
		if ( $result->ok ) {
			wp_send_json_success( $result->to_array() );
		}
		wp_send_json_error( $result->to_array(), 400 );
	}

	public static function handle_confirm_pending() {
		self::guard();
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		$result     = Agent_WP_Pending::confirm( $token );
		$out        = $result->to_array();
		$out['id']  = isset( $out['data']['id'] ) ? $out['data']['id'] : ( isset( $result->data['path'] ) ? 'write_file' : '' );

		if ( $result->ok ) {
			$msg = Agent_WP_Chats::resolve_confirm_in_message(
				$message_id,
				$token,
				'done',
				array(
					'message' => $result->message,
					'data'    => is_array( $result->data ) ? $result->data : array(),
				)
			);
			if ( ! is_wp_error( $msg ) ) {
				$out['chatMessage'] = $msg;
			}
			wp_send_json_success( $out );
		}
		// توکن نامعتبر — وضعیت پیام را هم منقضی کن
		Agent_WP_Chats::resolve_confirm_in_message( $message_id, $token, 'expired' );
		wp_send_json_error( $out, 400 );
	}

	public static function handle_cancel_pending() {
		self::guard();
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		$result     = Agent_WP_Pending::cancel( $token );
		$msg        = Agent_WP_Chats::resolve_confirm_in_message( $message_id, $token, 'cancelled' );
		$out        = $result->to_array();
		if ( ! is_wp_error( $msg ) ) {
			$out['chatMessage'] = $msg;
		}
		wp_send_json_success( $out );
	}

	public static function handle_list_chats() {
		self::guard();
		$chats = Agent_WP_Chats::ensure_default_for_user();
		wp_send_json_success( array( 'chats' => $chats ) );
	}

	public static function handle_create_chat() {
		self::guard();
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$id    = Agent_WP_Chats::create( array( 'title' => $title ) );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 500 );
		}
		wp_send_json_success(
			array(
				'chat'  => array(
					'id'          => $id,
					'title'       => $title ? $title : __( 'گفتگوی جدید', 'agent-wp' ),
					'updatedAt'   => current_time( 'mysql' ),
					'lastMessage' => '',
				),
				'chats' => Agent_WP_Chats::list_for_user(),
			)
		);
	}

	public static function handle_rename_chat() {
		self::guard();
		$chat_id = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$result  = Agent_WP_Chats::rename( $chat_id, $title );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'chats' => Agent_WP_Chats::list_for_user() ) );
	}

	public static function handle_get_messages() {
		self::guard();
		$chat_id  = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$messages = Agent_WP_Chats::list_messages( $chat_id );
		if ( is_wp_error( $messages ) ) {
			wp_send_json_error( array( 'message' => $messages->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'messages' => $messages ) );
	}

	public static function handle_send_message() {
		self::guard();
		$rate = Agent_WP_Assistant::check_rate( 'main_chat', 20, 60 );
		if ( is_wp_error( $rate ) ) {
			wp_send_json_error( array( 'message' => $rate->get_error_message() ), 429 );
		}

		// جلوگیری از ارسال دوبارهٔ همان درخواست (دوبار کلیک / شبکه)
		$request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
		if ( $request_id ) {
			$lock_key = 'agent_wp_req_' . md5( $request_id );
			if ( get_transient( $lock_key ) ) {
				wp_send_json_error( array( 'message' => __( 'این درخواست قبلاً در حال پردازش است.', 'agent-wp' ) ), 409 );
			}
			set_transient( $lock_key, 1, 90 );
		}

		$chat_id  = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$content  = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';
		$content  = Agent_WP_Assistant::clamp_message( $content );
		$model_raw = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		$mode      = isset( $_POST['model_mode'] ) ? sanitize_key( wp_unslash( $_POST['model_mode'] ) ) : '';
		$reply_to  = isset( $_POST['reply_to'] ) ? absint( $_POST['reply_to'] ) : 0;
		$reply_preview = isset( $_POST['reply_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['reply_preview'] ) ) : '';

		$attachments = self::ingest_chat_uploads();
		if ( is_wp_error( $attachments ) ) {
			if ( $request_id ) {
				delete_transient( 'agent_wp_req_' . md5( $request_id ) );
			}
			wp_send_json_error( array( 'message' => $attachments->get_error_message() ), 400 );
		}

		if ( '' === trim( $content ) && empty( $attachments ) ) {
			if ( $request_id ) {
				delete_transient( 'agent_wp_req_' . md5( $request_id ) );
			}
			wp_send_json_error( array( 'message' => __( 'پیام خالی است.', 'agent-wp' ) ), 400 );
		}
		if ( '' === trim( $content ) && ! empty( $attachments ) ) {
			$content = __( 'پیوست ارسال شد.', 'agent-wp' );
		}

		$auto = ( 'auto' === $mode || 'auto' === $model_raw || '' === $model_raw );
		$model_id = absint( $model_raw );
		if ( ! $auto && ! $model_id ) {
			$model_id = Agent_WP_Models::get_default_id();
		}

		$preferred_image = isset( $_POST['preferred_image_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_image_model'] ) )
			: '';
		$preferred_video = isset( $_POST['preferred_video_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_video_model'] ) )
			: '';
		$preferred_audio = isset( $_POST['preferred_audio_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_audio_model'] ) )
			: '';
		Agent_WP_Agent::set_request_context(
			array(
				'preferred_image_model' => $preferred_image,
				'preferred_video_model' => $preferred_video,
				'preferred_audio_model' => $preferred_audio,
			)
		);

		$user_meta = array();
		if ( $reply_to > 0 ) {
			$user_meta['replyTo'] = array(
				'id'      => $reply_to,
				'preview' => $reply_preview,
			);
		}
		if ( ! empty( $attachments ) ) {
			$user_meta['attachments'] = $attachments;
		}

		$user_msg = Agent_WP_Chats::add_message( $chat_id, 'user', $content, 0, $user_meta ? $user_meta : null );
		if ( is_wp_error( $user_msg ) ) {
			Agent_WP_Agent::set_request_context( array() );
			if ( $request_id ) {
				delete_transient( 'agent_wp_req_' . md5( $request_id ) );
			}
			wp_send_json_error( array( 'message' => $user_msg->get_error_message() ), 400 );
		}

		$agent_input = $content;
		if ( $reply_to > 0 && '' !== $reply_preview ) {
			$agent_input = "کاربر در پاسخ به این پیام دستیار:\n«{$reply_preview}»\n\n{$content}";
		}
		if ( ! empty( $attachments ) ) {
			$agent_input .= "\n\n" . self::format_attachments_for_agent( $attachments );
		}

		$agent = Agent_WP_Agent::respond( $agent_input, $model_id, $chat_id, $auto, 'agent', $request_id );
		Agent_WP_Agent::set_request_context( array() );
		$meta  = array();
		if ( ! empty( $agent['cancelled'] ) ) {
			$meta['cancelled'] = true;
		}
		if ( ! empty( $agent['tool'] ) ) {
			$meta['tool'] = $agent['tool'];
		}
		if ( ! empty( $agent['tools'] ) && is_array( $agent['tools'] ) ) {
			$clean_tools = array();
			foreach ( $agent['tools'] as $t ) {
				if ( ! empty( $t['data']['skippedDuplicate'] ) ) {
					continue;
				}
				$clean_tools[] = $t;
			}
			$meta['tools'] = $clean_tools ? $clean_tools : $agent['tools'];
			if ( empty( $meta['tool'] ) ) {
				$meta['tool'] = end( $meta['tools'] );
			}
		}
		if ( ! empty( $agent['usage'] ) && is_array( $agent['usage'] ) ) {
			$meta['usage'] = $agent['usage'];
		}
		if ( ! empty( $agent['route'] ) && is_array( $agent['route'] ) ) {
			$meta['route'] = $agent['route'];
		}
		$meta = self::meta_with_tool_attachments( $meta, isset( $meta['tools'] ) ? $meta['tools'] : array() );
		$bot = Agent_WP_Chats::add_message( $chat_id, 'assistant', $agent['reply'], 0, $meta );

		if ( $request_id ) {
			delete_transient( 'agent_wp_req_' . md5( $request_id ) );
		}

		wp_send_json_success(
			array(
				'userMessage'      => $user_msg,
				'assistantMessage' => is_wp_error( $bot ) ? null : $bot,
				'tool'             => isset( $meta['tool'] ) ? $meta['tool'] : null,
				'tools'            => isset( $agent['tools'] ) ? $agent['tools'] : null,
				'usage'            => isset( $agent['usage'] ) ? $agent['usage'] : null,
				'route'            => isset( $agent['route'] ) ? $agent['route'] : null,
				'cancelled'        => ! empty( $agent['cancelled'] ),
				'chats'            => Agent_WP_Chats::list_for_user(),
			)
		);
	}

	public static function handle_cancel_request() {
		self::guard();
		$request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
		if ( '' === $request_id ) {
			wp_send_json_error( array( 'message' => __( 'شناسه درخواست نامعتبر است.', 'agent-wp' ) ), 400 );
		}
		set_transient( 'agent_wp_cancel_' . md5( $request_id ), 1, 120 );
		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * آپلود پیوست‌های چت به کتابخانه رسانه.
	 *
	 * @return array|WP_Error
	 */
	private static function ingest_chat_uploads() {
		if ( empty( $_FILES['attachments'] ) || ! is_array( $_FILES['attachments'] ) ) {
			return array();
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'cap', __( 'اجازه آپلود فایل ندارید.', 'agent-wp' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$files = $_FILES['attachments'];
		$out   = array();
		$names = isset( $files['name'] ) ? (array) $files['name'] : array();
		$max_n = 5;
		$max_b = 8 * 1024 * 1024;
		$count = 0;

		foreach ( $names as $i => $name ) {
			if ( $count >= $max_n ) {
				break;
			}
			$error = isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== $error ) {
				return new WP_Error( 'upload', __( 'آپلود یکی از فایل‌ها ناموفق بود.', 'agent-wp' ) );
			}
			$size = isset( $files['size'][ $i ] ) ? (int) $files['size'][ $i ] : 0;
			if ( $size <= 0 || $size > $max_b ) {
				return new WP_Error( 'upload_size', __( 'حجم هر فایل حداکثر ۸ مگابایت است.', 'agent-wp' ) );
			}

			$file = array(
				'name'     => $name,
				'type'     => isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '',
				'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? $files['tmp_name'][ $i ] : '',
				'error'    => $error,
				'size'     => $size,
			);

			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $check['type'] ) && empty( $check['ext'] ) ) {
				return new WP_Error( 'upload_type', __( 'نوع فایل مجاز نیست.', 'agent-wp' ) );
			}

			$attachment_id = media_handle_sideload( $file, 0 );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$url  = wp_get_attachment_url( $attachment_id );
			$mime = get_post_mime_type( $attachment_id );
			$out[] = array(
				'id'      => (int) $attachment_id,
				'url'     => $url ? $url : '',
				'name'    => get_the_title( $attachment_id ) ? get_the_title( $attachment_id ) : sanitize_file_name( (string) $name ),
				'mime'    => $mime ? $mime : '',
				'isImage' => $mime ? ( 0 === strpos( $mime, 'image/' ) ) : false,
				'isVideo' => $mime ? ( 0 === strpos( $mime, 'video/' ) ) : false,
				'isAudio' => $mime ? ( 0 === strpos( $mime, 'audio/' ) ) : false,
			);
			$count++;
		}

		return $out;
	}

	/**
	 * @param array $attachments
	 */
	private static function format_attachments_for_agent( array $attachments ) {
		$lines = array( __( 'پیوست‌های کاربر (آپلودشده در رسانه وردپرس):', 'agent-wp' ) );
		foreach ( $attachments as $att ) {
			$name = isset( $att['name'] ) ? (string) $att['name'] : 'file';
			$url  = isset( $att['url'] ) ? (string) $att['url'] : '';
			$mime = isset( $att['mime'] ) ? (string) $att['mime'] : '';
			$kind = 'فایل';
			if ( ! empty( $att['isImage'] ) || ( $mime && 0 === strpos( $mime, 'image/' ) ) ) {
				$kind = 'تصویر';
			} elseif ( ! empty( $att['isVideo'] ) || ( $mime && 0 === strpos( $mime, 'video/' ) ) ) {
				$kind = 'ویدیو';
			} elseif ( ! empty( $att['isAudio'] ) || ( $mime && 0 === strpos( $mime, 'audio/' ) ) ) {
				$kind = 'صوت';
			}
			$lines[] = "- {$kind}: {$name}" . ( $mime ? " ({$mime})" : '' ) . ( $url ? " — {$url}" : '' );
		}
		$lines[] = __( 'اگر لازم است از این فایل‌ها در سایت استفاده کن. برای فایل صوت می‌توانی transcribe_audio را با attachmentId صدا بزنی.', 'agent-wp' );
		return implode( "\n", $lines );
	}

	/**
	 * استخراج پیوست‌های تولیدشده از نتایج Toolها (مثلاً generate_image).
	 *
	 * @param array $tools
	 * @return array<int,array>
	 */
	private static function collect_tool_attachments( array $tools ) {
		$out = array();
		foreach ( $tools as $t ) {
			if ( empty( $t['ok'] ) || empty( $t['data'] ) || ! is_array( $t['data'] ) ) {
				continue;
			}
			if ( ! empty( $t['data']['attachments'] ) && is_array( $t['data']['attachments'] ) ) {
				foreach ( $t['data']['attachments'] as $att ) {
					if ( ! is_array( $att ) || empty( $att['url'] ) ) {
						continue;
					}
					$out[] = array(
						'id'      => isset( $att['id'] ) ? (int) $att['id'] : 0,
						'url'     => (string) $att['url'],
						'name'    => isset( $att['name'] ) ? (string) $att['name'] : '',
						'mime'    => isset( $att['mime'] ) ? (string) $att['mime'] : 'image/png',
						'isImage' => ! empty( $att['isImage'] ) || ( isset( $att['mime'] ) && 0 === strpos( (string) $att['mime'], 'image/' ) ),
						'isVideo' => ! empty( $att['isVideo'] ) || ( isset( $att['mime'] ) && 0 === strpos( (string) $att['mime'], 'video/' ) ),
						'isAudio' => ! empty( $att['isAudio'] ) || ( isset( $att['mime'] ) && 0 === strpos( (string) $att['mime'], 'audio/' ) ),
					);
				}
			} elseif ( ! empty( $t['data']['url'] ) && ! empty( $t['data']['attachmentId'] ) ) {
				$mime = 'application/octet-stream';
				if ( ! empty( $t['data']['attachments'][0]['mime'] ) ) {
					$mime = (string) $t['data']['attachments'][0]['mime'];
				} elseif ( ! empty( $t['id'] ) && 'generate_speech' === $t['id'] ) {
					$mime = 'audio/mpeg';
				} elseif ( ! empty( $t['id'] ) && 'generate_video' === $t['id'] ) {
					$mime = 'video/mp4';
				} elseif ( ! empty( $t['id'] ) && 'generate_image' === $t['id'] ) {
					$mime = 'image/png';
				}
				$out[] = array(
					'id'      => (int) $t['data']['attachmentId'],
					'url'     => (string) $t['data']['url'],
					'name'    => isset( $t['data']['model'] ) ? (string) $t['data']['model'] : 'media',
					'mime'    => $mime,
					'isImage' => 0 === strpos( $mime, 'image/' ),
					'isVideo' => 0 === strpos( $mime, 'video/' ),
					'isAudio' => 0 === strpos( $mime, 'audio/' ),
				);
			}
		}
		return $out;
	}

	/**
	 * افزودن attachments تولیدشده به meta پیام دستیار.
	 *
	 * @param array $meta
	 * @param array $tools
	 * @return array
	 */
	private static function meta_with_tool_attachments( array $meta, array $tools ) {
		$atts = self::collect_tool_attachments( $tools );
		if ( $atts ) {
			$meta['attachments'] = $atts;
		}
		return $meta;
	}

	public static function handle_edit_message() {
		self::guard();
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		$content    = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';
		$result     = Agent_WP_Chats::update_message( $message_id, $content );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'message' => $result, 'chats' => Agent_WP_Chats::list_for_user() ) );
	}

	public static function handle_delete_message() {
		self::guard();
		$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
		$result     = Agent_WP_Chats::delete_message( $message_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	public static function handle_delete_chat() {
		self::guard();
		$chat_id = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$result  = Agent_WP_Chats::delete_chat( $chat_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'chats' => $result ) );
	}

	public static function handle_assistant_get() {
		self::guard();
		wp_send_json_success(
			array(
				'enabled'       => Agent_WP_Assistant::is_enabled(),
				'topic'         => Agent_WP_Assistant::get_topic(),
				'fabSide'       => Agent_WP_Assistant::get_fab_side(),
				'intervalHours' => Agent_WP_Assistant::get_interval_hours(),
				'quiet'         => Agent_WP_Assistant::quiet_enabled(),
				'openCount'     => Agent_WP_Assistant::count_open(),
				'lastScan'      => (int) get_option( Agent_WP_Assistant::OPTION_LAST_SCAN, 0 ),
				'lastScanLabel' => Agent_WP_Assistant::last_scan_label(),
				'notify'        => Agent_WP_Assistant::notify_enabled(),
				'sound'         => Agent_WP_Assistant::sound_enabled(),
				'debug'         => Agent_WP_Debug::enabled(),
				'openCount'     => Agent_WP_Assistant::count_open(),
			)
		);
	}

	public static function handle_assistant_set() {
		self::guard();
		$enabled = isset( $_POST['enabled'] ) ? (bool) absint( $_POST['enabled'] ) : false;
		$topic   = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$fab     = isset( $_POST['fab_side'] ) ? sanitize_key( wp_unslash( $_POST['fab_side'] ) ) : '';
		Agent_WP_Assistant::set_enabled( $enabled );
		if ( '' !== $topic ) {
			Agent_WP_Assistant::set_topic( $topic );
		}
		if ( '' !== $fab ) {
			Agent_WP_Assistant::set_fab_side( $fab );
		}
		if ( isset( $_POST['interval_hours'] ) ) {
			Agent_WP_Assistant::set_interval_hours( absint( $_POST['interval_hours'] ) );
		}
		if ( isset( $_POST['quiet'] ) ) {
			Agent_WP_Assistant::set_quiet_enabled( (bool) absint( $_POST['quiet'] ) );
		}
		if ( isset( $_POST['notify'] ) ) {
			Agent_WP_Assistant::set_notify_enabled( (bool) absint( $_POST['notify'] ) );
		}
		if ( isset( $_POST['sound'] ) ) {
			Agent_WP_Assistant::set_sound_enabled( (bool) absint( $_POST['sound'] ) );
		}
		if ( isset( $_POST['debug'] ) ) {
			Agent_WP_Debug::set_enabled( (bool) absint( $_POST['debug'] ) );
		}
		if ( isset( $_POST['surface'] ) ) {
			Agent_WP_Assistant::set_surface( sanitize_key( wp_unslash( $_POST['surface'] ) ) );
		}
		if ( isset( $_POST['note'] ) ) {
			Agent_WP_Assistant::set_note( wp_unslash( $_POST['note'] ) );
		}
		if ( isset( $_POST['max_open'] ) ) {
			Agent_WP_Assistant::set_max_open( absint( $_POST['max_open'] ) );
		}
		if ( isset( $_POST['digest_mail'] ) ) {
			Agent_WP_Assistant::set_digest_mail_enabled( (bool) absint( $_POST['digest_mail'] ) );
		}
		if ( isset( $_POST['modules'] ) ) {
			$raw = wp_unslash( $_POST['modules'] );
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			}
			if ( is_array( $raw ) ) {
				Agent_WP_Assistant::set_modules( $raw );
			}
		}
		wp_send_json_success(
			array(
				'enabled'       => Agent_WP_Assistant::is_enabled(),
				'topic'         => Agent_WP_Assistant::get_topic(),
				'fabSide'       => Agent_WP_Assistant::get_fab_side(),
				'intervalHours' => Agent_WP_Assistant::get_interval_hours(),
				'quiet'         => Agent_WP_Assistant::quiet_enabled(),
				'notify'        => Agent_WP_Assistant::notify_enabled(),
				'sound'         => Agent_WP_Assistant::sound_enabled(),
				'debug'         => Agent_WP_Debug::enabled(),
				'surface'       => Agent_WP_Assistant::get_surface(),
				'note'          => Agent_WP_Assistant::get_note(),
				'maxOpen'       => Agent_WP_Assistant::get_max_open(),
				'digestMail'    => Agent_WP_Assistant::digest_mail_enabled(),
				'modules'       => Agent_WP_Assistant::get_modules(),
				'openCount'     => Agent_WP_Assistant::count_open(),
				'message'       => $enabled
					? __( 'دستیار فعال شد و شروع به پایش سایت کرد.', 'agent-wp' )
					: __( 'دستیار خاموش شد.', 'agent-wp' ),
			)
		);
	}

	public static function handle_assistant_purge() {
		self::guard();
		$days  = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 14;
		$count = Agent_WP_Assistant::purge_closed( $days ? $days : 14 );
		wp_send_json_success(
			array(
				'deleted'   => $count,
				'openCount' => Agent_WP_Assistant::count_open(),
				'message'   => sprintf(
					/* translators: %d deleted rows */
					__( '%d پیشنهاد قدیمی پاک شد.', 'agent-wp' ),
					$count
				),
			)
		);
	}

	public static function handle_assistant_clear_chat() {
		self::guard();
		$result = Agent_WP_Assistant::clear_chat_history();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'message' => __( 'تاریخچه چت دستیار پاک شد.', 'agent-wp' ),
			)
		);
	}

	public static function handle_assistant_history() {
		self::guard();
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_success( array( 'messages' => array() ) );
		}
		$chat_id = Agent_WP_Assistant::ensure_chat_id();
		$msgs    = $chat_id ? Agent_WP_Chats::list_messages( $chat_id ) : array();
		if ( is_wp_error( $msgs ) ) {
			$msgs = array();
		}
		// آخرین ۵۰ پیام برای ویجت
		if ( count( $msgs ) > 50 ) {
			$msgs = array_slice( $msgs, -50 );
		}
		wp_send_json_success( array( 'messages' => $msgs, 'chatId' => $chat_id ) );
	}

	public static function handle_assistant_digest() {
		self::guard();
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'دستیار خاموش است.', 'agent-wp' ) ), 400 );
		}
		$brief = Agent_WP_Assistant::briefing();
		wp_send_json_success(
			array(
				'digest'      => $brief['text'],
				'briefing'    => $brief,
				'suggestions' => $brief['top'],
				'openCount'   => $brief['openCount'],
			)
		);
	}

	public static function handle_assistant_suggestions() {
		self::guard();
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_success( array( 'suggestions' => array(), 'pageSuggestions' => array(), 'enabled' => false ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'open';
		if ( ! in_array( $status, array( 'open', 'done', 'dismissed', 'snoozed' ), true ) ) {
			$status = 'open';
		}
		$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$cat  = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';

		$page_ctx = Agent_WP_Assistant::live_page_context(
			array(
				'url'        => isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : '',
				'screen'     => isset( $_POST['page_screen'] ) ? wp_unslash( $_POST['page_screen'] ) : '',
				'postId'     => isset( $_POST['page_post_id'] ) ? absint( $_POST['page_post_id'] ) : 0,
				'isAdmin'    => ! empty( $_POST['page_is_admin'] ),
				'title'      => isset( $_POST['page_title'] ) ? wp_unslash( $_POST['page_title'] ) : '',
				'commentId'  => isset( $_POST['page_comment_id'] ) ? absint( $_POST['page_comment_id'] ) : 0,
				'postType'   => isset( $_POST['page_post_type'] ) ? wp_unslash( $_POST['page_post_type'] ) : '',
				'optionPage' => isset( $_POST['page_option'] ) ? wp_unslash( $_POST['page_option'] ) : '',
				'isNew'      => ! empty( $_POST['page_is_new'] ),
			)
		);
		$page_suggestions = Agent_WP_Assistant::page_suggestions( $page_ctx );

		// خلاصه‌محور: برای open بدون فیلتر فقط چند مورد نماینده + بریفینگ
		if ( 'open' === $status && '' === $q && '' === $cat ) {
			$brief = Agent_WP_Assistant::briefing();
			wp_send_json_success(
				array(
					'enabled'          => true,
					'status'           => $status,
					'mode'             => 'briefing',
					'briefing'         => $brief,
					'suggestions'      => $brief['top'],
					'pageSuggestions'  => $page_suggestions,
					'pageContext'      => $page_ctx,
					'openCount'        => $brief['openCount'],
					'categoryCounts'   => Agent_WP_Assistant::category_counts(),
				)
			);
		}

		$list = ( $q || $cat )
			? Agent_WP_Assistant::search_suggestions( $q, $cat, $status, 8 )
			: Agent_WP_Assistant::list_by_status( $status, 8 );
		wp_send_json_success(
			array(
				'enabled'         => true,
				'status'          => $status,
				'mode'            => 'list',
				'briefing'        => null,
				'suggestions'     => $list,
				'pageSuggestions' => $page_suggestions,
				'pageContext'     => $page_ctx,
				'openCount'       => Agent_WP_Assistant::count_open(),
				'categoryCounts'  => Agent_WP_Assistant::category_counts(),
			)
		);
	}

	public static function handle_assistant_suggestion_status() {
		self::guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id || ! Agent_WP_Assistant::set_status( $id, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'به‌روزرسانی پیشنهاد ناموفق بود.', 'agent-wp' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'suggestions' => Agent_WP_Assistant::list_open( 20 ),
				'openCount'   => Agent_WP_Assistant::count_open(),
			)
		);
	}

	public static function handle_assistant_scan() {
		self::guard();
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'اول دستیار را فعال کنید.', 'agent-wp' ) ), 400 );
		}
		Agent_WP_Assistant_Scan::run( true );
		wp_send_json_success(
			array(
				'suggestions'   => Agent_WP_Assistant::list_open( 20 ),
				'openCount'     => Agent_WP_Assistant::count_open(),
				'lastScan'      => (int) get_option( Agent_WP_Assistant::OPTION_LAST_SCAN, 0 ),
				'lastScanLabel' => Agent_WP_Assistant::last_scan_label(),
				'message'       => __( 'اسکن تازه انجام شد.', 'agent-wp' ),
			)
		);
	}

	public static function handle_assistant_chat() {
		self::guard();
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'دستیار خاموش است.', 'agent-wp' ) ), 400 );
		}
		$rate = Agent_WP_Assistant::check_chat_rate( 10, 60 );
		if ( is_wp_error( $rate ) ) {
			wp_send_json_error( array( 'message' => $rate->get_error_message() ), 429 );
		}
		$content = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';
		$content = trim( Agent_WP_Assistant::clamp_message( $content ) );
		if ( '' === $content ) {
			wp_send_json_error( array( 'message' => __( 'پیام خالی است.', 'agent-wp' ) ), 400 );
		}

		$page_ctx = Agent_WP_Assistant::live_page_context(
			array(
				'url'        => isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : '',
				'screen'     => isset( $_POST['page_screen'] ) ? wp_unslash( $_POST['page_screen'] ) : '',
				'postId'     => isset( $_POST['page_post_id'] ) ? absint( $_POST['page_post_id'] ) : 0,
				'isAdmin'    => ! empty( $_POST['page_is_admin'] ),
				'title'      => isset( $_POST['page_title'] ) ? wp_unslash( $_POST['page_title'] ) : '',
				'commentId'  => isset( $_POST['page_comment_id'] ) ? absint( $_POST['page_comment_id'] ) : 0,
				'postType'   => isset( $_POST['page_post_type'] ) ? wp_unslash( $_POST['page_post_type'] ) : '',
				'optionPage' => isset( $_POST['page_option'] ) ? wp_unslash( $_POST['page_option'] ) : '',
				'isNew'      => ! empty( $_POST['page_is_new'] ),
			)
		);
		$req_ctx = array( 'page' => $page_ctx );
		if ( ! empty( $_POST['preferred_image_model'] ) ) {
			$req_ctx['preferred_image_model'] = sanitize_text_field( wp_unslash( $_POST['preferred_image_model'] ) );
		}
		Agent_WP_Agent::set_request_context( $req_ctx );

		$chat_id = Agent_WP_Assistant::ensure_chat_id();
		$user_msg = Agent_WP_Chats::add_message( $chat_id, 'user', $content );
		if ( is_wp_error( $user_msg ) ) {
			wp_send_json_error( array( 'message' => $user_msg->get_error_message() ), 400 );
		}

		$agent = Agent_WP_Agent::respond( $content, 0, $chat_id, true, 'assistant' );
		$meta  = array();
		if ( ! empty( $agent['tool'] ) ) {
			$meta['tool'] = $agent['tool'];
		}
		if ( ! empty( $agent['tools'] ) ) {
			$meta['tools'] = $agent['tools'];
		}
		if ( ! empty( $agent['usage'] ) ) {
			$meta['usage'] = $agent['usage'];
		}
		if ( ! empty( $agent['route'] ) ) {
			$meta['route'] = $agent['route'];
		}
		$meta = self::meta_with_tool_attachments( $meta, isset( $meta['tools'] ) ? $meta['tools'] : array() );
		$bot = Agent_WP_Chats::add_message( $chat_id, 'assistant', $agent['reply'], 0, $meta );

		$tools = isset( $agent['tools'] ) && is_array( $agent['tools'] ) ? $agent['tools'] : array();
		$closed = Agent_WP_Assistant::maybe_complete_from_tools( $tools, $content );

		wp_send_json_success(
			array(
				'userMessage'      => $user_msg,
				'assistantMessage' => is_wp_error( $bot ) ? null : $bot,
				'tools'            => $tools ? $tools : null,
				'route'            => isset( $agent['route'] ) ? $agent['route'] : null,
				'suggestions'      => Agent_WP_Assistant::list_open( 12 ),
				'openCount'        => Agent_WP_Assistant::count_open(),
				'closedSuggestions'=> $closed,
			)
		);
	}

	public static function handle_assistant_export() {
		self::guard();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'open';
		wp_send_json_success( Agent_WP_Assistant::export_suggestions( $status ) );
	}

	public static function handle_assistant_bulk() {
		self::guard();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$n      = Agent_WP_Assistant::bulk_set_status( $status, true );
		wp_send_json_success(
			array(
				'updated'     => $n,
				'suggestions' => Agent_WP_Assistant::list_open( 20 ),
				'openCount'   => Agent_WP_Assistant::count_open(),
				'message'     => sprintf(
					/* translators: %d updated */
					__( '%d پیشنهاد به‌روز شد.', 'agent-wp' ),
					$n
				),
			)
		);
	}

	public static function handle_health() {
		self::guard();
		Agent_WP_Pending::purge_expired();
		wp_send_json_success( Agent_WP_Health::report() );
	}

	public static function handle_export_chat() {
		self::guard();
		$chat_id = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$msgs    = Agent_WP_Chats::list_messages( $chat_id );
		if ( is_wp_error( $msgs ) ) {
			wp_send_json_error( array( 'message' => $msgs->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'chatId'     => $chat_id,
				'exportedAt' => gmdate( 'c' ),
				'messages'   => $msgs,
			)
		);
	}

	public static function handle_set_debug() {
		self::guard();
		$on = isset( $_POST['debug'] ) ? (bool) absint( $_POST['debug'] ) : false;
		Agent_WP_Debug::set_enabled( $on );
		wp_send_json_success(
			array(
				'debug'   => Agent_WP_Debug::enabled(),
				'message' => $on ? __( 'دیباگ روشن شد (فقط لاگ سرور).', 'agent-wp' ) : __( 'دیباگ خاموش شد.', 'agent-wp' ),
			)
		);
	}

	public static function handle_purge_pending() {
		self::guard();
		$n = Agent_WP_Pending::purge_expired();
		wp_send_json_success(
			array(
				'purged'  => $n,
				'message' => sprintf(
					/* translators: %d purged */
					__( '%d درخواست تأیید منقضی پاک شد.', 'agent-wp' ),
					$n
				),
			)
		);
	}

	public static function handle_gapgpt_test() {
		self::guard();
		$result = Agent_WP_Gapgpt::test_connection();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result, 400 );
		}
		wp_send_json_success( $result );
	}

	public static function handle_tool_stats() {
		self::guard();
		wp_send_json_success( array( 'stats' => Agent_WP_Action_Log::tool_stats( 12 ) ) );
	}

	public static function handle_chat_flag() {
		self::guard();
		$chat_id = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$key     = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$value   = isset( $_POST['value'] ) ? (bool) absint( $_POST['value'] ) : false;
		if ( ! in_array( $key, array( 'pinned', 'archived' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'پرچم نامعتبر است.', 'agent-wp' ) ), 400 );
		}
		$result = Agent_WP_Chats::set_flag( $chat_id, $key, $value );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'chats' => Agent_WP_Chats::list_for_user() ) );
	}

	public static function handle_regenerate() {
		self::guard();
		$chat_id = isset( $_POST['chat_id'] ) ? absint( $_POST['chat_id'] ) : 0;
		$msgs    = Agent_WP_Chats::list_messages( $chat_id );
		if ( is_wp_error( $msgs ) || ! $msgs ) {
			wp_send_json_error( array( 'message' => __( 'پیامی برای بازتولید نیست.', 'agent-wp' ) ), 400 );
		}
		$last_user = '';
		for ( $i = count( $msgs ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === $msgs[ $i ]['role'] ) {
				$last_user = $msgs[ $i ]['content'];
				break;
			}
		}
		if ( '' === trim( $last_user ) ) {
			wp_send_json_error( array( 'message' => __( 'پیام کاربر یافت نشد.', 'agent-wp' ) ), 400 );
		}
		// حذف آخرین پاسخ دستیار اگر آخرین پیام باشد
		$last = $msgs[ count( $msgs ) - 1 ];
		if ( 'assistant' === $last['role'] ) {
			Agent_WP_Chats::delete_message( (int) $last['id'] );
		}

		$model_raw = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : 'auto';
		$auto      = ( 'auto' === $model_raw || '' === $model_raw );
		$model_id  = absint( $model_raw );
		$preferred_image = isset( $_POST['preferred_image_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_image_model'] ) )
			: '';
		$preferred_video = isset( $_POST['preferred_video_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_video_model'] ) )
			: '';
		$preferred_audio = isset( $_POST['preferred_audio_model'] )
			? sanitize_text_field( wp_unslash( $_POST['preferred_audio_model'] ) )
			: '';
		Agent_WP_Agent::set_request_context(
			array(
				'preferred_image_model' => $preferred_image,
				'preferred_video_model' => $preferred_video,
				'preferred_audio_model' => $preferred_audio,
			)
		);
		$agent = Agent_WP_Agent::respond( $last_user, $model_id, $chat_id, $auto );
		Agent_WP_Agent::set_request_context( array() );
		$meta      = array();
		if ( ! empty( $agent['tools'] ) ) {
			$meta['tools'] = $agent['tools'];
		}
		if ( ! empty( $agent['route'] ) ) {
			$meta['route'] = $agent['route'];
		}
		if ( ! empty( $agent['usage'] ) ) {
			$meta['usage'] = $agent['usage'];
		}
		$meta = self::meta_with_tool_attachments( $meta, isset( $meta['tools'] ) ? $meta['tools'] : array() );
		$bot = Agent_WP_Chats::add_message( $chat_id, 'assistant', $agent['reply'], 0, $meta );
		wp_send_json_success(
			array(
				'assistantMessage' => is_wp_error( $bot ) ? null : $bot,
				'tools'            => isset( $agent['tools'] ) ? $agent['tools'] : null,
				'route'            => isset( $agent['route'] ) ? $agent['route'] : null,
				'messages'         => Agent_WP_Chats::list_messages( $chat_id ),
			)
		);
	}

	public static function handle_assistant_snooze() {
		self::guard();
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 1;
		if ( ! $id || ! Agent_WP_Assistant::snooze( $id, $days ) ) {
			wp_send_json_error( array( 'message' => __( 'اسنوز ناموفق بود.', 'agent-wp' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'suggestions' => Agent_WP_Assistant::list_open( 20 ),
				'openCount'   => Agent_WP_Assistant::count_open(),
				'message'     => __( 'پیشنهاد به تعویق افتاد.', 'agent-wp' ),
			)
		);
	}
}
