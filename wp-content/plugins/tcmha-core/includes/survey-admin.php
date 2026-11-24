<?php
/**
 * Polls: WP admin screens (question builder with pictures and files), actions, and admin-only REST routes.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

// The classic editor keeps the boxes simple.
add_filter(
	'use_block_editor_for_post_type',
	static function ( $use, $post_type ) {
		return 'tcmha_survey' === $post_type ? false : $use;
	},
	10,
	2
);

add_action(
	'add_meta_boxes_tcmha_survey',
	static function ( $post ) {
		$boxes = array(
			array( 'tcmha-survey-questions', __( 'Questions', 'tcmha' ), 'tcmha_survey_questions_box' ),
			array( 'tcmha-survey-settings', __( 'Poll settings', 'tcmha' ), 'tcmha_survey_settings_box' ),
		);
		$results = array( 'tcmha-survey-results', __( 'Answers', 'tcmha' ), 'tcmha_survey_results_box' );
		// Once people have answered, show the answers first.
		if ( tcmha_survey_responses( $post->ID ) ) {
			array_unshift( $boxes, $results );
		} else {
			$boxes[] = $results;
		}
		foreach ( $boxes as $box ) {
			add_meta_box( $box[0], $box[1], $box[2], 'tcmha_survey', 'normal', 'high' );
		}
		add_meta_box( 'tcmha-survey-howto', __( 'How a poll works', 'tcmha' ), 'tcmha_survey_howto_box', 'tcmha_survey', 'side', 'default' );
	}
);

add_filter(
	'enter_title_here',
	static function ( $text, $post ) {
		return 'tcmha_survey' === $post->post_type ? __( 'Poll title people will see, e.g. "Help us pick a new logo"', 'tcmha' ) : $text;
	},
	10,
	2
);

add_action(
	'edit_form_after_title',
	static function ( $post ) {
		if ( 'tcmha_survey' !== $post->post_type ) {
			return;
		}
		echo '<p class="tcmha-poll-admin-note"><strong>' . esc_html__( 'Intro text', 'tcmha' ) . '</strong> ' . esc_html__( '(shown under the title). Two or three short sentences: what the poll is for, how long it takes, and when it closes. Example: "TCMHA is getting a new logo. Please look at the three ideas below and tell us which one you like best. It takes about two minutes."', 'tcmha' ) . '</p>';
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'tcmha_survey' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'tcmha-survey-admin', TCMHA_CORE_URL . 'assets/css/survey-admin.css', array(), TCMHA_CORE_VERSION );
		wp_enqueue_script( 'tcmha-survey-admin', TCMHA_CORE_URL . 'assets/js/survey-admin.js', array( 'media-editor' ), TCMHA_CORE_VERSION, true );
		wp_localize_script(
			'tcmha-survey-admin',
			'tcmhaSurveyAdmin',
			array(
				'pickImage'      => __( 'Choose a picture', 'tcmha' ),
				'pickFile'       => __( 'Choose a file', 'tcmha' ),
				'pickFiles'      => __( 'Choose files', 'tcmha' ),
				'use'            => __( 'Use this', 'tcmha' ),
				'changeImage'    => __( 'Change picture', 'tcmha' ),
				'addImage'       => __( 'Add picture', 'tcmha' ),
				'changeFile'     => __( 'Change file', 'tcmha' ),
				'addFile'        => __( 'Add file', 'tcmha' ),
				'remove'         => __( 'Remove', 'tcmha' ),
				'noAlt'          => __( 'No description yet. Click "Change picture" and fill in "Alternative Text" so screen reader users know what it shows.', 'tcmha' ),
				'removeQuestion' => __( 'Remove this question? Answers to it will no longer show.', 'tcmha' ),
				'movedUp'        => __( 'Moved up.', 'tcmha' ),
				'movedDown'      => __( 'Moved down.', 'tcmha' ),
				'removed'        => __( 'Removed.', 'tcmha' ),
				'added'          => __( 'Added.', 'tcmha' ),
			)
		);
	}
);

/**
 * "How it works" side box.
 */
function tcmha_survey_howto_box() {
	?>
	<div class="tcmha-poll-howto">
		<ol>
			<li><?php esc_html_e( 'Add a title, intro, and your questions. Each question can have a picture and a file. Each choice can have a picture (for example, a logo idea) and a file.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Choose the settings, then press Publish. Publishing does not email anyone. Press Preview first to see what people will see.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Copy "Link to share" from the Answers box and send it to people (email, newsletter, etc.).', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'People answer without an account. If they leave an email, they get a private link to change their answers.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'Watch answers here in the Answers box. If you turned on "Email each answer", the "Send responses to" addresses get each one.', 'tcmha' ); ?></li>
			<li><?php esc_html_e( 'At the deadline (or when you press "Close the poll"), answers stop. The "Send responses to" addresses get the final results.', 'tcmha' ); ?></li>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: days */
						__( 'Names and emails are removed %d days after the poll closes. The answers and counts stay.', 'tcmha' ),
						TCMHA_SURVEY_SCRUB_DAYS
					)
				);
				?>
			</li>
		</ol>
		<p><?php esc_html_e( 'Changing questions after people answer: you can fix wording any time. If you remove a choice or question, answers to it stop counting.', 'tcmha' ); ?></p>
	</div>
	<?php
}

