<?php
/**
 * Gutenberg blocks (server rendered, no build step).
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block registration.
 */
class WPPDF_Block {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register the editor script and both blocks.
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'wppdf-blocks',
			WPPDF_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-data' ),
			WPPDF_VERSION,
			true
		);

		wp_localize_script(
			'wppdf-blocks',
			'wppdfBlocks',
			array(
				'postType'  => WPPDF_Post_Type::get_key(),
				'languages' => array_values( WPPDF_Languages::get_languages() ),
				'zoomModes' => WPPDF_Settings::zoom_modes(),
				'defaults'  => array(
					'height'  => (int) WPPDF_Settings::get( 'viewer_height' ),
					'zoom'    => WPPDF_Settings::get( 'viewer_zoom' ),
					'columns' => (int) WPPDF_Settings::get( 'archive_columns' ),
					'perPage' => (int) WPPDF_Settings::get( 'archive_per_page' ),
				),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wppdf-blocks', 'wp-pdf-reader', WPPDF_PATH . 'languages' );
		}

		register_block_type(
			WPPDF_PATH . 'blocks/reader',
			array( 'render_callback' => array( $this, 'render_reader' ) )
		);

		register_block_type(
			WPPDF_PATH . 'blocks/grid',
			array( 'render_callback' => array( $this, 'render_grid' ) )
		);
	}

	/**
	 * Render the reader block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_reader( $attributes ) {
		$attributes = wp_parse_args(
			(array) $attributes,
			array(
				'postId'   => 0,
				'lang'     => '',
				'height'   => (int) WPPDF_Settings::get( 'viewer_height' ),
				'zoom'     => WPPDF_Settings::get( 'viewer_zoom' ),
				'toolbar'  => (bool) WPPDF_Settings::get( 'show_toolbar' ),
				'download' => (bool) WPPDF_Settings::get( 'allow_download' ),
			)
		);

		$post_id = absint( $attributes['postId'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		return WPPDF_Viewer::render(
			$post_id,
			array(
				'lang'     => WPPDF_Settings::sanitize_language_code( $attributes['lang'] ),
				'height'   => (int) $attributes['height'],
				'zoom'     => sanitize_text_field( $attributes['zoom'] ),
				'toolbar'  => (bool) $attributes['toolbar'],
				'download' => (bool) $attributes['download'],
			)
		);
	}

	/**
	 * Render the grid block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_grid( $attributes ) {
		$attributes = wp_parse_args(
			(array) $attributes,
			array(
				'columns'  => (int) WPPDF_Settings::get( 'archive_columns' ),
				'perPage'  => (int) WPPDF_Settings::get( 'archive_per_page' ),
				'layout'   => WPPDF_Settings::get( 'archive_layout' ),
				'category' => '',
				'orderby'  => 'date',
				'order'    => 'DESC',
				'excerpt'  => true,
			)
		);

		$shortcodes = new WPPDF_Shortcodes();

		return $shortcodes->grid(
			array(
				'columns'  => (int) $attributes['columns'],
				'per_page' => (int) $attributes['perPage'],
				'layout'   => sanitize_key( $attributes['layout'] ),
				'category' => sanitize_text_field( $attributes['category'] ),
				'orderby'  => sanitize_key( $attributes['orderby'] ),
				'order'    => sanitize_text_field( $attributes['order'] ),
				'excerpt'  => $attributes['excerpt'] ? 1 : 0,
			),
			'',
			'pdf_grid'
		);
	}
}
