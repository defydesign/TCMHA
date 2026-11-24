<?php
/**
 * Meeting poll: WP admin screens, actions, and admin-only REST routes.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

// The classic editor keeps the settings boxes simple.
add_filter(
	'use_block_editor_for_post_type',
	static function ( $use, $post_type ) {
		return 'tcmha_poll' === $post_type ? false : $use;
	},
	10,
	2
);

add_action(
	'add_meta_boxes_tcmha_poll',
	static function ( $post ) {
		$has_answers = (bool) tcmha_poll_responses( $post->ID );
		// Once people have answered, show the answers first. Before that, settings come first.
		if ( $has_answers ) {
			add_meta_box( 'tcmha-poll-results', __( 'Answers', 'tcmha' ), 'tcmha_poll_results_box', 'tcmha_poll', 'normal', 'high' );
			add_meta_box( 'tcmha-poll-settings', __( 'Poll settings', 'tcmha' ), 'tcmha_poll_settings_box', 'tcmha_poll', 'normal', 'high' );
		} else {
			add_meta_box( 'tcmha-poll-settings', __( 'Poll settings', 'tcmha' ), 'tcmha_poll_settings_box', 'tcmha_poll', 'normal', 'high' );
			add_meta_box( 'tcmha-poll-results', __( 'Answers', 'tcmha' ), 'tcmha_poll_results_box', 'tcmha_poll', 'normal', 'high' );
		}
		add_meta_box( 'tcmha-poll-howto', __( 'How a meeting poll works', 'tcmha' ), 'tcmha_poll_howto_box', 'tcmha_poll', 'side', 'default' );
	}
);

// Title placeholder.
add_filter(
	'enter_title_here',
	static function ( $text, $post ) {
		return 'tcmha_poll' === $post->post_type ? __( 'Poll title people will see, e.g. "Website kickoff: when can you meet?"', 'tcmha' ) : $text;
	},
	10,
	2
);

// Note above the intro editor.
add_action(
	'edit_form_after_title',
	static function ( $post ) {
		if ( 'tcmha_poll' !== $post->post_type ) {
			return;
		}
		echo '<p class="tcmha-poll-admin-note"><strong>' . esc_html__( 'Intro text', 'tcmha' ) . '</strong> ' . esc_html__( '(shown under the title). Two or three short sentences: what the meeting is for, how long it is, and who should answer. Example: "We are building a new website for TCMHA and want your ideas. The kickoff is a one-hour video meeting. Please tell us which times work for you."', 'tcmha' ) . '</p>';
	}
);

add_action(
	'admin_head',
	static function () {
		$screen = get_current_screen();
		if ( ! $screen || 'tcmha_poll' !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			.tcmha-poll-admin-note { margin: 1em 0 0.5em; max-width: 60em; }
			.tcmha-poll-admin h3 { margin: 1.5em 0 0.25em; padding-bottom: 0.35em; border-bottom: 1px solid #dcdcde; font-size: 1.05em; }
			.tcmha-poll-admin h3:first-child { margin-top: 0.5em; }
			.tcmha-poll-admin .tcmha-field { margin: 1em 0; }
			.tcmha-poll-admin .tcmha-field > label, .tcmha-poll-admin .tcmha-field > .tcmha-label { display: block; font-weight: 600; margin-bottom: 0.3em; }
			.tcmha-poll-admin .description { display: block; margin-top: 0.35em; max-width: 52em; }
			.tcmha-slot-rows { margin: 0; padding: 0; list-style: none; }
			.tcmha-slot-rows li { display: flex; flex-wrap: wrap; gap: 0.5em; align-items: center; margin: 0 0 0.4em; }
			.tcmha-slot-rows input[type="date"], .tcmha-slot-rows input[type="time"] { min-height: 32px; }
			.tcmha-poll-howto ol { margin: 0.5em 0 0 1.25em; }
			.tcmha-poll-howto li { margin-bottom: 0.6em; }
		</style>
		<?php
	}
);

/**
 * "How it works" side box: the whole process, so the organizer knows what to expect.
 */
