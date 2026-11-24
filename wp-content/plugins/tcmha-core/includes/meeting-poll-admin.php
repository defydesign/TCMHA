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
	static function () {
		add_meta_box( 'tcmha-poll-results', __( 'Answers', 'tcmha' ), 'tcmha_poll_results_box', 'tcmha_poll', 'normal', 'high' );
		add_meta_box( 'tcmha-poll-settings', __( 'Poll settings', 'tcmha' ), 'tcmha_poll_settings_box', 'tcmha_poll', 'normal', 'default' );
	}
);

/**
 * Settings meta box.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_poll_settings_box( $post ) {
	$poll = tcmha_poll_get( $post->ID );
	wp_nonce_field( 'tcmha_poll_settings_' . $post->ID, 'tcmha_poll_settings_nonce' );
	?>
	<p>
		<label for="tcmha-poll-slots"><strong><?php esc_html_e( 'Times to offer', 'tcmha' ); ?></strong></label><br>
		<textarea id="tcmha-poll-slots" name="tcmha_poll[slots]" rows="8" class="large-text code" aria-describedby="tcmha-poll-slots-hint"><?php echo esc_textarea( implode( "\n", $poll['slots'] ) ); ?></textarea>
		<span id="tcmha-poll-slots-hint" class="description"><?php esc_html_e( 'One per line, as YYYY-MM-DD HH:MM in 24-hour time. Example: 2026-12-11 13:00', 'tcmha' ); ?></span>
	</p>
	<p>
		<label for="tcmha-poll-duration"><strong><?php esc_html_e( 'Meeting length (minutes)', 'tcmha' ); ?></strong></label><br>
		<input type="number" id="tcmha-poll-duration" name="tcmha_poll[duration]" min="15" max="600" step="15" value="<?php echo esc_attr( $poll['duration'] ); ?>">
	</p>
	<p>
		<label for="tcmha-poll-tz"><strong><?php esc_html_e( 'Time zone', 'tcmha' ); ?></strong></label><br>
		<input type="text" id="tcmha-poll-tz" name="tcmha_poll[tz]" value="<?php echo esc_attr( $poll['tz'] ); ?>" class="regular-text">
	</p>
	<p>
		<label for="tcmha-poll-link"><strong><?php esc_html_e( 'Video meeting link', 'tcmha' ); ?></strong></label><br>
		<input type="url" id="tcmha-poll-link" name="tcmha_poll[link]" value="<?php echo esc_attr( $poll['link'] ); ?>" class="large-text" aria-describedby="tcmha-poll-link-hint">
		<span id="tcmha-poll-link-hint" class="description"><?php esc_html_e( 'Shown on the page and in the invite once you pick a time.', 'tcmha' ); ?></span>
	</p>
	<p>
		<label for="tcmha-poll-send-to"><strong><?php esc_html_e( 'Send responses to', 'tcmha' ); ?></strong></label><br>
		<input type="text" id="tcmha-poll-send-to" name="tcmha_poll[send_to]" value="<?php echo esc_attr( implode( ', ', $poll['send_to'] ) ); ?>" class="large-text" aria-describedby="tcmha-poll-send-to-hint" autocomplete="off">
		<span id="tcmha-poll-send-to-hint" class="description"><?php esc_html_e( 'One or more email addresses, separated by commas. Each response is emailed here, and replies to poll emails come here too. Leave blank for no emails.', 'tcmha' ); ?></span>
	</p>
	<p>
		<input type="hidden" name="tcmha_poll[show_names]" value="0">
		<label><input type="checkbox" name="tcmha_poll[show_names]" value="1" <?php checked( $poll['show_names'] ); ?>> <?php esc_html_e( 'Show names to everyone with the link', 'tcmha' ); ?></label>
	</p>
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
		tcmha_poll_save_settings( $post_id, wp_unslash( $_POST['tcmha_poll'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
			foreach ( array( 'slots', 'duration', 'tz', 'link', 'send_to', 'show_names' ) as $field ) {
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
