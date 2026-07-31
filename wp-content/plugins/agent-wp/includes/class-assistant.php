<?php
/**
 * دستیار زنده سایت — فعال‌سازی، پیشنهادها، Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Assistant {

	const OPTION_ENABLED   = 'agent_wp_assistant_enabled';
	const OPTION_TOPIC     = 'agent_wp_assistant_topic';
	const OPTION_LAST_SCAN = 'agent_wp_assistant_last_scan';
	const OPTION_FAB_SIDE  = 'agent_wp_assistant_fab_side';
	const OPTION_INTERVAL  = 'agent_wp_assistant_interval';
	const OPTION_QUIET     = 'agent_wp_assistant_quiet';
	const OPTION_NOTIFY    = 'agent_wp_assistant_notify';
	const OPTION_SOUND     = 'agent_wp_assistant_sound';
	const OPTION_SURFACE   = 'agent_wp_assistant_surface';
	const OPTION_NOTE      = 'agent_wp_assistant_note';
	const OPTION_MAX_OPEN  = 'agent_wp_assistant_max_open';
	const OPTION_MODULES   = 'agent_wp_assistant_modules';
	const OPTION_DIGEST    = 'agent_wp_assistant_digest_mail';
	const CRON_HOOK        = 'agent_wp_assistant_scan';
	const CRON_PURGE       = 'agent_wp_assistant_autopurge';
	const CRON_DIGEST      = 'agent_wp_assistant_digest_mail';
	const MAX_MSG_LEN      = 8000;

	public static function init() {
		add_action( self::CRON_HOOK, array( 'Agent_WP_Assistant_Scan', 'run' ) );
		add_action( self::CRON_PURGE, array( __CLASS__, 'cron_autopurge' ) );
		add_action( self::CRON_DIGEST, array( __CLASS__, 'cron_digest_mail' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );

		if ( self::is_enabled() ) {
			self::ensure_schedule();
			self::ensure_purge_schedule();
			self::ensure_digest_schedule();
			add_action( 'admin_init', array( __CLASS__, 'maybe_pulse' ), 30 );
			add_action( 'wp', array( __CLASS__, 'maybe_pulse_front' ), 30 );
		} else {
			self::clear_schedule();
		}
	}

	public static function schedules( $schedules ) {
		$schedules['agent_wp_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'هر ۶ ساعت (دستیار Agent WP)', 'agent-wp' ),
		);
		$schedules['agent_wp_twelve_hours'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'هر ۱۲ ساعت (دستیار Agent WP)', 'agent-wp' ),
		);
		$schedules['agent_wp_day'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'هر ۲۴ ساعت (دستیار Agent WP)', 'agent-wp' ),
		);
		$schedules['agent_wp_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'هفتگی (پاکسازی دستیار Agent WP)', 'agent-wp' ),
		);
		return $schedules;
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	public static function set_enabled( $on ) {
		$on = (bool) $on;
		update_option( self::OPTION_ENABLED, $on ? 1 : 0, false );
		if ( $on ) {
			self::ensure_schedule();
			self::ensure_purge_schedule();
			self::ensure_digest_schedule();
			if ( ! get_option( self::OPTION_LAST_SCAN ) ) {
				Agent_WP_Assistant_Scan::run( true );
			}
		} else {
			self::clear_schedule();
		}
		return $on;
	}

	public static function get_topic() {
		$topic = (string) get_option( self::OPTION_TOPIC, '' );
		if ( '' !== $topic ) {
			return $topic;
		}
		$name = (string) get_bloginfo( 'name' );
		$desc = (string) get_bloginfo( 'description' );
		return trim( $name . ( $desc ? ' — ' . $desc : '' ) );
	}

	public static function set_topic( $topic ) {
		update_option( self::OPTION_TOPIC, sanitize_text_field( $topic ), false );
	}

	public static function get_fab_side() {
		$side = sanitize_key( (string) get_option( self::OPTION_FAB_SIDE, 'left' ) );
		return ( 'right' === $side ) ? 'right' : 'left';
	}

	public static function set_fab_side( $side ) {
		$side = ( 'right' === sanitize_key( $side ) ) ? 'right' : 'left';
		update_option( self::OPTION_FAB_SIDE, $side, false );
		return $side;
	}

	/** @return int 6|12|24 */
	public static function get_interval_hours() {
		$h = (int) get_option( self::OPTION_INTERVAL, 6 );
		if ( ! in_array( $h, array( 6, 12, 24 ), true ) ) {
			$h = 6;
		}
		return $h;
	}

	public static function set_interval_hours( $hours ) {
		$hours = (int) $hours;
		if ( ! in_array( $hours, array( 6, 12, 24 ), true ) ) {
			$hours = 6;
		}
		update_option( self::OPTION_INTERVAL, $hours, false );
		self::clear_schedule();
		self::ensure_schedule();
		return $hours;
	}

	public static function cron_recurrence() {
		$map = array(
			6  => 'agent_wp_six_hours',
			12 => 'agent_wp_twelve_hours',
			24 => 'agent_wp_day',
		);
		$h = self::get_interval_hours();
		return isset( $map[ $h ] ) ? $map[ $h ] : 'agent_wp_six_hours';
	}

	public static function quiet_enabled() {
		return (bool) get_option( self::OPTION_QUIET, true );
	}

	public static function set_quiet_enabled( $on ) {
		update_option( self::OPTION_QUIET, $on ? 1 : 0, false );
		return (bool) $on;
	}

	public static function notify_enabled() {
		return (bool) get_option( self::OPTION_NOTIFY, false );
	}

	public static function set_notify_enabled( $on ) {
		update_option( self::OPTION_NOTIFY, $on ? 1 : 0, false );
		return (bool) $on;
	}

	public static function sound_enabled() {
		return (bool) get_option( self::OPTION_SOUND, true );
	}

	public static function set_sound_enabled( $on ) {
		update_option( self::OPTION_SOUND, $on ? 1 : 0, false );
		return (bool) $on;
	}

	/** @return string both|front|admin */
	public static function get_surface() {
		$s = sanitize_key( (string) get_option( self::OPTION_SURFACE, 'both' ) );
		return in_array( $s, array( 'both', 'front', 'admin' ), true ) ? $s : 'both';
	}

	public static function set_surface( $s ) {
		$s = sanitize_key( $s );
		if ( ! in_array( $s, array( 'both', 'front', 'admin' ), true ) ) {
			$s = 'both';
		}
		update_option( self::OPTION_SURFACE, $s, false );
		return $s;
	}

	public static function get_note() {
		return (string) get_option( self::OPTION_NOTE, '' );
	}

	public static function set_note( $note ) {
		update_option( self::OPTION_NOTE, sanitize_textarea_field( $note ), false );
	}

	public static function get_max_open() {
		$n = (int) get_option( self::OPTION_MAX_OPEN, 12 );
		return max( 5, min( 40, $n ? $n : 12 ) );
	}

	public static function set_max_open( $n ) {
		$n = max( 5, min( 100, (int) $n ) );
		update_option( self::OPTION_MAX_OPEN, $n, false );
		return $n;
	}

	public static function default_modules() {
		return array(
			'content'   => 1,
			'design'    => 1,
			'seo'       => 1,
			'technical' => 1,
			'growth'    => 1,
			'cleanup'   => 1,
			'foresight' => 1,
			'quality'   => 1,
		);
	}

	public static function get_modules() {
		$saved = get_option( self::OPTION_MODULES, array() );
		$base  = self::default_modules();
		if ( ! is_array( $saved ) ) {
			return $base;
		}
		foreach ( $base as $k => $v ) {
			if ( isset( $saved[ $k ] ) ) {
				$base[ $k ] = $saved[ $k ] ? 1 : 0;
			}
		}
		return $base;
	}

	public static function set_modules( $modules ) {
		$base = self::default_modules();
		$out  = array();
		foreach ( $base as $k => $v ) {
			$out[ $k ] = ! empty( $modules[ $k ] ) ? 1 : 0;
		}
		update_option( self::OPTION_MODULES, $out, false );
		return $out;
	}

	public static function module_enabled( $key ) {
		$m = self::get_modules();
		return ! empty( $m[ sanitize_key( $key ) ] );
	}

	public static function digest_mail_enabled() {
		return (bool) get_option( self::OPTION_DIGEST, false );
	}

	public static function set_digest_mail_enabled( $on ) {
		update_option( self::OPTION_DIGEST, $on ? 1 : 0, false );
		if ( $on ) {
			self::ensure_digest_schedule();
		}
		return (bool) $on;
	}

	public static function ensure_digest_schedule() {
		if ( ! self::digest_mail_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_DIGEST ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'agent_wp_weekly', self::CRON_DIGEST );
		}
	}

	public static function cron_digest_mail() {
		if ( ! self::is_enabled() || ! self::digest_mail_enabled() ) {
			return;
		}
		$count = self::count_open();
		if ( $count < 1 ) {
			return;
		}
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf(
			/* translators: %s site name */
			__( '[دستیار] خلاصه هفتگی %s', 'agent-wp' ),
			get_bloginfo( 'name' )
		);
		wp_mail( $to, $subject, self::digest_text() );
	}

	public static function ensure_purge_schedule() {
		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'agent_wp_weekly', self::CRON_PURGE );
		}
	}

	public static function cron_autopurge() {
		self::wake_snoozed();
		self::purge_closed( 14 );
		Agent_WP_Pending::purge_expired();
	}

	public static function wake_snoozed() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, meta_json FROM " . self::table() . " WHERE status = 'snoozed' LIMIT 50",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		$now = time();
		foreach ( $rows as $row ) {
			$meta = array();
			if ( ! empty( $row['meta_json'] ) ) {
				$decoded = json_decode( (string) $row['meta_json'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			$until = isset( $meta['snoozeUntil'] ) ? (int) $meta['snoozeUntil'] : 0;
			if ( $until && $until <= $now ) {
				unset( $meta['snoozeUntil'] );
				$wpdb->update(
					self::table(),
					array(
						'status'     => 'open',
						'meta_json'  => wp_json_encode( $meta ),
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/** ساعات سکوت پیش‌فرض: ۰۰:۰۰ تا ۰۷:۰۰ به وقت سایت */
	public static function is_quiet_now() {
		if ( ! self::quiet_enabled() ) {
			return false;
		}
		$hour = (int) current_time( 'G' );
		return $hour >= 0 && $hour < 7;
	}

	public static function last_scan_label() {
		$ts = (int) get_option( self::OPTION_LAST_SCAN, 0 );
		if ( ! $ts ) {
			return __( 'هنوز اسکن نشده', 'agent-wp' );
		}
		return sprintf(
			/* translators: human time diff */
			__( 'آخرین اسکن: %s پیش', 'agent-wp' ),
			human_time_diff( $ts, time() )
		);
	}

	public static function admin_bar( $wp_admin_bar ) {
		if ( ! self::is_enabled() || ! current_user_can( 'manage_options' ) || ! is_object( $wp_admin_bar ) ) {
			return;
		}
		$count = self::count_open();
		$title = __( 'دستیار', 'agent-wp' );
		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod">' . (int) $count . '</span>';
		}
		$href = '#wpa-as-open';
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'agent-wp' ) ) {
				$href = admin_url( 'admin.php?page=agent-wp-chat' );
			}
		}
		$wp_admin_bar->add_node(
			array(
				'id'    => 'agent-wp-assistant',
				'title' => $title,
				'href'  => $href,
				'meta'  => array(
					'class' => 'agent-wp-as-bar',
					'title' => sprintf(
						/* translators: %d open suggestions */
						__( '%d پیشنهاد باز دستیار — کلیک برای باز کردن ویجت', 'agent-wp' ),
						$count
					),
				),
			)
		);
	}

	/**
	 * خلاصه کوتاه — بدون لیست بلند (برای UI و چت).
	 */
	public static function digest_text() {
		$brief = self::briefing();
		return $brief['text'];
	}

	/**
	 * بریفینگ دسته‌بندی‌شده: کم‌داده، اولویت‌دار.
	 *
	 * @return array{text:string,headline:string,total:int,categories:array,top:array,openCount:int}
	 */
	public static function briefing() {
		$open   = self::list_open( 40 );
		$total  = count( $open );
		$counts = self::category_counts();

		if ( ! $total ) {
			return array(
				'text'       => __( 'الان مورد فوری ندارم. اگر بخواهی سایت را دوباره سبک چک می‌کنم.', 'agent-wp' ),
				'headline'   => __( 'همه‌چیز آرام به نظر می‌رسد', 'agent-wp' ),
				'total'      => 0,
				'categories' => array(),
				'top'        => array(),
				'openCount'  => 0,
			);
		}

		// فقط دسته‌هایی که پیشنهاد دارند، مرتب بر اساس اولویت میانگین/تعداد
		$by_cat = array();
		foreach ( $open as $s ) {
			$c = $s['category'];
			if ( ! isset( $by_cat[ $c ] ) ) {
				$by_cat[ $c ] = array();
			}
			$by_cat[ $c ][] = $s;
		}

		$cat_labels = array(
			'content'   => __( 'محتوا', 'agent-wp' ),
			'design'    => __( 'ظاهر', 'agent-wp' ),
			'seo'       => __( 'سئو', 'agent-wp' ),
			'technical' => __( 'فنی', 'agent-wp' ),
			'growth'    => __( 'رشد', 'agent-wp' ),
			'cleanup'   => __( 'پاکسازی', 'agent-wp' ),
			'foresight' => __( 'آینده', 'agent-wp' ),
		);

		$categories = array();
		foreach ( $by_cat as $cat => $items ) {
			$max_p = 0;
			foreach ( $items as $it ) {
				$max_p = max( $max_p, (int) $it['priority'] );
			}
			$categories[] = array(
				'id'    => $cat,
				'label' => isset( $cat_labels[ $cat ] ) ? $cat_labels[ $cat ] : $cat,
				'count' => count( $items ),
				'maxPriority' => $max_p,
				// فقط ۱ مورد نماینده برای نمایش خلاصه
				'top'   => $items[0],
			);
		}
		usort(
			$categories,
			function ( $a, $b ) {
				if ( $a['maxPriority'] !== $b['maxPriority'] ) {
					return $b['maxPriority'] - $a['maxPriority'];
				}
				return $b['count'] - $a['count'];
			}
		);
		// حداکثر ۵ دسته در بریفینگ
		$categories = array_slice( $categories, 0, 5 );

		$top_n = array();
		foreach ( $categories as $c ) {
			$top_n[] = $c['top'];
			if ( count( $top_n ) >= 3 ) {
				break;
			}
		}

		$names = array();
		foreach ( $categories as $c ) {
			$names[] = $c['label'];
		}
		$headline = sprintf(
			/* translators: 1: count 2: category names */
			__( '%1$d موضوع — تمرکز: %2$s', 'agent-wp' ),
			$total,
			implode( '، ', array_slice( $names, 0, 3 ) )
		);

		$lines   = array();
		$lines[] = $headline . '.';
		$lines[] = __( 'پیشنهاد من: از مهم‌ترین دسته شروع کن؛ جزئیات را فقط اگر خواستی می‌گویم.', 'agent-wp' );
		foreach ( array_slice( $categories, 0, 3 ) as $c ) {
			$lines[] = '• ' . $c['label'] . ' (' . $c['count'] . '): ' . $c['top']['title'];
		}
		if ( $total > 3 ) {
			$lines[] = __( 'بگو «بیشتر بگو» یا نام دسته را بگو تا همان را باز کنم.', 'agent-wp' );
		}

		return array(
			'text'       => implode( "\n", $lines ),
			'headline'   => $headline,
			'total'      => $total,
			'categories' => $categories,
			'top'        => $top_n,
			'openCount'  => self::count_open(),
			'counts'     => $counts,
		);
	}

	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::cron_recurrence(), self::CRON_HOOK );
		}
	}

	public static function clear_schedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	public static function maybe_pulse() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::pulse_if_stale();
	}

	public static function maybe_pulse_front() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::pulse_if_stale();
	}

	private static function pulse_if_stale() {
		if ( self::is_quiet_now() ) {
			return;
		}
		$last = (int) get_option( self::OPTION_LAST_SCAN, 0 );
		$stale_after = max( 2, (int) ( self::get_interval_hours() / 2 ) ) * HOUR_IN_SECONDS;
		if ( $last && ( time() - $last ) < $stale_after ) {
			return;
		}
		// جلوگیری از اسکن همزمان سنگین
		if ( get_transient( 'agent_wp_assistant_scanning' ) ) {
			return;
		}
		set_transient( 'agent_wp_assistant_scanning', 1, 5 * MINUTE_IN_SECONDS );
		Agent_WP_Assistant_Scan::run();
		delete_transient( 'agent_wp_assistant_scanning' );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_wp_suggestions';
	}

	/**
	 * @return array<int,array>
	 */
	public static function list_open( $limit = 20 ) {
		return self::list_by_status( 'open', $limit );
	}

	/**
	 * @param string $status open|done|dismissed|all
	 * @return array<int,array>
	 */
	public static function list_by_status( $status = 'open', $limit = 20 ) {
		global $wpdb;
		$limit  = max( 1, min( 50, (int) $limit ) );
		$status = sanitize_key( $status );
		if ( 'all' === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC, id DESC LIMIT %d',
					$limit
				),
				ARRAY_A
			);
		} elseif ( 'open' === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . " WHERE status = 'open' ORDER BY priority DESC, id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		} elseif ( in_array( $status, array( 'done', 'dismissed', 'snoozed' ), true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = array();
		}
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'format_row' ), $rows );
	}

	public static function count_open() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE status = 'open'" );
	}

	/**
	 * محدودیت نرخ چت دستیار (هر کاربر).
	 *
	 * @return true|WP_Error
	 */
	public static function check_chat_rate( $max = 10, $window = 60 ) {
		$user_id = get_current_user_id();
		$key     = 'agent_wp_as_rate_' . $user_id;
		$hits    = (int) get_transient( $key );
		if ( $hits >= (int) $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'کمی صبر کن — پیام‌های دستیار زیاد پشت‌سرهم بود.', 'agent-wp' )
			);
		}
		set_transient( $key, $hits + 1, max( 10, (int) $window ) );
		return true;
	}

	/**
	 * بعد از ابزارهای موفق، پیشنهاد مرتبط را انجام‌شده کن.
	 *
	 * @param array  $tools نتایج ابزار
	 * @param string $hint  متن کاربر (ممکن است شامل [suggestion:ID] باشد)
	 * @return int تعداد بسته‌شده
	 */
	public static function maybe_complete_from_tools( array $tools, $hint = '' ) {
		$closed = 0;
		$hint   = (string) $hint;

		if ( preg_match( '/\[suggestion:(\d+)\]/i', $hint, $m ) ) {
			$sid = (int) $m[1];
			$ok  = false;
			foreach ( $tools as $t ) {
				if ( ! empty( $t['ok'] ) && empty( $t['data']['needsConfirm'] ) ) {
					$ok = true;
					break;
				}
			}
			if ( $ok && $sid && self::set_status( $sid, 'done' ) ) {
				$closed++;
			}
		}

		$open = self::list_open( 40 );
		if ( ! $open ) {
			return $closed;
		}

		$post_ids = array();
		foreach ( $tools as $t ) {
			if ( empty( $t['ok'] ) || ! empty( $t['data']['needsConfirm'] ) ) {
				continue;
			}
			$data = isset( $t['data'] ) && is_array( $t['data'] ) ? $t['data'] : array();
			foreach ( array( 'postId', 'pageId', 'id', 'attachmentId', 'menuId' ) as $k ) {
				if ( ! empty( $data[ $k ] ) ) {
					$post_ids[] = (int) $data[ $k ];
				}
			}
		}
		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
		if ( ! $post_ids ) {
			return $closed;
		}

		foreach ( $open as $s ) {
			$meta = isset( $s['meta'] ) && is_array( $s['meta'] ) ? $s['meta'] : array();
			$candidates = array();
			foreach ( array( 'postId', 'pageId', 'menuId', 'attachmentId' ) as $k ) {
				if ( ! empty( $meta[ $k ] ) ) {
					$candidates[] = (int) $meta[ $k ];
				}
			}
			if ( ! $candidates ) {
				continue;
			}
			if ( array_intersect( $candidates, $post_ids ) ) {
				if ( self::set_status( (int) $s['id'], 'done' ) ) {
					$closed++;
				}
			}
		}

		return $closed;
	}

	public static function format_row( $row ) {
		$meta = array();
		if ( ! empty( $row['meta_json'] ) ) {
			$decoded = json_decode( (string) $row['meta_json'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$edit_url = '';
		if ( ! empty( $meta['postId'] ) ) {
			$link = get_edit_post_link( (int) $meta['postId'], 'raw' );
			if ( $link ) {
				$edit_url = $link;
			}
		} elseif ( ! empty( $meta['pageId'] ) ) {
			$link = get_edit_post_link( (int) $meta['pageId'], 'raw' );
			if ( $link ) {
				$edit_url = $link;
			}
		} elseif ( ! empty( $meta['menuId'] ) && function_exists( 'admin_url' ) ) {
			$edit_url = admin_url( 'nav-menus.php?action=edit&menu=' . (int) $meta['menuId'] );
		}

		return array(
			'id'         => (int) $row['id'],
			'category'   => $row['category'],
			'title'      => $row['title'],
			'body'       => $row['body'],
			'priority'   => (int) $row['priority'],
			'status'     => $row['status'],
			'fingerprint'=> $row['fingerprint'],
			'meta'       => $meta,
			'editUrl'    => $edit_url,
			'createdAt'  => $row['created_at'],
		);
	}

	public static function upsert_suggestion( $fingerprint, $category, $title, $body, $priority = 5, array $meta = array() ) {
		global $wpdb;
		$fingerprint = substr( sanitize_key( $fingerprint ), 0, 64 );
		$existing    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status FROM ' . self::table() . ' WHERE fingerprint = %s LIMIT 1',
				$fingerprint
			),
			ARRAY_A
		);

		$data = array(
			'category'    => sanitize_key( $category ),
			'title'       => sanitize_text_field( $title ),
			'body'        => wp_kses_post( $body ),
			'priority'    => max( 1, min( 10, (int) $priority ) ),
			'meta_json'   => wp_json_encode( $meta ),
			'updated_at'  => current_time( 'mysql' ),
		);

		if ( $existing ) {
			if ( in_array( $existing['status'], array( 'dismissed', 'done', 'snoozed' ), true ) ) {
				return (int) $existing['id'];
			}
			$wpdb->update(
				self::table(),
				$data,
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing['id'];
		}

		if ( self::count_open() >= self::get_max_open() ) {
			return 0;
		}

		$wpdb->insert(
			self::table(),
			array_merge(
				$data,
				array(
					'fingerprint' => $fingerprint,
					'status'      => 'open',
					'created_at'  => current_time( 'mysql' ),
				)
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( 'open', 'done', 'dismissed', 'snoozed' ), true ) ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $id
	 * @param int $days 1|3|7
	 */
	public static function snooze( $id, $days = 1 ) {
		global $wpdb;
		$id   = (int) $id;
		$days = in_array( (int) $days, array( 1, 3, 7 ), true ) ? (int) $days : 1;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT meta_json FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		$meta = array();
		if ( ! empty( $row['meta_json'] ) ) {
			$decoded = json_decode( (string) $row['meta_json'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$meta['snoozeUntil'] = time() + ( $days * DAY_IN_SECONDS );
		return false !== $wpdb->update(
			self::table(),
			array(
				'status'     => 'snoozed',
				'meta_json'  => wp_json_encode( $meta ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * چت اختصاصی دستیار برای کاربر فعلی.
	 */
	public static function ensure_chat_id() {
		$user_id = get_current_user_id();
		$chats   = Agent_WP_Chats::list_for_user( $user_id );
		foreach ( $chats as $chat ) {
			if ( isset( $chat['title'] ) && 'دستیار' === $chat['title'] ) {
				return (int) $chat['id'];
			}
		}
		$id = Agent_WP_Chats::create(
			array(
				'title'   => 'دستیار',
				'user_id' => $user_id,
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * زمینهٔ صفحهٔ فعلی از درخواست AJAX / فرانت (پایه).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function normalize_page_context( array $raw = array() ) {
		$post_id = isset( $raw['postId'] ) ? absint( $raw['postId'] ) : 0;
		if ( ! $post_id && isset( $raw['post_id'] ) ) {
			$post_id = absint( $raw['post_id'] );
		}
		$comment_id = isset( $raw['commentId'] ) ? absint( $raw['commentId'] ) : 0;
		$title      = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		$post_type  = isset( $raw['postType'] ) ? sanitize_key( (string) $raw['postType'] ) : '';
		if ( $post_id ) {
			$p = get_post( $post_id );
			if ( $p ) {
				$post_type = (string) $p->post_type;
				if ( '' === $title ) {
					$title = get_the_title( $p );
				}
			}
		}
		return array(
			'url'        => isset( $raw['url'] ) ? esc_url_raw( (string) $raw['url'] ) : '',
			'screen'     => isset( $raw['screen'] ) ? sanitize_text_field( (string) $raw['screen'] ) : '',
			'postId'     => $post_id,
			'isAdmin'    => ! empty( $raw['isAdmin'] ) || ! empty( $raw['is_admin'] ),
			'title'      => $title,
			'postType'   => $post_type,
			'commentId'  => $comment_id,
			'optionPage' => isset( $raw['optionPage'] ) ? sanitize_key( (string) $raw['optionPage'] ) : '',
			'isNew'      => ! empty( $raw['isNew'] ),
		);
	}

	/**
	 * زمینهٔ غنی برای ایجنت (شناخت صفحه + snapshot فیلدها).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function live_page_context( array $raw = array() ) {
		$base = self::normalize_page_context( $raw );
		if ( class_exists( 'Agent_WP_Page_Context' ) ) {
			return Agent_WP_Page_Context::enrich( $base );
		}
		return $base;
	}

	/**
	 * ۱ تا ۳ پیشنهاد وابسته به صفحه‌ای که کاربر الان آنجاست (جدا از بریفینگ کلی).
	 *
	 * @param array<string,mixed> $ctx
	 * @return array<int,array<string,mixed>>
	 */
	public static function page_suggestions( array $ctx ) {
		$ctx  = self::normalize_page_context( $ctx );
		$out  = array();
		$push = function ( $key, $category, $title, $body, $priority = 7, array $extra = array() ) use ( &$out ) {
			if ( count( $out ) >= 3 ) {
				return;
			}
			$out[] = array_merge(
				array(
					'id'         => 'page:' . $key,
					'key'        => $key,
					'category'   => $category,
					'title'      => $title,
					'body'       => $body,
					'priority'   => $priority,
					'status'     => 'open',
					'ephemeral'  => true,
					'pageScoped' => true,
				),
				$extra
			);
		};

		$post_id = (int) $ctx['postId'];
		$screen  = (string) $ctx['screen'];
		$url     = (string) $ctx['url'];
		$kind    = isset( $ctx['kind'] ) ? (string) $ctx['kind'] : '';
		if ( ! $kind && class_exists( 'Agent_WP_Page_Context' ) ) {
			$kind = Agent_WP_Page_Context::detect_kind( $ctx );
		}

		// محصول ووکامرس
		if ( in_array( $kind, array( 'product_edit', 'product_new' ), true ) || 'product' === ( $ctx['postType'] ?? '' ) ) {
			$entity = isset( $ctx['entity'] ) && is_array( $ctx['entity'] ) ? $ctx['entity'] : array();
			if ( empty( $entity['hasThumb'] ) && $post_id ) {
				$push(
					'product_image',
					'design',
					__( 'تصویر محصول بساز', 'agent-wp' ),
					__( 'در چت بگو تصویر چه باشد — ساخته و به‌عنوان تصویر شاخص تنظیم می‌شود.', 'agent-wp' ),
					9,
					array(
						'chatPrompt' => $post_id
							? sprintf( __( 'برای محصول #%d یک تصویر شاخص جذاب بساز و تنظیم کن.', 'agent-wp' ), $post_id )
							: __( 'برای این محصول یک تصویر شاخص جذاب بساز و تنظیم کن.', 'agent-wp' ),
						'postId'     => $post_id,
					)
				);
			}
			if ( $post_id && ( empty( $entity['price'] ) && empty( $entity['regularPrice'] ) ) ) {
				$push(
					'product_price',
					'growth',
					__( 'قیمت را تنظیم کن', 'agent-wp' ),
					__( 'در چت قیمت / فروش ویژه / موجودی را بگو تا اعمال شود.', 'agent-wp' ),
					8,
					array(
						'chatPrompt' => sprintf( __( 'قیمت و موجودی محصول #%d را با من هماهنگ کن؛ اول وضعیت فعلی را بگو.', 'agent-wp' ), $post_id ),
						'postId'     => $post_id,
					)
				);
			}
			$push(
				'product_fill',
				'content',
				__( 'از چت پرش کن', 'agent-wp' ),
				__( 'عنوان، هشتگ، گالری، توضیح — هر چه بگویی روی همین محصول اعمال می‌شود.', 'agent-wp' ),
				7,
				array(
					'chatPrompt' => __( 'من روی صفحهٔ همین محصولم. آماده‌ای دستورهای عنوان/تصویر/قیمت/برچسب/موجودی را اجرا کنی؟ فقط تأیید کوتاه بده.', 'agent-wp' ),
					'postId'     => $post_id,
				)
			);
		}

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && ! in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				$edit = get_edit_post_link( $post_id, 'raw' );
				if ( ! has_post_thumbnail( $post_id ) ) {
					$push(
						'featured',
						'design',
						__( 'تصویر شاخص بساز', 'agent-wp' ),
						__( 'این نوشته تصویر شاخص ندارد. ایجنت می‌تواند با پرامپت تو تصویر بسازد و تنظیم کند.', 'agent-wp' ),
						9,
						array(
							'editorAction' => 'featured',
							'postId'       => $post_id,
							'editUrl'      => $edit ? $edit : '',
							'promptHint'   => sprintf(
								/* translators: %s post title */
								__( 'تصویر شاخص جذاب برای: %s', 'agent-wp' ),
								get_the_title( $post )
							),
						)
					);
				}
				$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
				if ( empty( $tags ) && is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
					$push(
						'tags',
						'seo',
						__( 'برچسب‌ها را پیشنهاد بده', 'agent-wp' ),
						__( 'هنوز برچسبی ندارد. ایجنت چند برچسب مرتبط پیشنهاد و اعمال می‌کند.', 'agent-wp' ),
						8,
						array(
							'editorAction' => 'tags',
							'postId'       => $post_id,
							'editUrl'      => $edit ? $edit : '',
							'promptHint'   => __( 'برچسب‌های کوتاه و مرتبط با موضوع نوشته', 'agent-wp' ),
						)
					);
				}
				$excerpt = trim( (string) $post->post_excerpt );
				if ( '' === $excerpt && post_type_supports( $post->post_type, 'excerpt' ) ) {
					$push(
						'excerpt',
						'content',
						__( 'خلاصهٔ کوتاه بنویس', 'agent-wp' ),
						__( 'خلاصه خالی است — برای سئو و کارت‌های اشتراک مفید است.', 'agent-wp' ),
						7,
						array(
							'editorAction' => 'excerpt',
							'postId'       => $post_id,
							'editUrl'      => $edit ? $edit : '',
							'promptHint'   => __( 'یک خلاصه ۲–۳ جمله‌ای جذاب و واضح', 'agent-wp' ),
						)
					);
				}
				$content_len = mb_strlen( wp_strip_all_tags( (string) $post->post_content ) );
				if ( $content_len < 80 && 'auto-draft' !== $post->post_status ) {
					$push(
						'content_block',
						'content',
						__( 'یک بلوک متن اولیه بنویس', 'agent-wp' ),
						__( 'محتوای نوشته خیلی کوتاه است. ایجنت می‌تواند یک پاراگراف شروع بسازد.', 'agent-wp' ),
						8,
						array(
							'editorAction' => 'content_block',
							'postId'       => $post_id,
							'editUrl'      => $edit ? $edit : '',
							'promptHint'   => __( 'یک پاراگراف مقدمهٔ واضح و مفید', 'agent-wp' ),
						)
					);
				}
				if ( 'draft' === $post->post_status || 'pending' === $post->post_status ) {
					$push(
						'publish_ready',
						'growth',
						__( 'برای انتشار آماده کن', 'agent-wp' ),
						__( 'هنوز پیش‌نویس است. بگو چه کم دارد تا قبل از انتشار درست شود.', 'agent-wp' ),
						6,
						array(
							'chatPrompt' => sprintf(
								/* translators: %d post id */
								__( 'نوشته #%d را برای انتشار بررسی کن و فقط مهم‌ترین کارها را بگو.', 'agent-wp' ),
								$post_id
							),
							'postId'     => $post_id,
							'editUrl'    => $edit ? $edit : '',
						)
					);
				}
			}
		}

		if ( false !== strpos( $screen, 'edit-comments' ) || false !== strpos( $screen, 'comment' ) || false !== strpos( $url, 'edit-comments.php' ) ) {
			$pending = (int) wp_count_comments()->moderated;
			if ( $pending > 0 ) {
				$push(
					'moderate_comments',
					'growth',
					sprintf(
						/* translators: %d pending comments */
						__( '%d دیدگاه در انتظار تأیید', 'agent-wp' ),
						$pending
					),
					__( 'می‌توانی از ایجنت بخواهی پاسخ مودبانه پیشنهاد دهد یا اولویت را بگوید.', 'agent-wp' ),
					8,
					array(
						'chatPrompt' => __( 'دیدگاه‌های در انتظار را خلاصه کن و برای مهم‌ترین‌ها یک پاسخ پیشنهادی بده.', 'agent-wp' ),
						'editUrl'    => admin_url( 'edit-comments.php?comment_status=moderated' ),
					)
				);
			} else {
				$push(
					'comment_tone',
					'growth',
					__( 'پاسخ دیدگاه با لحن برند', 'agent-wp' ),
					__( 'روی هر دیدگاه می‌توانی از دکمهٔ ایجنت برای پیش‌نویس پاسخ استفاده کنی.', 'agent-wp' ),
					5,
					array(
						'chatPrompt' => __( 'چطور با دیدگاه‌ها با لحن برند پاسخ بدهم؟ یک الگوی کوتاه بده.', 'agent-wp' ),
					)
				);
			}
		}

		if ( false !== strpos( $screen, 'dashboard' ) || false !== strpos( $url, 'index.php' ) ) {
			$push(
				'dashboard_focus',
				'growth',
				__( 'اولویت امروز سایت', 'agent-wp' ),
				__( 'یک کار با بیشترین اثر را از بریفینگ انتخاب کن و شروع کن.', 'agent-wp' ),
				6,
				array(
					'chatPrompt' => __( 'با توجه به پیشنهادهای باز، فقط یک اولویت امروز بگو و چرا.', 'agent-wp' ),
				)
			);
		}

		if ( ! $out && $ctx['isAdmin'] ) {
			$push(
				'ask_page',
				'technical',
				__( 'همین صفحه را بهینه کن', 'agent-wp' ),
				__( 'بگو در این صفحه چه کار می‌کنی تا پیشنهاد مشخص بدهم.', 'agent-wp' ),
				5,
				array(
					'chatPrompt' => sprintf(
						/* translators: %s screen or url */
						__( 'من در صفحه «%s» هستم. ۱ تا ۳ کار مفید و کوتاه پیشنهاد بده.', 'agent-wp' ),
						$screen ? $screen : $url
					),
				)
			);
		}

		if ( ! $out && ! $ctx['isAdmin'] && $post_id ) {
			$push(
				'front_improve',
				'design',
				__( 'این صفحه را بهتر کن', 'agent-wp' ),
				__( 'از نظر محتوا، ظاهر یا سئو چه بهبود سریعی پیشنهاد می‌کنی؟', 'agent-wp' ),
				6,
				array(
					'chatPrompt' => sprintf(
						/* translators: %s title */
						__( 'صفحهٔ «%s» را سریع بررسی کن و حداکثر ۳ پیشنهاد بده.', 'agent-wp' ),
						$ctx['title'] ? $ctx['title'] : __( 'فعلی', 'agent-wp' )
					),
					'postId'     => $post_id,
				)
			);
		}

		return array_slice( $out, 0, 3 );
	}

	/**
	 * زمینه برای system prompt چت دستیار.
	 */
	public static function context_for_prompt() {
		$topic = self::get_topic();
		$brief = self::briefing();
		$note  = self::get_note();
		$extra = $note ? ( "یادداشت کارفرما: {$note}\n" ) : '';

		$tops = array();
		foreach ( array_slice( $brief['top'], 0, 3 ) as $s ) {
			$tops[] = sprintf( '[#%d][%s] %s', $s['id'], $s['category'], $s['title'] );
		}
		$list = $tops ? implode( "\n", $tops ) : __( 'پیشنهاد بازی نیست.', 'agent-wp' );

		$page_raw = Agent_WP_Agent::get_request_context( 'page', array() );
		$page_blk = '';
		$on_page  = false;
		if ( is_array( $page_raw ) && $page_raw ) {
			$page    = self::live_page_context( $page_raw );
			$on_page = true;
			if ( class_exists( 'Agent_WP_Page_Context' ) ) {
				$page_blk = Agent_WP_Page_Context::prompt_block( $page ) . "\n\n";
			} else {
				$page_blk = "صفحهٔ فعلی: screen={$page['screen']} postId={$page['postId']}\n";
			}
			$page_sugs = self::page_suggestions( $page );
			$ps_lines  = array();
			foreach ( $page_sugs as $ps ) {
				$ps_lines[] = '- ' . $ps['title'] . ( ! empty( $ps['body'] ) ? ': ' . wp_strip_all_tags( $ps['body'] ) : '' );
			}
			if ( $ps_lines ) {
				$page_blk .= "پیشنهادهای مخصوص همین صفحه:\n" . implode( "\n", $ps_lines ) . "\n\n";
			}
		}

		$style = $on_page
			? "سبک گفتگو روی صفحهٔ زنده:\n"
				. "1) کاربر دستور می‌دهد؛ تو با Tool روی همین موجودیت اجرا می‌کنی — خلاصهٔ کوتاه بعد از اجرا.\n"
				. "2) نپرس کدام محصول/نوشته؛ postId/صفحه در زمینه است.\n"
				. "3) چند فیلد در یک پیام (عنوان+تصویر+قیمت+برچسب) را پشت‌سرهم با Toolها انجام بده.\n"
				. "4) کارهای مخرب تنظیمات هسته را تأیید UI بگیر؛ فیلدهای محتوا/محصول با دستور صریح فوری.\n"
			: "سبک گفتگو (اجباری):\n"
				. "1) همیشه اول خلاصهٔ کوتاه بده (۲–۴ خط). از لیست بلند و جدول و آمار پرحجم پرهیز کن.\n"
				. "2) پیشنهادها را دسته‌بندی کن (محتوا / ظاهر / سئو / فنی / رشد). در هر پاسخ حداکثر ۳ پیشنهاد نام ببر مگر کاربر «بیشتر» بخواهد.\n"
				. "3) جزئیات، داده خام، یا اجرای Tool را فقط وقتی بده که کاربر صریحاً بخواهد یا تأیید کند.\n"
				. "4) لحن: آرام، مطمئن، مثل مشاور انسانی باتجربه — نه ربات گزارش‌نویس.\n"
				. "5) اگر کاربر گفت «انجام بده»، با Toolها اجرا کن؛ کارهای مخرب را تأیید بگیر.\n";

		return "تو «دستیار» هستی — مشاور و مجری رشد سایت روی همان صفحه‌ای که کاربر ایستاده.\n"
			. "موضوع سایت: {$topic}\n"
			. $extra
			. $page_blk
			. $style
			. "بریفینگ کلی سایت (جدا از صفحه):\n{$list}\n"
			. "جمع باز: {$brief['total']}.";
	}

	/**
	 * پاکسازی پیشنهادهای بستهٔ قدیمی.
	 *
	 * @return int تعداد حذف‌شده
	 */
	public static function purge_closed( $days = 14 ) {
		global $wpdb;
		$days = max( 1, min( 365, (int) $days ) );
		$cutoff_local = date_i18n( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE status IN ('done','dismissed') AND updated_at < %s",
				$cutoff_local
			)
		);
	}

	/**
	 * پاک کردن پیام‌های چت دستیار (خود چت می‌ماند).
	 *
	 * @return true|WP_Error
	 */
	public static function clear_chat_history() {
		$chat_id = self::ensure_chat_id();
		if ( ! $chat_id ) {
			return new WP_Error( 'no_chat', __( 'گفتگوی دستیار پیدا نشد.', 'agent-wp' ) );
		}
		return Agent_WP_Chats::clear_messages( $chat_id );
	}

	public static function category_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT category, COUNT(*) AS c FROM " . self::table() . " WHERE status = 'open' GROUP BY category",
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ $r['category'] ] = (int) $r['c'];
			}
		}
		return $out;
	}

	public static function bulk_set_status( $status, $only_open = true ) {
		global $wpdb;
		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( 'done', 'dismissed', 'open' ), true ) ) {
			return 0;
		}
		if ( $only_open && 'open' !== $status ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = %s, updated_at = %s WHERE status = 'open'",
					$status,
					current_time( 'mysql' )
				)
			);
		}
		return 0;
	}

	public static function export_suggestions( $status = 'open' ) {
		$rows = self::list_by_status( $status, 50 );
		return array(
			'exportedAt'  => gmdate( 'c' ),
			'site'        => get_bloginfo( 'name' ),
			'topic'       => self::get_topic(),
			'status'      => $status,
			'suggestions' => $rows,
			'categories'  => self::category_counts(),
		);
	}

	public static function search_suggestions( $q, $category = '', $status = 'open', $limit = 20 ) {
		$items = self::list_by_status( $status, 50 );
		$q     = trim( (string) $q );
		if ( function_exists( 'mb_strtolower' ) ) {
			$q = mb_strtolower( $q );
		} else {
			$q = strtolower( $q );
		}
		$category = sanitize_key( $category );
		$out      = array();
		foreach ( $items as $s ) {
			if ( $category && $s['category'] !== $category ) {
				continue;
			}
			if ( $q ) {
				$hay = $s['title'] . ' ' . $s['body'];
				$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
				if ( false === strpos( $hay, $q ) ) {
					continue;
				}
			}
			$out[] = $s;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * محدودیت نرخ عمومی.
	 *
	 * @return true|WP_Error
	 */
	public static function check_rate( $bucket, $max = 20, $window = 60 ) {
		$user_id = get_current_user_id();
		$key     = 'agent_wp_rate_' . sanitize_key( $bucket ) . '_' . $user_id;
		$hits    = (int) get_transient( $key );
		if ( $hits >= (int) $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'کمی صبر کن — درخواست‌ها زیاد پشت‌سرهم بود.', 'agent-wp' )
			);
		}
		set_transient( $key, $hits + 1, max( 10, (int) $window ) );
		return true;
	}

	public static function clamp_message( $content ) {
		$content = (string) $content;
		$max     = self::MAX_MSG_LEN;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $content ) > $max ) {
			return mb_substr( $content, 0, $max );
		}
		if ( strlen( $content ) > $max ) {
			return substr( $content, 0, $max );
		}
		return $content;
	}
}
