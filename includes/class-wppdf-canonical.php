<?php
/**
 * Where a document really lives once the language fell back.
 *
 * A fallback is deliberate: a visitor on /de/ or /pl/ gets the Czech or
 * English PDF rather than a 404. What that produces, though, is one address
 * per language all showing the same document — and to a search engine an
 * address whose only own words are "this document is not available in your
 * language" plus a reader that has not loaded yet looks like an error page.
 * Google files those as soft 404s.
 *
 * The honest answer is not to hide the fallback but to say where the content
 * actually is: the canonical link of a fallback page points at the language
 * version that holds the file. Search engines then treat it as a duplicate of
 * that page — which is exactly what it is — instead of as a broken one, and
 * the visitor keeps the page.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical addresses for fallback language pages.
 */
class WPPDF_Canonical {

	/**
	 * Resolved target for this request, null until worked out.
	 *
	 * @var string|null
	 */
	protected static $target = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_head', array( $this, 'render' ), 1 );

		// Every SEO plugin prints its own canonical and ignores WordPress's, so
		// the value has to be handed to whichever one is installed.
		$filters = array(
			'wpseo_canonical',                      // Yoast.
			'rank_math/frontend/canonical',         // Rank Math.
			'seopress_titles_canonical',            // SEOPress.
			'aioseo_canonical_url',                 // All in One SEO.
			'the_seo_framework_rel_canonical_output', // The SEO Framework.
		);

		foreach ( $filters as $filter ) {
			add_filter( $filter, array( $this, 'filter_canonical' ) );
		}
	}

	/**
	 * Forget the resolved target.
	 *
	 * Only the tests need this: one request answers one address.
	 */
	public static function flush_cache() {
		self::$target = null;
	}

	/**
	 * Hand the target to an SEO plugin's canonical.
	 *
	 * @param string $canonical Canonical the plugin worked out.
	 * @return string
	 */
	public function filter_canonical( $canonical ) {
		$target = self::get_target();

		return '' !== $target ? $target : $canonical;
	}

	/**
	 * Print the canonical link when no SEO plugin is doing it.
	 *
	 * WordPress's own rel_canonical() would print the address of the page we
	 * are on, which is the claim we are correcting, so it stands down.
	 */
	public function render() {
		$target = self::get_target();

		if ( '' === $target || WPPDF_Seo::seo_plugin_active() ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );

		printf( '<link rel="canonical" href="%s" />%s', esc_url( $target ), "\n" );
	}

	/**
	 * The address this page should be consolidated onto.
	 *
	 * @return string Empty when the page is not a fallback, or when the
	 *                language version cannot be addressed separately.
	 */
	public static function get_target() {
		if ( null === self::$target ) {
			self::$target = self::resolve();
		}

		return self::$target;
	}

	/**
	 * Work out the target once.
	 *
	 * @return string
	 */
	protected static function resolve() {
		if ( ! WPPDF_Settings::get( 'canonical_fallback' ) ) {
			return '';
		}

		if ( is_admin() || ! is_singular( WPPDF_Post_Type::get_supported_post_types() ) ) {
			return '';
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return '';
		}

		$file = WPPDF_Documents::get_file( $post_id );

		// No file at all is a different problem: there is no better address to
		// send anyone to, so the page keeps its own.
		if ( ! $file || empty( $file['is_fallback'] ) ) {
			return '';
		}

		$target = WPPDF_Permalinks::get_language_permalink( $file['post_id'], $file['lang'] );
		$here   = get_permalink( $post_id );

		// Without a multilingual plugin both languages share one address, and a
		// canonical pointing at the page it sits on says nothing.
		if ( '' === $target || self::is_same_address( $target, $here ) ) {
			return '';
		}

		/**
		 * Filter the canonical address of a fallback page.
		 *
		 * Return an empty string to leave the page canonical to itself.
		 *
		 * @param string $target  Address of the language version holding the file.
		 * @param int    $post_id Document being viewed.
		 * @param array  $file    Resolved file data.
		 */
		return (string) apply_filters( 'wppdf_canonical_url', $target, $post_id, $file );
	}

	/**
	 * Whether two addresses point at the same page.
	 *
	 * @param string $a First address.
	 * @param string $b Second address.
	 * @return bool
	 */
	protected static function is_same_address( $a, $b ) {
		return untrailingslashit( (string) $a ) === untrailingslashit( (string) $b );
	}
}
