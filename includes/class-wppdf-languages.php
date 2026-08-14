<?php
/**
 * Language list, current language detection and the fallback chain.
 *
 * The plugin stores one PDF per language on a single document, so it works
 * with or without WPML/Polylang. When a multilingual plugin is active it is
 * only used as the source of truth for "which language is the visitor on".
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language helper.
 */
class WPPDF_Languages {

	/**
	 * Meta key prefix for the per-language file.
	 */
	const META_FILE = '_wppdf_file_';

	/**
	 * Meta key prefix for the per-language generated cover.
	 */
	const META_COVER = '_wppdf_cover_';

	/**
	 * Runtime cache for the language list.
	 *
	 * @var array|null
	 */
	protected static $languages = null;

	/**
	 * Clear the runtime cache.
	 */
	public static function flush_cache() {
		self::$languages = null;
	}

	/**
	 * The languages a document can hold a file for.
	 *
	 * Configured languages first, then any extra language reported by
	 * WPML/Polylang when syncing is enabled.
	 *
	 * @return array Map of code => array( 'code' => string, 'label' => string ).
	 */
	public static function get_languages() {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$languages = array();

		foreach ( (array) WPPDF_Settings::get( 'languages', array() ) as $language ) {
			if ( empty( $language['code'] ) ) {
				continue;
			}

			$code = WPPDF_Settings::sanitize_language_code( $language['code'] );
			if ( '' === $code ) {
				continue;
			}

			$languages[ $code ] = array(
				'code'  => $code,
				'label' => isset( $language['label'] ) && '' !== $language['label'] ? $language['label'] : strtoupper( $code ),
			);
		}

		if ( WPPDF_Settings::get( 'sync_with_wpml' ) ) {
			foreach ( self::multilingual_languages() as $code => $label ) {
				if ( ! isset( $languages[ $code ] ) ) {
					$languages[ $code ] = array(
						'code'  => $code,
						'label' => $label,
					);
				}
			}
		}

		if ( empty( $languages ) ) {
			$languages['cs'] = array(
				'code'  => 'cs',
				'label' => 'Čeština',
			);
		}

		/**
		 * Filter the languages a document can hold files for.
		 *
		 * @param array $languages Map of code => language data.
		 */
		self::$languages = apply_filters( 'wppdf_languages', $languages );

		return self::$languages;
	}

	/**
	 * Language codes only.
	 *
	 * @return string[]
	 */
	public static function get_codes() {
		return array_keys( self::get_languages() );
	}

