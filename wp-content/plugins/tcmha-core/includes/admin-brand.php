<?php
/**
 * Admin look and feel: branded login, TCMHA admin colors and buttons, a simpler menu for
 * editors, a welcome dashboard, and a helpful footer.
 *
 * Simple admin: on by default for anyone who is not an administrator. Administrators can turn
 * it on for themselves (Profile > "Simple admin") to see what editors see. Hiding a menu only
 * tidies the screen; WordPress capabilities still decide what each person can do.
 *
 * @package TCMHA_Core
 */

defined( 'ABSPATH' ) || exit;

const TCMHA_ADMIN_SCHEME = 'tcmha';

/**
 * Who people should contact for help with the website. Filterable; defaults to the site email.
 *
 * @return string
 */
function tcmha_admin_support_email() {
	return (string) apply_filters( 'tcmha_admin_support_email', get_option( 'admin_email' ) );
}

/**
 * Whether the current user sees the simplified admin.
 *
 * @return bool
 */
function tcmha_admin_is_simple() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$pref = get_user_meta( $user_id, 'tcmha_simple_admin', true );
	if ( 'on' === $pref ) {
		return true;
	}
	if ( 'off' === $pref && current_user_can( 'manage_options' ) ) {
		return false;
	}
	return ! current_user_can( 'manage_options' );
}

/**
 * True on the Elementor editor screen, which has its own interface we leave alone.
 *
 * @return bool
 */
function tcmha_admin_is_elementor_editor() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	return isset( $_GET['action'] ) && 'elementor' === sanitize_key( wp_unslash( $_GET['action'] ) );
}

/* ---------------------------------------------------------------------------------------------
 * Login page
 * ------------------------------------------------------------------------------------------- */

add_action(
	'login_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'tcmha-admin', TCMHA_CORE_URL . 'assets/css/admin.css', array(), TCMHA_CORE_VERSION );
	}
);

add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
add_filter( 'login_headertext', static fn() => __( 'Teller County Mental Health Alliance', 'tcmha' ) );

add_filter(
	'login_title',
	static function () {
		return __( 'Log in · Teller County Mental Health Alliance', 'tcmha' );
	}
);

// Crisis numbers on the login page, in case someone lands here needing help.
add_filter(
	'login_message',
	static function ( $message ) {
		$crisis  = '<div class="tcmha-login-help" role="note">';
		$crisis .= '<p><strong>' . esc_html__( 'Need help right now?', 'tcmha' ) . '</strong></p>';
		$crisis .= '<p><a href="tel:988">' . esc_html__( 'Call or text 988', 'tcmha' ) . '</a> · ';
		$crisis .= '<a href="tel:911">' . esc_html__( 'Call 911 if you are in danger', 'tcmha' ) . '</a></p>';
		$crisis .= '</div>';
		return $crisis . $message;
	}
);

// The language picker under the form adds clutter; the site is English with Google Translate.
add_filter( 'login_display_language_dropdown', '__return_false' );

/* ---------------------------------------------------------------------------------------------
 * Admin colors, buttons, and font
 * ------------------------------------------------------------------------------------------- */

add_action(
	'admin_init',
	static function () {
		wp_admin_css_color(
			TCMHA_ADMIN_SCHEME,
			__( 'TCMHA', 'tcmha' ),
			TCMHA_CORE_URL . 'assets/css/admin-colors.css?v=' . TCMHA_CORE_VERSION,
			array( '#625778', '#f4f1f8', '#ccbfdd', '#f2a65a' ),
			array(
				'base'    => '#625778',
				'focus'   => '#ffffff',
				'current' => '#ffffff',
			)
		);
	},
	1
);

// Everyone gets the TCMHA colors unless they picked a different scheme on purpose.
add_filter(
	'get_user_option_admin_color',
	static function ( $color ) {
		return ( empty( $color ) || 'fresh' === $color ) ? TCMHA_ADMIN_SCHEME : $color;
	}
);

add_action(
	'admin_enqueue_scripts',
	static function () {
		if ( tcmha_admin_is_elementor_editor() ) {
			return;
		}
		wp_enqueue_style( 'tcmha-admin', TCMHA_CORE_URL . 'assets/css/admin.css', array(), TCMHA_CORE_VERSION );
	}
);

/* ---------------------------------------------------------------------------------------------
 * Simple admin: menus and toolbar
 * ------------------------------------------------------------------------------------------- */

add_filter( 'custom_menu_order', '__return_true' );

