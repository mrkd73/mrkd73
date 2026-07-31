<?php
/**
 * موتور ایجنت: LLM + حلقه Tool Calling (دسترسی گسترده به سایت با sandbox).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Agent {

	const MAX_TOOL_ROUNDS = 10;

	/** @var array<string,mixed> */
	private static $request_ctx = array();

	/**
	 * زمینهٔ درخواست فعلی (مثلاً مدل ترجیحی تصویر).
	 *
	 * @param array<string,mixed> $ctx
	 */
	public static function set_request_context( array $ctx ) {
		self::$request_ctx = $ctx;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get_request_context( $key, $default = null ) {
		$key = (string) $key;
		return array_key_exists( $key, self::$request_ctx ) ? self::$request_ctx[ $key ] : $default;
	}

	/**
	 * @param string $mode agent|assistant
	 * @param string $request_id برای توقف از UI
	 * @return array{reply:string,tool?:array,tools?:array,usage?:array,route?:array,cancelled?:bool}
	 */
	public static function respond( $user_text, $model_id = 0, $chat_id = 0, $auto = false, $mode = 'agent', $request_id = '' ) {
		$user_text = trim( (string) $user_text );
		$model_id  = (int) $model_id;
		$mode      = ( 'assistant' === $mode ) ? 'assistant' : 'agent';
		$request_id = sanitize_text_field( (string) $request_id );

		$route = Agent_WP_Model_Router::resolve( $user_text, $auto ? 0 : $model_id );
		$model_id = (int) $route['modelId'];

		if ( $model_id > 0 ) {
			$llm = self::try_llm( $user_text, $model_id, $chat_id, $mode, $request_id );
			if ( null !== $llm ) {
				$llm['route'] = $route;
				return $llm;
			}
		}

		return array(
			'reply' => self::fallback_help(),
			'route' => $route,
		);
	}

	public static function is_request_cancelled( $request_id ) {
		$request_id = sanitize_text_field( (string) $request_id );
		if ( '' === $request_id ) {
			return false;
		}
		return (bool) get_transient( 'agent_wp_cancel_' . md5( $request_id ) );
	}

	/**
	 * @return array|null
	 */
	private static function try_llm( $user_text, $model_id, $chat_id, $mode = 'agent', $request_id = '' ) {
		$system = self::system_prompt();
		if ( 'assistant' === $mode && class_exists( 'Agent_WP_Assistant' ) && Agent_WP_Assistant::is_enabled() ) {
			$system = Agent_WP_Assistant::context_for_prompt() . "\n\n---\n" . $system;
		} elseif ( class_exists( 'Agent_WP_Page_Context' ) ) {
			// چت اصلی هم اگر زمینه صفحه داشته باشد، همان صفحه را می‌شناسد
			$page_raw = self::get_request_context( 'page', array() );
			if ( is_array( $page_raw ) && $page_raw ) {
				$page = Agent_WP_Assistant::live_page_context( $page_raw );
				$system = Agent_WP_Page_Context::prompt_block( $page ) . "\n\n---\n" . $system;
			}
		}

		$pref_image = sanitize_text_field( (string) self::get_request_context( 'preferred_image_model', '' ) );
		if ( '' !== $pref_image ) {
			$system .= "\n\nمدل ترجیحی ساخت تصویر این گفتگو: «{$pref_image}». در generate_image همین را در پارامتر model بفرست مگر کاربر مدل دیگری گفته باشد.\n";
		}
		$pref_video = sanitize_text_field( (string) self::get_request_context( 'preferred_video_model', '' ) );
		if ( '' !== $pref_video ) {
			$system .= "\n\nمدل ترجیحی ساخت ویدیو این گفتگو: «{$pref_video}». در generate_video همین را در model بفرست.\n";
		}
		$pref_audio = sanitize_text_field( (string) self::get_request_context( 'preferred_audio_model', '' ) );
		if ( '' !== $pref_audio ) {
			$system .= "\n\nمدل ترجیحی گفتار این گفتگو: «{$pref_audio}». در generate_speech همین را در model بفرست.\n";
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);

		if ( $chat_id ) {
			$history = Agent_WP_Chats::list_messages( (int) $chat_id );
			if ( ! is_wp_error( $history ) && is_array( $history ) ) {
				$slice = array_slice( $history, -16 );
				foreach ( $slice as $msg ) {
					$role       = ( 'assistant' === $msg['role'] ) ? 'assistant' : 'user';
					$messages[] = array(
						'role'    => $role,
						'content' => (string) $msg['content'],
					);
				}
			}
		} else {
			$messages[] = array(
				'role'    => 'user',
				'content' => $user_text,
			);
		}

		$tools       = Agent_WP_Tool_Registry::instance()->openai_tools();
		$usage_total = array(
			'promptTokens'     => 0,
			'completionTokens' => 0,
			'totalTokens'      => 0,
		);
		$executed    = array();
		$last_tool   = null;
		$seen_fps    = array(); // جلوگیری از اجرای تکراری همان Tool با همان آرگومان

		for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
			if ( $request_id && self::is_request_cancelled( $request_id ) ) {
				return array(
					'reply'     => $executed
						? __( 'درخواست متوقف شد. بعضی اکشن‌ها قبل از توقف اجرا شده بودند — از تاریخچه اکشن‌ها بررسی/Rollback کنید.', 'agent-wp' )
						: __( 'درخواست متوقف شد.', 'agent-wp' ),
					'tools'     => $executed,
					'usage'     => $usage_total,
					'cancelled' => true,
					'tool'      => $last_tool,
				);
			}

			$result = Agent_WP_Llm::chat(
				$model_id,
				$messages,
				array(
					'tools'       => $tools,
					'tool_choice' => 'auto',
					'temperature' => 0.3,
				)
			);

			if ( is_wp_error( $result ) ) {
				$friendly = class_exists( 'Agent_WP_Gapgpt' )
					? Agent_WP_Gapgpt::humanize_api_error( $result->get_error_message() )
					: $result->get_error_message();

				// اگر قبلاً Tool اجرا شده: اول نتیجه درخواست را تأیید کن، بعد خطای اعتبار/اتصال
				if ( $executed ) {
					$intent_ctx = (string) $user_text;
					foreach ( $messages as $m ) {
						if ( ! empty( $m['role'] ) && 'user' === $m['role'] && ! empty( $m['content'] ) ) {
							$intent_ctx .= "\n" . $m['content'];
						}
					}
					$friendly = self::build_partial_success_reply( $intent_ctx, $executed, $friendly );
				}

				return array(
					'reply' => $friendly,
					'usage' => $usage_total,
					'tools' => $executed,
					'error' => array(
						'code'  => $result->get_error_code(),
						'quota' => (bool) ( class_exists( 'Agent_WP_Gapgpt' ) ? Agent_WP_Gapgpt::get_last_quota() : null ),
					),
				);
			}

			if ( ! empty( $result['usage'] ) && is_array( $result['usage'] ) ) {
				$usage_total['promptTokens']     += (int) ( $result['usage']['promptTokens'] ?? 0 );
				$usage_total['completionTokens'] += (int) ( $result['usage']['completionTokens'] ?? 0 );
				$usage_total['totalTokens']      += (int) ( $result['usage']['totalTokens'] ?? 0 );
			}

			$tool_calls = ! empty( $result['toolCalls'] ) ? $result['toolCalls'] : array();

			// fallback: مارکر متنی اگر function calling نبود
			if ( ! $tool_calls && ! empty( $result['content'] ) ) {
				$marker = self::parse_tool_marker( $result['content'] );
				if ( null !== $marker ) {
					$tool_calls = array(
						array(
							'id'   => 'marker_' . uniqid(),
							'name' => $marker['tool'],
							'args' => $marker['args'],
						),
					);
				}
			}

			// حذف فراخوانی‌های تکراری در همان پاسخ مدل
			$tool_calls = self::dedupe_tool_calls( $tool_calls );

			if ( ! $tool_calls ) {
				$reply = trim( (string) $result['content'] );
				if ( '' === $reply && $executed ) {
					$reply = self::summarize_tools( $executed );
				}
				$out = array(
					'reply' => $reply,
					'usage' => $usage_total,
				);
				if ( $last_tool ) {
					$out['tool'] = $last_tool;
				}
				if ( $executed ) {
					$out['tools'] = $executed;
				}
				return $out;
			}

			// پیام دستیار با tool_calls برای ادامهٔ حلقه
			if ( ! empty( $result['rawMessage'] ) && is_array( $result['rawMessage'] ) ) {
				$messages[] = $result['rawMessage'];
			} else {
				$messages[] = array(
					'role'    => 'assistant',
					'content' => (string) $result['content'],
				);
			}

			foreach ( $tool_calls as $call ) {
				$name = isset( $call['name'] ) ? sanitize_key( $call['name'] ) : '';
				$args = isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array();
				$fp   = self::tool_fingerprint( $name, $args );

				if ( $fp && isset( $seen_fps[ $fp ] ) ) {
					$exec = array(
						'ok'      => true,
						'message' => __( 'این اکشن قبلاً در همین درخواست اجرا شده؛ از تکرار صرف‌نظر شد.', 'agent-wp' ),
						'data'    => array(
							'skippedDuplicate' => true,
							'original'         => isset( $seen_fps[ $fp ]['data'] ) ? $seen_fps[ $fp ]['data'] : array(),
						),
						'id'      => $name,
						'logId'   => 0,
					);
				} else {
					$exec = self::run_tool( $name, $args );
					if ( $fp && ! empty( $exec['ok'] ) && empty( $exec['data']['needsConfirm'] ) ) {
						$seen_fps[ $fp ] = $exec;
					} elseif ( $fp && ! empty( $exec['data']['needsConfirm'] ) ) {
						// تأیید در انتظار هم تکرار نشود
						$seen_fps[ $fp ] = $exec;
					}
				}

				$executed[] = $exec;
				$last_tool  = $exec;

				// اگر تأیید لازم است، حلقه را قطع کن تا کاربر در UI تأیید کند.
				if ( ! empty( $exec['data']['needsConfirm'] ) ) {
					$warn = isset( $exec['data']['warning'] ) ? trim( (string) $exec['data']['warning'] ) : '';
					$reply = $warn
						? $warn . "\n\n" . __( 'از دکمه‌های تأیید، لغو یا پاسخ دیگر استفاده کنید.', 'agent-wp' )
						: __( 'این اکشن نیاز به تأیید دارد. از دکمه‌های زیر استفاده کنید.', 'agent-wp' );
					return array(
						'reply' => $reply,
						'tool'  => $exec,
						'tools' => $executed,
						'usage' => $usage_total,
					);
				}

				$payload = wp_json_encode(
					array(
						'ok'      => ! empty( $exec['ok'] ),
						'message' => isset( $exec['message'] ) ? $exec['message'] : '',
						'data'    => isset( $exec['data'] ) ? $exec['data'] : array(),
					),
					JSON_UNESCAPED_UNICODE
				);

				// اگر rawMessage داشتیم، نقش tool استاندارد بفرست
				if ( ! empty( $result['rawMessage']['tool_calls'] ) ) {
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => $call['id'],
						'content'      => $payload ? $payload : '{}',
					);
				} else {
					$messages[] = array(
						'role'    => 'user',
						'content' => "نتیجه ابزار {$name}:\n" . ( $payload ? $payload : '{}' ),
					);
				}
			}
		}

		return array(
			'reply' => self::summarize_tools( $executed ),
			'tool'  => $last_tool,
			'tools' => $executed,
			'usage' => $usage_total,
		);
	}

	/**
	 * @return array
	 */
	private static function run_tool( $tool_id, array $args ) {
		$args = self::inject_page_entity_args( $tool_id, $args );

		// مدل ترجیحی تصویر را اگر مدل خالی است تزریق کن
		if ( 'generate_image' === $tool_id && empty( $args['model'] ) ) {
			$pref = sanitize_text_field( (string) self::get_request_context( 'preferred_image_model', '' ) );
			if ( '' !== $pref ) {
				$args['model'] = $pref;
			}
		}
		if ( 'generate_video' === $tool_id && empty( $args['model'] ) ) {
			$pref = sanitize_text_field( (string) self::get_request_context( 'preferred_video_model', '' ) );
			if ( '' !== $pref ) {
				$args['model'] = $pref;
			}
		}
		if ( 'generate_speech' === $tool_id && empty( $args['model'] ) ) {
			$pref = sanitize_text_field( (string) self::get_request_context( 'preferred_audio_model', '' ) );
			if ( '' !== $pref ) {
				$args['model'] = $pref;
			}
		}
		$result = Agent_WP_Tool_Registry::instance()->execute( $tool_id, $args );
		$tool   = $result->to_array();
		$tool['id'] = $tool_id;
		return $tool;
	}

	/**
	 * اگر کاربر روی یک موجودیت است و مدل id نفرستاد، از زمینهٔ صفحه پر کن.
	 *
	 * @param string               $tool_id
	 * @param array<string,mixed>  $args
	 * @return array<string,mixed>
	 */
	private static function inject_page_entity_args( $tool_id, array $args ) {
		$page = self::get_request_context( 'page', array() );
		if ( ! is_array( $page ) ) {
			return $args;
		}
		$pid = isset( $page['postId'] ) ? absint( $page['postId'] ) : 0;
		if ( ! $pid ) {
			return $args;
		}

		switch ( $tool_id ) {
			case 'woo_products':
				$mode = sanitize_key( $args['mode'] ?? '' );
				if ( '' === $mode ) {
					// اگر فیلد آپدیت آمده، mode=update فرض کن
					$update_keys = array( 'title', 'content', 'shortDescription', 'regularPrice', 'salePrice', 'sku', 'stockStatus', 'stockQuantity', 'manageStock', 'categories', 'tags', 'featuredImageId', 'galleryIds', 'postStatus' );
					foreach ( $update_keys as $uk ) {
						if ( array_key_exists( $uk, $args ) ) {
							$mode          = 'update';
							$args['mode']  = 'update';
							break;
						}
					}
				}
				if ( in_array( $mode, array( 'get', 'update' ), true ) && empty( $args['product_id'] ) ) {
					$args['product_id'] = $pid;
				}
				break;
			case 'wp_content':
			case 'update_content':
			case 'get_content':
			case 'trash_content':
				if ( empty( $args['id'] ) && empty( $args['post_id'] ) ) {
					$args['id'] = $pid;
				}
				break;
			case 'post_meta':
				if ( empty( $args['post_id'] ) ) {
					$args['post_id'] = $pid;
				}
				break;
			case 'media':
				$mmode = sanitize_key( $args['mode'] ?? '' );
				if ( in_array( $mmode, array( 'set_featured', 'remove_featured' ), true ) && empty( $args['post_id'] ) ) {
					$args['post_id'] = $pid;
				}
				break;
			case 'terms':
				if ( 'assign' === sanitize_key( $args['mode'] ?? '' ) && empty( $args['post_id'] ) ) {
					$args['post_id'] = $pid;
				}
				break;
		}

		return $args;
	}

	/**
	 * حذف فراخوانی تکراری هم‌زمان در یک پاسخ مدل.
	 *
	 * @param array $tool_calls
	 * @return array
	 */
	private static function dedupe_tool_calls( array $tool_calls ) {
		$out  = array();
		$seen = array();
		foreach ( $tool_calls as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}
			$name = isset( $call['name'] ) ? sanitize_key( $call['name'] ) : '';
			$args = isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array();
			$fp   = self::tool_fingerprint( $name, $args );
			if ( $fp && isset( $seen[ $fp ] ) ) {
				continue;
			}
			if ( $fp ) {
				$seen[ $fp ] = true;
			}
			$out[] = $call;
		}
		return $out;
	}

	/**
	 * اثرانگشت منطقی اکشن — برای جلوگیری از ساخت دو برگه/نوشتن دوبار فایل.
	 */
	private static function tool_fingerprint( $name, array $args ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) {
			return '';
		}

		if ( 'create_page' === $name ) {
			$title = mb_strtolower( trim( (string) ( $args['title'] ?? '' ) ) );
			return $title ? ( 'create:page:' . $title ) : '';
		}
		if ( 'create_post' === $name ) {
			$title = mb_strtolower( trim( (string) ( $args['title'] ?? '' ) ) );
			return $title ? ( 'create:post:' . $title ) : '';
		}
		if ( 'wp_content' === $name && 'create' === sanitize_key( (string) ( $args['mode'] ?? '' ) ) ) {
			$type  = sanitize_key( (string) ( $args['type'] ?? 'post' ) );
			$title = mb_strtolower( trim( (string) ( $args['title'] ?? '' ) ) );
			return $title ? ( 'create:' . $type . ':' . $title ) : '';
		}
		if ( 'write_file' === $name ) {
			$path = str_replace( '\\', '/', ltrim( (string) ( $args['path'] ?? '' ), '/' ) );
			return $path ? ( 'write_file:' . mb_strtolower( $path ) ) : '';
		}
		if ( 'update_content' === $name || ( 'wp_content' === $name && 'update' === sanitize_key( (string) ( $args['mode'] ?? '' ) ) ) ) {
			$id = absint( $args['id'] ?? 0 );
			return $id ? ( 'update:' . $id ) : '';
		}

		$copy = $args;
		ksort( $copy );
		return $name . ':' . md5( (string) wp_json_encode( $copy ) );
	}

	private static function summarize_tools( array $executed ) {
		$lines = array();
		foreach ( $executed as $t ) {
			$status  = ! empty( $t['ok'] ) ? '✓' : '✗';
			$lines[] = $status . ' ' . ( isset( $t['id'] ) ? $t['id'] : 'tool' ) . ': ' . ( isset( $t['message'] ) ? $t['message'] : '' );
		}
		return $lines ? implode( "\n", $lines ) : __( 'اکشن‌ها اجرا شدند.', 'agent-wp' );
	}

	/**
	 * بعد از اجرای موفق Tool و قطع LLM: اول تأیید کن درخواست کاربر انجام شده، بعد خطای شارژ.
	 *
	 * @param string $user_text
	 * @param array  $executed
	 * @param string $error_text
	 * @return string
	 */
	private static function build_partial_success_reply( $user_text, array $executed, $error_text ) {
		$outcomes = self::verify_tool_outcomes( $user_text, $executed );
		$parts    = array();

		if ( ! empty( $outcomes['headline'] ) ) {
			$parts[] = $outcomes['headline'];
		} elseif ( ! empty( $outcomes['details'] ) ) {
			$parts[] = __( 'اکشن‌ها ذخیره شدند؛ نتیجه را در سایت بررسی کنید.', 'agent-wp' );
		}

		if ( ! empty( $outcomes['details'] ) ) {
			$parts[] = __( 'جزئیات:', 'agent-wp' ) . "\n" . implode( "\n", $outcomes['details'] );
		}

		if ( ! empty( $outcomes['can_rollback'] ) ) {
			$parts[] = __( 'اگر تغییر ناخواسته بود، از «تاریخچه اکشن‌ها» Rollback کنید.', 'agent-wp' );
		}

		$parts[] = $error_text;
		return implode( "\n\n", $parts );
	}

	/**
	 * بررسی واقعی نتیجه Tools نسبت به درخواست کاربر (بدون فراخوانی دوباره مدل).
	 *
	 * @return array{headline:string,details:array,can_rollback:bool}
	 */
	private static function verify_tool_outcomes( $user_text, array $executed ) {
		$details      = array();
		$headlines    = array();
		$can_rollback = false;
		$color_intent = self::detect_color_intent( $user_text );

		foreach ( $executed as $t ) {
			if ( empty( $t['ok'] ) || ! empty( $t['data']['needsConfirm'] ) ) {
				continue;
			}
			$id  = isset( $t['id'] ) ? sanitize_key( (string) $t['id'] ) : '';
			$msg = isset( $t['message'] ) ? (string) $t['message'] : '';
			$data = isset( $t['data'] ) && is_array( $t['data'] ) ? $t['data'] : array();

			if ( 'custom_css' === $id ) {
				$can_rollback = true;
				$live         = (string) wp_get_custom_css();
				$saved        = isset( $data['css'] ) ? (string) $data['css'] : '';
				$persisted    = ( '' !== $live ) && ( '' === $saved || false !== strpos( $live, trim( $saved ) ) || trim( $live ) === trim( $saved ) );

				if ( $color_intent ) {
					$matches = self::css_matches_color_intent( $live, $color_intent );
					if ( $persisted && $matches ) {
						$headlines[] = sprintf(
							/* translators: %s: color label like سبز */
							__( '✅ درخواست شما انجام شد: ظاهر سایت «%s» شده و CSS سفارشی روی سایت فعال است.', 'agent-wp' ),
							$color_intent['label']
						);
						$details[] = '• CSS سفارشی: ذخیره و با هدف «' . $color_intent['label'] . '» هم‌خوان است.';
					} elseif ( $persisted ) {
						$headlines[] = __( '✅ CSS سفارشی ذخیره و فعال شد؛ ولی رنگ درخواستی در CSS فعلی به‌روشنی پیدا نشد — ظاهر را یک‌بار رفرش کنید.', 'agent-wp' );
						$details[]   = '• CSS سفارشی: ذخیره شد (' . strlen( $live ) . ' بایت).';
					} else {
						$details[] = '• CSS سفارشی: ابزار گزارش موفقیت داد، ولی CSS فعلی خالی یا متفاوت است.';
					}
				} elseif ( $persisted ) {
					$headlines[] = __( '✅ CSS سفارشی ذخیره و روی سایت فعال است.', 'agent-wp' );
					$details[]   = '• CSS سفارشی: ' . ( $msg ? $msg : __( 'ذخیره شد.', 'agent-wp' ) );
				} else {
					$details[] = '• CSS سفارشی: ' . ( $msg ? $msg : __( 'اجرا شد.', 'agent-wp' ) );
				}
				continue;
			}

			if ( 'write_file' === $id ) {
				$can_rollback = true;
				$path         = isset( $data['path'] ) ? (string) $data['path'] : '';
				$exists       = false;
				if ( $path && class_exists( 'Agent_WP_Fs' ) ) {
					$read = Agent_WP_Fs::read( $path );
					$exists = ! is_wp_error( $read );
				}
				if ( $exists ) {
					$headlines[] = sprintf(
						/* translators: %s: file path */
						__( '✅ فایل ذخیره شد و روی دیسک موجود است: %s', 'agent-wp' ),
						$path
					);
					$details[] = '• write_file: ' . $path;
				} else {
					$details[] = '• write_file: ' . ( $msg ? $msg : $path );
				}
				continue;
			}

			if ( in_array( $id, array( 'create_page', 'create_post' ), true ) || ( 'wp_content' === $id && ! empty( $data['id'] ) ) ) {
				$post_id = absint( $data['pageId'] ?? $data['postId'] ?? $data['id'] ?? 0 );
				$post    = $post_id ? get_post( $post_id ) : null;
				if ( $post ) {
					$headlines[] = sprintf(
						/* translators: 1: post type label, 2: title */
						__( '✅ %1$s «%2$s» ایجاد/به‌روز شد.', 'agent-wp' ),
						get_post_type_object( $post->post_type ) ? get_post_type_object( $post->post_type )->labels->singular_name : $id,
						$post->post_title
					);
					$details[] = '• ' . $id . ': #' . $post_id;
				} else {
					$details[] = '• ' . $id . ': ' . $msg;
				}
				continue;
			}

			$details[] = '• ' . ( $id ? $id : __( 'ابزار', 'agent-wp' ) ) . ( $msg ? ': ' . $msg : '' );
			if ( ! empty( $data['restorePointId'] ) || ! empty( $data['editUrl'] ) ) {
				$can_rollback = true;
			}
		}

		$headline = $headlines ? $headlines[0] : '';
		return array(
			'headline'     => $headline,
			'details'      => $details,
			'can_rollback' => $can_rollback,
		);
	}

	/**
	 * @return array{label:string,tokens:array}|null
	 */
	private static function detect_color_intent( $text ) {
		$t = mb_strtolower( (string) $text );
		$map = array(
			array( 'label' => 'سبز', 'tokens' => array( 'سبز', 'green', '#0f0', '#00ff00', '#4caf50', '#22c55e' ) ),
			array( 'label' => 'قرمز', 'tokens' => array( 'قرمز', 'red', '#f00', '#ff0000', '#e53935', '#ef4444' ) ),
			array( 'label' => 'آبی', 'tokens' => array( 'آبی', 'ابي', 'blue', '#00f', '#0000ff', '#2196f3', '#3b82f6' ) ),
			array( 'label' => 'زرد', 'tokens' => array( 'زرد', 'yellow', '#ff0', '#ffff00', '#fbc02d' ) ),
			array( 'label' => 'مشکی', 'tokens' => array( 'مشکی', 'سیاه', 'black', '#000', '#000000' ) ),
			array( 'label' => 'سفید', 'tokens' => array( 'سفید', 'white', '#fff', '#ffffff' ) ),
			array( 'label' => 'بنفش', 'tokens' => array( 'بنفش', 'purple', 'violet', '#9c27b0', '#a855f7' ) ),
			array( 'label' => 'نارنجی', 'tokens' => array( 'نارنجی', 'orange', '#ff9800', '#f97316' ) ),
		);
		foreach ( $map as $row ) {
			foreach ( $row['tokens'] as $tok ) {
				if ( false !== mb_strpos( $t, mb_strtolower( $tok ) ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	/**
	 * @param string $css
	 * @param array  $intent
	 */
	private static function css_matches_color_intent( $css, array $intent ) {
		$hay = mb_strtolower( (string) $css );
		if ( '' === trim( $hay ) ) {
			return false;
		}
		foreach ( $intent['tokens'] as $tok ) {
			$tok = mb_strtolower( (string) $tok );
			if ( '' === $tok ) {
				continue;
			}
			if ( false !== mb_strpos( $hay, $tok ) ) {
				return true;
			}
		}
		// رنگ‌های کوتاه مثل green در background / color
		$label = isset( $intent['label'] ) ? $intent['label'] : '';
		$named = array(
			'سبز'  => array( 'green', 'lime', 'forestgreen', 'seagreen', '#0f0', '#00ff00', '#4caf50', '#22c55e', '#2ecc71', '#28a745', '#008000', 'rgb(0, 128', 'rgb(0,128', 'rgb(46, 204', 'rgb(76, 175' ),
			'قرمز' => array( 'red', 'crimson', '#f00', '#ff0000', '#e53935', '#ef4444', '#f44336', 'rgb(255, 0', 'rgb(255,0' ),
			'آبی'  => array( 'blue', 'dodgerblue', 'royalblue', '#00f', '#0000ff', '#2196f3', '#3b82f6', 'rgb(0, 0, 255', 'rgb(0,0,255', 'rgb(33, 150' ),
		);
		if ( isset( $named[ $label ] ) ) {
			foreach ( $named[ $label ] as $n ) {
				if ( false !== mb_strpos( $hay, $n ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return array{tool:string,args:array}|null
	 */
	private static function parse_tool_marker( $content ) {
		// TOOL_JSON: {...}
		if ( preg_match( '/TOOL_JSON\s*[:：]\s*(\{.*\})\s*$/uis', $content, $m ) ) {
			$data = json_decode( $m[1], true );
			if ( is_array( $data ) && ! empty( $data['tool'] ) ) {
				return array(
					'tool' => sanitize_key( $data['tool'] ),
					'args' => isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array(),
				);
			}
		}

		$patterns = array(
			'create_page'       => '/TOOL_CREATE_PAGE\s*[:：]\s*(.+)$/uim',
			'create_post'       => '/TOOL_CREATE_POST\s*[:：]\s*(.+)$/uim',
			'update_site_title' => '/TOOL_UPDATE_SITE_TITLE\s*[:：]\s*(.+)$/uim',
			'list_pages'        => '/TOOL_LIST_PAGES(?:\s*[:：]\s*(.*))?$/uim',
			'site_info'         => '/TOOL_SITE_INFO\b/ui',
			'list_posts'        => '/TOOL_LIST_POSTS(?:\s*[:：]\s*(.*))?$/uim',
			'get_content'       => '/TOOL_GET_CONTENT\s*[:：]\s*(\d+)/uim',
			'trash_content'     => '/TOOL_TRASH_CONTENT\s*[:：]\s*(\d+)/uim',
			'list_files'        => '/TOOL_LIST_FILES(?:\s*[:：]\s*(.*))?$/uim',
			'read_file'         => '/TOOL_READ_FILE\s*[:：]\s*(.+)$/uim',
			'custom_css'        => '/TOOL_CUSTOM_CSS\s*[:：]\s*(get|set|append)(?:\s*\n([\s\S]*))?$/uim',
		);

		foreach ( $patterns as $tool_id => $pattern ) {
			if ( ! preg_match( $pattern, $content, $m ) ) {
				continue;
			}
			switch ( $tool_id ) {
				case 'site_info':
					return array( 'tool' => 'site_info', 'args' => array() );
				case 'list_pages':
					return array(
						'tool' => 'list_pages',
						'args' => array(
							'search' => isset( $m[1] ) ? trim( $m[1], " \t\"'«»" ) : '',
							'limit'  => 10,
						),
					);
				case 'list_posts':
					return array(
						'tool' => 'list_posts',
						'args' => array(
							'search' => isset( $m[1] ) ? trim( $m[1], " \t\"'«»" ) : '',
							'limit'  => 10,
						),
					);
				case 'get_content':
				case 'trash_content':
					return array(
						'tool' => $tool_id,
						'args' => array( 'id' => absint( $m[1] ) ),
					);
				case 'list_files':
					$path = isset( $m[1] ) ? trim( $m[1] ) : 'theme/';
					return array(
						'tool' => 'list_files',
						'args' => array( 'path' => $path ? $path : 'theme/' ),
					);
				case 'read_file':
					return array(
						'tool' => 'read_file',
						'args' => array( 'path' => trim( $m[1] ) ),
					);
				case 'custom_css':
					$mode = strtolower( trim( $m[1] ) );
					$args = array( 'mode' => $mode );
					if ( isset( $m[2] ) && '' !== trim( $m[2] ) ) {
						$args['css'] = trim( $m[2] );
					}
					return array( 'tool' => 'custom_css', 'args' => $args );
				default:
					$title = trim( $m[1], " \t\"'«»" );
					if ( '' !== $title ) {
						return array(
							'tool' => $tool_id,
							'args' => array( 'title' => $title ),
						);
					}
			}
		}

		// TOOL_WRITE_FILE: path\n<<<\ncontent\n>>>
		if ( preg_match( '/TOOL_WRITE_FILE\s*[:：]\s*(.+?)\s*\n+<<<\n([\s\S]*?)\n>>>/u', $content, $wm ) ) {
			return array(
				'tool' => 'write_file',
				'args' => array(
					'path'    => trim( $wm[1] ),
					'content' => $wm[2],
				),
			);
		}

		// TOOL_UPDATE_CONTENT: id | title | ...
		if ( preg_match( '/TOOL_UPDATE_CONTENT\s*[:：]\s*(\d+)(?:\s*\|\s*(.+))?$/uim', $content, $um ) ) {
			$args = array( 'id' => absint( $um[1] ) );
			if ( ! empty( $um[2] ) ) {
				$args['title'] = trim( $um[2] );
			}
			return array( 'tool' => 'update_content', 'args' => $args );
		}

		return null;
	}

	private static function system_prompt() {
		$tools = Agent_WP_Tool_Registry::instance()->all_public();
		$lines = array();
		foreach ( $tools as $t ) {
			if ( 'ping' === $t['id'] ) {
				continue;
			}
			$lines[] = '- ' . $t['id'] . ': ' . ( isset( $t['description'] ) ? $t['description'] : $t['label'] );
		}
		$tool_list = implode( "\n", $lines );

		$theme = wp_get_theme();
		$site  = get_bloginfo( 'name' );

		return "تو Agent WP هستی — دستیار اکشن‌محور مدیریت وردپرس داخل پیشخوان.\n"
			. "سایت: {$site} | قالب فعال: " . ( $theme ? $theme->get( 'Name' ) : '' ) . "\n"
			. "زبان پاسخ: فارسی، کوتاه و عملی. وقتی اکشن انجام می‌دهی خلاصه بگو چه شد.\n\n"
			. "استراتژی دسترسی عمومی:\n"
			. "1) اول discover بزن تا post_typeها، افزونه‌ها و مسیرهای REST را ببینی.\n"
			. "2) برای هر نوع محتوا از wp_content / post_meta / rest / update_option استفاده کن.\n"
			. "3) خواندن کد: list_files و read_file روی theme/، parent/، themes/، plugin/نام‌افزونه/، mu-plugin/، workspace/.\n"
			. "4) نوشتن کد: write_file روی همان مسیرها مجاز است؛ برای قالب و افزونه UI حتماً از کاربر تأیید + اخطار می‌گیرد و نقطه بازگشت می‌سازد.\n"
			. "5) هسته وردپرس و wp-config را هرگز دست نزن.\n"
			. "6) قبل از تغییر افزونه مهم، کوتاه اخطار بده که ممکن است به‌روزرسانی بعدی کد را بازنویسی کند.\n\n"
			. "اصول امنیتی و محصول:\n"
			. "1) اکشن واقعی بزن؛ فقط حرف نزن.\n"
			. "2) هر اکشن را فقط یک‌بار صدا بزن. برای یک برگه فقط create_page یا فقط wp_content(create) — نه هر دو، نه دوبار.\n"
			. "3) سیاست تأیید UI:\n"
			. "   - سریع بدون تأیید: عنوان/محتوا/خلاصه/برچسب، woo_products update، تصویر شاخص، متای allowlist محصول/سئو، optionهای allowlist.\n"
			. "   - با تأیید UI: write_file قالب/افزونه، custom_css، update_option غیرمجاز، REST نوشتنی عمومی، trash، search_replace apply، متای ناشناخته.\n"
			. "4) برگه/نوشتهٔ جدید پیش‌فرض draft مگر کاربر صریحاً publish بخواهد.\n"
			. "5) دیزاین: برای تغییر رنگ/CSS فوراً custom_css (یا write_file روی CSS) را صدا بزن. UI خودش تأیید/لغو را نشان می‌دهد.\n"
			. "6) ساخت تصویر/بنر/عکس محصول: فوراً generate_image را صدا بزن. مدل پیش‌فرض ترجیحی: gapgpt/z-image. نگو «نمی‌توانم تصویر بسازم». اگر مدل شکست بخورد UI تأیید مدل جایگزین می‌گیرد.\n"
			. "7) لندینگ + تصاویر خودساخته: اول generate_image، بعد create_page/wp_content با همان URLها؛ placeholder جعلی نگذار.\n"
			. "8) ساخت ویدیو: فوراً generate_video را صدا بزن و در رسانه ذخیره کن.\n"
			. "9) ساخت گفتار/نریشن: فوراً generate_speech را صدا بزن و فایل صوتی را برگردان.\n"
			. "10) رونویسی صوت آپلودشده: transcribe_audio را با attachmentId (یا url) صدا بزن.\n"
			. "11) هرگز در متن از کاربر نپرس «آیا ادامه دهم؟» — فقط ابزار را صدا بزن و منتظر دکمه UI بمان.\n"
			. "12) اگر صفحهٔ زنده داری و کاربر دستور فیلد می‌دهد، روی همان postId اجرا کن؛ id را اگر جا افتاد سیستم تزریق می‌کند.\n"
			. "13) از function calling استفاده کن.\n\n"
			. "ابزارهای موجود:\n{$tool_list}";
	}

	private static function fallback_help() {
		return __( 'مدل GapGPT آماده نیست. از تنظیمات مدل و کلید را بررسی کنید.', 'agent-wp' );
	}
}
