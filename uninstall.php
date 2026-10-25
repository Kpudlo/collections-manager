<?php
/**
 * Uninstall: drop plugin options only. Collections (posts/meta) stay.
 *
 * @package CollectionsManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'collections_manager_global_styles' );
delete_option( 'collections_manager_youtube_api_key' );
delete_option( 'collections_manager_license_key' );
