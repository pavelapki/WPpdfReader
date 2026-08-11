<?php
/**
 * Plugin bootstrap.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together.
 */
class WPPDF_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPPDF_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Component instances.
	 *
	 * @var array
	 */
	protected $components = array();

	/**
	 * Get the plugin instance.
	 *
	 * @return WPPDF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 */
	protected function __construct() {
		$this->components = array(
			'settings'   => new WPPDF_Settings(),
			'post_type'  => new WPPDF_Post_Type(),
			'meta'       => new WPPDF_Meta(),
			'admin'      => new WPPDF_Admin(),
			'viewer'     => new WPPDF_Viewer(),
			'shortcodes' => new WPPDF_Shortcodes(),
			'block'      => new WPPDF_Block(),
			'templates'  => new WPPDF_Templates(),
		);

		foreach ( $this->components as $component ) {
			if ( method_exists( $component, 'hooks' ) ) {
				$component->hooks();
			}
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'update_option_' . WPPDF_Settings::OPTION, array( $this, 'flush_caches' ) );
	}

	/**
	 * Get a component.
	 *
	 * @param string $name Component key.
	 * @return object|null
	 */
	public function get( $name ) {
		return isset( $this->components[ $name ] ) ? $this->components[ $name ] : null;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-pdf-reader', false, dirname( WPPDF_BASENAME ) . '/languages' );
	}

	/**
	 * Reset runtime caches after the settings changed.
	 */
	public function flush_caches() {
		WPPDF_Settings::flush_cache();
		WPPDF_Languages::flush_cache();
	}

	/**
	 * Activation: store defaults and refresh permalinks.
	 */
	public static function activate() {
		if ( false === get_option( WPPDF_Settings::OPTION, false ) ) {
			add_option( WPPDF_Settings::OPTION, WPPDF_Settings::defaults() );
		}

		$post_type = new WPPDF_Post_Type();
		$post_type->register();

		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation: drop the generated rewrite rules.
	 */
	public static function deactivate() {
		flush_rewrite_rules( false );
		delete_option( 'wppdf_flush_rewrite' );
	}
}
