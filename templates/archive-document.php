<?php
/**
 * Document archive.
 *
 * Override by copying to yourtheme/wp-pdf-reader/archive-document.php, or by
 * adding archive-{post_type}.php to the theme.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="wppdf-archive">
	<header class="wppdf-archive__header">
		<h1 class="wppdf-archive__title"><?php post_type_archive_title(); ?></h1>
		<?php
		$wppdf_description = get_the_post_type_description();
		if ( $wppdf_description ) :
			?>
			<div class="wppdf-archive__description"><?php echo wp_kses_post( $wppdf_description ); ?></div>
		<?php endif; ?>
	</header>

	<?php
	echo WPPDF_Templates::get_part( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output.
		'parts/archive-loop.php',
		array(
			'query'      => $GLOBALS['wp_query'],
			'columns'    => (int) WPPDF_Settings::get( 'archive_columns' ),
			'layout'     => WPPDF_Settings::get( 'archive_layout' ),
			'excerpt'    => true,
			'show_meta'  => true,
			'pagination' => true,
		)
	);
	?>
</div>
<?php
get_footer();
