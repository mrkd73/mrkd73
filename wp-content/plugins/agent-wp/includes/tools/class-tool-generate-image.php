<?php
/**
 * ساخت تصویر با GapGPT (OpenAI-compatible images/generations)
 * و ذخیره در رسانه وردپرس. اگر مدل اول شکست بخورد، با تأیید کاربر مدل بعدی امتحان می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Generate_Image implements Agent_WP_Tool_Interface {

	public function id() {
		return 'generate_image';
	}

	public function label() {
		return __( 'ساخت تصویر', 'agent-wp' );
	}

	public function description() {
		return 'Generate an image from a text prompt via GapGPT POST /v1/images/generations (same for all image models: gapgpt/z-image, imagen-4.0-generate-001, dall-e-3, flux-pro, …). Save to WordPress media and return the URL. Prefer gapgpt/z-image or the user-selected image model. Do not claim you cannot generate images.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt'  => array(
					'type'        => 'string',
					'description' => 'Detailed image generation prompt',
				),
				'model'   => array(
					'type'        => 'string',
					'description' => 'Image model id. Prefer gapgpt/z-image (GapGPT native). Others: dall-e-3, gpt-image-1, flux-pro',
				),
				'size'    => array(
					'type'        => 'string',
					'description' => 'Size like 1024x1024 or 1792x1024',
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
			return Agent_WP_Tool_Result::error( __( 'پرامپت تصویر خالی است.', 'agent-wp' ) );
		}

		$size  = isset( $args['size'] ) ? sanitize_text_field( (string) $args['size'] ) : '1024x1024';
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
			$model = self::default_image_model( $tried );
		}
		if ( '' === $model ) {
			return Agent_WP_Tool_Result::error( __( 'مدل ساخت تصویر در کاتالوگ یافت نشد.', 'agent-wp' ) );
		}

		$result = Agent_WP_Llm::generate_image(
			$model,
			$prompt,
			array( 'size' => $size )
		);

		if ( ! is_wp_error( $result ) ) {
			$saved = Agent_WP_Llm::sideload_generated_image(
				$result,
				'agent-wp-' . sanitize_title( mb_substr( $prompt, 0, 40 ) )
			);
			if ( is_wp_error( $saved ) ) {
				return Agent_WP_Tool_Result::error(
					sprintf(
						/* translators: 1: model, 2: error */
						__( 'تصویر با «%1$s» ساخته شد ولی ذخیره در رسانه ناموفق بود: %2$s', 'agent-wp' ),
						$model,
						$saved->get_error_message()
					),
					array(
						'model' => $model,
						'raw'   => array(
							'url' => isset( $result['url'] ) ? $result['url'] : '',
						),
					)
				);
			}

			$att = array(
				'id'      => (int) $saved['id'],
				'url'     => $saved['url'],
				'name'    => $saved['name'],
				'mime'    => $saved['mime'],
				'isImage' => true,
			);

			return Agent_WP_Tool_Result::success(
				sprintf(
					/* translators: %s: model name */
					__( 'تصویر با مدل «%s» ساخته و در رسانه ذخیره شد.', 'agent-wp' ),
					$model
				),
				array(
					'model'         => $model,
					'prompt'        => $prompt,
					'revisedPrompt' => isset( $result['revisedPrompt'] ) ? $result['revisedPrompt'] : '',
					'attachmentId'  => (int) $saved['id'],
					'url'           => $saved['url'],
					'editUrl'       => get_edit_post_link( (int) $saved['id'], 'raw' ),
					'viewUrl'       => $saved['url'],
					'attachments'   => array( $att ),
				)
			);
		}

		$err_msg = $result->get_error_message();
		if ( class_exists( 'Agent_WP_Gapgpt' ) && preg_match( '/no available channel/i', $err_msg ) ) {
			Agent_WP_Gapgpt::remember_unavailable_model( $model );
		}
		$tried[] = $model;
		$next    = self::next_image_model( $tried );

		// هرگز بدون اجازه کاربر سراغ مدل بعدی نرو
		if ( $next ) {
			$warning = sprintf(
				/* translators: 1: failed model, 2: error, 3: next model */
				__( 'مدل «%1$s» نتوانست تصویر بسازد (%2$s). با مدل «%3$s» دوباره امتحان کنم؟', 'agent-wp' ),
				$model,
				$err_msg,
				$next
			);
			$token = Agent_WP_Pending::store(
				'generate_image',
				array(
					'prompt' => $prompt,
					'size'   => $size,
					'model'  => $next,
					'tried'  => $tried,
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
			sprintf(
				/* translators: 1: model, 2: error */
				__( 'ساخت تصویر با «%1$s» ناموفق بود و مدل جایگزین دیگری نیست: %2$s', 'agent-wp' ),
				$model,
				$err_msg
			),
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
			return Agent_WP_Tool_Result::success( __( 'تصویر تولیدشده از رسانه حذف شد.', 'agent-wp' ) );
		}
		return Agent_WP_Tool_Result::success( __( 'rollback لازم نبود.', 'agent-wp' ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function image_model_ids() {
		$ids = array();
		// کاتالوگ استاتیک + مدل‌های زنده‌ای که از /v1/models بیایند
		foreach ( Agent_WP_Models::gapgpt_catalog_merged() as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$cat = isset( $item['category'] ) ? $item['category'] : Agent_WP_Models::guess_category( $item['id'] );
			if ( 'image' === $cat ) {
				$ids[] = (string) $item['id'];
			}
		}
		$prefer  = array(
			'gapgpt/z-image',
			'imagen-4.0-generate-001',
			'imagen-3.0-generate-002',
			'dall-e-3',
			'gpt-image-1',
			'flux-pro',
			'flux-1.1-pro',
			'flux-schnell',
			'dall-e-2',
		);
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

	public static function default_image_model( array $exclude = array() ) {
		$blocked = class_exists( 'Agent_WP_Gapgpt' ) ? Agent_WP_Gapgpt::unavailable_models() : array();
		$exclude = array_merge( $exclude, $blocked );
		foreach ( self::image_model_ids() as $id ) {
			if ( ! in_array( $id, $exclude, true ) ) {
				return $id;
			}
		}
		return '';
	}

	public static function next_image_model( array $tried ) {
		return self::default_image_model( $tried );
	}
}
