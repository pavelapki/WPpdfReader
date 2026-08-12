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
	if ( WPPDF_Settings::get( 'archive_filters' ) ) {
		echo WPPDF_Filters::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output.

		if ( WPPDF_Filters::is_filtered() ) {
			$wppdf_found = (int) $GLOBALS['wp_query']->found_posts;

			printf(
				'<p class="wppdf-archive__count">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of documents found. */
						_n( '%d document found', '%d documents found', $wppdf_found, 'wp-pdf-reader' ),
						$wppdf_found
					)
				)
			);
		}
	}

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
