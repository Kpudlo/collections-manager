<?php
/**
 * Styles tab save handler and renderer.
 *
 * @package CollectionsManager
 */

namespace CollectionsManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CollectionsManager\Styles\Repository;

/**
 * Global styles form on the dashboard.
 */
class StylesPage {

	/**
	 * Register the save handler.
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Persist styles from POST.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['collections_manager_save_styles'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage collection styles.', 'collections-manager' ) );
		}

		check_admin_referer( 'collections_manager_save_styles' );

		$input = isset( $_POST['cm_styles'] ) ? wp_unslash( $_POST['cm_styles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		Repository::save( is_array( $input ) ? $input : array() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'collections_manager',
					'tab'              => 'styles',
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Output the Styles tab.
	 */
	public static function render() {
		$styles = Repository::get();
		$view   = COLLECTIONS_MANAGER_PATH . 'includes/Admin/views/styles-tab.php';
		if ( is_readable( $view ) ) {
			require $view;
		}
	}

	/**
	 * Theme/custom color dropdown.
	 *
	 * @param string $id         Hidden input id.
	 * @param string $name       Hidden input name.
	 * @param string $value      Stored color.
	 * @param string $empty_label Label for the inherit option.
	 */
	public static function color_control( $id, $name, $value, $empty_label = '' ) {
		$value       = Repository::sanitize_color( $value );
		$palette     = Repository::palette();
		$is_custom   = (bool) $value && ! preg_match( '/^var\(--wp--preset--color--/', $value );
		$hex         = ( $value && '#' === $value[0] ) ? $value : '';
		$picker      = $hex ? $hex : '#000000';
		$empty_label = $empty_label ? $empty_label : __( 'Inherit from theme', 'collections-manager' );
		if ( 4 === strlen( $picker ) ) {
			$picker = '#' . $picker[1] . $picker[1] . $picker[2] . $picker[2] . $picker[3] . $picker[3];
		}

		$label = $empty_label;
		$chip  = 'currentColor';
		if ( $is_custom ) {
			$label = __( 'Custom color…', 'collections-manager' );
			$chip  = $hex ? $hex : '#000000';
		} else {
			foreach ( $palette as $item ) {
				if ( $value === $item['css'] ) {
					$label = $item['name'];
					$chip  = $item['hex'];
					break;
				}
			}
		}
		require COLLECTIONS_MANAGER_PATH . 'includes/Admin/views/color-control.php';
	}
}
