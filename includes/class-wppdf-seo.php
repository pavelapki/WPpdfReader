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

		$title       = get_the_title( $post_id );
		$permalink   = get_permalink( $post_id );
		$description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$description = $description ? wp_trim_words( $description, 40 ) : '';
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
