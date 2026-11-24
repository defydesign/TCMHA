<?php
/**
 * Polls ("general polls"): several questions per poll, with pictures and files on questions and choices.
 * Example: a logo design poll where each choice is a picture.
 *
 * - Each poll is a `tcmha_survey` post at /poll/<slug>/. "Only people with the link" polls open with ?k=KEY.
 *   "Anyone" polls open for everyone (after launch; the pre-launch visitor gate still applies).
 * - Question types: one choice, several choices (optional limit), written answer.
 * - People answer without an account. Name and email are optional or off, per poll.
 * - Each person gets a private link to change their answers. Nothing is stored in cookies.
 * - Results show after answering, after the poll closes, or only to organizers.
 * - At the deadline, answers close and the results are emailed to the "Send responses to" addresses.
 * - Names and emails are removed 30 days after the poll closes. Answers are kept without them.
 * - [tcmha_poll id="123"] shows a poll inside any page (a simple embed until the site's embed approach is set).
 *
 * Never add fields that invite health information.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

const TCMHA_SURVEY_RESPONSE_KEY = '_tcmha_survey_response';
const TCMHA_SURVEY_TYPES        = array( 'single', 'multiple', 'text' );
const TCMHA_SURVEY_SCRUB_DAYS   = 30;
const TCMHA_SURVEY_TEXT_MAX     = 1000;
const TCMHA_SURVEY_MAX_ITEMS    = 30;
const TCMHA_SURVEY_QUERY_ARGS   = array( 'poll_id', 'poll_r', 'poll_t', 'poll_saved', 'poll_error', 'poll_draft' );

/**
 * Register the poll post type.
 */
function tcmha_survey_register() {
	register_post_type(
		'tcmha_survey',
		array(
			'labels'              => array(
				'name'          => __( 'Polls', 'tcmha' ),
				'singular_name' => __( 'Poll', 'tcmha' ),
				'add_new'       => __( 'Add poll', 'tcmha' ),
				'add_new_item'  => __( 'Add poll', 'tcmha' ),
				'edit_item'     => __( 'Edit poll', 'tcmha' ),
				'new_item'      => __( 'New poll', 'tcmha' ),
				'view_item'     => __( 'View poll', 'tcmha' ),
				'all_items'     => __( 'All polls', 'tcmha' ),
				'search_items'  => __( 'Search polls', 'tcmha' ),
				'not_found'     => __( 'No polls yet.', 'tcmha' ),
				'menu_name'     => __( 'Polls', 'tcmha' ),
			),
			'public'              => false,
			'publicly_queryable'  => true,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false, // Keeps the key and answers out of /wp/v2. See the tcmha/v1/surveys routes.
			'menu_icon'           => 'dashicons-chart-bar',
			'supports'            => array( 'title', 'editor' ),
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => 'poll',
				'with_front' => false,
			),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'tcmha_survey_register' );

/**
 * Default settings.
 *
 * @return array
 */
function tcmha_survey_defaults() {
	return array(
		'questions'   => array(),     // See tcmha_survey_clean_questions().
		'files'       => array(),     // Attachment IDs everyone sees above the questions.
		'access'      => 'link',      // 'link' (only people with the link) or 'public'.
		'ask_name'    => 'optional',  // 'required', 'optional', or 'none'.
		'ask_email'   => true,        // Optional for people; used only to send their private link.
		'results'     => 'after',     // 'after' answering, after the poll is 'closed', or 'private' (organizers only).
		'deadline'    => '',          // 'Y-m-d H:i' in the poll's time zone.
		'tz'          => 'America/Denver',
		'send_to'     => array(),     // Organizer emails: each answer (if notify_each) and the final results.
		'notify_each' => true,
		'closed'      => false,       // Closed by hand.
		'key'         => '',
	);
}

/**
 * Settings for a poll.
 *
 * @param int $post_id Poll ID.
 * @return array
 */
function tcmha_survey_get( $post_id ) {
	$saved = get_post_meta( $post_id, '_tcmha_survey_settings', true );
	$poll  = wp_parse_args( is_array( $saved ) ? $saved : array(), tcmha_survey_defaults() );

	$poll['id']  = (int) $post_id;
	$poll['key'] = (string) get_post_meta( $post_id, '_tcmha_survey_key', true );

	return $poll;
}

/**
 * The poll's time zone.
 *
 * @param array $poll Poll settings.
 * @return DateTimeZone
 */
function tcmha_survey_timezone( $poll ) {
	try {
		return new DateTimeZone( $poll['tz'] );
	} catch ( Exception $e ) {
		return new DateTimeZone( 'America/Denver' );
	}
}

/**
 * A clean, unique ID for a question or choice. Keeps a good existing ID so answers stay attached.
 *
 * @param mixed  $raw    Proposed ID.
 * @param string $prefix 'q' or 'o'.
 * @param array  $used   IDs already taken (as keys).
 * @return string
 */
function tcmha_survey_clean_id( $raw, $prefix, $used ) {
	$id = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
	if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,23}$/', $id ) || isset( $used[ $id ] ) ) {
		do {
			$id = $prefix . strtolower( wp_generate_password( 8, false, false ) );
		} while ( isset( $used[ $id ] ) );
	}
	return $id;
}

/**
 * A valid attachment ID, or 0.
 *
 * @param mixed $id         Proposed ID.
 * @param bool  $image_only Only accept images.
 * @return int
 */
function tcmha_survey_clean_attachment( $id, $image_only = false ) {
	$id = absint( $id );
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return 0;
	}
	if ( $image_only && ! wp_attachment_is_image( $id ) ) {
		return 0;
	}
	return $id;
}

/**
 * Sanitize questions. Accepts a list or a map keyed by ID (the admin form posts a map).
 *
 * Each question: id, type (single|multiple|text), text, help, required, max (multiple only; 0 = no limit),
 * image, file, options[] (id, label, image, file). Questions without text are dropped. Choices without
 * a label or picture are dropped.
 *
 * @param mixed $raw Raw questions.
 * @return array[]
 */
function tcmha_survey_clean_questions( $raw ) {
	$questions = array();
	$used      = array();
	if ( ! is_array( $raw ) ) {
		return $questions;
	}
	foreach ( $raw as $key => $q ) {
		if ( ! is_array( $q ) || count( $questions ) >= TCMHA_SURVEY_MAX_ITEMS ) {
			continue;
		}
		$text = mb_substr( trim( sanitize_text_field( (string) ( $q['text'] ?? '' ) ) ), 0, 300 );
		if ( '' === $text ) {
			continue;
		}
		$id          = tcmha_survey_clean_id( $q['id'] ?? ( is_string( $key ) ? $key : '' ), 'q', $used );
		$used[ $id ] = true;
		$type        = in_array( $q['type'] ?? '', TCMHA_SURVEY_TYPES, true ) ? $q['type'] : 'single';

		$options = array();
		$oused   = array();
		if ( 'text' !== $type && isset( $q['options'] ) && is_array( $q['options'] ) ) {
			foreach ( $q['options'] as $okey => $o ) {
				if ( ! is_array( $o ) || count( $options ) >= TCMHA_SURVEY_MAX_ITEMS ) {
					continue;
				}
				$label = mb_substr( trim( sanitize_text_field( (string) ( $o['label'] ?? '' ) ) ), 0, 200 );
				$image = tcmha_survey_clean_attachment( $o['image'] ?? 0, true );
				if ( '' === $label && ! $image ) {
					continue;
				}
				$oid           = tcmha_survey_clean_id( $o['id'] ?? ( is_string( $okey ) ? $okey : '' ), 'o', $oused );
				$oused[ $oid ] = true;
				if ( '' === $label ) {
					/* translators: %d: choice number */
					$label = sprintf( __( 'Choice %d', 'tcmha' ), count( $options ) + 1 );
				}
				$options[] = array(
					'id'    => $oid,
					'label' => $label,
					'image' => $image,
					'file'  => tcmha_survey_clean_attachment( $o['file'] ?? 0 ),
				);
			}
		}

		$max = 'multiple' === $type ? absint( $q['max'] ?? 0 ) : 0;
		if ( $max >= count( $options ) ) {
			$max = 0;
		}

		$questions[] = array(
			'id'       => $id,
			'type'     => $type,
			'text'     => $text,
			'help'     => mb_substr( trim( sanitize_textarea_field( (string) ( $q['help'] ?? '' ) ) ), 0, 600 ),
			'required' => isset( $q['required'] ) && rest_sanitize_boolean( $q['required'] ),
			'max'      => $max,
			'image'    => tcmha_survey_clean_attachment( $q['image'] ?? 0, true ),
			'file'     => tcmha_survey_clean_attachment( $q['file'] ?? 0 ),
			'options'  => $options,
		);
	}
	return $questions;
}

