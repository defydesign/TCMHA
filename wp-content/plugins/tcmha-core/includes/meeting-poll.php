<?php
/**
 * Meeting-time poll ("When can you meet?").
 *
 * A small, self-hosted replacement for third-party scheduling polls:
 * - Each poll is a private `tcmha_poll` post. Its page opens only with the secret link (?k=KEY).
 * - People answer without an account: name, optional email, and Works / If needed / Can't per time.
 * - Each person gets a private link to change their answers. Nothing is stored in cookies.
 * - Editors pick the final time, then email everyone who left an address a calendar invite (.ics).
 * - Responses are deleted automatically 30 days after the chosen meeting time.
 *
 * Only a name and an optional email are collected. Never add fields that invite health information.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

const TCMHA_POLL_ANSWERS      = array( 'yes', 'maybe', 'no' );
const TCMHA_POLL_RESPONSE_KEY = '_tcmha_poll_response';
const TCMHA_POLL_PURGE_DAYS   = 30;

/**
 * Register the poll post type.
 */
function tcmha_poll_register() {
	register_post_type(
		'tcmha_poll',
		array(
			'labels'              => array(
				'name'          => __( 'Meeting polls', 'tcmha' ),
				'singular_name' => __( 'Meeting poll', 'tcmha' ),
				'add_new_item'  => __( 'Add meeting poll', 'tcmha' ),
				'edit_item'     => __( 'Edit meeting poll', 'tcmha' ),
				'menu_name'     => __( 'Meeting polls', 'tcmha' ),
			),
			'public'              => false,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false, // Keeps the secret key and responses out of /wp/v2. See tcmha/v1 routes.
			'menu_icon'           => 'dashicons-calendar-alt',
			'supports'            => array( 'title', 'editor' ),
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => 'meet',
				'with_front' => false,
			),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'tcmha_poll_register' );

/**
 * Default settings for a poll.
 *
 * @return array
 */
function tcmha_poll_defaults() {
	return array(
		'slots'      => array(),        // 'Y-m-d H:i' in the poll's time zone, sorted.
		'duration'   => 60,             // Minutes.
		'tz'         => 'America/Denver',
		'link'       => '',             // Video meeting link, shown once a time is picked.
		'send_to'    => array(),        // "Send responses to": emails that get each response. Also the Reply-To on poll emails.
		'show_names' => true,
		'locked'     => '',             // The chosen slot, or ''.
		'deadline'   => '',             // 'Y-m-d H:i' in the poll's time zone; answers close then and the final tally is emailed.
		'key'        => '',
	);
}

/**
 * Settings for a poll.
 *
 * @param int $post_id Poll ID.
 * @return array
 */
function tcmha_poll_get( $post_id ) {
	$saved = get_post_meta( $post_id, '_tcmha_poll_settings', true );
	$poll  = wp_parse_args( is_array( $saved ) ? $saved : array(), tcmha_poll_defaults() );

	$poll['id']  = (int) $post_id;
	$poll['key'] = (string) get_post_meta( $post_id, '_tcmha_poll_key', true );

	return $poll;
}

/**
 * The poll's time zone.
 *
 * @param array $poll Poll settings.
 * @return DateTimeZone
 */
function tcmha_poll_timezone( $poll ) {
	try {
		return new DateTimeZone( $poll['tz'] );
	} catch ( Exception $e ) {
		return new DateTimeZone( 'America/Denver' );
	}
}

/**
 * Sanitize and save settings. Unknown keys are ignored. Returns the saved settings.
 *
 * @param int   $post_id Poll ID.
 * @param array $input   Raw settings: slots (array or newline string), duration, tz, link, send_to (array or comma/newline string), show_names.
 * @return array
 */
function tcmha_poll_save_settings( $post_id, $input ) {
	$poll = tcmha_poll_get( $post_id );

	if ( isset( $input['tz'] ) && in_array( $input['tz'], timezone_identifiers_list(), true ) ) {
		$poll['tz'] = $input['tz'];
	}
	$tz = tcmha_poll_timezone( $poll );

	if ( isset( $input['slots'] ) ) {
		$lines = is_array( $input['slots'] ) ? $input['slots'] : preg_split( '/\r\n|\r|\n/', (string) $input['slots'] );
		$slots = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $line, $tz );
			if ( $date && $date->format( 'Y-m-d H:i' ) === $line ) {
				$slots[ $line ] = $line;
			}
		}
		ksort( $slots );
		$poll['slots'] = array_values( $slots );
	}

	if ( isset( $input['duration'] ) ) {
		$poll['duration'] = min( 600, max( 15, absint( $input['duration'] ) ) );
	}
	if ( isset( $input['link'] ) ) {
		$poll['link'] = esc_url_raw( trim( (string) $input['link'] ), array( 'https' ) );
	}
	if ( isset( $input['send_to'] ) ) {
		$list = is_array( $input['send_to'] ) ? $input['send_to'] : preg_split( '/[\s,;]+/', (string) $input['send_to'] );
		$poll['send_to'] = array();
		foreach ( $list as $email ) {
			$email = strtolower( sanitize_email( (string) $email ) );
			if ( is_email( $email ) && ! in_array( $email, $poll['send_to'], true ) && count( $poll['send_to'] ) < 10 ) {
				$poll['send_to'][] = $email;
			}
		}
	}
	if ( isset( $input['deadline'] ) ) {
		$raw  = str_replace( 'T', ' ', trim( sanitize_text_field( (string) $input['deadline'] ) ) );
		$date = '' === $raw ? false : DateTimeImmutable::createFromFormat( '!Y-m-d H:i', substr( $raw, 0, 16 ), $tz );
		$poll['deadline'] = $date ? $date->format( 'Y-m-d H:i' ) : '';
		if ( $poll['deadline'] !== (string) get_post_meta( $post_id, '_tcmha_poll_tally_for', true ) ) {
			delete_post_meta( $post_id, '_tcmha_poll_tally_sent' ); // A new deadline gets a new final tally.
		}
	}
	foreach ( array( 'show_names' ) as $flag ) {
		if ( array_key_exists( $flag, $input ) ) {
			$poll[ $flag ] = rest_sanitize_boolean( $input[ $flag ] );
		}
	}
	if ( '' !== $poll['locked'] && ! in_array( $poll['locked'], $poll['slots'], true ) ) {
		$poll['locked'] = '';
	}

	tcmha_poll_store( $post_id, $poll );
	tcmha_poll_ensure_key( $post_id );

	return tcmha_poll_get( $post_id );
}

