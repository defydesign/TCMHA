<?php
/**
 * Standalone branded page for a meeting poll (no theme header or footer), styled like the
 * stakeholder progress page: white masthead, purple hero band, light section, purple footer band.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

$tcmha_poll  = tcmha_poll_current();
$tcmha_intro  = get_post_field( 'post_content', get_queried_object_id() );
$tcmha_choose = $tcmha_poll && tcmha_poll_choose_mode( $tcmha_poll );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'tcmha-poll-page' ); ?>>
<?php wp_body_open(); ?>
<a class="tcmha-skip" href="#tcmha-poll-main"><?php esc_html_e( 'Skip to the poll', 'tcmha' ); ?></a>

<header class="tcmha-poll-masthead">
	<div class="tcmha-poll-wrap tcmha-poll-masthead__inner">
		<p class="tcmha-poll-brand">
			<strong>TCMHA</strong>
			<span><?php esc_html_e( 'Teller County Mental Health Alliance', 'tcmha' ); ?></span>
		</p>
		<p class="tcmha-poll-masthead__note"><?php esc_html_e( 'Times are in Mountain Time', 'tcmha' ); ?></p>
	</div>
</header>

<main id="tcmha-poll-main" tabindex="-1">
	<section class="tcmha-poll-hero">
		<div class="tcmha-poll-wrap">
			<p class="tcmha-poll-eyebrow"><?php echo $tcmha_choose ? esc_html__( 'Meeting poll: for the organizer', 'tcmha' ) : esc_html__( 'Meeting poll', 'tcmha' ); ?></p>
			<h1><?php echo esc_html( get_the_title() ); ?></h1>
			<?php if ( ! $tcmha_choose && '' !== trim( (string) $tcmha_intro ) ) : ?>
				<div class="tcmha-poll-lead"><?php echo wp_kses_post( wpautop( $tcmha_intro ) ); ?></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="tcmha-poll-body">
		<div class="tcmha-poll-wrap">
			<?php
			if ( $tcmha_choose ) {
				echo tcmha_poll_render_choose( $tcmha_poll ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			} elseif ( $tcmha_poll ) {
				echo tcmha_poll_render( $tcmha_poll ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			?>
		</div>
	</section>
</main>

<footer class="tcmha-poll-footer">
	<div class="tcmha-poll-wrap">
		<p><?php esc_html_e( 'Prepared by Storiline for ChangeLine and the Teller County Mental Health Alliance.', 'tcmha' ); ?></p>
		<p><?php esc_html_e( 'We only keep your name, email, and answers until 30 days after the meeting.', 'tcmha' ); ?></p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
