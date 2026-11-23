<?php
/**
 * Plugin Name:       TCMHA Core
 * Description:       Content model, shared taxonomy, and persistent crisis actions for the Teller County Mental Health Alliance site.
 * Version:           0.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Storiline LLC
 * Text Domain:       tcmha
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'TCMHA_CORE_VERSION', '0.1.1' );
define( 'TCMHA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCMHA_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once TCMHA_CORE_DIR . 'includes/content-types.php';
require_once TCMHA_CORE_DIR . 'includes/taxonomies.php';
require_once TCMHA_CORE_DIR . 'includes/crisis-bar.php';

register_activation_hook(
	__FILE__,
	static function () {
		tcmha_register_content_types();
		tcmha_register_taxonomies();
		flush_rewrite_rules();
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
