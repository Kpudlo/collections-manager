<?php
/**
 * YouTube API key settings.
 *
 * @package CollectionsManager
 */

namespace CollectionsManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CollectionsManager\YouTube\Client;

/**
 * Settings tab for YouTube credentials.
 */
class SettingsPage {

	const OPTION = 'collections_manager_youtube_api_key';

	/**
	 * Register the save handler.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Persist the API key.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['collections_manager_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change Collections settings.', 'collections-manager' ) );
		}

		check_admin_referer( 'collections_manager_save_settings' );

		if ( defined( 'COLLECTIONS_MANAGER_YOUTUBE_API_KEY' ) && COLLECTIONS_MANAGER_YOUTUBE_API_KEY ) {
			wp_safe_redirect( self::url( array( 'updated' => 'constant' ) ) );
			exit;
		}

		$key = isset( $_POST['youtube_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['youtube_api_key'] ) ) : '';
		if ( '' === $key ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $key, false );
		}

		wp_safe_redirect( self::url( array( 'updated' => 'settings' ) ) );
		exit;
	}

	/**
	 * Settings tab URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'collections_manager',
					'tab'  => 'settings',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Output the Settings tab.
	 */
	public static function render() {
		$locked = defined( 'COLLECTIONS_MANAGER_YOUTUBE_API_KEY' ) && COLLECTIONS_MANAGER_YOUTUBE_API_KEY;
		$stored = (string) get_option( self::OPTION, '' );
		$has    = Client::has_api_key();
		$view   = COLLECTIONS_MANAGER_PATH . 'includes/Admin/views/settings-tab.php';
		if ( is_readable( $view ) ) {
			require $view;
		}
	}
}
