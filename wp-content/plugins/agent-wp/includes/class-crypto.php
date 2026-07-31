<?php
/**
 * رمزنگاری متقارن برای اسرار (مثل API Key).
 * چرا: کلید هرگز به‌صورت plaintext در DB/فرانت نباشد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Crypto {

	/**
	 * کلید مشتق از salt وردپرس — وابسته به نصب، نه هاردکد.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|agent-wp-v1', true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Fallback ضعیف‌تر فقط اگر openssl نباشد؛ باز هم base64 ساده نیست.
			return 'b64:' . base64_encode( $plain );
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}

		return 'enc:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$decoded = base64_decode( substr( $stored, 4 ), true );
			return false === $decoded ? '' : $decoded;
		}

		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv      = substr( $raw, 0, 16 );
		$cipher  = substr( $raw, 16 );
		$plain   = openssl_decrypt( $cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * نمایش امن برای UI — کلید کامل برنمی‌گردد.
	 */
	public static function mask( $plain ) {
		$plain = (string) $plain;
		$len   = strlen( $plain );
		if ( $len <= 8 ) {
			return str_repeat( '•', max( 4, $len ) );
		}
		return substr( $plain, 0, 3 ) . str_repeat( '•', min( 12, $len - 6 ) ) . substr( $plain, -3 );
	}
}
