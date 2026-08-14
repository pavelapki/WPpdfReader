<?php
/**
 * Filtering the document archive: full text, category, language and year.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive filters.
 */
class WPPDF_Filters {

	/**
	 * Query variable holding the search term.
	 */
	const VAR_SEARCH = 'wppdf_q';

	/**
	 * Query variable holding the category.
	 */
	const VAR_CATEGORY = 'wppdf_cat';

	/**
	 * Query variable holding the language.
	 */
	const VAR_LANGUAGE = 'wppdf_lang_filter';

	/**
	 * Query variable holding the year.
	 */
	const VAR_YEAR = 'wppdf_year';

	/**
	 * Query variable holding the sort order.
	 */
	const VAR_SORT = 'wppdf_sort';

	/**
	 * Memoised request values.
	 *
	 * @var array|null
	 */
	protected static $current = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'pre_get_posts', array( $this, 'filter_archive' ) );
		add_action( 'save_post', array( $this, 'flush_years' ) );
		add_action( 'deleted_post', array( $this, 'flush_years' ) );
	}

	/**
	 * Drop the cached list of years when the library changes.
	 *
	 * @param int $post_id Post ID.
	 */
	public function flush_years( $post_id ) {
		if ( in_array( get_post_type( $post_id ), WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			delete_transient( 'wppdf_years' );
		}
	}

	/**
	 * The values the visitor selected, sanitized.
	 *
	 * @return array
	 */
	public static function get_current() {
		// Read once per request: the archive query, the form and every link
		// builder ask for this, and $_GET does not change in between.
		if ( null !== self::$current ) {
			return self::$current;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only front end filter.
		$search   = isset( $_GET[ self::VAR_SEARCH ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::VAR_SEARCH ] ) ) : '';
		$category = isset( $_GET[ self::VAR_CATEGORY ] ) ? sanitize_title( wp_unslash( $_GET[ self::VAR_CATEGORY ] ) ) : '';
		$language = isset( $_GET[ self::VAR_LANGUAGE ] ) ? wppdf_sanitize_language_code( wp_unslash( $_GET[ self::VAR_LANGUAGE ] ) ) : '';
		$year     = isset( $_GET[ self::VAR_YEAR ] ) ? absint( wp_unslash( $_GET[ self::VAR_YEAR ] ) ) : 0;
		$sort     = isset( $_GET[ self::VAR_SORT ] ) ? sanitize_key( wp_unslash( $_GET[ self::VAR_SORT ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $language && ! in_array( $language, WPPDF_Languages::get_codes(), true ) ) {
			$language = '';
		}

		if ( ! array_key_exists( $sort, self::get_sort_options() ) ) {
			$sort = '';
		}

		self::$current = array(
			'search'   => $search,
			'category' => $category,
			'language' => $language,
			'year'     => $year,
			'sort'     => $sort,
		);

		return self::$current;
	}

	/**
	 * Drop the memoised request values.
	 */
	public static function flush_cache() {
		self::$current = null;
	}

	/**
	 * Whether any filter is active.
	 *
	 * @return bool
	 */
	public static function is_filtered() {
		foreach ( self::get_current() as $value ) {
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sort orders offered.
	 *
	 * @return array Map of key => array( label, orderby, order ).
	 */
	public static function get_sort_options() {
		return array(
			'newest' => array(
				'label'   => __( 'Newest first', 'wp-pdf-reader' ),
				'orderby' => 'date',
				'order'   => 'DESC',
			),
			'oldest' => array(
				'label'   => __( 'Oldest first', 'wp-pdf-reader' ),
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			'title'  => array(
				'label'   => __( 'Title A–Z', 'wp-pdf-reader' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			),
			'manual' => array(
				'label'   => __( 'Manual order', 'wp-pdf-reader' ),
				'orderby' => 'menu_order title',
				'order'   => 'ASC',
			),
		);
	}

	/**
	 * Apply the filters to a query.
	 *
	 * Shared by the archive and by the shortcode, so both behave the same.
	 *
	 * @param array $args    Query arguments to extend.
	 * @param array $current Selected values, defaults to the request.
	 * @return array
	 */
	public static function apply_to_args( array $args, array $current = null ) {
		$current = null === $current ? self::get_current() : $current;

		if ( '' !== $current['search'] ) {
			$args['s'] = $current['search'];
		}

		if ( '' !== $current['category'] ) {
			$taxonomy = WPPDF_Settings::get( 'own_taxonomy' ) && ! WPPDF_Settings::get( 'shared_taxonomies' )
				? WPPDF_Post_Type::OWN_TAXONOMY
				: 'category';

			$tax_query = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array();

			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => array( $current['category'] ),
			);

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering by category is the point of the filter; the taxonomy tables are indexed for it.
			$args['tax_query'] = $tax_query;
		}

		if ( '' !== $current['language'] ) {
			$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

			$meta_query[] = WPPDF_Documents::language_meta_query( $current['language'] );

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- which languages a document has is only recorded in meta, so there is no taxonomy to filter on instead.
			$args['meta_query'] = $meta_query;
		}

		if ( $current['year'] > 0 ) {
			$args['date_query'] = array(
				array( 'year' => $current['year'] ),
			);
		}

		if ( '' !== $current['sort'] ) {
			$options = self::get_sort_options();

			$args['orderby'] = $options[ $current['sort'] ]['orderby'];
			$args['order']   = $options[ $current['sort'] ]['order'];
		}

		return $args;
	}

	/**
	 * Apply the filters to the main archive query.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public function filter_archive( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_post_type_archive( WPPDF_Post_Type::get_key() ) ) {
			return;
		}

		if ( ! WPPDF_Settings::get( 'archive_filters' ) || ! self::is_filtered() ) {
			return;
		}

		$current = self::get_current();

		if ( '' !== $current['search'] ) {
			// Only the term is set, not the search flag, so the archive keeps
			// its own template. The text search join keys off the term.
			$query->set( 's', $current['search'] );
		}

		$args = self::apply_to_args( array(), $current );

		foreach ( array( 'tax_query', 'meta_query', 'date_query', 'orderby', 'order' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$query->set( $key, $args[ $key ] );
			}
		}
	}

	/**
	 * Build the URL of the archive with a set of filters applied.
	 *
	 * @param array $overrides Values to change.
	 * @return string
	 */
	public static function get_url( array $overrides = array() ) {
		$current = array_merge( self::get_current(), $overrides );

		$base = get_post_type_archive_link( WPPDF_Post_Type::get_key() );

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		$query = array_filter(
			array(
				self::VAR_SEARCH   => $current['search'],
				self::VAR_CATEGORY => $current['category'],
				self::VAR_LANGUAGE => $current['language'],
				self::VAR_YEAR     => $current['year'] ? $current['year'] : '',
				self::VAR_SORT     => $current['sort'],
			),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		return empty( $query ) ? $base : add_query_arg( $query, $base );
	}

	/**
	 * Render the filter form.
	 *
	 * @param array $args Overrides passed to the template.
	 * @return string
	 */
	public static function render( array $args = array() ) {
		$taxonomy = WPPDF_Settings::get( 'own_taxonomy' ) && ! WPPDF_Settings::get( 'shared_taxonomies' )
			? WPPDF_Post_Type::OWN_TAXONOMY
			: 'category';

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$defaults = array(
			'action'    => get_post_type_archive_link( WPPDF_Post_Type::get_key() ),
			'terms'     => $terms,
			'languages' => WPPDF_Languages::get_languages(),
			'years'     => WPPDF_Documents::get_years(),
			'sorts'     => self::get_sort_options(),
			'current'   => self::get_current(),
			'filtered'  => self::is_filtered(),
		);

		return WPPDF_Templates::get_part( 'parts/filters.php', wp_parse_args( $args, $defaults ) );
	}
}
