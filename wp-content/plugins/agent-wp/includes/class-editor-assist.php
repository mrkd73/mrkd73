<?php
/**
 * دکمه‌های ایجنت داخل صفحات ویرایش وردپرس
 * (تصویر شاخص، برچسب، خلاصه، بلوک متن، پاسخ دیدگاه) — پاپ‌آپ پرامپت → اجرا.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Editor_Assist {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block' ) );
		add_filter( 'admin_post_thumbnail_html', array( __CLASS__, 'featured_image_button' ), 20, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_filter( 'comment_row_actions', array( __CLASS__, 'comment_row_action' ), 20, 2 );
		add_action( 'admin_footer-edit-comments.php', array( __CLASS__, 'comments_list_script' ) );
		add_action( 'admin_footer-comment.php', array( __CLASS__, 'comment_edit_button' ) );
		add_action( 'wp_ajax_agent_wp_editor_assist', array( __CLASS__, 'ajax' ) );
	}

	private static function allowed_screen() {
		if ( ! current_user_can( 'manage_options' ) || ! Agent_WP_Assistant::is_enabled() ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->base ) ) {
			return false;
		}
		return in_array( $screen->base, array( 'post', 'comment', 'edit-comments' ), true );
	}

	private static function is_block_editor_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ! empty( $screen->is_block_editor );
	}

	/**
	 * @param \WP_Post $post
	 * @return array{tags:bool,excerpt:bool,featured:bool,content:bool,taxonomy:string}
	 */
	private static function supports_for_post( $post ) {
		$type = $post instanceof WP_Post ? $post->post_type : 'post';
		$tax  = '';
		if ( is_object_in_taxonomy( $type, 'post_tag' ) ) {
			$tax = 'post_tag';
		}
		return array(
			'tags'     => (bool) $tax,
			'excerpt'  => post_type_supports( $type, 'excerpt' ),
			'featured' => post_type_supports( $type, 'thumbnail' ),
			'content'  => post_type_supports( $type, 'editor' ),
			'taxonomy' => $tax,
		);
	}

	public static function enqueue( $hook ) {
		if ( ! self::allowed_screen() ) {
			return;
		}
		self::enqueue_assets( false );
	}

	public static function enqueue_block() {
		if ( ! current_user_can( 'manage_options' ) || ! Agent_WP_Assistant::is_enabled() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// فقط ویرایش نوشته/برگه — نه ویجت یا Site Editor
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		self::enqueue_assets( true );
		wp_enqueue_script(
			'agent-wp-editor-assist-block',
			AGENT_WP_URL . 'assets/js/editor-assist-block.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-block-editor', 'agent-wp-editor-assist' ),
			AGENT_WP_VERSION,
			true
		);
	}

	private static function enqueue_assets( $for_block = false ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_id = 0;
		if ( $screen && 'post' === $screen->base ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $post_id && function_exists( 'get_the_ID' ) ) {
			$post_id = absint( get_the_ID() );
		}

		wp_enqueue_style(
			'agent-wp-editor-assist',
			AGENT_WP_URL . 'assets/css/editor-assist.css',
			array(),
			AGENT_WP_VERSION
		);
		wp_enqueue_script(
			'agent-wp-editor-assist',
			AGENT_WP_URL . 'assets/js/editor-assist.js',
			array(),
			AGENT_WP_VERSION,
			true
		);

		$post     = $post_id ? get_post( $post_id ) : null;
		$supports = $post ? self::supports_for_post( $post ) : array(
			'tags'     => true,
			'excerpt'  => true,
			'featured' => true,
			'content'  => true,
			'taxonomy' => 'post_tag',
		);
		$title    = $post_id ? get_the_title( $post_id ) : '';
		$excerpt  = $post_id ? (string) get_post_field( 'post_excerpt', $post_id ) : '';
		$content  = $post_id ? wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) : '';
		$content  = mb_substr( $content, 0, 400 );
		$comment_id = 0;
		if ( $screen && 'comment' === $screen->base && isset( $_GET['c'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$comment_id = absint( $_GET['c'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$auto = isset( $_GET['agent_wp_assist'] ) ? sanitize_key( wp_unslash( $_GET['agent_wp_assist'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$auto_prompt = isset( $_GET['agent_wp_prompt'] ) ? sanitize_text_field( wp_unslash( $_GET['agent_wp_prompt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'agent-wp-editor-assist',
			'agentWpEditorAssist',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'agent_wp_admin' ),
				'postId'    => $post_id,
				'commentId' => $comment_id,
				'screen'    => $screen ? (string) $screen->id : '',
				'title'     => $title,
				'excerpt'   => $excerpt,
				'content'   => $content,
				'hasThumb'  => $post_id ? (bool) get_post_thumbnail_id( $post_id ) : false,
				'forBlock'  => (bool) $for_block,
				'isBlock'   => self::is_block_editor_screen(),
				'supports'  => $supports,
				'autoOpen'  => $auto,
				'autoPrompt'=> $auto_prompt,
				'i18n'      => array(
					'featuredBtn'   => __( 'ایجنت: ساخت تصویر شاخص', 'agent-wp' ),
					'tagsBtn'       => __( 'ایجنت: پیشنهاد برچسب', 'agent-wp' ),
					'excerptBtn'    => __( 'ایجنت: نوشتن خلاصه', 'agent-wp' ),
					'contentBtn'    => __( 'ایجنت: بلوک متن', 'agent-wp' ),
					'replyBtn'      => __( 'ایجنت: پیش‌نویس پاسخ', 'agent-wp' ),
					'panelTitle'    => __( 'ایجنت', 'agent-wp' ),
					'featuredShort' => __( 'ساخت تصویر شاخص', 'agent-wp' ),
					'tagsShort'     => __( 'پیشنهاد برچسب‌ها', 'agent-wp' ),
					'excerptShort'  => __( 'نوشتن خلاصه', 'agent-wp' ),
					'contentShort'  => __( 'افزودن بلوک متن', 'agent-wp' ),
					'modalTitle'    => __( 'ایجنت — انجام کار', 'agent-wp' ),
					'promptLabel'   => __( 'پرامپت', 'agent-wp' ),
					'promptPh'      => __( 'توضیح بده چه می‌خواهی…', 'agent-wp' ),
					'run'           => __( 'بساز و اعمال کن', 'agent-wp' ),
					'cancel'        => __( 'انصراف', 'agent-wp' ),
					'close'         => __( 'بستن', 'agent-wp' ),
					'loading'       => __( 'در حال ساخت…', 'agent-wp' ),
					'ok'            => __( 'انجام شد.', 'agent-wp' ),
					'fail'          => __( 'ناموفق بود.', 'agent-wp' ),
					'needPrompt'    => __( 'پرامپت را بنویس.', 'agent-wp' ),
					'confirmNext'   => __( 'بله، با مدل دیگر', 'agent-wp' ),
					'reloadAsk'     => __( 'برای دیدن بلوک متن، صفحه را رفرش کنم؟ تغییرات ذخیره‌نشده ممکن است از بین برود.', 'agent-wp' ),
					'boxTitle'      => __( 'ایجنت روی این صفحه', 'agent-wp' ),
					'boxHint'       => __( 'کارهایی که معمولاً دستی می‌کنی را اینجا بسپار.', 'agent-wp' ),
					'draftBox'      => __( 'پیش‌نویس پاسخ ایجنت', 'agent-wp' ),
					'rowReply'      => __( 'پاسخ با ایجنت', 'agent-wp' ),
				),
			)
		);
	}

	/**
	 * @param string $content
	 * @param int    $post_id
	 */
	public static function featured_image_button( $content, $post_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! Agent_WP_Assistant::is_enabled() ) {
			return $content;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $content;
		}
		// در گوتنبرگ پنل سند کافی است — از تکرار دکمه کلاسیک جلوگیری کن
		if ( self::is_block_editor_screen() ) {
			return $content;
		}
		$btn = '<p class="wpa-ed-featured">'
			. '<button type="button" class="button wpa-ed-btn" data-wpa-ed-action="featured" data-post-id="' . esc_attr( (string) $post_id ) . '">'
			. esc_html__( 'ایجنت: ساخت تصویر شاخص', 'agent-wp' )
			. '</button></p>';
		return $content . $btn;
	}

	public static function register_meta_boxes() {
		if ( ! Agent_WP_Assistant::is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// در بلاک ادیتور پنل سند جایگزین متاباکس است
		if ( self::is_block_editor_screen() ) {
			return;
		}
		$types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $types as $type ) {
			add_meta_box(
				'agent_wp_editor_assist',
				__( 'ایجنت روی این صفحه', 'agent-wp' ),
				array( __CLASS__, 'render_meta_box' ),
				$type,
				'side',
				'high'
			);
		}
	}

	public static function render_meta_box( $post ) {
		$s = self::supports_for_post( $post );
		echo '<div class="wpa-ed-box">';
		echo '<p class="wpa-ed-box__hint">' . esc_html__( 'کارهایی که معمولاً دستی می‌کنی را اینجا بسپار.', 'agent-wp' ) . '</p>';
		if ( $s['featured'] ) {
			echo '<p><button type="button" class="button button-primary wpa-ed-btn" data-wpa-ed-action="featured" data-post-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html__( 'ساخت تصویر شاخص', 'agent-wp' ) . '</button></p>';
		}
		if ( $s['tags'] ) {
			echo '<p><button type="button" class="button wpa-ed-btn" data-wpa-ed-action="tags" data-post-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html__( 'پیشنهاد برچسب‌ها', 'agent-wp' ) . '</button></p>';
		}
		if ( $s['excerpt'] ) {
			echo '<p><button type="button" class="button wpa-ed-btn" data-wpa-ed-action="excerpt" data-post-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html__( 'نوشتن خلاصه', 'agent-wp' ) . '</button></p>';
		}
		if ( $s['content'] ) {
			echo '<p><button type="button" class="button wpa-ed-btn" data-wpa-ed-action="content_block" data-post-id="' . esc_attr( (string) $post->ID ) . '">' . esc_html__( 'افزودن بلوک متن', 'agent-wp' ) . '</button></p>';
		}
		echo '</div>';
	}

	/**
	 * @param array      $actions
	 * @param \WP_Comment $comment
	 */
	public static function comment_row_action( $actions, $comment ) {
		if ( ! current_user_can( 'manage_options' ) || ! Agent_WP_Assistant::is_enabled() ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return $actions;
		}
		$actions['agent_wp_reply'] = sprintf(
			'<a href="#" class="wpa-ed-btn" data-wpa-ed-action="comment_reply" data-comment-id="%d">%s</a>',
			(int) $comment->comment_ID,
			esc_html__( 'پاسخ با ایجنت', 'agent-wp' )
		);
		return $actions;
	}

	public static function comments_list_script() {
		if ( ! self::allowed_screen() ) {
			return;
		}
		// فقط اطمینان از لود اسکریپت؛ دکمه از row action می‌آید
	}

	public static function comment_edit_button() {
		if ( ! self::allowed_screen() ) {
			return;
		}
		$cid = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var r = document.getElementById('namediv') || document.getElementById('submitdiv');
			if (!r || document.querySelector('[data-wpa-ed-action="comment_reply"]')) return;
			var p = document.createElement('p');
			p.className = 'wpa-ed-comment-btn';
			p.style.margin = '12px 0';
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button button-primary wpa-ed-btn';
			b.setAttribute('data-wpa-ed-action', 'comment_reply');
			b.setAttribute('data-comment-id', <?php echo (int) $cid; ?>);
			b.textContent = <?php echo wp_json_encode( __( 'ایجنت: پیش‌نویس پاسخ', 'agent-wp' ) ); ?>;
			p.appendChild(b);
			r.parentNode.insertBefore(p, r);
		});
		</script>
		<?php
	}

	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی کافی نیست.', 'agent-wp' ) ), 403 );
		}
		check_ajax_referer( 'agent_wp_admin', 'nonce' );
		if ( ! Agent_WP_Assistant::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'دستیار خاموش است.', 'agent-wp' ) ), 400 );
		}

		$action_key = isset( $_POST['assist'] ) ? sanitize_key( wp_unslash( $_POST['assist'] ) ) : '';
		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$prompt     = isset( $_POST['prompt'] ) ? trim( (string) wp_unslash( $_POST['prompt'] ) ) : '';
		$preferred  = isset( $_POST['preferred_image_model'] ) ? sanitize_text_field( wp_unslash( $_POST['preferred_image_model'] ) ) : '';

		if ( $preferred ) {
			Agent_WP_Agent::set_request_context( array( 'preferred_image_model' => $preferred ) );
		}

		switch ( $action_key ) {
			case 'featured':
				self::ajax_featured( $post_id, $prompt, $preferred );
				break;
			case 'set_featured':
				self::ajax_set_featured( $post_id, isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0 );
				break;
			case 'tags':
				self::ajax_tags( $post_id, $prompt );
				break;
			case 'excerpt':
				self::ajax_excerpt( $post_id, $prompt );
				break;
			case 'content_block':
				self::ajax_content_block( $post_id, $prompt );
				break;
			case 'comment_reply':
				self::ajax_comment_reply( isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0, $prompt );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'عملیات نامعتبر.', 'agent-wp' ) ), 400 );
		}
	}

	private static function require_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'نوشته معتبر نیست.', 'agent-wp' ) ), 400 );
		}
		return get_post( $post_id );
	}

	private static function ajax_featured( $post_id, $prompt, $preferred = '' ) {
		$post = self::require_post( $post_id );
		if ( '' === $prompt ) {
			$prompt = sprintf(
				/* translators: %s title */
				__( 'تصویر شاخص حرفه‌ای و جذاب برای مطلب: %s', 'agent-wp' ),
				get_the_title( $post )
			);
		}

		$args = array(
			'prompt' => $prompt,
			'size'   => '1024x1024',
		);
		if ( $preferred ) {
			$args['model'] = $preferred;
		}

		$tool   = new Agent_WP_Tool_Generate_Image();
		$result = $tool->run( $args );
		$arr    = $result->to_array();

		if ( ! empty( $arr['data']['needsConfirm'] ) ) {
			wp_send_json_success(
				array(
					'needsConfirm' => true,
					'confirmToken' => $arr['data']['confirmToken'],
					'confirmLabel' => isset( $arr['data']['confirmLabel'] ) ? $arr['data']['confirmLabel'] : __( 'بله، با مدل دیگر', 'agent-wp' ),
					'warning'      => isset( $arr['data']['warning'] ) ? $arr['data']['warning'] : $result->message,
					'postId'       => $post_id,
					'assist'       => 'featured',
				)
			);
		}

		if ( ! $result->ok ) {
			wp_send_json_error( array( 'message' => $result->message, 'data' => $arr['data'] ), 400 );
		}

		$media_id = isset( $arr['data']['attachmentId'] ) ? absint( $arr['data']['attachmentId'] ) : 0;
		if ( ! $media_id ) {
			wp_send_json_error( array( 'message' => __( 'شناسه رسانه برنگشت.', 'agent-wp' ) ), 500 );
		}

		set_post_thumbnail( $post_id, $media_id );
		$url = wp_get_attachment_image_url( $media_id, 'medium' );
		wp_send_json_success(
			array(
				'message'      => __( 'تصویر شاخص ساخته و تنظیم شد.', 'agent-wp' ),
				'attachmentId' => $media_id,
				'url'          => $url ? $url : ( isset( $arr['data']['url'] ) ? $arr['data']['url'] : '' ),
				'thumbHtml'    => _wp_post_thumbnail_html( $media_id, $post_id ),
				'postId'       => $post_id,
			)
		);
	}

	private static function ajax_set_featured( $post_id, $media_id ) {
		self::require_post( $post_id );
		if ( ! $media_id || 'attachment' !== get_post_type( $media_id ) ) {
			wp_send_json_error( array( 'message' => __( 'رسانه نامعتبر.', 'agent-wp' ) ), 400 );
		}
		set_post_thumbnail( $post_id, $media_id );
		$url = wp_get_attachment_image_url( $media_id, 'medium' );
		wp_send_json_success(
			array(
				'message'      => __( 'تصویر شاخص تنظیم شد.', 'agent-wp' ),
				'attachmentId' => $media_id,
				'url'          => $url ? $url : '',
				'thumbHtml'    => _wp_post_thumbnail_html( $media_id, $post_id ),
				'postId'       => $post_id,
			)
		);
	}

	private static function llm_text( $system, $user ) {
		$model_id = Agent_WP_Models::get_default_id();
		if ( ! $model_id ) {
			return new WP_Error( 'no_model', __( 'مدل پیش‌فرض تنظیم نشده.', 'agent-wp' ) );
		}
		$res = Agent_WP_Llm::chat(
			$model_id,
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			array( 'temperature' => 0.4 )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = isset( $res['content'] ) ? trim( (string) $res['content'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'empty', __( 'پاسخ خالی از مدل.', 'agent-wp' ) );
		}
		return $text;
	}

	private static function ajax_tags( $post_id, $prompt ) {
		$post = self::require_post( $post_id );
		$s    = self::supports_for_post( $post );
		if ( ! $s['tags'] || ! $s['taxonomy'] ) {
			wp_send_json_error( array( 'message' => __( 'این نوع نوشته برچسب پشتیبانی نمی‌کند.', 'agent-wp' ) ), 400 );
		}
		$tax = $s['taxonomy'];

		$title   = get_the_title( $post );
		$snippet = mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 500 );
		$user    = "عنوان: {$title}\nمتن: {$snippet}\n";
		if ( $prompt ) {
			$user .= "راهنمای کاربر: {$prompt}\n";
		}
		$user .= '۵ تا ۸ برچسب کوتاه فارسی، فقط با ویرگول جدا کن. بدون توضیح.';

		$text = self::llm_text( 'تو ویراستار سئوی وردپرس هستی. فقط لیست برچسب برگردان.', $user );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ), 400 );
		}

		$parts = preg_split( '/[,،\n]+/u', (string) $text );
		$tags  = array();
		foreach ( (array) $parts as $p ) {
			$p = sanitize_text_field( trim( $p ) );
			$p = preg_replace( '/^[\d\.\-\*\•]+\s*/u', '', $p );
			if ( $p && mb_strlen( $p ) < 40 ) {
				$tags[] = $p;
			}
		}
		$tags = array_values( array_unique( array_slice( $tags, 0, 8 ) ) );
		if ( ! $tags ) {
			wp_send_json_error( array( 'message' => __( 'برچسبی استخراج نشد.', 'agent-wp' ) ), 400 );
		}

		$r = wp_set_post_terms( $post_id, $tags, $tax, true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		}

		$term_ids = array();
		foreach ( $tags as $name ) {
			$term = get_term_by( 'name', $name, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s tags */
					__( 'برچسب‌ها اعمال شد: %s', 'agent-wp' ),
					implode( '، ', $tags )
				),
				'tags'     => $tags,
				'termIds'  => $term_ids,
				'taxonomy' => $tax,
				'postId'   => $post_id,
			)
		);
	}

	private static function ajax_excerpt( $post_id, $prompt ) {
		$post = self::require_post( $post_id );
		if ( ! post_type_supports( $post->post_type, 'excerpt' ) ) {
			wp_send_json_error( array( 'message' => __( 'این نوع نوشته خلاصه ندارد.', 'agent-wp' ) ), 400 );
		}
		$title   = get_the_title( $post );
		$snippet = mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 800 );
		$user    = "عنوان: {$title}\nمتن: {$snippet}\n";
		if ( $prompt ) {
			$user .= "راهنما: {$prompt}\n";
		}
		$user .= 'یک خلاصه فارسی ۲ تا ۳ جمله‌ای بنویس. فقط خود خلاصه.';

		$text = self::llm_text( 'تو نویسنده محتوا هستی. فقط خلاصه را برگردان.', $user );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ), 400 );
		}
		$excerpt = sanitize_textarea_field( (string) $text );
		$upd     = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $excerpt,
			),
			true
		);
		if ( is_wp_error( $upd ) ) {
			wp_send_json_error( array( 'message' => $upd->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'خلاصه ذخیره شد.', 'agent-wp' ),
				'excerpt' => $excerpt,
				'postId'  => $post_id,
			)
		);
	}

	private static function ajax_content_block( $post_id, $prompt ) {
		$post = self::require_post( $post_id );
		$title = get_the_title( $post );
		$existing = mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 400 );
		$user = "عنوان: {$title}\nموجود: {$existing}\n";
		if ( $prompt ) {
			$user .= "درخواست: {$prompt}\n";
		} else {
			$user .= "یک پاراگراف مقدمهٔ مفید بنویس.\n";
		}
		$user .= 'فقط متن پاراگراف را برگردان، بدون عنوان و بدون HTML.';

		$text = self::llm_text( 'تو نویسنده وب هستی. فقط یک پاراگراف فارسی بنویس.', $user );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ), 400 );
		}

		$clean = trim( wp_strip_all_tags( (string) $text ) );
		$para  = '<p>' . esc_html( $clean ) . '</p>';
		$block = "<!-- wp:paragraph -->\n{$para}\n<!-- /wp:paragraph -->\n\n";

		// فقط وقتی ویرایشگر بلاک باز نیست در DB بنویس؛ فرانت گوتنبرگ خودش بلوک را درج می‌کند
		$client_block = ! empty( $_POST['client_insert'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $client_block ) {
			$new = $block . (string) $post->post_content;
			$upd = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new,
				),
				true
			);
			if ( is_wp_error( $upd ) ) {
				wp_send_json_error( array( 'message' => $upd->get_error_message() ), 400 );
			}
		}

		wp_send_json_success(
			array(
				'message'      => $client_block
					? __( 'بلوک متن آماده است.', 'agent-wp' )
					: __( 'بلوک متن به ابتدای نوشته اضافه شد.', 'agent-wp' ),
				'html'         => $para,
				'plainText'    => $clean,
				'blockContent' => $block,
				'postId'       => $post_id,
				'reload'       => ! $client_block,
				'insertClient' => (bool) $client_block,
			)
		);
	}

	private static function ajax_comment_reply( $comment_id, $prompt ) {
		$comment_id = absint( $comment_id );
		$c          = get_comment( $comment_id );
		if ( ! $c || ! current_user_can( 'edit_comment', $comment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'دیدگاه معتبر نیست.', 'agent-wp' ) ), 400 );
		}
		$user = 'دیدگاه: ' . wp_strip_all_tags( $c->comment_content ) . "\nنویسنده: " . $c->comment_author . "\n";
		if ( $prompt ) {
			$user .= "راهنما: {$prompt}\n";
		}
		$user .= 'یک پاسخ کوتاه، مودبانه و حرفه‌ای به فارسی بنویس. فقط متن پاسخ.';

		$text = self::llm_text( 'تو مدیر سایت هستی و به دیدگاه‌ها پاسخ می‌دهی.', $user );
		if ( is_wp_error( $text ) ) {
			wp_send_json_error( array( 'message' => $text->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'پیش‌نویس پاسخ آماده است.', 'agent-wp' ),
				'reply'     => sanitize_textarea_field( (string) $text ),
				'commentId' => $comment_id,
			)
		);
	}
}
