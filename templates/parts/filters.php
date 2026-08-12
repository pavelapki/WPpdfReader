<?php
/**
 * Archive filter form.
 *
 * Override by copying to yourtheme/wp-pdf-reader/parts/filters.php
 *
 * @package WP_PDF_Reader
 *
 * @var string $action    Form target URL.
 * @var array  $terms     Terms to offer.
 * @var array  $languages Languages to offer.
 * @var array  $years     Years to offer.
 * @var array  $sorts     Sort orders to offer.
 * @var array  $current   Currently selected values.
 * @var bool   $filtered  Whether any filter is active.
 */

defined( 'ABSPATH' ) || exit;

$action    = isset( $action ) ? $action : home_url( '/' );
$terms     = isset( $terms ) ? $terms : array();
$languages = isset( $languages ) ? $languages : array();
$years     = isset( $years ) ? $years : array();
$sorts     = isset( $sorts ) ? $sorts : array();
$current   = isset( $current ) ? $current : array();
$filtered  = ! empty( $filtered );

wp_enqueue_style( 'wppdf-archive' );
?>
<form class="wppdf-filters" method="get" action="<?php echo esc_url( $action ); ?>" role="search">
	<div class="wppdf-filters__row">
		<p class="wppdf-filters__field wppdf-filters__field--search">
			<label for="wppdf-filter-search"><?php esc_html_e( 'Search', 'wp-pdf-reader' ); ?></label>
			<input
				type="search"
				id="wppdf-filter-search"
				name="<?php echo esc_attr( WPPDF_Filters::VAR_SEARCH ); ?>"
				value="<?php echo esc_attr( isset( $current['search'] ) ? $current['search'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Also inside the documents…', 'wp-pdf-reader' ); ?>"
			/>
		</p>

		<?php if ( ! empty( $terms ) ) : ?>
			<p class="wppdf-filters__field">
				<label for="wppdf-filter-category"><?php esc_html_e( 'Category', 'wp-pdf-reader' ); ?></label>
				<select id="wppdf-filter-category" name="<?php echo esc_attr( WPPDF_Filters::VAR_CATEGORY ); ?>">
					<option value=""><?php esc_html_e( 'All', 'wp-pdf-reader' ); ?></option>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( isset( $current['category'] ) ? $current['category'] : '', $term->slug ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<?php if ( count( $languages ) > 1 ) : ?>
			<p class="wppdf-filters__field">
				<label for="wppdf-filter-language"><?php esc_html_e( 'Available in', 'wp-pdf-reader' ); ?></label>
				<select id="wppdf-filter-language" name="<?php echo esc_attr( WPPDF_Filters::VAR_LANGUAGE ); ?>">
					<option value=""><?php esc_html_e( 'Any language', 'wp-pdf-reader' ); ?></option>
					<?php foreach ( $languages as $code => $language ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( isset( $current['language'] ) ? $current['language'] : '', $code ); ?>>
							<?php echo esc_html( $language['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<?php if ( count( $years ) > 1 ) : ?>
			<p class="wppdf-filters__field">
				<label for="wppdf-filter-year"><?php esc_html_e( 'Year', 'wp-pdf-reader' ); ?></label>
				<select id="wppdf-filter-year" name="<?php echo esc_attr( WPPDF_Filters::VAR_YEAR ); ?>">
					<option value=""><?php esc_html_e( 'All', 'wp-pdf-reader' ); ?></option>
					<?php foreach ( $years as $year ) : ?>
						<option value="<?php echo esc_attr( $year ); ?>" <?php selected( isset( $current['year'] ) ? (int) $current['year'] : 0, $year ); ?>>
							<?php echo esc_html( $year ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<p class="wppdf-filters__field">
			<label for="wppdf-filter-sort"><?php esc_html_e( 'Sort by', 'wp-pdf-reader' ); ?></label>
			<select id="wppdf-filter-sort" name="<?php echo esc_attr( WPPDF_Filters::VAR_SORT ); ?>">
				<?php foreach ( $sorts as $key => $sort ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( isset( $current['sort'] ) ? $current['sort'] : '', $key ); ?>>
						<?php echo esc_html( $sort['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="wppdf-filters__actions">
			<button type="submit" class="wppdf-filters__submit"><?php esc_html_e( 'Filter', 'wp-pdf-reader' ); ?></button>
			<?php if ( $filtered ) : ?>
				<a class="wppdf-filters__reset" href="<?php echo esc_url( get_post_type_archive_link( WPPDF_Post_Type::get_key() ) ); ?>">
					<?php esc_html_e( 'Clear', 'wp-pdf-reader' ); ?>
				</a>
			<?php endif; ?>
		</p>
	</div>
</form>
