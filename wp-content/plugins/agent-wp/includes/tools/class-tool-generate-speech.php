<?php
/**
 * متن به گفتار (TTS) با GapGPT — POST /v1/audio/speech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Generate_Speech implements Agent_WP_Tool_Interface {

	public function id() {
		return 'generate_speech';
	}

	public function label() {
		return __( 'ساخت گفتار', 'agent-wp' );
	}

	public function description() {
		return 'Convert text to speech via GapGPT POST /v1/audio/speech (tts-1, tts-1-hd, gpt-4o-mini-tts, …). Save MP3 to WordPress media and return the URL. Use when the user asks for voice-over, audio narration, or TTS.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'  => array(
					'type'        => 'string',
					'description' => 'Text to speak (Persian or English)',
				),
				'model' => array(
					'type'        => 'string',
					'description' => 'TTS model id, e.g. tts-1, tts-1-hd, gpt-4o-mini-tts',
				),
				'voice' => array(
					'type'        => 'string',
					'description' => 'Voice preset, e.g. alloy, echo, fable, onyx, nova, shimmer',
				),
			),
			'required'   => array( 'text' ),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'upload_files' ) && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$text = isset( $args['text'] ) ? trim( (string) $args['text'] ) : '';
		if ( '' === $text && ! empty( $args['prompt'] ) ) {
			$text = trim( (string) $args['prompt'] );
		}
		if ( '' === $text ) {
			return Agent_WP_Tool_Result::error( __( 'متن گفتار خالی است.', 'agent-wp' ) );
		}

		$voice = isset( $args['voice'] ) ? sanitize_text_field( (string) $args['voice'] ) : 'alloy';
		$tried = array();
		if ( ! empty( $args['tried'] ) && is_array( $args['tried'] ) ) {
			foreach ( $args['tried'] as $t ) {
				$t = sanitize_text_field( (string) $t );
				if ( $t ) {
					$tried[] = $t;
				}
			}
		}

		$model = isset( $args['model'] ) ? sanitize_text_field( (string) $args['model'] ) : '';
		if ( '' === $model ) {
			$model = self::default_speech_model( $tried );
		}
		if ( '' === $model ) {
			return Agent_WP_Tool_Result::error( __( 'مدل گفتار در کاتالوگ یافت نشد.', 'agent-wp' ) );
		}

		$result = Agent_WP_Llm::generate_speech(
			$model,
			$text,
			array(
				'voice'  => $voice,
				'format' => 'mp3',
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$saved = Agent_WP_Llm::sideload_generated_file(
				$result,
				'agent-wp-speech-' . sanitize_title( mb_substr( $text, 0, 30 ) ),
				isset( $result['ext'] ) ? $result['ext'] : 'mp3',
				isset( $result['mime'] ) ? $result['mime'] : 'audio/mpeg'
			);
			if ( is_wp_error( $saved ) ) {
				return Agent_WP_Tool_Result::error(
					sprintf(
						__( 'گفتار با «%1$s» ساخته شد ولی ذخیره ناموفق بود: %2$s', 'agent-wp' ),
						$model,
						$saved->get_error_message()
					),
					array( 'model' => $model )
				);
			}

			$att = array(
				'id'      => (int) $saved['id'],
				'url'     => $saved['url'],
				'name'    => $saved['name'],
				'mime'    => $saved['mime'],
				'isImage' => false,
				'isAudio' => true,
			);

			return Agent_WP_Tool_Result::success(
				sprintf( __( 'گفتار با مدل «%s» ساخته و در رسانه ذخیره شد.', 'agent-wp' ), $model ),
				array(
					'model'        => $model,
					'voice'        => $voice,
					'attachmentId' => (int) $saved['id'],
					'url'          => $saved['url'],
					'editUrl'      => get_edit_post_link( (int) $saved['id'], 'raw' ),
					'viewUrl'      => $saved['url'],
					'attachments'  => array( $att ),
				)
			);
		}

		$err_msg = $result->get_error_message();
		if ( class_exists( 'Agent_WP_Gapgpt' ) && preg_match( '/no available channel/i', $err_msg ) ) {
			Agent_WP_Gapgpt::remember_unavailable_model( $model );
		}
		$tried[] = $model;
		$next    = self::next_speech_model( $tried );
		if ( $next ) {
			$warning = sprintf(
				__( 'مدل «%1$s» نتوانست گفتار بسازد (%2$s). با مدل «%3$s» دوباره امتحان کنم؟', 'agent-wp' ),
				$model,
				$err_msg,
				$next
			);
			$token = Agent_WP_Pending::store(
				'generate_speech',
				array(
					'text'  => $text,
					'voice' => $voice,
					'model' => $next,
					'tried' => $tried,
				),
				array(
					'failedModel' => $model,
					'nextModel'   => $next,
					'error'       => $err_msg,
					'warning'     => $warning,
				)
			);
			return Agent_WP_Tool_Result::success(
				__( 'برای ادامه با مدل دیگر تأیید کنید.', 'agent-wp' ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'confirmLabel' => __( 'بله، با مدل دیگر بساز', 'agent-wp' ),
					'warning'      => $warning,
					'highRisk'     => false,
					'failedModel'  => $model,
					'nextModel'    => $next,
					'error'        => $err_msg,
				)
			);
		}

		return Agent_WP_Tool_Result::error(
			sprintf( __( 'ساخت گفتار با «%1$s» ناموفق بود: %2$s', 'agent-wp' ), $model, $err_msg ),
			array(
				'model' => $model,
				'tried' => $tried,
			)
		);
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$after = json_decode( (string) $row['after_json'], true );
		$id    = isset( $after['attachmentId'] ) ? absint( $after['attachmentId'] ) : 0;
		if ( $id && 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
			return Agent_WP_Tool_Result::success( __( 'فایل گفتار از رسانه حذف شد.', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function speech_model_ids() {
		$ids = array();
		foreach ( Agent_WP_Models::gapgpt_catalog_merged() as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$cat = isset( $item['category'] ) ? $item['category'] : Agent_WP_Models::guess_category( $item['id'] );
			if ( 'audio' === $cat ) {
				$ids[] = (string) $item['id'];
			}
		}
		$prefer  = array( 'tts-1-hd', 'tts-1', 'gpt-4o-mini-tts', 'tts-1-1106' );
		$ordered = array();
		foreach ( $prefer as $p ) {
			if ( in_array( $p, $ids, true ) ) {
				$ordered[] = $p;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $ordered, true ) ) {
				$ordered[] = $id;
			}
		}
		return $ordered;
	}

	public static function default_speech_model( array $exclude = array() ) {
		$blocked = class_exists( 'Agent_WP_Gapgpt' ) ? Agent_WP_Gapgpt::unavailable_models() : array();
		$exclude = array_merge( $exclude, $blocked );
		foreach ( self::speech_model_ids() as $id ) {
			if ( ! in_array( $id, $exclude, true ) ) {
				return $id;
			}
		}
		return 'tts-1';
	}

	public static function next_speech_model( array $tried ) {
		return self::default_speech_model( $tried );
	}
}
