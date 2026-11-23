<?php
/**
 * TCMHA child theme setup.
 *
 * Brand tokens and accessible base styles only. Business logic lives in the tcmha-core plugin.
 *
 * @package TCMHA
 */

defined( 'ABSPATH' ) || exit;

define( 'TCMHA_THEME_VERSION', '0.2.0' );

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
