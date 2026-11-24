<?php
/**
 * Plugin Name:       TCMHA Core
 * Description:       Content model, shared taxonomy, persistent crisis actions, meeting polls, general polls with pictures, and a branded admin for the Teller County Mental Health Alliance site.
 * Version:           0.6.2
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Storiline LLC
 * Text Domain:       tcmha
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'TCMHA_CORE_VERSION', '0.6.2' );
define( 'TCMHA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCMHA_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once TCMHA_CORE_DIR . 'includes/content-types.php';
require_once TCMHA_CORE_DIR . 'includes/taxonomies.php';
require_once TCMHA_CORE_DIR . 'includes/crisis-bar.php';
require_once TCMHA_CORE_DIR . 'includes/visitor-gate.php';
require_once TCMHA_CORE_DIR . 'includes/meeting-poll.php';
require_once TCMHA_CORE_DIR . 'includes/meeting-poll-admin.php';
require_once TCMHA_CORE_DIR . 'includes/meeting-poll-close.php';
require_once TCMHA_CORE_DIR . 'includes/survey.php';
require_once TCMHA_CORE_DIR . 'includes/survey-admin.php';
require_once TCMHA_CORE_DIR . 'includes/admin-brand.php';

register_activation_hook(
	__FILE__,
	static function () {
		tcmha_register_content_types();
		tcmha_register_taxonomies();
		tcmha_poll_register();
		tcmha_survey_register();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'tcmha_poll_purge' );
		wp_clear_scheduled_hook( 'tcmha_poll_deadlines' );
		wp_clear_scheduled_hook( 'tcmha_survey_hourly' );
		flush_rewrite_rules();
	}
);