/**
 * Write settings (without the key, which has its own meta).
 *
 * @param int   $post_id Poll ID.
 * @param array $poll    Settings.
 */
function tcmha_poll_store( $post_id, $poll ) {
	$keep = array_intersect_key( $poll, tcmha_poll_defaults() );
	unset( $keep['key'] );
	update_post_meta( $post_id, '_tcmha_poll_settings', $keep );
}

/**
 * Make sure the poll has a secret link key.
 *
 * @param int $post_id Poll ID.
 * @return string
 */
function tcmha_poll_ensure_key( $post_id ) {
	$key = (string) get_post_meta( $post_id, '_tcmha_poll_key', true );
	if ( '' === $key ) {
		$key = wp_generate_password( 16, false, false );
		update_post_meta( $post_id, '_tcmha_poll_key', $key );
	}
	return $key;
}

/**
 * The shareable link for a poll.
 *
 * @param int $post_id Poll ID.
 * @return string
 */
function tcmha_poll_share_url( $post_id ) {
	return add_query_arg( 'k', rawurlencode( tcmha_poll_ensure_key( $post_id ) ), get_permalink( $post_id ) );
}

/**
 * A respondent's private link to change their answers.
 *
 * @param int    $post_id Poll ID.
 * @param string $rid     Response ID.
 * @param string $token   Plain edit token.
 * @return string
 */
function tcmha_poll_personal_url( $post_id, $rid, $token ) {
	return add_query_arg(
		array(
			'r' => rawurlencode( $rid ),
			't' => rawurlencode( $token ),
		),
		tcmha_poll_share_url( $post_id )
	);
}

/**
 * Start and end of a slot.
 *
 * @param array  $poll Poll settings.
 * @param string $slot Slot string.
 * @return DateTimeImmutable[] [ start, end ]
 */
function tcmha_poll_slot_range( $poll, $slot ) {
	$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $slot, tcmha_poll_timezone( $poll ) );
	if ( ! $start ) {
		$start = new DateTimeImmutable( 'now', tcmha_poll_timezone( $poll ) );
	}
	return array( $start, $start->modify( '+' . (int) $poll['duration'] . ' minutes' ) );
}

/**
 * Plain-language label parts for a slot, e.g. [ 'Tuesday, December 1', '10:00–11:00 AM MT' ].
 *
 * @param array  $poll Poll settings.
 * @param string $slot Slot string.
 * @return array{day: string, time: string, full: string}
 */
function tcmha_poll_slot_label( $poll, $slot ) {
	list( $start, $end ) = tcmha_poll_slot_range( $poll, $slot );
	$tz                  = tcmha_poll_timezone( $poll );

	$day   = wp_date( 'l, F j', $start->getTimestamp(), $tz );
	$same  = $start->format( 'A' ) === $end->format( 'A' );
	$from  = wp_date( $same ? 'g:i' : 'g:i A', $start->getTimestamp(), $tz );
	$to    = wp_date( 'g:i A', $end->getTimestamp(), $tz );
	$zone  = 'America/Denver' === $tz->getName() ? 'MT' : $start->format( 'T' );
	$time  = sprintf( '%1$s–%2$s %3$s', $from, $to, $zone );

	return array(
		'day'   => $day,
		'time'  => $time,
		'range' => sprintf( '%1$s–%2$s', $from, $to ),
		'full'  => $day . ', ' . $time,
	);
}

/**
 * All responses.
 *
 * @param int $post_id Poll ID.
 * @return array[]
 */
function tcmha_poll_responses( $post_id ) {
	$rows = get_post_meta( $post_id, TCMHA_POLL_RESPONSE_KEY, false );
	$rows = array_values( array_filter( $rows, 'is_array' ) );
	usort(
		$rows,
		static function ( $a, $b ) {
			return ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 );
		}
	);
	return $rows;
}

/**
 * Find a response by ID and verify its edit token.
 *
 * @param int    $post_id Poll ID.
 * @param string $rid     Response ID.
 * @param string $token   Plain token.
 * @return array|null
 */
