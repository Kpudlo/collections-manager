<?php
/**
 * Rough dashboard for creating and ordering collections.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'collections_manager_register_menu' );
function collections_manager_register_menu() {
	add_menu_page(
		__( 'Collections', 'collections-manager' ),
		__( 'Collections', 'collections-manager' ),
		'edit_posts',
		'collections_manager',
		'collections_manager_render_dashboard_page',
		'dashicons-index-card',
		29
	);
	add_submenu_page(
		'collections_manager',
		__( 'Collections', 'collections-manager' ),
		__( 'Dashboard', 'collections-manager' ),
		'edit_posts',
		'collections_manager',
		'collections_manager_render_dashboard_page'
	);
}

add_action( 'admin_init', 'collections_manager_handle_dashboard_actions' );
function collections_manager_handle_dashboard_actions() {
	if ( ! is_admin() ) {
		return;
	}

	if ( isset( $_POST['collections_manager_save'] ) ) {
		collections_manager_save_collection();
	}

	if ( isset( $_GET['collections_manager_trash'] ) ) {
		collections_manager_trash_collection();
	}
}

add_action( 'wp_ajax_collections_manager_search_posts', 'collections_manager_ajax_search_posts' );
function collections_manager_ajax_search_posts() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$types     = collections_manager_collectable_post_types();
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
	if ( ! isset( $types[ $post_type ] ) ) {
		$post_type = isset( $types['post'] ) ? 'post' : (string) array_key_first( $types );
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$query  = new WP_Query(
		array(
			'post_type'      => $post_type ? $post_type : 'post',
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => 12,
			'no_found_rows'  => true,
		)
	);

	$results = array();
	foreach ( $query->posts as $post ) {
		$results[] = array(
			'id'    => $post->ID,
			'title' => get_the_title( $post ),
			'date'  => get_the_date( '', $post ),
			'image' => collections_manager_card_image_url( $post->ID, 'thumbnail' ),
		);
	}

	wp_send_json_success( $results );
}

add_action( 'wp_ajax_collections_manager_query_terms', 'collections_manager_ajax_query_terms' );
function collections_manager_ajax_query_terms() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
	$taxonomy  = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
	$taxes     = collections_manager_taxonomies_for_post_type( $post_type );
	if ( ! $taxonomy || ! isset( $taxes[ $taxonomy ] ) ) {
		wp_send_json_success( array() );
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		wp_send_json_success( array() );
	}

	$results = array();
	foreach ( $terms as $term ) {
		$results[] = array(
			'id'    => (int) $term->term_id,
			'name'  => $term->name,
			'count' => (int) $term->count,
		);
	}

	wp_send_json_success( $results );
}

add_action( 'wp_ajax_collections_manager_youtube_resolve_channel', 'collections_manager_ajax_youtube_resolve_channel' );
function collections_manager_ajax_youtube_resolve_channel() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$raw    = isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '';
	$result = \CollectionsManager\YouTube\Client::resolve_channel( $raw );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}

add_action( 'wp_ajax_collections_manager_youtube_channel_videos', 'collections_manager_ajax_youtube_channel_videos' );
function collections_manager_ajax_youtube_channel_videos() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$channel_id = isset( $_GET['channel_id'] ) ? sanitize_text_field( wp_unslash( $_GET['channel_id'] ) ) : '';
	$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$token      = isset( $_GET['page_token'] ) ? sanitize_text_field( wp_unslash( $_GET['page_token'] ) ) : '';
	$result     = \CollectionsManager\YouTube\Client::channel_videos( $channel_id, $search, $token );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}

add_action( 'wp_ajax_collections_manager_youtube_playlist_preview', 'collections_manager_ajax_youtube_playlist_preview' );
function collections_manager_ajax_youtube_playlist_preview() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$playlist = isset( $_GET['playlist'] ) ? sanitize_text_field( wp_unslash( $_GET['playlist'] ) ) : '';
	$limit    = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 12;
	$result   = \CollectionsManager\YouTube\Client::playlist_videos( $playlist, $limit );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'count' => count( $result ),
			'title' => isset( $result[0]['title'] ) ? $result[0]['title'] : '',
		)
	);
}

add_action( 'wp_ajax_collections_manager_youtube_channel_playlists', 'collections_manager_ajax_youtube_channel_playlists' );
function collections_manager_ajax_youtube_channel_playlists() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$channel_id = isset( $_GET['channel_id'] ) ? sanitize_text_field( wp_unslash( $_GET['channel_id'] ) ) : '';
	$result     = \CollectionsManager\YouTube\Client::channel_playlists( $channel_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'playlists' => $result ) );
}

add_action( 'wp_ajax_collections_manager_youtube_latest_preview', 'collections_manager_ajax_youtube_latest_preview' );
function collections_manager_ajax_youtube_latest_preview() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$raw      = isset( $_GET['channels'] ) ? sanitize_text_field( wp_unslash( $_GET['channels'] ) ) : '';
	$channels = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $channel_id ) {
		$channels[] = array( 'id' => $channel_id );
	}
	$limit  = isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 12;
	$result = \CollectionsManager\YouTube\Client::latest_from_channels( $channels, $limit );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'count' => count( $result ),
			'title' => isset( $result[0]['title'] ) ? $result[0]['title'] : '',
		)
	);
}

add_action( 'wp_ajax_collections_manager_feed_discover', 'collections_manager_ajax_feed_discover' );
function collections_manager_ajax_feed_discover() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$url    = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ), array( 'http', 'https' ) ) : '';
	$result = \CollectionsManager\Feed\Client::discover( $url );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}

add_action( 'wp_ajax_collections_manager_feed_preview', 'collections_manager_ajax_feed_preview' );
function collections_manager_ajax_feed_preview() {
	check_ajax_referer( 'collections_manager_admin' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$feed   = array(
		'url'           => isset( $_GET['url'] ) ? wp_unslash( $_GET['url'] ) : '',
		'kind'          => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'auto',
		'rest_base'     => isset( $_GET['rest_base'] ) ? sanitize_key( wp_unslash( $_GET['rest_base'] ) ) : 'posts',
		'category_id'   => isset( $_GET['category_id'] ) ? absint( wp_unslash( $_GET['category_id'] ) ) : 0,
		'category_slug' => isset( $_GET['category_slug'] ) ? sanitize_title( wp_unslash( $_GET['category_slug'] ) ) : '',
		'limit'         => isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 12,
	);
	$result = \CollectionsManager\Feed\Client::fetch( collections_manager_sanitize_feed( $feed ) );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'count' => count( $result ),
			'title' => isset( $result[0]['title'] ) ? $result[0]['title'] : '',
		)
	);
}

add_action( 'admin_enqueue_scripts', 'collections_manager_enqueue_dashboard_assets' );
function collections_manager_enqueue_dashboard_assets( $hook ) {
	if ( 'toplevel_page_collections_manager' !== $hook ) {
		return;
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

	if ( 'styles' === $tab ) {
		wp_enqueue_style(
			'collections-manager-browser',
			COLLECTIONS_MANAGER_URL . 'build/browser/style-index.css',
			array(),
			COLLECTIONS_MANAGER_VERSION
		);
		wp_enqueue_style(
			'collections-manager-styles-admin',
			COLLECTIONS_MANAGER_URL . 'assets/styles-admin.css',
			array( 'collections-manager-browser' ),
			COLLECTIONS_MANAGER_VERSION
		);
		wp_enqueue_script(
			'collections-manager-styles-admin',
			COLLECTIONS_MANAGER_URL . 'assets/styles-admin.js',
			array(),
			COLLECTIONS_MANAGER_VERSION,
			true
		);
		return;
	}

	if ( 'settings' === $tab ) {
		wp_enqueue_style(
			'collections-manager-dashboard',
			COLLECTIONS_MANAGER_URL . 'assets/dashboard.css',
			array(),
			COLLECTIONS_MANAGER_VERSION
		);
		return;
	}

	if ( 'collections' !== $tab ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style(
		'collections-manager-dashboard',
		COLLECTIONS_MANAGER_URL . 'assets/dashboard.css',
		array(),
		COLLECTIONS_MANAGER_VERSION
	);
	wp_enqueue_script(
		'collections-manager-dashboard',
		COLLECTIONS_MANAGER_URL . 'assets/dashboard.js',
		array( 'jquery' ),
		COLLECTIONS_MANAGER_VERSION,
		true
	);
	$post_types = array();
	foreach ( collections_manager_collectable_post_types() as $slug => $label ) {
		$post_types[ $slug ] = array(
			'label'      => $label,
			'taxonomies' => collections_manager_taxonomies_for_post_type( $slug ),
		);
	}

	wp_localize_script(
		'collections-manager-dashboard',
		'collectionsManagerDashboard',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'collections_manager_admin' ),
			'postTypes' => $post_types,
			'hasApiKey' => \CollectionsManager\YouTube\Client::has_api_key(),
			'i18n'      => array(
				'noVideos'     => __( 'No videos found for that channel.', 'collections-manager' ),
				'addChannel'   => __( 'Add a channel first.', 'collections-manager' ),
				'previewOk'      => __( 'Found %d videos. Newest first.', 'collections-manager' ),
				'previewEmpty'   => __( 'No public videos found.', 'collections-manager' ),
				'playlistsEmpty' => __( 'No public playlists on that channel. Paste a playlist URL instead.', 'collections-manager' ),
				'feedOk'       => __( 'Found %d items.', 'collections-manager' ),
				'feedEmpty'    => __( 'No items found at that URL.', 'collections-manager' ),
				'feedRest'     => __( 'WordPress site detected. Choose a post type or category, then check the feed.', 'collections-manager' ),
				'feedRss'      => __( 'XML feed detected. Check the feed to preview items.', 'collections-manager' ),
			),
		)
	);
}

function collections_manager_save_collection() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage collections.', 'collections-manager' ) );
	}

	check_admin_referer( 'collections_manager_save' );

	$input = isset( $_POST['collection'] ) ? wp_unslash( $_POST['collection'] ) : array();
	$input = is_array( $input ) ? $input : array();

	$title       = sanitize_text_field( $input['title'] ?? '' );
	$description = sanitize_textarea_field( $input['description'] ?? '' );
	$image       = absint( $input['image_id'] ?? 0 );
	$order       = isset( $input['menu_order'] ) ? (int) $input['menu_order'] : 0;
	$post_ids      = array_map( 'absint', (array) ( $input['post_ids'] ?? array() ) );
	$allowed_src   = array( 'manual', 'query', 'youtube', 'feed' );
	$source        = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : 'manual';
	if ( ! in_array( $source, $allowed_src, true ) ) {
		$source = 'manual';
	}
	$query         = isset( $input['query'] ) ? $input['query'] : array();
	$youtube       = isset( $input['youtube'] ) ? $input['youtube'] : array();
	$feed          = isset( $input['feed'] ) ? $input['feed'] : array();
	$collection_id = absint( $input['id'] ?? 0 );

	if ( '' === $title ) {
		wp_safe_redirect( collections_manager_dashboard_url( array( 'tab' => 'collections', 'error' => 'missing-title', 'collection' => $collection_id ) ) );
		exit;
	}

	$postarr = array(
		'post_type'    => COLLECTIONS_MANAGER_POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_excerpt' => $description,
		'menu_order'   => $order,
	);

	if ( $collection_id ) {
		$existing = get_post( $collection_id );
		if ( ! $existing || COLLECTIONS_MANAGER_POST_TYPE !== $existing->post_type || ! current_user_can( 'edit_post', $collection_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this collection.', 'collections-manager' ) );
		}
		$postarr['ID'] = $collection_id;
	}

	$saved_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $saved_id ) || ! $saved_id ) {
		wp_safe_redirect( collections_manager_dashboard_url( array( 'tab' => 'collections', 'error' => 'save-failed', 'collection' => $collection_id ) ) );
		exit;
	}

	collections_manager_save_post_ids( $saved_id, $post_ids );
	update_post_meta( $saved_id, COLLECTIONS_MANAGER_SOURCE_META, $source );
	collections_manager_save_query( $saved_id, $query );
	collections_manager_save_youtube( $saved_id, $youtube );
	collections_manager_save_feed( $saved_id, $feed );

	if ( $image && wp_attachment_is_image( $image ) ) {
		set_post_thumbnail( $saved_id, $image );
	} else {
		delete_post_thumbnail( $saved_id );
	}

	wp_safe_redirect( collections_manager_dashboard_url( array( 'tab' => 'collections', 'updated' => 'saved' ) ) );
	exit;
}

function collections_manager_trash_collection() {
	$id = absint( wp_unslash( $_GET['collections_manager_trash'] ) );
	check_admin_referer( 'collections_manager_trash_' . $id );

	if ( ! $id || ! current_user_can( 'delete_post', $id ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to delete this collection.', 'collections-manager' ) );
	}

	$post = get_post( $id );
	if ( $post && COLLECTIONS_MANAGER_POST_TYPE === $post->post_type ) {
		wp_trash_post( $id );
	}

	wp_safe_redirect( collections_manager_dashboard_url( array( 'tab' => 'collections', 'updated' => 'trashed' ) ) );
	exit;
}

function collections_manager_render_dashboard_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'collections-manager' ) );
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
	if ( ! in_array( $tab, array( 'overview', 'collections', 'styles', 'settings', 'how-to-use' ), true ) ) {
		$tab = 'overview';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Collections Manager', 'collections-manager' ) . '</h1>';
	collections_manager_render_notices();
	echo '<h2 class="nav-tab-wrapper">';
	$tabs = array(
		'overview'     => __( 'Overview', 'collections-manager' ),
		'collections'  => __( 'Collections', 'collections-manager' ),
		'styles'       => __( 'Styles', 'collections-manager' ),
		'settings'     => __( 'Settings', 'collections-manager' ),
		'how-to-use'   => __( 'How to Use', 'collections-manager' ),
	);
	foreach ( $tabs as $id => $label ) {
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( collections_manager_dashboard_url( array( 'tab' => $id ) ) ),
			$tab === $id ? ' nav-tab-active' : '',
			esc_html( $label )
		);
	}
	echo '</h2>';

	if ( 'collections' === $tab ) {
		collections_manager_render_collections_tab();
	} elseif ( 'styles' === $tab ) {
		\CollectionsManager\Admin\StylesPage::render();
	} elseif ( 'settings' === $tab ) {
		\CollectionsManager\Admin\SettingsPage::render();
	} elseif ( 'how-to-use' === $tab ) {
		collections_manager_render_how_to_use_tab();
	} else {
		collections_manager_render_overview_tab();
	}

	echo '</div>';
}

function collections_manager_render_notices() {
	$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
	$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

	$messages = array(
		'saved'     => __( 'Collection saved.', 'collections-manager' ),
		'trashed'   => __( 'Collection moved to trash.', 'collections-manager' ),
		'settings'  => __( 'Settings saved.', 'collections-manager' ),
		'constant'  => __( 'Settings were not changed because COLLECTIONS_MANAGER_YOUTUBE_API_KEY is defined in wp-config.php.', 'collections-manager' ),
	);
	$errors = array(
		'missing-title' => __( 'Please enter a collection name.', 'collections-manager' ),
		'save-failed'   => __( 'The collection could not be saved.', 'collections-manager' ),
	);

	if ( isset( $messages[ $updated ] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $updated ] ) . '</p></div>';
	}
	if ( isset( $errors[ $error ] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
	}
}

function collections_manager_render_overview_tab() {
	$collections = collections_manager_get_collections();
	echo '<p style="margin-top:20px;">' . esc_html__( 'Curated groups of posts — Most Popular, Park Guides, Festivals, and anything else you want readers to browse without leaving the page.', 'collections-manager' ) . '</p>';
	echo '<p>';
	printf(
		'<a class="button button-primary" href="%s">%s</a> ',
		esc_url( collections_manager_dashboard_url( array( 'tab' => 'collections', 'collection' => 'new' ) ) ),
		esc_html__( 'Add collection', 'collections-manager' )
	);
	printf(
		'<a class="button" href="%s">%s</a>',
		esc_url( collections_manager_dashboard_url( array( 'tab' => 'collections' ) ) ),
		esc_html__( 'Manage collections', 'collections-manager' )
	);
	echo '</p>';

	echo '<table class="widefat striped" style="max-width:760px;margin-top:16px;"><thead><tr>';
	echo '<th>' . esc_html__( 'Collection', 'collections-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Items', 'collections-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Order', 'collections-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $collections ) ) {
		echo '<tr><td colspan="3">' . esc_html__( 'No collections yet.', 'collections-manager' ) . '</td></tr>';
	} else {
		foreach ( $collections as $collection ) {
			$edit = collections_manager_dashboard_url( array( 'tab' => 'collections', 'collection' => $collection->ID ) );
			echo '<tr>';
			printf( '<td><a href="%s"><strong>%s</strong></a></td>', esc_url( $edit ), esc_html( get_the_title( $collection ) ) );
			echo '<td>' . esc_html( (string) collections_manager_collection_item_count( $collection->ID ) ) . '</td>';
			echo '<td>' . esc_html( (string) $collection->menu_order ) . '</td>';
			echo '</tr>';
		}
	}
	echo '</tbody></table>';
}

function collections_manager_render_how_to_use_tab() {
	echo '<div style="max-width:760px;margin-top:20px;">';
	echo '<ol style="list-style:decimal;margin-left:1.2em;">';
	echo '<li>' . esc_html__( 'Create a collection and give it a name. Add a custom cover only if you do not want the latest item’s image.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Choose how items are added: pick WordPress items by hand, pull from a local post type and category, load a remote WordPress site or XML feed (for example podcasts.wdwnt.com), or use a YouTube playlist or videos.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'For a remote site, paste the homepage (WordPress REST is used when available) or an RSS/Atom URL such as a category feed. Optional category filters apply to WordPress REST sources.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'For YouTube, add a channel, then choose latest uploads, a playlist from that channel, or specific videos. Listing playlists and browsing beyond the public RSS feed needs a Data API key under Collections → Settings (or COLLECTIONS_MANAGER_YOUTUBE_API_KEY in wp-config.php).', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Lower order numbers appear first in the Collections Browser row.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Insert the Collections Browser block. Readers tap a collection to open its carousel underneath — they stay on the page.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Set site-wide spacing, shadows, slider box, and colors under Collections → Styles. Those inherit the active theme unless you pick a palette or custom color.', 'collections-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'In the block sidebar, leave “Use global styles” on, or turn it off to style only that block.', 'collections-manager' ) . '</li>';
	echo '</ol></div>';
}

function collections_manager_render_collections_tab() {
	$edit_id = isset( $_GET['collection'] ) ? sanitize_text_field( wp_unslash( $_GET['collection'] ) ) : '';
	$post    = null;
	if ( 'new' === $edit_id ) {
		$post = null;
	} elseif ( $edit_id ) {
		$post = get_post( absint( $edit_id ) );
		if ( ! $post || COLLECTIONS_MANAGER_POST_TYPE !== $post->post_type ) {
			$post = null;
		}
	}

	collections_manager_render_collection_form( $post );

	$collections = collections_manager_get_collections();
	echo '<h2>' . esc_html__( 'All collections', 'collections-manager' ) . '</h2>';
	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html__( 'Name', 'collections-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Items', 'collections-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Order', 'collections-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'collections-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $collections ) ) {
		echo '<tr><td colspan="4">' . esc_html__( 'No collections yet.', 'collections-manager' ) . '</td></tr>';
	} else {
		foreach ( $collections as $collection ) {
			$edit  = collections_manager_dashboard_url( array( 'tab' => 'collections', 'collection' => $collection->ID ) );
			$trash = wp_nonce_url(
				collections_manager_dashboard_url( array( 'tab' => 'collections', 'collections_manager_trash' => $collection->ID ) ),
				'collections_manager_trash_' . $collection->ID
			);
			echo '<tr>';
			printf( '<td><a href="%s"><strong>%s</strong></a></td>', esc_url( $edit ), esc_html( get_the_title( $collection ) ) );
			echo '<td>' . esc_html( (string) collections_manager_collection_item_count( $collection->ID ) ) . '</td>';
			echo '<td>' . esc_html( (string) $collection->menu_order ) . '</td>';
			echo '<td>';
			printf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'collections-manager' ) );
			if ( current_user_can( 'delete_post', $collection->ID ) ) {
				echo ' | ';
				printf( '<a class="submitdelete" href="%s">%s</a>', esc_url( $trash ), esc_html__( 'Trash', 'collections-manager' ) );
			}
			echo '</td></tr>';
		}
	}
	echo '</tbody></table>';
}

function collections_manager_render_collection_form( $post = null ) {
	$is_edit = $post instanceof WP_Post;
	$title   = $is_edit ? $post->post_title : '';
	$desc    = $is_edit ? $post->post_excerpt : '';
	$order   = $is_edit ? (int) $post->menu_order : 0;
	$image   = $is_edit ? (int) get_post_thumbnail_id( $post ) : 0;
	$source  = $is_edit ? collections_manager_get_source( $post->ID ) : 'manual';
	$query   = $is_edit ? collections_manager_get_query( $post->ID ) : collections_manager_default_query();
	$youtube = $is_edit ? collections_manager_get_youtube( $post->ID ) : collections_manager_default_youtube();
	$feed    = $is_edit ? collections_manager_get_feed( $post->ID ) : collections_manager_default_feed();
	$ids     = $is_edit ? collections_manager_get_post_ids( $post->ID ) : array();
	$selected_posts = array();
	foreach ( $ids as $id ) {
		$item = get_post( $id );
		if ( $item && 'publish' === $item->post_status ) {
			$selected_posts[] = $item;
		}
	}

	$post_types     = collections_manager_collectable_post_types();
	$query_taxes    = collections_manager_taxonomies_for_post_type( $query['post_type'] );
	$matched_count  = $is_edit && in_array( $source, array( 'query', 'youtube', 'feed' ), true ) ? collections_manager_collection_item_count( $post->ID ) : null;

	echo '<div class="cm-dash-card">';
	echo '<h2>' . esc_html( $is_edit ? __( 'Edit collection', 'collections-manager' ) : __( 'Add collection', 'collections-manager' ) ) . '</h2>';
	echo '<form method="post" action="' . esc_url( collections_manager_dashboard_url( array( 'tab' => 'collections' ) ) ) . '">';
	wp_nonce_field( 'collections_manager_save' );
	if ( $is_edit ) {
		echo '<input type="hidden" name="collection[id]" value="' . esc_attr( (string) $post->ID ) . '" />';
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="cm-title"><?php esc_html_e( 'Name', 'collections-manager' ); ?></label></th>
			<td><input name="collection[title]" id="cm-title" type="text" class="regular-text" required value="<?php echo esc_attr( $title ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="cm-description"><?php esc_html_e( 'Short description', 'collections-manager' ); ?></label></th>
			<td><textarea name="collection[description]" id="cm-description" class="large-text" rows="2"><?php echo esc_textarea( $desc ); ?></textarea></td>
		</tr>
		<tr>
			<th scope="row"><label for="cm-order"><?php esc_html_e( 'Display order', 'collections-manager' ); ?></label></th>
			<td>
				<input name="collection[menu_order]" id="cm-order" type="number" class="small-text" value="<?php echo esc_attr( (string) $order ); ?>" />
				<p class="description"><?php esc_html_e( 'Lower numbers appear first in the browser row.', 'collections-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Cover image', 'collections-manager' ); ?></th>
			<td>
				<input type="hidden" name="collection[image_id]" id="cm-image-id" value="<?php echo esc_attr( (string) $image ); ?>" />
				<div class="cm-image-preview">
					<?php
					if ( $image ) {
						echo wp_get_attachment_image( $image, 'medium', false, array( 'style' => 'max-width:240px;height:auto;display:block;' ) );
					}
					?>
				</div>
				<button type="button" class="button cm-select-image"><?php esc_html_e( 'Select image', 'collections-manager' ); ?></button>
				<button type="button" class="button-link cm-remove-image" <?php echo $image ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'collections-manager' ); ?></button>
				<p class="description"><?php esc_html_e( 'Optional. Without a custom cover, the collection uses the latest item’s image.', 'collections-manager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Items', 'collections-manager' ); ?></th>
			<td>
				<fieldset class="cm-source-toggle">
					<legend class="screen-reader-text"><?php esc_html_e( 'How this collection gets items', 'collections-manager' ); ?></legend>
					<label>
						<input type="radio" name="collection[source]" value="manual" <?php checked( $source, 'manual' ); ?> />
						<?php esc_html_e( 'Pick items manually', 'collections-manager' ); ?>
					</label>
					<label>
						<input type="radio" name="collection[source]" value="query" <?php checked( $source, 'query' ); ?> />
						<?php esc_html_e( 'From post type and category', 'collections-manager' ); ?>
					</label>
					<label>
						<input type="radio" name="collection[source]" value="youtube" <?php checked( $source, 'youtube' ); ?> />
						<?php esc_html_e( 'YouTube channel', 'collections-manager' ); ?>
					</label>
					<label>
						<input type="radio" name="collection[source]" value="feed" <?php checked( $source, 'feed' ); ?> />
						<?php esc_html_e( 'WordPress site or XML feed', 'collections-manager' ); ?>
					</label>
				</fieldset>

				<div id="cm-panel-manual" class="cm-source-panel" <?php echo 'manual' === $source ? '' : 'hidden'; ?>>
					<div class="cm-search-row">
						<label for="cm-manual-post-type" class="screen-reader-text"><?php esc_html_e( 'Post type to search', 'collections-manager' ); ?></label>
						<select id="cm-manual-post-type">
							<?php foreach ( $post_types as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="search" id="cm-post-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search…', 'collections-manager' ); ?>" autocomplete="off" />
					</div>
					<ul class="cm-search-results" id="cm-search-results" hidden></ul>
					<ul class="cm-selected-posts" id="cm-selected-posts">
						<?php foreach ( $selected_posts as $item ) : ?>
							<li data-id="<?php echo esc_attr( (string) $item->ID ); ?>">
								<input type="hidden" name="collection[post_ids][]" value="<?php echo esc_attr( (string) $item->ID ); ?>" />
								<span class="cm-selected-posts__title"><?php echo esc_html( get_the_title( $item ) ); ?></span>
								<button type="button" class="button-link cm-move-up"><?php esc_html_e( 'Up', 'collections-manager' ); ?></button>
								<button type="button" class="button-link cm-move-down"><?php esc_html_e( 'Down', 'collections-manager' ); ?></button>
								<button type="button" class="button-link cm-remove-post"><?php esc_html_e( 'Remove', 'collections-manager' ); ?></button>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="description"><?php esc_html_e( 'Order here is the order in the carousel.', 'collections-manager' ); ?></p>
				</div>

				<div id="cm-panel-query" class="cm-source-panel" <?php echo 'query' === $source ? '' : 'hidden'; ?>>
					<p>
						<label for="cm-query-post-type"><?php esc_html_e( 'Post type', 'collections-manager' ); ?></label><br />
						<select name="collection[query][post_type]" id="cm-query-post-type">
							<?php foreach ( $post_types as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $query['post_type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="cm-query-taxonomy"><?php esc_html_e( 'Category or taxonomy', 'collections-manager' ); ?></label><br />
						<select name="collection[query][taxonomy]" id="cm-query-taxonomy">
							<?php if ( empty( $query_taxes ) ) : ?>
								<option value=""><?php esc_html_e( 'None available', 'collections-manager' ); ?></option>
							<?php else : ?>
								<?php foreach ( $query_taxes as $tax_slug => $tax_label ) : ?>
									<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( $query['taxonomy'], $tax_slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</p>
					<p><?php esc_html_e( 'Terms', 'collections-manager' ); ?></p>
					<?php collections_manager_render_query_term_checkboxes( $query['taxonomy'], $query['terms'] ); ?>
					<p>
						<label>
							<input type="checkbox" name="collection[query][unlimited]" id="cm-query-unlimited" value="1" <?php checked( ! empty( $query['unlimited'] ) ); ?> />
							<?php esc_html_e( 'Promote all matching items', 'collections-manager' ); ?>
						</label>
					</p>
					<p id="cm-query-limit-wrap" <?php echo ! empty( $query['unlimited'] ) ? 'hidden' : ''; ?>>
						<label for="cm-query-limit"><?php esc_html_e( 'Maximum items', 'collections-manager' ); ?></label><br />
						<input name="collection[query][limit]" id="cm-query-limit" type="number" min="1" max="50" class="small-text" value="<?php echo esc_attr( (string) $query['limit'] ); ?>" />
					</p>
					<p class="description">
						<?php
						if ( null !== $matched_count ) {
							printf(
								esc_html(
									/* translators: %d: number of matching posts. */
									_n( 'This query currently matches %d published item.', 'This query currently matches %d published items.', $matched_count, 'collections-manager' )
								),
								(int) $matched_count
							);
							echo ' ';
						}
						if ( ! empty( $query['unlimited'] ) ) {
							esc_html_e( 'The carousel shows the latest 12, then a View more slide to the rest. Newest items appear first.', 'collections-manager' );
						} else {
							esc_html_e( 'Newest items appear first. Leave every term unchecked to include all items of this post type.', 'collections-manager' );
						}
						?>
					</p>
				</div>

				<div id="cm-panel-youtube" class="cm-source-panel" <?php echo 'youtube' === $source ? '' : 'hidden'; ?>>
					<?php if ( ! \CollectionsManager\YouTube\Client::has_api_key() ) : ?>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: settings tab link */
									__( 'Latest uploads work from a channel ID without a key. Add a YouTube Data API key under %s to look up @handles, list playlists, and browse more videos.', 'collections-manager' ),
									'<a href="' . esc_url( collections_manager_dashboard_url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'collections-manager' ) . '</a>'
								),
								array( 'a' => array( 'href' => array() ) )
							);
							?>
						</p>
					<?php endif; ?>
					<p>
						<label for="cm-youtube-channel-input"><?php esc_html_e( 'Add a channel', 'collections-manager' ); ?></label><br />
						<input type="text" id="cm-youtube-channel-input" class="regular-text" placeholder="@WDWNT, channel URL, or UC…" />
						<button type="button" class="button" id="cm-youtube-add-channel"><?php esc_html_e( 'Add channel', 'collections-manager' ); ?></button>
					</p>
					<ul class="cm-selected-posts" id="cm-youtube-channels">
						<?php foreach ( $youtube['channels'] as $channel ) : ?>
							<li data-id="<?php echo esc_attr( $channel['id'] ); ?>">
								<input type="hidden" name="collection[youtube][channels][id][]" value="<?php echo esc_attr( $channel['id'] ); ?>" />
								<input type="hidden" name="collection[youtube][channels][title][]" value="<?php echo esc_attr( $channel['title'] ); ?>" />
								<span class="cm-selected-posts__title"><?php echo esc_html( $channel['title'] ); ?></span>
								<button type="button" class="button-link cm-remove-channel"><?php esc_html_e( 'Remove', 'collections-manager' ); ?></button>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php
					$youtube_ready = ! empty( $youtube['channels'] ) || ( 'playlist' === $youtube['mode'] && '' !== $youtube['playlist'] );
					?>
					<p id="cm-youtube-need-channel" class="description" <?php echo $youtube_ready ? 'hidden' : ''; ?>>
						<?php esc_html_e( 'Add a channel, then choose latest videos, a playlist, or specific videos from that channel.', 'collections-manager' ); ?>
					</p>
					<div id="cm-youtube-after-channel" <?php echo $youtube_ready ? '' : 'hidden'; ?>>
						<fieldset class="cm-source-toggle">
							<legend class="screen-reader-text"><?php esc_html_e( 'Videos from this channel', 'collections-manager' ); ?></legend>
							<label>
								<input type="radio" name="collection[youtube][mode]" value="latest" <?php checked( $youtube['mode'], 'latest' ); ?> />
								<?php esc_html_e( 'Latest videos', 'collections-manager' ); ?>
							</label>
							<label>
								<input type="radio" name="collection[youtube][mode]" value="playlist" <?php checked( $youtube['mode'], 'playlist' ); ?> />
								<?php esc_html_e( 'A playlist', 'collections-manager' ); ?>
							</label>
							<label>
								<input type="radio" name="collection[youtube][mode]" value="videos" <?php checked( $youtube['mode'], 'videos' ); ?> />
								<?php esc_html_e( 'Specific videos', 'collections-manager' ); ?>
							</label>
						</fieldset>

						<p>
							<label>
								<input type="checkbox" name="collection[youtube][unlimited]" id="cm-youtube-unlimited" value="1" <?php checked( ! empty( $youtube['unlimited'] ) ); ?> />
								<?php esc_html_e( 'Promote all matching videos', 'collections-manager' ); ?>
							</label>
						</p>
						<p id="cm-youtube-limit-wrap" <?php echo ( 'videos' === $youtube['mode'] || ! empty( $youtube['unlimited'] ) ) ? 'hidden' : ''; ?>>
							<label for="cm-youtube-limit"><?php esc_html_e( 'Maximum videos', 'collections-manager' ); ?></label><br />
							<input name="collection[youtube][limit]" id="cm-youtube-limit" type="number" min="1" max="50" class="small-text" value="<?php echo esc_attr( (string) $youtube['limit'] ); ?>" />
						</p>
						<p class="description" id="cm-youtube-unlimited-help" <?php echo empty( $youtube['unlimited'] ) ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'The carousel shows the latest 12, then a View more slide to the channel or playlist.', 'collections-manager' ); ?>
						</p>

						<div id="cm-youtube-latest" <?php echo 'latest' === $youtube['mode'] ? '' : 'hidden'; ?>>
							<p class="description"><?php esc_html_e( 'The collection stays up to date with the newest public uploads. If you add more than one channel, videos are mixed by date.', 'collections-manager' ); ?></p>
							<p>
								<button type="button" class="button" id="cm-youtube-preview-latest"><?php esc_html_e( 'Check latest videos', 'collections-manager' ); ?></button>
								<span id="cm-youtube-latest-status" class="description" role="status"></span>
							</p>
						</div>

						<div id="cm-youtube-playlist" <?php echo 'playlist' === $youtube['mode'] ? '' : 'hidden'; ?>>
							<p>
								<label for="cm-youtube-playlist-channel"><?php esc_html_e( 'Channel', 'collections-manager' ); ?></label><br />
								<select id="cm-youtube-playlist-channel">
									<?php foreach ( $youtube['channels'] as $channel ) : ?>
										<option value="<?php echo esc_attr( $channel['id'] ); ?>"><?php echo esc_html( $channel['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label for="cm-youtube-playlist-pick"><?php esc_html_e( 'Playlists on this channel', 'collections-manager' ); ?></label><br />
								<select id="cm-youtube-playlist-pick">
									<option value=""><?php esc_html_e( 'Choose a playlist…', 'collections-manager' ); ?></option>
								</select>
								<span id="cm-youtube-playlist-list-status" class="description" role="status"></span>
							</p>
							<p>
								<label for="cm-youtube-playlist-id"><?php esc_html_e( 'Or paste a playlist URL or ID', 'collections-manager' ); ?></label><br />
								<input name="collection[youtube][playlist]" id="cm-youtube-playlist-id" type="text" class="regular-text" value="<?php echo esc_attr( $youtube['playlist'] ); ?>" placeholder="PLxxxxxxxx or youtube.com/playlist?list=" />
							</p>
							<p>
								<button type="button" class="button" id="cm-youtube-preview"><?php esc_html_e( 'Check playlist', 'collections-manager' ); ?></button>
								<span id="cm-youtube-preview-status" class="description" role="status"></span>
							</p>
						</div>

						<div id="cm-youtube-videos" <?php echo 'videos' === $youtube['mode'] ? '' : 'hidden'; ?>>
							<div class="cm-search-row">
								<label for="cm-youtube-channel-pick"><?php esc_html_e( 'Channel to browse', 'collections-manager' ); ?></label>
								<select id="cm-youtube-channel-pick">
									<?php foreach ( $youtube['channels'] as $channel ) : ?>
										<option value="<?php echo esc_attr( $channel['id'] ); ?>"><?php echo esc_html( $channel['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="search" id="cm-youtube-video-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search videos…', 'collections-manager' ); ?>" autocomplete="off" />
								<button type="button" class="button" id="cm-youtube-load-videos"><?php esc_html_e( 'Load videos', 'collections-manager' ); ?></button>
							</div>
							<ul class="cm-search-results" id="cm-youtube-video-results" hidden></ul>
							<p>
								<button type="button" class="button-link" id="cm-youtube-more" hidden><?php esc_html_e( 'Load more', 'collections-manager' ); ?></button>
							</p>
							<ul class="cm-selected-posts" id="cm-youtube-selected">
								<?php foreach ( $youtube['videos'] as $video ) : ?>
									<li data-id="<?php echo esc_attr( $video['id'] ); ?>">
										<input type="hidden" name="collection[youtube][video_ids][]" value="<?php echo esc_attr( $video['id'] ); ?>" />
										<input type="hidden" name="collection[youtube][video_titles][]" value="<?php echo esc_attr( $video['title'] ); ?>" />
										<span class="cm-selected-posts__title"><?php echo esc_html( $video['title'] ? $video['title'] : $video['id'] ); ?></span>
										<button type="button" class="button-link cm-move-up"><?php esc_html_e( 'Up', 'collections-manager' ); ?></button>
										<button type="button" class="button-link cm-move-down"><?php esc_html_e( 'Down', 'collections-manager' ); ?></button>
										<button type="button" class="button-link cm-remove-video"><?php esc_html_e( 'Remove', 'collections-manager' ); ?></button>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Order here is the order in the carousel. Videos open on YouTube.', 'collections-manager' ); ?></p>
						</div>
					</div>
				</div>

				<div id="cm-panel-feed" class="cm-source-panel" <?php echo 'feed' === $source ? '' : 'hidden'; ?>>
					<p>
						<label for="cm-feed-url"><?php esc_html_e( 'Site or feed URL', 'collections-manager' ); ?></label><br />
						<input name="collection[feed][url]" id="cm-feed-url" type="url" class="regular-text" value="<?php echo esc_attr( $feed['url'] ); ?>" placeholder="https://podcasts.wdwnt.com" />
					</p>
					<input type="hidden" name="collection[feed][kind]" id="cm-feed-kind" value="<?php echo esc_attr( $feed['kind'] ); ?>" />
					<input type="hidden" name="collection[feed][category_slug]" id="cm-feed-category-slug" value="<?php echo esc_attr( $feed['category_slug'] ); ?>" />
					<p>
						<button type="button" class="button" id="cm-feed-discover"><?php esc_html_e( 'Detect site', 'collections-manager' ); ?></button>
						<span id="cm-feed-discover-status" class="description" role="status"></span>
					</p>
					<div id="cm-feed-rest-fields" <?php echo 'rss' === $feed['kind'] ? 'hidden' : ''; ?>>
						<p>
							<label for="cm-feed-rest-base"><?php esc_html_e( 'Post type', 'collections-manager' ); ?></label><br />
							<select name="collection[feed][rest_base]" id="cm-feed-rest-base">
								<option value="<?php echo esc_attr( $feed['rest_base'] ); ?>" selected><?php echo esc_html( $feed['rest_base'] ); ?></option>
							</select>
						</p>
						<p>
							<label for="cm-feed-category"><?php esc_html_e( 'Category', 'collections-manager' ); ?></label><br />
							<select name="collection[feed][category_id]" id="cm-feed-category">
								<option value="0"><?php esc_html_e( 'All categories', 'collections-manager' ); ?></option>
								<?php if ( $feed['category_id'] ) : ?>
									<option value="<?php echo esc_attr( (string) $feed['category_id'] ); ?>" selected><?php echo esc_html( $feed['category_slug'] ? $feed['category_slug'] : (string) $feed['category_id'] ); ?></option>
								<?php endif; ?>
							</select>
						</p>
					</div>
					<p>
						<label>
							<input type="checkbox" name="collection[feed][unlimited]" id="cm-feed-unlimited" value="1" <?php checked( ! empty( $feed['unlimited'] ) ); ?> />
							<?php esc_html_e( 'Promote all matching items', 'collections-manager' ); ?>
						</label>
					</p>
					<p id="cm-feed-limit-wrap" <?php echo ! empty( $feed['unlimited'] ) ? 'hidden' : ''; ?>>
						<label for="cm-feed-limit"><?php esc_html_e( 'Maximum items', 'collections-manager' ); ?></label><br />
						<input name="collection[feed][limit]" id="cm-feed-limit" type="number" min="1" max="50" class="small-text" value="<?php echo esc_attr( (string) $feed['limit'] ); ?>" />
					</p>
					<p class="description" <?php echo empty( $feed['unlimited'] ) ? 'hidden' : ''; ?> id="cm-feed-unlimited-help">
						<?php esc_html_e( 'The carousel shows the latest 12, then a View more slide to the source site.', 'collections-manager' ); ?>
					</p>
					<p>
						<button type="button" class="button" id="cm-feed-preview"><?php esc_html_e( 'Check feed', 'collections-manager' ); ?></button>
						<span id="cm-feed-preview-status" class="description" role="status"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Paste a WordPress homepage, a /wp-json/ URL, or an RSS/Atom feed (including category feeds). Items open on the remote site.', 'collections-manager' ); ?></p>
				</div>
			</td>
		</tr>
	</table>
	<?php
	submit_button( $is_edit ? __( 'Save collection', 'collections-manager' ) : __( 'Add collection', 'collections-manager' ), 'primary', 'collections_manager_save' );
	echo '</form></div>';
}

/**
 * Checkbox list of taxonomy terms for a query collection.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param int[]  $selected Selected term IDs.
 */
function collections_manager_render_query_term_checkboxes( $taxonomy, $selected ) {
	$selected = array_map( 'absint', (array) $selected );
	echo '<div class="cm-term-list" id="cm-query-terms">';
	if ( ! $taxonomy ) {
		echo '<p class="description">' . esc_html__( 'This post type has no categories.', 'collections-manager' ) . '</p>';
		echo '</div>';
		return;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		echo '<p class="description">' . esc_html__( 'No terms yet for this taxonomy.', 'collections-manager' ) . '</p>';
		echo '</div>';
		return;
	}

	foreach ( $terms as $term ) {
		printf(
			'<label><input type="checkbox" name="collection[query][terms][]" value="%1$d"%2$s /> %3$s <span class="cm-term-count">(%4$d)</span></label>',
			(int) $term->term_id,
			checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
			esc_html( $term->name ),
			(int) $term->count
		);
	}
	echo '</div>';
}
