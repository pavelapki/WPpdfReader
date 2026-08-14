<?php
/**
 * Template loading and theme overrides.
 *
 * Any template in this plugin's /templates directory can be overridden by
 * copying it into a "wp-pdf-reader" folder inside the (child) theme.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template loader.
 */
class WPPDF_Templates {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'template_include', array( $this, 'template_include' ), 20 );
		add_filter( 'pre_get_posts', array( $this, 'archive_per_page' ) );
	}

	/**
	 * Directory inside a theme that may hold overrides.
	 *
	 * @return string
	 */
	public static function theme_directory() {
		/**
		 * Filter the theme sub directory used for template overrides.
		 *
		 * @param string $directory Directory name.
		 */
		return apply_filters( 'wppdf_theme_template_directory', 'wp-pdf-reader' );
	}

	/**
	 * Find a template, preferring the theme copy.
	 *
	 * @param string $name Template file name, e.g. "parts/card.php".
	 * @return string Absolute path, empty when missing.
	 */
	public static function locate( $name ) {
		$name = ltrim( $name, '/' );

		$theme = locate_template( array( trailingslashit( self::theme_directory() ) . $name ) );
		if ( $theme ) {
			return $theme;
		}

		$plugin = WPPDF_PATH . 'templates/' . $name;

		return file_exists( $plugin ) ? $plugin : '';
	}

	/**
	 * Render a template part.
	 *
	 * @param string $name Template file name.
	 * @param array  $args Variables made available to the template.
	 * @return string
	 */
	public static function get_part( $name, array $args = array() ) {
		$file = self::locate( $name );

		if ( '' === $file ) {
			return '';
		}

		ob_start();
		self::include_file( $file, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Include a template in an isolated scope.
	 *
	 * @param string $file Absolute path.
	 * @param array  $args Template variables.
	 */
	protected static function include_file( $file, array $args ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- deliberate template scope.
		extract( $args, EXTR_SKIP );

		include $file;
	}

	/**
	 * Which single template the settings ask for.
	 *
	 * The standalone one gives the reader the whole viewport by never calling
	 * get_header() or get_footer(), which is what prints the theme's header,
	 * navigation and footer.
	 *
	 * @return string Template file name.
	 */
	public static function single_template_name() {
		$standalone = 'theme' !== WPPDF_Settings::get( 'single_layout' );

		/**
		 * Filter whether a document opens on a page of its own.
		 *
		 * @param bool $standalone Whether to use the full page template.
		 */
		$standalone = (bool) apply_filters( 'wppdf_standalone_single', $standalone );

		return $standalone ? 'single-document-standalone.php' : 'single-document.php';
	}

	/**
	 * Swap in the plugin's single/archive templates when the theme has none.
	 *
	 * @param string $template Template path chosen by WordPress.
	 * @return string
	 */
	public function template_include( $template ) {
		if ( ! WPPDF_Settings::get( 'override_templates' ) ) {
			return $template;
		}

		$post_type = WPPDF_Post_Type::get_key();

		if ( is_singular( $post_type ) ) {
			$theme_template = locate_template( array( "single-{$post_type}.php" ) );
			if ( ! $theme_template ) {
				$plugin_template = self::locate( self::single_template_name() );
				if ( $plugin_template ) {
					return $plugin_template;
				}
			}
		}

		if ( is_post_type_archive( $post_type ) ) {
			$theme_template = locate_template( array( "archive-{$post_type}.php" ) );
			if ( ! $theme_template ) {
				$plugin_template = self::locate( 'archive-document.php' );
				if ( $plugin_template ) {
					return $plugin_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Apply the configured items per page to the document archive.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public function archive_per_page( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_post_type_archive( WPPDF_Post_Type::get_key() ) ) {
			return;
		}

		$query->set( 'posts_per_page', (int) WPPDF_Settings::get( 'archive_per_page' ) );
	}
}