function tcmha_poll_find_response( $post_id, $rid, $token ) {
	if ( '' === $rid || '' === $token ) {
		return null;
	}
	foreach ( tcmha_poll_responses( $post_id ) as $row ) {
		if ( isset( $row['id'], $row['token'] ) && hash_equals( (string) $row['id'], $rid ) && hash_equals( (string) $row['token'], hash( 'sha256', $token ) ) ) {
			return $row;
		}
	}
	return null;
}

/**
 * Count answers for each slot and find the best time(s).
 *
 * @param array   $poll      Poll settings.
 * @param array[] $responses Responses.
 * @return array{slots: array, best: string[], total: int}
 */
function tcmha_poll_tally( $poll, $responses ) {
	$slots = array();
	$top   = -1;
	foreach ( $poll['slots'] as $slot ) {
		$row = array(
			'yes'   => array(),
			'maybe' => array(),
			'no'    => array(),
		);
		foreach ( $responses as $response ) {
			$answer = $response['answers'][ $slot ] ?? '';
			if ( isset( $row[ $answer ] ) ) {
				$row[ $answer ][] = (string) $response['name'];
			}
		}
		$row['score']   = count( $row['yes'] ) * 2 + count( $row['maybe'] );
		$slots[ $slot ] = $row;
		$top            = max( $top, $row['score'] );
	}

	$best = array();
	if ( $top > 0 ) {
		foreach ( $slots as $slot => $row ) {
			if ( $row['score'] === $top ) {
				$best[] = $slot;
			}
		}
	}

	return array(
		'slots' => $slots,
		'best'  => $best,
		'total' => count( $responses ),
	);
}

/**
 * The poll being viewed, if the visitor has the right link (or can edit it).
 *
 * @return array|null Poll settings.
 */
function tcmha_poll_current() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache ? $cache : null;
	}
	$cache = false;

	if ( ! is_singular( 'tcmha_poll' ) ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return null;
	}

	$poll = tcmha_poll_get( $post->ID );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the access check.
	$given = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
	if ( ( '' !== $poll['key'] && '' !== $given && hash_equals( $poll['key'], $given ) ) || current_user_can( 'edit_post', $post->ID ) ) {
		$cache = $poll;
	}

	return $cache ? $cache : null;
}

// Let people with the poll link through the pre-launch visitor gate.
add_filter(
	'tcmha_visitor_gate_allows_request',
	static function ( $allowed ) {
		return $allowed || null !== tcmha_poll_current();
	}
);

// Poll pages are never indexed.
add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( is_singular( 'tcmha_poll' ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

/**
 * Without the link: not found. With it: no caching, then handle .ics downloads and answers.
 */
add_action(
	'template_redirect',
	static function () {
		if ( ! is_singular( 'tcmha_poll' ) ) {
			return;
		}
		$poll = tcmha_poll_current();
		if ( ! $poll ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true ); // The link carries the key.

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download for link holders.
		if ( isset( $_GET['ics'] ) && '' !== $poll['locked'] ) {
			$body = tcmha_poll_ics( $poll );
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_the_title( $poll['id'] ) ) . '.ics"' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped in tcmha_poll_ics().
			exit;
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['tcmha_poll_respond'] ) ) {
			tcmha_poll_handle_response( $poll );
		}
	},
	5
);

/**
 * Save a response from the front-end form, then redirect (so a refresh doesn't resend).
 *
 * @param array $poll Poll settings.
 */
