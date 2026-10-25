<?php
/**
 * Lightweight collection list for the block editor.
 *
 * @package CollectionsManager
 */

namespace CollectionsManager\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Published collection ids and titles for the browser block inspector.
 */
class Picker {

	/**
	 * Register the route and editor preload.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_filter( 'block_editor_rest_api_preload_paths', array( __CLASS__, 'preload' ) );
	}

	/**
	 * GET /collections-manager/v1/picker.
	 */
	public static function register_route() {
		register_rest_route(
			'collections-manager/v1',
			'/picker',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'items' ),
				'permission_callback' => array( __CLASS__, 'can_pick' ),
			)
		);
	}

	/**
	 * Whether the current user can choose collections in the editor.
	 *
	 * @return bool
	 */
	public static function can_pick() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Published collections, in dashboard order.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function items() {
		$posts = get_posts(
			array(
				'post_type'              => COLLECTIONS_MANAGER_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
			);
		}

		return $items;
	}

	/**
	 * Ship the list with the editor page so picking does not wait on another request.
	 *
	 * @param array|mixed $paths Preload paths.
	 * @return array
	 */
	public static function preload( $paths ) {
		if ( ! is_array( $paths ) ) {
			$paths = array();
		}

		$paths[] = '/collections-manager/v1/picker';
		return $paths;
	}
}