	/**
	 * Human readable label for a code.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function get_label( $code ) {
		$languages = self::get_languages();

		return isset( $languages[ $code ] ) ? $languages[ $code ]['label'] : strtoupper( $code );
	}

	/**
	 * Languages reported by WPML or Polylang.
	 *
	 * @return array Map of code => label.
	 */
	protected static function multilingual_languages() {
		$found = array();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_active_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed rather than introduced.
			$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $active ) ) {
				foreach ( $active as $code => $language ) {
					$code = WPPDF_Settings::sanitize_language_code( $code );
					if ( '' === $code ) {
						continue;
					}
					$label = '';
					if ( is_array( $language ) ) {
						$label = ! empty( $language['native_name'] ) ? $language['native_name'] : ( ! empty( $language['translated_name'] ) ? $language['translated_name'] : '' );
					}
					$found[ $code ] = '' !== $label ? $label : strtoupper( $code );
				}
			}
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			$names = pll_languages_list( array( 'fields' => 'name' ) );
			if ( is_array( $slugs ) ) {
				foreach ( $slugs as $index => $slug ) {
					$code = WPPDF_Settings::sanitize_language_code( $slug );
					if ( '' === $code ) {
						continue;
					}
					$found[ $code ] = isset( $names[ $index ] ) ? $names[ $index ] : strtoupper( $code );
				}
			}
		}

		return $found;
	}

	/**
	 * The language the visitor is currently browsing in.
	 *
	 * @return string
	 */
	public static function get_current_language() {
		$code = '';

		if ( WPPDF_Settings::get( 'sync_with_wpml' ) ) {
			if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
				$code = WPPDF_Settings::sanitize_language_code( ICL_LANGUAGE_CODE );
			}

			if ( '' === $code && has_filter( 'wpml_current_language' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, consumed rather than introduced.
				$code = WPPDF_Settings::sanitize_language_code( apply_filters( 'wpml_current_language', null ) );
			}

			if ( '' === $code && function_exists( 'pll_current_language' ) ) {
				$code = WPPDF_Settings::sanitize_language_code( pll_current_language( 'slug' ) );
			}
		}

		if ( '' === $code ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			$code   = WPPDF_Settings::sanitize_language_code( $locale );
		}

		$code = self::match_code( $code );

		if ( '' === $code ) {
			$code = self::get_default_language();
		}

		/**
		 * Filter the detected front-end language.
		 *
		 * @param string $code Language code.
		 */
		return apply_filters( 'wppdf_current_language', $code );
	}

	/**
	 * Map an arbitrary locale/code onto a configured language code.
	 *
	 * "cs_CZ" matches "cs", "en-GB" matches "en-gb" first and then "en".
	 *
	 * @param string $code Raw code.
	 * @return string Configured code or an empty string.
	 */
	public static function match_code( $code ) {
		$code = WPPDF_Settings::sanitize_language_code( $code );
		if ( '' === $code ) {
			return '';
		}

		$codes = self::get_codes();

		if ( in_array( $code, $codes, true ) ) {
			return $code;
		}

		$base = strtok( $code, '-' );
		if ( $base && in_array( $base, $codes, true ) ) {
			return $base;
		}

		// "en" should also match a configured "en-gb" when that is all there is.
		foreach ( $codes as $candidate ) {
			if ( strtok( $candidate, '-' ) === $base ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The site's default document language.
	 *
	 * @return string
	 */
	public static function get_default_language() {
		$default = WPPDF_Settings::sanitize_language_code( WPPDF_Settings::get( 'default_language', 'cs' ) );
		$codes   = self::get_codes();

		if ( in_array( $default, $codes, true ) ) {
			return $default;
		}

		return isset( $codes[0] ) ? $codes[0] : 'cs';
	}

	/**
	 * The ordered list of languages tried for a requested language.
	 *
	 * @param string $requested Requested language code.
	 * @return string[]
	 */
	public static function get_fallback_order( $requested = '' ) {
		$requested = WPPDF_Settings::sanitize_language_code( $requested );
		if ( '' === $requested ) {
			$requested = self::get_current_language();
		}

		$order = array();

		$matched = self::match_code( $requested );
		if ( '' !== $matched ) {
			$order[] = $matched;
		} elseif ( '' !== $requested ) {
			$order[] = $requested;
		}

		// A regional variant falls back to its base language ("en-gb" → "en").
		$base = strtok( $requested, '-' );
		if ( $base && ! in_array( $base, $order, true ) && in_array( $base, self::get_codes(), true ) ) {
			$order[] = $base;
		}

		foreach ( (array) WPPDF_Settings::get( 'fallback_chain', array() ) as $code ) {
			$code = WPPDF_Settings::sanitize_language_code( $code );
			if ( '' !== $code && ! in_array( $code, $order, true ) ) {
				$order[] = $code;
			}
		}

		$default = self::get_default_language();
		if ( ! in_array( $default, $order, true ) ) {
			$order[] = $default;
		}

		if ( WPPDF_Settings::get( 'fallback_any' ) ) {
			foreach ( self::get_codes() as $code ) {
				if ( ! in_array( $code, $order, true ) ) {
					$order[] = $code;
				}
			}
		}

		/**
		 * Filter the fallback order used to resolve a document file.
		 *
		 * @param string[] $order     Ordered language codes.
		 * @param string   $requested Originally requested code.
		 */
		return apply_filters( 'wppdf_fallback_order', $order, $requested );
	}

	/**
	 * Meta key holding the file for a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function file_meta_key( $code ) {
		return self::META_FILE . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * Meta key holding the generated cover for a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function cover_meta_key( $code ) {
		return self::META_COVER . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}
}
