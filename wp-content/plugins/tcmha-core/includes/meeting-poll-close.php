<?php
/**
 * Meeting poll: response deadline, final tally email, and the organizer's "choose the final time" page.
 *
 * The time is never picked automatically. At the deadline, the "Send responses to" addresses get
 * the final tally and a private link. The organizer picks a time on that page, which emails the
 * calendar invite to everyone who left an address.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the deadline has passed.
 *
 * @param array $poll Poll settings.
 * @return bool
 */
function tcmha_poll_deadline_passed( $poll ) {
	if ( '' === $poll['deadline'] ) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $poll['deadline'], tcmha_poll_timezone( $poll ) );
	return $date && $date->getTimestamp() <= time();
}

/**
 * Whether the poll no longer takes answers (a time was picked, or the deadline passed).
 *
 * @param array $poll Poll settings.
 * @return bool
 */
function tcmha_poll_is_closed( $poll ) {
	return '' !== $poll['locked'] || tcmha_poll_deadline_passed( $poll );
}

/**
 * The deadline in plain words, e.g. "Monday, December 7 at 5:00 PM MT".
 *
 * @param array $poll Poll settings.
 * @return string
 */
function tcmha_poll_deadline_label( $poll ) {
	$tz   = tcmha_poll_timezone( $poll );
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $poll['deadline'], $tz );
	if ( ! $date ) {
		return '';
	}
	$zone = 'America/Denver' === $tz->getName() ? 'MT' : $date->format( 'T' );
	/* translators: 1: date, 2: time, 3: time zone */
	return sprintf( __( '%1$s at %2$s %3$s', 'tcmha' ), wp_date( 'l, F j', $date->getTimestamp(), $tz ), wp_date( 'g:i A', $date->getTimestamp(), $tz ), $zone );
}

/**
 * The organizer's private link for choosing the final time.
 *
 * @param int $post_id Poll ID.
 * @return string
 */
function tcmha_poll_choose_url( $post_id ) {
	$key = (string) get_post_meta( $post_id, '_tcmha_poll_choose_key', true );
	if ( '' === $key ) {
		$key = strtolower( wp_generate_password( 24, false, false ) );
		update_post_meta( $post_id, '_tcmha_poll_choose_key', $key );
	}
	return add_query_arg( 'choose', rawurlencode( $key ), tcmha_poll_share_url( $post_id ) );
}

/**
 * Whether the current request carries the poll's valid choose key.
 *
 * @param array $poll Poll settings.
 * @return bool
 */
function tcmha_poll_choose_mode( $poll ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the access check.
	$given = isset( $_GET['choose'] ) ? sanitize_key( wp_unslash( $_GET['choose'] ) ) : '';
	$key   = (string) get_post_meta( $poll['id'], '_tcmha_poll_choose_key', true );
	return '' !== $given && '' !== $key && hash_equals( $key, $given );
}

/**
 * Slots ranked best first (Works counts double), then by date.
 *
 * @param array $tally Tally from tcmha_poll_tally().
 * @return string[]
 */
function tcmha_poll_ranked_slots( $tally ) {
	$slots = array_keys( $tally['slots'] );
	usort(
		$slots,
		static function ( $a, $b ) use ( $tally ) {
			return array( $tally['slots'][ $b ]['score'], $a ) <=> array( $tally['slots'][ $a ]['score'], $b );
		}
	);
	return $slots;
}

/**
 * Email the final tally and the choose link to the "Send responses to" addresses.
 *
 * @param array $poll Poll settings.
 * @return bool Whether it was sent.
 */
