<?php
/**
 * Per-language document slugs.
 *
 * A document is one post with one post_name, so WordPress gives it one
 * address. This lets each language carry its own last URL segment: the same
 * record answers on /pdf/vyrocni-zprava-2025/ and /pdf/annual-report-2025/.
 *
 * Which one is canonical follows the site language — the same rule that picks
 * the PDF — and the others redirect to it, so a document never has two live
 * addresses at once.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language aware permalinks.
 */
class WPPDF_Permalinks {

	/**
	 * Meta key prefix holding the slug of one language.
	 */
	const META_SLUG = '_wppdf_slug_';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'request', array( $this, 'resolve_request' ) );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 10, 3 );
	}

	/**
	 * Meta key for one language's slug.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function slug_meta_key( $code ) {
		return self::META_SLUG . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * The slug stored for one language.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @return string Empty when the language has none.
	 */
	public static function get_slug( $post_id, $code ) {
		$code = WPPDF_Settings::sanitize_language_code( $code );

		if ( '' === $code || ! $post_id ) {
			return '';
		}

		return (string) get_post_meta( $post_id, self::slug_meta_key( $code ), true );
	}

	/**
	 * The address a document should answer on right now.
	 *
	 * Falls back to the post's own slug, so a language without one keeps the
	 * address it always had.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code, defaults to the current one.
	 * @return string
	 */
	public static function get_current_slug( $post_id, $code = '' ) {
		$code = '' !== $code ? $code : WPPDF_Languages::get_current_language();

		return self::get_slug( $post_id, $code );
	}

	/**
	 * Store a language's slug, made unique and safe.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @param string $slug    Requested slug, empty to remove it.
	 * @return string The slug that was actually stored.
	 */
	public static function set_slug( $post_id, $code, $slug ) {
		$code = WPPDF_Settings::sanitize_language_code( $code );
		$key  = self::slug_meta_key( $code );
		$slug = sanitize_title( $slug );

		if ( '' === $code || '' === $slug ) {
			delete_post_meta( $post_id, $key );

			return '';
		}

		$slug = self::make_unique( $slug, $post_id );

		update_post_meta( $post_id, $key, $slug );

		return $slug;
	}

	/**
	 * Make a slug unique across post names and every language slug.
	 *
	 * Two documents answering on the same address would make which one wins
	 * depend on row order, so the second one gets a suffix — the same thing
	 * WordPress does to post_name.
	 *
	 * @param string $slug    Requested slug.
	 * @param int    $post_id Document the slug belongs to.
	 * @return string
	 */
	protected static function make_unique( $slug, $post_id ) {
		$candidate = $slug;
		$suffix    = 1;

		while ( self::is_taken( $candidate, $post_id ) && $suffix < 100 ) {
			++$suffix;
			$candidate = $slug . '-' . $suffix;
		}

		return $candidate;
	}

	/**
	 * Whether an address already belongs to another document.
	 *
	 * @param string $slug    Slug to test.
	 * @param int    $post_id Document that may keep it.
	 * @return bool
	 */
	protected static function is_taken( $slug, $post_id ) {
		global $wpdb;

		$post_types = WPPDF_Post_Type::get_supported_post_types();
		$types      = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $types is a list of %s built from a count and filled by prepare(), so the sniff cannot count them; a uniqueness check must see what was written a moment ago.
		$owner = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_name = %s
				AND post_type IN ( {$types} )
				AND post_status NOT IN ( 'trash', 'auto-draft' )
				AND ID != %d
				LIMIT 1",
				array_merge( array( $slug ), $post_types, array( (int) $post_id ) )
			)
		);

		if ( $owner ) {
			return true;
		}

		$owner = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key LIKE %s
				AND meta_value = %s
				AND post_id != %d
				LIMIT 1",
				$wpdb->esc_like( self::META_SLUG ) . '%',
				$slug,
				(int) $post_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (bool) $owner;
	}

	/**
	 * The document that answers on an address, by any of its languages.
	 *
	 * Every language is searched, not just the current one, so a link to the
	 * English address does not 404 on a Czech site — it redirects, which is
	 * what redirect_canonical does once the permalink disagrees.
	 *
	 * @param string $slug Address segment.
	 * @return int Document ID, 0 when nothing matches.
	 */
	public static function find_by_slug( $slug ) {
		global $wpdb;

		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return 0;
		}

		$post_types = WPPDF_Post_Type::get_supported_post_types();
		$types      = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $types is a list of %s built from a count and filled by prepare(), so the sniff cannot count them; this resolves a front end address, so a stale answer would serve the wrong document.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} AS m
				INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id
				WHERE m.meta_key LIKE %s
				AND m.meta_value = %s
				AND p.post_type IN ( {$types} )
				AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				LIMIT 1",
				array_merge( array( $wpdb->esc_like( self::META_SLUG ) . '%', $slug ), $post_types )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $found;
	}

	/**
	 * Point a request at the document whose language slug it names.
	 *
	 * Only runs when WordPress found nothing itself, so an ordinary address is
	 * never touched and the cost on a normal request is zero.
	 *
	 * @param array $vars Query variables.
	 * @return array
	 */
	public function resolve_request( $vars ) {
		if ( empty( $vars['name'] ) || empty( $vars['post_type'] ) ) {
			return $vars;
		}

		$post_type = is_array( $vars['post_type'] ) ? reset( $vars['post_type'] ) : $vars['post_type'];

		if ( ! in_array( $post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			return $vars;
		}

		$name = sanitize_title( $vars['name'] );

		// A real post_name wins: it is what WordPress would have served, and
		// looking further would let a language slug shadow another document.
		if ( get_page_by_path( $name, OBJECT, $post_type ) ) {
			return $vars;
		}

		$post_id = self::find_by_slug( $name );

		if ( ! $post_id ) {
			return $vars;
		}

		unset( $vars['name'], $vars[ $post_type ] );

		$vars['p']         = $post_id;
		$vars['post_type'] = $post_type;

		return $vars;
	}

	/**
	 * Swap the last segment of a permalink for the current language's slug.
	 *
	 * @param string  $link      Permalink so far.
	 * @param WP_Post $post      Post object.
	 * @param bool    $leavename Whether the name placeholder is kept.
	 * @return string
	 */
	public function filter_permalink( $link, $post, $leavename = false ) {
		// With $leavename the link still holds %postname%, which the editor
		// fills in itself; rewriting it would break the preview.
		if ( $leavename || ! is_object( $post ) || ! isset( $post->post_type, $post->post_name ) ) {
			return $link;
		}

		if ( ! in_array( $post->post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			return $link;
		}

		$slug = self::get_current_slug( $post->ID );

		if ( '' === $slug || $slug === $post->post_name ) {
			return $link;
		}

		$trailing = '/' === substr( $link, -1 );
		$parts    = explode( '/', untrailingslashit( $link ) );
		$last     = array_pop( $parts );

		// Plain permalinks end in ?p=123 rather than the name, and a structure
		// nobody expected should be left exactly as it is.
		if ( $last !== $post->post_name ) {
			return $link;
		}

		$parts[] = $slug;
		$link    = implode( '/', $parts );

		return $trailing ? trailingslashit( $link ) : $link;
	}
}