function tcmha_poll_handle_response( $poll ) {
	$rid      = isset( $_POST['tcmha_poll_r'] ) ? sanitize_key( wp_unslash( $_POST['tcmha_poll_r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below before any write.
	$token    = isset( $_POST['tcmha_poll_t'] ) ? sanitize_key( wp_unslash( $_POST['tcmha_poll_t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$existing = tcmha_poll_find_response( $poll['id'], $rid, $token );
	$back     = $existing ? tcmha_poll_personal_url( $poll['id'], $rid, $token ) : tcmha_poll_share_url( $poll['id'] );
	$fail     = static function ( $code ) use ( $back ) {
		wp_safe_redirect( add_query_arg( 'poll_error', $code, $back ) . '#tcmha-poll', 303 );
		exit;
	};

	if ( ! isset( $_POST['tcmha_poll_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_poll_nonce'] ) ), 'tcmha_poll_respond_' . $poll['id'] ) ) {
		$fail( 'expired' );
	}
	if ( tcmha_poll_is_closed( $poll ) ) {
		$fail( 'closed' );
	}
	// Honeypot: people never see this field.
	if ( ! empty( $_POST['tcmha_poll_website'] ) ) {
		wp_safe_redirect( add_query_arg( 'saved', '1', $back ) . '#tcmha-poll', 303 );
		exit;
	}

	// Simple rate limit: 20 saves per hour per visitor per poll.
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate = 'tcmha_poll_rl_' . md5( $poll['id'] . '|' . $ip . '|' . wp_salt( 'nonce' ) );
	$hits = (int) get_transient( $rate );
	if ( $hits >= 20 ) {
		$fail( 'busy' );
	}
	set_transient( $rate, $hits + 1, HOUR_IN_SECONDS );

	$name = isset( $_POST['tcmha_poll_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tcmha_poll_name'] ) ) : '';
	$name = mb_substr( trim( $name ), 0, 80 );
	if ( '' === $name ) {
		$fail( 'name' );
	}
	$email = isset( $_POST['tcmha_poll_email'] ) ? sanitize_email( wp_unslash( $_POST['tcmha_poll_email'] ) ) : '';
	if ( '' !== $email && ! is_email( $email ) ) {
		$fail( 'email' );
	}

	$raw     = isset( $_POST['tcmha_poll_answers'] ) && is_array( $_POST['tcmha_poll_answers'] ) ? map_deep( wp_unslash( $_POST['tcmha_poll_answers'] ), 'sanitize_key' ) : array();
	$answers = array();
	foreach ( $poll['slots'] as $index => $slot ) {
		$answer = $raw[ $index ] ?? '';
		if ( in_array( $answer, TCMHA_POLL_ANSWERS, true ) ) {
			$answers[ $slot ] = $answer;
		}
	}
	if ( ! $answers ) {
		$fail( 'answers' );
	}

	$now = time();

	if ( $existing ) {
		$row = array_merge(
			$existing,
			array(
				'name'    => $name,
				'email'   => $email,
				'answers' => $answers,
				'updated' => $now,
			)
		);
		update_post_meta( $poll['id'], TCMHA_POLL_RESPONSE_KEY, $row, $existing );
	} else {
		$rid   = strtolower( wp_generate_password( 12, false, false ) );
		$token = strtolower( wp_generate_password( 24, false, false ) );
		$row   = array(
			'id'      => $rid,
			'token'   => hash( 'sha256', $token ),
			'name'    => $name,
			'email'   => $email,
			'answers' => $answers,
			'created' => $now,
			'updated' => $now,
		);
		add_post_meta( $poll['id'], TCMHA_POLL_RESPONSE_KEY, $row );
	}

	$personal = tcmha_poll_personal_url( $poll['id'], $rid, $token );
	tcmha_poll_mail_confirmation( $poll, $row, $personal, (bool) $existing );
	tcmha_poll_mail_organizer( $poll, $row, (bool) $existing );

	wp_safe_redirect( add_query_arg( 'saved', '1', $personal ) . '#tcmha-poll', 303 );
	exit;
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! tcmha_poll_current() ) {
			return;
		}
		wp_enqueue_style( 'tcmha-meeting-poll', TCMHA_CORE_URL . 'assets/css/meeting-poll.css', array(), TCMHA_CORE_VERSION );
		wp_add_inline_style(
			'tcmha-meeting-poll',
			'@font-face{font-family:"Lexend";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url( TCMHA_CORE_URL . 'assets/fonts/lexend-latin-variable.woff2' ) . '") format("woff2");}'
		);
		wp_enqueue_script( 'tcmha-meeting-poll', TCMHA_CORE_URL . 'assets/js/meeting-poll.js', array(), TCMHA_CORE_VERSION, array( 'strategy' => 'defer' ) );
	}
);

// Poll pages use their own branded template instead of the theme's single template.
add_filter(
	'template_include',
	static function ( $template ) {
		if ( is_singular( 'tcmha_poll' ) && tcmha_poll_current() ) {
			return TCMHA_CORE_DIR . 'templates/meeting-poll.php';
		}
		return $template;
	},
	99
);

// Fallback: if another template renders the post, show the poll under the intro text.
add_filter(
	'the_content',
	static function ( $content ) {
		if ( ! is_singular( 'tcmha_poll' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$poll = tcmha_poll_current();
		return $poll ? $content . tcmha_poll_render( $poll ) : $content;
	},
	20
);

/**
 * Front-end poll markup.
 *
 * @param array $poll Poll settings.
 * @return string
 */
function tcmha_poll_render( $poll ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only query args.
	$rid   = isset( $_GET['r'] ) ? sanitize_key( wp_unslash( $_GET['r'] ) ) : '';
	$token = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : '';
	$saved = isset( $_GET['saved'] );
	$error = isset( $_GET['poll_error'] ) ? sanitize_key( wp_unslash( $_GET['poll_error'] ) ) : '';
	// phpcs:enable

	$mine      = tcmha_poll_find_response( $poll['id'], $rid, $token );
	$responses = tcmha_poll_responses( $poll['id'] );
	$tally     = tcmha_poll_tally( $poll, $responses );
	$tz        = tcmha_poll_timezone( $poll );
	$errors    = array(
		'expired' => __( 'This page was open a long time, so we could not save. Please choose your times again and press Save.', 'tcmha' ),
		'closed'  => __( 'Answers are closed for this poll.', 'tcmha' ),
		'busy'    => __( 'Too many tries from this device. Please wait an hour and try again.', 'tcmha' ),
		'name'    => __( 'Please type your name.', 'tcmha' ),
		'email'   => __( 'That email address does not look right. Please check it, or leave it blank.', 'tcmha' ),
		'answers' => __( 'Please choose an answer for at least one time.', 'tcmha' ),
	);

	ob_start();
	?>
	<div class="tcmha-poll" id="tcmha-poll" data-tz="<?php echo esc_attr( $tz->getName() ); ?>">
		<?php if ( $saved && $mine ) : ?>
			<div class="tcmha-poll__notice tcmha-poll__notice--ok" role="status" tabindex="-1" data-focus>
				<p><strong><?php echo esc_html( sprintf( /* translators: %s: name */ __( 'Thank you, %s. Your times are saved.', 'tcmha' ), $mine['name'] ) ); ?></strong></p>
				<p><?php esc_html_e( 'This page is your private link. Bookmark it to change your answers later.', 'tcmha' ); ?>
				<?php if ( ! empty( $mine['email'] ) ) : ?>
					<?php esc_html_e( 'We also emailed it to you.', 'tcmha' ); ?>
				<?php endif; ?></p>
			</div>
		<?php elseif ( $error && isset( $errors[ $error ] ) ) : ?>
			<div class="tcmha-poll__notice tcmha-poll__notice--error" role="alert" tabindex="-1" data-focus>
				<p><?php echo esc_html( $errors[ $error ] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $poll['locked'] ) : ?>
			<?php $label = tcmha_poll_slot_label( $poll, $poll['locked'] ); ?>
			<section class="tcmha-poll__picked" aria-labelledby="tcmha-poll-picked">
				<h2 id="tcmha-poll-picked"><?php esc_html_e( 'We picked a time', 'tcmha' ); ?></h2>
				<p class="tcmha-poll__picked-time"><span><?php echo esc_html( $label['day'] ); ?></span> <span><?php echo esc_html( $label['time'] ); ?></span></p>
				<?php tcmha_poll_local_hint( $poll, $poll['locked'] ); ?>
				<p class="tcmha-poll__actions">
					<a class="tcmha-poll__button" href="<?php echo esc_url( add_query_arg( 'ics', '1', tcmha_poll_share_url( $poll['id'] ) ) ); ?>"><?php esc_html_e( 'Add to my calendar', 'tcmha' ); ?></a>
					<?php if ( $poll['link'] ) : ?>
						<a class="tcmha-poll__button tcmha-poll__button--secondary" href="<?php echo esc_url( $poll['link'] ); ?>"><?php esc_html_e( 'Meeting link', 'tcmha' ); ?></a>
					<?php endif; ?>
				</p>
			</section>
		<?php elseif ( tcmha_poll_is_closed( $poll ) ) : ?>
			<section class="tcmha-poll__picked" aria-labelledby="tcmha-poll-closed">
				<h2 id="tcmha-poll-closed"><?php esc_html_e( 'Answers are closed', 'tcmha' ); ?></h2>
				<p><?php esc_html_e( 'Thank you for answering. We will email the final time and a calendar invite to everyone who left an email address.', 'tcmha' ); ?></p>
			</section>
		<?php elseif ( ! $poll['slots'] ) : ?>
			<p><?php esc_html_e( 'Times will be added soon.', 'tcmha' ); ?></p>
		<?php else : ?>
			<form class="tcmha-poll__form" method="post" action="<?php echo esc_url( tcmha_poll_share_url( $poll['id'] ) ); ?>">
				<?php wp_nonce_field( 'tcmha_poll_respond_' . $poll['id'], 'tcmha_poll_nonce', false ); ?>
				<input type="hidden" name="tcmha_poll_respond" value="1">
				<?php if ( $mine ) : ?>
					<input type="hidden" name="tcmha_poll_r" value="<?php echo esc_attr( $rid ); ?>">
					<input type="hidden" name="tcmha_poll_t" value="<?php echo esc_attr( $token ); ?>">
				<?php endif; ?>
				<div class="tcmha-poll__trap" aria-hidden="true">
					<label for="tcmha-poll-website"><?php esc_html_e( 'Leave this empty', 'tcmha' ); ?></label>
					<input type="text" id="tcmha-poll-website" name="tcmha_poll_website" tabindex="-1" autocomplete="off">
				</div>

				<div class="tcmha-poll__who">
					<p class="tcmha-poll__field">
						<label for="tcmha-poll-name"><?php esc_html_e( 'Your name', 'tcmha' ); ?> <span class="tcmha-poll__req"><?php esc_html_e( '(required)', 'tcmha' ); ?></span></label>
						<input type="text" id="tcmha-poll-name" name="tcmha_poll_name" required maxlength="80" autocomplete="name" value="<?php echo esc_attr( $mine['name'] ?? '' ); ?>">
					</p>
					<p class="tcmha-poll__field">
						<label for="tcmha-poll-email"><?php esc_html_e( 'Your email', 'tcmha' ); ?> <span class="tcmha-poll__req"><?php esc_html_e( '(optional)', 'tcmha' ); ?></span></label>
						<input type="email" id="tcmha-poll-email" name="tcmha_poll_email" maxlength="190" autocomplete="email" aria-describedby="tcmha-poll-email-hint" value="<?php echo esc_attr( $mine['email'] ?? '' ); ?>">
						<span class="tcmha-poll__hint" id="tcmha-poll-email-hint"><?php esc_html_e( 'We only use it to send you the meeting invite.', 'tcmha' ); ?></span>
					</p>
				</div>

				<h2 class="tcmha-poll__heading"><?php esc_html_e( 'When can you join?', 'tcmha' ); ?></h2>
				<p class="tcmha-poll__lead">
					<?php esc_html_e( 'Pick one answer for each time. All times are Mountain Time (Colorado).', 'tcmha' ); ?>
					<?php if ( '' !== $poll['deadline'] ) : ?>
						<strong><?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Please answer by %s.', 'tcmha' ), tcmha_poll_deadline_label( $poll ) ) ); ?></strong>
					<?php endif; ?>
					<?php if ( $poll['show_names'] ) : ?>
						<?php esc_html_e( 'Others with this link can see your name and answers.', 'tcmha' ); ?>
					<?php endif; ?>
				</p>

				<?php
				$choices = array(
					'yes'   => __( 'Works', 'tcmha' ),
					'maybe' => __( 'If needed', 'tcmha' ),
					'no'    => __( "Can't", 'tcmha' ),
				);
				$days    = array();
				foreach ( $poll['slots'] as $index => $slot ) {
					$days[ substr( $slot, 0, 10 ) ][ $index ] = $slot;
				}
				?>
				<?php foreach ( $days as $day_slots ) : ?>
					<?php $day_label = tcmha_poll_slot_label( $poll, reset( $day_slots ) )['day']; ?>
					<section class="tcmha-poll__day-group">
						<h3 class="tcmha-poll__day"><?php echo esc_html( $day_label ); ?></h3>
						<?php foreach ( $day_slots as $index => $slot ) : ?>
							<?php
							$label   = tcmha_poll_slot_label( $poll, $slot );
							$row     = $tally['slots'][ $slot ];
							$current = $mine['answers'][ $slot ] ?? '';
							$is_best = in_array( $slot, $tally['best'], true );
							?>
							<fieldset class="tcmha-poll__slot<?php echo $is_best ? ' is-best' : ''; ?>">
								<legend class="tcmha-poll__time">
									<span class="tcmha-poll__sr"><?php echo esc_html( $day_label ); ?>, </span><?php echo esc_html( $label['range'] ); ?>
								</legend>
								<div class="tcmha-poll__slot-main">
									<div class="tcmha-poll__choices">
										<?php foreach ( $choices as $value => $text ) : ?>
											<label class="tcmha-poll__choice tcmha-poll__choice--<?php echo esc_attr( $value ); ?>">
												<input type="radio" name="tcmha_poll_answers[<?php echo (int) $index; ?>]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> <?php echo 'yes' === $value ? 'required' : ''; ?>>
												<span><?php echo esc_html( $text ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<div class="tcmha-poll__slot-when">
										<?php tcmha_poll_local_hint( $poll, $slot ); ?>
										<?php tcmha_poll_render_tally( $poll, $row, $tally['total'], $is_best ); ?>
									</div>
								</div>
							</fieldset>
						<?php endforeach; ?>
					</section>
				<?php endforeach; ?>

				<p class="tcmha-poll__submit">
					<button type="submit" class="tcmha-poll__button"><?php echo $mine ? esc_html__( 'Update my times', 'tcmha' ) : esc_html__( 'Save my times', 'tcmha' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<p class="tcmha-poll__count" aria-live="polite">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of people */
					_n( '%d person has answered so far.', '%d people have answered so far.', $tally['total'], 'tcmha' ),
					$tally['total']
				)
			);
			?>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Placeholder the script fills with the visitor's own time when they are outside the poll's time zone.
 *
 * @param array  $poll Poll settings.
 * @param string $slot Slot string.
 */
function tcmha_poll_local_hint( $poll, $slot ) {
	list( $start, $end ) = tcmha_poll_slot_range( $poll, $slot );
	printf(
		'<p class="tcmha-poll__local" data-start="%1$s" data-end="%2$s" hidden></p>',
		esc_attr( $start->format( DATE_ATOM ) ),
		esc_attr( $end->format( DATE_ATOM ) )
	);
}

/**
 * Answer counts for one time, as text plus a decorative bar.
 *
 * @param array $poll    Poll settings.
 * @param array $row     Tally row.
 * @param int   $total   Number of responses.
 * @param bool  $is_best Whether this is a top time.
 */
function tcmha_poll_render_tally( $poll, $row, $total, $is_best ) {
	if ( $total < 1 ) {
		return;
	}
	$yes   = count( $row['yes'] );
	$maybe = count( $row['maybe'] );
	?>
	<div class="tcmha-poll__tally">
		<div class="tcmha-poll__bar" aria-hidden="true">
			<span class="tcmha-poll__bar-yes" style="width: <?php echo esc_attr( round( 100 * $yes / $total, 2 ) ); ?>%"></span><span class="tcmha-poll__bar-maybe" style="width: <?php echo esc_attr( round( 100 * $maybe / $total, 2 ) ); ?>%"></span>
		</div>
		<p>
			<?php if ( $is_best ) : ?>
				<strong class="tcmha-poll__badge"><?php esc_html_e( 'Best time so far', 'tcmha' ); ?></strong>
			<?php endif; ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: people who can, 2: people who can if needed */
					__( '%1$d can, %2$d if needed', 'tcmha' ),
					$yes,
					$maybe
				)
			);
			?>
		</p>
		<?php if ( $poll['show_names'] && ( $yes || $maybe ) ) : ?>
			<p class="tcmha-poll__names">
				<?php
				$names = $row['yes'];
				foreach ( $row['maybe'] as $name ) {
					/* translators: %s: name of someone who can come if needed */
					$names[] = sprintf( __( '%s (if needed)', 'tcmha' ), $name );
				}
				echo esc_html( implode( ', ', $names ) );
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Escape text for an iCalendar property value.
 *
 * @param string $text Text.
 * @return string
 */
function tcmha_poll_ics_escape( $text ) {
	return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n' ), (string) $text );
}

/**
 * The chosen time as an iCalendar file (UTC times, works in every calendar app).
 *
 * @param array $poll Poll settings.
 * @return string
 */
function tcmha_poll_ics( $poll ) {
	list( $start, $end ) = tcmha_poll_slot_range( $poll, $poll['locked'] );
	$utc                 = new DateTimeZone( 'UTC' );
	$host                = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$title               = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$description         = $poll['link'] ? sprintf( 'Join: %s', $poll['link'] ) : '';

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//TCMHA//Meeting poll//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'BEGIN:VEVENT',
		'UID:tcmha-poll-' . $poll['id'] . '-' . $start->format( 'YmdHi' ) . '@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
		'DTEND:' . $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
		'SUMMARY:' . tcmha_poll_ics_escape( $title ),
	);
	if ( $poll['link'] ) {
		$lines[] = 'LOCATION:' . tcmha_poll_ics_escape( $poll['link'] );
		$lines[] = 'URL:' . tcmha_poll_ics_escape( $poll['link'] );
	}
	if ( $description ) {
		$lines[] = 'DESCRIPTION:' . tcmha_poll_ics_escape( $description );
	}
	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';

	// Fold lines longer than 75 bytes (RFC 5545).
	$folded = array();
	foreach ( $lines as $line ) {
		while ( strlen( $line ) > 75 ) {
			$cut      = mb_strcut( $line, 0, 75, 'UTF-8' );
			$folded[] = $cut;
			$line     = ' ' . substr( $line, strlen( $cut ) );
		}
		$folded[] = $line;
	}

	return implode( "\r\n", $folded ) . "\r\n";
}

/**
 * Reply-To header so replies from people go to the poll's "Send responses to" addresses.
 *
 * @param array $poll Poll settings.
 * @return string[]
 */
function tcmha_poll_mail_headers( $poll ) {
	return $poll['send_to'] ? array( 'Reply-To: ' . implode( ', ', $poll['send_to'] ) ) : array();
}

/**
 * Sign-off used in poll emails.
 *
 * @return string
 */
function tcmha_poll_mail_signoff() {
	return "\n\n" . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . "\n" . home_url( '/' );
}

/**
 * Email a respondent their private link.
 *
 * @param array  $poll     Poll settings.
 * @param array  $row      Response.
 * @param string $personal Private link.
 * @param bool   $updated  Whether this was a change.
 */
function tcmha_poll_mail_confirmation( $poll, $row, $personal, $updated ) {
	if ( empty( $row['email'] ) ) {
		return;
	}
	$title = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	/* translators: %s: poll title */
	$subject = sprintf( $updated ? __( 'Updated: your times for %s', 'tcmha' ) : __( 'Thanks: we got your times for %s', 'tcmha' ), $title );
	$body    = sprintf(
		/* translators: 1: name, 2: private link */
		__( "Hi %1\$s,\n\nThanks for sharing when you can meet. You can change your answers any time here:\n%2\$s\n\nWhen we pick a time, we will email you a calendar invite.", 'tcmha' ),
		$row['name'],
		$personal
	) . tcmha_poll_mail_signoff();

	wp_mail( $row['email'], $subject, $body, tcmha_poll_mail_headers( $poll ) );
}

/**
 * Send each response to the poll's "Send responses to" addresses.
 *
 * @param array $poll    Poll settings.
 * @param array $row     Response.
 * @param bool  $updated Whether this was a change.
 */
function tcmha_poll_mail_organizer( $poll, $row, $updated ) {
	if ( ! $poll['send_to'] ) {
		return;
	}
	$title = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$tally = tcmha_poll_tally( $poll, tcmha_poll_responses( $poll['id'] ) );

	/* translators: 1: name, 2: poll title */
	$subject = sprintf( $updated ? __( '%1$s changed their times: %2$s', 'tcmha' ) : __( '%1$s answered: %2$s', 'tcmha' ), $row['name'], $title );

	$lines = array( $row['name'] . ( ! empty( $row['email'] ) ? ' (' . $row['email'] . ')' : '' ) );
	$groups = array(
		'yes'   => __( 'WORKS', 'tcmha' ),
		'maybe' => __( 'IF NEEDED', 'tcmha' ),
		'no'    => __( "CAN'T", 'tcmha' ),
	);
	foreach ( $groups as $answer => $heading ) {
		$slots = array_keys( array_intersect( $row['answers'], array( $answer ) ) );
		$lines[] = '';
		$lines[] = $heading;
		$lines   = array_merge( $lines, $slots ? tcmha_poll_mail_day_lines( $poll, $slots ) : array( '  ' . __( 'None', 'tcmha' ) ) );
	}

	$lines[] = '';
	$lines[] = __( 'All times are Mountain Time.', 'tcmha' );
	$lines[] = '';
	/* translators: %d: number of answers */
	$lines[] = sprintf( _n( 'So far: %d answer.', 'So far: %d answers.', $tally['total'], 'tcmha' ), $tally['total'] );
	if ( $tally['best'] ) {
		$lines[] = _n( 'Best time so far:', 'Best times so far (tied):', count( $tally['best'] ), 'tcmha' );
		$lines   = array_merge( $lines, tcmha_poll_mail_day_lines( $poll, $tally['best'] ) );
	}
	$lines[] = '';
	$lines[] = __( 'See all answers (sign in first):', 'tcmha' );
	$lines[] = admin_url( 'post.php?post=' . $poll['id'] . '&action=edit' );

	$headers = ! empty( $row['email'] ) ? array( 'Reply-To: ' . $row['email'] ) : array();
	wp_mail( $poll['send_to'], $subject, implode( "\n", $lines ) . tcmha_poll_mail_signoff(), $headers );
}

/**
 * Slots as one line per day, in order: "  Fri, Dec 11: 9:00–10:00 AM, 3:00–4:00 PM".
 *
 * @param array    $poll  Poll settings.
 * @param string[] $slots Slot strings.
 * @return string[]
 */
function tcmha_poll_mail_day_lines( $poll, $slots ) {
	sort( $slots );
	$days = array();
	foreach ( $slots as $slot ) {
		list( $start ) = tcmha_poll_slot_range( $poll, $slot );
		$day           = wp_date( 'D, M j', $start->getTimestamp(), tcmha_poll_timezone( $poll ) );
		$days[ $day ][] = tcmha_poll_slot_label( $poll, $slot )['range'];
	}
	$lines = array();
	foreach ( $days as $day => $ranges ) {
		$lines[] = '  ' . $day . ': ' . implode( ', ', $ranges );
	}
	return $lines;
}

/**
 * Email the chosen time and a calendar invite to everyone who left an address.
 * Only called when an editor presses the button in WP admin.
 *
 * @param array $poll Poll settings (must be locked).
 * @return array{sent: int, failed: int}
 */
function tcmha_poll_send_invites( $poll ) {
	$result = array(
		'sent'   => 0,
		'failed' => 0,
	);
	if ( '' === $poll['locked'] ) {
		return $result;
	}

	$title   = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$label   = tcmha_poll_slot_label( $poll, $poll['locked'] );
	$dir     = trailingslashit( get_temp_dir() ) . 'tcmha-poll-' . wp_generate_password( 8, false, false );
	$file    = $dir . '/invite.ics';
	$attach  = array();
	if ( wp_mkdir_p( $dir ) && false !== file_put_contents( $file, tcmha_poll_ics( $poll ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$attach = array( 'invite.ics' => $file );
	}

	$seen = array();
	foreach ( tcmha_poll_responses( $poll['id'] ) as $row ) {
		$email = strtolower( (string) ( $row['email'] ?? '' ) );
		if ( ! is_email( $email ) || isset( $seen[ $email ] ) ) {
			continue;
		}
		$seen[ $email ] = true;

		/* translators: 1: poll title, 2: date and time */
		$subject = sprintf( __( '%1$s: %2$s', 'tcmha' ), $title, $label['full'] );
		$body    = sprintf(
			/* translators: 1: name, 2: date and time */
			__( "Hi %1\$s,\n\nThank you for sharing when you can meet. We picked:\n\n%2\$s", 'tcmha' ),
			$row['name'],
			$label['full']
		);
		if ( $poll['link'] ) {
			/* translators: %s: meeting link */
			$body .= sprintf( __( "\n\nJoin here:\n%s", 'tcmha' ), $poll['link'] );
		}
		$body .= "\n\n" . __( 'A calendar invite is attached. You can also add it to your calendar here:', 'tcmha' ) . "\n" . add_query_arg( 'ics', '1', tcmha_poll_share_url( $poll['id'] ) );
		$body .= tcmha_poll_mail_signoff();

		if ( wp_mail( $email, $subject, $body, tcmha_poll_mail_headers( $poll ), $attach ) ) {
			++$result['sent'];
		} else {
			++$result['failed'];
		}
	}

	if ( $attach ) {
		wp_delete_file( $file );
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	return $result;
}

/**
 * Daily: delete responses 30 days after the chosen meeting time.
 */
function tcmha_poll_purge_old_responses() {
	$polls = get_posts(
		array(
			'post_type'      => 'tcmha_poll',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		)
	);
	foreach ( $polls as $post_id ) {
		$poll = tcmha_poll_get( $post_id );
		if ( '' === $poll['locked'] ) {
			continue;
		}
		list( , $end ) = tcmha_poll_slot_range( $poll, $poll['locked'] );
		if ( $end->getTimestamp() + TCMHA_POLL_PURGE_DAYS * DAY_IN_SECONDS < time() ) {
			delete_post_meta( $post_id, TCMHA_POLL_RESPONSE_KEY );
		}
	}
}
add_action( 'tcmha_poll_purge', 'tcmha_poll_purge_old_responses' );

add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'tcmha_poll_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tcmha_poll_purge' );
		}
	}
);

// Flush rewrite rules once after this feature first loads, so /meet/ links work without a manual step.
add_action(
	'init',
	static function () {
		if ( get_option( 'tcmha_poll_rewrite_version' ) !== TCMHA_CORE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'tcmha_poll_rewrite_version', TCMHA_CORE_VERSION, false );
		}
	},
	99
);
