<?php
/**
 * اکشن‌های در انتظار تأیید کاربر (پیش‌نمایش قبل از اجرا).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Pending {

	const OPTION = 'agent_wp_pending_actions';
	const TTL    = 1800; // 30 دقیقه

	/**
	 * @return string token
	 */
	public static function store( $tool_id, array $args, array $preview = array() ) {
		$token = wp_generate_password( 20, false, false );
		$all   = self::all();
		$all[ $token ] = array(
			'tool'      => sanitize_key( $tool_id ),
			'args'      => $args,
			'preview'   => $preview,
			'user_id'   => get_current_user_id(),
			'created'   => time(),
		);
		self::save( $all );
		return $token;
	}

	/**
	 * @return array|null
	 */
	public static function get( $token ) {
		$token = sanitize_text_field( (string) $token );
		$all   = self::all();
		if ( empty( $all[ $token ] ) || ! is_array( $all[ $token ] ) ) {
			return null;
		}
		$row = $all[ $token ];
		if ( (int) $row['user_id'] !== get_current_user_id() ) {
			return null;
		}
		if ( time() - (int) $row['created'] > self::TTL ) {
			unset( $all[ $token ] );
			self::save( $all );
			return null;
		}
		return $row;
	}

	public static function forget( $token ) {
		$all = self::all();
		unset( $all[ sanitize_text_field( (string) $token ) ] );
		self::save( $all );
	}

	/**
	 * اجرای اکشن تأییدشده.
	 *
	 * @return Agent_WP_Tool_Result
	 */
	public static function confirm( $token ) {
		$row = self::get( $token );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'درخواست تأیید منقضی یا نامعتبر است.', 'agent-wp' ) );
		}
		$args = is_array( $row['args'] ) ? $row['args'] : array();
		$args['confirmed'] = true;
		self::forget( $token );
		$result = Agent_WP_Tool_Registry::instance()->execute( $row['tool'], $args );
		$result->data['confirmed'] = true;
		return $result;
	}

	public static function exists( $token ) {
		$token = sanitize_text_field( (string) $token );
		$all   = self::all();
		if ( empty( $all[ $token ] ) || ! is_array( $all[ $token ] ) ) {
			return false;
		}
		$row = $all[ $token ];
		if ( (int) $row['user_id'] !== get_current_user_id() ) {
			return false;
		}
		if ( time() - (int) $row['created'] > self::TTL ) {
			return false;
		}
		return true;
	}

	public static function cancel( $token ) {
		$token = sanitize_text_field( (string) $token );
		$had   = self::exists( $token ) || self::get_raw( $token );
		self::forget( $token );
		return Agent_WP_Tool_Result::success(
			__( 'اکشن لغو شد.', 'agent-wp' ),
			array(
				'cancelled' => true,
				'hadToken'  => (bool) $had,
			)
		);
	}

	/**
	 * خواندن خام بدون حذف منقضی (برای تشخیص وجود قبلی).
	 */
	private static function get_raw( $token ) {
		$all = self::all();
		$token = sanitize_text_field( (string) $token );
		return ( ! empty( $all[ $token ] ) && is_array( $all[ $token ] ) ) ? $all[ $token ] : null;
	}

	public static function count_all() {
		return count( self::all() );
	}

	/**
	 * پاکسازی همهٔ توکن‌های منقضی.
	 *
	 * @return int
	 */
	public static function purge_expired() {
		$all = self::all();
		$before = count( $all );
		self::save( $all );
		return max( 0, $before - count( self::all() ) );
	}

	/**
	 * ثانیه‌های باقی‌مانده تا انقضا.
	 */
	public static function ttl_left( $token ) {
		$row = self::get( $token );
		if ( ! $row ) {
			return 0;
		}
		$left = self::TTL - ( time() - (int) $row['created'] );
		return max( 0, (int) $left );
	}

	private static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	private static function save( array $all ) {
		// پاکسازی منقضی
		$now = time();
		foreach ( $all as $k => $row ) {
			if ( ! is_array( $row ) || $now - (int) ( $row['created'] ?? 0 ) > self::TTL ) {
				unset( $all[ $k ] );
			}
		}
		update_option( self::OPTION, $all, false );
	}
}
