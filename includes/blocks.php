<?php
/**
 * Register Collections Manager blocks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'collections_manager_register_blocks' );
function collections_manager_register_blocks() {
	$block_dir = COLLECTIONS_MANAGER_PATH . 'build/browser';
	if ( file_exists( $block_dir . '/block.json' ) ) {
		register_block_type( $block_dir );
	}
}
