<?php
/**
 * Shortcodes.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handlers.
 */
class WPPDF_Shortcodes {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_shortcode( 'pdf_reader', array( $this, 'reader' ) );
		add_shortcode( 'pdf_grid', array( $this, 'grid' ) );
		add_shortcode( 'pdf_list', array( $this, 'grid' ) );
		add_shortcode( 'pdf_download', array( $this, 'download' ) );
		add_shortcode( 'pdf_links', array( $this, 'links' ) );
	}

	/**
	 * [pdf_reader id="12" lang="en" height="800"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function reader( $atts, $content = '', $tag = 'pdf_reader' ) {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'lang'     => '',
				'height'   => (int) WPPDF_Settings::get( 'viewer_height' ),
				'zoom'     => WPPDF_Settings::get( 'viewer_zoom' ),
				'page'     => 1,
				'toolbar'  => WPPDF_Settings::get( 'show_toolbar' ),
				'download' => WPPDF_Settings::get( 'allow_download' ),
				'print'    => WPPDF_Settings::get( 'allow_print' ),
				'lazy'     => WPPDF_Settings::get( 'lazy_load' ),
				'class'    => '',
			),
			$atts,
			$tag
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		return WPPDF_Viewer::render(
			$post_id,
			array(
				'lang'     => WPPDF_Settings::sanitize_language_code( $atts['lang'] ),
				'height'   => (int) $atts['height'],
				'zoom'     => sanitize_text_field( $atts['zoom'] ),
				'page'     => absint( $atts['page'] ),
				'toolbar'  => self::to_bool( $atts['toolbar'] ),
				'download' => self::to_bool( $atts['download'] ),
				'print'    => self::to_bool( $atts['print'] ),
				'lazy'     => self::to_bool( $atts['lazy'] ),
				'class'    => sanitize_html_class( $atts['class'] ),
			)
		);
	}

	/**
	 * [pdf_grid columns="3" per_page="12" category="reports"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function grid( $atts, $content = '', $tag = 'pdf_grid' ) {
		$atts = shortcode_atts(
			array(
				'columns'    => (int) WPPDF_Settings::get( 'archive_columns' ),
				'per_page'   => (int) WPPDF_Settings::get( 'archive_per_page' ),
				'layout'     => 'pdf_list' === $tag ? 'list' : WPPDF_Settings::get( 'archive_layout' ),
				'category'   => '',
				'tag'        => '',
				'taxonomy'   => '',
				'terms'      => '',
				'ids'        => '',
				'exclude'    => '',
				'author'     => '',
				'orderby'    => 'date',
				'order'      => 'DESC',
				'search'     => '',
				'lang'       => '',
				'excerpt'    => 1,
				'meta'       => 1,
				'pagination' => 0,
				'filters'    => 0,
				'from_field' => '',
				'empty'      => 'hide',
			),
			$atts,
			$tag
		);

		$filters = self::to_bool( $atts['filters'] );

		$query_args = array(
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC',
		);

		$categories = self::to_array( $atts['category'] );

		if ( ! empty( $categories ) ) {
			$query_args = self::add_tax_condition( $query_args, self::category_condition( $categories ) );
		}

		if ( '' !== $atts['tag'] ) {
			$query_args['tag'] = sanitize_text_field( $atts['tag'] );
		}

		// Categories taken from the page's own field, for templates that show
		// only what belongs to that page.
		if ( '' !== $atts['from_field'] ) {
			$field = in_array( strtolower( $atts['from_field'] ), array( '1', 'true', 'yes', 'on' ), true )
				? ''
				: sanitize_key( $atts['from_field'] );

			$term_ids = WPPDF_Acf::get_term_ids( get_the_ID(), $field );

			if ( empty( $term_ids ) ) {
				// Showing the whole library would be worse than showing none.
				if ( 'all' !== $atts['empty'] ) {
					return $filters ? WPPDF_Filters::render() : '';
				}
			} else {
				// The terms may sit in any taxonomy the documents use, so all
				// of them are asked and a hit in one is enough.
				$condition = array( 'relation' => 'OR' );

				foreach ( WPPDF_Post_Type::get_document_taxonomies() as $taxonomy ) {
					$condition[] = array(
						'taxonomy'         => $taxonomy,
						'field'            => 'term_id',
						'terms'            => $term_ids,
						'include_children' => is_taxonomy_hierarchical( $taxonomy ),
					);
				}

				if ( count( $condition ) > 1 ) {
					$query_args = self::add_tax_condition( $query_args, $condition );
				}
			}
		}

		if ( '' !== $atts['taxonomy'] && '' !== $atts['terms'] ) {
			$query_args = self::add_tax_condition(
				$query_args,
				array(
					'taxonomy' => sanitize_key( $atts['taxonomy'] ),
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', self::to_array( $atts['terms'] ) ),
				)
			);
		}

		if ( '' !== $atts['ids'] ) {
			$query_args['post__in'] = array_map( 'absint', self::to_array( $atts['ids'] ) );
			if ( 'date' === $query_args['orderby'] ) {
				$query_args['orderby'] = 'post__in';
			}
		}

		if ( '' !== $atts['exclude'] ) {
			$query_args['post__not_in'] = array_map( 'absint', self::to_array( $atts['exclude'] ) );
		}

		if ( '' !== $atts['author'] ) {
			$query_args['author'] = sanitize_text_field( $atts['author'] );
		}

		if ( '' !== $atts['search'] ) {
			$query_args['s'] = sanitize_text_field( $atts['search'] );
		}

		if ( self::to_bool( $atts['pagination'] ) ) {
			$query_args['paged'] = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		}

		if ( $filters ) {
			$query_args = WPPDF_Filters::apply_to_args( $query_args );
		}

		$query = WPPDF_Documents::query( $query_args );

		$html = WPPDF_Templates::get_part(
			'parts/archive-loop.php',
			array(
				'query'      => $query,
				'columns'    => min( 6, max( 1, (int) $atts['columns'] ) ),
				'layout'     => 'list' === $atts['layout'] ? 'list' : 'grid',
				'lang'       => WPPDF_Settings::sanitize_language_code( $atts['lang'] ),
				'excerpt'    => self::to_bool( $atts['excerpt'] ),
				'show_meta'  => self::to_bool( $atts['meta'] ),
				'pagination' => self::to_bool( $atts['pagination'] ),
			)
		);

		wp_reset_postdata();

		if ( $filters ) {
			$html = WPPDF_Filters::render( array( 'action' => get_permalink() ? get_permalink() : home_url( '/' ) ) ) . $html;
		}

		return $html;
	}

	/**
	 * [pdf_download id="12" text="Download the report"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function download( $atts, $content = '', $tag = 'pdf_download' ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'lang'  => '',
				'text'  => '',
				'class' => '',
				'meta'  => 1,
			),
			$atts,
			$tag
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$file = $post_id ? WPPDF_Documents::get_file( $post_id, WPPDF_Settings::sanitize_language_code( $atts['lang'] ) ) : null;

		if ( ! $file ) {
			return '';
		}

		wp_enqueue_style( 'wppdf-archive' );

		$text  = '' !== $atts['text'] ? $atts['text'] : __( 'Download PDF', 'wp-pdf-reader' );
		$class = 'wppdf-download-link';
		if ( $atts['class'] ) {
			$class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		$meta = '';
		if ( self::to_bool( $atts['meta'] ) ) {
			$parts = array( strtoupper( $file['lang'] ) );
			$size  = WPPDF_Documents::format_filesize( $file['filesize'] );
			if ( $size ) {
				$parts[] = $size;
			}
			$meta = ' <span class="wppdf-download-link__meta">(' . esc_html( implode( ', ', $parts ) ) . ')</span>';
		}

		return sprintf(
			'<a class="%1$s" href="%2$s" download>%3$s%4$s</a>',
			esc_attr( $class ),
			esc_url( $file['url'] ),
			esc_html( $text ),
			$meta
		);
	}

	/**
	 * [pdf_links category="vyrocni-zpravy,ceniky"]
	 *
	 * A bare list of links to the documents, titled in the language the site
	 * is shown in, or its fallback. No covers or cards: for a page that only
	 * needs to point at them.
	 *
	 * The list is the same on every language version of the site. A document
	 * carries its languages inside it — a PDF and a title for each — so only
	 * the titles and addresses follow the page, not which documents appear.
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function links( $atts, $content = '', $tag = 'pdf_links' ) {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'lang'     => '',
				'orderby'  => 'date',
				'order'    => 'DESC',
				'limit'    => 0,
				'class'    => '',
			),
			$atts,
			$tag
		);

		$query_args = array(
			// Every match is fetched and the limit counted on what is printed,
			// after translations are folded together and documents without a
			// PDF dropped, or those would take places in it.
			'posts_per_page' => -1,
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC',
			'no_found_rows'  => true,
		);

		// WPML and Polylang would pick the documents and the categories by the
		// page's language, and an English page found no Czech category and
		// listed nothing. They are told to look at every language while the
		// documents are picked, and put back before anything is printed.
		$language = self::lift_language_filter();

		if ( function_exists( 'pll_current_language' ) ) {
			$query_args['lang'] = '';
		}

		$categories = self::to_array( $atts['category'] );

		if ( ! empty( $categories ) ) {
			$query_args = self::add_tax_condition( $query_args, self::category_condition( $categories ) );
		}

		$query = WPPDF_Documents::query( $query_args );

		self::restore_language_filter( $language );

		$post_ids = array();

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_ids[] = get_the_ID();
		}

		wp_reset_postdata();

		$lang     = WPPDF_Settings::sanitize_language_code( $atts['lang'] );
		$post_ids = WPPDF_Documents::one_per_translation( $post_ids, $lang );
		$limit    = absint( $atts['limit'] );
		$items    = array();

		foreach ( $post_ids as $post_id ) {
			if ( $limit && count( $items ) >= $limit ) {
				break;
			}

			// A document with no PDF in any language would open on an empty
			// reader, so it is not offered.
			if ( ! WPPDF_Documents::get_file( $post_id, $lang ) ) {
				continue;
			}

			$title = WPPDF_Documents::get_language_title( $post_id, $lang );
			if ( '' === $title ) {
				$title = get_the_title( $post_id );
			}

			// The permalink already carries the current language's slug; a
			// pinned language needs that language's address instead.
			$url = '' !== $lang ? WPPDF_Permalinks::get_language_permalink( $post_id, $lang ) : get_permalink( $post_id );

			if ( ! $url ) {
				continue;
			}

			$items[] = sprintf(
				'<li class="wppdf-links__item"><a href="%1$s">%2$s</a></li>',
				esc_url( wp_make_link_relative( $url ) ),
				esc_html( $title )
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		$class = 'wppdf-links';
		if ( $atts['class'] ) {
			$class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		return '<ul class="' . esc_attr( $class ) . '">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * A tax_query condition matching documents in any of some categories.
	 *
	 * Categories live wherever the documents keep them — the shared post
	 * categories, the plugin's own taxonomy, or both — so each is asked and a
	 * hit in one is enough. Tags are left out: a tag that shares a slug with a
	 * category is not what category="" asked for.
	 *
	 * Under WPML or Polylang a category is one term per language, the Czech
	 * "docs" being "docs-2" in English, and a document sits in only one of
	 * them. The terms are therefore matched together with their translations,
	 * so either slug finds the documents.
	 *
	 * @param string[] $slugs Category slugs.
	 * @return array
	 */
	public static function category_condition( array $slugs ) {
		$slugs      = array_values( array_map( 'sanitize_title', $slugs ) );
		$taxonomies = array_values( array_filter( WPPDF_Post_Type::get_document_taxonomies(), 'is_taxonomy_hierarchical' ) );
		$terms      = array();

		if ( ! empty( $taxonomies ) ) {
			$args = array(
				'taxonomy'   => $taxonomies,
				'slug'       => $slugs,
				'hide_empty' => false,
			);

			if ( function_exists( 'pll_current_language' ) ) {
				$args['lang'] = '';
			}

			$terms = get_terms( $args );
		}

		$found = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			foreach ( self::get_term_translation_ids( $term ) as $term_taxonomy_id ) {
				$found[ $term->taxonomy ][] = $term_taxonomy_id;
			}
		}

		$condition = array( 'relation' => 'OR' );

		foreach ( $found as $taxonomy => $term_taxonomy_ids ) {
			$condition[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_taxonomy_id',
				'terms'    => array_values( array_unique( $term_taxonomy_ids ) ),
			);
		}

		// No such category: a condition nothing can meet, so the result comes
		// out empty rather than as the whole library.
		if ( 1 === count( $condition ) ) {
			$condition[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( 0 ),
			);
		}

		return $condition;
	}

	/**
	 * A category term and its WPML or Polylang translations.
	 *
	 * @param WP_Term $term Category term.
	 * @return int[] Term taxonomy IDs, the term's own first.
	 */
	protected static function get_term_translation_ids( $term ) {
		$ids = array( (int) $term->term_taxonomy_id );

		// WPML keys a term's translations by its term_taxonomy_id.
		if ( has_filter( 'wpml_element_trid' ) ) {
			$type = 'tax_' . $term->taxonomy;

			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filters, consumed rather than introduced.
			$trid = apply_filters( 'wpml_element_trid', null, $term->term_taxonomy_id, $type );

			if ( $trid ) {
				foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $translation ) {
					if ( is_object( $translation ) && ! empty( $translation->element_id ) ) {
						$ids[] = (int) $translation->element_id;
					}
				}
			}
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		if ( function_exists( 'pll_get_term_translations' ) ) {
			foreach ( (array) pll_get_term_translations( $term->term_id ) as $term_id ) {
				$translated = get_term( (int) $term_id, $term->taxonomy );

				if ( $translated && ! is_wp_error( $translated ) ) {
					$ids[] = (int) $translated->term_taxonomy_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Let WPML query every language until restore_language_filter().
	 *
	 * @return string|false The language to go back to, false when nothing changed.
	 */
	protected static function lift_language_filter() {
		if ( ! has_filter( 'wpml_current_language' ) ) {
			return false;
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hooks, consumed rather than introduced.
		$current = apply_filters( 'wpml_current_language', null );

		do_action( 'wpml_switch_language', 'all' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return (string) $current;
	}

	/**
	 * Put back the language lift_language_filter() switched away from.
	 *
	 * @param string|false $language Its return value.
	 */
	protected static function restore_language_filter( $language ) {
		if ( false === $language ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action, consumed rather than introduced.
		do_action( 'wpml_switch_language', '' !== $language ? $language : null );
	}

	/**
	 * Add a condition to a query's tax_query, narrowing what is already there.
	 *
	 * Each condition goes in as a group of its own under the default AND, so
	 * the OR inside one — "any of these categories" — never widens another,
	 * such as the category picked in the filter bar, which is appended the
	 * same way.
	 *
	 * @param array $query_args Query arguments.
	 * @param array $condition  A tax_query clause or nested group.
	 * @return array
	 */
	protected static function add_tax_condition( array $query_args, array $condition ) {
		$tax_query   = isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
		$tax_query[] = $condition;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- listing the documents of a category is what the shortcodes are for.
		$query_args['tax_query'] = $tax_query;

		return $query_args;
	}

	/**
	 * Cast a shortcode attribute to a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Split a comma separated attribute.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		return array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
	}
}