// Most-used items first. Anything not listed keeps its place after these.
add_filter(
	'menu_order',
	static function () {
		return array(
			'index.php',
			'separator1',
			'edit.php?post_type=page',
			'edit.php?post_type=tcmha_resource',
			'edit.php?post_type=tcmha_provider',
			'edit.php?post_type=tcmha_glossary',
			'edit.php?post_type=tcmha_poll',
			'edit.php',
			'upload.php',
		);
	}
);

add_action(
	'admin_menu',
	static function () {
		if ( ! tcmha_admin_is_simple() ) {
			return;
		}
		$hide = array(
			'edit-comments.php',
			'tools.php',
			'edit.php?post_type=elementor_library',
			'themes.php',
			'plugins.php',
			'users.php',
			'options-general.php',
			'elementor',
			'elementor-home',
		);
		foreach ( $hide as $slug ) {
			remove_menu_page( $slug );
		}
	},
	999
);

add_action(
	'admin_bar_menu',
	static function ( WP_Admin_Bar $bar ) {
		$bar->remove_node( 'wp-logo' );
		if ( tcmha_admin_is_simple() ) {
			$bar->remove_node( 'comments' );
			$bar->remove_node( 'updates' );
		}
	},
	999
);

// Editors can't act on update notices, so don't show them.
add_action(
	'admin_head',
	static function () {
		if ( tcmha_admin_is_simple() && ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
		}
	},
	1
);

// Profile setting so administrators can preview (or editors can turn off) the simple admin.
function tcmha_admin_profile_field( $user ) {
	$pref = get_user_meta( $user->ID, 'tcmha_simple_admin', true );
	$on   = 'on' === $pref || ( '' === $pref && ! user_can( $user, 'manage_options' ) );
	?>
	<h2><?php esc_html_e( 'Simple admin', 'tcmha' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Menu', 'tcmha' ); ?></th>
			<td>
				<?php wp_nonce_field( 'tcmha_simple_admin', 'tcmha_simple_admin_nonce' ); ?>
				<label for="tcmha_simple_admin">
					<input type="checkbox" name="tcmha_simple_admin" id="tcmha_simple_admin" value="on" <?php checked( $on ); ?> />
					<?php esc_html_e( 'Show the simple menu (hides settings and tools most editors don\'t need)', 'tcmha' ); ?>
				</label>
				<?php if ( user_can( $user, 'manage_options' ) ) : ?>
					<p class="description"><?php esc_html_e( 'Turn this on to see the admin the way editors see it. Your access does not change.', 'tcmha' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'tcmha_admin_profile_field' );
add_action( 'edit_user_profile', 'tcmha_admin_profile_field' );

function tcmha_admin_profile_save( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['tcmha_simple_admin_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tcmha_simple_admin_nonce'] ) ), 'tcmha_simple_admin' ) ) {
		return;
	}
	update_user_meta( $user_id, 'tcmha_simple_admin', isset( $_POST['tcmha_simple_admin'] ) ? 'on' : 'off' );
}
add_action( 'personal_options_update', 'tcmha_admin_profile_save' );
add_action( 'edit_user_profile_update', 'tcmha_admin_profile_save' );

/* ---------------------------------------------------------------------------------------------
 * Dashboard
 * ------------------------------------------------------------------------------------------- */