/**
 * Picture or file picker.
 *
 * @param string $name  Field name.
 * @param int    $id    Attachment ID.
 * @param string $kind  'image' or 'file'.
 * @param string $label What the picker is for (screen reader text on the buttons).
 */
function tcmha_survey_admin_picker( $name, $id, $kind, $label ) {
	$has = $id > 0;
	if ( 'image' === $kind ) {
		$choose = $has ? __( 'Change picture', 'tcmha' ) : __( 'Add picture', 'tcmha' );
	} else {
		$choose = $has ? __( 'Change file', 'tcmha' ) : __( 'Add file', 'tcmha' );
	}
	?>
	<div class="tcmha-media" data-kind="<?php echo esc_attr( $kind ); ?>">
		<input type="hidden" class="tcmha-media__id" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $has ? (int) $id : ''; ?>">
		<span class="tcmha-media__preview">
			<?php
			if ( $has && 'image' === $kind ) {
				echo wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '' ) );
				if ( '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
					echo '<span class="tcmha-media__warn">' . esc_html__( 'No description yet. Click "Change picture" and fill in "Alternative Text" so screen reader users know what it shows.', 'tcmha' ) . '</span>';
				}
			} elseif ( $has ) {
				echo '<span class="tcmha-media__name">' . esc_html( wp_basename( (string) get_attached_file( $id ) ) ) . '</span>';
			}
			?>
		</span>
		<button type="button" class="button tcmha-media__choose"><span class="tcmha-media__choose-text"><?php echo esc_html( $choose ); ?></span><span class="screen-reader-text">: <?php echo esc_html( $label ); ?></span></button>
		<button type="button" class="button-link tcmha-media__clear" <?php echo $has ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'tcmha' ); ?><span class="screen-reader-text">: <?php echo esc_html( $label ); ?></span></button>
	</div>
	<?php
}

/**
 * One choice row in the builder.
 *
 * @param string $qid Question ID (or __Q__ in the template).
 * @param array  $o   Choice.
 */