function tcmha_poll_mail_final_tally( $poll ) {
	if ( ! $poll['send_to'] ) {
		return false;
	}
	$title = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$tally = tcmha_poll_tally( $poll, tcmha_poll_responses( $poll['id'] ) );

	/* translators: %s: poll title */
	$subject = sprintf( __( 'Final answers: %s', 'tcmha' ), $title );
	$lines   = array(
		/* translators: %d: number of people */
		sprintf( _n( 'Answers are closed. %d person answered.', 'Answers are closed. %d people answered.', $tally['total'], 'tcmha' ), $tally['total'] ),
		'',
		__( 'Choose the final time here. Nothing is sent until you choose:', 'tcmha' ),
		tcmha_poll_choose_url( $poll['id'] ),
		'',
		__( 'TIMES, BEST FIRST (Mountain Time)', 'tcmha' ),
	);
	foreach ( tcmha_poll_ranked_slots( $tally ) as $slot ) {
		$row     = $tally['slots'][ $slot ];
		$label   = tcmha_poll_slot_label( $poll, $slot );
		$lines[] = '';
		$lines[] = $label['day'] . ', ' . $label['range'];
		$lines[] = sprintf(
			/* translators: 1: works, 2: if needed, 3: can't */
			__( '  %1$d works, %2$d if needed, %3$d can\'t', 'tcmha' ),
			count( $row['yes'] ),
			count( $row['maybe'] ),
			count( $row['no'] )
		);
		if ( $row['yes'] ) {
			$lines[] = '  ' . __( 'Works:', 'tcmha' ) . ' ' . implode( ', ', $row['yes'] );
		}
		if ( $row['maybe'] ) {
			$lines[] = '  ' . __( 'If needed:', 'tcmha' ) . ' ' . implode( ', ', $row['maybe'] );
		}
		if ( $row['no'] ) {
			$lines[] = '  ' . __( "Can't:", 'tcmha' ) . ' ' . implode( ', ', $row['no'] );
		}
	}

	$sent = wp_mail( $poll['send_to'], $subject, implode( "\n", $lines ) . tcmha_poll_mail_signoff() );
	if ( $sent ) {
		update_post_meta( $poll['id'], '_tcmha_poll_tally_sent', time() );
		update_post_meta( $poll['id'], '_tcmha_poll_tally_for', $poll['deadline'] );
	}
	return $sent;
}

/**
 * Send the final tally for any poll whose deadline has passed (once per deadline).
 */
function tcmha_poll_check_deadlines() {
	$ids = get_posts(
		array(
			'post_type'      => 'tcmha_poll',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $post_id ) {
		$poll = tcmha_poll_get( $post_id );
		if ( '' === $poll['locked'] && tcmha_poll_deadline_passed( $poll ) && ! get_post_meta( $post_id, '_tcmha_poll_tally_sent', true ) ) {
			tcmha_poll_mail_final_tally( $poll );
		}
	}
}
add_action( 'tcmha_poll_deadlines', 'tcmha_poll_check_deadlines' );

add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'tcmha_poll_deadlines' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'tcmha_poll_deadlines' );
		}
	}
);

/**
 * Choose page: handle the organizer's pick, and send the tally if the cron hasn't yet.
 */
add_action(
	'template_redirect',
	static function () {
		$poll = tcmha_poll_current();
		if ( ! $poll ) {
			return;
		}
		if ( '' === $poll['locked'] && tcmha_poll_deadline_passed( $poll ) && ! get_post_meta( $poll['id'], '_tcmha_poll_tally_sent', true ) ) {
			tcmha_poll_mail_final_tally( $poll );
		}
		if ( ! tcmha_poll_choose_mode( $poll ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['tcmha_poll_choose'] ) ) {
			return;
		}

		$back = tcmha_poll_choose_url( $poll['id'] );
		if ( ! isset( $_POST['tcmha_poll_choose_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_poll_choose_nonce'] ) ), 'tcmha_poll_choose_' . $poll['id'] ) ) {
			wp_safe_redirect( add_query_arg( 'poll_error', 'expired', $back ), 303 );
			exit;
		}
		$slot = sanitize_text_field( wp_unslash( $_POST['tcmha_poll_choose'] ) );
		if ( '' !== $poll['locked'] || ! in_array( $slot, $poll['slots'], true ) ) {
			wp_safe_redirect( $back, 303 );
			exit;
		}

		$poll['locked'] = $slot;
		tcmha_poll_store( $poll['id'], $poll );
		$result = tcmha_poll_send_invites( $poll );
		update_post_meta( $poll['id'], '_tcmha_poll_last_send', array_merge( $result, array( 'at' => time() ) ) );

		wp_safe_redirect( add_query_arg( 'chosen', '1', $back ), 303 );
		exit;
	},
	6
);

/**
 * The organizer's choose screen.
 *
 * @param array $poll Poll settings.
 * @return string
 */
