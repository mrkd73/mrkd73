<?php
/**
 * گفتگوها و پیام‌ها — persistence سمت سرور.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Chats {

	public static function chats_table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_wp_chats';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_wp_messages';
	}

	public static function ensure_default_for_user( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$list    = self::list_for_user( $user_id );
		if ( ! empty( $list ) ) {
			return $list;
		}

		$id = self::create(
			array(
				'title'   => __( 'دستیار', 'agent-wp' ),
				'user_id' => $user_id,
			)
		);

		if ( ! is_wp_error( $id ) ) {
			self::add_message(
				$id,
				'assistant',
				__( 'سلام! گفتگوها از این به بعد روی سرور ذخیره می‌شوند.', 'agent-wp' ),
				$user_id
			);
		}

		return self::list_for_user( $user_id );
	}

	public static function list_for_user( $user_id = 0, $include_archived = false ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$chats_t = self::chats_table();
		$msg_t   = self::messages_table();
		$flags   = self::get_flags( $user_id );

		$sql = $wpdb->prepare(
			"SELECT c.id, c.title, c.updated_at,
				(SELECT m.content FROM {$msg_t} m WHERE m.chat_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message
			FROM {$chats_t} c
			WHERE c.user_id = %d
			ORDER BY c.updated_at DESC, c.id DESC",
			$user_id
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! empty( $wpdb->last_error ) ) {
			Agent_WP_Install::create_tables();
			$wpdb->last_error = '';
			$rows             = $wpdb->get_results( $sql, ARRAY_A );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$flag = isset( $flags[ $id ] ) && is_array( $flags[ $id ] ) ? $flags[ $id ] : array();
			$archived = ! empty( $flag['archived'] );
			if ( $archived && ! $include_archived ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'title'       => $row['title'],
				'updatedAt'   => $row['updated_at'],
				'lastMessage' => $row['last_message'] ? $row['last_message'] : '',
				'pinned'      => ! empty( $flag['pinned'] ),
				'archived'    => $archived,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['pinned'] !== $b['pinned'] ) {
					return $a['pinned'] ? -1 : 1;
				}
				return strcmp( (string) $b['updatedAt'], (string) $a['updatedAt'] );
			}
		);

		return $out;
	}

	public static function get_flags( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$flags   = get_user_meta( $user_id, 'agent_wp_chat_flags', true );
		return is_array( $flags ) ? $flags : array();
	}

	public static function set_flag( $chat_id, $key, $value, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}
		$key   = sanitize_key( $key );
		$flags = self::get_flags( $user_id );
		$id    = (int) $chat_id;
		if ( ! isset( $flags[ $id ] ) || ! is_array( $flags[ $id ] ) ) {
			$flags[ $id ] = array();
		}
		$flags[ $id ][ $key ] = $value ? 1 : 0;
		update_user_meta( $user_id, 'agent_wp_chat_flags', $flags );
		return self::list_for_user( $user_id, true );
	}

	public static function get_owned( $chat_id, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::chats_table() . ' WHERE id = %d AND user_id = %d',
				$chat_id,
				$user_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $data ) {
		global $wpdb;
		$now     = current_time( 'mysql' );
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : get_current_user_id();
		$title   = sanitize_text_field( $data['title'] ?? __( 'گفتگوی جدید', 'agent-wp' ) );
		if ( '' === $title ) {
			$title = __( 'گفتگوی جدید', 'agent-wp' );
		}

		$ok = $wpdb->insert(
			self::chats_table(),
			array(
				'user_id'    => $user_id,
				'title'      => $title,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'chat_create', __( 'ساخت گفتگو ناموفق بود.', 'agent-wp' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function rename( $chat_id, $title, $user_id = 0 ) {
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}

		$title = sanitize_text_field( $title );
		if ( '' === $title ) {
			return new WP_Error( 'invalid', __( 'عنوان خالی است.', 'agent-wp' ) );
		}

		global $wpdb;
		$wpdb->update(
			self::chats_table(),
			array(
				'title'      => $title,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $chat_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	public static function touch( $chat_id ) {
		global $wpdb;
		$wpdb->update(
			self::chats_table(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $chat_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function list_messages( $chat_id, $user_id = 0 ) {
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, role, content, meta_json, created_at, updated_at FROM ' . self::messages_table() . ' WHERE chat_id = %d ORDER BY id ASC',
				$chat_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$meta = array();
			if ( ! empty( $row['meta_json'] ) ) {
				$decoded = json_decode( (string) $row['meta_json'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			$meta = self::hydrate_pending_tools( $meta );
			$out[] = array(
				'id'        => (int) $row['id'],
				'role'      => $row['role'],
				'content'   => $row['content'],
				'meta'      => $meta,
				'createdAt' => $row['created_at'],
				'updatedAt' => $row['updated_at'],
			);
		}
		return $out;
	}

	/**
	 * اگر توکن تأیید دیگر معتبر نیست، وضعیت کارت را در پاسخ اصلاح کن.
	 */
	public static function hydrate_pending_tools( array $meta ) {
		if ( empty( $meta['tools'] ) || ! is_array( $meta['tools'] ) ) {
			return $meta;
		}
		$changed = false;
		foreach ( $meta['tools'] as $i => $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['data'] ) || ! is_array( $tool['data'] ) ) {
				continue;
			}
			$data = $tool['data'];
			if ( empty( $data['needsConfirm'] ) || empty( $data['confirmToken'] ) ) {
				continue;
			}
			if ( ! empty( $data['confirmStatus'] ) && in_array( $data['confirmStatus'], array( 'cancelled', 'done', 'expired' ), true ) ) {
				continue;
			}
			if ( Agent_WP_Pending::exists( (string) $data['confirmToken'] ) ) {
				continue;
			}
			$meta['tools'][ $i ]['data']['needsConfirm']   = false;
			$meta['tools'][ $i ]['data']['confirmStatus']  = 'expired';
			$meta['tools'][ $i ]['data']['confirmToken']   = '';
			$meta['tools'][ $i ]['ok']                     = false;
			$meta['tools'][ $i ]['message']                = __( 'این درخواست لغو یا منقضی شده است.', 'agent-wp' );
			$changed = true;
		}
		if ( $changed && ! empty( $meta['tool'] ) && is_array( $meta['tool'] ) ) {
			$last = end( $meta['tools'] );
			if ( $last ) {
				$meta['tool'] = $last;
			}
		}
		return $meta;
	}

	/**
	 * به‌روزرسانی وضعیت یک Tool در meta پیام بر اساس confirmToken.
	 *
	 * @param string               $status cancelled|done|expired
	 * @param array<string,mixed>  $extra  دادهٔ اضافه برای data
	 * @return array|WP_Error پیام به‌روزشده
	 */
	public static function resolve_confirm_in_message( $message_id, $token, $status, array $extra = array(), $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$token   = sanitize_text_field( (string) $token );
		$status  = sanitize_key( (string) $status );
		if ( ! in_array( $status, array( 'cancelled', 'done', 'expired' ), true ) ) {
			$status = 'cancelled';
		}

		$message_id = (int) $message_id;
		$row        = null;

		if ( $message_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT m.*, c.user_id FROM ' . self::messages_table() . ' m
					INNER JOIN ' . self::chats_table() . ' c ON c.id = m.chat_id
					WHERE m.id = %d',
					$message_id
				),
				ARRAY_A
			);
			if ( ! $row || (int) $row['user_id'] !== $user_id ) {
				$row = null;
			}
		}

		// اگر message_id نبود، آخرین پیام‌های دستیار کاربر را برای توکن بگرد
		if ( ! $row && $token ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT m.*, c.user_id FROM ' . self::messages_table() . ' m
					INNER JOIN ' . self::chats_table() . ' c ON c.id = m.chat_id
					WHERE c.user_id = %d AND m.role = %s
					ORDER BY m.id DESC LIMIT 40',
					$user_id,
					'assistant'
				),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $candidate ) {
					$meta = array();
					if ( ! empty( $candidate['meta_json'] ) ) {
						$decoded = json_decode( (string) $candidate['meta_json'], true );
						if ( is_array( $decoded ) ) {
							$meta = $decoded;
						}
					}
					if ( empty( $meta['tools'] ) || ! is_array( $meta['tools'] ) ) {
						continue;
					}
					foreach ( $meta['tools'] as $tool ) {
						if ( ! empty( $tool['data']['confirmToken'] ) && (string) $tool['data']['confirmToken'] === $token ) {
							$row = $candidate;
							break 2;
						}
					}
				}
			}
		}

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'پیام مربوط به این تأیید یافت نشد.', 'agent-wp' ) );
		}

		$meta = array();
		if ( ! empty( $row['meta_json'] ) ) {
			$decoded = json_decode( (string) $row['meta_json'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		$found = false;
		if ( ! empty( $meta['tools'] ) && is_array( $meta['tools'] ) ) {
			foreach ( $meta['tools'] as $i => $tool ) {
				$t = isset( $tool['data']['confirmToken'] ) ? (string) $tool['data']['confirmToken'] : '';
				if ( $token && $t !== $token ) {
					continue;
				}
				if ( ! $token && empty( $tool['data']['needsConfirm'] ) ) {
					continue;
				}
				$found = true;
				if ( 'done' === $status ) {
					// اگر تأیید منجر به تأیید بعدی شد (مثلاً مدل جایگزین تصویر)
					if ( ! empty( $extra['data']['needsConfirm'] ) && ! empty( $extra['data']['confirmToken'] ) ) {
						$meta['tools'][ $i ]['ok']      = true;
						$meta['tools'][ $i ]['message'] = isset( $extra['message'] ) ? (string) $extra['message'] : __( 'در انتظار تأیید مدل بعدی.', 'agent-wp' );
						$meta['tools'][ $i ]['data']    = array_merge(
							is_array( $meta['tools'][ $i ]['data'] ) ? $meta['tools'][ $i ]['data'] : array(),
							$extra['data']
						);
						$meta['tools'][ $i ]['data']['needsConfirm'] = true;
					} else {
						$meta['tools'][ $i ]['ok']      = true;
						$meta['tools'][ $i ]['message'] = isset( $extra['message'] ) ? (string) $extra['message'] : __( 'انجام شد.', 'agent-wp' );
						if ( ! empty( $extra['data'] ) && is_array( $extra['data'] ) ) {
							$meta['tools'][ $i ]['data'] = array_merge( $meta['tools'][ $i ]['data'], $extra['data'] );
							// پیوست تصویر تولیدشده را روی پیام هم بگذار
							if ( ! empty( $extra['data']['attachments'] ) && is_array( $extra['data']['attachments'] ) ) {
								$existing = isset( $meta['attachments'] ) && is_array( $meta['attachments'] ) ? $meta['attachments'] : array();
								$meta['attachments'] = array_merge( $existing, $extra['data']['attachments'] );
							} elseif ( ! empty( $extra['data']['url'] ) && ! empty( $extra['data']['attachmentId'] ) ) {
								$existing = isset( $meta['attachments'] ) && is_array( $meta['attachments'] ) ? $meta['attachments'] : array();
								$mime     = 'application/octet-stream';
								if ( ! empty( $extra['data']['attachments'][0]['mime'] ) ) {
									$mime = (string) $extra['data']['attachments'][0]['mime'];
								}
								$existing[] = array(
									'id'      => (int) $extra['data']['attachmentId'],
									'url'     => (string) $extra['data']['url'],
									'name'    => isset( $extra['data']['model'] ) ? (string) $extra['data']['model'] : 'media',
									'mime'    => $mime,
									'isImage' => 0 === strpos( $mime, 'image/' ) || ( ! empty( $extra['data']['attachments'][0]['isImage'] ) ),
									'isVideo' => 0 === strpos( $mime, 'video/' ) || ( ! empty( $extra['data']['attachments'][0]['isVideo'] ) ),
									'isAudio' => 0 === strpos( $mime, 'audio/' ) || ( ! empty( $extra['data']['attachments'][0]['isAudio'] ) ),
								);
								$meta['attachments'] = $existing;
							}
						}
						$meta['tools'][ $i ]['data']['needsConfirm']  = false;
						$meta['tools'][ $i ]['data']['confirmStatus'] = $status;
						$meta['tools'][ $i ]['data']['confirmToken']  = '';
					}
				} else {
					$meta['tools'][ $i ]['data']['needsConfirm']  = false;
					$meta['tools'][ $i ]['data']['confirmStatus'] = $status;
					$meta['tools'][ $i ]['data']['confirmToken']  = '';
					$meta['tools'][ $i ]['ok']      = false;
					$meta['tools'][ $i ]['message'] = 'cancelled' === $status
						? __( 'اکشن توسط شما لغو شد.', 'agent-wp' )
						: __( 'این درخواست منقضی شده است.', 'agent-wp' );
				}
			}
		}

		// همگام‌سازی tool تکی
		if ( ! empty( $meta['tools'] ) ) {
			$meta['tool'] = end( $meta['tools'] );
		}

		if ( ! $found && $token ) {
			// حتی اگر پیدا نشد، خطا نده — توکن لغو شده
			return array(
				'id'   => (int) $row['id'],
				'meta' => $meta,
			);
		}

		$now = current_time( 'mysql' );
		$wpdb->update(
			self::messages_table(),
			array(
				'meta_json'  => wp_json_encode( $meta ),
				'updated_at' => $now,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::touch( (int) $row['chat_id'] );

		return array(
			'id'        => (int) $row['id'],
			'role'      => $row['role'],
			'content'   => $row['content'],
			'meta'      => $meta,
			'createdAt' => $row['created_at'],
			'updatedAt' => $now,
		);
	}

	public static function add_message( $chat_id, $role, $content, $user_id = 0, $meta = null ) {
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}

		$role = ( 'assistant' === $role ) ? 'assistant' : 'user';
		$content = wp_kses_post( $content );
		$content = trim( wp_strip_all_tags( $content ) );
		if ( '' === $content ) {
			return new WP_Error( 'invalid', __( 'متن پیام خالی است.', 'agent-wp' ) );
		}

		$meta_json = '';
		if ( is_array( $meta ) && ! empty( $meta ) ) {
			$meta_json = wp_json_encode( $meta );
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::messages_table(),
			array(
				'chat_id'    => (int) $chat_id,
				'role'       => $role,
				'content'    => $content,
				'meta_json'  => $meta_json,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'msg_create', __( 'ذخیره پیام ناموفق بود.', 'agent-wp' ) );
		}

		self::touch( $chat_id );

		return array(
			'id'        => (int) $wpdb->insert_id,
			'role'      => $role,
			'content'   => $content,
			'meta'      => is_array( $meta ) ? $meta : array(),
			'createdAt' => $now,
			'updatedAt' => $now,
		);
	}

	public static function delete_chat( $chat_id, $user_id = 0 ) {
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}

		global $wpdb;
		$wpdb->delete( self::messages_table(), array( 'chat_id' => (int) $chat_id ), array( '%d' ) );
		$wpdb->delete( self::chats_table(), array( 'id' => (int) $chat_id ), array( '%d' ) );

		$remaining = self::list_for_user( $user_id );
		if ( empty( $remaining ) ) {
			self::ensure_default_for_user( $user_id );
			$remaining = self::list_for_user( $user_id );
		}

		return $remaining;
	}

	public static function clear_messages( $chat_id, $user_id = 0 ) {
		if ( ! self::get_owned( $chat_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'گفتگو یافت نشد.', 'agent-wp' ) );
		}
		global $wpdb;
		$wpdb->delete( self::messages_table(), array( 'chat_id' => (int) $chat_id ), array( '%d' ) );
		self::touch( (int) $chat_id );
		return true;
	}

	public static function delete_message( $message_id, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT m.*, c.user_id FROM ' . self::messages_table() . ' m
				INNER JOIN ' . self::chats_table() . ' c ON c.id = m.chat_id
				WHERE m.id = %d',
				$message_id
			),
			ARRAY_A
		);

		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			return new WP_Error( 'forbidden', __( 'پیام یافت نشد.', 'agent-wp' ) );
		}

		$wpdb->delete( self::messages_table(), array( 'id' => (int) $message_id ), array( '%d' ) );
		self::touch( (int) $row['chat_id'] );

		return array(
			'chatId' => (int) $row['chat_id'],
			'chats'  => self::list_for_user( $user_id ),
		);
	}

	public static function update_message( $message_id, $content, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT m.*, c.user_id FROM ' . self::messages_table() . ' m
				INNER JOIN ' . self::chats_table() . ' c ON c.id = m.chat_id
				WHERE m.id = %d',
				$message_id
			),
			ARRAY_A
		);

		if ( ! $row || (int) $row['user_id'] !== $user_id ) {
			return new WP_Error( 'forbidden', __( 'پیام یافت نشد.', 'agent-wp' ) );
		}

		// فعلاً فقط پیام‌های کاربر قابل ویرایش‌اند
		if ( 'user' !== $row['role'] ) {
			return new WP_Error( 'forbidden', __( 'فقط پیام‌های خودتان قابل ویرایش است.', 'agent-wp' ) );
		}

		$content = trim( wp_strip_all_tags( (string) $content ) );
		if ( '' === $content ) {
			return new WP_Error( 'invalid', __( 'متن پیام خالی است.', 'agent-wp' ) );
		}

		$now = current_time( 'mysql' );
		$wpdb->update(
			self::messages_table(),
			array(
				'content'    => $content,
				'updated_at' => $now,
			),
			array( 'id' => (int) $message_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::touch( (int) $row['chat_id'] );

		return array(
			'id'        => (int) $message_id,
			'role'      => 'user',
			'content'   => $content,
			'createdAt' => $row['created_at'],
			'updatedAt' => $now,
		);
	}
}
