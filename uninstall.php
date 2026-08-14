<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin options only. Documents, uploaded PDFs and the per
 * language meta are left untouched so nothing is lost by mistake — delete
 * them from the admin if you really want them gone.
 *
 * @package WP_PDF_Reader
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wppdf_settings' );
delete_option( 'wppdf_flush_rewrite' );

if ( is_multisite() ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no API for this and uninstall runs once.
	$wppdf_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

	// Not named $blog_id: at this scope that is WordPress's own global, and
	// the loop would leave it pointing at the last site visited.
	foreach ( (array) $wppdf_blog_ids as $wppdf_site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'wppdf_settings' );
		delete_option( 'wppdf_flush_rewrite' );
		restore_current_blog();
	}
}
