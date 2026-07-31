<?php
/**
 * کلاینت LLM سازگار با OpenAI Chat Completions + function calling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Llm {

	/**
	 * @return array|WP_Error { content:string, toolCalls?:array, usage?:array, baseUrl?:string, rawMessage?:array }
	 */
	public static function chat( $model_id, array $messages, array $opts = array() ) {
		$model = Agent_WP_Models::get( (int) $model_id );
		if ( ! $model ) {
			return new WP_Error( 'no_model', __( 'مدل یافت نشد.', 'agent-wp' ) );
		}

		$api_key = Agent_WP_Crypto::decrypt( (string) $model['api_key_enc'] );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'API Key مدل خالی است.', 'agent-wp' ) );
		}

		$model_name = trim( (string) $model['model_name'] );
		if ( 'cerebras' === sanitize_key( (string) $model['provider'] ) ) {
			$model_name = self::normalize_cerebras_model( $model_name );
		}

		$primary = self::normalize_base(
			! empty( $model['base_url'] ) ? $model['base_url'] : self::default_base( $model['provider'] )
		);

		$bases = array( $primary );
		if ( 'gapgpt' === sanitize_key( (string) $model['provider'] ) ) {
			$alt = ( false !== strpos( $primary, 'gapapi.com' ) )
				? Agent_WP_Models::GAPGPT_BASE_DIRECT
				: Agent_WP_Models::GAPGPT_BASE_CDN;
			$alt = self::normalize_base( $alt );
			if ( $alt && $alt !== $primary ) {
				$bases[] = $alt;
			}
		}

		$last_error = null;
		foreach ( $bases as $index => $base ) {
			$result = self::request_chat( $base, $api_key, $model_name, $messages, $opts );
			if ( ! is_wp_error( $result ) ) {
				$result['baseUrl'] = $base;
				if ( $index > 0 && 'gapgpt' === sanitize_key( (string) $model['provider'] ) ) {
					$mode = ( false !== strpos( $base, 'gapapi.com' ) ) ? 'cdn' : 'direct';
					Agent_WP_Models::set_gapgpt_mode( $mode );
				}
				return $result;
			}
			$last_error = $result;
			// اگر مدل tools را پشتیبانی نمی‌کند، یک‌بار بدون tools امتحان کن
			if ( ! empty( $opts['tools'] ) && self::is_tools_unsupported( $result ) ) {
				$opts_no = $opts;
				unset( $opts_no['tools'], $opts_no['tool_choice'] );
				$result2 = self::request_chat( $base, $api_key, $model_name, $messages, $opts_no );
				if ( ! is_wp_error( $result2 ) ) {
					$result2['baseUrl']         = $base;
					$result2['toolsDisabled'] = true;
					return $result2;
				}
			}
		}

		return $last_error ? $last_error : new WP_Error( 'llm_fail', __( 'اتصال به مدل ناموفق بود.', 'agent-wp' ) );
	}

	private static function is_tools_unsupported( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$msg = strtolower( $error->get_error_message() );
		return false !== strpos( $msg, 'tool' ) || false !== strpos( $msg, 'function' );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function request_chat( $base, $api_key, $model_name, array $messages, array $opts ) {
		$url  = $base . '/chat/completions';
		$body = array(
			'model'       => $model_name,
			'messages'    => array_values( $messages ),
			'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.3,
		);

		if ( ! empty( $opts['tools'] ) && is_array( $opts['tools'] ) ) {
			$body['tools'] = array_values( $opts['tools'] );
			$body['tool_choice'] = isset( $opts['tool_choice'] ) ? $opts['tool_choice'] : 'auto';
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = self::extract_error_message( $data, $raw, $code );
			if ( class_exists( 'Agent_WP_Gapgpt' ) ) {
				// ذخیره سهمیه از متن خطا (اگر بود)
				Agent_WP_Gapgpt::humanize_api_error( $msg );
			}
			if ( 404 === $code ) {
				$msg .= ' ' . sprintf(
					__( 'مدل «%s» در این ارائه‌دهنده پیدا نشد؛ نام مدل را در تنظیمات عوض کنید.', 'agent-wp' ),
					$model_name
				);
			}
			return new WP_Error( 'llm_http', $msg );
		}

		$message = ( is_array( $data ) && isset( $data['choices'][0]['message'] ) && is_array( $data['choices'][0]['message'] ) )
			? $data['choices'][0]['message']
			: array();

		$content = '';
		if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
			$content = trim( $message['content'] );
		}

		$tool_calls = array();
		if ( ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			foreach ( $message['tool_calls'] as $tc ) {
				if ( ! is_array( $tc ) ) {
					continue;
				}
				$fn = isset( $tc['function'] ) && is_array( $tc['function'] ) ? $tc['function'] : array();
				$args_raw = isset( $fn['arguments'] ) ? (string) $fn['arguments'] : '{}';
				$args     = json_decode( $args_raw, true );
				if ( ! is_array( $args ) ) {
					$args = array();
				}
				$tool_calls[] = array(
					'id'   => isset( $tc['id'] ) ? (string) $tc['id'] : uniqid( 'call_', true ),
					'name' => isset( $fn['name'] ) ? sanitize_key( $fn['name'] ) : '',
					'args' => $args,
				);
			}
		}

		if ( '' === $content && ! $tool_calls ) {
			return new WP_Error( 'empty', __( 'پاسخ مدل خالی بود.', 'agent-wp' ) );
		}

		$usage = array();
		if ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$usage = array(
				'promptTokens'     => isset( $data['usage']['prompt_tokens'] ) ? (int) $data['usage']['prompt_tokens'] : 0,
				'completionTokens' => isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0,
				'totalTokens'      => isset( $data['usage']['total_tokens'] ) ? (int) $data['usage']['total_tokens'] : 0,
			);
		}

		return array(
			'content'    => $content,
			'toolCalls'  => $tool_calls,
			'usage'      => $usage,
			'rawMessage' => $message,
		);
	}

	private static function extract_error_message( $data, $raw, $code ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				return $data['error']['message'];
			}
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
		}

		$snippet = trim( wp_strip_all_tags( (string) $raw ) );
		if ( '' !== $snippet ) {
			return mb_substr( $snippet, 0, 240 );
		}

		return sprintf( __( 'خطای API مدل (کد %d).', 'agent-wp' ), $code );
	}

	/**
	 * ساخت تصویر از طریق Endpoint سازگار با OpenAI: POST /images/generations
	 *
	 * @return array|WP_Error { url?:string, b64?:string, revisedPrompt?:string, model:string, raw?:array }
	 */
	public static function generate_image( $model_name, $prompt, array $opts = array() ) {
		$model_name = trim( (string) $model_name );
		$prompt     = trim( (string) $prompt );
		if ( '' === $model_name || '' === $prompt ) {
			return new WP_Error( 'invalid', __( 'مدل و پرامپت تصویر لازم است.', 'agent-wp' ) );
		}

		$api_key = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ) );
		}

		$primary = self::normalize_base( Agent_WP_Models::get_gapgpt_base() );
		$bases   = array( $primary );
		$alt     = ( false !== strpos( $primary, 'gapapi.com' ) )
			? Agent_WP_Models::GAPGPT_BASE_DIRECT
			: Agent_WP_Models::GAPGPT_BASE_CDN;
		$alt = self::normalize_base( $alt );
		if ( $alt && $alt !== $primary ) {
			$bases[] = $alt;
		}

		$size = isset( $opts['size'] ) ? sanitize_text_field( (string) $opts['size'] ) : '1024x1024';
		if ( ! preg_match( '/^\d{2,4}x\d{2,4}$/', $size ) ) {
			$size = '1024x1024';
		}

		$body = array(
			'model'  => $model_name,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $size,
		);

		// نمونه رسمی GapGPT (z-image) بدون response_format است؛ اول همان را بزن
		$variants = array( $body );
		$with_url = $body;
		$with_url['response_format'] = 'url';
		$variants[] = $with_url;
		$with_b64 = $body;
		$with_b64['response_format'] = 'b64_json';
		$variants[] = $with_b64;

		$last_error = null;
		foreach ( $bases as $base ) {
			foreach ( $variants as $payload ) {
				$result = self::request_image( $base, $api_key, $payload );
				if ( ! is_wp_error( $result ) ) {
					$result['model']   = $model_name;
					$result['baseUrl'] = $base;
					return $result;
				}
				$last_error = $result;
			}
		}

		return $last_error ? $last_error : new WP_Error( 'image_fail', __( 'ساخت تصویر ناموفق بود.', 'agent-wp' ) );
	}

	/**
	 * @return array|WP_Error
	 */
	private static function request_image( $base, $api_key, array $body ) {
		$url      = untrailingslashit( $base ) . '/images/generations';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = self::extract_error_message( $data, $raw, $code );
			if ( class_exists( 'Agent_WP_Gapgpt' ) ) {
				$msg = Agent_WP_Gapgpt::humanize_api_error( $msg );
			}
			return new WP_Error( 'image_http', $msg );
		}

		$item = ( is_array( $data ) && ! empty( $data['data'][0] ) && is_array( $data['data'][0] ) )
			? $data['data'][0]
			: array();

		$url_out = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
		$b64     = isset( $item['b64_json'] ) ? (string) $item['b64_json'] : '';
		if ( '' === $url_out && '' === $b64 ) {
			return new WP_Error( 'empty_image', __( 'پاسخ ساخت تصویر خالی بود.', 'agent-wp' ) );
		}

		return array(
			'url'           => $url_out,
			'b64'           => $b64,
			'revisedPrompt' => isset( $item['revised_prompt'] ) ? (string) $item['revised_prompt'] : '',
			'raw'           => $data,
		);
	}

	/**
	 * ذخیره خروجی تصویر در کتابخانه رسانه وردپرس.
	 *
	 * @param array{url?:string,b64?:string} $image
	 * @return array|WP_Error { id:int, url:string, name:string, mime:string, isImage:bool }
	 */
	public static function sideload_generated_image( array $image, $title = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = sanitize_file_name( $title ? $title : 'agent-wp-image-' . gmdate( 'Ymd-His' ) );
		$tmp   = '';

		if ( ! empty( $image['url'] ) ) {
			$tmp = download_url( (string) $image['url'], 90 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		} elseif ( ! empty( $image['b64'] ) ) {
			$bin = base64_decode( (string) $image['b64'], true );
			if ( false === $bin || '' === $bin ) {
				return new WP_Error( 'b64', __( 'داده تصویر نامعتبر است.', 'agent-wp' ) );
			}
			$tmp = wp_tempnam( $title . '.png' );
			if ( ! $tmp || false === file_put_contents( $tmp, $bin ) ) {
				return new WP_Error( 'tmp', __( 'نوشتن فایل موقت تصویر ناموفق بود.', 'agent-wp' ) );
			}
		} else {
			return new WP_Error( 'no_image', __( 'خروجی تصویر برای ذخیره نیست.', 'agent-wp' ) );
		}

		$file_array = array(
			'name'     => $title . '.png',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		$url  = wp_get_attachment_url( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );

		return array(
			'id'      => (int) $attachment_id,
			'url'     => $url ? $url : '',
			'name'    => get_the_title( $attachment_id ) ? get_the_title( $attachment_id ) : $title,
			'mime'    => $mime ? $mime : 'image/png',
			'isImage' => true,
		);
	}

	/**
	 * متن به گفتار — OpenAI-compatible POST /audio/speech
	 *
	 * @return array|WP_Error { binary:string, mime:string, model:string }
	 */
	public static function generate_speech( $model_name, $text, array $opts = array() ) {
		$model_name = trim( (string) $model_name );
		$text       = trim( (string) $text );
		if ( '' === $model_name || '' === $text ) {
			return new WP_Error( 'invalid', __( 'مدل و متن گفتار لازم است.', 'agent-wp' ) );
		}
		// محدودیت طول برای جلوگیری از timeout/هزینه زیاد
		if ( mb_strlen( $text ) > 4000 ) {
			$text = mb_substr( $text, 0, 4000 );
		}
		$api_key = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ) );
		}

		$voice  = isset( $opts['voice'] ) ? sanitize_text_field( (string) $opts['voice'] ) : 'alloy';
		$format = isset( $opts['format'] ) ? sanitize_key( (string) $opts['format'] ) : 'mp3';
		if ( ! in_array( $format, array( 'mp3', 'opus', 'aac', 'flac', 'wav', 'pcm' ), true ) ) {
			$format = 'mp3';
		}

		$body = array(
			'model'           => $model_name,
			'input'           => $text,
			'voice'           => $voice ? $voice : 'alloy',
			'response_format' => $format,
		);

		$last_error = null;
		foreach ( self::api_bases() as $base ) {
			$url      = untrailingslashit( $base ) . '/audio/speech';
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 120,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'Accept'        => '*/*',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );
			$ct   = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( $code < 200 || $code >= 300 ) {
				$data = json_decode( $raw, true );
				$msg  = self::extract_error_message( $data, $raw, $code );
				if ( class_exists( 'Agent_WP_Gapgpt' ) ) {
					$msg = Agent_WP_Gapgpt::humanize_api_error( $msg );
				}
				$last_error = new WP_Error( 'speech_http', $msg );
				continue;
			}
			// بعضی درگاه‌ها JSON با url/b64 برمی‌گردانند
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				$url_out = self::pick_media_url( $data );
				$b64     = self::pick_media_b64( $data );
				if ( $url_out || $b64 ) {
					return array(
						'url'    => $url_out,
						'b64'    => $b64,
						'binary' => '',
						'mime'   => 'audio/' . ( 'mp3' === $format ? 'mpeg' : $format ),
						'model'  => $model_name,
						'ext'    => $format,
					);
				}
			}
			if ( '' === $raw ) {
				$last_error = new WP_Error( 'empty_audio', __( 'خروجی گفتار خالی بود.', 'agent-wp' ) );
				continue;
			}
			$mime = ( $ct && false === strpos( $ct, 'json' ) ) ? strtok( $ct, ';' ) : ( 'audio/' . ( 'mp3' === $format ? 'mpeg' : $format ) );
			return array(
				'binary' => $raw,
				'mime'   => $mime,
				'model'  => $model_name,
				'ext'    => $format,
				'url'    => '',
				'b64'    => '',
			);
		}

		return $last_error ? $last_error : new WP_Error( 'speech_fail', __( 'ساخت گفتار ناموفق بود.', 'agent-wp' ) );
	}

	/**
	 * رونویسی صوت — OpenAI-compatible POST /audio/transcriptions
	 *
	 * @param string $file_path مسیر محلی فایل
	 * @return array|WP_Error { text:string, model:string }
	 */
	public static function transcribe_audio( $model_name, $file_path, array $opts = array() ) {
		$model_name = trim( (string) $model_name );
		$file_path  = (string) $file_path;
		if ( '' === $model_name || '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'invalid', __( 'مدل و فایل صوت لازم است.', 'agent-wp' ) );
		}
		$api_key = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ) );
		}
		if ( ! function_exists( 'curl_init' ) || ! class_exists( 'CURLFile' ) ) {
			return new WP_Error( 'curl', __( 'برای رونویسی صوت، PHP cURL لازم است.', 'agent-wp' ) );
		}

		$filename = isset( $opts['filename'] ) ? sanitize_file_name( (string) $opts['filename'] ) : basename( $file_path );
		$language = isset( $opts['language'] ) ? sanitize_text_field( (string) $opts['language'] ) : 'fa';
		$mime     = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $file_path ) : 'audio/mpeg';
		if ( ! $mime ) {
			$mime = 'audio/mpeg';
		}

		$last_error = null;
		foreach ( self::api_bases() as $base ) {
			$url  = untrailingslashit( $base ) . '/audio/transcriptions';
			$post = array(
				'model' => $model_name,
				'file'  => new CURLFile( $file_path, $mime, $filename ),
			);
			if ( $language ) {
				$post['language'] = $language;
			}

			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $post,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 120,
					CURLOPT_HTTPHEADER     => array(
						'Authorization: Bearer ' . $api_key,
						'Accept: application/json',
					),
				)
			);
			$raw  = curl_exec( $ch );
			$err  = curl_error( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( false === $raw ) {
				$last_error = new WP_Error( 'curl', $err ? $err : __( 'خطای cURL در رونویسی.', 'agent-wp' ) );
				continue;
			}
			$data = json_decode( (string) $raw, true );
			if ( $code < 200 || $code >= 300 ) {
				$msg = self::extract_error_message( $data, (string) $raw, $code );
				if ( class_exists( 'Agent_WP_Gapgpt' ) ) {
					$msg = Agent_WP_Gapgpt::humanize_api_error( $msg );
				}
				$last_error = new WP_Error( 'stt_http', $msg );
				continue;
			}
			$text = ( is_array( $data ) && isset( $data['text'] ) ) ? trim( (string) $data['text'] ) : trim( (string) $raw );
			if ( '' === $text ) {
				$last_error = new WP_Error( 'empty_stt', __( 'متن رونویسی خالی بود.', 'agent-wp' ) );
				continue;
			}
			return array(
				'text'  => $text,
				'model' => $model_name,
				'raw'   => $data,
			);
		}

		return $last_error ? $last_error : new WP_Error( 'stt_fail', __( 'رونویسی صوت ناموفق بود.', 'agent-wp' ) );
	}

	/**
	 * ساخت ویدیو — چند مسیر رایج GapGPT/سازگار با OpenAI را امتحان می‌کند.
	 *
	 * @return array|WP_Error { url?:string, b64?:string, model:string }
	 */
	public static function generate_video( $model_name, $prompt, array $opts = array() ) {
		$model_name = trim( (string) $model_name );
		$prompt     = trim( (string) $prompt );
		if ( '' === $model_name || '' === $prompt ) {
			return new WP_Error( 'invalid', __( 'مدل و پرامپت ویدیو لازم است.', 'agent-wp' ) );
		}
		$api_key = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ) );
		}

		$seconds = isset( $opts['seconds'] ) ? absint( $opts['seconds'] ) : 5;
		if ( $seconds < 2 ) {
			$seconds = 2;
		}
		if ( $seconds > 20 ) {
			$seconds = 20;
		}
		$size = isset( $opts['size'] ) ? sanitize_text_field( (string) $opts['size'] ) : '1280x720';

		$body = array(
			'model'    => $model_name,
			'prompt'   => $prompt,
			'n'        => 1,
			'size'     => $size,
			'duration' => $seconds,
			'seconds'  => $seconds,
		);

		$paths = array(
			'/videos/generations',
			'/video/generations',
			'/videos',
		);

		$last_error = null;
		foreach ( self::api_bases() as $base ) {
			foreach ( $paths as $path ) {
				$result = self::request_json_media( untrailingslashit( $base ) . $path, $api_key, $body, 180 );
				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					continue;
				}
				// async job?
				$job = self::maybe_poll_video_job( $base, $api_key, $result );
				if ( is_wp_error( $job ) ) {
					$last_error = $job;
					continue;
				}
				if ( is_array( $job ) ) {
					$result = $job;
				}
				$url_out = self::pick_media_url( $result );
				$b64     = self::pick_media_b64( $result );
				if ( ! $url_out && ! $b64 ) {
					$last_error = new WP_Error( 'empty_video', __( 'خروجی ویدیو خالی بود.', 'agent-wp' ) );
					continue;
				}
				return array(
					'url'   => $url_out,
					'b64'   => $b64,
					'model' => $model_name,
					'raw'   => $result,
				);
			}
		}

		return $last_error ? $last_error : new WP_Error( 'video_fail', __( 'ساخت ویدیو ناموفق بود.', 'agent-wp' ) );
	}

	/**
	 * ذخیره باینری/URL در رسانه.
	 *
	 * @param array{url?:string,b64?:string,binary?:string} $file
	 * @return array|WP_Error
	 */
	public static function sideload_generated_file( array $file, $title = '', $ext = 'bin', $mime = 'application/octet-stream' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = sanitize_file_name( $title ? $title : 'agent-wp-media-' . gmdate( 'Ymd-His' ) );
		$ext   = preg_replace( '/[^a-z0-9]/i', '', (string) $ext );
		if ( '' === $ext ) {
			$ext = 'bin';
		}
		$tmp = '';

		if ( ! empty( $file['url'] ) ) {
			$url_in = (string) $file['url'];
			$path_e = strtolower( pathinfo( wp_parse_url( $url_in, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( $path_e && preg_match( '/^[a-z0-9]{2,5}$/', $path_e ) ) {
				$ext = $path_e;
			}
			$tmp = download_url( $url_in, 180 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
		} elseif ( ! empty( $file['b64'] ) ) {
			$bin = base64_decode( (string) $file['b64'], true );
			if ( false === $bin || '' === $bin ) {
				return new WP_Error( 'b64', __( 'داده فایل نامعتبر است.', 'agent-wp' ) );
			}
			$tmp = wp_tempnam( $title . '.' . $ext );
			if ( ! $tmp || false === file_put_contents( $tmp, $bin ) ) {
				return new WP_Error( 'tmp', __( 'نوشتن فایل موقت ناموفق بود.', 'agent-wp' ) );
			}
		} elseif ( ! empty( $file['binary'] ) ) {
			$tmp = wp_tempnam( $title . '.' . $ext );
			if ( ! $tmp || false === file_put_contents( $tmp, (string) $file['binary'] ) ) {
				return new WP_Error( 'tmp', __( 'نوشتن فایل موقت ناموفق بود.', 'agent-wp' ) );
			}
		} else {
			return new WP_Error( 'no_file', __( 'خروجی فایل برای ذخیره نیست.', 'agent-wp' ) );
		}

		$file_array = array(
			'name'     => $title . '.' . $ext,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		$url  = wp_get_attachment_url( $attachment_id );
		$got  = get_post_mime_type( $attachment_id );
		$mime = $got ? $got : $mime;
		$kind = ( 0 === strpos( $mime, 'video/' ) ) ? 'video' : ( ( 0 === strpos( $mime, 'audio/' ) ) ? 'audio' : 'file' );

		return array(
			'id'       => (int) $attachment_id,
			'url'      => $url ? $url : '',
			'name'     => get_the_title( $attachment_id ) ? get_the_title( $attachment_id ) : $title,
			'mime'     => $mime,
			'isImage'  => false,
			'isVideo'  => ( 'video' === $kind ),
			'isAudio'  => ( 'audio' === $kind ),
		);
	}

	/**
	 * @return string[]
	 */
	private static function api_bases() {
		$primary = self::normalize_base( Agent_WP_Models::get_gapgpt_base() );
		$bases   = array( $primary );
		$alt     = ( false !== strpos( $primary, 'gapapi.com' ) )
			? Agent_WP_Models::GAPGPT_BASE_DIRECT
			: Agent_WP_Models::GAPGPT_BASE_CDN;
		$alt = self::normalize_base( $alt );
		if ( $alt && $alt !== $primary ) {
			$bases[] = $alt;
		}
		return $bases;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function request_json_media( $url, $api_key, array $body, $timeout = 120 ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = self::extract_error_message( $data, $raw, $code );
			if ( class_exists( 'Agent_WP_Gapgpt' ) ) {
				$msg = Agent_WP_Gapgpt::humanize_api_error( $msg );
			}
			return new WP_Error( 'media_http', $msg );
		}
		return is_array( $data ) ? $data : array( 'raw' => $raw );
	}

	/**
	 * @param mixed $data
	 */
	private static function pick_media_url( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$candidates = array(
			isset( $data['data'][0]['url'] ) ? $data['data'][0]['url'] : '',
			isset( $data['url'] ) ? $data['url'] : '',
			isset( $data['video_url'] ) ? $data['video_url'] : '',
			isset( $data['audio_url'] ) ? $data['audio_url'] : '',
			isset( $data['output']['url'] ) ? $data['output']['url'] : '',
			isset( $data['result']['url'] ) ? $data['result']['url'] : '',
			isset( $data['unsigned_urls'][0] ) ? $data['unsigned_urls'][0] : '',
		);
		foreach ( $candidates as $u ) {
			$u = esc_url_raw( (string) $u );
			if ( $u ) {
				return $u;
			}
		}
		return '';
	}

	/**
	 * @param mixed $data
	 */
	private static function pick_media_b64( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		$candidates = array(
			isset( $data['data'][0]['b64_json'] ) ? $data['data'][0]['b64_json'] : '',
			isset( $data['b64_json'] ) ? $data['b64_json'] : '',
			isset( $data['b64'] ) ? $data['b64'] : '',
		);
		foreach ( $candidates as $b ) {
			if ( is_string( $b ) && '' !== $b ) {
				return $b;
			}
		}
		return '';
	}

	/**
	 * اگر پاسخ job async بود، چند بار poll کن.
	 *
	 * @param array $data
	 * @return array|WP_Error|null null = نیازی به poll نبود
	 */
	private static function maybe_poll_video_job( $base, $api_key, array $data ) {
		$id = '';
		if ( ! empty( $data['id'] ) && ( ! empty( $data['status'] ) || ! empty( $data['polling_url'] ) ) ) {
			$id = (string) $data['id'];
		}
		$poll_url = ! empty( $data['polling_url'] ) ? (string) $data['polling_url'] : '';
		if ( ! $id && ! $poll_url ) {
			return null;
		}
		if ( ! $poll_url ) {
			$poll_url = untrailingslashit( $base ) . '/videos/' . rawurlencode( $id );
		}

		$status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : 'queued';
		if ( in_array( $status, array( 'completed', 'succeeded', 'success', 'done' ), true ) ) {
			return $data;
		}

		for ( $i = 0; $i < 24; $i++ ) {
			sleep( 5 );
			$res = wp_remote_get(
				$poll_url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept'        => 'application/json',
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
				continue;
			}
			$st = isset( $body['status'] ) ? strtolower( (string) $body['status'] ) : '';
			if ( in_array( $st, array( 'failed', 'error', 'cancelled' ), true ) ) {
				$msg = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'ساخت ویدیو ناموفق بود.', 'agent-wp' );
				return new WP_Error( 'video_job', $msg );
			}
			if ( in_array( $st, array( 'completed', 'succeeded', 'success', 'done' ), true ) || self::pick_media_url( $body ) ) {
				return $body;
			}
		}

		return new WP_Error( 'video_timeout', __( 'ساخت ویدیو طولانی شد. بعداً دوباره تلاش کنید.', 'agent-wp' ) );
	}

	private static function normalize_base( $base ) {
		$base = untrailingslashit( trim( (string) $base ) );
		$base = preg_replace( '#/chat/completions$#i', '', $base );
		return untrailingslashit( (string) $base );
	}

	private static function normalize_cerebras_model( $name ) {
		$map = array(
			'llama3.1-8b'   => 'gpt-oss-120b',
			'llama-3.1-8b'  => 'gpt-oss-120b',
			'llama3-8b'     => 'gpt-oss-120b',
			'llama-3.3-70b' => 'gpt-oss-120b',
			'llama3.3-70b'  => 'gpt-oss-120b',
			'qwen-3-32b'    => 'gpt-oss-120b',
			'gpt-oss-120b'  => 'gpt-oss-120b',
			'gemma-4-31b'   => 'gemma-4-31b',
			'zai-glm-4.7'   => 'zai-glm-4.7',
		);
		$key = strtolower( trim( (string) $name ) );
		return isset( $map[ $key ] ) ? $map[ $key ] : trim( (string) $name );
	}

	private static function default_base( $provider ) {
		switch ( sanitize_key( $provider ) ) {
			case 'gapgpt':
				return Agent_WP_Models::get_gapgpt_base();
			case 'groq':
				return 'https://api.groq.com/openai/v1';
			case 'openrouter':
				return 'https://openrouter.ai/api/v1';
			case 'gemini':
				return 'https://generativelanguage.googleapis.com/v1beta/openai';
			case 'cerebras':
				return 'https://api.cerebras.ai/v1';
			case 'mistral':
				return 'https://api.mistral.ai/v1';
			case 'deepseek':
				return 'https://api.deepseek.com/v1';
			case 'anthropic':
				return 'https://api.anthropic.com/v1';
			case 'openai':
			default:
				return 'https://api.openai.com/v1';
		}
	}
}
