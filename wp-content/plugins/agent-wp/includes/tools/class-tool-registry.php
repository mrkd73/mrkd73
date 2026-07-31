<?php
/**
 * رجیستری Toolها + اجرای امن با لاگ.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Registry {

	/** @var self|null */
	private static $instance = null;

	/** @var Agent_WP_Tool_Interface[] */
	private $tools = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register( Agent_WP_Tool_Interface $tool ) {
		$this->tools[ $tool->id() ] = $tool;
	}

	public function get( $id ) {
		$id = sanitize_key( $id );
		return isset( $this->tools[ $id ] ) ? $this->tools[ $id ] : null;
	}

	public function all_public() {
		$out = array();
		foreach ( $this->tools as $tool ) {
			$out[] = array(
				'id'          => $tool->id(),
				'label'       => $tool->label(),
				'description' => method_exists( $tool, 'description' ) ? $tool->description() : $tool->label(),
			);
		}
		return $out;
	}

	/**
	 * تعاریف OpenAI-compatible function calling برای GapGPT.
	 *
	 * @return array<int,array>
	 */
	public function openai_tools() {
		$out = array();
		foreach ( $this->tools as $tool ) {
			if ( 'ping' === $tool->id() ) {
				continue;
			}
			$params = method_exists( $tool, 'schema' )
				? $tool->schema()
				: array(
					'type'       => 'object',
					'properties' => new stdClass(),
				);
			// JSON: properties خالی باید {} باشد نه []
			if ( isset( $params['properties'] ) && is_array( $params['properties'] ) && ! $params['properties'] ) {
				$params['properties'] = new stdClass();
			}
			$out[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool->id(),
					'description' => method_exists( $tool, 'description' ) ? $tool->description() : $tool->label(),
					'parameters'   => $params,
				),
			);
		}
		return $out;
	}

	/**
	 * اجرای Tool با لاگ قبل/بعد برای rollback بعدی.
	 */
	public function execute( $tool_id, array $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			update_option( 'agent_wp_blocked_attempts', (int) get_option( 'agent_wp_blocked_attempts', 0 ) + 1, false );
			Agent_WP_Debug::log( 'tool_blocked_cap', array( 'tool' => $tool_id ) );
			return Agent_WP_Tool_Result::error( __( 'دسترسی کافی نیست.', 'agent-wp' ) );
		}

		$tool = $this->get( $tool_id );
		if ( ! $tool ) {
			return Agent_WP_Tool_Result::error( __( 'Tool یافت نشد.', 'agent-wp' ) );
		}

		if ( ! $tool->can_run( $args ) ) {
			update_option( 'agent_wp_blocked_attempts', (int) get_option( 'agent_wp_blocked_attempts', 0 ) + 1, false );
			Agent_WP_Debug::log( 'tool_blocked_can_run', array( 'tool' => $tool_id ) );
			return Agent_WP_Tool_Result::error( __( 'این Tool با آرگومان‌های فعلی قابل اجرا نیست.', 'agent-wp' ) );
		}

		$before = array();
		if ( method_exists( $tool, 'snapshot_before' ) ) {
			$before = (array) $tool->snapshot_before( $args );
		}

		$log_id = Agent_WP_Action_Log::start( $tool->id(), 'run', $args, $before );

		try {
			$result = $tool->run( $args );
			if ( ! $result instanceof Agent_WP_Tool_Result ) {
				Agent_WP_Action_Log::fail( $log_id, 'invalid_result' );
				Agent_WP_Debug::log( 'tool_invalid_result', array( 'tool' => $tool_id ) );
				return Agent_WP_Tool_Result::error( __( 'نتیجه Tool نامعتبر است.', 'agent-wp' ), array(), $log_id );
			}

			$result->log_id = $log_id;
			if ( $result->ok ) {
				if ( ! empty( $result->data['needsConfirm'] ) ) {
					Agent_WP_Action_Log::fail( $log_id, 'awaiting_confirm' );
				} else {
					Agent_WP_Action_Log::complete( $log_id, $result->data );
				}
			} else {
				Agent_WP_Action_Log::fail( $log_id, $result->message );
				Agent_WP_Debug::log( 'tool_fail', array( 'tool' => $tool_id, 'msg' => $result->message ) );
			}
			return $result;
		} catch ( Exception $e ) {
			Agent_WP_Action_Log::fail( $log_id, $e->getMessage() );
			Agent_WP_Debug::log( 'tool_exception', array( 'tool' => $tool_id, 'msg' => $e->getMessage() ) );
			return Agent_WP_Tool_Result::error( $e->getMessage(), array(), $log_id );
		}
	}

	public function rollback( $log_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Agent_WP_Tool_Result::error( __( 'دسترسی کافی نیست.', 'agent-wp' ) );
		}

		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		if ( 'rolled_back' === $row['status'] ) {
			return Agent_WP_Tool_Result::error( __( 'قبلاً برگشت داده شده.', 'agent-wp' ) );
		}
		if ( 'done' !== $row['status'] ) {
			return Agent_WP_Tool_Result::error( __( 'فقط اکشن موفق قابل برگشت است.', 'agent-wp' ) );
		}

		$tool = $this->get( $row['tool_id'] );
		if ( ! $tool ) {
			return Agent_WP_Tool_Result::error( __( 'Tool مربوط به این لاگ موجود نیست.', 'agent-wp' ) );
		}

		$result = $tool->rollback( (int) $log_id );
		if ( $result->ok ) {
			Agent_WP_Action_Log::mark_rolled_back( (int) $log_id );
		}
		return $result;
	}
}