/**
 * Sanitize and save settings. Unknown keys are ignored. Returns the saved settings.
 *
 * @param int   $post_id Poll ID.
 * @param array $input   Raw settings (any subset of tcmha_survey_defaults() keys, except key).
 * @return array
 */
function tcmha_survey_save_settings( $post_id, $input ) {
	$poll = tcmha_survey_get( $post_id );

	if ( isset( $input['tz'] ) && in_array( $input['tz'], timezone_identifiers_list(), true ) ) {
		$poll['tz'] = $input['tz'];
	}
	if ( array_key_exists( 'questions', $input ) ) {
		$poll['questions'] = tcmha_survey_clean_questions( $input['questions'] );
	}
	if ( array_key_exists( 'files', $input ) ) {
		$poll['files'] = array();
		foreach ( (array) $input['files'] as $file ) {
			$file = tcmha_survey_clean_attachment( $file );
			if ( $file && ! in_array( $file, $poll['files'], true ) && count( $poll['files'] ) < 10 ) {
				$poll['files'][] = $file;
			}
		}
	}
	if ( isset( $input['access'] ) && in_array( $input['access'], array( 'link', 'public' ), true ) ) {
		$poll['access'] = $input['access'];
	}
	if ( isset( $input['ask_name'] ) && in_array( $input['ask_name'], array( 'required', 'optional', 'none' ), true ) ) {
		$poll['ask_name'] = $input['ask_name'];
	}
	if ( isset( $input['results'] ) && in_array( $input['results'], array( 'after', 'closed', 'private' ), true ) ) {
		$poll['results'] = $input['results'];
	}
	foreach ( array( 'ask_email', 'notify_each' ) as $flag ) {
		if ( array_key_exists( $flag, $input ) ) {
			$poll[ $flag ] = rest_sanitize_boolean( $input[ $flag ] );
		}
	}
	if ( array_key_exists( 'closed', $input ) ) {
		tcmha_survey_set_closed( $poll, rest_sanitize_boolean( $input['closed'] ) );
	}
	if ( isset( $input['send_to'] ) ) {
		$list            = is_array( $input['send_to'] ) ? $input['send_to'] : preg_split( '/[\s,;]+/', (string) $input['send_to'] );
		$poll['send_to'] = array();
		foreach ( $list as $email ) {
			$email = strtolower( sanitize_email( (string) $email ) );
			if ( is_email( $email ) && ! in_array( $email, $poll['send_to'], true ) && count( $poll['send_to'] ) < 10 ) {
				$poll['send_to'][] = $email;
			}
		}
	}
	if ( isset( $input['deadline'] ) ) {
		$raw              = str_replace( 'T', ' ', trim( sanitize_text_field( (string) $input['deadline'] ) ) );
		$date             = '' === $raw ? false : DateTimeImmutable::createFromFormat( '!Y-m-d H:i', substr( $raw, 0, 16 ), tcmha_survey_timezone( $poll ) );
		$poll['deadline'] = $date ? $date->format( 'Y-m-d H:i' ) : '';
		if ( $poll['deadline'] !== (string) get_post_meta( $post_id, '_tcmha_survey_results_for', true ) ) {
			delete_post_meta( $post_id, '_tcmha_survey_results_sent' ); // A new deadline gets a new results email.
		}
	}

	tcmha_survey_store( $post_id, $poll );
	tcmha_survey_ensure_key( $post_id );

	return tcmha_survey_get( $post_id );
}

/**
 * Close or reopen by hand (records when it closed, for removing names later). Does not store.
 *
 * @param array $poll   Poll settings, changed in place.
 * @param bool  $closed Closed or not.
 */
function tcmha_survey_set_closed( &$poll, $closed ) {
	if ( $closed && ! $poll['closed'] ) {
		update_post_meta( $poll['id'], '_tcmha_survey_closed_at', time() );
	} elseif ( ! $closed ) {
		delete_post_meta( $poll['id'], '_tcmha_survey_closed_at' );
	}
	$poll['closed'] = $closed;
}

/**
 * Write settings (without the key, which has its own meta).
 *
 * @param int   $post_id Poll ID.
 * @param array $poll    Settings.
 */
function tcmha_survey_store( $post_id, $poll ) {
	$keep = array_intersect_key( $poll, tcmha_survey_defaults() );
	unset( $keep['key'] );
	update_post_meta( $post_id, '_tcmha_survey_settings', $keep );
}

/**
 * Make sure the poll has a secret link key.
 *
 * @param int $post_id Poll ID.
 * @return string
 */
function tcmha_survey_ensure_key( $post_id ) {
	$key = (string) get_post_meta( $post_id, '_tcmha_survey_key', true );
	if ( '' === $key ) {
		$key = strtolower( wp_generate_password( 16, false, false ) );
		update_post_meta( $post_id, '_tcmha_survey_key', $key );
	}
	return $key;
}

/**
 * The shareable link (with the key, so it works for both access settings).
 *
 * @param int $post_id Poll ID.
 * @return string
 */
function tcmha_survey_share_url( $post_id ) {
	return add_query_arg( 'k', rawurlencode( tcmha_survey_ensure_key( $post_id ) ), get_permalink( $post_id ) );
}

/**
 * A person's private link to change their answers.
 *
 * @param int    $post_id Poll ID.
 * @param string $rid     Response ID.
 * @param string $token   Plain edit token.
 * @param string $base    Page the link opens (defaults to the poll's own page).
 * @return string
 */
function tcmha_survey_personal_url( $post_id, $rid, $token, $base = '' ) {
	return add_query_arg(
		array(
			'poll_id' => (int) $post_id,
			'poll_r'  => rawurlencode( $rid ),
			'poll_t'  => rawurlencode( $token ),
		),
		$base ? $base : tcmha_survey_share_url( $post_id )
	);
}

/**
 * Whether the deadline has passed.
 *
 * @param array $poll Poll settings.
 * @return bool
 */
function tcmha_survey_deadline_passed( $poll ) {
	if ( '' === $poll['deadline'] ) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $poll['deadline'], tcmha_survey_timezone( $poll ) );
	return $date && $date->getTimestamp() <= time();
}

/**
 * Whether the poll no longer takes answers.
 *
 * @param array $poll Poll settings.
 * @return bool
 */
function tcmha_survey_is_closed( $poll ) {
	return $poll['closed'] || tcmha_survey_deadline_passed( $poll );
}

/**
 * When the poll closed (Unix time), or 0 if it is open.
 *
 * @param array $poll Poll settings.
 * @return int
 */
function tcmha_survey_closed_at( $poll ) {
	$times = array();
	if ( $poll['closed'] ) {
		$times[] = (int) get_post_meta( $poll['id'], '_tcmha_survey_closed_at', true );
	}
	if ( tcmha_survey_deadline_passed( $poll ) ) {
		$times[] = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $poll['deadline'], tcmha_survey_timezone( $poll ) )->getTimestamp();
	}
	$times = array_filter( $times );
	return $times ? min( $times ) : 0;
}

/**
 * The deadline in plain words, e.g. "Monday, December 7 at 5:00 PM MT".
 *
 * @param array $poll Poll settings.
 * @return string
 */
function tcmha_survey_deadline_label( $poll ) {
	$tz   = tcmha_survey_timezone( $poll );
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $poll['deadline'], $tz );
	if ( ! $date ) {
		return '';
	}
	$zone = 'America/Denver' === $tz->getName() ? 'MT' : $date->format( 'T' );
	/* translators: 1: date, 2: time, 3: time zone */
	return sprintf( __( '%1$s at %2$s %3$s', 'tcmha' ), wp_date( 'l, F j', $date->getTimestamp(), $tz ), wp_date( 'g:i A', $date->getTimestamp(), $tz ), $zone );
}

