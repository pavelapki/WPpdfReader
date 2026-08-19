<?php
/**
 * Structured data and social metadata for single documents.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO output.
 */
class WPPDF_Seo {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 5 );

		foreach ( self::description_filters() as $filter ) {
			add_filter( $filter, array( $this, 'filter_description' ) );
		}
	}

	/**
	 * The filters an SEO plugin runs its descriptions through.
	 *
	 * @return string[]
	 */
	protected static function description_filters() {
		return array(
			'wpseo_metadesc',                          // Yoast.
			'wpseo_opengraph_desc',
			'wpseo_twitter_description',
			'rank_math/frontend/description',          // Rank Math.
			'rank_math/opengraph/facebook/description',
			'rank_math/opengraph/twitter/description',
			'seopress_titles_desc',                    // SEOPress.
			'seopress_social_og_desc',
			'aioseo_description',                      // All in One SEO.
			'aioseo_og_description',
			'aioseo_twitter_description',
		);
	}

	/**
	 * Whether a dedicated SEO plugin already handles Open Graph tags.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| class_exists( 'RankMath' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' )
			|| function_exists( 'aioseo' );

		/**
		 * Filter whether Open Graph output is left to another plugin.
		 *
		 * @param bool $active Whether an SEO plugin is handling it.
		 */
		return (bool) apply_filters( 'wppdf_seo_plugin_active', $active );
	}

	/**
	 * What a document says about itself.
	 *
	 * The excerpt when there is one. A document's own words otherwise live in
	 * the PDF rather than in the post, so the extracted text is the next best
	 * thing — and on a page whose post content is empty it is the only thing.
	 *
	 * @param int        $post_id Document ID.
	 * @param array|null $file    Resolved file, looked up when not given.
	 * @return string Empty when the document says nothing.
	 */
	public static function get_description( $post_id, $file = null ) {
		$description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$description = $description ? wp_trim_words( $description, 40 ) : '';

		if ( '' !== $description && ! self::looks_like_reader( $description ) ) {
			return $description;
		}

		if ( null === $file ) {
			$file = WPPDF_Documents::get_file( $post_id );
		}

		if ( $file ) {
			$text = WPPDF_Text::get_text( $file['post_id'], $file['lang'] );

			if ( '' !== $text ) {
				return wp_trim_words( $text, 40 );
			}
		}

		return '';
	}

	/**
	 * Whether a description was built out of the reader's own interface.
	 *
	 * The reader is part of a document's content, so a plugin that summarises
	 * the page ends up with the toolbar: "This is the Czech version… Search in
	 * the document… Loading document…". That reads like a broken page, which is
	 * both a poor search result and one of the things that gets an address
	 * filed as a soft 404, so it is replaced with what the document says.
	 *
	 * @param string $description Description to test.
	 * @return bool
	 */
	protected static function looks_like_reader( $description ) {
		$description = (string) $description;

		if ( '' === $description ) {
			return false;
		}

		// Both the translated strings and the originals: the summary may have
		// been built in another locale than the one printing it.
		$markers = array(
			__( 'Loading document…', 'wp-pdf-reader' ),
			__( 'Search in the document', 'wp-pdf-reader' ),
			__( 'Pages to print', 'wp-pdf-reader' ),
			__( 'Open the PDF', 'wp-pdf-reader' ),
			'Loading document…',
			'Search in the document',
			'Pages to print',
			'Open the PDF',
		);

		foreach ( array_unique( $markers ) as $marker ) {
			if ( false !== strpos( $description, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace an SEO plugin's description when the reader leaked into it.
	 *
	 * Anything the site actually wrote is left alone.
	 *
	 * @param string $description Description the plugin worked out.
	 * @return string
	 */
	public function filter_description( $description ) {
		if ( ! is_string( $description ) || ! self::looks_like_reader( $description ) ) {
			return $description;
		}

		if ( ! is_singular( WPPDF_Post_Type::get_supported_post_types() ) ) {
			return $description;
		}

		$post_id = get_queried_object_id();

		// An empty description beats a description made of button labels: the
		// search engine then writes one from the page instead of quoting the
		// toolbar back at the reader.
		return $post_id ? self::get_description( $post_id ) : '';
	}

	/**
	 * Print the metadata for the current document.
	 */
	public function render() {
		if ( ! WPPDF_Settings::get( 'seo_metadata' ) ) {
			return;
		}

		if ( ! is_singular( WPPDF_Post_Type::get_supported_post_types() ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		$file    = $post_id ? WPPDF_Documents::get_file( $post_id ) : null;

		if ( ! $file ) {
			return;
		}

		$title     = get_the_title( $post_id );
		$permalink = get_permalink( $post_id );

		// A fallback page is a copy of the language version that holds the
		// file, so it names that one as its own address too.
		$canonical = WPPDF_Canonical::get_target();

		if ( '' !== $canonical ) {
			$permalink = $canonical;
		}

		$description = self::get_description( $post_id, $file );
		$cover_id    = WPPDF_Documents::get_cover_id( $post_id, $file['lang'] );
		$cover       = $cover_id ? wp_get_attachment_image_url( $cover_id, 'large' ) : '';
		$pages       = WPPDF_Text::get_page_count( $post_id, $file['lang'] );

		$data = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'DigitalDocument',
			'name'           => $title,
			'url'            => $permalink,
			'inLanguage'     => $file['lang'],
			'encodingFormat' => 'application/pdf',
			'contentUrl'     => $file['url'],
			'datePublished'  => get_the_date( 'c', $post_id ),
			'dateModified'   => get_the_modified_date( 'c', $post_id ),
		);

		if ( $description ) {
			$data['description'] = $description;
		}

		if ( $cover ) {
			$data['thumbnailUrl'] = $cover;
		}

		if ( $file['filesize'] > 0 ) {
			$data['contentSize'] = (string) $file['filesize'];
		}

		if ( $pages > 0 ) {
			$data['numberOfPages'] = $pages;
		}

		$author = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) );

		if ( $author ) {
			$data['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
			);
		}

		/**
		 * Filter the JSON-LD data of a document.
		 *
		 * @param array $data    Structured data.
		 * @param int   $post_id Document ID.
		 */
		$data = apply_filters( 'wppdf_schema_data', $data, $post_id );

		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";

		if ( self::seo_plugin_active() ) {
			return;
		}

		printf( '<meta property="og:type" content="article" />%s', "\n" );
		printf( '<meta property="og:title" content="%s" />%s', esc_attr( $title ), "\n" );
		printf( '<meta property="og:url" content="%s" />%s', esc_url( $permalink ), "\n" );
		printf( '<meta property="og:locale" content="%s" />%s', esc_attr( str_replace( '-', '_', $file['lang'] ) ), "\n" );

		if ( $description ) {
			printf( '<meta property="og:description" content="%s" />%s', esc_attr( $description ), "\n" );
		}

		if ( $cover ) {
			printf( '<meta property="og:image" content="%s" />%s', esc_url( $cover ), "\n" );
			printf( '<meta name="twitter:card" content="summary_large_image" />%s', "\n" );
		} else {
			printf( '<meta name="twitter:card" content="summary" />%s', "\n" );
		}
	}
}
