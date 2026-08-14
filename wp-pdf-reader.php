<?php
/**
 * Plugin Name:       WP PDF Reader
 * Plugin URI:        https://github.com/pavelapki/WPpdfReader
 * Description:       Post-like library of PDF documents with a bundled PDF.js reader, full text search inside PDFs, shared categories and per-language files with a configurable fallback chain (cs → en by default).
 * Version:           1.7.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Pavel Apki
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-pdf-reader
 * Domain Path:       /languages
 * GitHub Plugin URI: pavelapki/WPpdfReader
 * Primary Branch:    main
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

define( 'WPPDF_VERSION', '1.7.0' );
define( 'WPPDF_FILE', __FILE__ );
define( 'WPPDF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPPDF_URL', plugin_dir_url( __FILE__ ) );
define( 'WPPDF_BASENAME', plugin_basename( __FILE__ ) );

require_once WPPDF_PATH . 'includes/class-wppdf-settings.php';
require_once WPPDF_PATH . 'includes/class-wppdf-languages.php';
require_once WPPDF_PATH . 'includes/class-wppdf-post-type.php';
require_once WPPDF_PATH . 'includes/class-wppdf-documents.php';
require_once WPPDF_PATH . 'includes/class-wppdf-cover.php';
require_once WPPDF_PATH . 'includes/class-wppdf-text.php';
require_once WPPDF_PATH . 'includes/class-wppdf-search.php';
require_once WPPDF_PATH . 'includes/class-wppdf-filters.php';
require_once WPPDF_PATH . 'includes/class-wppdf-acf.php';
require_once WPPDF_PATH . 'includes/class-wppdf-stats.php';
require_once WPPDF_PATH . 'includes/class-wppdf-seo.php';
require_once WPPDF_PATH . 'includes/class-wppdf-protection.php';
require_once WPPDF_PATH . 'includes/class-wppdf-meta.php';
require_once WPPDF_PATH . 'includes/class-wppdf-importer.php';
require_once WPPDF_PATH . 'includes/class-wppdf-migrator.php';
require_once WPPDF_PATH . 'includes/class-wppdf-redirects.php';
require_once WPPDF_PATH . 'includes/class-wppdf-reindex.php';
require_once WPPDF_PATH . 'includes/class-wppdf-updater.php';
require_once WPPDF_PATH . 'includes/class-wppdf-admin.php';
require_once WPPDF_PATH . 'includes/class-wppdf-viewer.php';
require_once WPPDF_PATH . 'includes/class-wppdf-shortcodes.php';
require_once WPPDF_PATH . 'includes/class-wppdf-block.php';
require_once WPPDF_PATH . 'includes/class-wppdf-templates.php';
require_once WPPDF_PATH . 'includes/functions.php';
require_once WPPDF_PATH . 'includes/class-wppdf-plugin.php';

/**
 * Main plugin instance.
 *
 * @return WPPDF_Plugin
 */
function wppdf() {
	return WPPDF_Plugin::instance();
}

wppdf();

register_activation_hook( __FILE__, array( 'WPPDF_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPPDF_Plugin', 'deactivate' ) );
