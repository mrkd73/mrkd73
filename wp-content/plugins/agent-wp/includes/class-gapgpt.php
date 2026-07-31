<?php
/**
 * درخواست‌های کمکی GapGPT: اعتبار، مصرف، و مسیرهای جایگزین.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Gapgpt {

	/**
	 * تست سریع اتصال به GapGPT (لیست مدل‌ها).
	 *
	 * @return array{ok:bool,message:string,latencyMs?:int}
	 */
	public static function test_connection() {
		$key = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ),
			);
		}
		$base  = untrailingslashit( Agent_WP_Models::get_gapgpt_base() );
		$start = microtime( true );
		$res   = self::get_json( $base . '/models', $key );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			// بعضی درگاه‌ها /v1/models می‌خواهند
			$res = self::get_json( $base . '/v1/models', $key );
			$ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
		}
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'        => false,
				'message'   => $res->get_error_message(),
				'latencyMs' => $ms,
				'baseUrl'   => $base,
			);
		}
		$count = 0;
		if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
			$count = count( $res['data'] );
		} elseif ( is_array( $res ) ) {
			$count = count( $res );
		}
		return array(
			'ok'        => true,
			'message'   => sprintf(
				/* translators: 1: model count 2: ms */
				__( 'اتصال برقرار است — حدود %1$d مدل، %2$dms', 'agent-wp' ),
				$count,
				$ms
			),
			'latencyMs' => $ms,
			'baseUrl'   => $base,
			'modelCount'=> $count,
		);
	}

	/**
	 * تبدیل خطای خام API به پیام فارسیِ قابل‌نمایش.
	 *
	 * @return string
	 */
	public static function humanize_api_error( $message ) {
		$msg = trim( (string) $message );
		if ( '' === $msg ) {
			return __( 'اتصال به مدل ناموفق بود.', 'agent-wp' );
		}

		// خطای سهمیه/اعتبار GapGPT (با $ یا ＄ تمام‌عرض)
		if ( preg_match( '/remaining\s+user\s+quota:\s*[＄$]?\s*([\d.]+).*required\s+pre-consume\s+quota:\s*[＄$]?\s*([\d.]+)/iu', $msg, $m ) ) {
			$remain = (float) $m[1];
			$need   = (float) $m[2];
			self::remember_quota( $remain, $need, $msg );
			return sprintf(
				/* translators: 1: remaining 2: required */
				__( "⚠️ اعتبار GapGPT کافی نیست.\n\nباقی‌مانده: %1\$s دلار\nموردنیاز این درخواست: حدود %2\$s دلار\n\nلطفاً حساب را در پنل GapGPT شارژ کنید، بعد دوباره پیام بفرستید.", 'agent-wp' ),
				number_format_i18n( $remain, 4 ),
				number_format_i18n( $need, 4 )
			);
		}

		if ( preg_match( '/insufficient|quota|billing|pre-consume|out of credit|balance too low/i', $msg ) ) {
			self::remember_quota_from_text( $msg );
			return __( "⚠️ اعتبار یا سهمیه GapGPT کافی نیست.\n\nاز پنل GapGPT حساب را شارژ کنید و دوباره تلاش کنید.", 'agent-wp' );
		}

		if ( preg_match( '/unauthorized|invalid api key|incorrect api key|401/i', $msg ) ) {
			return __( '🔑 کلید GapGPT نامعتبر است. از تنظیمات کلید را بررسی کنید.', 'agent-wp' );
		}

		// مدل در کاتالوگ هست ولی برای این حساب/پلن کانال فعالی ندارد (معمولاً شارژ نیست)
		if ( preg_match( '/no available channel(?: for model)?/i', $msg ) ) {
			$model = '';
			if ( preg_match( '/no available channel for model\s+([^\s\),]+)/i', $msg, $m ) ) {
				$model = sanitize_text_field( $m[1] );
			}
			if ( $model ) {
				self::remember_unavailable_model( $model );
				return sprintf(
					/* translators: %s: model id */
					__( 'مدل «%s» الان در GapGPT برای حساب شما کانال فعالی ندارد (معمولاً پلن/دسترسی API است، نه کمبود شارژ). مدل دیگری را امتحان کنید.', 'agent-wp' ),
					$model
				);
			}
			return __( 'این مدل الان در GapGPT برای حساب شما کانال فعالی ندارد (معمولاً پلن/دسترسی است، نه کمبود شارژ). مدل دیگری را امتحان کنید یا در پنل GapGPT مدل‌های فعال را چک کنید.', 'agent-wp' );
		}

		if ( preg_match( '/video_timeout|ساخت ویدیو طولانی/i', $msg ) ) {
			return __( 'ساخت ویدیو بیش از حد طول کشید. مدل دیگری را امتحان کنید یا بعداً دوباره بفرستید.', 'agent-wp' );
		}

		if ( preg_match( '/model .+ not found|does not exist|404/i', $msg ) ) {
			return __( 'مدل انتخاب‌شده در GapGPT پیدا نشد. مدل دیگری را انتخاب کنید.', 'agent-wp' );
		}

		if ( preg_match( '/rate limit|too many requests|429/i', $msg ) ) {
			return __( 'تعداد درخواست‌ها زیاد شده. چند لحظه صبر کنید و دوباره بفرستید.', 'agent-wp' );
		}

		if ( preg_match( '/timeout|timed out|cURL error/i', $msg ) ) {
			return __( 'پاسخ مدل طول کشید یا قطع شد. دوباره تلاش کنید.', 'agent-wp' );
		}

		// پیام خیلی انگلیسی/تکنیکی را کوتاه و فارسی کن
		if ( preg_match( '/^[A-Za-z0-9\s\-\_\.\,\:\(\)\$＄\/]+$/', $msg ) && mb_strlen( $msg ) > 80 ) {
			return __( 'خطا از سمت GapGPT دریافت شد. اگر مشکل ادامه داشت، اعتبار و کلید را در تنظیمات بررسی کنید.', 'agent-wp' );
		}

		return $msg;
	}

	public static function remember_quota( $remaining, $required = null, $raw = '' ) {
		update_option(
			'agent_wp_gapgpt_last_quota',
			array(
				'remaining' => (float) $remaining,
				'required'  => null !== $required ? (float) $required : null,
				'at'        => time(),
				'raw'       => (string) $raw,
			),
			false
		);
		delete_transient( 'agent_wp_gapgpt_account' );
	}

	/**
	 * مدل‌هایی که در این نشست کانال نداشتند (برای رد کردن سریع در fallback).
	 */
	public static function remember_unavailable_model( $model_id ) {
		$model_id = sanitize_text_field( (string) $model_id );
		if ( '' === $model_id ) {
			return;
		}
		$key  = 'agent_wp_gapgpt_unavailable_models';
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $model_id ] = time();
		set_transient( $key, $list, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * @return string[]
	 */
	public static function unavailable_models() {
		$list = get_transient( 'agent_wp_gapgpt_unavailable_models' );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_keys( $list );
	}

	public static function is_model_unavailable( $model_id ) {
		$model_id = sanitize_text_field( (string) $model_id );
		return in_array( $model_id, self::unavailable_models(), true );
	}

	private static function remember_quota_from_text( $msg ) {
		if ( preg_match( '/[＄$]\s*([\d.]+)/u', (string) $msg, $m ) ) {
			self::remember_quota( (float) $m[1], null, $msg );
		}
	}

	/**
	 * @return array{remaining:?float,required:?float,at:int}|null
	 */
	public static function get_last_quota() {
		$q = get_option( 'agent_wp_gapgpt_last_quota', null );
		return is_array( $q ) ? $q : null;
	}

	/**
	 * @param bool $force اگر true، کش خطای قدیمی را به‌عنوان موجودی فعلی نشان نده.
	 * @return array{ok:bool,credit?:mixed,usage?:mixed,raw?:array,message?:string,baseUrl?:string}
	 */
	public static function fetch_account( $force = false ) {
		$force = (bool) $force;
		$key   = Agent_WP_Models::get_gapgpt_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'کلید GapGPT تنظیم نشده است.', 'agent-wp' ),
				'display' => array(
					'balance'   => '—',
					'label'     => __( 'اعتبار باقی‌مانده', 'agent-wp' ),
					'detail'    => __( 'ابتدا کلید API را ذخیره کنید.', 'agent-wp' ),
					'low'       => true,
					'chargeUrl' => 'https://gapgpt.app/',
				),
			);
		}

		if ( $force ) {
			delete_transient( 'agent_wp_gapgpt_account' );
		}

		$bases = array();
		foreach ( array(
			Agent_WP_Models::get_gapgpt_base(),
			Agent_WP_Models::GAPGPT_BASE_DIRECT,
			Agent_WP_Models::GAPGPT_BASE_CDN,
			'https://gapgpt.app/api/v1',
			'https://api.gapgpt.app/v1',
			'https://api.gapapi.com/v1',
		) as $b ) {
			$b = untrailingslashit( (string) $b );
			if ( $b && ! in_array( $b, $bases, true ) ) {
				$bases[] = $b;
			}
		}

		$credit_suffixes = array(
			'/credit',
			'/credits',
			'/billing/credit',
			'/billing/credits',
			'/dashboard/billing/credit_grants',
			'/user/credit',
			'/wallet',
			'/balance',
			'/account',
			'/me',
		);

		$credit     = null;
		$credit_url = '';
		$tried      = array();
		foreach ( $bases as $base ) {
			foreach ( $credit_suffixes as $suffix ) {
				$url = $base . $suffix;
				if ( isset( $tried[ $url ] ) ) {
					continue;
				}
				$tried[ $url ] = true;
				$try = self::get_json( $url, $key );
				if ( is_wp_error( $try ) || ! is_array( $try ) ) {
					continue;
				}
				// پاسخ خالی/خطادار را رد کن
				if ( isset( $try['error'] ) && empty( $try['credit'] ) && empty( $try['balance'] ) && empty( $try['data'] ) ) {
					continue;
				}
				$num = self::extract_balance_number( $try );
				if ( null === $num && ! self::looks_like_account_payload( $try ) ) {
					continue;
				}
				$credit     = $try;
				$credit_url = $url;
				break 2;
			}
		}

		$usage = null;
		foreach ( $bases as $base ) {
			foreach ( array( '/usage', '/billing/usage' ) as $suffix ) {
				$try = self::get_json( $base . $suffix, $key );
				if ( ! is_wp_error( $try ) && is_array( $try ) ) {
					$usage = $try;
					break 2;
				}
			}
		}

		$out = array(
			'ok'      => true,
			'baseUrl' => $bases[0],
			'mode'    => Agent_WP_Models::get_gapgpt_mode(),
			'credit'  => is_array( $credit ) ? $credit : null,
			'usage'   => is_array( $usage ) ? $usage : null,
			'creditUrl' => $credit_url,
			'display' => array(
				'balance'   => '—',
				'label'     => __( 'اعتبار باقی‌مانده', 'agent-wp' ),
				'detail'    => '',
				'low'       => false,
				'chargeUrl' => 'https://gapgpt.app/',
			),
		);

		$out['display'] = self::format_display( $out['credit'], $out['usage'] );
		$live_num       = self::extract_balance_number( $out['credit'] );

		if ( null !== $live_num ) {
			$out['display']['balance'] = self::format_money( $live_num ) . ' $';
			$out['display']['low']     = $live_num >= 0 && $live_num < 1;
			if ( '' === $out['display']['detail'] ) {
				$out['display']['detail'] = __( 'موجودی زنده از GapGPT خوانده شد.', 'agent-wp' );
			}
			self::remember_quota( $live_num );
			$out['live'] = true;
		} else {
			// API موجودی نداد
			$last = self::get_last_quota();
			$age  = ( $last && ! empty( $last['at'] ) ) ? ( time() - (int) $last['at'] ) : PHP_INT_MAX;

			// رفرش دستی یا گزارش قدیمی‌تر از ۱۰ دقیقه → عدد کهنه را به‌عنوان موجودی فعلی نشان نده
			if ( $force || $age > 10 * MINUTE_IN_SECONDS ) {
				if ( $force ) {
					// کش خطای قدیمی را پاک کن تا بعد از شارژ گیج نکند
					delete_option( 'agent_wp_gapgpt_last_quota' );
				}
				$out['display']['balance'] = '؟';
				$out['display']['low']     = false;
				$out['display']['detail']  = __( 'موجودی زنده از درگاه خوانده نشد. عدد پنل GapGPT را مبنا بگیرید؛ دکمه بروزرسانی را دوباره بزنید.', 'agent-wp' );
				$out['fromLastError']      = false;
				$out['ok']                 = false;
				$out['message']            = $out['display']['detail'];
			} elseif ( $last && isset( $last['remaining'] ) ) {
				$remain = (float) $last['remaining'];
				$out['display']['balance'] = self::format_money( $remain ) . ' $';
				$out['display']['low']     = $remain < 1;
				$out['display']['detail']  = sprintf(
					/* translators: %s: relative time */
					__( '⚠️ موجودی زنده خوانده نشد — آخرین گزارش از خطای مدل · %s پیش (ممکن است بعد از شارژ قدیمی باشد)', 'agent-wp' ),
					human_time_diff( (int) $last['at'], time() )
				);
				if ( ! empty( $last['required'] ) ) {
					$out['display']['detail'] .= ' · ' . sprintf(
						__( 'آخرین درخواست حدود %s $ نیاز داشت', 'agent-wp' ),
						number_format_i18n( (float) $last['required'], 4 )
					);
				}
				$out['fromLastError'] = true;
			} else {
				$out['display']['balance'] = '؟';
				$out['display']['low']     = false;
				$out['display']['detail']  = __( 'موجودی از درگاه خوانده نشد. از پنل GapGPT ببینید یا دکمه بروزرسانی را بزنید.', 'agent-wp' );
			}
		}

		$out['display']['chargeUrl'] = 'https://gapgpt.app/';
		return $out;
	}

	/**
	 * آیا پاسخ شبیه اطلاعات حساب/اعتبار است؟
	 *
	 * @param array $data
	 */
	private static function looks_like_account_payload( array $data ) {
		$keys = array( 'credit', 'credits', 'balance', 'wallet', 'remaining', 'quota', 'total_available', 'hard_limit_usd', 'soft_limit_usd', 'user', 'account' );
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				return true;
			}
		}
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return self::looks_like_account_payload( $data['data'] );
		}
		return false;
	}

	/**
	 * استخراج عدد موجودی از ساختارهای مختلف پاسخ GapGPT.
	 *
	 * @param mixed $data
	 * @return float|null
	 */
	private static function extract_balance_number( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$prefer = array(
			'credit',
			'balance',
			'remaining',
			'remain',
			'amount',
			'credits',
			'wallet',
			'total_available',
			'total_granted',
			'available',
			'quota',
			'hard_limit_usd',
			'soft_limit_usd',
			'data.credit',
			'data.balance',
			'data.remaining',
			'data.total_available',
			'data.wallet',
			'data.available',
			'user.credit',
			'user.balance',
			'account.credit',
			'account.balance',
		);
		$num = self::pick_number( $data, $prefer );
		if ( is_numeric( $num ) ) {
			return (float) $num;
		}
		// جستجوی بازگشتی کلیدهای رایج
		$found = self::find_balance_recursive( $data, 0 );
		return is_numeric( $found ) ? (float) $found : null;
	}

	/**
	 * @param mixed $data
	 * @param int   $depth
	 * @return float|null
	 */
	private static function find_balance_recursive( $data, $depth ) {
		if ( $depth > 4 || ! is_array( $data ) ) {
			return null;
		}
		$want = array( 'credit', 'credits', 'balance', 'remaining', 'remain', 'wallet', 'total_available', 'available', 'quota', 'amount' );
		foreach ( $data as $k => $v ) {
			$key = strtolower( (string) $k );
			if ( in_array( $key, $want, true ) ) {
				if ( is_numeric( $v ) ) {
					return (float) $v;
				}
				if ( is_string( $v ) && preg_match( '/-?\d+(?:\.\d+)?/', $v, $m ) ) {
					return (float) $m[0];
				}
				if ( is_array( $v ) ) {
					$inner = self::find_balance_recursive( $v, $depth + 1 );
					if ( null !== $inner ) {
						return $inner;
					}
				}
			}
		}
		foreach ( $data as $v ) {
			if ( is_array( $v ) ) {
				$inner = self::find_balance_recursive( $v, $depth + 1 );
				if ( null !== $inner ) {
					return $inner;
				}
			}
		}
		return null;
	}

	/**
	 * @param mixed $credit
	 * @param mixed $usage
	 * @return array{balance:string,label:string,detail:string,low:bool,chargeUrl:string}
	 */
	private static function format_display( $credit, $usage ) {
		$balance = self::extract_balance_number( is_array( $credit ) ? $credit : array() );
		$total_cost = self::pick_string(
			$usage,
			array( 'total_cost', 'cost', 'spent', 'data.total_cost', 'data.cost' )
		);

		$balance_text = '—';
		$low          = false;
		if ( null !== $balance ) {
			$balance_text = self::format_money( $balance ) . ' $';
			$low          = $balance >= 0 && $balance < 1;
		}

		$detail = '';
		if ( $total_cost ) {
			$detail = sprintf( __( 'مصرف: %s', 'agent-wp' ), $total_cost );
		}

		return array(
			'balance'   => $balance_text,
			'label'     => __( 'اعتبار باقی‌مانده', 'agent-wp' ),
			'detail'    => $detail,
			'low'       => $low,
			'chargeUrl' => 'https://gapgpt.app/',
		);
	}

	private static function format_money( $num ) {
		if ( abs( $num ) >= 1000 ) {
			return number_format_i18n( $num, 0 );
		}
		if ( abs( $num ) < 0.1 ) {
			return number_format_i18n( $num, 4 );
		}
		return number_format_i18n( $num, 2 );
	}

	/**
	 * @param mixed $data
	 * @param string[] $paths
	 * @return mixed|null
	 */
	private static function pick_number( $data, array $paths ) {
		$val = self::pick_value( $data, $paths );
		if ( null === $val ) {
			return null;
		}
		if ( is_numeric( $val ) ) {
			return 0 + $val;
		}
		if ( is_string( $val ) && preg_match( '/-?\d+(?:\.\d+)?/', $val, $m ) ) {
			return (float) $m[0];
		}
		return $val;
	}

	private static function pick_string( $data, array $paths ) {
		$val = self::pick_value( $data, $paths );
		return ( null === $val || is_array( $val ) ) ? '' : (string) $val;
	}

	/**
	 * @param mixed $data
	 * @param string[] $paths
	 * @return mixed|null
	 */
	private static function pick_value( $data, array $paths ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( $paths as $path ) {
			$cur  = $data;
			$ok   = true;
			$parts = explode( '.', $path );
			foreach ( $parts as $part ) {
				if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
					$ok = false;
					break;
				}
				$cur = $cur[ $part ];
			}
			if ( $ok && null !== $cur && '' !== $cur ) {
				return $cur;
			}
		}
		return null;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function get_json( $url, $api_key ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'gapgpt_http', $msg );
		}
		return is_array( $data ) ? $data : array( 'raw' => $raw );
	}
}
