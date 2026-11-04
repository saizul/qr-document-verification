<?php
/**
 * Runs when the plugin is deleted from WordPress. Removes entries and stored PDFs.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$post_type = 'qdv_entry';

$ids = get_posts( array(
	'post_type'   => $post_type,
	'post_status' => 'any',
	'numberposts' => -1,
	'fields'      => 'ids',
) );

foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}

// Remove the auto-created viewer page.
$page_id = (int) get_option( 'qdv_page_id' );
if ( $page_id ) {
	wp_delete_post( $page_id, true );
}

// Remove plugin options.
delete_option( 'qdv_options' );
delete_option( 'qdv_slug' );
delete_option( 'qdv_page_id' );
delete_option( 'qdv_rewrite_sig' );

// Remove the protected folder and its PDFs via WP_Filesystem.
$up  = wp_upload_dir();
$dir = trailingslashit( $up['basedir'] ) . 'qdv-pdf';
if ( is_dir( $dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $dir, true );
	}
}
