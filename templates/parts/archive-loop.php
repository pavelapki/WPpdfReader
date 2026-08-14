<?php
/**
 * Document grid / list loop.
 *
 * Override by copying to yourtheme/wp-pdf-reader/parts/archive-loop.php
 *
 * @package WP_PDF_Reader
 *
 * @var WP_Query $query      Query to loop over.
 * @var int      $columns    Number of columns.
 * @var string   $layout     "grid" or "list".
 * @var string   $lang       Requested language code.
 * @var bool     $excerpt    Whether to show excerpts.
 * @var bool     $show_meta  Whether to show the file meta line.
 * @var bool     $pagination Whether to print pagination links.
 */

defined( 'ABSPATH' ) || exit;

$query      = isset( $query ) ? $query : $GLOBALS['wp_query'];
$columns    = isset( $columns ) ? max( 1, (int) $columns ) : (int) WPPDF_Settings::get( 'archive_columns' );
$layout     = isset( $layout ) && 'list' === $layout ? 'list' : 'grid';
$lang       = isset( $lang ) ? $lang : '';
$excerpt    = isset( $excerpt ) ? (bool) $excerpt : true;
$show_meta  = isset( $show_meta ) ? (bool) $show_meta : true;
$pagination = isset( $pagination ) ? (bool) $pagination : false;

wp_enqueue_style( 'wppdf-archive' );

if ( ! $query->have_posts() ) {
	echo '<p class="wppdf-empty">' . esc_html__( 'No documents found.', 'wp-pdf-reader' ) . '</p>';

	return;
}
?>
<div class="wppdf-collection wppdf-collection--<?php echo esc_attr( $layout ); ?> wppdf-collection--cols-<?php echo (int) $columns; ?>">
	<?php
	while ( $query->have_posts() ) :
		$query->the_post();

		$wppdf_card = WPPDF_Templates::get_part(
			'parts/card.php',
			array(
				'post_id'   => get_the_ID(),
				'lang'      => $lang,
				'excerpt'   => $excerpt,
				'show_meta' => $show_meta,
				'layout'    => $layout,
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- finished markup, escaped field by field inside the template.
		echo $wppdf_card;
	endwhile;
	?>
</div>

<?php if ( $pagination ) : ?>
	<nav class="wppdf-pagination">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'total'   => (int) $query->max_num_pages,
					'current' => max( 1, (int) $query->get( 'paged' ) ),
					'type'    => 'plain',
				)
			)
		);
		?>
	</nav>
<?php endif; ?>
<?php
wp_reset_postdata();
