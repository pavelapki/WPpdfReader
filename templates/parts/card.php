<?php
/**
 * A single document card.
 *
 * Override by copying to yourtheme/wp-pdf-reader/parts/card.php
 *
 * @package WP_PDF_Reader
 *
 * @var int    $post_id   Document ID.
 * @var string $lang      Requested language code.
 * @var bool   $excerpt   Whether to show the excerpt.
 * @var bool   $show_meta Whether to show the file meta line.
 * @var string $layout    "grid" or "list".
 */

defined( 'ABSPATH' ) || exit;

$post_id   = isset( $post_id ) ? (int) $post_id : get_the_ID();
$lang      = isset( $lang ) ? $lang : '';
$excerpt   = isset( $excerpt ) ? (bool) $excerpt : true;
$show_meta = isset( $show_meta ) ? (bool) $show_meta : true;
$layout    = isset( $layout ) ? $layout : 'grid';

$wppdf_file  = WPPDF_Documents::get_file( $post_id, $lang );
$wppdf_cover = WPPDF_Documents::get_cover_id( $post_id, $wppdf_file ? $wppdf_file['lang'] : '' );
$wppdf_terms = get_the_term_list( $post_id, 'category', '', ', ' );
?>
<article id="wppdf-card-<?php echo (int) $post_id; ?>" <?php post_class( 'wppdf-card wppdf-card--' . esc_attr( $layout ), $post_id ); ?>>
	<a class="wppdf-card__thumb" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<?php if ( $wppdf_cover ) : ?>
			<?php echo wp_kses_post( wp_get_attachment_image( $wppdf_cover, 'medium', false, array( 'class' => 'wppdf-card__image', 'loading' => 'lazy' ) ) ); ?>
		<?php else : ?>
			<span class="wppdf-card__placeholder" aria-hidden="true">PDF</span>
		<?php endif; ?>

		<?php if ( $wppdf_file ) : ?>
			<span class="wppdf-card__lang"><?php echo esc_html( strtoupper( $wppdf_file['lang'] ) ); ?></span>
		<?php endif; ?>
	</a>

	<div class="wppdf-card__body">
		<h2 class="wppdf-card__title">
			<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
		</h2>

		<?php if ( $wppdf_terms && ! is_wp_error( $wppdf_terms ) ) : ?>
			<p class="wppdf-card__terms"><?php echo wp_kses_post( $wppdf_terms ); ?></p>
		<?php endif; ?>

		<?php if ( $excerpt ) : ?>
			<?php $wppdf_excerpt = get_the_excerpt( $post_id ); ?>
			<?php if ( $wppdf_excerpt ) : ?>
				<p class="wppdf-card__excerpt"><?php echo esc_html( wp_trim_words( $wppdf_excerpt, 24 ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $show_meta && $wppdf_file ) : ?>
			<p class="wppdf-card__meta">
				<span class="wppdf-card__meta-item"><?php echo esc_html( $wppdf_file['language_label'] ); ?></span>
				<?php $wppdf_size = WPPDF_Documents::format_filesize( $wppdf_file['filesize'] ); ?>
				<?php if ( $wppdf_size ) : ?>
					<span class="wppdf-card__meta-item"><?php echo esc_html( $wppdf_size ); ?></span>
				<?php endif; ?>
				<span class="wppdf-card__meta-item"><?php echo esc_html( get_the_date( '', $post_id ) ); ?></span>
			</p>
		<?php elseif ( $show_meta ) : ?>
			<p class="wppdf-card__meta wppdf-card__meta--empty"><?php esc_html_e( 'No PDF available', 'wp-pdf-reader' ); ?></p>
		<?php endif; ?>
	</div>
</article>
