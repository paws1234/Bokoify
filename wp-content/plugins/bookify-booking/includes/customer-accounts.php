<?php
/**
 * Customer accounts, for the customers who want one (T22, plan D14).
 *
 * Accounts are an opt-in, and the plan is complete without them: the magic link does everything by
 * itself, and everything here is a convenience on top of it. Two rules shape the whole file.
 *
 *   1. A booking is attached to a user only when that user *is* the person booking. A booking made
 *      while signed in belongs to that customer; a booking made while signed out belongs to nobody
 *      until the customer claims it with their own link. The email address is never used to find a
 *      user, or anyone could plant a row in a stranger's account by typing their address.
 *   2. A `subscriber` and nothing more. No custom role, no new capability, and every query on the
 *      My bookings page is filtered by the current user id rather than by anything in the URL.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The copy the account offer and the My bookings page print.
 *
 * Its own list, like the manage page's: the booking form's keys are what the Elementor widget
 * offers controls for, and these strings are never printed by that form.
 *
 * @return array<string,string>
 */
function bookify_customer_accounts_defaults() {
	return array(
		'offer_title'   => __( 'Want an account?', 'bookify-booking' ),
		'offer_intro'   => __( 'Create one and you can see all your bookings in one place. We will email you a link to set your own password.', 'bookify-booking' ),
		'offer_label'   => __( 'Create my account', 'bookify-booking' ),
		'created'       => __( 'Your account is ready. Check your email for the link that sets your password.', 'bookify-booking' ),
		'exists'        => __( 'That address already has an account, so nothing was created. Please sign in instead — your booking is already waiting there.', 'bookify-booking' ),
		'my_title'      => __( 'Your bookings', 'bookify-booking' ),
		'my_none'       => __( 'You have no bookings yet.', 'bookify-booking' ),
		'my_self'       => __( 'These are the bookings made while you were signed in.', 'bookify-booking' ),
		'sign_in'       => __( 'Sign in to see your bookings.', 'bookify-booking' ),
		'sign_in_label' => __( 'Sign in', 'bookify-booking' ),
		'book_label'    => __( 'Book an appointment', 'bookify-booking' ),
	);
}

/**
 * The booking a request has proved it owns, or null.
 *
 * The same test every manage request goes through: a reference to look the booking up by, and a
 * token compared with hash_equals() that has not expired. An offer is only made to the person
 * holding the booking's own link.
 *
 * @param string $method 'get' or 'post'.
 * @return \WP_Post|null
 */
function bookify_customer_accounts_request_booking( $method ) {
	$lookup = bookify_booking_manage_lookup( bookify_booking_manage_request_args( $method ) );

	return 'act' === $lookup['state'] ? $lookup['booking'] : null;
}

/**
 * The offer to create an account, or '' when it does not apply.
 *
 * It appears on the confirmation page — the page a visitor lands on after booking, which carries
 * the new booking's own link — and only for a booking that is not already attached to a customer
 * and only while nobody is signed in. Otherwise there is nothing to offer.
 *
 * @return string
 */
