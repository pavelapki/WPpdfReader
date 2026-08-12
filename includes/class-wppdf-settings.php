<?php
/**
 * Plugin settings storage and settings screen.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings handler.
 */
class WPPDF_Settings {

	/**
	 * Option name in wp_options.
	 */
	const OPTION = 'wppdf_settings';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Post type.
			'post_type_key'       => 'pdf_document',
			'post_type_slug'      => 'pdf',
			'label_singular'      => 'PDF',
			'label_plural'        => 'PDF documents',
			'menu_icon'           => 'dashicons-media-document',
			'menu_position'       => 20,
			'has_archive'         => 1,
			'shared_taxonomies'   => 1,
			'own_taxonomy'        => 0,
			'show_in_blog'        => 0,
			'supports_excerpt'    => 1,
			'supports_thumbnail'  => 1,

			// Languages.
			'languages'           => array(
				array(
					'code'  => 'cs',
					'label' => 'Čeština',
				),
				array(
					'code'  => 'en',
					'label' => 'English',
				),
			),
			'default_language'    => 'cs',
			'fallback_chain'      => array( 'cs', 'en' ),
			'fallback_any'        => 1,
			'sync_with_wpml'      => 1,
			'show_fallback_notice' => 1,

			// Viewer.
			'viewer_height'       => 800,
			'viewer_zoom'         => 'auto',
			'show_toolbar'        => 1,
			'allow_download'      => 1,
			'allow_print'         => 1,
			'lazy_load'           => 1,
			'append_to_content'   => 1,

			// Archive / grid.
			'archive_layout'      => 'grid',
			'archive_columns'     => 3,
			'archive_per_page'    => 12,
			'override_templates'  => 1,

			// Covers.
			'generate_covers'     => 1,

			// Text extraction and search.
			'extract_text'        => 1,
			'search_pdf_text'     => 1,
			'ocr_enabled'         => 1,
			'ocr_max_pages'       => 20,
			'ocr_languages'       => 'ces+eng',

			// Statistics, SEO and updates.
			'count_views'         => 1,
			'seo_metadata'        => 1,
			'language_switcher'   => 1,
			'github_updates'      => 1,
			'github_repository'   => 'pavelapki/WPpdfReader',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = wp_parse_args( $stored, self::defaults() );

