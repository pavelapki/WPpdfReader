<?php
/**
 * Template tags and public helpers.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wppdf_sanitize_language_code' ) ) {
	/**
	 * Reduce a language code to the letters, digits and dashes it may contain.
	 *
	 * Every request that names a language goes through this, so it lives here
	 * as a plain function: static analysis can recognise a sanitizer by name,
	 * which it cannot do for a class method.
	 *
	 * @param mixed $code Raw value.
	 * @return string Sanitized code, empty when there is nothing usable left.
	 */
	function wppdf_sanitize_language_code( $code ) {
		return WPPDF_Settings::sanitize_language_code( $code );
	}
}

if ( ! function_exists( 'wppdf_get_file' ) ) {
	/**
	 * Resolve the PDF to show for a document, applying the fallback chain.
	 *
	 * @param int    $post_id Post ID. Defaults to the current post.
	 * @param string $lang    Language code. Defaults to the current language.
	 * @return array|null
	 */
	function wppdf_get_file( $post_id = 0, $lang = '' ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();

		return $post_id ? WPPDF_Documents::get_file( $post_id, $lang ) : null;
	}
}

if ( ! function_exists( 'wppdf_get_file_url' ) ) {
	/**
	 * URL of the resolved PDF.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return string
	 */
	function wppdf_get_file_url( $post_id = 0, $lang = '' ) {
		$file = wppdf_get_file( $post_id, $lang );

		return $file ? $file['url'] : '';
	}
}

if ( ! function_exists( 'wppdf_has_file' ) ) {
	/**
	 * Whether a document resolves to any file.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return bool
	 */
	function wppdf_has_file( $post_id = 0, $lang = '' ) {
		return null !== wppdf_get_file( $post_id, $lang );
	}
}

if ( ! function_exists( 'wppdf_get_available_languages' ) ) {
	/**
	 * Language codes that have a file on this document.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	function wppdf_get_available_languages( $post_id = 0 ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();

		return $post_id ? WPPDF_Documents::get_available_languages( $post_id ) : array();
	}
}

if ( ! function_exists( 'wppdf_get_viewer' ) ) {
	/**
	 * Reader markup for a document.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Reader arguments.
	 * @return string
	 */
	function wppdf_get_viewer( $post_id = 0, array $args = array() ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();

		return $post_id ? WPPDF_Viewer::render( $post_id, $args ) : '';
	}
}

if ( ! function_exists( 'wppdf_the_viewer' ) ) {
	/**
	 * Echo the reader for a document.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Reader arguments.
	 */
	function wppdf_the_viewer( $post_id = 0, array $args = array() ) {
		echo wppdf_get_viewer( $post_id, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built by the plugin.
	}
}

if ( ! function_exists( 'wppdf_get_cover_id' ) ) {
	/**
	 * Cover image attachment ID for a document.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	function wppdf_get_cover_id( $post_id = 0, $lang = '' ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();

		return $post_id ? WPPDF_Documents::get_cover_id( $post_id, $lang ) : 0;
	}
}

if ( ! function_exists( 'wppdf_get_post_type' ) ) {
	/**
	 * The configured document post type key.
	 *
	 * @return string
	 */
	function wppdf_get_post_type() {
		return WPPDF_Post_Type::get_key();
	}
}

if ( ! function_exists( 'wppdf_get_page_categories' ) ) {
	/**
	 * The document category IDs a page selects in its field.
	 *
	 * @param int    $post_id Page ID. Defaults to the current post.
	 * @param string $field   Field name. Defaults to the configured one.
	 * @return int[]
	 */
	function wppdf_get_page_categories( $post_id = 0, $field = '' ) {
		return WPPDF_Acf::get_term_ids( $post_id, $field );
	}
}

if ( ! function_exists( 'wppdf_get_page_documents' ) ) {
	/**
	 * Markup listing the documents that belong to a page.
	 *
	 * Reads the categories from the page's field and lists only those
	 * documents. Nothing is printed when the field is empty.
	 *
	 * @param array $args {
	 *     Optional overrides, matching the [pdf_grid] attributes.
	 *
	 *     @type int    $columns  Columns in the grid.
	 *     @type int    $per_page How many documents at most.
	 *     @type string $layout   grid or list.
	 *     @type string $orderby  Sorting field.
	 *     @type string $order    ASC or DESC.
	 *     @type string $field    Field to read instead of the configured one.
	 *     @type string $empty    hide (default) or all.
	 * }
	 * @return string
	 */
	function wppdf_get_page_documents( array $args = array() ) {
		$shortcodes = new WPPDF_Shortcodes();

		$atts = wp_parse_args(
			$args,
			array(
				'from_field' => isset( $args['field'] ) && '' !== $args['field'] ? $args['field'] : '1',
			)
		);

		unset( $atts['field'] );

		return $shortcodes->grid( $atts, '', 'pdf_grid' );
	}
}

if ( ! function_exists( 'wppdf_the_page_documents' ) ) {
	/**
	 * Echo the documents that belong to a page.
	 *
	 * @param array $args Overrides, see wppdf_get_page_documents().
	 */
	function wppdf_the_page_documents( array $args = array() ) {
		echo wppdf_get_page_documents( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built by the plugin.
	}
}

if ( ! function_exists( 'wppdf_get_back_url' ) ) {
	/**
	 * Where the full page reader's back link should go.
	 *
	 * A page without the site's navigation needs a way out. The referring page
	 * is the honest answer when the visitor came from this site — a listing, a
	 * category, a search — and the document archive is the fallback for a
	 * direct hit or a link from elsewhere.
	 *
	 * @param int $post_id Document ID.
	 * @return string
	 */
	function wppdf_get_back_url( $post_id = 0 ) {
		$referer = wp_get_referer();
		$home    = wp_parse_url( home_url(), PHP_URL_HOST );

		// wp_get_referer() already refuses a referer from another host, but it
		// also returns the current URL when the visitor reloaded the reader,
		// which would make the link go nowhere.
		if ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) === $home && get_permalink( $post_id ) !== $referer ) {
			return $referer;
		}

		$archive = get_post_type_archive_link( WPPDF_Post_Type::get_key() );

		return $archive ? $archive : home_url( '/' );
	}
}

if ( ! function_exists( 'wppdf_get_current_language' ) ) {
	/**
	 * The language currently used to resolve documents.
	 *
	 * @return string
	 */
	function wppdf_get_current_language() {
		return WPPDF_Languages::get_current_language();
	}
}
