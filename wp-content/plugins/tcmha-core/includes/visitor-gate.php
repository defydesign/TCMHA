<?php
/**
 * Pre-launch visitor gate.
 *
 * While the site is being built:
 * - Logged-in editors and administrators see everything.
 * - Anyone with the link and password can open a password-protected page (WordPress's own
 *   post password), such as the stakeholder progress page.
 * - Everyone else is sent to the "Coming soon" page.
 *
 * The gate is on unless the site runs on its public domain (tcmha.org), so it lifts by itself when
 * the domain moves over at launch. (WP Engine Dev did not report a non-production environment type, so the
 * WordPress environment type can't be used.) Override with the TCMHA_VISITOR_GATE constant
 * (true or false) in wp-config.php, or the tcmha_visitor_gate_enabled filter.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the gate is active.
 *
 * @return bool
 */
function tcmha_visitor_gate_enabled() {
	if ( defined( 'TCMHA_VISITOR_GATE' ) ) {
		$enabled = (bool) TCMHA_VISITOR_GATE;
	} else {
		$public_hosts = apply_filters( 'tcmha_public_hosts', array( 'tcmha.org', 'www.tcmha.org' ) );
		$host         = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$enabled      = ! in_array( $host, $public_hosts, true );
	}

	return (bool) apply_filters( 'tcmha_visitor_gate_enabled', $enabled );
}

/**
 * The public "Coming soon" page, found by slug.
 *
 * @return WP_Post|null
 */
function tcmha_coming_soon_page() {
	$slug = apply_filters( 'tcmha_coming_soon_slug', 'coming-soon' );
	$page = get_page_by_path( $slug );

	return ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? $page : null;
}

/**
 * Whether the current visitor may see the current request.
 *
 * @return bool
 */
function tcmha_visitor_gate_allows_request() {
	if ( current_user_can( 'edit_others_posts' ) ) {
		return true; // Administrators and editors.
	}

	// Signed Elementor preview links (created by an editor) validate their own token.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check; Elementor verifies the token.
	if ( isset( $_GET['elementor_preview_token'] ) ) {
		return true;
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			// Password-protected pages are shareable: WordPress shows its password form.
			if ( '' !== $post->post_password ) {
				return true;
			}
			$coming_soon = tcmha_coming_soon_page();
			if ( $coming_soon && (int) $coming_soon->ID === (int) $post->ID ) {
				return true;
			}
		}
	}

	return false;
}

add_action(
	'template_redirect',
	static function () {
		if ( ! tcmha_visitor_gate_enabled() || tcmha_visitor_gate_allows_request() ) {
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$coming_soon = tcmha_coming_soon_page();
		if ( $coming_soon ) {
			wp_safe_redirect( get_permalink( $coming_soon ), 302, 'TCMHA' );
			exit;
		}

		wp_die(
			esc_html__( 'The new TCMHA website is coming soon. If you need help now, call or text 988.', 'tcmha' ),
			esc_html__( 'Coming soon', 'tcmha' ),
			array( 'response' => 503 )
		);
	},
	1
);
