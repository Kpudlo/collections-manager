<?php
/**
 * Collections Browser visual styles.
 *
 * @package CollectionsManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CollectionsManager\Styles\Repository;

/**
 * @return array<string, mixed>
 */
function collections_manager_browser_style_defaults() {
	return Repository::defaults();
}

/**
 * @return array<string, mixed>
 */
function collections_manager_get_global_styles() {
	return Repository::get();
}

/**
 * @param mixed $color Raw color.
 * @return string
 */
function collections_manager_sanitize_color_value( $color ) {
	return Repository::sanitize_color( $color );
}

/**
 * @param string $color    Stored color.
 * @param string $fallback CSS fallback.
 * @return string
 */
function collections_manager_color_to_css( $color, $fallback = 'currentColor' ) {
	return Repository::color_to_css( $color, $fallback );
}

/**
 * @param mixed  $value   Raw choice.
 * @param array  $allowed Allowed keys.
 * @param string $default Fallback.
 * @return string
 */
function collections_manager_sanitize_choice( $value, array $allowed, $default ) {
	$value = sanitize_key( (string) $value );
	return in_array( $value, $allowed, true ) ? $value : $default;
}

/**
 * @param array $input Raw styles.
 * @return array<string, mixed>
 */
function collections_manager_sanitize_browser_styles( $input ) {
	return Repository::sanitize( $input );
}

/**
 * @param array $attributes Block attributes.
 * @return array<string, mixed>
 */
function collections_manager_browser_styles_from_attributes( array $attributes ) {
	return Repository::from_attributes( $attributes );
}

/**
 * Global styles unless the block opts out.
 *
 * @param array $attributes Block attributes.
 * @return array<string, mixed>
 */
function collections_manager_resolve_browser_styles( array $attributes ) {
	return Repository::resolve( $attributes );
}

/**
 * @param array $styles      Style set.
 * @param bool  $for_preview Preview mode.
 * @return string
 */
function collections_manager_browser_styles_to_css( array $styles, $for_preview = false ) {
	return Repository::to_css( $styles, $for_preview );
}

/**
 * @param array $styles Style set.
 * @return string
 */
function collections_manager_browser_style_classes( array $styles ) {
	return Repository::classes( $styles );
}