		/**
		 * Filter the resolved plugin settings.
		 *
		 * @param array $settings Settings array.
		 */
		self::$cache = apply_filters( 'wppdf_settings', self::$cache );

		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}

		return $all[ $key ];
	}

	/**
	 * Persist settings and clear the runtime cache.
	 *
	 * @param array $settings Settings to store.
	 */
	public static function update( array $settings ) {
		self::$cache = null;
		update_option( self::OPTION, $settings );
	}

	/**
	 * Clear the runtime cache.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			'wppdf_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the whole settings array coming from the settings screen.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();
		$old      = self::all();
		$out      = $defaults;

		if ( ! is_array( $input ) ) {
			return $old;
		}

		// --- Post type -------------------------------------------------.
		$key = isset( $input['post_type_key'] ) ? sanitize_key( $input['post_type_key'] ) : '';
		$key = substr( $key, 0, 20 );
		if ( '' === $key || in_array( $key, self::reserved_post_types(), true ) ) {
			$key = $old['post_type_key'];
			add_settings_error(
				self::OPTION,
				'wppdf_post_type_key',
				__( 'The post type key is empty or reserved by WordPress. The previous value was kept.', 'wp-pdf-reader' )
			);
		}
		$out['post_type_key'] = $key;

		$slug                  = isset( $input['post_type_slug'] ) ? sanitize_title( $input['post_type_slug'] ) : '';
		$out['post_type_slug'] = '' !== $slug ? $slug : $key;

		$out['label_singular'] = isset( $input['label_singular'] ) ? sanitize_text_field( $input['label_singular'] ) : $defaults['label_singular'];
		$out['label_plural']   = isset( $input['label_plural'] ) ? sanitize_text_field( $input['label_plural'] ) : $defaults['label_plural'];
		if ( '' === $out['label_singular'] ) {
			$out['label_singular'] = $defaults['label_singular'];
		}
		if ( '' === $out['label_plural'] ) {
			$out['label_plural'] = $defaults['label_plural'];
		}

		$out['menu_icon']     = isset( $input['menu_icon'] ) ? sanitize_text_field( $input['menu_icon'] ) : $defaults['menu_icon'];
		$out['menu_position'] = isset( $input['menu_position'] ) ? max( 1, absint( $input['menu_position'] ) ) : $defaults['menu_position'];

		foreach ( array( 'has_archive', 'shared_taxonomies', 'own_taxonomy', 'show_in_blog', 'supports_excerpt', 'supports_thumbnail' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// --- Languages -------------------------------------------------.
		$languages = array();
		if ( isset( $input['languages'] ) && is_array( $input['languages'] ) ) {
			foreach ( $input['languages'] as $language ) {
				if ( ! is_array( $language ) ) {
					continue;
				}

				$code = isset( $language['code'] ) ? self::sanitize_language_code( $language['code'] ) : '';
				if ( '' === $code ) {
					continue;
				}

				$label = isset( $language['label'] ) ? sanitize_text_field( $language['label'] ) : '';

				$languages[ $code ] = array(
					'code'  => $code,
					'label' => '' !== $label ? $label : strtoupper( $code ),
				);
			}
		}

		if ( empty( $languages ) ) {
			$languages = array();
			foreach ( $defaults['languages'] as $language ) {
				$languages[ $language['code'] ] = $language;
			}
			add_settings_error(
				self::OPTION,
				'wppdf_languages',
				__( 'At least one language is required. The default languages were restored.', 'wp-pdf-reader' )
			);
		}

		$out['languages'] = array_values( $languages );
		$codes            = array_keys( $languages );

		$default_language         = isset( $input['default_language'] ) ? self::sanitize_language_code( $input['default_language'] ) : '';
		$out['default_language']  = in_array( $default_language, $codes, true ) ? $default_language : $codes[0];

		// Fallback chain: ordered, deduplicated, only known codes.
		$chain = array();
		if ( isset( $input['fallback_chain'] ) ) {
			$raw = $input['fallback_chain'];
			if ( is_string( $raw ) ) {
				$raw = preg_split( '/[\s,]+/', $raw );
			}
			if ( is_array( $raw ) ) {
				foreach ( $raw as $code ) {
					$code = self::sanitize_language_code( $code );
					if ( '' !== $code && in_array( $code, $codes, true ) && ! in_array( $code, $chain, true ) ) {
						$chain[] = $code;
					}
				}
			}
		}
		if ( empty( $chain ) ) {
			$chain = array( $out['default_language'] );
		}
		$out['fallback_chain'] = $chain;

		foreach ( array( 'fallback_any', 'sync_with_wpml', 'show_fallback_notice' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// --- Viewer ----------------------------------------------------.
		$out['viewer_height'] = isset( $input['viewer_height'] ) ? min( 4000, max( 200, absint( $input['viewer_height'] ) ) ) : $defaults['viewer_height'];

		$zoom               = isset( $input['viewer_zoom'] ) ? sanitize_text_field( $input['viewer_zoom'] ) : $defaults['viewer_zoom'];
		$out['viewer_zoom'] = in_array( $zoom, self::zoom_modes(), true ) ? $zoom : $defaults['viewer_zoom'];

		foreach ( array( 'show_toolbar', 'allow_download', 'allow_print', 'lazy_load', 'append_to_content' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// --- Archive ---------------------------------------------------.
		$layout               = isset( $input['archive_layout'] ) ? sanitize_key( $input['archive_layout'] ) : 'grid';
		$out['archive_layout'] = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

		$out['archive_columns']  = isset( $input['archive_columns'] ) ? min( 6, max( 1, absint( $input['archive_columns'] ) ) ) : $defaults['archive_columns'];
		$out['archive_per_page'] = isset( $input['archive_per_page'] ) ? min( 100, max( 1, absint( $input['archive_per_page'] ) ) ) : $defaults['archive_per_page'];

		foreach ( array( 'override_templates', 'generate_covers', 'extract_text', 'search_pdf_text', 'ocr_enabled', 'count_views', 'seo_metadata', 'language_switcher', 'github_updates' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['ocr_max_pages'] = isset( $input['ocr_max_pages'] ) ? min( 500, max( 1, absint( $input['ocr_max_pages'] ) ) ) : $defaults['ocr_max_pages'];

		$ocr_languages = isset( $input['ocr_languages'] ) ? preg_replace( '/[^a-zA-Z+_]/', '', (string) $input['ocr_languages'] ) : '';
		$out['ocr_languages'] = '' !== $ocr_languages ? $ocr_languages : $defaults['ocr_languages'];

		// --- Updates ---------------------------------------------------.
		$repository = isset( $input['github_repository'] ) ? sanitize_text_field( $input['github_repository'] ) : '';
		$repository = trim( $repository );

		if ( '' !== $repository && ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ) {
			$repository = $old['github_repository'];
			add_settings_error(
				self::OPTION,
				'wppdf_repository',
				__( 'The repository must be given as owner/name. The previous value was kept.', 'wp-pdf-reader' )
			);
		}

		$out['github_repository'] = $repository;

		// --- Side effects ----------------------------------------------.
		if ( $old['post_type_key'] !== $out['post_type_key'] ) {
			$moved = WPPDF_Post_Type::migrate_post_type( $old['post_type_key'], $out['post_type_key'] );
			if ( $moved > 0 ) {
				add_settings_error(
					self::OPTION,
					'wppdf_post_type_migrated',
					sprintf(
						/* translators: 1: number of posts, 2: old post type key, 3: new post type key. */
						__( '%1$d documents were moved from "%2$s" to "%3$s".', 'wp-pdf-reader' ),
						$moved,
						$old['post_type_key'],
						$out['post_type_key']
					),
					'success'
				);
			}
		}

		self::$cache = null;
		update_option( 'wppdf_flush_rewrite', 1 );

		return $out;
	}

	/**
	 * Post type keys that must not be taken over.
	 *
	 * @return array
	 */
	public static function reserved_post_types() {
		return array(
			'post',
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'action',
			'author',
			'order',
			'theme',
		);
	}

	/**
	 * Allowed zoom modes.
	 *
	 * @return array
	 */
	public static function zoom_modes() {
		return array( 'auto', 'page-width', 'page-fit', '100', '125', '150' );
	}

	/**
	 * Sanitize a language code such as "cs", "en-gb" or "pt_BR".
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	public static function sanitize_language_code( $code ) {
		if ( ! is_string( $code ) ) {
			return '';
		}

		$code = strtolower( trim( $code ) );
		$code = str_replace( '_', '-', $code );
		$code = preg_replace( '/[^a-z0-9-]/', '', $code );

		return (string) substr( (string) $code, 0, 10 );
	}
}
