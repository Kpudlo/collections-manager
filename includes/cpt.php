<?php
/**
 * Collection post type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'collections_manager_register_post_type' );
function collections_manager_register_post_type() {
	register_post_type(
		COLLECTIONS_MANAGER_POST_TYPE,
		array(
			'labels'              => array(
				'name'          => __( 'Collections', 'collections-manager' ),
				'singular_name' => __( 'Collection', 'collections-manager' ),
				'add_new_item'  => __( 'Add New Collection', 'collections-manager' ),
				'edit_item'     => __( 'Edit Collection', 'collections-manager' ),
				'search_items'  => __( 'Search Collections', 'collections-manager' ),
				'not_found'     => __( 'No collections found.', 'collections-manager' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'excerpt', 'thumbnail', 'page-attributes' ),
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
		)
	);
}
