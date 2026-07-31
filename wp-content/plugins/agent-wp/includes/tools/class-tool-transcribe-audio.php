<?php
/**
 * رونویسی صوت (STT) با GapGPT — POST /v1/audio/transcriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Transcribe_Audio implements Agent_WP_Tool_Interface {

	public function id() {
		return 'transcribe_audio';
	}

	public function label() {
		return __( 'رونویسی صوت', 'agent-wp' );
	}

	public function description() {
		return 'Transcribe an audio file (attachmentId or URL) to text via GapGPT Whisper-compatible POST /v1/audio/transcriptions. Use when the user uploads audio or asks to convert speech to text.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachmentId' => array(
					'type'        => 'integer',
					'description' => 'WordPress media attachment ID of the audio file',
				),
				'url'          => array(
					'type'        => 'string',
					'description' => 'Public URL of an audio file if attachmentId is unknown',
				),
				'model'        => array(
					'type'        => 'string',
					'description' => 'STT model id, e.g. whisper-1',
				),
				'language'     => array(
					'type'        => 'string',
					'description' => 'Language code, e.g. fa or en',
				),
			),
			'required'   => array(),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'upload_files' ) && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$attachment_id = isset( $args['attachmentId'] ) ? absint( $args['attachmentId'] ) : 0;
		$url           = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '';
		$language      = isset( $args['language'] ) ? sanitize_text_field( (string) $args['language'] ) : 'fa';
		$model         = isset( $args['model'] ) ? sanitize_text_field( (string) $args['model'] ) : '';
		if ( '' === $model ) {
			$model = 'whisper-1';
		}

		$tmp      = '';
		$filename = 'audio.mp3';
		$cleanup  = false;

		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			if ( ! $path || ! file_exists( $path ) ) {
				return Agent_WP_Tool_Result::error( __( 'فایل پیوست صوت پیدا نشد.', 'agent-wp' ) );
			}
			$tmp      = $path;
			$filename = basename( $path );
		} elseif ( $url ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = download_url( $url, 90 );
			if ( is_wp_error( $tmp ) ) {
				return Agent_WP_Tool_Result::error( $tmp->get_error_message() );
			}
			$cleanup  = true;
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( ! $filename ) {
				$filename = 'audio.mp3';
			}
		} else {
			return Agent_WP_Tool_Result::error( __( 'attachmentId یا url صوت لازم است.', 'agent-wp' ) );
		}

		$result = Agent_WP_Llm::transcribe_audio(
			$model,
			$tmp,
			array(
				'filename' => $filename,
				'language' => $language,
			)
		);
		if ( $cleanup && $tmp ) {
			@unlink( $tmp );
		}

		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			if ( class_exists( 'Agent_WP_Gapgpt' ) && preg_match( '/no available channel/i', $msg ) ) {
				Agent_WP_Gapgpt::remember_unavailable_model( $model );
			}
			return Agent_WP_Tool_Result::error( $msg, array( 'model' => $model ) );
		}

		return Agent_WP_Tool_Result::success(
			sprintf( __( 'رونویسی با مدل «%s» انجام شد.', 'agent-wp' ), $model ),
			array(
				'model'        => $model,
				'text'         => $result['text'],
				'transcript'   => $result['text'],
				'attachmentId' => $attachment_id,
				'preview'      => mb_substr( $result['text'], 0, 500 ),
			)
		);
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'rollback برای رونویسی لازم نیست.', 'agent-wp' ) );
	}
}
