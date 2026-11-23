<?php
/**
 * Shared taxonomy from the RFP: one tag set used by providers, resources, events, and articles
 * so related content appears across pages without duplication. Terms are set in discovery.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register shared taxonomies.
 */
function tcmha_register_taxonomies() {
	$objects = apply_filters( 'tcmha_taxonomy_objects', array( 'tcmha_provider', 'tcmha_resource', 'post' ) );

	$taxonomies = array(
		'tcmha_topic'        => array( __( 'Topics', 'tcmha' ), __( 'Topic', 'tcmha' ), 'topic', true ),
		'tcmha_audience'     => array( __( 'Audiences', 'tcmha' ), __( 'Audience', 'tcmha' ), 'audience', true ),
		'tcmha_service_type' => array( __( 'Service Types', 'tcmha' ), __( 'Service Type', 'tcmha' ), 'service-type', true ),
		'tcmha_area'         => array( __( 'Locations', 'tcmha' ), __( 'Location', 'tcmha' ), 'location', true ),
		'tcmha_access'       => array( __( 'Access Types', 'tcmha' ), __( 'Access Type', 'tcmha' ), 'access', false ),
		'tcmha_cost'         => array( __( 'Cost and Insurance', 'tcmha' ), __( 'Cost or Insurance', 'tcmha' ), 'cost', false ),
		'tcmha_language'     => array( __( 'Languages', 'tcmha' ), __( 'Language', 'tcmha' ), 'language', false ),
		'tcmha_urgency'      => array( __( 'Urgency', 'tcmha' ), __( 'Urgency', 'tcmha' ), 'urgency', false ),
		'tcmha_format'       => array( __( 'Formats', 'tcmha' ), __( 'Format', 'tcmha' ), 'format', false ),
	);

	foreach ( $taxonomies as $taxonomy => list( $plural, $singular, $slug, $hierarchical ) ) {
		register_taxonomy(
			$taxonomy,
			$objects,
			array(
				'labels'            => array(
					'name'          => $plural,
					'singular_name' => $singular,
				),
				'hierarchical'      => $hierarchical,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => $slug ),
			)
		);
	}
}
add_action( 'init', 'tcmha_register_taxonomies' );
