<?php
/**
 * Make the text inside PDFs searchable through the normal WordPress search.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Search integration.
 */
class WPPDF_Search {

	/**
	 * Alias used for the joined postmeta table.
	 */
	const ALIAS = 'wppdf_text';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'posts_join', array( $this, 'join' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'search' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'distinct' ), 10, 2 );
	}

	/**
	 * Whether this query should look inside documents.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool
	 */
	protected function applies( $query ) {
		if ( ! WPPDF_Settings::get( 'search_pdf_text' ) ) {
			return false;
		}

		if ( ! is_a( $query, 'WP_Query' ) ) {
			return false;
		}

		// The archive filter sets only the search term, without turning the
		// query into a search query, so the term itself is what counts here.
		if ( '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}

		if ( $query->get( 'wppdf_skip_text_search' ) ) {
			return false;
		}

		$post_types = $query->get( 'post_type' );

		if ( ! empty( $post_types ) && 'any' !== $post_types ) {
			$post_types = (array) $post_types;

			if ( ! array_intersect( $post_types, WPPDF_Post_Type::get_supported_post_types() ) ) {
				return false;
			}
		}

		/**
		 * Filter whether a query searches the text of PDFs.
		 *
		 * @param bool     $applies Whether to join the extracted text.
		 * @param WP_Query $query   Query instance.
		 */
		return (bool) apply_filters( 'wppdf_search_applies', true, $query );
	}

	/**
	 * Join the extracted text of every language.
	 *
	 * @param string   $join  Existing JOIN clause.
	 * @param WP_Query $query Query instance.
	 * @return string
	 */
	public function join( $join, $query = null ) {
		global $wpdb;

		if ( ! $this->applies( $query ) ) {
			return $join;
		}

		$prefix = $wpdb->esc_like( WPPDF_Text::META_TEXT ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- only the table names and the class's own alias constant are concatenated; the one value goes through a placeholder.
		$join .= $wpdb->prepare(
			' LEFT JOIN ' . $wpdb->postmeta . ' AS ' . self::ALIAS .
			' ON ( ' . $wpdb->posts . '.ID = ' . self::ALIAS . '.post_id AND ' . self::ALIAS . '.meta_key LIKE %s ) ',
			$prefix
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $join;
	}

	/**
	 * Widen the generated search clause to the joined text column.
	 *
	 * WordPress builds one "post_title LIKE '%term%'" fragment per search
	 * term, including the negated ones. Mirroring each fragment onto the meta
	 * column keeps the term logic — including exclusions and sentence mode —
	 * exactly as WordPress intended it.
	 *
	 * @param string   $search Existing search clause.
	 * @param WP_Query $query  Query instance.
	 * @return string
	 */
	public function search( $search, $query = null ) {
		global $wpdb;

		if ( '' === trim( (string) $search ) || ! $this->applies( $query ) ) {
			return $search;
		}

		$pattern = '/\(\s*' . preg_quote( $wpdb->posts, '/' ) . '\.post_title\s+(NOT\s+)?LIKE\s*(\'[^\']*\')\s*\)/i';

		$widened = preg_replace(
			$pattern,
			'(' . $wpdb->posts . '.post_title $1LIKE $2) OR (' . self::ALIAS . '.meta_value $1LIKE $2)',
			$search
		);

		return null === $widened ? $search : $widened;
	}

	/**
	 * A document can hold text in several languages, so rows must be unique.
	 *
	 * @param string   $distinct Existing DISTINCT clause.
	 * @param WP_Query $query    Query instance.
	 * @return string
	 */
	public function distinct( $distinct, $query = null ) {
		if ( ! $this->applies( $query ) ) {
			return $distinct;
		}

		return 'DISTINCT';
	}
}
