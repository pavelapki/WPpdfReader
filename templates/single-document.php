<?php
/**
 * Single document.
 *
 * Override by copying to yourtheme/wp-pdf-reader/single-document.php, or by
 * adding single-{post_type}.php to the theme.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$wppdf_post_id = get_the_ID();
	$wppdf_file    = WPPDF_Documents::get_file( $wppdf_post_id );
	?>
	<article <?php post_class( 'wppdf-single' ); ?>>
		<header class="wppdf-single__header">
			<h1 class="wppdf-single__title"><?php the_title(); ?></h1>

			<p class="wppdf-single__meta">
				<span class="wppdf-single__date"><?php echo esc_html( get_the_date() ); ?></span>
				<?php
				$wppdf_terms = get_the_term_list( $wppdf_post_id, 'category', '', ', ' );
				if ( $wppdf_terms && ! is_wp_error( $wppdf_terms ) ) :
					?>
					<span class="wppdf-single__terms"><?php echo wp_kses_post( $wppdf_terms ); ?></span>
				<?php endif; ?>

				<?php if ( $wppdf_file ) : ?>
					<span class="wppdf-single__lang">
						<?php
						printf(
							/* translators: %s: language label. */
							esc_html__( 'Language: %s', 'wp-pdf-reader' ),
							esc_html( $wppdf_file['language_label'] )
						);
						?>
					</span>
					<?php $wppdf_size = WPPDF_Documents::format_filesize( $wppdf_file['filesize'] ); ?>
					<?php if ( $wppdf_size ) : ?>
						<span class="wppdf-single__size"><?php echo esc_html( $wppdf_size ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</header>

		<div class="wppdf-single__content">
			<?php the_content(); ?>
		</div>

		<?php
		// The reader is appended to the content automatically unless that was
		// switched off in the settings.
		if ( ! WPPDF_Settings::get( 'append_to_content' ) ) {
			wppdf_the_viewer( $wppdf_post_id );
		}
		?>

		<?php
		$wppdf_available = WPPDF_Documents::get_available_languages( $wppdf_post_id );
		if ( count( $wppdf_available ) > 1 ) :
			?>
			<section class="wppdf-single__versions">
				<h2 class="wppdf-single__versions-title"><?php esc_html_e( 'Other language versions', 'wp-pdf-reader' ); ?></h2>
				<ul class="wppdf-single__versions-list">
					<?php
					foreach ( $wppdf_available as $wppdf_code ) :
						$wppdf_version = WPPDF_Documents::get_raw_file( $wppdf_post_id, $wppdf_code );
						if ( ! $wppdf_version ) {
							continue;
						}
						?>
						<li>
							<a href="<?php echo esc_url( $wppdf_version['url'] ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( WPPDF_Languages::get_label( $wppdf_code ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
		?>
	</article>
	<?php
endwhile;

get_footer();