function bookify_customer_account_offer_html() {
	if ( is_user_logged_in() ) {
		return '';
	}

	$booking = bookify_customer_accounts_request_booking( 'get' );

	if ( ! $booking || absint( get_post_meta( $booking->ID, 'bookify_customer_user', true ) ) ) {
		return '';
	}

	$args = bookify_customer_accounts_defaults();

	ob_start();
	?>
	<div class="bookify-account">
		<h3 class="bookify-booking__title"><?php echo esc_html( $args['offer_title'] ); ?></h3>
		<p class="bookify-booking__intro"><?php echo esc_html( $args['offer_intro'] ); ?></p>

		<form class="bookify-account__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bookify_create_account" />
			<?php bookify_manage_nonce_field( 'bookify_create_account', 'bookify_account_nonce' ); ?>
			<input type="hidden" name="bookify_manage" value="<?php echo esc_attr( (string) get_post_meta( $booking->ID, 'bookify_reference', true ) ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( (string) get_post_meta( $booking->ID, 'bookify_manage_token', true ) ); ?>" />

			<p class="bookify-booking__submit">
				<button type="submit"><?php echo esc_html( $args['offer_label'] ); ?></button>
			</p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Create the customer's account for the booking their link proves they own.
 *
 * The address comes from the booking and never from a request: the link is the claim. WordPress
 * sends its own "set your password" message, so this plugin never handles a password and there is
 * no second reset flow to keep working — and the attached booking is the one the link names, so
 * nothing is ever attached by matching an address.
 */
function bookify_booking_handle_account_create() {
	$target  = home_url( '/' );
	$booking = bookify_customer_accounts_request_booking( 'post' );
	$nonce   = isset( $_POST['bookify_account_nonce'] ) ? sanitize_key( wp_unslash( $_POST['bookify_account_nonce'] ) ) : '';

	if ( ! $booking || ! wp_verify_nonce( $nonce, 'bookify_create_account' ) ) {
		wp_safe_redirect( $target );
		exit;
	}

	// The customer's own page is where the answer belongs, and it is also the page that shows the
	// booking they just attached.
	$target = bookify_booking_manage_url( $booking->ID );

	if ( is_user_logged_in() || absint( get_post_meta( $booking->ID, 'bookify_customer_user', true ) ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'account_exists', $target ) );
		exit;
	}

	$email = (string) get_post_meta( $booking->ID, 'bookify_customer_email', true );

	if ( ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	// An address that already has an account is never silently reused or duplicated: the customer
	// is told to sign in, and this booking is left exactly as it was.
	if ( email_exists( $email ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'account_exists', $target ) );
		exit;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => bookify_customer_accounts_unique_login( $email ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => (string) get_post_meta( $booking->ID, 'bookify_customer_name', true ),
			// A subscriber and nothing else: no custom role, no capability of its own, and no
			// access to wp-admin beyond their own profile (D14).
			'role'         => 'subscriber',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	update_post_meta( $booking->ID, 'bookify_customer_user', $user_id );

	// WordPress's own message, and WordPress's own set-password link inside it: one password path
	// in the whole site, and this plugin never sees the password.
	wp_new_user_notification( $user_id, null, 'user' );

	wp_safe_redirect( add_query_arg( 'bookify', 'account_created', $target ) );
	exit;
}

/**
 * A login name WordPress has not taken yet, made from the address.
 *
 * The address itself where it can be, and a numbered variant where it cannot: login names are
 * unique, and a customer should not be refused an account because a stranger has their name.
 *
 * @param string $email The customer's address.
 * @return string
 */
function bookify_customer_accounts_unique_login( $email ) {
	$base = sanitize_user( (string) strstr( $email, '@', true ), true );

	if ( '' === $base ) {
		$base = 'bookify-customer';
	}

	$login = $base;
	$n     = 1;

	while ( username_exists( $login ) ) {
		$n++;
		$login = $base . $n;
	}

	return $login;
}

/**
 * The My bookings page.
 *
 * Every booking of the signed-in customer, rendered by the manage page's own renderer so the
 * actions are the same code with the same rules. The query is filtered by the current user id and
 * by nothing else — never by a parameter — which is what stops one customer's page showing
 * another's booking.
 *
 * @return string
 */
function bookify_customer_my_bookings_html() {
	$args    = wp_parse_args( bookify_customer_accounts_defaults(), bookify_manage_defaults() );
	$manage  = wp_parse_args( bookify_manage_defaults(), bookify_booking_form_defaults() );
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		ob_start();
		?>
		<div class="bookify-manage">
			<h2 class="bookify-booking__title"><?php echo esc_html( $args['my_title'] ); ?></h2>
			<p class="bookify-booking__intro"><?php echo esc_html( $args['sign_in'] ); ?></p>
			<p class="bookify-booking__submit">
				<a class="bookify-account__link" href="<?php echo esc_url( wp_login_url( bookify_customer_my_bookings_page_url() ) ); ?>">
					<?php echo esc_html( $args['sign_in_label'] ); ?>
				</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	$bookings = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_key'       => 'bookify_customer_user',
			'meta_value'     => $user_id,
		)
	);

	$notice = bookify_customer_accounts_notice( $args );

	ob_start();
	?>
	<div class="bookify-account-list">
		<?php if ( $notice ) : ?>
			<div class="bookify-booking__notice <?php echo esc_attr( $notice['class'] ); ?>" role="status" aria-live="polite"><?php echo esc_html( $notice['text'] ); ?></div>
		<?php endif; ?>

		<h2 class="bookify-booking__title"><?php echo esc_html( $args['my_title'] ); ?></h2>

		<?php if ( ! $bookings ) : ?>
			<p class="bookify-booking__intro"><?php echo esc_html( $args['my_none'] ); ?></p>
			<p class="bookify-booking__submit">
				<a class="bookify-account__link" href="<?php echo esc_url( bookify_booking_page_url() ); ?>"><?php echo esc_html( $args['book_label'] ); ?></a>
			</p>
		<?php else : ?>
			<p class="bookify-booking__intro"><?php echo esc_html( $args['my_self'] ); ?></p>

			<?php
			foreach ( $bookings as $booking_id ) {
				$booking = get_post( $booking_id );

				if ( ! $booking instanceof WP_Post ) {
					continue;
				}

				// The same renderer the manage page uses, with the notice suppressed: one notice is
				// printed above the list rather than once per booking.
				echo bookify_booking_manage_booking_html( $booking, $manage, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			}
			?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The notice for the My bookings page.
 *
 * @param array<string,string> $args The copy above.
 * @return array{class:string,text:string}|null
 */
function bookify_customer_accounts_notice( array $args ) {
	$state = bookify_booking_manage_state();

	$notices = array(
		'account_created' => array(
			'class' => 'bookify-booking__notice--success',
			'text'  => $args['created'],
		),
		'account_exists'  => array(
			'class' => '',
			'text'  => $args['exists'],
		),
	);

	return isset( $notices[ $state ] ) ? $notices[ $state ] : null;
}

/**
 * The page the My bookings shortcode lives on.
 *
 * Found by its shortcode like every other page this plugin links to, with the slug its own
 * documentation names as the fallback.
 *
 * @return string
 */
function bookify_customer_my_bookings_page_url() {
	static $url = null;

	if ( is_string( $url ) ) {
		return $url;
	}

	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	foreach ( $pages as $page ) {
		if ( has_shortcode( $page->post_content, 'bookify_my_bookings' ) ) {
			$url = (string) get_permalink( $page );

			return $url;
		}
	}

	$url = home_url( '/my-bookings/' );

	return $url;
}

/**
 * Render the My bookings page from a shortcode.
 *
 * @return string
 */
function bookify_customer_my_bookings_shortcode() {
	wp_enqueue_style( 'bookify-booking-form' );
	wp_enqueue_style( 'bookify-manage-booking' );

	return bookify_customer_my_bookings_html();
}

/**
 * Register the offer's handler and the My bookings page.
 */
function bookify_booking_register_customer_accounts() {
	add_shortcode( 'bookify_my_bookings', 'bookify_customer_my_bookings_shortcode' );

	add_action( 'admin_post_bookify_create_account', 'bookify_booking_handle_account_create' );
	add_action( 'admin_post_nopriv_bookify_create_account', 'bookify_booking_handle_account_create' );
}
