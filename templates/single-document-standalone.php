<?php
/**
 * Single document, on a page of its own.
 *
 * The theme's header, navigation and footer are what get_header() and
 * get_footer() print, so this template simply does not call them. wp_head()
 * and wp_footer() still run, because scripts, styles and anything else hooked
 * there are not part of the site chrome and the reader needs them.
 *
 * Override by copying to yourtheme/wp-pdf-reader/single-document-standalone.php.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

while ( have_posts() ) :
	the_post();

	$wppdf_post_id = get_the_ID();
	$wppdf_file    = WPPDF_Documents::get_file( $wppdf_post_id );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'wppdf-standalone' ); ?>>
	<div class="wppdf-standalone__bar">
		<a class="wppdf-standalone__back" href="<?php echo esc_url( wppdf_get_back_url( $wppdf_post_id ) ); ?>">
			<span aria-hidden="true">&larr;</span>
			<?php esc_html_e( 'Back', 'wp-pdf-reader' ); ?>
		</a>

		<h1 class="wppdf-standalone__title"><?php the_title(); ?></h1>

		<?php if ( $wppdf_file ) : ?>
			<span class="wppdf-standalone__lang"><?php echo esc_html( $wppdf_file['language_label'] ); ?></span>
		<?php endif; ?>
	</div>

	<main class="wppdf-standalone__main">
		<?php wppdf_the_viewer( $wppdf_post_id ); ?>
	</main>

	<?php wp_footer(); ?>
</body>
</html>
	<?php
endwhile;