function tcmha_poll_render_choose( $poll ) {
	$responses = tcmha_poll_responses( $poll['id'] );
	$tally     = tcmha_poll_tally( $poll, $responses );
	$with_mail = count( array_unique( array_filter( array_map( 'strtolower', wp_list_pluck( $responses, 'email' ) ) ) ) );
	$last_send = get_post_meta( $poll['id'], '_tcmha_poll_last_send', true );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
	$chosen = isset( $_GET['chosen'] );

	ob_start();
	?>
	<div class="tcmha-poll tcmha-poll--choose" id="tcmha-poll" data-tz="<?php echo esc_attr( tcmha_poll_timezone( $poll )->getName() ); ?>">
		<?php if ( '' !== $poll['locked'] ) : ?>
			<?php $label = tcmha_poll_slot_label( $poll, $poll['locked'] ); ?>
			<section class="tcmha-poll__picked" aria-labelledby="tcmha-poll-final" <?php echo $chosen ? 'role="status" tabindex="-1" data-focus' : ''; ?>>
				<h2 id="tcmha-poll-final"><?php esc_html_e( 'Final time', 'tcmha' ); ?></h2>
				<p class="tcmha-poll__picked-time"><span><?php echo esc_html( $label['day'] ); ?></span> <span><?php echo esc_html( $label['time'] ); ?></span></p>
				<?php if ( is_array( $last_send ) && isset( $last_send['sent'] ) ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number sent, 2: number failed */
								__( 'Calendar invites sent: %1$d. Failed: %2$d.', 'tcmha' ),
								(int) $last_send['sent'],
								(int) $last_send['failed']
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p><?php esc_html_e( 'To change the time, reopen the poll in WP admin.', 'tcmha' ); ?></p>
			</section>
		<?php else : ?>
			<h2 class="tcmha-poll__heading"><?php esc_html_e( 'Choose the final time', 'tcmha' ); ?></h2>
			<p class="tcmha-poll__lead">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of people, 2: number with email */
						__( '%1$d people answered. When you choose a time, a calendar invite is emailed to the %2$d who left an address. Times are listed best first, in Mountain Time.', 'tcmha' ),
						$tally['total'],
						$with_mail
					)
				);
				?>
				<?php if ( ! tcmha_poll_deadline_passed( $poll ) ) : ?>
					<strong><?php esc_html_e( 'The poll is still open, so more answers may come in.', 'tcmha' ); ?></strong>
				<?php endif; ?>
			</p>
			<?php
			$confirm = sprintf(
				/* translators: %d: number of people */
				_n( 'Choose this time and email the invite to %d person?', 'Choose this time and email the invite to %d people?', $with_mail, 'tcmha' ),
				$with_mail
			);
			?>
			<ol class="tcmha-poll__ranked">
				<?php foreach ( tcmha_poll_ranked_slots( $tally ) as $slot ) : ?>
					<?php
					$row   = $tally['slots'][ $slot ];
					$label = tcmha_poll_slot_label( $poll, $slot );
					?>
					<li class="tcmha-poll__day-group<?php echo in_array( $slot, $tally['best'], true ) ? ' is-best' : ''; ?>">
						<h3 class="tcmha-poll__day"><?php echo esc_html( $label['day'] . ', ' . $label['range'] ); ?></h3>
						<?php tcmha_poll_render_tally( array_merge( $poll, array( 'show_names' => true ) ), $row, $tally['total'], in_array( $slot, $tally['best'], true ) ); ?>
						<?php if ( $row['no'] ) : ?>
							<p class="tcmha-poll__names"><?php echo esc_html( __( "Can't:", 'tcmha' ) . ' ' . implode( ', ', $row['no'] ) ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( tcmha_poll_choose_url( $poll['id'] ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);">
							<?php wp_nonce_field( 'tcmha_poll_choose_' . $poll['id'], 'tcmha_poll_choose_nonce', false ); ?>
							<input type="hidden" name="tcmha_poll_choose" value="<?php echo esc_attr( $slot ); ?>">
							<button type="submit" class="tcmha-poll__button">
								<?php esc_html_e( 'Choose this time and send invites', 'tcmha' ); ?>
								<span class="tcmha-poll__sr"><?php echo esc_html( ': ' . $label['full'] ); ?></span>
							</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
