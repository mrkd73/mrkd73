<?php
/**
 * لاگ اکشن‌ها برای شفافیت و rollback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Action_Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_wp_action_log';
	}

	public static function start( $tool_id, $action_name, array $args = array(), array $before = array() ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'user_id'        => get_current_user_id(),
				'tool_id'        => sanitize_key( $tool_id ),
				'action_name'    => sanitize_key( $action_name ),
				'status'         => 'running',
				'args_json'      => wp_json_encode( $args ),
				'before_json'    => wp_json_encode( $before ),
				'after_json'     => '',
				'error_message'  => '',
				'created_at'     => current_time( 'mysql' ),
				'rolled_back_at' => '0000-00-00 00:00:00',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function complete( $log_id, array $after = array() ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'done',
				'after_json' => wp_json_encode( $after ),
			),
			array( 'id' => (int) $log_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function fail( $log_id, $message ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'        => 'failed',
				'error_message' => sanitize_text_field( $message ),
			),
			array( 'id' => (int) $log_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function mark_rolled_back( $log_id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'         => 'rolled_back',
				'rolled_back_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $log_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function tool_stats( $limit = 12 ) {
		global $wpdb;
		$limit = max( 1, min( 30, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT tool_id, status, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY tool_id, status ORDER BY c DESC LIMIT %d',
				$limit * 3
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$id = $r['tool_id'];
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = array(
						'tool'   => $id,
						'done'   => 0,
						'failed' => 0,
						'total'  => 0,
					);
				}
				$c = (int) $r['c'];
				$out[ $id ]['total'] += $c;
				if ( 'done' === $r['status'] ) {
					$out[ $id ]['done'] += $c;
				} elseif ( 'failed' === $r['status'] || 'awaiting_confirm' === $r['status'] ) {
					$out[ $id ]['failed'] += $c;
				}
			}
		}
		$out = array_values( $out );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['total'] - $a['total'];
			}
		);
		return array_slice( $out, 0, $limit );
	}

	public static function get( $log_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $log_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = $wpdb->get_results( "SELECT id, user_id, tool_id, action_name, status, created_at, rolled_back_at FROM " . self::table() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