function tcmha_survey_admin_option( $qid, $o ) {
	$o    = wp_parse_args(
		$o,
		array(
			'id'    => '__O__',
			'label' => '',
			'image' => 0,
			'file'  => 0,
		)
	);
	$name = 'tcmha_survey[questions][' . $qid . '][options][' . $o['id'] . ']';
	$fid  = 'tcmha-o-' . $qid . '-' . $o['id'];
	?>
	<li class="tcmha-opt" data-o="<?php echo esc_attr( $o['id'] ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $o['id'] ); ?>">
		<div class="tcmha-opt__text">
			<label for="<?php echo esc_attr( $fid ); ?>"><?php esc_html_e( 'Choice', 'tcmha' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $o['label'] ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. Logo A: mountains', 'tcmha' ); ?>">
		</div>
		<div class="tcmha-opt__media">
			<?php tcmha_survey_admin_picker( $name . '[image]', (int) $o['image'], 'image', __( 'choice picture', 'tcmha' ) ); ?>
			<?php tcmha_survey_admin_picker( $name . '[file]', (int) $o['file'], 'file', __( 'choice file', 'tcmha' ) ); ?>
		</div>
		<div class="tcmha-opt__tools">
			<button type="button" class="button-link tcmha-o-up"><?php esc_html_e( 'Move up', 'tcmha' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(choice)', 'tcmha' ); ?></span></button>
			<button type="button" class="button-link tcmha-o-down"><?php esc_html_e( 'Move down', 'tcmha' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(choice)', 'tcmha' ); ?></span></button>
			<button type="button" class="button-link button-link-delete tcmha-o-remove"><?php esc_html_e( 'Remove choice', 'tcmha' ); ?></button>
		</div>
	</li>
	<?php
}

/**
 * One question block in the builder.
 *
 * @param array $q      Question (empty for the template).
 * @param int   $number Question number.
 */
function tcmha_survey_admin_question( $q, $number ) {
	$q    = wp_parse_args(
		$q,
		array(
			'id'       => '__Q__',
			'type'     => 'single',
			'text'     => '',
			'help'     => '',
			'required' => false,
			'max'      => 0,
			'image'    => 0,
			'file'     => 0,
			'options'  => array(),
		)
	);
	$name = 'tcmha_survey[questions][' . $q['id'] . ']';
	$fid  = 'tcmha-q-' . $q['id'];
	?>
	<div class="tcmha-q" data-q="<?php echo esc_attr( $q['id'] ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $fid ); ?>-title">
		<div class="tcmha-q__head">
			<h3 class="tcmha-q__title" id="<?php echo esc_attr( $fid ); ?>-title"><?php esc_html_e( 'Question', 'tcmha' ); ?> <span class="tcmha-q__num"><?php echo (int) $number; ?></span></h3>
			<div class="tcmha-q__tools">
				<button type="button" class="button tcmha-q-up"><?php esc_html_e( 'Move up', 'tcmha' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(question)', 'tcmha' ); ?></span></button>
				<button type="button" class="button tcmha-q-down"><?php esc_html_e( 'Move down', 'tcmha' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(question)', 'tcmha' ); ?></span></button>
				<button type="button" class="button-link button-link-delete tcmha-q-remove"><?php esc_html_e( 'Remove question', 'tcmha' ); ?></button>
			</div>
		</div>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $q['id'] ); ?>">

		<div class="tcmha-field">
			<label for="<?php echo esc_attr( $fid ); ?>-text"><?php esc_html_e( 'Question', 'tcmha' ); ?></label>
			<input type="text" class="large-text tcmha-q__text" id="<?php echo esc_attr( $fid ); ?>-text" name="<?php echo esc_attr( $name ); ?>[text]" value="<?php echo esc_attr( $q['text'] ); ?>" maxlength="300" aria-describedby="<?php echo esc_attr( $fid ); ?>-text-hint">
			<span class="description" id="<?php echo esc_attr( $fid ); ?>-text-hint"><?php esc_html_e( 'Short and plain. Example: "Which logo do you like best?" A question with no text is not saved.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field tcmha-field--row">
			<div>
				<label for="<?php echo esc_attr( $fid ); ?>-type"><?php esc_html_e( 'How people answer', 'tcmha' ); ?></label>
				<select class="tcmha-q__type" id="<?php echo esc_attr( $fid ); ?>-type" name="<?php echo esc_attr( $name ); ?>[type]">
					<option value="single" <?php selected( $q['type'], 'single' ); ?>><?php esc_html_e( 'Pick one choice', 'tcmha' ); ?></option>
					<option value="multiple" <?php selected( $q['type'], 'multiple' ); ?>><?php esc_html_e( 'Pick one or more choices', 'tcmha' ); ?></option>
					<option value="text" <?php selected( $q['type'], 'text' ); ?>><?php esc_html_e( 'Write an answer', 'tcmha' ); ?></option>
				</select>
			</div>
			<div data-show-for="multiple">
				<label for="<?php echo esc_attr( $fid ); ?>-max"><?php esc_html_e( 'Most choices allowed', 'tcmha' ); ?></label>
				<input type="number" class="small-text" id="<?php echo esc_attr( $fid ); ?>-max" name="<?php echo esc_attr( $name ); ?>[max]" min="0" max="30" value="<?php echo $q['max'] ? (int) $q['max'] : ''; ?>" aria-describedby="<?php echo esc_attr( $fid ); ?>-max-hint">
				<span class="description" id="<?php echo esc_attr( $fid ); ?>-max-hint"><?php esc_html_e( 'Example: 2 for "pick your top two". Leave blank for no limit.', 'tcmha' ); ?></span>
			</div>
			<div>
				<span class="tcmha-label"><?php esc_html_e( 'Required', 'tcmha' ); ?></span>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[required]" value="0">
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $q['required'] ); ?>> <?php esc_html_e( 'People must answer this', 'tcmha' ); ?></label>
			</div>
		</div>

		<div class="tcmha-field">
			<label for="<?php echo esc_attr( $fid ); ?>-help"><?php esc_html_e( 'More details (optional)', 'tcmha' ); ?></label>
			<textarea class="large-text" rows="2" id="<?php echo esc_attr( $fid ); ?>-help" name="<?php echo esc_attr( $name ); ?>[help]" maxlength="600" aria-describedby="<?php echo esc_attr( $fid ); ?>-help-hint"><?php echo esc_textarea( $q['help'] ); ?></textarea>
			<span class="description" id="<?php echo esc_attr( $fid ); ?>-help-hint"><?php esc_html_e( 'Shown under the question. Example: "Think about how it looks on a phone and on a T-shirt."', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<span class="tcmha-label"><?php esc_html_e( 'Picture and file for the whole question (optional)', 'tcmha' ); ?></span>
			<div class="tcmha-field--row">
				<?php tcmha_survey_admin_picker( $name . '[image]', (int) $q['image'], 'image', __( 'question picture', 'tcmha' ) ); ?>
				<?php tcmha_survey_admin_picker( $name . '[file]', (int) $q['file'], 'file', __( 'question file', 'tcmha' ) ); ?>
			</div>
			<span class="description"><?php esc_html_e( 'Example: a picture of all the logo ideas side by side, or a PDF with the full design boards.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field" data-show-for="single multiple">
			<span class="tcmha-label"><?php esc_html_e( 'Choices', 'tcmha' ); ?></span>
			<span class="description"><?php esc_html_e( 'Give every choice a short name, even when it has a picture. Example: "Logo A: mountains". A picture shows as a card people can tap. A file (like a PDF) shows as a link under it.', 'tcmha' ); ?></span>
			<ul class="tcmha-opts">
				<?php
				foreach ( $q['options'] as $o ) {
					tcmha_survey_admin_option( $q['id'], $o );
				}
				?>
			</ul>
			<button type="button" class="button tcmha-o-add"><?php esc_html_e( 'Add a choice', 'tcmha' ); ?></button>
		</div>
	</div>
	<?php
}

/**
 * Questions box: files for everyone, then the question builder.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_survey_questions_box( $post ) {
	$poll      = tcmha_survey_get( $post->ID );
	$questions = $poll['questions'];
	if ( ! $questions ) {
		$questions = array(
			array(
				'id'      => tcmha_survey_clean_id( '', 'q', array() ),
				'options' => array(
					array( 'id' => 'oa' ),
					array( 'id' => 'ob' ),
				),
			),
		);
	}
	wp_nonce_field( 'tcmha_survey_settings_' . $post->ID, 'tcmha_survey_settings_nonce' );
	?>
	<div class="tcmha-poll-admin" id="tcmha-survey-builder">
		<input type="hidden" name="tcmha_survey[builder]" value="1">
		<p class="screen-reader-text" aria-live="polite" id="tcmha-survey-live"></p>

		<h3><?php esc_html_e( 'Files to look at first (optional)', 'tcmha' ); ?></h3>
		<div class="tcmha-media tcmha-files" data-kind="files" data-name="tcmha_survey[files][]">
			<ul class="tcmha-files__list">
				<?php foreach ( $poll['files'] as $file ) : ?>
					<li>
						<input type="hidden" name="tcmha_survey[files][]" value="<?php echo (int) $file; ?>">
						<span class="tcmha-media__name"><?php echo esc_html( wp_basename( (string) get_attached_file( $file ) ) ); ?></span>
						<button type="button" class="button-link tcmha-files__remove"><?php esc_html_e( 'Remove', 'tcmha' ); ?><span class="screen-reader-text">: <?php echo esc_html( wp_basename( (string) get_attached_file( $file ) ) ); ?></span></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="button tcmha-media__choose"><?php esc_html_e( 'Add files', 'tcmha' ); ?></button>
		</div>
		<span class="description"><?php esc_html_e( 'Shown above the questions, as links. Example: a PDF that explains the logo ideas. Up to 10. Anyone with a file\'s address can open it, so don\'t add private documents.', 'tcmha' ); ?></span>

		<h3><?php esc_html_e( 'Questions', 'tcmha' ); ?></h3>
		<div class="tcmha-qs">
			<?php
			foreach ( $questions as $i => $q ) {
				tcmha_survey_admin_question( $q, $i + 1 );
			}
			?>
		</div>
		<p><button type="button" class="button button-secondary tcmha-q-add"><?php esc_html_e( 'Add a question', 'tcmha' ); ?></button></p>
	</div>
	<script type="text/html" id="tcmha-survey-q-template"><?php tcmha_survey_admin_question( array(), 0 ); ?></script>
	<script type="text/html" id="tcmha-survey-o-template"><?php tcmha_survey_admin_option( '__Q__', array() ); ?></script>
	<?php
}

/**
 * Settings box.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_survey_settings_box( $post ) {
	$poll = tcmha_survey_get( $post->ID );
	?>
	<div class="tcmha-poll-admin">
		<h3><?php esc_html_e( 'Who can answer', 'tcmha' ); ?></h3>

		<fieldset class="tcmha-field">
			<legend class="tcmha-label"><?php esc_html_e( 'Who can open the poll', 'tcmha' ); ?></legend>
			<label><input type="radio" name="tcmha_survey[access]" value="link" <?php checked( $poll['access'], 'link' ); ?>> <?php esc_html_e( 'Only people with the link (private)', 'tcmha' ); ?></label><br>
			<label><input type="radio" name="tcmha_survey[access]" value="public" <?php checked( $poll['access'], 'public' ); ?>> <?php esc_html_e( 'Anyone (public page)', 'tcmha' ); ?></label>
			<span class="description"><?php esc_html_e( 'Private: for a group, like the advisory board. Public: anyone who finds the page can answer, and search engines may list it. Before launch, the site\'s Coming soon page still blocks public visitors, so share the link either way.', 'tcmha' ); ?></span>
		</fieldset>

		<div class="tcmha-field">
			<label for="tcmha-survey-ask-name"><?php esc_html_e( 'Ask for a name', 'tcmha' ); ?></label>
			<select id="tcmha-survey-ask-name" name="tcmha_survey[ask_name]" aria-describedby="tcmha-survey-ask-name-hint">
				<option value="optional" <?php selected( $poll['ask_name'], 'optional' ); ?>><?php esc_html_e( 'Yes, but people can skip it', 'tcmha' ); ?></option>
				<option value="required" <?php selected( $poll['ask_name'], 'required' ); ?>><?php esc_html_e( 'Yes, required', 'tcmha' ); ?></option>
				<option value="none" <?php selected( $poll['ask_name'], 'none' ); ?>><?php esc_html_e( 'No, answers are anonymous', 'tcmha' ); ?></option>
			</select>
			<span class="description" id="tcmha-survey-ask-name-hint"><?php esc_html_e( 'Required is good for a vote by a known group (you can see who answered). Anonymous is best for public polls and honest feedback. Names only show to organizers, never on the poll page.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<span class="tcmha-label"><?php esc_html_e( 'Email', 'tcmha' ); ?></span>
			<input type="hidden" name="tcmha_survey[ask_email]" value="0">
			<label><input type="checkbox" name="tcmha_survey[ask_email]" value="1" <?php checked( $poll['ask_email'] ); ?> aria-describedby="tcmha-survey-ask-email-hint"> <?php esc_html_e( 'Let people leave an email (always optional for them)', 'tcmha' ); ?></label>
			<span class="description" id="tcmha-survey-ask-email-hint"><?php esc_html_e( 'Used only to email them a private link to change their answers. Turn off to collect less personal information.', 'tcmha' ); ?></span>
		</div>

		<fieldset class="tcmha-field">
			<legend class="tcmha-label"><?php esc_html_e( 'Who sees the results', 'tcmha' ); ?></legend>
			<label><input type="radio" name="tcmha_survey[results]" value="after" <?php checked( $poll['results'], 'after' ); ?>> <?php esc_html_e( 'People see results after they answer', 'tcmha' ); ?></label><br>
			<label><input type="radio" name="tcmha_survey[results]" value="closed" <?php checked( $poll['results'], 'closed' ); ?>> <?php esc_html_e( 'People see results after the poll closes', 'tcmha' ); ?></label><br>
			<label><input type="radio" name="tcmha_survey[results]" value="private" <?php checked( $poll['results'], 'private' ); ?>> <?php esc_html_e( 'Only organizers see results', 'tcmha' ); ?></label>
			<span class="description"><?php esc_html_e( 'For a logo poll, "after the poll closes" keeps early votes from swaying later ones. The page shows counts and percents only. Names and written answers stay private.', 'tcmha' ); ?></span>
		</fieldset>

		<h3><?php esc_html_e( 'Deadline and emails', 'tcmha' ); ?></h3>

		<div class="tcmha-field">
			<label for="tcmha-survey-deadline"><?php esc_html_e( 'Answer by (deadline)', 'tcmha' ); ?></label>
			<input type="datetime-local" id="tcmha-survey-deadline" name="tcmha_survey[deadline]" value="<?php echo esc_attr( str_replace( ' ', 'T', $poll['deadline'] ) ); ?>" aria-describedby="tcmha-survey-deadline-hint">
			<span class="description" id="tcmha-survey-deadline-hint"><?php esc_html_e( 'Mountain Time. Example: Dec 18, 2026 at 11:59 PM for "end of day Friday". Answers close then, and the final results are emailed to the addresses below (within about an hour). Leave blank to keep the poll open until you close it.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<label for="tcmha-survey-send-to"><?php esc_html_e( 'Send responses to', 'tcmha' ); ?></label>
			<input type="text" id="tcmha-survey-send-to" name="tcmha_survey[send_to]" value="<?php echo esc_attr( implode( ', ', $poll['send_to'] ) ); ?>" class="large-text" placeholder="name@example.org, another@example.org" aria-describedby="tcmha-survey-send-to-hint" autocomplete="off">
			<span class="description" id="tcmha-survey-send-to-hint"><?php esc_html_e( 'Who runs this poll. Separate several addresses with commas (up to 10). Example: admin@tcmha.org, you@example.org. These addresses get the final results, and replies to poll emails come here. Leave blank for no emails.', 'tcmha' ); ?></span>
		</div>

		<div class="tcmha-field">
			<span class="tcmha-label"><?php esc_html_e( 'Email each answer', 'tcmha' ); ?></span>
			<input type="hidden" name="tcmha_survey[notify_each]" value="0">
			<label><input type="checkbox" name="tcmha_survey[notify_each]" value="1" <?php checked( $poll['notify_each'] ); ?> aria-describedby="tcmha-survey-notify-hint"> <?php esc_html_e( 'Also email the addresses above every time someone answers', 'tcmha' ); ?></label>
			<span class="description" id="tcmha-survey-notify-hint"><?php esc_html_e( 'Good for small groups. Turn off for a big public poll so your inbox doesn\'t fill up.', 'tcmha' ); ?></span>
		</div>
	</div>
	<?php
}

add_action(
	'save_post_tcmha_survey',
	static function ( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['tcmha_survey_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_survey_settings_nonce'] ) ), 'tcmha_survey_settings_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['tcmha_survey'] ) || ! is_array( $_POST['tcmha_survey'] ) ) {
			return;
		}
		// Sanitized field by field in tcmha_survey_save_settings().
		$input = wp_unslash( $_POST['tcmha_survey'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $input['builder'] ) ) {
			// Removing every question or file posts nothing for it, so default to empty.
			$input += array(
				'questions' => array(),
				'files'     => array(),
			);
		}
		unset( $input['builder'], $input['closed'] );
		tcmha_survey_save_settings( $post_id, $input );
	}
);

/**
 * Admin action URL with a nonce.
 *
 * @param string $action  Action name.
 * @param int    $post_id Poll ID.
 * @return string
 */
function tcmha_survey_action_url( $action, $post_id ) {
	$url = add_query_arg(
		array(
			'action' => 'tcmha_survey_' . $action,
			'post'   => $post_id,
		),
		admin_url( 'admin-post.php' )
	);
	return wp_nonce_url( $url, 'tcmha_survey_' . $action . '_' . $post_id );
}

/**
 * Answers box: links, status, actions, and results with names.
 *
 * @param WP_Post $post Poll.
 */
function tcmha_survey_results_box( $post ) {
	$poll      = tcmha_survey_get( $post->ID );
	$responses = tcmha_survey_responses( $post->ID );
	$tally     = tcmha_survey_tally( $poll, $responses );
	$closed    = tcmha_survey_is_closed( $poll );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
	$notice = isset( $_GET['tcmha_survey_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['tcmha_survey_notice'] ) ) : '';
	if ( $notice ) {
		printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $notice ) );
	}

	if ( 'publish' !== $post->post_status ) {
		echo '<p>' . esc_html__( 'Publish this poll to get its link. Use Preview to see it first.', 'tcmha' ) . '</p>';
	} else {
		?>
		<p>
			<label for="tcmha-survey-share"><strong><?php esc_html_e( 'Link to share', 'tcmha' ); ?></strong></label><br>
			<input type="text" id="tcmha-survey-share" class="large-text code" readonly value="<?php echo esc_attr( tcmha_survey_share_url( $post->ID ) ); ?>" onfocus="this.select()">
			<span class="description">
				<?php
				echo 'public' === $poll['access']
					? esc_html__( 'This poll is public, so the plain page address works too.', 'tcmha' )
					: esc_html__( 'Only people with this link can open the poll.', 'tcmha' );
				?>
			</span>
		</p>
		<p>
			<label for="tcmha-survey-embed"><strong><?php esc_html_e( 'Show it inside another page', 'tcmha' ); ?></strong></label><br>
			<input type="text" id="tcmha-survey-embed" class="regular-text code" readonly value="<?php echo esc_attr( '[tcmha_poll id="' . $post->ID . '"]' ); ?>" onfocus="this.select()">
			<span class="description"><?php esc_html_e( 'Paste this into a Shortcode element on any page. Everyone who can see that page can answer, even if the poll is private.', 'tcmha' ); ?></span>
		</p>
		<?php
	}
	?>
	<p class="tcmha-survey-status">
		<strong><?php echo $closed ? esc_html__( 'Closed.', 'tcmha' ) : esc_html__( 'Open.', 'tcmha' ); ?></strong>
		<?php
		$sent_at = (int) get_post_meta( $post->ID, '_tcmha_survey_results_sent', true );
		if ( $sent_at ) {
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'Final results emailed %s.', 'tcmha' ), wp_date( 'M j, g:i A', $sent_at, tcmha_survey_timezone( $poll ) ) ) );
		} elseif ( ! $closed && '' !== $poll['deadline'] ) {
			/* translators: %s: deadline */
			echo esc_html( sprintf( __( 'Closes %s. Final results are emailed then.', 'tcmha' ), tcmha_survey_deadline_label( $poll ) ) );
		}
		?>
	</p>
	<p class="tcmha-survey-actions">
		<?php if ( $closed ) : ?>
			<?php if ( $poll['closed'] || ! tcmha_survey_deadline_passed( $poll ) ) : ?>
				<a class="button" href="<?php echo esc_url( tcmha_survey_action_url( 'reopen', $post->ID ) ); ?>"><?php esc_html_e( 'Reopen the poll', 'tcmha' ); ?></a>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'To reopen, change or clear the deadline in Poll settings and press Update.', 'tcmha' ); ?></span>
			<?php endif; ?>
		<?php elseif ( 'publish' === $post->post_status ) : ?>
			<a class="button" href="<?php echo esc_url( tcmha_survey_action_url( 'close', $post->ID ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Close the poll now? People can no longer answer. Final results are emailed to "Send responses to".', 'tcmha' ) ) ); ?>);"><?php esc_html_e( 'Close the poll', 'tcmha' ); ?></a>
		<?php endif; ?>
		<?php if ( $poll['send_to'] && $responses ) : ?>
			<a class="button" href="<?php echo esc_url( tcmha_survey_action_url( 'email', $post->ID ) ); ?>"><?php esc_html_e( 'Email the results now', 'tcmha' ); ?></a>
		<?php endif; ?>
		<?php if ( $responses ) : ?>
			<a class="button" href="<?php echo esc_url( tcmha_survey_action_url( 'csv', $post->ID ) ); ?>"><?php esc_html_e( 'Download answers (spreadsheet)', 'tcmha' ); ?></a>
			<a class="button-link-delete" href="<?php echo esc_url( tcmha_survey_action_url( 'delete', $post->ID ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete all answers? This cannot be undone.', 'tcmha' ) ) ); ?>);"><?php esc_html_e( 'Delete all answers', 'tcmha' ); ?></a>
		<?php endif; ?>
	</p>
	<?php
	if ( ! $poll['questions'] ) {
		echo '<p>' . esc_html__( 'Add questions above, then press Update.', 'tcmha' ) . '</p>';
		return;
	}
	?>
	<p>
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
	<?php foreach ( $poll['questions'] as $n => $q ) : ?>
		<?php $row = $tally['questions'][ $q['id'] ]; ?>
		<h4 class="tcmha-survey-result-title"><?php echo esc_html( ( $n + 1 ) . '. ' . $q['text'] ); ?></h4>
		<?php if ( 'text' === $q['type'] ) : ?>
			<?php if ( ! $row['texts'] ) : ?>
				<p><?php esc_html_e( 'No written answers yet.', 'tcmha' ); ?></p>
			<?php else : ?>
				<ul class="tcmha-survey-texts">
					<?php foreach ( $row['texts'] as $text ) : ?>
						<li><strong><?php echo esc_html( '' !== $text['name'] ? $text['name'] : __( 'No name', 'tcmha' ) ); ?>:</strong> <?php echo nl2br( esc_html( $text['text'] ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<table class="widefat striped tcmha-survey-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Choice', 'tcmha' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Picked by', 'tcmha' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Names', 'tcmha' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $q['options'] as $o ) : ?>
						<?php
						$votes = $row['options'][ $o['id'] ];
						$pct   = $row['answered'] ? round( 100 * $votes / $row['answered'] ) : 0;
						$names = array_filter( $row['names'][ $o['id'] ] );
						$anon  = count( $row['names'][ $o['id'] ] ) - count( $names );
						?>
						<tr>
							<th scope="row">
								<?php
								if ( $o['image'] ) {
									echo wp_get_attachment_image( $o['image'], array( 48, 48 ), false, array( 'alt' => '' ) ) . ' ';
								}
								echo esc_html( $o['label'] );
								if ( in_array( $o['id'], $row['top'], true ) ) {
									echo '<br><strong>' . esc_html__( 'Most picked', 'tcmha' ) . '</strong>';
								}
								?>
							</th>
							<td><strong><?php echo (int) $votes; ?></strong> (<?php echo (int) $pct; ?>%)</td>
							<td>
								<?php
								echo esc_html( implode( ', ', $names ) );
								if ( $anon ) {
									/* translators: %d: number of people without a name */
									echo esc_html( ( $names ? ', ' : '' ) . sprintf( _n( '%d with no name', '%d with no name', $anon, 'tcmha' ), $anon ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endforeach; ?>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: days */
				__( 'Names and emails are removed %d days after the poll closes. Answers and counts stay.', 'tcmha' ),
				TCMHA_SURVEY_SCRUB_DAYS
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
function tcmha_survey_admin_guard( $action ) {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
	check_admin_referer( 'tcmha_survey_' . $action . '_' . $post_id );
	if ( ! $post_id || 'tcmha_survey' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You cannot change this poll.', 'tcmha' ), 403 );
	}
	return tcmha_survey_get( $post_id );
}

/**
 * Back to the poll's edit screen with a notice.
 *
 * @param int    $post_id Poll ID.
 * @param string $notice  Message.
 */
function tcmha_survey_admin_back( $post_id, $notice ) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'post'                => $post_id,
				'action'              => 'edit',
				'tcmha_survey_notice' => rawurlencode( $notice ),
			),
			admin_url( 'post.php' )
		)
	);
	exit;
}

add_action(
	'admin_post_tcmha_survey_close',
	static function () {
		$poll = tcmha_survey_admin_guard( 'close' );
		tcmha_survey_set_closed( $poll, true );
		tcmha_survey_store( $poll['id'], $poll );
		$sent = tcmha_survey_mail_results( $poll );
		tcmha_survey_admin_back( $poll['id'], $sent ? __( 'The poll is closed. Final results emailed.', 'tcmha' ) : __( 'The poll is closed.', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_survey_reopen',
	static function () {
		$poll = tcmha_survey_admin_guard( 'reopen' );
		tcmha_survey_set_closed( $poll, false );
		tcmha_survey_store( $poll['id'], $poll );
		delete_post_meta( $poll['id'], '_tcmha_survey_results_sent' );
		$notice = tcmha_survey_deadline_passed( $poll ) ? __( 'Reopened, but the deadline has passed. Change or clear it in Poll settings.', 'tcmha' ) : __( 'The poll is open again.', 'tcmha' );
		tcmha_survey_admin_back( $poll['id'], $notice );
	}
);

add_action(
	'admin_post_tcmha_survey_email',
	static function () {
		$poll = tcmha_survey_admin_guard( 'email' );
		$sent = tcmha_survey_mail_results( $poll );
		tcmha_survey_admin_back( $poll['id'], $sent ? __( 'Results emailed.', 'tcmha' ) : __( 'The results could not be emailed. Check "Send responses to".', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_survey_delete',
	static function () {
		$poll = tcmha_survey_admin_guard( 'delete' );
		delete_post_meta( $poll['id'], TCMHA_SURVEY_RESPONSE_KEY );
		tcmha_survey_admin_back( $poll['id'], __( 'All answers deleted.', 'tcmha' ) );
	}
);

add_action(
	'admin_post_tcmha_survey_csv',
	static function () {
		$poll = tcmha_survey_admin_guard( 'csv' );
		$tz   = tcmha_survey_timezone( $poll );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="poll-' . $poll['id'] . '-answers.csv"' );

		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$head = array( 'Name', 'Email', 'Last changed' );
		foreach ( $poll['questions'] as $q ) {
			$head[] = tcmha_poll_csv_safe( $q['text'] );
		}
		fputcsv( $out, $head );
		foreach ( tcmha_survey_responses( $poll['id'] ) as $row ) {
			$line = array(
				tcmha_poll_csv_safe( $row['name'] ?? '' ),
				tcmha_poll_csv_safe( $row['email'] ?? '' ),
				wp_date( 'Y-m-d H:i', (int) $row['updated'], $tz ),
			);
			foreach ( $poll['questions'] as $q ) {
				$answer = $row['answers'][ $q['id'] ] ?? null;
				if ( null === $answer ) {
					$line[] = '';
				} elseif ( 'text' === $q['type'] ) {
					$line[] = tcmha_poll_csv_safe( (string) $answer );
				} else {
					$line[] = tcmha_poll_csv_safe( implode( '; ', tcmha_survey_answer_labels( $q, $answer ) ) );
				}
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);

/**
 * Poll data for the REST API (editors only).
 *
 * @param int  $post_id   Poll ID.
 * @param bool $responses Include individual answers.
 * @return array
 */
function tcmha_survey_rest_data( $post_id, $responses = true ) {
	$poll   = tcmha_survey_get( $post_id );
	$rows   = tcmha_survey_responses( $post_id );
	$public = 'publish' === get_post_status( $post_id );
	$data   = array(
		'id'           => $post_id,
		'title'        => get_the_title( $post_id ),
		'status'       => get_post_status( $post_id ),
		'url'          => $public ? tcmha_survey_share_url( $post_id ) : '',
		'shortcode'    => '[tcmha_poll id="' . $post_id . '"]',
		'settings'     => array_diff_key( $poll, array_flip( array( 'id', 'key' ) ) ),
		'is_closed'    => tcmha_survey_is_closed( $poll ),
		'results_sent' => (int) get_post_meta( $post_id, '_tcmha_survey_results_sent', true ),
		'total'        => count( $rows ),
		'tally'        => tcmha_survey_tally( $poll, $rows )['questions'],
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
		$fields = array( 'questions', 'files', 'access', 'ask_name', 'ask_email', 'results', 'deadline', 'tz', 'send_to', 'notify_each', 'closed' );
		$args   = array(
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string' ),
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'draft', 'publish' ),
			),
			'questions'   => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'files'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'access'      => array(
				'type' => 'string',
				'enum' => array( 'link', 'public' ),
			),
			'ask_name'    => array(
				'type' => 'string',
				'enum' => array( 'required', 'optional', 'none' ),
			),
			'ask_email'   => array( 'type' => 'boolean' ),
			'results'     => array(
				'type' => 'string',
				'enum' => array( 'after', 'closed', 'private' ),
			),
			'deadline'    => array( 'type' => 'string' ),
			'tz'          => array( 'type' => 'string' ),
			'send_to'     => array(
				'type'  => array( 'array', 'string' ),
				'items' => array( 'type' => 'string' ),
			),
			'notify_each' => array( 'type' => 'boolean' ),
			'closed'      => array( 'type' => 'boolean' ),
		);

		$save = static function ( WP_REST_Request $request, $post_id ) use ( $fields ) {
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
			foreach ( $fields as $field ) {
				if ( null !== $request[ $field ] ) {
					$input[ $field ] = $request[ $field ];
				}
			}
			tcmha_survey_save_settings( $post_id, $input );
			return rest_ensure_response( tcmha_survey_rest_data( $post_id ) );
		};

		$can_edit = static function ( WP_REST_Request $request ) {
			return 'tcmha_survey' === get_post_type( (int) $request['id'] ) && current_user_can( 'edit_post', (int) $request['id'] );
		};

		register_rest_route(
			'tcmha/v1',
			'/surveys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'callback'            => static function () {
						$ids = get_posts(
							array(
								'post_type'      => 'tcmha_survey',
								'post_status'    => array( 'publish', 'draft' ),
								'posts_per_page' => 50,
								'fields'         => 'ids',
							)
						);
						return rest_ensure_response(
							array_map(
								static function ( $id ) {
									return tcmha_survey_rest_data( $id, false );
								},
								$ids
							)
						);
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'args'                => $args,
					'permission_callback' => static function () {
						return current_user_can( 'publish_posts' );
					},
					'callback'            => static function ( WP_REST_Request $request ) use ( $save ) {
						$post_id = wp_insert_post(
							array(
								'post_type'   => 'tcmha_survey',
								'post_title'  => sanitize_text_field( (string) ( $request['title'] ?? __( 'Poll', 'tcmha' ) ) ),
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

		register_rest_route(
			'tcmha/v1',
			'/surveys/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $can_edit,
					'callback'            => static function ( WP_REST_Request $request ) {
						return rest_ensure_response( tcmha_survey_rest_data( (int) $request['id'] ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'args'                => $args,
					'permission_callback' => $can_edit,
					'callback'            => static function ( WP_REST_Request $request ) use ( $save ) {
						return $save( $request, (int) $request['id'] );
					},
				),
			)
		);

		// Delete all answers (for clearing test answers).
		register_rest_route(
			'tcmha/v1',
			'/surveys/(?P<id>\d+)/responses',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => $can_edit,
				'callback'            => static function ( WP_REST_Request $request ) {
					delete_post_meta( (int) $request['id'], TCMHA_SURVEY_RESPONSE_KEY );
					return rest_ensure_response( tcmha_survey_rest_data( (int) $request['id'] ) );
				},
			)
		);
	}
);
