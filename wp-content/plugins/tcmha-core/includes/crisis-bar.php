<?php
/**
 * Persistent "Get Help Now" crisis bar on every front-end page.
 *
 * Links hand visitors straight to the crisis provider. Nothing is stored or relayed.
 * Numbers must be confirmed with TCMHA before launch. No aria-label overrides: the visible
 * text is the accessible name (WCAG 2.5.3 Label in Name).
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crisis actions shown in the bar. Filterable so TCMHA can adjust without code changes later.
 *
 * @return array<int, array{label: string, href: string}>
 */
function tcmha_crisis_actions() {
	return apply_filters(
		'tcmha_crisis_actions',
		array(
			array(
				'label' => __( 'Call 911 if you are in danger', 'tcmha' ),
				'href'  => 'tel:911',
			),
			array(
				'label' => __( 'Call or text 988', 'tcmha' ),
				'href'  => 'tel:988',
			),
			array(
				'label' => __( 'Colorado Crisis Services 844-493-8255', 'tcmha' ),
				'href'  => 'tel:+18444938255',
			),
		)
	);
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'tcmha-crisis-bar', TCMHA_CORE_URL . 'assets/css/crisis-bar.css', array(), TCMHA_CORE_VERSION );
	}
);

add_action(
	'wp_body_open',
	static function () {
		$help_url = apply_filters( 'tcmha_get_help_url', home_url( '/get-help-now/' ) );
		?>
		<aside class="tcmha-crisis-bar" aria-label="<?php esc_attr_e( 'Crisis help', 'tcmha' ); ?>">
			<a class="tcmha-crisis-bar__primary" href="<?php echo esc_url( $help_url ); ?>"><?php esc_html_e( 'Get Help Now', 'tcmha' ); ?></a>
			<ul class="tcmha-crisis-bar__actions">
				<?php foreach ( tcmha_crisis_actions() as $action ) : ?>
					<li><a href="<?php echo esc_url( $action['href'], array( 'tel', 'sms', 'https' ) ); ?>"><?php echo esc_html( $action['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</aside>
		<?php
	},
	5
);
