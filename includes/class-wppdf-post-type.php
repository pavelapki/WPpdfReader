<?php
/**
 * The document post type and its taxonomies.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post type registration.
 */
class WPPDF_Post_Type {

	/**
	 * Slug of the optional dedicated taxonomy.
	 */
	const OWN_TAXONOMY = 'pdf_category';

	/**
	 * Memoised list of supported post types.
	 *
	 * @var string[]|null
	 */
	protected static $supported = null;

	/**
	 * Memoised list of document taxonomies.
	 *
	 * @var string[]|null
	 */
	protected static $taxonomies = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_action( 'pre_get_posts', array( $this, 'include_in_blog' ) );
	}

	/**
	 * Configured post type key.
	 *
	 * @return string
	 */
	public static function get_key() {
		$key = WPPDF_Settings::get( 'post_type_key', 'pdf_document' );
		$key = sanitize_key( $key );

		return '' !== $key ? $key : 'pdf_document';
	}

	/**
	 * Post types the viewer applies to.
	 *
	 * @return string[]
	 */
	public static function get_supported_post_types() {
		// Called from query filters that run for every WP_Query, so the answer
		// is memoised rather than refiltered dozens of times per request.
		if ( null !== self::$supported ) {
			return self::$supported;
		}

		/**
		 * Filter the post types that can hold PDF files.
		 *
		 * Add 'post' or 'page' here to reuse the per-language fields elsewhere.
		 *
		 * @param string[] $post_types Post type keys.
		 */
		self::$supported = array_values( array_unique( (array) apply_filters( 'wppdf_supported_post_types', array( self::get_key() ) ) ) );

		return self::$supported;
	}

	/**
	 * Clear the memoised post type list.
	 */
	public static function flush_cache() {
		self::$supported  = null;
		self::$taxonomies = null;
	}

	/**
	 * Taxonomies the documents are filed under.
	 *
	 * @return string[]
	 */
	public static function get_document_taxonomies() {
		// Every grid builds one tax_query branch per taxonomy, so this is asked
		// repeatedly on a page that lists documents in more than one place.
		if ( null !== self::$taxonomies ) {
			return self::$taxonomies;
		}

		$taxonomies = array();

		if ( WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			$taxonomies[] = 'category';
			$taxonomies[] = 'post_tag';
		}

		if ( WPPDF_Settings::get( 'own_taxonomy' ) ) {
			$taxonomies[] = self::OWN_TAXONOMY;
		}

		/**
		 * Filter the taxonomies documents are filed under.
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 */
		self::$taxonomies = array_values( array_unique( (array) apply_filters( 'wppdf_document_taxonomies', $taxonomies ) ) );

		return self::$taxonomies;
	}

	/**
	 * Register the post type and taxonomies.
	 */
	public function register() {
		$key      = self::get_key();
		$slug     = WPPDF_Settings::get( 'post_type_slug' );
		$slug     = $slug ? sanitize_title( $slug ) : $key;
		$singular = WPPDF_Settings::get( 'label_singular' );
		$plural   = WPPDF_Settings::get( 'label_plural' );

		// page-attributes provides the order field the menu_order sorting needs.
		$supports = array( 'title', 'editor', 'author', 'comments', 'custom-fields', 'revisions', 'page-attributes' );
		if ( WPPDF_Settings::get( 'supports_excerpt' ) ) {
			$supports[] = 'excerpt';
		}
		if ( WPPDF_Settings::get( 'supports_thumbnail' ) ) {
			$supports[] = 'thumbnail';
		}

		$taxonomies = array();
		if ( WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			$taxonomies[] = 'category';
			$taxonomies[] = 'post_tag';
		}
		if ( WPPDF_Settings::get( 'own_taxonomy' ) ) {
			$this->register_own_taxonomy( $key, $slug );
			$taxonomies[] = self::OWN_TAXONOMY;
		}

		$args = array(
			'labels'            => self::build_labels( $singular, $plural ),
			'public'            => true,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'show_in_nav_menus' => true,
			'menu_position'     => (int) WPPDF_Settings::get( 'menu_position' ),
			'menu_icon'         => WPPDF_Settings::get( 'menu_icon' ),
			'capability_type'   => 'post',
			'hierarchical'      => false,
			'has_archive'       => WPPDF_Settings::get( 'has_archive' ) ? $slug : false,
			'rewrite'           => array(
				'slug'       => $slug,
				'with_front' => false,
			),
			'supports'          => $supports,
			'taxonomies'        => $taxonomies,
		);

		/**
		 * Filter the document post type arguments.
		 *
		 * @param array  $args Post type arguments.
		 * @param string $key  Post type key.
		 */
		$args = apply_filters( 'wppdf_post_type_args', $args, $key );

		register_post_type( $key, $args );

		// Make sure the shared taxonomies really are attached, even when another
		// plugin registered them after this point.
		if ( WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			register_taxonomy_for_object_type( 'category', $key );
			register_taxonomy_for_object_type( 'post_tag', $key );
		}
	}

	/**
	 * Register the optional dedicated taxonomy.
	 *
	 * @param string $key  Post type key.
	 * @param string $slug Post type slug.
	 */
	protected function register_own_taxonomy( $key, $slug ) {
		register_taxonomy(
			self::OWN_TAXONOMY,
			$key,
			array(
				'labels'            => array(
					'name'          => __( 'Document categories', 'wp-pdf-reader' ),
					'singular_name' => __( 'Document category', 'wp-pdf-reader' ),
					'menu_name'     => __( 'Categories', 'wp-pdf-reader' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => $slug . '-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Build the post type labels from the configured names.
	 *
	 * @param string $singular Singular label.
	 * @param string $plural   Plural label.
	 * @return array
	 */
	public static function build_labels( $singular, $plural ) {
		return array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'all_items'             => $plural,
			/* translators: %s: singular post type label. */
			'add_new_item'          => sprintf( __( 'Add new %s', 'wp-pdf-reader' ), $singular ),
			'add_new'               => __( 'Add new', 'wp-pdf-reader' ),
			/* translators: %s: singular post type label. */
			'edit_item'             => sprintf( __( 'Edit %s', 'wp-pdf-reader' ), $singular ),
			/* translators: %s: singular post type label. */
			'new_item'              => sprintf( __( 'New %s', 'wp-pdf-reader' ), $singular ),
			/* translators: %s: singular post type label. */
			'view_item'             => sprintf( __( 'View %s', 'wp-pdf-reader' ), $singular ),
			/* translators: %s: plural post type label. */
			'view_items'            => sprintf( __( 'View %s', 'wp-pdf-reader' ), $plural ),
			/* translators: %s: plural post type label. */
			'search_items'          => sprintf( __( 'Search %s', 'wp-pdf-reader' ), $plural ),
			/* translators: %s: plural post type label. */
			'not_found'             => sprintf( __( 'No %s found', 'wp-pdf-reader' ), $plural ),
			/* translators: %s: plural post type label. */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash', 'wp-pdf-reader' ), $plural ),
			/* translators: %s: plural post type label. */
			'archives'              => sprintf( __( '%s archive', 'wp-pdf-reader' ), $plural ),
			'featured_image'        => __( 'Cover image', 'wp-pdf-reader' ),
			'set_featured_image'    => __( 'Set cover image', 'wp-pdf-reader' ),
			'remove_featured_image' => __( 'Remove cover image', 'wp-pdf-reader' ),
			'use_featured_image'    => __( 'Use as cover image', 'wp-pdf-reader' ),
		);
	}

	/**
	 * Optionally show documents in the blog loop, feeds and shared archives.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public function include_in_blog( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! WPPDF_Settings::get( 'show_in_blog' ) ) {
			return;
		}

		if ( ! ( $query->is_home() || $query->is_feed() || $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() || $query->is_search() ) ) {
			return;
		}

		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		} elseif ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		if ( in_array( 'any', $post_types, true ) ) {
			return;
		}

		$post_types[] = self::get_key();

		$query->set( 'post_type', array_values( array_unique( $post_types ) ) );
	}

	/**
	 * Flush rewrite rules once after the settings changed.
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'wppdf_flush_rewrite' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'wppdf_flush_rewrite' );
		}
	}

	/**
	 * Move existing documents to a new post type key.
	 *
	 * Renaming the post type in the settings would otherwise orphan every
	 * document, so the rows are rewritten in place.
	 *
	 * @param string $old_key Previous post type key.
	 * @param string $new_key New post type key.
	 * @return int Number of updated posts.
	 */
	public static function migrate_post_type( $old_key, $new_key ) {
		global $wpdb;

		$old_key = sanitize_key( $old_key );
		$new_key = sanitize_key( $new_key );

		if ( '' === $old_key || '' === $new_key || $old_key === $new_key ) {
			return 0;
		}

		if ( in_array( $old_key, WPPDF_Settings::reserved_post_types(), true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- renaming the post type in one statement; wp_update_post() per row would be thousands of queries. The caches are cleaned right below.
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_type' => $new_key ),
			array( 'post_type' => $old_key ),
			array( '%s' ),
			array( '%s' )
		);

		if ( $updated ) {
			self::clean_cache_for_post_type( $new_key );
		}

		return (int) $updated;
	}

	/**
	 * Flush caches for every post of a type after a bulk update.
	 *
	 * @param string $post_type Post type key.
	 */
	protected static function clean_cache_for_post_type( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- this is the cache flush itself, so reading through the cache would defeat it.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );

		foreach ( (array) $ids as $id ) {
			clean_post_cache( (int) $id );
		}
	}
}