add_action(
	'wp_dashboard_setup',
	static function () {
		// News feeds and quick-draft boxes add noise for everyone.
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		if ( tcmha_admin_is_simple() ) {
			remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
			remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
			remove_meta_box( 'e-dashboard-overview', 'dashboard', 'normal' );
		}

		wp_add_dashboard_widget( 'tcmha_welcome', __( 'Welcome to the TCMHA website', 'tcmha' ), 'tcmha_admin_welcome_widget' );
		wp_add_dashboard_widget( 'tcmha_waiting', __( 'Waiting for review', 'tcmha' ), 'tcmha_admin_waiting_widget' );

		// Put our boxes at the top of the first column.
		global $wp_meta_boxes;
		foreach ( array( 'tcmha_welcome', 'tcmha_waiting' ) as $order => $id ) {
			if ( isset( $wp_meta_boxes['dashboard']['normal']['core'][ $id ] ) ) {
				$box = $wp_meta_boxes['dashboard']['normal']['core'][ $id ];
				unset( $wp_meta_boxes['dashboard']['normal']['core'][ $id ] );
				$wp_meta_boxes['dashboard']['normal']['high'][ $id ] = $box; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	},
	999
);

/**
 * Quick links the current user is allowed to use.
 *
 * @return array<int, array{label: string, url: string, primary?: bool}>
 */
function tcmha_admin_quick_links() {
	$links = array();
	$types = array(
		'page'           => __( 'Add a page', 'tcmha' ),
		'tcmha_resource' => __( 'Add a resource', 'tcmha' ),
		'tcmha_provider' => __( 'Add a provider', 'tcmha' ),
		'tcmha_glossary' => __( 'Add a glossary word', 'tcmha' ),
		'tcmha_poll'     => __( 'Start a meeting poll', 'tcmha' ),
	);
	foreach ( $types as $type => $label ) {
		$obj = get_post_type_object( $type );
		if ( $obj && current_user_can( $obj->cap->create_posts ) ) {
			$links[] = array(
				'label' => $label,
				'url'   => admin_url( 'post-new.php' . ( 'post' === $type ? '' : '?post_type=' . $type ) ),
			);
		}
	}
	if ( current_user_can( 'upload_files' ) ) {
		$links[] = array(
			'label' => __( 'Add a photo or file', 'tcmha' ),
			'url'   => admin_url( 'media-new.php' ),
		);
	}
	return apply_filters( 'tcmha_admin_quick_links', $links );
}

function tcmha_admin_welcome_widget() {
	$user  = wp_get_current_user();
	$links = tcmha_admin_quick_links();
	$email = tcmha_admin_support_email();
	?>
	<div class="tcmha-welcome">
		<p class="tcmha-welcome__hello">
			<?php
			/* translators: %s: the person's display name. */
			echo esc_html( sprintf( __( 'Hi, %s. What would you like to do?', 'tcmha' ), $user->display_name ) );
			?>
		</p>
		<?php if ( $links ) : ?>
			<ul class="tcmha-welcome__links">
				<?php foreach ( $links as $i => $link ) : ?>
					<li><a class="button <?php echo 0 === $i ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<ul class="tcmha-welcome__more">
			<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'See the website', 'tcmha' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Find a page to change', 'tcmha' ); ?></a></li>
			<?php if ( $email ) : ?>
				<li>
					<?php esc_html_e( 'Stuck?', 'tcmha' ); ?>
					<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php esc_html_e( 'Email for website help', 'tcmha' ); ?></a>
				</li>
			<?php endif; ?>
		</ul>
	</div>
	<?php
}

/**
 * Content types whose pending items show in "Waiting for review".
 *
 * @return string[]
 */
function tcmha_admin_review_types() {
	return apply_filters( 'tcmha_admin_review_types', array( 'page', 'post', 'tcmha_resource', 'tcmha_provider', 'tcmha_glossary' ) );
}

function tcmha_admin_waiting_widget() {
	$types = array_values(
		array_filter(
			tcmha_admin_review_types(),
			static function ( $type ) {
				$obj = get_post_type_object( $type );
				return $obj && current_user_can( $obj->cap->edit_others_posts );
			}
		)
	);

	if ( ! $types ) {
		echo '<p>' . esc_html__( 'Nothing for you to review.', 'tcmha' ) . '</p>';
		return;
	}

	$query = new WP_Query(
		array(
			'post_type'      => $types,
			'post_status'    => 'pending',
			'posts_per_page' => 5,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		)
	);

	if ( ! $query->have_posts() ) {
		echo '<p class="tcmha-waiting__none">' . esc_html__( 'All caught up. Nothing is waiting for review.', 'tcmha' ) . '</p>';
		return;
	}

	echo '<p>' . esc_html(
		sprintf(
			/* translators: %d: number of items waiting. */
			_n( '%d item is waiting for someone to check it and publish it.', '%d items are waiting for someone to check them and publish them.', $query->found_posts, 'tcmha' ),
			$query->found_posts
		)
	) . '</p>';
	echo '<ul class="tcmha-waiting">';
	foreach ( $query->posts as $post ) {
		$obj = get_post_type_object( $post->post_type );
		printf(
			'<li><a href="%1$s">%2$s</a> <span class="tcmha-waiting__type">%3$s</span></li>',
			esc_url( (string) get_edit_post_link( $post->ID ) ),
			esc_html( get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'tcmha' ) ),
			esc_html( $obj ? $obj->labels->singular_name : '' )
		);
	}
	echo '</ul>';
	wp_reset_postdata();
}

/* ---------------------------------------------------------------------------------------------
 * Footer
 * ------------------------------------------------------------------------------------------- */

add_filter(
	'admin_footer_text',
	static function () {
		$email = tcmha_admin_support_email();
		$text  = esc_html__( 'Teller County Mental Health Alliance website.', 'tcmha' );
		if ( $email ) {
			$text .= ' ' . esc_html__( 'Need help?', 'tcmha' ) . ' <a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
		}
		return '<span class="tcmha-admin-footer">' . $text . '</span>';
	}
);

add_filter(
	'update_footer',
	static function ( $text ) {
		return tcmha_admin_is_simple() ? '' : $text;
	},
	999
);
