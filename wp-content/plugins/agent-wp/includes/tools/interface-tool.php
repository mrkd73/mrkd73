<?php
/**
 * قرارداد Tool — هر قابلیت آینده یک Tool است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Agent_WP_Tool_Interface {

	public function id();

	public function label();

	/**
	 * آیا با آرگومان‌های داده‌شده قابل اجراست؟
	 */
	public function can_run( array $args );

	/**
	 * اجرا + ثبت لاگ داخلی توسط Registry.
	 *
	 * @return Agent_WP_Tool_Result
	 */
	public function run( array $args );

	/**
	 * برگشت به وضعیت before ذخیره‌شده در لاگ.
	 *
	 * @return Agent_WP_Tool_Result
	 */
	public function rollback( $log_id );
}