/**
 * All responses, oldest first.
 *
 * @param int $post_id Poll ID.
 * @return array[]
 */
function tcmha_survey_responses( $post_id ) {
	$rows = array_values( array_filter( get_post_meta( $post_id, TCMHA_SURVEY_RESPONSE_KEY, false ), 'is_array' ) );
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
function tcmha_survey_find_response( $post_id, $rid, $token ) {
	if ( '' === $rid || '' === $token ) {
		return null;
	}
	foreach ( tcmha_survey_responses( $post_id ) as $row ) {
		if ( isset( $row['id'], $row['token'] ) && hash_equals( (string) $row['id'], $rid ) && hash_equals( (string) $row['token'], hash( 'sha256', $token ) ) ) {
			return $row;
		}
	}
	return null;
}

/**
 * A question by ID.
 *
 * @param array  $poll Poll settings.
 * @param string $qid  Question ID.
 * @return array|null
 */
function tcmha_survey_question( $poll, $qid ) {
	foreach ( $poll['questions'] as $q ) {
		if ( $q['id'] === $qid ) {
			return $q;
		}
	}
	return null;
}

/**
 * Choice labels for an answer, in the question's order.
 *
 * @param array        $q      Question.
 * @param string|array $answer Choice ID(s).
 * @return string[]
 */
function tcmha_survey_answer_labels( $q, $answer ) {
	$picked = (array) $answer;
	$labels = array();
	foreach ( $q['options'] as $o ) {
		if ( in_array( $o['id'], $picked, true ) ) {
			$labels[] = $o['label'];
		}
	}
	return $labels;
}

/**
 * Count answers per question.
 *
 * @param array   $poll      Poll settings.
 * @param array[] $responses Responses.
 * @return array{total: int, questions: array}
 *   questions[qid] = answered (int), options[oid] (count), names[oid] (string[]), top (oid[]), texts (array{name, text}[]).
 */
function tcmha_survey_tally( $poll, $responses ) {
	$out = array(
		'total'     => count( $responses ),
		'questions' => array(),
	);
	foreach ( $poll['questions'] as $q ) {
		$row = array(
			'answered' => 0,
			'options'  => array(),
			'names'    => array(),
			'top'      => array(),
			'texts'    => array(),
		);
		foreach ( $q['options'] as $o ) {
			$row['options'][ $o['id'] ] = 0;
			$row['names'][ $o['id'] ]   = array();
		}
		foreach ( $responses as $response ) {
			$answer = $response['answers'][ $q['id'] ] ?? null;
			$name   = (string) ( $response['name'] ?? '' );
			if ( 'text' === $q['type'] ) {
				if ( is_string( $answer ) && '' !== $answer ) {
					++$row['answered'];
					$row['texts'][] = array(
						'name' => $name,
						'text' => $answer,
					);
				}
				continue;
			}
			$picked = array_intersect( array_keys( $row['options'] ), (array) $answer );
			if ( ! $picked ) {
				continue;
			}
			++$row['answered'];
			foreach ( $picked as $oid ) {
				++$row['options'][ $oid ];
				$row['names'][ $oid ][] = $name;
			}
		}
		$top = $row['options'] ? max( $row['options'] ) : 0;
		if ( $top > 0 ) {
			$row['top'] = array_keys( $row['options'], $top, true );
		}
		$out['questions'][ $q['id'] ] = $row;
	}
	return $out;
}

/**
 * Whether results show on the poll page.
 *
 * @param array $poll     Poll settings.
 * @param bool  $answered Whether the visitor has answered (has their private link).
 * @return bool
 */
function tcmha_survey_results_visible( $poll, $answered ) {
	if ( 'after' === $poll['results'] ) {
		return $answered || tcmha_survey_is_closed( $poll );
	}
	if ( 'closed' === $poll['results'] ) {
		return tcmha_survey_is_closed( $poll );
	}
	return false;
}

/**
 * Whether the visitor may open a poll.
 *
 * @param array  $poll  Poll settings.
 * @param string $given Key from the link.
 * @return bool
 */
function tcmha_survey_can_open( $poll, $given ) {
	if ( current_user_can( 'edit_post', $poll['id'] ) ) {
		return true; // Editors can preview drafts and private polls.
	}
	if ( 'publish' !== get_post_status( $poll['id'] ) ) {
		return false;
	}
	return 'public' === $poll['access'] || ( '' !== $poll['key'] && '' !== $given && hash_equals( $poll['key'], $given ) );
}

/**
 * The poll on its own page, if the visitor may open it.
 *
 * @return array|null
 */
function tcmha_survey_current() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache ? $cache : null;
	}
	$cache = false;
	if ( ! is_singular( 'tcmha_survey' ) ) {
		return null;
	}
	$poll = tcmha_survey_get( get_queried_object_id() );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the access check.
	$given = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
	if ( tcmha_survey_can_open( $poll, $given ) ) {
		$cache = $poll;
	}
	return $cache ? $cache : null;
}

// Let people who may open the poll through the pre-launch visitor gate.
add_filter(
	'tcmha_visitor_gate_allows_request',
	static function ( $allowed ) {
		return $allowed || null !== tcmha_survey_current();
	}
);

