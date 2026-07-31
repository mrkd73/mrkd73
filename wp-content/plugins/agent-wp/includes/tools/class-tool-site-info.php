<?php
/**
 * Tool اطلاعات سایت وردپرس — فقط خواندنی؛ rollback لازم نیست.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_WP_Tool_Site_Info implements Agent_WP_Tool_Interface {

	public function id() {
		return 'site_info';
	}

	public function label() {
		return __( 'اطلاعات سایت', 'agent-wp' );
	}

	public function description() {
		return 'Get WordPress site info: name, URL, theme, WP version.';
	}

	public function schema() {
		return array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);
	}

	public function can_run( array $args ) {
		return current_user_can( 'manage_options' );
	}

	public function snapshot_before( array $args ) {
		return array();
	}

	public function run( array $args ) {
		$theme = wp_get_theme();
		$data  = array(
			'name'        => (string) get_bloginfo( 'name' ),
			'description' => (string) get_bloginfo( 'description' ),
			'url'         => (string) home_url( '/' ),
			'adminUrl'    => (string) admin_url(),
			'language'    => (string) get_bloginfo( 'language' ),
			'wpVersion'   => (string) get_bloginfo( 'version' ),
			'theme'       => $theme ? $theme->get( 'Name' ) : '',
			'themeVersion'=> $theme ? $theme->get( 'Version' ) : '',
			'isMultisite' => is_multisite(),
		);

		$msg = sprintf(
			"نام: %s\nآدرس: %s\nقالب: %s\nنسخه وردپرس: %s",
			$data['name'],
			$data['url'],
			$data['theme'],
			$data['wpVersion']
		);

		return Agent_WP_Tool_Result::success( $msg, $data );
	}

	public function rollback( $log_id ) {
		return Agent_WP_Tool_Result::success( __( 'این اکشن فقط خواندنی است.', 'agent-wp' ) );
	}
}
