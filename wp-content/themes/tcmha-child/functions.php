<?php
/**
 * TCMHA child theme setup.
 *
 * Brand tokens and accessible base styles only. Business logic lives in the tcmha-core plugin.
 *
 * @package TCMHA
 */

defined( 'ABSPATH' ) || exit;

define( 'TCMHA_THEME_VERSION', '0.3.0' );

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style(
			'tcmha-tokens',
			get_stylesheet_directory_uri() . '/assets/css/tokens.css',
			array(),
			TCMHA_THEME_VERSION
		);
		wp_enqueue_style(
			'tcmha-base',
			get_stylesheet_directory_uri() . '/assets/css/base.css',
			array( 'tcmha-tokens' ),
			TCMHA_THEME_VERSION
		);
	},
	20
);

add_action(
	'after_setup_theme',
	static function () {
		load_child_theme_textdomain( 'tcmha', get_stylesheet_directory() . '/languages' );
	}
);

/*
 * Lexend is self-hosted in assets/fonts (see tokens.css). Stop Elementor from also
 * requesting Google Fonts, so no visitor data goes to Google.
 */
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