// Private-link polls are never indexed.
add_filter(
	'wp_robots',
	static function ( $robots ) {
		$poll = is_singular( 'tcmha_survey' ) ? tcmha_survey_get( get_queried_object_id() ) : null;
		if ( $poll && 'public' !== $poll['access'] ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

// Answers can be sent from the poll's page or from any page that shows it.
add_action(
	'template_redirect',
	static function () {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['tcmha_survey_respond'], $_POST['tcmha_survey_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the handler.
			return;
		}
		$post_id = absint( $_POST['tcmha_survey_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'tcmha_survey' === get_post_type( $post_id ) ) {
			tcmha_survey_handle_response( tcmha_survey_get( $post_id ) );
		}
	},
	4
);

// The poll's own page: not found without access; no caching with it.
add_action(
	'template_redirect',
	static function () {
		if ( ! is_singular( 'tcmha_survey' ) ) {
			return;
		}
		$poll = tcmha_survey_current();
		if ( ! $poll ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		nocache_headers();
		if ( 'public' !== $poll['access'] ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true ); // The link carries the key.
		}
	},
	5
);

/**
 * Remove our query args from a URL.
 *
 * @param string $url URL.
 * @return string
 */
function tcmha_survey_clean_url( $url ) {
	return remove_query_arg( TCMHA_SURVEY_QUERY_ARGS, $url );
}

/**
 * Save a response, then redirect back to the page it came from (so a refresh doesn't resend).
 * On a problem, what the person typed is kept for 15 minutes so they don't lose it.
 *
 * @param array $poll Poll settings.
 */
function tcmha_survey_handle_response( $poll ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified below before any write.
	$given    = isset( $_POST['tcmha_survey_k'] ) ? sanitize_text_field( wp_unslash( $_POST['tcmha_survey_k'] ) ) : '';
	$back_raw = isset( $_POST['tcmha_survey_back'] ) ? esc_url_raw( wp_unslash( $_POST['tcmha_survey_back'] ) ) : '';
	$back     = tcmha_survey_clean_url( wp_validate_redirect( $back_raw, tcmha_survey_share_url( $poll['id'] ) ) );
	$rid      = isset( $_POST['tcmha_survey_r'] ) ? sanitize_key( wp_unslash( $_POST['tcmha_survey_r'] ) ) : '';
	$token    = isset( $_POST['tcmha_survey_t'] ) ? sanitize_key( wp_unslash( $_POST['tcmha_survey_t'] ) ) : '';
	$existing = tcmha_survey_find_response( $poll['id'], $rid, $token );
	$anchor   = '#tcmha-survey-' . $poll['id'];

	// What the person sent, cleaned, so a problem never loses their answers.
	$name  = isset( $_POST['tcmha_survey_name'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['tcmha_survey_name'] ) ) ), 0, 80 ) : '';
	$email = isset( $_POST['tcmha_survey_email'] ) ? mb_substr( trim( sanitize_email( wp_unslash( $_POST['tcmha_survey_email'] ) ) ), 0, 190 ) : '';
	$raw   = isset( $_POST['tcmha_survey_a'] ) && is_array( $_POST['tcmha_survey_a'] ) ? wp_unslash( $_POST['tcmha_survey_a'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is checked against the poll's questions below.
	// phpcs:enable
	if ( 'none' === $poll['ask_name'] ) {
		$name = '';
	}
	if ( ! $poll['ask_email'] ) {
		$email = '';
	}

	$answers  = array();
	$missing  = array();
	$too_many = array();
	foreach ( $poll['questions'] as $q ) {
		$value = $raw[ $q['id'] ] ?? null;
		if ( 'text' === $q['type'] ) {
			$text = is_string( $value ) ? mb_substr( trim( sanitize_textarea_field( $value ) ), 0, TCMHA_SURVEY_TEXT_MAX ) : '';
			if ( '' !== $text ) {
				$answers[ $q['id'] ] = $text;
			}
		} else {
			$ids    = wp_list_pluck( $q['options'], 'id' );
			$sent   = array_map( 'sanitize_key', array_filter( (array) $value, 'is_string' ) );
			$picked = array_values( array_intersect( $ids, $sent ) );
			if ( $picked && 'single' === $q['type'] ) {
				$answers[ $q['id'] ] = $picked[0];
			} elseif ( $picked ) {
				if ( $q['max'] && count( $picked ) > $q['max'] ) {
					$too_many[] = $q['id'];
				}
				$answers[ $q['id'] ] = $picked;
			}
			if ( ! $q['options'] ) {
				continue;
			}
		}
		if ( $q['required'] && ! isset( $answers[ $q['id'] ] ) ) {
			$missing[] = $q['id'];
		}
	}

	$base = $existing ? tcmha_survey_personal_url( $poll['id'], $rid, $token, $back ) : add_query_arg( 'poll_id', $poll['id'], $back );
	$fail = static function ( $code ) use ( $base, $anchor, $name, $email, $answers, $missing, $too_many ) {
		$draft = strtolower( wp_generate_password( 16, false, false ) );
		set_transient(
			'tcmha_survey_draft_' . $draft,
			array(
				'name'     => $name,
				'email'    => $email,
				'answers'  => $answers,
				'missing'  => $missing,
				'too_many' => $too_many,
			),
			15 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'poll_error' => $code,
					'poll_draft' => $draft,
				),
				$base
			) . $anchor,
			303
		);
		exit;
	};

	if ( ! isset( $_POST['tcmha_survey_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_survey_nonce'] ) ), 'tcmha_survey_respond_' . $poll['id'] ) ) {
		$fail( 'expired' );
	}
	if ( 'publish' !== get_post_status( $poll['id'] ) ) {
		$fail( 'draft' );
	}
	if ( 'public' !== $poll['access'] && ! ( '' !== $poll['key'] && '' !== $given && hash_equals( $poll['key'], $given ) ) ) {
		$fail( 'access' );
	}
	if ( tcmha_survey_is_closed( $poll ) ) {
		$fail( 'closed' );
	}
	// Honeypot: people never see this field.
	if ( ! empty( $_POST['tcmha_survey_website'] ) ) {
		wp_safe_redirect( add_query_arg( 'poll_saved', '1', $base ) . $anchor, 303 );
		exit;
	}
	// Simple rate limit: 20 saves per hour per visitor per poll. The address is hashed and kept for an hour.
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate = 'tcmha_survey_rl_' . md5( $poll['id'] . '|' . $ip . '|' . wp_salt( 'nonce' ) );
	$hits = (int) get_transient( $rate );
	if ( $hits >= 20 ) {
		$fail( 'busy' );
	}
	set_transient( $rate, $hits + 1, HOUR_IN_SECONDS );

	if ( 'required' === $poll['ask_name'] && '' === $name ) {
		$fail( 'name' );
	}
	if ( '' !== $email && ! is_email( $email ) ) {
		$fail( 'email' );
	}
	if ( $missing ) {
		$fail( 'required' );
	}
	if ( $too_many ) {
		$fail( 'too_many' );
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
		update_post_meta( $poll['id'], TCMHA_SURVEY_RESPONSE_KEY, $row, $existing );
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
		add_post_meta( $poll['id'], TCMHA_SURVEY_RESPONSE_KEY, $row );
	}

	tcmha_survey_mail_confirmation( $poll, $row, tcmha_survey_personal_url( $poll['id'], $rid, $token ), (bool) $existing );
	if ( $poll['notify_each'] ) {
		tcmha_survey_mail_organizer( $poll, $row, (bool) $existing );
	}

	wp_safe_redirect( add_query_arg( 'poll_saved', '1', tcmha_survey_personal_url( $poll['id'], $rid, $token, $back ) ) . $anchor, 303 );
	exit;
}

/**
 * Load the poll's styles and script (works on the poll page and on any page that shows a poll).
 */
function tcmha_survey_enqueue() {
	if ( ! wp_style_is( 'tcmha-meeting-poll', 'enqueued' ) ) {
		wp_enqueue_style( 'tcmha-meeting-poll', TCMHA_CORE_URL . 'assets/css/meeting-poll.css', array(), TCMHA_CORE_VERSION );
		wp_add_inline_style(
			'tcmha-meeting-poll',
			'@font-face{font-family:"Lexend";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url( TCMHA_CORE_URL . 'assets/fonts/lexend-latin-variable.woff2' ) . '") format("woff2");}'
		);
	}
	wp_enqueue_style( 'tcmha-survey', TCMHA_CORE_URL . 'assets/css/survey.css', array( 'tcmha-meeting-poll' ), TCMHA_CORE_VERSION );
	wp_enqueue_script( 'tcmha-survey', TCMHA_CORE_URL . 'assets/js/survey.js', array(), TCMHA_CORE_VERSION, array( 'strategy' => 'defer' ) );
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( tcmha_survey_current() ) {
			tcmha_survey_enqueue();
		}
	}
);

// The poll's own page uses a branded template instead of the theme's single template.
add_filter(
	'template_include',
	static function ( $template ) {
		if ( is_singular( 'tcmha_survey' ) && tcmha_survey_current() ) {
			return TCMHA_CORE_DIR . 'templates/survey.php';
		}
		return $template;
	},
	99
);

/**
 * Show a poll inside any page: [tcmha_poll id="123"].
 * Showing a poll on a page opens it to everyone who can see that page.
 */
add_shortcode(
	'tcmha_poll',
	static function ( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'tcmha_poll' );
		$id   = absint( $atts['id'] );
		if ( 'tcmha_survey' !== get_post_type( $id ) || ( 'publish' !== get_post_status( $id ) && ! current_user_can( 'edit_post', $id ) ) ) {
			return '';
		}
		tcmha_survey_enqueue();
		$back = is_singular() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
		return tcmha_survey_render(
			tcmha_survey_get( $id ),
			array(
				'embedded' => true,
				'back'     => $back,
			)
		);
	}
);

/**
 * Query args for this poll on the current request.
 *
 * @param array $poll Poll settings.
 * @return array{rid: string, token: string, saved: bool, error: string, draft: array|null}
 */
function tcmha_survey_request_state( $poll ) {
	$state = array(
		'rid'   => '',
		'token' => '',
		'saved' => false,
		'error' => '',
		'draft' => null,
	);
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only query args.
	if ( ! isset( $_GET['poll_id'] ) || absint( $_GET['poll_id'] ) !== $poll['id'] ) {
		return $state;
	}
	$state['rid']   = isset( $_GET['poll_r'] ) ? sanitize_key( wp_unslash( $_GET['poll_r'] ) ) : '';
	$state['token'] = isset( $_GET['poll_t'] ) ? sanitize_key( wp_unslash( $_GET['poll_t'] ) ) : '';
	$state['saved'] = isset( $_GET['poll_saved'] );
	$state['error'] = isset( $_GET['poll_error'] ) ? sanitize_key( wp_unslash( $_GET['poll_error'] ) ) : '';
	$draft          = isset( $_GET['poll_draft'] ) ? sanitize_key( wp_unslash( $_GET['poll_draft'] ) ) : '';
	// phpcs:enable
	if ( '' !== $draft ) {
		$saved = get_transient( 'tcmha_survey_draft_' . $draft );
		if ( is_array( $saved ) ) {
			$state['draft'] = $saved;
		}
	}
	return $state;
}

