<?php
/**
 * Content types. DRAFT: final list and fields are confirmed in discovery (Dec 2026).
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register custom post types.
 */
function tcmha_register_content_types() {
	$types = array(
		'tcmha_provider' => array(
			'singular' => __( 'Provider', 'tcmha' ),
			'plural'   => __( 'Providers', 'tcmha' ),
			'slug'     => 'providers',
			'icon'     => 'dashicons-groups',
			'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
		),
		'tcmha_resource' => array(
			'singular' => __( 'Resource', 'tcmha' ),
			'plural'   => __( 'Resources', 'tcmha' ),
			'slug'     => 'resources',
			'icon'     => 'dashicons-book-alt',
			'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields' ),
		),
		'tcmha_glossary' => array(
			'singular' => __( 'Glossary Term', 'tcmha' ),
			'plural'   => __( 'Glossary', 'tcmha' ),
			'slug'     => 'glossary',
			'icon'     => 'dashicons-editor-help',
			'supports' => array( 'title', 'editor', 'revisions' ),
		),
	);

	foreach ( $types as $post_type => $t ) {
		register_post_type(
			$post_type,
			array(
				'labels'       => array(
					'name'          => $t['plural'],
					'singular_name' => $t['singular'],
					/* translators: %s: content type name. */
					'add_new_item'  => sprintf( __( 'Add %s', 'tcmha' ), $t['singular'] ),
					/* translators: %s: content type name. */
					'edit_item'     => sprintf( __( 'Edit %s', 'tcmha' ), $t['singular'] ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => $t['icon'],
				'supports'     => $t['supports'],
				'rewrite'      => array( 'slug' => $t['slug'] ),
			)
		);
	}

	// Every listing shows when it was last checked (RFP: last-verified dates).
	foreach ( array( 'tcmha_provider', 'tcmha_resource' ) as $post_type ) {
		register_post_meta(
			$post_type,
			'tcmha_last_verified',
			array(
				'type'              => 'string',
				'description'       => 'Date the listing was last verified (YYYY-MM-DD).',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'tcmha_sanitize_date',
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			)
		);
	}
}
add_action( 'init', 'tcmha_register_content_types' );

/**
 * Keep only valid YYYY-MM-DD dates.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function tcmha_sanitize_date( $value ) {
	$value = sanitize_text_field( (string) $value );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
}
