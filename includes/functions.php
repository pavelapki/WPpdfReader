<?php
/**
 * Template tags and public helpers.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

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