/**
 * Picture, file, and "view larger" helpers.
 */

/**
 * "Open file" link: name, type, and size; opens in a new tab so answers aren't lost.
 *
 * @param int $file_id Attachment ID.
 * @return string HTML.
 */
function tcmha_survey_file_link( $file_id ) {
	$url = wp_get_attachment_url( $file_id );
	if ( ! $url ) {
		return '';
	}
	$path  = (string) get_attached_file( $file_id );
	$name  = get_the_title( $file_id );
	$name  = '' !== trim( $name ) ? $name : wp_basename( $path );
	$facts = array();
	$ext   = strtoupper( pathinfo( $path ? $path : $url, PATHINFO_EXTENSION ) );
	if ( $ext ) {
		$facts[] = $ext;
	}
	if ( $path && file_exists( $path ) ) {
		$facts[] = size_format( filesize( $path ), 1 );
	}
	return sprintf(
		'<a class="tcmha-survey__file" href="%1$s" target="_blank" rel="noopener">%2$s <span class="tcmha-survey__file-name">%3$s</span>%4$s<span class="tcmha-poll__sr"> %5$s</span></a>',
		esc_url( $url ),
		esc_html__( 'Open file:', 'tcmha' ),
		esc_html( $name ),
		$facts ? ' <span class="tcmha-survey__file-facts">(' . esc_html( implode( ', ', $facts ) ) . ')</span>' : '',
		esc_html__( '(opens in a new tab)', 'tcmha' )
	);
}

/**
 * "View larger" link for a picture. The script turns it into a pop-up; without the script it opens the picture.
 *
 * @param int    $image_id Attachment ID.
 * @param string $caption  What the picture is (the choice or question text).
 * @return string HTML.
 */
