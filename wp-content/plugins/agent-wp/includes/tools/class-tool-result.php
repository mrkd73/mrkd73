<?php
/**
 * نتیجه استاندارد اجرای Tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Result {

	public $ok;
	public $message;
	public $data;
	public $log_id;

	public function __construct( $ok, $message = '', array $data = array(), $log_id = 0 ) {
		$this->ok      = (bool) $ok;
		$this->message = (string) $message;
		$this->data    = $data;
		$this->log_id  = (int) $log_id;
	}

	public static function success( $message = '', array $data = array(), $log_id = 0 ) {
		return new self( true, $message, $data, $log_id );
	}

	public static function error( $message, array $data = array(), $log_id = 0 ) {
		return new self( false, $message, $data, $log_id );
	}

	public function to_array() {
		return array(
			'ok'      => $this->ok,
			'message' => $this->message,
			'data'    => $this->data,
			'logId'   => $this->log_id,
		);
	}
}
