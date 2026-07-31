<?php
/**
 * ساخت ویدیو با GapGPT (مسیرهای سازگار /videos/generations و مشابه)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Generate_Video implements Agent_WP_Tool_Interface {

	public function id() {
		return 'generate_video';
	}

	public function label() {
		return __( 'ساخت ویدیو', 'agent-wp' );
	}

	public function description() {
		return 'Generate a short video from a text prompt via GapGPT video endpoints (sora, kling, runway, luma, …). Save to WordPress media and return the URL. Use when the user asks to create/generate a video clip. Prefer the user-selected video model.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'  => array(
					'type'        => 'string',
					'description' => 'Detailed video generation prompt',
				),
				'model'   => array(
					'type'        => 'string',
					'description' => 'Video model id, e.g. sora, kling-video, runway-gen3, luma-ray2',
				),
				'seconds' => array(
					'type'        => 'integer',
					'description' => 'Duration in seconds (2-20)',
				),
				'size'    => array(
					'type'        => 'string',
					'description' => 'Size like 1280x720',
				),
			),
			'required'   => array( 'prompt' ),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'upload_files' ) && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$prompt = isset( $args['prompt'] ) ? trim( (string) $args['prompt'] ) : '';
		if ( '' === $prompt ) {
			return Agent_WP_Tool_Result::error( __( 'پرامپت ویدیو خالی است.', 'agent-wp' ) );
		}

		$seconds = isset( $args['seconds'] ) ? absint( $args['seconds'] ) : 5;
		$size    = isset( $args['size'] ) ? sanitize_text_field( (string) $args['size'] ) : '1280x720';
		$tried   = array();
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
			$model = self::default_video_model( $tried );
		}
		if ( '' === $model ) {
			return Agent_WP_Tool_Result::error( __( 'مدل ساخت ویدیو در کاتالوگ یافت نشد.', 'agent-wp' ) );
		}

		$result = Agent_WP_Llm::generate_video(
			$model,
			$prompt,
			array(
				'seconds' => $seconds,
				'size'    => $size,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$saved = Agent_WP_Llm::sideload_generated_file(
				$result,
				'agent-wp-video-' . sanitize_title( mb_substr( $prompt, 0, 30 ) ),
				'mp4',
				'video/mp4'
			);
			if ( is_wp_error( $saved ) ) {
				return Agent_WP_Tool_Result::error(
					sprintf(
						__( 'ویدیو با «%1$s» ساخته شد ولی ذخیره ناموفق بود: %2$s', 'agent-wp' ),
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
				'isVideo' => true,
			);

			return Agent_WP_Tool_Result::success(
				sprintf( __( 'ویدیو با مدل «%s» ساخته و در رسانه ذخیره شد.', 'agent-wp' ), $model ),
				array(
					'model'        => $model,
					'prompt'       => $prompt,
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
		$next    = self::next_video_model( $tried );
		if ( $next ) {
			$warning = sprintf(
				__( 'مدل «%1$s» نتوانست ویدیو بسازد (%2$s). با مدل «%3$s» دوباره امتحان کنم؟', 'agent-wp' ),
				$model,
				$err_msg,
				$next
			);
			$token = Agent_WP_Pending::store(
				'generate_video',
				array(
					'prompt'  => $prompt,
					'seconds' => $seconds,
					'size'    => $size,
					'model'   => $next,
					'tried'   => $tried,
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
			sprintf( __( 'ساخت ویدیو با «%1$s» ناموفق بود: %2$s', 'agent-wp' ), $model, $err_msg ),
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
			return Agent_WP_Tool_Result::success( __( 'ویدیو تولیدشده از رسانه حذف شد.', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function video_model_ids() {
		$ids = array();
		foreach ( Agent_WP_Models::gapgpt_catalog_merged() as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$cat = isset( $item['category'] ) ? $item['category'] : Agent_WP_Models::guess_category( $item['id'] );
			if ( 'video' === $cat ) {
				$ids[] = (string) $item['id'];
			}
		}
		$prefer  = array( 'sora', 'kling-video', 'runway-gen3', 'luma-ray2' );
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

	public static function default_video_model( array $exclude = array() ) {
		$blocked = class_exists( 'Agent_WP_Gapgpt' ) ? Agent_WP_Gapgpt::unavailable_models() : array();
		$exclude = array_merge( $exclude, $blocked );
		foreach ( self::video_model_ids() as $id ) {
			if ( ! in_array( $id, $exclude, true ) ) {
				return $id;
			}
		}
		return '';
	}

	public static function next_video_model( array $tried ) {
		return self::default_video_model( $tried );
	}
}