function tcmha_poll_howto_box() {
	?>
	<div class="tcmha-poll-howto">
		<ol>
			<li><?php esc_html_e( 'Fill in the title, intro, and Poll settings, then press Publish. Publishing does not email anyone.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Copy "Link to share" from the Answers box and send it to the people you are inviting (email, newsletter, etc.). Only people with the link can open the poll.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'People choose Works, If needed, or Can\'t for each time. No account needed. If they leave an email, they get a confirmation with a private link to change their answers.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Each answer is emailed to the "Send responses to" addresses. You can also watch answers here in the Answers box.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'At the deadline, answers close. The "Send responses to" addresses get the final tally, best times first, with a private link to choose the time.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'You choose the time. It is never picked automatically. Choosing it emails everyone who left an address a calendar invite with the video link, and the poll page shows the final time.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Answers are deleted automatically 30 days after the meeting.', 'tcmha' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Need to change something after people have answered? You can edit any setting. If you remove a time, answers for that time are dropped.', 'tcmha' ); ?></p>
	</div>
	<?php
}

/**
 * Settings meta box.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_poll_settings_box( $post ) {
	$poll = tcmha_poll_get( $post->ID );
	$tz   = tcmha_poll_timezone( $poll );
	wp_nonce_field( 'tcmha_poll_settings_' . $post->ID, 'tcmha_poll_settings_nonce' );

	$rows = $poll['slots'];
	for ( $i = 0; $i < max( 2, 4 - count( $rows ) ); $i++ ) {
		$rows[] = ''; // Empty rows to fill in.
	}
	$zones = array(
		'America/Denver'      => __( 'Mountain Time (Colorado)', 'tcmha' ),
		'America/Phoenix'     => __( 'Mountain Time, no daylight saving (Arizona)', 'tcmha' ),
		'America/Chicago'     => __( 'Central Time', 'tcmha' ),
		'America/New_York'    => __( 'Eastern Time', 'tcmha' ),
		'America/Los_Angeles' => __( 'Pacific Time', 'tcmha' ),
	);
	if ( ! isset( $zones[ $poll['tz'] ] ) ) {
		$zones[ $poll['tz'] ] = $poll['tz'];
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
	$skipped = get_transient( 'tcmha_poll_skipped_' . $post->ID . '_' . get_current_user_id() );
	if ( $skipped ) {
		delete_transient( 'tcmha_poll_skipped_' . $post->ID . '_' . get_current_user_id() );
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some times were not saved because the date or time was missing:', 'tcmha' ) . ' ' . esc_html( implode( '; ', (array) $skipped ) ) . '</p></div>';
	}
	?>
	<div class="tcmha-poll-admin">
		<h3><?php esc_html_e( 'The meeting', 'tcmha' ); ?></h3>

		<div class="tcmha-field">
			<span class="tcmha-label" id="tcmha-slots-label"><?php esc_html_e( 'Times to offer', 'tcmha' ); ?></span>
			<ul class="tcmha-slot-rows" id="tcmha-slot-rows" aria-labelledby="tcmha-slots-label">
				<?php foreach ( $rows as $i => $slot ) : ?>
					<?php
					$date = '' === $slot ? '' : substr( $slot, 0, 10 );
					$time = '' === $slot ? '' : substr( $slot, 11, 5 );
					?>
					<li>
						<label class="screen-reader-text" for="tcmha-slot-date-<?php echo (int) $i; ?>"><?php esc_html_e( 'Date', 'tcmha' ); ?></label>
						<input type="date" id="tcmha-slot-date-<?php echo (int) $i; ?>" name="tcmha_poll[slot_date][]" value="<?php echo esc_attr( $date ); ?>">
						<label class="screen-reader-text" for="tcmha-slot-time-<?php echo (int) $i; ?>"><?php esc_html_e( 'Start time', 'tcmha' ); ?></label>
						<input type="time" id="tcmha-slot-time-<?php echo (int) $i; ?>" name="tcmha_poll[slot_time][]" step="900" value="<?php echo esc_attr( $time ); ?>">
						<button type="button" class="button-link tcmha-slot-remove"><?php esc_html_e( 'Remove', 'tcmha' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="button" id="tcmha-slot-add"><?php esc_html_e( 'Add another time', 'tcmha' ); ?></button>
			<span class="description"><?php esc_html_e( 'One row per choice: the date and the start time. Example: Dec 11, 2026 at 9:00 AM. Leave extra rows blank. They are put in order when you save, and the page groups them by day.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<label for="tcmha-poll-duration"><?php esc_html_e( 'Meeting length (minutes)', 'tcmha' ); ?></label>
			<input type="number" id="tcmha-poll-duration" name="tcmha_poll[duration]" min="15" max="600" step="15" value="<?php echo esc_attr( $poll['duration'] ); ?>" aria-describedby="tcmha-poll-duration-hint">
			<span id="tcmha-poll-duration-hint" class="description"><?php esc_html_e( 'Used to show end times and for the calendar invite. Example: 60 for one hour, 90 for an hour and a half.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<label for="tcmha-poll-tz"><?php esc_html_e( 'Time zone', 'tcmha' ); ?></label>
			<select id="tcmha-poll-tz" name="tcmha_poll[tz]" aria-describedby="tcmha-poll-tz-hint">
				<?php foreach ( $zones as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $poll['tz'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span id="tcmha-poll-tz-hint" class="description"><?php esc_html_e( 'The times above and the deadline are in this time zone. People in other time zones also see the time in their own zone.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<label for="tcmha-poll-link"><?php esc_html_e( 'Video meeting link', 'tcmha' ); ?></label>
			<input type="url" id="tcmha-poll-link" name="tcmha_poll[link]" value="<?php echo esc_attr( $poll['link'] ); ?>" class="large-text" placeholder="https://" aria-describedby="tcmha-poll-link-hint">
			<span id="tcmha-poll-link-hint" class="description"><?php esc_html_e( 'The Zoom, Teams, or Google Meet link. Example: https://us02web.zoom.us/j/1234567890. It stays hidden until you choose the time, then it appears on the page and in the calendar invite. You can add it later.', 'tcmha' ); ?></span>
		</div>

		<h3><?php esc_html_e( 'Answers and emails', 'tcmha' ); ?></h3>

		<div class="tcmha-field">
			<label for="tcmha-poll-deadline"><?php esc_html_e( 'Answer by (deadline)', 'tcmha' ); ?></label>
			<input type="datetime-local" id="tcmha-poll-deadline" name="tcmha_poll[deadline]" value="<?php echo esc_attr( str_replace( ' ', 'T', $poll['deadline'] ) ); ?>" aria-describedby="tcmha-poll-deadline-hint">
			<span id="tcmha-poll-deadline-hint" class="description"><?php esc_html_e( 'Example: Dec 7, 2026 at 11:59 PM for "end of day Monday". Answers close then, and the final tally with a link to choose the time is emailed to the addresses below (within about an hour). Leave blank to keep the poll open until you choose a time.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<label for="tcmha-poll-send-to"><?php esc_html_e( 'Send responses to', 'tcmha' ); ?></label>
			<input type="text" id="tcmha-poll-send-to" name="tcmha_poll[send_to]" value="<?php echo esc_attr( implode( ', ', $poll['send_to'] ) ); ?>" class="large-text" placeholder="name@example.org, another@example.org" aria-describedby="tcmha-poll-send-to-hint" autocomplete="off">
			<span id="tcmha-poll-send-to-hint" class="description"><?php esc_html_e( 'Who runs this poll. Separate several addresses with commas (up to 10). Example: admin@tcmha.org, you@example.org. These addresses get an email for each answer and the final tally with the choose link, and replies to poll emails come here. Leave blank for no emails.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<span class="tcmha-label"><?php esc_html_e( 'Names', 'tcmha' ); ?></span>
			<input type="hidden" name="tcmha_poll[show_names]" value="0">
			<label><input type="checkbox" name="tcmha_poll[show_names]" value="1" <?php checked( $poll['show_names'] ); ?> aria-describedby="tcmha-poll-names-hint"> <?php esc_html_e( 'Show who answered to everyone with the link', 'tcmha' ); ?></label>
			<span id="tcmha-poll-names-hint" class="description"><?php esc_html_e( 'On: people see names under each time (like most scheduling polls). Off: they only see counts. You always see names here and in emails.', 'tcmha' ); ?></span>
		</div>
	</div>
	<script>
	( function () {
		var list = document.getElementById( 'tcmha-slot-rows' );
		var add = document.getElementById( 'tcmha-slot-add' );
		if ( ! list || ! add ) { return; }
		var n = list.children.length;
		add.addEventListener( 'click', function () {
			var row = list.lastElementChild.cloneNode( true );
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
				input.id = input.id.replace( /\d+$/, n );
			} );
			row.querySelectorAll( 'label' ).forEach( function ( label ) {
				label.htmlFor = label.htmlFor.replace( /\d+$/, n );
			} );
			n++;
			list.appendChild( row );
			row.querySelector( 'input' ).focus();
		} );
		list.addEventListener( 'click', function ( e ) {
			if ( ! e.target.classList.contains( 'tcmha-slot-remove' ) ) { return; }
			var row = e.target.closest( 'li' );
			if ( list.children.length > 1 ) {
				var next = row.nextElementSibling || row.previousElementSibling;
				row.remove();
				next.querySelector( 'input' ).focus();
			} else {
				row.querySelectorAll( 'input' ).forEach( function ( input ) { input.value = ''; } );
			}
		} );
	}() );
	</script>
	<?php
}

add_action(
	'save_post_tcmha_poll',
	static function ( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['tcmha_poll_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_poll_settings_nonce'] ) ), 'tcmha_poll_settings_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['tcmha_poll'] ) || ! is_array( $_POST['tcmha_poll'] ) ) {
			return;
		}
		// Sanitized field by field in tcmha_poll_save_settings().
		$input = wp_unslash( $_POST['tcmha_poll'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Combine the date and time rows into "Y-m-d H:i" slots.
		if ( isset( $input['slot_date'], $input['slot_time'] ) && is_array( $input['slot_date'] ) && is_array( $input['slot_time'] ) ) {
			$slots   = array();
			$skipped = array();
			foreach ( $input['slot_date'] as $i => $date ) {
				$date = sanitize_text_field( (string) $date );
				$time = sanitize_text_field( (string) ( $input['slot_time'][ $i ] ?? '' ) );
				if ( '' === $date && '' === $time ) {
					continue;
				}
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}/', $time ) ) {
					$slots[] = $date . ' ' . substr( $time, 0, 5 );
				} else {
					$skipped[] = trim( $date . ' ' . $time );
				}
			}
			$input['slots'] = $slots;
			if ( $skipped ) {
				set_transient( 'tcmha_poll_skipped_' . $post_id . '_' . get_current_user_id(), $skipped, 5 * MINUTE_IN_SECONDS );
			}
		}
		unset( $input['slot_date'], $input['slot_time'] );

		tcmha_poll_save_settings( $post_id, $input );
	}
);

/**
 * Admin action URL with a nonce.
 *
 * @param string $action  Action name.
 * @param int    $post_id Poll ID.
 * @param array  $args    Extra args.
 * @return string
 */
function tcmha_poll_action_url( $action, $post_id, $args = array() ) {
	$url = add_query_arg(
		array_merge(
			array(
				'action' => 'tcmha_poll_' . $action,
				'post'   => $post_id,
			),
			$args
		),
		admin_url( 'admin-post.php' )
	);
	return wp_nonce_url( $url, 'tcmha_poll_' . $action . '_' . $post_id );
}

/**
 * Results meta box: share link, answers grid, pick a time, send invites, export, delete.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_poll_results_box( $post ) {
	$poll      = tcmha_poll_get( $post->ID );
	$responses = tcmha_poll_responses( $post->ID );
	$tally     = tcmha_poll_tally( $poll, $responses );
	$with_mail = count( array_filter( wp_list_pluck( $responses, 'email' ) ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
	$notice = isset( $_GET['tcmha_poll_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['tcmha_poll_notice'] ) ) : '';
	if ( $notice ) {
		printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $notice ) );
	}

	if ( 'publish' !== $post->post_status ) {
		echo '<p>' . esc_html__( 'Publish this poll to get its link.', 'tcmha' ) . '</p>';
	} else {
		$share = tcmha_poll_share_url( $post->ID );
		?>
		<p>
			<label for="tcmha-poll-share"><strong><?php esc_html_e( 'Link to share', 'tcmha' ); ?></strong></label><br>
			<input type="text" id="tcmha-poll-share" class="large-text code" readonly value="<?php echo esc_attr( $share ); ?>" onfocus="this.select()">
			<span class="description"><?php esc_html_e( 'Only people with this link can open the poll.', 'tcmha' ); ?></span>
		</p>
		<p>
			<label for="tcmha-poll-choose"><strong><?php esc_html_e( 'Organizer link: choose the final time', 'tcmha' ); ?></strong></label><br>
			<input type="text" id="tcmha-poll-choose" class="large-text code" readonly value="<?php echo esc_attr( tcmha_poll_choose_url( $post->ID ) ); ?>" onfocus="this.select()">
			<span class="description"><?php esc_html_e( 'Keep this private. Anyone with it can pick the time and send the invites. It is also in the final tally email.', 'tcmha' ); ?></span>
		</p>
		<p>
			<?php
			$tally_sent = (int) get_post_meta( $post->ID, '_tcmha_poll_tally_sent', true );
			if ( $tally_sent ) {
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Final tally emailed %s.', 'tcmha' ), wp_date( 'M j, g:i A', $tally_sent, tcmha_poll_timezone( $poll ) ) ) ) . ' ';
			} elseif ( '' !== $poll['deadline'] ) {
				/* translators: %s: deadline */
				echo esc_html( sprintf( __( 'Final tally will be emailed after %s.', 'tcmha' ), tcmha_poll_deadline_label( $poll ) ) ) . ' ';
			}
			if ( $poll['send_to'] ) :
				?>
				<a class="button" href="<?php echo esc_url( tcmha_poll_action_url( 'tally', $post->ID ) ); ?>"><?php esc_html_e( 'Email the tally now', 'tcmha' ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'Add "Send responses to" addresses to get the final tally by email.', 'tcmha' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	if ( ! $poll['slots'] ) {
		echo '<p>' . esc_html__( 'Add times under Poll settings, then update.', 'tcmha' ) . '</p>';
		return;
	}
	?>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: answers, 2: with email */
				__( '%1$d answers. %2$d left an email.', 'tcmha' ),
				$tally['total'],
				$with_mail
			)
		);
		?>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time', 'tcmha' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Works', 'tcmha' ); ?></th>
				<th scope="col"><?php esc_html_e( 'If needed', 'tcmha' ); ?></th>
				<th scope="col"><?php esc_html_e( "Can't", 'tcmha' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'tcmha' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $tally['slots'] as $slot => $row ) : ?>
				<?php $label = tcmha_poll_slot_label( $poll, $slot ); ?>
				<tr>
					<th scope="row">
						<?php echo esc_html( $label['full'] ); ?>
						<?php if ( in_array( $slot, $tally['best'], true ) ) : ?>
							<br><strong><?php esc_html_e( 'Best so far', 'tcmha' ); ?></strong>
						<?php endif; ?>
						<?php if ( $slot === $poll['locked'] ) : ?>
							<br><strong><?php esc_html_e( 'Picked', 'tcmha' ); ?></strong>
						<?php endif; ?>
					</th>
					<?php foreach ( array( 'yes', 'maybe', 'no' ) as $answer ) : ?>
						<td><strong><?php echo (int) count( $row[ $answer ] ); ?></strong><br><?php echo esc_html( implode( ', ', $row[ $answer ] ) ); ?></td>
					<?php endforeach; ?>
					<td>
						<?php if ( '' === $poll['locked'] ) : ?>
							<a class="button" href="<?php echo esc_url( tcmha_poll_action_url( 'lock', $post->ID, array( 'slot' => $slot ) ) ); ?>"><?php esc_html_e( 'Pick this time', 'tcmha' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<?php if ( '' !== $poll['locked'] ) : ?>
			<?php
			$confirm = sprintf(
				/* translators: %d: number of people */
				_n( 'Email the invite to %d person now?', 'Email the invite to %d people now?', $with_mail, 'tcmha' ),
				$with_mail
			);
			?>
			<a class="button button-primary" href="<?php echo esc_url( tcmha_poll_action_url( 'send', $post->ID ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of people */
						_n( 'Email invite to %d person', 'Email invite to %d people', $with_mail, 'tcmha' ),
						$with_mail
					)
				);
				?>
			</a>
			<a class="button" href="<?php echo esc_url( tcmha_poll_action_url( 'unlock', $post->ID ) ); ?>"><?php esc_html_e( 'Reopen the poll', 'tcmha' ); ?></a>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( tcmha_poll_action_url( 'csv', $post->ID ) ); ?>"><?php esc_html_e( 'Download answers (CSV)', 'tcmha' ); ?></a>
		<?php if ( $responses ) : ?>
			<a class="button-link-delete" href="<?php echo esc_url( tcmha_poll_action_url( 'delete', $post->ID ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete all answers? This cannot be undone.', 'tcmha' ) ) ); ?>);"><?php esc_html_e( 'Delete all answers', 'tcmha' ); ?></a>
		<?php endif; ?>
	</p>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: days */
				__( 'Answers are deleted automatically %d days after the picked meeting time.', 'tcmha' ),
				TCMHA_POLL_PURGE_DAYS
			)
		);
		?>
	</p>
	<?php
}

