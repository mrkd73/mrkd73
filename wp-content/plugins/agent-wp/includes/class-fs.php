<?php
/**
 * دسترسی فایل ایجنت به قالب‌ها و افزونه‌ها.
 * چرا: خواندن/نوشتن گسترده با sandbox منطقی — هسته WP بسته؛ تأیید + نقطه بازگشت جدا.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Fs {

	const WORKSPACE_DIR = 'agent-wp-workspace';
	const RESTORE_DIR   = 'restore-points';

	/**
	 * ریشه‌های مجاز نوشتن: همه قالب‌ها، همه افزونه‌ها، mu-plugins، workspace.
	 *
	 * @return string[]
	 */
	public static function roots() {
		$roots = array();

		$themes = self::themes_dir();
		if ( $themes ) {
			$roots[] = $themes;
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
			$roots[] = wp_normalize_path( WP_PLUGIN_DIR );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$roots[] = wp_normalize_path( WPMU_PLUGIN_DIR );
		}

		$ws = self::workspace_dir( true );
		if ( $ws ) {
			$roots[] = $ws;
		}

		/**
		 * فیلتر گسترش مسیرهای مجاز نوشتن.
		 *
		 * @param string[] $roots
		 */
		$roots = apply_filters( 'agent_wp_fs_roots', $roots );

		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * ریشه‌های خواندنی = همان نوشتنی‌ها (به‌علاوه فیلتر جدا).
	 *
	 * @return string[]
	 */
	public static function read_roots() {
		$roots = self::roots();
		/**
		 * @param string[] $roots
		 */
		$roots = apply_filters( 'agent_wp_fs_read_roots', $roots );
		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * سطح ریسک نوشتن برای پیام اخطار/تأیید.
	 *
	 * @return string workspace|theme|plugin|unknown
	 */
	public static function write_risk( $relative ) {
		$relative = str_replace( '\\', '/', ltrim( (string) $relative, '/' ) );
		if ( 0 === strpos( $relative, 'workspace/' ) || 'workspace' === $relative ) {
			return 'workspace';
		}
		if (
			0 === strpos( $relative, 'plugin/' )
			|| 0 === strpos( $relative, 'plugins/' )
			|| 0 === strpos( $relative, 'mu-plugin/' )
			|| 'plugin' === $relative
			|| 'plugins' === $relative
			|| 'mu-plugin' === $relative
		) {
			return 'plugin';
		}
		if (
			0 === strpos( $relative, 'theme/' )
			|| 0 === strpos( $relative, 'parent/' )
			|| 0 === strpos( $relative, 'themes/' )
			|| 'theme' === $relative
			|| 'parent' === $relative
			|| 'themes' === $relative
		) {
			return 'theme';
		}
		return 'unknown';
	}

	/**
	 * آیا نوشتن حتماً نیاز به تأیید UI دارد؟
	 */
	public static function write_needs_confirm( $relative ) {
		$risk = self::write_risk( $relative );
		return in_array( $risk, array( 'theme', 'plugin', 'unknown' ), true );
	}

	/**
	 * متن اخطار فارسی برای UI.
	 */
	public static function write_warning( $relative, $will_overwrite = false ) {
		$risk = self::write_risk( $relative );
		$path = (string) $relative;
		if ( 'plugin' === $risk ) {
			$base = sprintf(
				/* translators: %s: virtual path */
				__( 'اخطار: در حال تغییر کد افزونه هستید (%s). ممکن است سایت یا به‌روزرسانی افزونه خراب شود.', 'agent-wp' ),
				$path
			);
		} elseif ( 'theme' === $risk ) {
			$base = sprintf(
				/* translators: %s: virtual path */
				__( 'اخطار: در حال تغییر فایل قالب هستید (%s). ظاهر یا عملکرد سایت ممکن است آسیب ببیند.', 'agent-wp' ),
				$path
			);
		} else {
			$base = sprintf(
				/* translators: %s: virtual path */
				__( 'اخطار: نوشتن در مسیر %s.', 'agent-wp' ),
				$path
			);
		}
		if ( $will_overwrite ) {
			$base .= ' ' . __( 'فایل فعلی قبل از اجرا در نقطه بازگشت ذخیره می‌شود و از تاریخچه اکشن قابل برگشت است.', 'agent-wp' );
		} else {
			$base .= ' ' . __( 'فایل جدید ساخته می‌شود؛ در صورت نیاز از rollback حذف می‌شود.', 'agent-wp' );
		}
		return $base;
	}

	/**
	 * @param bool $for_write اگر true فقط roots نوشتنی
	 * @return string|WP_Error مسیر مطلق نرمال‌شده
	 */
	public static function resolve( $relative, $must_exist = false, $for_write = false ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return new WP_Error( 'fs_path', __( 'مسیر فایل نامعتبر است.', 'agent-wp' ) );
		}

		$candidates = self::candidate_paths( $relative );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $abs ) {
			$abs = wp_normalize_path( $abs );
			if ( ! self::is_allowed( $abs, $for_write ) ) {
				continue;
			}
			if ( $must_exist && ! file_exists( $abs ) ) {
				continue;
			}
			return $abs;
		}

		if ( ! $must_exist && $for_write ) {
			foreach ( $candidates as $abs ) {
				$abs = wp_normalize_path( $abs );
				if ( self::is_allowed( $abs, true ) ) {
					return $abs;
				}
			}
		}

		return new WP_Error( 'fs_denied', __( 'دسترسی به این مسیر مجاز نیست (خارج از محدوده قالب/افزونه/workspace).', 'agent-wp' ) );
	}

	/**
	 * @return string[]|WP_Error
	 */
	private static function candidate_paths( $relative ) {
		$candidates = array();

		if ( 0 === strpos( $relative, 'theme/' ) || 'theme' === $relative ) {
			$rest = ( 'theme' === $relative ) ? '' : substr( $relative, 6 );
			$candidates[] = trailingslashit( get_stylesheet_directory() ) . $rest;
		} elseif ( 0 === strpos( $relative, 'parent/' ) || 'parent' === $relative ) {
			$rest = ( 'parent' === $relative ) ? '' : substr( $relative, 7 );
			$candidates[] = trailingslashit( get_template_directory() ) . $rest;
		} elseif ( 0 === strpos( $relative, 'themes/' ) || 'themes' === $relative ) {
			$themes = self::themes_dir();
			if ( ! $themes ) {
				return new WP_Error( 'fs_themes', __( 'پوشه قالب‌ها در دسترس نیست.', 'agent-wp' ) );
			}
			$rest = ( 'themes' === $relative ) ? '' : substr( $relative, 7 );
			$candidates[] = trailingslashit( $themes ) . $rest;
		} elseif ( 0 === strpos( $relative, 'plugin/' ) || 'plugin' === $relative ) {
			$rest = ( 'plugin' === $relative ) ? '' : substr( $relative, 7 );
			$candidates[] = trailingslashit( WP_PLUGIN_DIR ) . $rest;
		} elseif ( 0 === strpos( $relative, 'plugins/' ) || 'plugins' === $relative ) {
			$rest = ( 'plugins' === $relative ) ? '' : substr( $relative, 8 );
			$candidates[] = trailingslashit( WP_PLUGIN_DIR ) . $rest;
		} elseif ( 0 === strpos( $relative, 'mu-plugin/' ) || 'mu-plugin' === $relative ) {
			if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
				return new WP_Error( 'fs_mu', __( 'mu-plugins در این نصب تعریف نشده.', 'agent-wp' ) );
			}
			$rest = ( 'mu-plugin' === $relative ) ? '' : substr( $relative, 10 );
			$candidates[] = trailingslashit( WPMU_PLUGIN_DIR ) . $rest;
		} elseif ( 0 === strpos( $relative, 'workspace/' ) || 'workspace' === $relative ) {
			$ws = self::workspace_dir( true );
			if ( ! $ws ) {
				return new WP_Error( 'fs_upload', __( 'آپلود در دسترس نیست.', 'agent-wp' ) );
			}
			$rest = ( 'workspace' === $relative ) ? '' : substr( $relative, 10 );
			$candidates[] = trailingslashit( $ws ) . $rest;
		} else {
			// پیش‌فرض: قالب فعال، بعد workspace
			$candidates[] = trailingslashit( get_stylesheet_directory() ) . $relative;
			$ws = self::workspace_dir( true );
			if ( $ws ) {
				$candidates[] = trailingslashit( $ws ) . $relative;
			}
		}

		return $candidates;
	}

	/**
	 * @param bool $for_write
	 */
	public static function is_allowed( $abs, $for_write = false ) {
		$abs = wp_normalize_path( (string) $abs );

		$blocked = array(
			wp_normalize_path( ABSPATH . 'wp-config.php' ),
			wp_normalize_path( ABSPATH . 'wp-admin' ),
			wp_normalize_path( ABSPATH . 'wp-includes' ),
		);
		foreach ( $blocked as $b ) {
			if ( $abs === $b || 0 === strpos( $abs, trailingslashit( $b ) ) ) {
				return false;
			}
		}

		// خودِ فایل‌های حساس ریشه نصب
		$base = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$deny_names = array( 'wp-config.php', 'wp-config-sample.php', '.htaccess', 'nginx.conf' );
		foreach ( $deny_names as $name ) {
			if ( $abs === $base . '/' . $name ) {
				return false;
			}
		}

		$pool = $for_write ? self::roots() : self::read_roots();
		foreach ( $pool as $root ) {
			$root = trailingslashit( $root );
			if ( 0 === strpos( $abs, $root ) || $abs === rtrim( $root, '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{path:string,content:string,size:int}|WP_Error
	 */
	public static function read( $relative ) {
		$abs = self::resolve( $relative, true, false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) || ! is_readable( $abs ) ) {
			return new WP_Error( 'fs_read', __( 'فایل خواندنی نیست.', 'agent-wp' ) );
		}
		$size = filesize( $abs );
		if ( false !== $size && $size > 1024 * 1024 ) {
			return new WP_Error( 'fs_large', __( 'فایل بزرگ‌تر از 1MB است.', 'agent-wp' ) );
		}
		$content = file_get_contents( $abs );
		if ( false === $content ) {
			return new WP_Error( 'fs_read', __( 'خواندن فایل ناموفق بود.', 'agent-wp' ) );
		}
		return array(
			'path'    => self::to_virtual( $abs ),
			'abs'     => $abs,
			'content' => $content,
			'size'    => strlen( $content ),
		);
	}

	/**
	 * ذخیره نقطه بازگشت قبل از نوشتن.
	 *
	 * @return array{id:string,dir:string}|WP_Error|null null اگر محتوایی نبود
	 */
	public static function create_restore_point( $relative, $previous_content = null, $existed = false ) {
		$ws = self::workspace_dir( true );
		if ( ! $ws ) {
			return new WP_Error( 'fs_restore', __( 'ساخت نقطه بازگشت ناموفق (آپلود).', 'agent-wp' ) );
		}
		$id  = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
		$dir = trailingslashit( $ws ) . self::RESTORE_DIR . '/' . $id;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'fs_restore', __( 'پوشه نقطه بازگشت ساخته نشد.', 'agent-wp' ) );
		}

		$meta = array(
			'id'        => $id,
			'path'      => (string) $relative,
			'existed'   => (bool) $existed,
			'createdAt' => gmdate( 'c' ),
			'userId'    => get_current_user_id(),
			'risk'      => self::write_risk( $relative ),
		);
		file_put_contents( $dir . '/meta.json', wp_json_encode( $meta ) );
		if ( $existed && null !== $previous_content ) {
			file_put_contents( $dir . '/before.txt', (string) $previous_content );
		}

		return array(
			'id'  => $id,
			'dir' => self::to_virtual( $dir ),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public static function write( $relative, $content, $with_restore_point = true ) {
		$abs = self::resolve( $relative, false, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}

		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$previous = null;
		$existed  = file_exists( $abs );
		if ( $existed ) {
			$previous = file_get_contents( $abs );
		}

		$restore = null;
		if ( $with_restore_point && self::write_needs_confirm( $relative ) ) {
			$virt_for_rp = self::to_virtual( $abs );
			$restore     = self::create_restore_point( $virt_for_rp, $previous, $existed );
			if ( is_wp_error( $restore ) ) {
				return $restore;
			}
		}

		$ok = file_put_contents( $abs, (string) $content );
		if ( false === $ok ) {
			return new WP_Error( 'fs_write', __( 'نوشتن فایل ناموفق بود.', 'agent-wp' ) );
		}

		$virt = self::to_virtual( $abs );
		return array(
			'path'            => $virt,
			'abs'             => $abs,
			'bytes'           => (int) $ok,
			'created'         => ! $existed,
			'previous'        => $previous,
			'risk'            => self::write_risk( $virt ),
			'restorePointId'  => is_array( $restore ) ? $restore['id'] : null,
			'restorePointDir' => is_array( $restore ) ? $restore['dir'] : null,
		);
	}

	/**
	 * @return array<int,array{path:string,type:string,size?:int}>
	 */
	public static function list_dir( $relative = 'theme/', $depth = 2 ) {
		$relative = (string) $relative;
		$abs      = self::resolve( rtrim( $relative, '/' ), false, false );

		if ( is_wp_error( $abs ) ) {
			$norm = rtrim( $relative, '/' );
			$map  = array(
				'theme'     => get_stylesheet_directory(),
				'parent'    => get_template_directory(),
				'themes'    => self::themes_dir(),
				'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
				'plugins'   => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
				'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
				'workspace' => self::workspace_dir( true ),
			);
			if ( isset( $map[ $norm ] ) && $map[ $norm ] ) {
				$abs = wp_normalize_path( $map[ $norm ] );
			} else {
				return array();
			}
		}

		if ( is_file( $abs ) ) {
			return array(
				array(
					'path' => self::to_virtual( $abs ),
					'type' => 'file',
					'size' => (int) filesize( $abs ),
				),
			);
		}

		if ( ! is_dir( $abs ) ) {
			return array();
		}

		$out   = array();
		$depth = max( 0, min( 5, (int) $depth ) );
		self::walk( $abs, $depth, 0, $out );
		return $out;
	}

	private static function walk( $dir, $max_depth, $level, array &$out ) {
		if ( $level > $max_depth || count( $out ) >= 200 ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( count( $out ) >= 200 ) {
				return;
			}
			$path = wp_normalize_path( $dir . '/' . $item );
			if ( ! self::is_allowed( $path, false ) ) {
				continue;
			}
			// نقطه بازگشت را در لیست شلوغ نکن مگر صریحاً خواسته شود
			if ( false !== strpos( $path, '/' . self::WORKSPACE_DIR . '/' . self::RESTORE_DIR ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$out[] = array(
					'path' => self::to_virtual( $path ),
					'type' => 'dir',
				);
				self::walk( $path, $max_depth, $level + 1, $out );
			} else {
				$out[] = array(
					'path' => self::to_virtual( $path ),
					'type' => 'file',
					'size' => (int) @filesize( $path ),
				);
			}
		}
	}

	public static function to_virtual( $abs ) {
		$abs = wp_normalize_path( $abs );

		$theme = trailingslashit( wp_normalize_path( get_stylesheet_directory() ) );
		if ( 0 === strpos( $abs, $theme ) ) {
			return 'theme/' . substr( $abs, strlen( $theme ) );
		}

		$parent = trailingslashit( wp_normalize_path( get_template_directory() ) );
		if ( $parent !== $theme && 0 === strpos( $abs, $parent ) ) {
			return 'parent/' . substr( $abs, strlen( $parent ) );
		}

		$ws = self::workspace_dir( false );
		if ( $ws ) {
			$ws_slash = trailingslashit( $ws );
			if ( 0 === strpos( $abs, $ws_slash ) ) {
				return 'workspace/' . substr( $abs, strlen( $ws_slash ) );
			}
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$mu = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
			if ( 0 === strpos( $abs, $mu ) ) {
				return 'mu-plugin/' . substr( $abs, strlen( $mu ) );
			}
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
			if ( 0 === strpos( $abs, $plugins ) ) {
				return 'plugin/' . substr( $abs, strlen( $plugins ) );
			}
		}

		$themes = self::themes_dir();
		if ( $themes ) {
			$themes_slash = trailingslashit( $themes );
			if ( 0 === strpos( $abs, $themes_slash ) ) {
				return 'themes/' . substr( $abs, strlen( $themes_slash ) );
			}
		}

		return basename( $abs );
	}

	/**
	 * @return string|null
	 */
	public static function themes_dir() {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'themes' );
			return is_dir( $dir ) ? $dir : null;
		}
		return null;
	}

	/**
	 * @param bool $create
	 * @return string|null
	 */
	public static function workspace_dir( $create = false ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return null;
		}
		$ws = wp_normalize_path( trailingslashit( $upload['basedir'] ) . self::WORKSPACE_DIR );
		if ( $create && ! is_dir( $ws ) ) {
			wp_mkdir_p( $ws );
		}
		return is_dir( $ws ) || $create ? $ws : null;
	}
}
