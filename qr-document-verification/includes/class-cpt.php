<?php
/**
 * Custom post type + token generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QDV_CPT {

	/**
	 * Register the (private) custom post type. Managed in admin, never public-queryable.
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'PDF Verifications', 'qr-document-verification' ),
			'singular_name'      => __( 'PDF Verification', 'qr-document-verification' ),
			'add_new'            => __( 'Add New', 'qr-document-verification' ),
			'add_new_item'       => __( 'Add New Entry', 'qr-document-verification' ),
			'edit_item'          => __( 'Edit Entry', 'qr-document-verification' ),
			'new_item'           => __( 'New Entry', 'qr-document-verification' ),
			'view_item'          => __( 'View Entry', 'qr-document-verification' ),
			'search_items'       => __( 'Search Entries', 'qr-document-verification' ),
			'not_found'          => __( 'No entries found', 'qr-document-verification' ),
			'not_found_in_trash' => __( 'No entries in Trash', 'qr-document-verification' ),
			'all_items'          => __( 'All Entries', 'qr-document-verification' ),
			'menu_name'          => __( 'PDF Verify', 'qr-document-verification' ),
		);

		register_post_type( QDV_POST_TYPE, array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-shield-alt',
			'menu_position'       => 26,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title' ), // Post title = person's name.
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
		) );
	}

	/**
	 * A random, unguessable token (24 hex chars).
	 */
	public static function generate_token() {
		try {
			if ( function_exists( 'random_bytes' ) ) {
				return bin2hex( random_bytes( 12 ) );
			}
		} catch ( Exception $e ) {
			// fall through
		}
		return strtolower( wp_generate_password( 24, false, false ) );
	}

	/**
	 * Ensure a post has a token; create and store one if missing. Returns the token.
	 */
	public static function ensure_token( $post_id ) {
		$token = get_post_meta( $post_id, '_qdv_token', true );
		if ( empty( $token ) ) {
			// Guarantee uniqueness.
			do {
				$token = self::generate_token();
			} while ( qdv_find_post_by_token( $token ) );
			update_post_meta( $post_id, '_qdv_token', $token );
		}
		return $token;
	}
}
