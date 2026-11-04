<?php
/**
 * Plugin Name: QR Document Verification
 * Description: Upload PDFs with a name and ID number. Each entry gets a secret, unguessable link and a QR code; scanning the QR opens a fully customizable "Verified" page that shows the PDF. Everything — the verification URL slug, the header, the badge, and the field labels — is editable from the settings.
 * Version:     1.0.0
 * Author:      Saizul Amin
 * Author URI:  https://www.saizul.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qr-document-verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QDV_VERSION', '1.0.0' );
define( 'QDV_FILE', __FILE__ );
define( 'QDV_DIR', plugin_dir_path( __FILE__ ) );
define( 'QDV_URL', plugin_dir_url( __FILE__ ) );
define( 'QDV_POST_TYPE', 'qdv_entry' );   // <= 20 chars
define( 'QDV_DEFAULT_SLUG', 'verify' );   // default; editable in Settings -> /{slug}/{token}

require_once QDV_DIR . 'includes/helpers.php';
require_once QDV_DIR . 'includes/class-cpt.php';
require_once QDV_DIR . 'includes/class-frontend.php';
require_once QDV_DIR . 'includes/class-admin.php';
require_once QDV_DIR . 'includes/class-settings.php';

/**
 * Default customizable options (everything the user can edit).
 */
function qdv_defaults() {
	return array(
		'header_title'  => 'Document Verification',
		'header_bg'     => '#242a7d',
		'header_bg2'    => '#1a1f66',
		'header_color'  => '#ffffff',
		'show_badge'    => 1,
		'badge_text'    => 'Verified',
		'badge_bg'      => '#e9f9ef',
		'badge_color'   => '#137a3e',
		'show_name'     => 1,
		'label_name'    => 'Name',
		'show_id'       => 1,
		'label_id'      => 'ID Number',
		'show_download' => 1,
		'download_text' => 'Open / Download PDF',
		'notfound_text' => 'Verification not found',
	);
}

/**
 * Merged settings.
 */
function qdv_get_options() {
	$saved = get_option( 'qdv_options', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, qdv_defaults() );
}

/**
 * Boot the plugin.
 */
add_action( 'init', 'qdv_ensure_page', 5 );
add_action( 'init', array( 'QDV_CPT', 'register' ) );
add_action( 'init', array( 'QDV_Frontend', 'add_rewrite' ) );
add_action( 'init', 'qdv_maybe_flush', 99 );

QDV_Frontend::init();
QDV_Admin::init();
QDV_Settings::init();

/**
 * Make sure the real WordPress page that hosts the [qdv_viewer] shortcode
 * exists. This is what lets the theme + Elementor header/footer render
 * natively on the verification URLs. Returns the page ID.
 */
function qdv_ensure_page() {
	$id = (int) get_option( 'qdv_page_id' );
	if ( $id ) {
		$p = get_post( $id );
		if ( $p && 'page' === $p->post_type && 'trash' !== $p->post_status ) {
			return $id;
		}
	}

	$id = wp_insert_post( array(
		'post_title'   => 'Document Verification',
		'post_name'    => 'qdv-viewer',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[qdv_viewer]',
		'comment_status' => 'closed',
		'ping_status'  => 'closed',
	) );

	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'qdv_page_id', (int) $id );
		return (int) $id;
	}
	return 0;
}

/**
 * Auto-flush rewrite rules once whenever the plugin version, the URL slug,
 * or the viewer page changes — so /{slug}/{token}/ works without manually
 * re-saving permalinks.
 */
function qdv_maybe_flush() {
	$signature = QDV_VERSION . '|' . qdv_get_slug() . '|' . (int) get_option( 'qdv_page_id' );
	if ( get_option( 'qdv_rewrite_sig' ) !== $signature ) {
		flush_rewrite_rules( false );
		update_option( 'qdv_rewrite_sig', $signature );
	}
}

/**
 * Activation: register rules, create the protected upload folder, flush rewrites.
 */
register_activation_hook( __FILE__, 'qdv_activate' );
function qdv_activate() {
	add_option( 'qdv_slug', QDV_DEFAULT_SLUG );

	qdv_ensure_page();
	QDV_CPT::register();
	QDV_Frontend::add_rewrite();

	$dir = qdv_protected_dir();
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}

	if ( $wp_filesystem ) {
		// Block direct web access to the stored PDFs (Apache + Litespeed).
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess ) ) {
			$rules  = "# Deny direct access. PDFs are served through the plugin only.\n";
			$rules .= "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			$wp_filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
		}
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! $wp_filesystem->exists( $index ) ) {
			$wp_filesystem->put_contents( $index, '', FS_CHMOD_FILE );
		}
	}

	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'qdv_deactivate' );
function qdv_deactivate() {
	flush_rewrite_rules();
}