function tcmha_survey_zoom_link( $image_id, $caption ) {
	$full = wp_get_attachment_image_url( $image_id, 'full' );
	if ( ! $full ) {
		return '';
	}
	return sprintf(
		'<a class="tcmha-survey__zoom" href="%1$s" target="_blank" rel="noopener" data-tcmha-zoom data-alt="%2$s" data-caption="%3$s">%4$s<span class="tcmha-poll__sr">: %5$s</span><span class="tcmha-poll__sr" data-newtab> %6$s</span></a>',
		esc_url( $full ),
		esc_attr( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
		esc_attr( $caption ),
		esc_html__( 'View larger', 'tcmha' ),
		esc_html( $caption ),
		esc_html__( '(opens in a new tab)', 'tcmha' )
	);
}

/**
 * Front-end poll markup. Used by the poll's own page and by [tcmha_poll].
 *
 * @param array $poll Poll settings.
 * @param array $args embedded (bool): shown inside another page; back (string): page to return to after saving.
 * @return string
 */
function tcmha_survey_render( $poll, $args = array() ) {
	$args     = wp_parse_args(
		$args,
		array(
			'embedded' => false,
			'back'     => '',
		)
	);
	$embedded = (bool) $args['embedded'];
	$back     = $args['back'] ? tcmha_survey_clean_url( $args['back'] ) : tcmha_survey_share_url( $poll['id'] );
	$hq       = $embedded ? 'h3' : 'h2'; // Heading level for questions and sections.
	$uid      = 'tcmha-survey-' . $poll['id'];
	$state    = tcmha_survey_request_state( $poll );
	$mine     = tcmha_survey_find_response( $poll['id'], $state['rid'], $state['token'] );
	$draft    = $state['draft'];
	$values   = $draft ? $draft : ( $mine ? $mine : array() );
	$closed   = tcmha_survey_is_closed( $poll );
	$is_draft = 'publish' !== get_post_status( $poll['id'] );
	$show_res = tcmha_survey_results_visible( $poll, (bool) $mine );
	$errors   = array(
		'expired'  => __( 'This page was open a long time, so we could not save. Please check your answers and press Send again.', 'tcmha' ),
		'draft'    => __( 'This poll is not published yet, so answers are not saved.', 'tcmha' ),
		'access'   => __( 'This link does not work for answering. Please use the link you were sent.', 'tcmha' ),
		'closed'   => __( 'This poll is closed, so your answers were not saved.', 'tcmha' ),
		'busy'     => __( 'Too many tries from this device. Please wait an hour and try again.', 'tcmha' ),
		'name'     => __( 'Please type your name.', 'tcmha' ),
		'email'    => __( 'That email address does not look right. Please check it, or leave it blank.', 'tcmha' ),
		'required' => __( 'Please answer the questions marked below.', 'tcmha' ),
		'too_many' => __( 'You picked too many choices for a question marked below.', 'tcmha' ),
		'answers'  => __( 'Please answer at least one question.', 'tcmha' ),
	);
	$missing  = $draft['missing'] ?? array();
	$too_many = $draft['too_many'] ?? array();

	ob_start();
	?>
	<div class="tcmha-poll tcmha-survey<?php echo $embedded ? ' tcmha-survey--embedded' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>">
		<?php if ( $embedded ) : ?>
			<h2 class="tcmha-survey__title"><?php echo esc_html( get_the_title( $poll['id'] ) ); ?></h2>
			<?php $intro = get_post_field( 'post_content', $poll['id'] ); ?>
			<?php if ( '' !== trim( (string) $intro ) ) : ?>
				<div class="tcmha-survey__intro"><?php echo wp_kses_post( wpautop( $intro ) ); ?></div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $state['saved'] && $mine ) : ?>
			<div class="tcmha-poll__notice tcmha-poll__notice--ok" role="status" tabindex="-1" data-focus>
				<p><strong>
					<?php
					echo esc_html(
						'' !== $mine['name']
							/* translators: %s: name */
							? sprintf( __( 'Thank you, %s. Your answers are saved.', 'tcmha' ), $mine['name'] )
							: __( 'Thank you. Your answers are saved.', 'tcmha' )
					);
					?>
				</strong></p>
				<p><?php esc_html_e( 'This page is your private link. Bookmark it to change your answers later.', 'tcmha' ); ?>
				<?php if ( ! empty( $mine['email'] ) ) : ?>
					<?php esc_html_e( 'We also emailed it to you.', 'tcmha' ); ?>
				<?php endif; ?></p>
			</div>
		<?php elseif ( $state['error'] && isset( $errors[ $state['error'] ] ) ) : ?>
			<div class="tcmha-poll__notice tcmha-poll__notice--error" role="alert" tabindex="-1" data-focus>
				<p><?php echo esc_html( $errors[ $state['error'] ] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $is_draft ) : ?>
			<div class="tcmha-poll__notice tcmha-poll__notice--error">
				<p><?php esc_html_e( 'Preview: this poll is not published. Only editors can see it, and answers are not saved.', 'tcmha' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $closed ) : ?>
			<section class="tcmha-poll__picked" aria-labelledby="<?php echo esc_attr( $uid ); ?>-closed">
				<<?php echo esc_html( $hq ); ?> id="<?php echo esc_attr( $uid ); ?>-closed"><?php esc_html_e( 'This poll is closed', 'tcmha' ); ?></<?php echo esc_html( $hq ); ?>>
				<p><?php echo esc_html( $show_res ? __( 'Thank you to everyone who answered. Here are the results.', 'tcmha' ) : __( 'Thank you to everyone who answered.', 'tcmha' ) ); ?></p>
			</section>
		<?php elseif ( '' !== $poll['deadline'] ) : ?>
			<p class="tcmha-poll__lead"><strong><?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Please answer by %s.', 'tcmha' ), tcmha_survey_deadline_label( $poll ) ) ); ?></strong></p>
		<?php endif; ?>

		<?php if ( $poll['files'] ) : ?>
			<section class="tcmha-survey__files" aria-labelledby="<?php echo esc_attr( $uid ); ?>-files">
				<<?php echo esc_html( $hq ); ?> id="<?php echo esc_attr( $uid ); ?>-files" class="tcmha-survey__files-title"><?php esc_html_e( 'Files to look at', 'tcmha' ); ?></<?php echo esc_html( $hq ); ?>>
				<ul role="list">
					<?php foreach ( $poll['files'] as $file ) : ?>
						<li><?php echo tcmha_survey_file_link( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php
		if ( $show_res ) {
			tcmha_survey_render_results( $poll, $hq, $uid );
		}
		?>

		<?php if ( ! $closed && ! $poll['questions'] ) : ?>
			<p><?php esc_html_e( 'Questions will be added soon.', 'tcmha' ); ?></p>
		<?php elseif ( ! $closed ) : ?>
			<?php $collapse = $mine && $show_res && ! $draft; ?>
			<?php if ( $collapse ) : ?>
				<details class="tcmha-survey__change">
					<summary><?php esc_html_e( 'Change my answers', 'tcmha' ); ?></summary>
			<?php endif; ?>

			<form class="tcmha-survey__form" method="post" action="<?php echo esc_url( $back . '#' . $uid ); ?>">
				<?php wp_nonce_field( 'tcmha_survey_respond_' . $poll['id'], 'tcmha_survey_nonce', false ); ?>
				<input type="hidden" name="tcmha_survey_respond" value="1">
				<input type="hidden" name="tcmha_survey_id" value="<?php echo (int) $poll['id']; ?>">
				<input type="hidden" name="tcmha_survey_k" value="<?php echo esc_attr( $poll['key'] ); ?>">
				<input type="hidden" name="tcmha_survey_back" value="<?php echo esc_url( $back ); ?>">
				<?php if ( $mine ) : ?>
					<input type="hidden" name="tcmha_survey_r" value="<?php echo esc_attr( $state['rid'] ); ?>">
					<input type="hidden" name="tcmha_survey_t" value="<?php echo esc_attr( $state['token'] ); ?>">
				<?php endif; ?>
				<div class="tcmha-poll__trap" aria-hidden="true">
					<label for="<?php echo esc_attr( $uid ); ?>-website"><?php esc_html_e( 'Leave this empty', 'tcmha' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-website" name="tcmha_survey_website" tabindex="-1" autocomplete="off">
				</div>

				<?php if ( 'none' !== $poll['ask_name'] || $poll['ask_email'] ) : ?>
					<div class="tcmha-poll__who">
						<?php if ( 'none' !== $poll['ask_name'] ) : ?>
							<p class="tcmha-poll__field">
								<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Your name', 'tcmha' ); ?> <span class="tcmha-poll__req"><?php echo 'required' === $poll['ask_name'] ? esc_html__( '(required)', 'tcmha' ) : esc_html__( '(optional)', 'tcmha' ); ?></span></label>
								<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="tcmha_survey_name" maxlength="80" autocomplete="name" value="<?php echo esc_attr( $values['name'] ?? '' ); ?>" <?php echo 'required' === $poll['ask_name'] ? 'required' : ''; ?>>
							</p>
						<?php endif; ?>
						<?php if ( $poll['ask_email'] ) : ?>
							<p class="tcmha-poll__field">
								<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Your email', 'tcmha' ); ?> <span class="tcmha-poll__req"><?php esc_html_e( '(optional)', 'tcmha' ); ?></span></label>
								<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="tcmha_survey_email" maxlength="190" autocomplete="email" aria-describedby="<?php echo esc_attr( $uid ); ?>-email-hint" value="<?php echo esc_attr( $values['email'] ?? '' ); ?>">
								<span class="tcmha-poll__hint" id="<?php echo esc_attr( $uid ); ?>-email-hint"><?php esc_html_e( 'We only use it to send you a link to change your answers.', 'tcmha' ); ?></span>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php
				$count = count( $poll['questions'] );
				foreach ( $poll['questions'] as $n => $q ) {
					tcmha_survey_render_question( $poll, $q, $n + 1, $count, $values['answers'][ $q['id'] ] ?? null, $hq, $uid, in_array( $q['id'], $missing, true ), in_array( $q['id'], $too_many, true ) );
				}
				?>

				<p class="tcmha-poll__submit">
					<button type="submit" class="tcmha-poll__button"><?php echo $mine ? esc_html__( 'Update my answers', 'tcmha' ) : esc_html__( 'Send my answers', 'tcmha' ); ?></button>
				</p>
			</form>

			<?php if ( $collapse ) : ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * One question in the form.
 *
 * @param array        $poll     Poll settings.
 * @param array        $q        Question.
 * @param int          $number   Question number.
 * @param int          $count    Number of questions.
 * @param string|array $current  Current answer.
 * @param string       $hq       Heading tag.
 * @param string       $uid      Poll element ID.
 * @param bool         $missing  Required but not answered (after a failed send).
 * @param bool         $too_many Too many choices (after a failed send).
 */
function tcmha_survey_render_question( $poll, $q, $number, $count, $current, $hq, $uid, $missing, $too_many ) {
	$qid      = $uid . '-' . $q['id'];
	$title    = $count > 1 ? $number . '. ' . $q['text'] : $q['text'];
	$required = $q['required'] ? ' <span class="tcmha-poll__req">' . esc_html__( '(required)', 'tcmha' ) . '</span>' : '';
	$describe = array();
	$error    = '';
	if ( $missing ) {
		$error = 'text' === $q['type'] ? __( 'Please write an answer.', 'tcmha' ) : __( 'Please pick an answer.', 'tcmha' );
	} elseif ( $too_many ) {
		/* translators: %d: most choices allowed */
		$error = sprintf( __( 'Please pick no more than %d.', 'tcmha' ), $q['max'] );
	}

	if ( 'text' !== $q['type'] && ! $q['options'] ) {
		return;
	}

	ob_start();
	if ( '' !== $q['help'] ) {
		$describe[] = $qid . '-help';
		echo '<p class="tcmha-survey__help" id="' . esc_attr( $qid . '-help' ) . '">' . nl2br( esc_html( $q['help'] ) ) . '</p>';
	}
	if ( $q['image'] ) {
		echo '<figure class="tcmha-survey__q-image">' . wp_get_attachment_image( $q['image'], 'large', false, array( 'sizes' => '(max-width: 54rem) 100vw, 54rem' ) );
		echo '<figcaption>' . tcmha_survey_zoom_link( $q['image'], $q['text'] ) . '</figcaption></figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
	if ( $q['file'] ) {
		echo '<p class="tcmha-survey__q-file">' . tcmha_survey_file_link( $q['file'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
	$extras = (string) ob_get_clean();

	$error_html = '';
	if ( $error ) {
		$describe[] = $qid . '-error';
		$error_html = '<p class="tcmha-survey__error" id="' . esc_attr( $qid . '-error' ) . '">' . esc_html( $error ) . '</p>';
	}
	?>
	<div class="tcmha-survey__q<?php echo $error ? ' has-error' : ''; ?>" id="<?php echo esc_attr( $qid ); ?>">
		<?php if ( 'text' === $q['type'] ) : ?>
			<<?php echo esc_html( $hq ); ?> class="tcmha-survey__q-title"><label for="<?php echo esc_attr( $qid ); ?>-input"><?php echo esc_html( $title ); ?><?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?></label></<?php echo esc_html( $hq ); ?>>
			<?php echo $extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?>
			<?php $describe[] = $qid . '-limit'; ?>
			<textarea id="<?php echo esc_attr( $qid ); ?>-input" name="tcmha_survey_a[<?php echo esc_attr( $q['id'] ); ?>]" rows="4" maxlength="<?php echo (int) TCMHA_SURVEY_TEXT_MAX; ?>" aria-describedby="<?php echo esc_attr( implode( ' ', $describe ) ); ?>" <?php echo $q['required'] ? 'required' : ''; ?> <?php echo $error ? 'aria-invalid="true"' : ''; ?>><?php echo esc_textarea( is_string( $current ) ? $current : '' ); ?></textarea>
			<p class="tcmha-poll__hint" id="<?php echo esc_attr( $qid ); ?>-limit"><?php esc_html_e( 'Up to 1,000 characters. Please do not include health details or other private information.', 'tcmha' ); ?></p>
			<?php echo $error_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?>
		<?php else : ?>
			<?php
			$multi  = 'multiple' === $q['type'];
			$how    = $multi ? ( $q['max'] ? sprintf( /* translators: %d: most choices allowed */ __( 'Pick up to %d.', 'tcmha' ), $q['max'] ) : __( 'Pick all that you like.', 'tcmha' ) ) : __( 'Pick one.', 'tcmha' );
			$images = (bool) array_filter( wp_list_pluck( $q['options'], 'image' ) );
			array_unshift( $describe, $qid . '-how' );
			$picked = (array) $current;
			?>
			<fieldset class="tcmha-survey__fieldset" aria-describedby="<?php echo esc_attr( implode( ' ', $describe ) ); ?>" <?php echo $multi && $q['max'] ? 'data-max="' . (int) $q['max'] . '"' : ''; ?> <?php echo $multi && $q['required'] ? 'data-required-group' : ''; ?>>
				<legend class="tcmha-survey__legend"><<?php echo esc_html( $hq ); ?> class="tcmha-survey__q-title"><?php echo esc_html( $title ); ?><?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?></<?php echo esc_html( $hq ); ?>></legend>
				<p class="tcmha-survey__how" id="<?php echo esc_attr( $qid ); ?>-how"><?php echo esc_html( $how ); ?></p>
				<?php echo $extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?>
				<?php echo $error_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above. ?>
				<p class="tcmha-survey__error" id="<?php echo esc_attr( $qid ); ?>-js-error" data-js-error hidden><?php esc_html_e( 'Please pick an answer.', 'tcmha' ); ?></p>
				<ul class="tcmha-survey__options<?php echo $images ? ' has-images' : ''; ?>" role="list">
					<?php foreach ( $q['options'] as $o ) : ?>
						<li class="tcmha-survey__option">
							<label class="tcmha-survey__choice">
								<input type="<?php echo $multi ? 'checkbox' : 'radio'; ?>" name="tcmha_survey_a[<?php echo esc_attr( $q['id'] ); ?>]<?php echo $multi ? '[]' : ''; ?>" value="<?php echo esc_attr( $o['id'] ); ?>" <?php checked( in_array( $o['id'], $picked, true ) ); ?> <?php echo ! $multi && $q['required'] ? 'required' : ''; ?>>
								<span class="tcmha-survey__card">
									<?php if ( $o['image'] ) : ?>
										<span class="tcmha-survey__img"><?php echo wp_get_attachment_image( $o['image'], 'medium_large', false, array( 'sizes' => '(max-width: 40rem) 100vw, 24rem' ) ); ?></span>
									<?php endif; ?>
									<span class="tcmha-survey__label"><?php echo esc_html( $o['label'] ); ?></span>
								</span>
							</label>
							<?php if ( $o['image'] || $o['file'] ) : ?>
								<p class="tcmha-survey__extras">
									<?php
									echo $o['image'] ? tcmha_survey_zoom_link( $o['image'], $o['label'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
									echo $o['file'] ? tcmha_survey_file_link( $o['file'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
									?>
								</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $multi && $q['max'] ) : ?>
					<p class="tcmha-poll__hint tcmha-survey__limit" aria-live="polite"></p>
				<?php endif; ?>
			</fieldset>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Results on the poll page: counts and percentages. Names and written answers stay private.
 *
 * @param array  $poll Poll settings.
 * @param string $hq   Heading tag for the section.
 * @param string $uid  Poll element ID.
 */
function tcmha_survey_render_results( $poll, $hq, $uid ) {
	$tally = tcmha_survey_tally( $poll, tcmha_survey_responses( $poll['id'] ) );
	$sub   = 'h2' === $hq ? 'h3' : 'h4';
	?>
	<section class="tcmha-survey__results" aria-labelledby="<?php echo esc_attr( $uid ); ?>-results">
		<<?php echo esc_html( $hq ); ?> id="<?php echo esc_attr( $uid ); ?>-results"><?php esc_html_e( 'Results so far', 'tcmha' ); ?></<?php echo esc_html( $hq ); ?>>
		<p class="tcmha-poll__lead">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of people */
					_n( '%d person has answered.', '%d people have answered.', $tally['total'], 'tcmha' ),
					$tally['total']
				)
			);
			?>
		</p>
		<?php foreach ( $poll['questions'] as $q ) : ?>
			<?php $row = $tally['questions'][ $q['id'] ]; ?>
			<div class="tcmha-survey__result">
				<<?php echo esc_html( $sub ); ?> class="tcmha-survey__result-title"><?php echo esc_html( $q['text'] ); ?></<?php echo esc_html( $sub ); ?>>
				<?php if ( 'text' === $q['type'] ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of written answers */
								_n( '%d written answer. Only the organizers read these.', '%d written answers. Only the organizers read these.', $row['answered'], 'tcmha' ),
								$row['answered']
							)
						);
						?>
					</p>
				<?php else : ?>
					<ul class="tcmha-survey__bars" role="list">
						<?php foreach ( $q['options'] as $o ) : ?>
							<?php
							$votes = $row['options'][ $o['id'] ];
							$pct   = $row['answered'] ? round( 100 * $votes / $row['answered'] ) : 0;
							$top   = in_array( $o['id'], $row['top'], true );
							?>
							<li class="tcmha-survey__bar-row<?php echo $top ? ' is-top' : ''; ?>">
								<?php if ( $o['image'] ) : ?>
									<span class="tcmha-survey__thumb"><?php echo wp_get_attachment_image( $o['image'], 'thumbnail', false, array( 'alt' => '' ) ); ?></span>
								<?php endif; ?>
								<span class="tcmha-survey__bar-text">
									<span class="tcmha-survey__bar-label"><?php echo esc_html( $o['label'] ); ?></span>
									<span class="tcmha-survey__bar-count">
										<?php if ( $top ) : ?>
											<strong class="tcmha-poll__badge"><?php echo count( $row['top'] ) > 1 ? esc_html__( 'Tied for most', 'tcmha' ) : esc_html__( 'Most picked', 'tcmha' ); ?></strong>
										<?php endif; ?>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: number of people, 2: percent */
												_n( '%1$d person (%2$d%%)', '%1$d people (%2$d%%)', $votes, 'tcmha' ),
												$votes,
												$pct
											)
										);
										?>
									</span>
									<span class="tcmha-poll__bar" aria-hidden="true"><span class="tcmha-poll__bar-yes" style="width: <?php echo esc_attr( $pct ); ?>%"></span></span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( 'multiple' === $q['type'] ) : ?>
						<p class="tcmha-poll__hint"><?php esc_html_e( 'People could pick more than one, so percents can add up to more than 100.', 'tcmha' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</section>
	<?php
}

/**
 * Plain-text lines for one person's answers (for emails).
 *
 * @param array $poll    Poll settings.
 * @param array $answers Answers.
 * @return string[]
 */
function tcmha_survey_answer_lines( $poll, $answers ) {
	$lines = array();
	foreach ( $poll['questions'] as $n => $q ) {
		$lines[] = '';
		$lines[] = ( $n + 1 ) . '. ' . $q['text'];
		$answer  = $answers[ $q['id'] ] ?? null;
		if ( null === $answer ) {
			$lines[] = '   ' . __( '(no answer)', 'tcmha' );
		} elseif ( 'text' === $q['type'] ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $answer ) as $line ) {
				$lines[] = '   ' . $line;
			}
		} else {
			$lines[] = '   ' . implode( ', ', tcmha_survey_answer_labels( $q, $answer ) );
		}
	}
	return $lines;
}

/**
 * Plain-text lines for the results (for emails), with names.
 *
 * @param array $poll  Poll settings.
 * @param array $tally Tally.
 * @return string[]
 */
function tcmha_survey_result_lines( $poll, $tally ) {
	$lines = array();
	foreach ( $poll['questions'] as $n => $q ) {
		$row     = $tally['questions'][ $q['id'] ];
		$lines[] = '';
		$lines[] = ( $n + 1 ) . '. ' . strtoupper( $q['text'] );
		if ( 'text' === $q['type'] ) {
			if ( ! $row['texts'] ) {
				$lines[] = '   ' . __( '(no written answers)', 'tcmha' );
			}
			foreach ( $row['texts'] as $text ) {
				$who     = '' !== $text['name'] ? $text['name'] : __( 'No name', 'tcmha' );
				$lines[] = '   - ' . $who . ': ' . preg_replace( '/\s*\R\s*/', ' / ', $text['text'] );
			}
			continue;
		}
		$order = $q['options'];
		usort(
			$order,
			static function ( $a, $b ) use ( $row ) {
				return $row['options'][ $b['id'] ] <=> $row['options'][ $a['id'] ];
			}
		);
		foreach ( $order as $o ) {
			$votes   = $row['options'][ $o['id'] ];
			$pct     = $row['answered'] ? round( 100 * $votes / $row['answered'] ) : 0;
			$names   = array_filter( $row['names'][ $o['id'] ] );
			$lines[] = sprintf( '   %1$s: %2$d (%3$d%%)%4$s', $o['label'], $votes, $pct, $names ? ' - ' . implode( ', ', $names ) : '' );
		}
	}
	return $lines;
}

/**
 * Reply-To header so replies to poll emails reach the organizers.
 *
 * @param array $poll Poll settings.
 * @return string[]
 */
function tcmha_survey_mail_headers( $poll ) {
	return $poll['send_to'] ? array( 'Reply-To: ' . implode( ', ', $poll['send_to'] ) ) : array();
}

/**
 * Email a person their private link.
 *
 * @param array  $poll     Poll settings.
 * @param array  $row      Response.
 * @param string $personal Private link.
 * @param bool   $updated  Whether this was a change.
 */
function tcmha_survey_mail_confirmation( $poll, $row, $personal, $updated ) {
	if ( empty( $row['email'] ) ) {
		return;
	}
	$title = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	/* translators: %s: poll title */
	$subject = sprintf( $updated ? __( 'Updated: your answers for %s', 'tcmha' ) : __( 'Thanks: we got your answers for %s', 'tcmha' ), $title );
	$hello   = '' !== $row['name'] ? sprintf( /* translators: %s: name */ __( 'Hi %s,', 'tcmha' ), $row['name'] ) : __( 'Hi,', 'tcmha' );
	$body    = $hello . "\n\n" . sprintf(
		/* translators: %s: private link */
		__( "Thanks for answering. You can change your answers any time before the poll closes here:\n%s", 'tcmha' ),
		$personal
	) . "\n\n" . __( 'Your answers:', 'tcmha' ) . "\n" . implode( "\n", tcmha_survey_answer_lines( $poll, $row['answers'] ) ) . tcmha_poll_mail_signoff();

	wp_mail( $row['email'], $subject, $body, tcmha_survey_mail_headers( $poll ) );
}

/**
 * Send each answer to the "Send responses to" addresses.
 *
 * @param array $poll    Poll settings.
 * @param array $row     Response.
 * @param bool  $updated Whether this was a change.
 */
function tcmha_survey_mail_organizer( $poll, $row, $updated ) {
	if ( ! $poll['send_to'] ) {
		return;
	}
	$title = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$who   = '' !== $row['name'] ? $row['name'] : __( 'Someone', 'tcmha' );
	$total = count( tcmha_survey_responses( $poll['id'] ) );
	/* translators: 1: name, 2: poll title */
	$subject = sprintf( $updated ? __( '%1$s changed their answers: %2$s', 'tcmha' ) : __( '%1$s answered: %2$s', 'tcmha' ), $who, $title );
	$lines   = array_merge(
		array( $who . ( ! empty( $row['email'] ) ? ' (' . $row['email'] . ')' : '' ) ),
		tcmha_survey_answer_lines( $poll, $row['answers'] ),
		array(
			'',
			/* translators: %d: number of answers */
			sprintf( _n( 'So far: %d person has answered.', 'So far: %d people have answered.', $total, 'tcmha' ), $total ),
			'',
			__( 'See all answers (sign in first):', 'tcmha' ),
			admin_url( 'post.php?post=' . $poll['id'] . '&action=edit' ),
		)
	);
	$headers = ! empty( $row['email'] ) ? array( 'Reply-To: ' . $row['email'] ) : array();
	wp_mail( $poll['send_to'], $subject, implode( "\n", $lines ) . tcmha_poll_mail_signoff(), $headers );
}

/**
 * Email the results to the "Send responses to" addresses.
 *
 * @param array $poll Poll settings.
 * @return bool Whether it was sent.
 */
function tcmha_survey_mail_results( $poll ) {
	if ( ! $poll['send_to'] ) {
		return false;
	}
	$title  = html_entity_decode( get_the_title( $poll['id'] ), ENT_QUOTES, 'UTF-8' );
	$tally  = tcmha_survey_tally( $poll, tcmha_survey_responses( $poll['id'] ) );
	$closed = tcmha_survey_is_closed( $poll );
	/* translators: %s: poll title */
	$subject = sprintf( $closed ? __( 'Final results: %s', 'tcmha' ) : __( 'Results so far: %s', 'tcmha' ), $title );
	$lines   = array_merge(
		array(
			$closed
				/* translators: %d: number of people */
				? sprintf( _n( 'The poll is closed. %d person answered.', 'The poll is closed. %d people answered.', $tally['total'], 'tcmha' ), $tally['total'] )
				/* translators: %d: number of people */
				: sprintf( _n( 'The poll is still open. %d person has answered so far.', 'The poll is still open. %d people have answered so far.', $tally['total'], 'tcmha' ), $tally['total'] ),
			__( 'Choices are listed most picked first.', 'tcmha' ),
		),
		tcmha_survey_result_lines( $poll, $tally ),
		array(
			'',
			__( 'See all answers or download a spreadsheet (sign in first):', 'tcmha' ),
			admin_url( 'post.php?post=' . $poll['id'] . '&action=edit' ),
		)
	);
	$sent = wp_mail( $poll['send_to'], $subject, implode( "\n", $lines ) . tcmha_poll_mail_signoff() );
	if ( $sent && $closed ) {
		update_post_meta( $poll['id'], '_tcmha_survey_results_sent', time() );
		update_post_meta( $poll['id'], '_tcmha_survey_results_for', $poll['deadline'] );
	}
	return $sent;
}

/**
 * Hourly: send final results after a deadline (once per deadline), and remove names and emails
 * 30 days after a poll closed.
 */
function tcmha_survey_hourly() {
	$ids = get_posts(
		array(
			'post_type'      => 'tcmha_survey',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => 100,
			'fields'         => 'ids',
		)
	);
	foreach ( $ids as $post_id ) {
		$poll = tcmha_survey_get( $post_id );
		if ( 'publish' === get_post_status( $post_id ) && tcmha_survey_deadline_passed( $poll ) && ! get_post_meta( $post_id, '_tcmha_survey_results_sent', true ) ) {
			tcmha_survey_mail_results( $poll );
		}
		$closed_at = tcmha_survey_closed_at( $poll );
		if ( $closed_at && $closed_at + TCMHA_SURVEY_SCRUB_DAYS * DAY_IN_SECONDS < time() ) {
			tcmha_survey_scrub( $post_id );
		}
	}
}
add_action( 'tcmha_survey_hourly', 'tcmha_survey_hourly' );

/**
 * Remove names and emails from a poll's answers (answers stay, without who sent them).
 *
 * @param int $post_id Poll ID.
 */
function tcmha_survey_scrub( $post_id ) {
	foreach ( tcmha_survey_responses( $post_id ) as $row ) {
		if ( '' === ( $row['name'] ?? '' ) && '' === ( $row['email'] ?? '' ) ) {
			continue;
		}
		$clean          = $row;
		$clean['name']  = '';
		$clean['email'] = '';
		update_post_meta( $post_id, TCMHA_SURVEY_RESPONSE_KEY, $clean, $row );
	}
}

add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'tcmha_survey_hourly' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'tcmha_survey_hourly' );
		}
	}
);
