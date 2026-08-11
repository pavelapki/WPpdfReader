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

	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

	foreach ( (array) $blog_ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		delete_option( 'wppdf_settings' );
		delete_option( 'wppdf_flush_rewrite' );
		restore_current_blog();
	}
}
