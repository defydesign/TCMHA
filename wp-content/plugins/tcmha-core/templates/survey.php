<?php
/**
 * Standalone branded page for a poll (no theme header or footer), matching the meeting poll page:
 * white masthead, purple hero band, light section, purple footer band.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

$tcmha_survey = tcmha_survey_current();
$tcmha_intro  = get_post_field( 'post_content', get_queried_object_id() );
$tcmha_count  = $tcmha_survey ? count( $tcmha_survey['questions'] ) : 0;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'tcmha-poll-page tcmha-survey-page' ); ?>>
<?php wp_body_open(); ?>
<a class="tcmha-skip" href="#tcmha-poll-main"><?php esc_html_e( 'Skip to the poll', 'tcmha' ); ?></a>

<header class="tcmha-poll-masthead">
	<div class="tcmha-poll-wrap tcmha-poll-masthead__inner">
		<p class="tcmha-poll-brand">
			<strong>TCMHA</strong>
			<span><?php esc_html_e( 'Teller County Mental Health Alliance', 'tcmha' ); ?></span>
		</p>
		<?php if ( $tcmha_count ) : ?>
			<p class="tcmha-poll-masthead__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of questions */
						_n( '%d question', '%d questions', $tcmha_count, 'tcmha' ),
						$tcmha_count
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</header>

<main id="tcmha-poll-main" tabindex="-1">
	<section class="tcmha-poll-hero">
		<div class="tcmha-poll-wrap">
			<p class="tcmha-poll-eyebrow"><?php esc_html_e( 'Poll', 'tcmha' ); ?></p>
			<h1><?php echo esc_html( get_the_title() ); ?></h1>
			<?php if ( '' !== trim( (string) $tcmha_intro ) ) : ?>
				<div class="tcmha-poll-lead"><?php echo wp_kses_post( wpautop( $tcmha_intro ) ); ?></div>
			<?php endif; ?>
		</div>
	</section>

	<section class="tcmha-poll-body">
		<div class="tcmha-poll-wrap">
			<?php
			if ( $tcmha_survey ) {
				echo tcmha_survey_render( $tcmha_survey ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			?>
		</div>
	</section>
</main>

<footer class="tcmha-poll-footer">
	<div class="tcmha-poll-wrap">
		<p><?php esc_html_e( 'Teller County Mental Health Alliance', 'tcmha' ); ?></p>
		<p>
			<?php
			if ( $tcmha_survey && ( 'none' !== $tcmha_survey['ask_name'] || $tcmha_survey['ask_email'] ) ) {
				echo esc_html(
					sprintf(
						/* translators: %d: days */
						__( 'Only the organizers see names and emails. We remove them %d days after the poll closes.', 'tcmha' ),
						TCMHA_SURVEY_SCRUB_DAYS
					)
				);
			} else {
				esc_html_e( 'We do not ask who you are. Answers are anonymous.', 'tcmha' );
			}
			?>
		</p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
