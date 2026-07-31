<?php
/**
 * نوشتن/ویرایش فایل قالب یا افزونه — اجباری تأیید + نقطه بازگشت + rollback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Write_File implements Agent_WP_Tool_Interface {

	public function id() {
		return 'write_file';
	}

	public function label() {
		return __( 'نوشتن فایل', 'agent-wp' );
	}

	public function description() {
		return 'Create or overwrite a code/text file under theme/, parent/, themes/, plugin/, mu-plugin/, or workspace/. Theme/plugin writes ALWAYS need user confirm in UI; a restore point is saved. Never touch wp-config or WordPress core.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'      => array(
					'type'        => 'string',
					'description' => 'e.g. theme/style.css, plugin/woocommerce/includes/foo.php, themes/twentytwentyfour/functions.php, workspace/custom.css',
				),
				'content'   => array( 'type' => 'string', 'description' => 'Full file contents' ),
				'confirmed' => array( 'type' => 'boolean', 'description' => 'Must be true after user confirms risky writes' ),
			),
			'required'   => array( 'path', 'content' ),
		);
	}

	public function can_run( array $args ) {
		return ! empty( $args['path'] ) && isset( $args['content'] ) && current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		$path = (string) ( $args['path'] ?? '' );
		$read = Agent_WP_Fs::read( $path );
		if ( is_wp_error( $read ) ) {
			return array(
				'path'    => $path,
				'existed' => false,
				'content' => null,
				'risk'    => Agent_WP_Fs::write_risk( $path ),
			);
		}
		return array(
			'path'    => $read['path'],
			'existed' => true,
			'content' => $read['content'],
			'risk'    => Agent_WP_Fs::write_risk( $read['path'] ),
			'size'    => $read['size'],
		);
	}

	public function run( array $args ) {
		$path           = (string) ( $args['path'] ?? '' );
		$existing       = Agent_WP_Fs::read( $path );
		$will_overwrite = ! is_wp_error( $existing );
		$risk           = Agent_WP_Fs::write_risk( $path );
		$needs_confirm  = Agent_WP_Fs::write_needs_confirm( $path ) || $will_overwrite;

		if ( $needs_confirm && empty( $args['confirmed'] ) ) {
			$virt = $will_overwrite ? $existing['path'] : $path;
			$warning = Agent_WP_Fs::write_warning( $virt, $will_overwrite );
			$token   = Agent_WP_Pending::store(
				'write_file',
				array_merge( $args, array( 'confirmed' => true ) ),
				array(
					'path'     => $virt,
					'bytes'    => $will_overwrite ? (int) $existing['size'] : 0,
					'action'   => $will_overwrite ? 'overwrite' : 'create',
					'risk'     => $risk,
					'warning'  => $warning,
					'highRisk' => in_array( $risk, array( 'theme', 'plugin' ), true ),
				)
			);
			return Agent_WP_Tool_Result::success(
				__( 'برای ادامه تأیید کنید.', 'agent-wp' ),
				array(
					'needsConfirm' => true,
					'confirmToken' => $token,
					'path'         => $virt,
					'risk'         => $risk,
					'warning'      => $warning,
					'highRisk'     => in_array( $risk, array( 'theme', 'plugin' ), true ),
					'action'       => $will_overwrite ? 'overwrite' : 'create',
				)
			);
		}

		$result = Agent_WP_Fs::write( $args['path'], $args['content'], true );
		if ( is_wp_error( $result ) ) {
			return Agent_WP_Tool_Result::error( $result->get_error_message() );
		}

		$msg = sprintf(
			__( 'فایل %s %s (%d بایت).', 'agent-wp' ),
			$result['path'],
			! empty( $result['created'] ) ? __( 'ساخته شد', 'agent-wp' ) : __( 'به‌روز شد', 'agent-wp' ),
			$result['bytes']
		);
		if ( ! empty( $result['restorePointId'] ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: restore point id */
				__( 'نقطه بازگشت: %s — از تاریخچه اکشن می‌توانید برگردانید.', 'agent-wp' ),
				$result['restorePointId']
			);
		}

		unset( $result['previous'] );
		return Agent_WP_Tool_Result::success( $msg, $result );
	}

	public function rollback( $log_id ) {
		$row = Agent_WP_Action_Log::get( $log_id );
		if ( ! $row ) {
			return Agent_WP_Tool_Result::error( __( 'لاگ یافت نشد.', 'agent-wp' ) );
		}
		$before = json_decode( (string) $row['before_json'], true );
		$after  = json_decode( (string) $row['after_json'], true );
		$path   = isset( $before['path'] ) ? $before['path'] : ( isset( $after['path'] ) ? $after['path'] : '' );
		if ( '' === $path ) {
			return Agent_WP_Tool_Result::error( __( 'مسیر در لاگ نیست.', 'agent-wp' ) );
		}

		if ( empty( $before['existed'] ) ) {
			$abs = Agent_WP_Fs::resolve( $path, true, true );
			if ( ! is_wp_error( $abs ) && is_file( $abs ) ) {
				@unlink( $abs );
			}
			return Agent_WP_Tool_Result::success( __( 'فایل جدید حذف شد (rollback).', 'agent-wp' ), array( 'path' => $path ) );
		}

		// بدون ساخت نقطه بازگشت دوباره هنگام برگشت
		$write = Agent_WP_Fs::write( $path, (string) $before['content'], false );
		if ( is_wp_error( $write ) ) {
			return Agent_WP_Tool_Result::error( $write->get_error_message() );
		}
		return Agent_WP_Tool_Result::success(
			__( 'محتوای قبلی فایل بازگردانده شد (نقطه بازگشت).', 'agent-wp' ),
			array(
				'path' => $path,
				'risk' => isset( $before['risk'] ) ? $before['risk'] : Agent_WP_Fs::write_risk( $path ),
			)
		);
	}
}