/**
 * Check nonce and capability for an admin action. Returns the poll.
 *
 * @param string $action Action name.
 * @return array
 */
function tcmha_poll_admin_guard( $action ) {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
	check_admin_referer( 'tcmha_poll_' . $action . '_' . $post_id );
	if ( ! $post_id || 'tcmha_poll' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You cannot change this poll.', 'tcmha' ), 403 );
	}
	return tcmha_poll_get( $post_id );
}

/**
 * Back to the poll's edit screen with a notice.
 *
 * @param int    $post_id Poll ID.
 * @param string $notice  Message.
 */
function tcmha_poll_admin_back( $post_id, $notice ) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'post'              => $post_id,
				'action'            => 'edit',
				'tcmha_poll_notice' => rawurlencode( $notice ),
			),
			admin_url( 'post.php' )
		)
	);
	exit;
}

add_action(
	'admin_post_tcmha_poll_lock',
	static function () {
		$poll = tcmha_poll_admin_guard( 'lock' );
		$slot = isset( $_GET['slot'] ) ? sanitize_text_field( wp_unslash( $_GET['slot'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guarded above.
		if ( ! in_array( $slot, $poll['slots'], true ) ) {
			tcmha_poll_admin_back( $poll['id'], __( 'That time is not in this poll.', 'tcmha' ) );
		}
		$poll['locked'] = $slot;
		tcmha_poll_store( $poll['id'], $poll );
		tcmha_poll_admin_back( $poll['id'], __( 'Time picked. The poll page now shows it. Nobody has been emailed yet.', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_poll_tally',
	static function () {
		$poll = tcmha_poll_admin_guard( 'tally' );
		$sent = tcmha_poll_mail_final_tally( $poll );
		tcmha_poll_admin_back( $poll['id'], $sent ? __( 'Tally emailed.', 'tcmha' ) : __( 'The tally could not be emailed. Check "Send responses to".', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_poll_unlock',
	static function () {
		$poll           = tcmha_poll_admin_guard( 'unlock' );
		$poll['locked'] = '';
		tcmha_poll_store( $poll['id'], $poll );
		tcmha_poll_admin_back( $poll['id'], __( 'The poll is open again.', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_poll_send',
	static function () {
		$poll   = tcmha_poll_admin_guard( 'send' );
		$result = tcmha_poll_send_invites( $poll );
		update_post_meta( $poll['id'], '_tcmha_poll_last_send', array_merge( $result, array( 'at' => time() ) ) );
		tcmha_poll_admin_back(
			$poll['id'],
			sprintf(
				/* translators: 1: sent, 2: failed */
				__( 'Invites sent: %1$d. Failed: %2$d.', 'tcmha' ),
				$result['sent'],
				$result['failed']
			)
		);
	}
);

add_action(
	'admin_post_tcmha_poll_delete',
	static function () {
		$poll = tcmha_poll_admin_guard( 'delete' );
		delete_post_meta( $poll['id'], TCMHA_POLL_RESPONSE_KEY );
		tcmha_poll_admin_back( $poll['id'], __( 'All answers deleted.', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_poll_csv',
	static function () {
		$poll = tcmha_poll_admin_guard( 'csv' );
		$tz   = tcmha_poll_timezone( $poll );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="poll-' . $poll['id'] . '-answers.csv"' );

		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$head = array( 'Name', 'Email', 'Last changed' );
		foreach ( $poll['slots'] as $slot ) {
			$head[] = tcmha_poll_slot_label( $poll, $slot )['full'];
		}
		fputcsv( $out, $head );
		$words = array(
			'yes'   => 'Works',
			'maybe' => 'If needed',
			'no'    => "Can't",
		);
		foreach ( tcmha_poll_responses( $poll['id'] ) as $row ) {
			$line = array(
				tcmha_poll_csv_safe( $row['name'] ),
				tcmha_poll_csv_safe( $row['email'] ?? '' ),
				wp_date( 'Y-m-d H:i', (int) $row['updated'], $tz ),
			);
			foreach ( $poll['slots'] as $slot ) {
				$line[] = $words[ $row['answers'][ $slot ] ?? '' ] ?? '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);

/**
 * Stop spreadsheet formula injection from typed-in names.
 *
 * @param string $value Cell value.
 * @return string
 */
function tcmha_poll_csv_safe( $value ) {
	$value = (string) $value;
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

/**
 * Poll data for the REST API (editors only).
 *
 * @param int  $post_id   Poll ID.
 * @param bool $responses Include individual answers.
 * @return array
 */
function tcmha_poll_rest_data( $post_id, $responses = true ) {
	$poll  = tcmha_poll_get( $post_id );
	$rows  = tcmha_poll_responses( $post_id );
	$tally = tcmha_poll_tally( $poll, $rows );
	$slots = array();
	foreach ( $tally['slots'] as $slot => $row ) {
		$slots[] = array(
			'slot'  => $slot,
			'label' => tcmha_poll_slot_label( $poll, $slot )['full'],
			'yes'   => $row['yes'],
			'maybe' => $row['maybe'],
			'no'    => $row['no'],
			'score' => $row['score'],
		);
	}
	$data = array(
		'id'        => $post_id,
		'title'     => get_the_title( $post_id ),
		'status'    => get_post_status( $post_id ),
		'url'       => 'publish' === get_post_status( $post_id ) ? tcmha_poll_share_url( $post_id ) : '',
		'settings'  => array_diff_key( $poll, array_flip( array( 'id', 'key' ) ) ),
		'total'     => $tally['total'],
		'best'      => $tally['best'],
		'slots'     => $slots,
		'last_send' => get_post_meta( $post_id, '_tcmha_poll_last_send', true ),
		'choose_url' => 'publish' === get_post_status( $post_id ) ? tcmha_poll_choose_url( $post_id ) : '',
		'tally_sent' => (int) get_post_meta( $post_id, '_tcmha_poll_tally_sent', true ),
	);
	if ( $responses ) {
		$data['responses'] = array_map(
			static function ( $row ) {
				unset( $row['token'] );
				return $row;
			},
			$rows
		);
	}
	return $data;
}

add_action(
	'rest_api_init',
	static function () {
		$settings_args = array(
			'title'      => array( 'type' => 'string' ),
			'content'    => array( 'type' => 'string' ),
			'status'     => array(
				'type' => 'string',
				'enum' => array( 'draft', 'publish' ),
			),
			'slots'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'duration'   => array( 'type' => 'integer' ),
			'tz'         => array( 'type' => 'string' ),
			'link'       => array( 'type' => 'string' ),
			'send_to'    => array(
				'type'  => array( 'array', 'string' ),
				'items' => array( 'type' => 'string' ),
			),
			'show_names' => array( 'type' => 'boolean' ),
			'deadline'   => array( 'type' => 'string' ),
		);

		$save = static function ( WP_REST_Request $request, $post_id ) {
			$post = array( 'ID' => $post_id );
			if ( null !== $request['title'] ) {
				$post['post_title'] = sanitize_text_field( $request['title'] );
			}
			if ( null !== $request['content'] ) {
				$post['post_content'] = wp_kses_post( $request['content'] );
			}
			if ( null !== $request['status'] ) {
				$post['post_status'] = $request['status'];
			}
			if ( count( $post ) > 1 ) {
				wp_update_post( $post );
			}
			$input = array();
			foreach ( array( 'slots', 'duration', 'tz', 'link', 'send_to', 'show_names', 'deadline' ) as $field ) {
				if ( null !== $request[ $field ] ) {
					$input[ $field ] = $request[ $field ];
				}
			}
			tcmha_poll_save_settings( $post_id, $input );
			return rest_ensure_response( tcmha_poll_rest_data( $post_id ) );
		};

		register_rest_route(
			'tcmha/v1',
			'/polls',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'callback'            => static function () {
						$ids = get_posts(
							array(
								'post_type'      => 'tcmha_poll',
								'post_status'    => array( 'publish', 'draft' ),
								'posts_per_page' => 50,
								'fields'         => 'ids',
							)
						);
						return rest_ensure_response(
							array_map(
								static function ( $id ) {
									return tcmha_poll_rest_data( $id, false );
								},
								$ids
							)
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'args'                => $settings_args,
					'permission_callback' => static function () {
						return current_user_can( 'publish_posts' );
					},
					'callback'            => static function ( WP_REST_Request $request ) use ( $save ) {
						$post_id = wp_insert_post(
							array(
								'post_type'   => 'tcmha_poll',
								'post_title'  => sanitize_text_field( (string) ( $request['title'] ?? __( 'When can you meet?', 'tcmha' ) ) ),
								'post_status' => 'draft',
							),
							true
						);
						if ( is_wp_error( $post_id ) ) {
							return $post_id;
						}
						return $save( $request, $post_id );
					},
				),
			)
		);

		$can_edit = static function ( WP_REST_Request $request ) {
			return 'tcmha_poll' === get_post_type( (int) $request['id'] ) && current_user_can( 'edit_post', (int) $request['id'] );
		};

		// Delete all answers (for clearing test answers).
		register_rest_route(
			'tcmha/v1',
			'/polls/(?P<id>\d+)/responses',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $can_edit,
				'callback'            => static function ( WP_REST_Request $request ) {
					delete_post_meta( (int) $request['id'], TCMHA_POLL_RESPONSE_KEY );
					return rest_ensure_response( tcmha_poll_rest_data( (int) $request['id'] ) );
				},
			)
		);

		// Pick a time (slot) or reopen (empty slot). Never sends email: invites go out only from WP admin.
		register_rest_route(
			'tcmha/v1',
			'/polls/(?P<id>\d+)/lock',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $can_edit,
				'args'                => array(
					'slot' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$poll = tcmha_poll_get( (int) $request['id'] );
					$slot = (string) $request['slot'];
					if ( '' !== $slot && ! in_array( $slot, $poll['slots'], true ) ) {
						return new WP_Error( 'tcmha_poll_bad_slot', __( 'That time is not in this poll.', 'tcmha' ), array( 'status' => 400 ) );
					}
					$poll['locked'] = $slot;
					tcmha_poll_store( $poll['id'], $poll );
					return rest_ensure_response( tcmha_poll_rest_data( $poll['id'] ) );
				},
			)
		);

		register_rest_route(
			'tcmha/v1',
			'/polls/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array(
						'type'              => 'integer',
						'validate_callback' => static function ( $id ) {
							return 'tcmha_poll' === get_post_type( (int) $id );
						},
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function ( WP_REST_Request $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => static function ( WP_REST_Request $request ) {
						return rest_ensure_response( tcmha_poll_rest_data( (int) $request['id'] ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'args'                => $settings_args,
					'permission_callback' => static function ( WP_REST_Request $request ) {
						return current_user_can( 'edit_post', (int) $request['id'] );
					},
					'callback'            => static function ( WP_REST_Request $request ) use ( $save ) {
						return $save( $request, (int) $request['id'] );
					},
				),
			)
		);
	}
);
