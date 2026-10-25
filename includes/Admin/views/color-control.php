<?php
/**
 * Theme/custom color dropdown used on the Styles tab.
 *
 * @package CollectionsManager
 *
 * @var string $id          Hidden input id.
 * @var string $name        Hidden input name.
 * @var string $value       Stored color.
 * @var bool   $is_custom   Whether the value is a custom hex.
 * @var string $hex         Hex value when custom.
 * @var string $picker      Color-input value.
 * @var string $label       Toggle label.
 * @var string $chip        Chip background.
 * @var string $empty_label Inherit option label.
 * @var array  $palette     Theme palette entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cm-color" data-color-control>
	<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
	<button type="button" class="cm-color__toggle" aria-expanded="false" aria-haspopup="listbox">
		<span class="cm-color__chip" style="background:<?php echo esc_attr( $chip ); ?>"></span>
		<span class="cm-color__label"><?php echo esc_html( $label ); ?></span>
		<span class="cm-color__chevron" aria-hidden="true">▾</span>
	</button>
	<div class="cm-color__menu" hidden role="listbox">
		<button type="button" class="cm-color__option<?php echo '' === $value ? ' is-selected' : ''; ?>" data-color="" data-hex="currentColor" data-label="<?php echo esc_attr( $empty_label ); ?>">
			<span class="cm-color__chip" style="background:currentColor"></span>
			<?php echo esc_html( $empty_label ); ?>
		</button>
		<?php foreach ( $palette as $cm_swatch ) : ?>
			<button
				type="button"
				class="cm-color__option<?php echo $value === $cm_swatch['css'] ? ' is-selected' : ''; ?>"
				data-color="<?php echo esc_attr( $cm_swatch['css'] ); ?>"
				data-hex="<?php echo esc_attr( $cm_swatch['hex'] ); ?>"
				data-label="<?php echo esc_attr( $cm_swatch['name'] ); ?>"
			>
				<span class="cm-color__chip" style="background:<?php echo esc_attr( $cm_swatch['hex'] ); ?>"></span>
				<?php echo esc_html( $cm_swatch['name'] ); ?>
			</button>
		<?php endforeach; ?>
		<button type="button" class="cm-color__option<?php echo $is_custom ? ' is-selected' : ''; ?>" data-color="__custom" data-hex="#000000" data-label="<?php esc_attr_e( 'Custom color…', 'collections-manager' ); ?>">
			<span class="cm-color__chip" style="background:conic-gradient(red, yellow, lime, aqua, blue, magenta, red)"></span>
			<?php esc_html_e( 'Custom color…', 'collections-manager' ); ?>
		</button>
	</div>
	<div class="cm-color__custom" <?php echo $is_custom ? '' : 'hidden'; ?>>
		<input type="text" class="cm-color__hex" value="<?php echo esc_attr( $hex ); ?>" placeholder="#000000" aria-label="<?php esc_attr_e( 'Custom color', 'collections-manager' ); ?>" />
		<input type="color" class="cm-color__picker" value="<?php echo esc_attr( $picker ); ?>" aria-label="<?php esc_attr_e( 'Color picker', 'collections-manager' ); ?>" />
	</div>
</div>
