<?php
/**
 * The customer's own booking, reached from a link in their email.
 *
 * T17's one-shot cancel link becomes one expiring link that shows the booking and offers exactly
 * the actions its status allows — cancelling, and moving it to another slot (T21). No account and
 * no password: the link carries the booking's reference and a secret token compared with
 * hash_equals(), and a query argument never names a post id (T17's rule, kept).
 *
 * This is one of the plugin's request-reading places — the others are includes/bookings.php (the
 * form and T17's cancel link) and includes/payments/stripe.php (the button that starts a payment),
 * and includes/payments/webhook.php reads Stripe's own body, which is nobody's browser — and it is
 * the only one a stranger holds a credential for: what guards it is the token, because the person
 * holding the link is not logged in by design (D14). Nothing here writes storage — it decides, and
 * hands the decision to includes/bookings.php, whose two write functions stay the one set of rules
 * with the one advisory lock.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * How long after the appointment its manage link keeps working, in days.
 *
 * "Well beyond any booking date but not forever", which is what the plan asks for: long enough to
 * find the booking again afterwards, short enough that a link nobody withdrew stops being a
 * credential. A cancellation ends one sooner than this, because it replaces the token.
 *
 * @return int
 */
function bookify_booking_manage_window_days() {
	return 90;
}

/**
 * The booking's manage token, created the first time one is needed.
 *
 * Written lazily rather than at booking time so a booking made before this task still gets a link;
 * alphanumeric (wp_generate_password's default alphabet), so it survives a URL intact.
 *
 * @param int $booking_id The booking.
 * @return string
 */
function bookify_booking_manage_token( $booking_id ) {
	$booking_id = absint( $booking_id );
	$token      = (string) get_post_meta( $booking_id, 'bookify_manage_token', true );

	if ( '' === $token ) {
		$token = wp_generate_password( 32, false );

		update_post_meta( $booking_id, 'bookify_manage_token', $token );
	}

	return $token;
}

/**
 * When this booking's link stops working, as a Unix timestamp.
 *
 * A timestamp rather than a date string: it is compared with time(), which has no timezone to get
 * wrong, and it is the one value in this plugin deliberately not printed as it is stored.
 *
 * @param int $booking_id The booking.
 * @return int
 */
function bookify_booking_manage_expiry( $booking_id ) {
	$booking_id = absint( $booking_id );
	$stored     = absint( get_post_meta( $booking_id, 'bookify_manage_expires', true ) );

	return $stored ? $stored : bookify_booking_refresh_manage_expiry( $booking_id );
}

/**
 * Recompute and store this booking's expiry from where the booking now is.
 *
 * Called the first time a token is needed, and whenever the booking moves, so a booking moved
 * further out does not keep the expiry of the slot it left.
 *
 * @param int $booking_id The booking.
 * @return int The expiry that was stored.
 */
function bookify_booking_refresh_manage_expiry( $booking_id ) {
	$booking_id = absint( $booking_id );
	$starts     = bookify_booking_starts_at( $booking_id );

	// No readable date and time: the window runs from now, which is the only honest anchor left.
	$from = $starts ? $starts : new DateTimeImmutable( 'now', wp_timezone() );

	$expires = $from->modify( '+' . bookify_booking_manage_window_days() . ' days' )->getTimestamp();

	update_post_meta( $booking_id, 'bookify_manage_expires', $expires );

	return $expires;
}

/**
 * Replace a booking's manage token, so the link that was just used stops being able to act.
 *
 * Called when a booking is cancelled. The customer is redirected to the booking's new link in the
 * same response, so they see their cancelled booking rather than "that link is not valid" — and a
 * copy of the old link, kept anywhere, is dead.
 *
 * @param int $booking_id The booking.
 * @return string The new token.
 */
function bookify_booking_rotate_manage_token( $booking_id ) {
	$token = wp_generate_password( 32, false );

	update_post_meta( absint( $booking_id ), 'bookify_manage_token', $token );

	return $token;
}

/**
 * The link that manages one booking.
 *
 * Built here rather than stored, so a site whose address changes does not keep handing out a dead
 * host, and so the token is read at the moment the message is composed.
 *
 * @param int $booking_id The booking.
 * @return string URL, or '' when the booking has nothing to prove itself with.
 */
function bookify_booking_manage_url( $booking_id ) {
	$booking_id = absint( $booking_id );
	$reference  = (string) get_post_meta( $booking_id, 'bookify_reference', true );
	$token      = bookify_booking_manage_token( $booking_id );

	if ( '' === $reference || '' === $token ) {
		return '';
	}

	return add_query_arg(
		array(
			'bookify_manage' => $reference,
			'key'            => $token,
		),
		bookify_booking_manage_page_url()
	);
}

/**
 * The page that manages a booking.
 *
 * Found by its shortcode, exactly as the booking form finds its own page, so the owner can move it
 * and every link follows. A site that never made the page falls back to the slug the plugin's own
 * documentation names, rather than to no link at all.
 *
 * @return string
 */
function bookify_booking_manage_page_url() {
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
		if ( has_shortcode( $page->post_content, 'bookify_manage_booking' ) ) {
			$url = (string) get_permalink( $page );

			return $url;
		}
	}

	$url = home_url( '/manage-booking/' );

	return $url;
}

/**
 * What one token may do with one booking.
 *
 *   'act'     — this is the booking's own link and it has not expired
 *   'expired' — it was this booking's link, and its window has closed
 *   ''        — nothing here proves anything about any booking
 *
 * The expiry is only reported for a token that matched, so a guessed token cannot learn that a
 * booking is real by being told its link expired.
 *
 * @param int    $booking_id The booking.
 * @param string $token      The token the request carried, compared unsanitised.
 * @return string
 */
function bookify_booking_manage_token_state( $booking_id, $token ) {
	$booking_id = absint( $booking_id );

	if ( ! is_string( $token ) || '' === $token ) {
		return '';
	}

	$current = (string) get_post_meta( $booking_id, 'bookify_manage_token', true );

	if ( '' === $current || ! hash_equals( $current, $token ) ) {
		return '';
	}

	return bookify_booking_manage_expiry( $booking_id ) < time() ? 'expired' : 'act';
}

/**
 * The reference and token a manage request carries, from wherever it carries them.
 *
 * The page arrives as a link (?bookify_manage=…&key=…) and its buttons post back, so both shapes
 * are read the same way. The token is never sanitised before it is compared: sanitising a secret
 * can only break the comparison (T17's rule).
 *
 * @param string $method 'get' or 'post'.
 * @return array{reference:string,token:string}
 */
function bookify_booking_manage_request_args( $method ) {
	$source = 'post' === $method ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read, never trusted; the token is the guard.

	$reference = isset( $source['bookify_manage'] ) && is_string( $source['bookify_manage'] )
		? sanitize_text_field( wp_unslash( $source['bookify_manage'] ) )
		: '';

	$token = isset( $source['key'] ) && is_string( $source['key'] ) ? (string) wp_unslash( $source['key'] ) : '';

	return array(
		'reference' => $reference,
		'token'     => $token,
	);
}

/**
 * The booking a manage request names, and what its token allows.
 *
 * @param array $args The reference and token, as read by bookify_booking_manage_request_args().
 * @return array{booking:\WP_Post|null,state:string}
 */
function bookify_booking_manage_lookup( array $args ) {
	$booking = '' === $args['reference'] ? null : bookify_booking_find_by_reference( $args['reference'] );

	return array(
		'booking' => $booking,
		'state'   => $booking ? bookify_booking_manage_token_state( $booking->ID, $args['token'] ) : '',
	);
}

/**
 * A nonce field for a form that can appear many times on one page.
 *
 * wp_nonce_field() prints id="{name}", so one form per booking would put a run of identical ids on
 * the My bookings page. What is checked on the way back in is the field's name and its value, not
 * its id, so the field is written here without one — and without the referer field wp_nonce_field()
 * adds beside it, which nothing in this plugin needs: every handler builds its own redirect target
 * rather than being sent back by the referer.
 *
 * @param string $action The nonce action.
 * @param string $name   The field's name, which is also what the handler reads.
 */
function bookify_manage_nonce_field( $action, $name ) {
	printf(
		'<input type="hidden" name="%s" value="%s" />',
		esc_attr( $name ),
		esc_attr( wp_create_nonce( $action ) )
	);
}

/**
 * The copy the manage page prints.
 *
 * Its own list, separate from the booking form's: the widget's Elementor controls map the *form's*
 * keys one to one, and a string that form never prints has no business appearing there. The keys
 * the two surfaces genuinely share — the date and time labels, the two "no times" sentences, the
 * cancelled and error notices — are read from bookify_booking_form_defaults() instead of being
 * written twice.
 *
 * @return array<string,string>
 */
function bookify_manage_defaults() {
	return array(
		'title'          => __( 'Your booking', 'bookify-booking' ),
		'label_reference' => __( 'Booking reference', 'bookify-booking' ),
		'label_status'   => __( 'Status', 'bookify-booking' ),
		'title'          => __( 'Your booking', 'bookify-booking' ),
		// There is deliberately no label for what was booked: the row asks the booking itself, because
		// this page serves a session and an event and one word cannot be right for both (Stage 7).
		'label_party'    => __( 'People', 'bookify-booking' ),
		// T24: the one place a customer pays, and the words for every way that can go — including
		// the way where the site has no Stripe key and the button must not be offered at all.
		'label_payment'  => __( 'Payment', 'bookify-booking' ),
		'pay_title'      => __( 'Pay for this booking', 'bookify-booking' ),
		'pay_due'        => __( 'Please pay %1$s by %2$s to keep this time.', 'bookify-booking' ),
		'pay_due_any'    => __( 'Please pay %s to keep this time.', 'bookify-booking' ),
		'pay_label'      => __( 'Pay now', 'bookify-booking' ),
		'pay_note'       => __( 'Payment is taken on Stripe’s own page, so no card details are entered on this site. If you have just paid, this page catches up as soon as the payment is confirmed — please do not pay twice.', 'bookify-booking' ),
		'move_title'     => __( 'Move this booking', 'bookify-booking' ),
		'move_intro'     => __( 'Choose another day and time and we will move it.', 'bookify-booking' ),
		'move_label'     => __( 'Move my booking', 'bookify-booking' ),
		'cancel_label'   => __( 'Cancel this booking', 'bookify-booking' ),
		'cancelled_note' => __( 'This booking was cancelled, so the time is free again. Nothing else can be done with it.', 'bookify-booking' ),
		'moved'          => __( 'Your booking has been moved. The details above are the new ones.', 'bookify-booking' ),
		'lookup_title'   => __( 'Find your booking', 'bookify-booking' ),
		'lookup_intro'   => __( 'Give us the email address and the reference from your confirmation, and we will send the link again.', 'bookify-booking' ),
		'lookup_label'   => __( 'Email me the link', 'bookify-booking' ),
		'link_asked'     => __( 'If we know that address and reference, the link is on its way to the address on the booking.', 'bookify-booking' ),
		'link_invalid'   => __( 'That link is not valid, so no booking is shown here.', 'bookify-booking' ),
		'link_expired'   => __( 'That link has expired. Ask for a new one below and we will send it.', 'bookify-booking' ),
		// T24's three answers when a payment cannot be started. None of them says anything about a
		// provider, and none of them leaves the customer looking at a blank page.
		'pay_off'        => __( 'Card payments are not set up on this site yet. Please contact us and we will take the payment another way.', 'bookify-booking' ),
		'pay_failed'     => __( 'We could not start the payment just now, and nothing has been charged. Please try again in a moment, or contact us.', 'bookify-booking' ),
		'pay_none'       => __( 'There is nothing to pay on this booking, so nothing has been charged.', 'bookify-booking' ),
	);
}

/**
 * The state the page was asked for, when the customer has just done something.
 *
 * @return string
 */
function bookify_booking_manage_state() {
	return isset( $_GET['bookify'] ) ? sanitize_key( wp_unslash( $_GET['bookify'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a message, not acting on one.
}

/**
 * The notice above the manage page.
 *
 * @param array<string,string> $args The manage copy, merged with the form's.
 * @return array{class:string,text:string}|null
 */
function bookify_booking_manage_notice( array $args ) {
	$notices = array(
		'cancelled'  => array(
			'class' => 'bookify-booking__notice--success',
			'text'  => $args['cancelled'],
		),
		'moved'      => array(
			'class' => 'bookify-booking__notice--success',
			'text'  => $args['moved'],
		),
		'link_asked' => array(
			'class' => '',
			'text'  => $args['link_asked'],
		),
		'error'      => array(
			'class' => 'bookify-booking__notice--error',
			'text'  => bookify_booking_error_message( bookify_booking_error_code(), $args['error'] ),
		),
		// T24: the three answers a payment can come back with. "Nothing has been charged" is the part
		// that matters to the customer, so it is announced at the top of the page like every other
		// outcome rather than only being implied by the button still being there.
		'pay_failed' => array(
			'class' => 'bookify-booking__notice--error',
			'text'  => $args['pay_failed'],
		),
		'pay_off'    => array(
			'class' => '',
			'text'  => $args['pay_off'],
		),
		'pay_none'   => array(
			'class' => '',
			'text'  => $args['pay_none'],
		),
	);

	$state = bookify_booking_manage_state();

	return isset( $notices[ $state ] ) ? $notices[ $state ] : null;
}

/**
 * One row of the booking's details.
 *
 * A row with no value is not printed at all, so the list never shows an empty pair.
 *
 * @param string $label The field's label, or '' for a value that needs none.
 * @param string $value The value.
 */
function bookify_booking_manage_row( $label, $value ) {
	if ( '' === (string) $value ) {
		return;
	}

	printf(
		'<dt class="bookify-manage__label">%s</dt><dd class="bookify-manage__value">%s</dd>',
		esc_html( $label ),
		esc_html( (string) $value )
	);
}

/**
 * The form that cancels this booking.
 *
 * A post rather than a link: cancelling changes something, and a plain link can be prefetched by a
 * browser or a mail scanner. The token travels with it and is checked again on the way in.
 *
 * @param \WP_Post $booking The booking.
 * @param string   $token   The token this request arrived with.
 */
function bookify_booking_manage_cancel_form( \WP_Post $booking, $token ) {
	?>
	<form class="bookify-manage__cancel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bookify_manage_cancel" />
		<?php bookify_manage_nonce_field( 'bookify_manage_cancel', 'bookify_cancel_nonce' ); ?>
		<input type="hidden" name="bookify_manage" value="<?php echo esc_attr( (string) get_post_meta( $booking->ID, 'bookify_reference', true ) ); ?>" />
		<input type="hidden" name="key" value="<?php echo esc_attr( $token ); ?>" />
		<button type="submit" class="bookify-manage__cancel-button">
			<?php echo esc_html( bookify_manage_defaults()['cancel_label'] ); ?>
		</button>
	</form>
	<?php
}

/**
 * The picker that moves this booking, or '' when there is nothing to move it to.
 *
 * It is the booking form's own picker: the same widgets, the same DOM contract that
 * assets/booking-form.js is written against, and the same server functions deciding what may be
 * offered — narrowed to one service and one party size, because a move changes when, never what.
 *
 * @param \WP_Post $booking The booking.
 * @return string
 */
function bookify_booking_manage_picker_html( \WP_Post $booking ) {
	$args       = wp_parse_args( bookify_manage_defaults(), bookify_booking_form_defaults() );
	$service_id = absint( get_post_meta( $booking->ID, 'bookify_service_id', true ) );
	$reference  = (string) get_post_meta( $booking->ID, 'bookify_reference', true );
	$token      = (string) get_post_meta( $booking->ID, 'bookify_manage_token', true );

	// A service that can no longer be booked has no slots to offer, and a booking with no token has
	// no link to prove itself with: say nothing rather than offer a picker that cannot work.
	if ( null === bookify_booking_bookable_service( $service_id ) || '' === $token ) {
		return '';
	}

	$open_days = bookify_available_days( $service_id );

	if ( ! $open_days ) {
		return '<p class="bookify-booking__message bookify-booking__message--info">' . esc_html( $args['no_days'] ) . '</p>';
	}

	// The day being looked at: the one the customer asked for with "Show times" (a page request,
	// because the picker has to work without JavaScript), or the first day this service can still
	// be booked on. A real date is kept even when the diary is shut on it, so the page answers
	// "we are closed that day" rather than quietly showing another day's times.
	$chosen = isset( $_GET['bookify_day'] ) ? sanitize_text_field( wp_unslash( $_GET['bookify_day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a day to look at, not an action.
	$day    = bookify_booking_is_date( $chosen ) ? $chosen : $open_days[0];

	$day_options = array();

	foreach ( $open_days as $open_day ) {
		$day_options[ $open_day ] = bookify_booking_day_label( $open_day );
	}

	if ( ! isset( $day_options[ $day ] ) ) {
		$day_options = array( $day => bookify_booking_day_label( $day ) ) + $day_options;
	}

	// The one list: rendered here, and the only list bookify_booking_reschedule() accepts.
	$slots       = bookify_available_slots( $service_id, $day );
	$day_is_open = bookify_is_open_on( $day );

	// WordPress prints a style enqueued after wp_head in the footer, and this page renders after
	// wp_head, so both are asked for here rather than from a hook of their own.
	wp_enqueue_style( 'bookify-booking-form' );
	wp_enqueue_script( 'bookify-booking-form' );

	ob_start();
	?>
	<div
		class="bookify-booking bookify-manage__picker"
		data-bookify-rest="<?php echo esc_url( rest_url( BOOKIFY_BOOKING_REST_NAMESPACE . '/slots' ) ); ?>"
		data-bookify-days="<?php echo esc_attr( wp_json_encode( array_values( $open_days ) ) ); ?>"
	>
		<h3 class="bookify-booking__title"><?php echo esc_html( $args['move_title'] ); ?></h3>
		<p class="bookify-booking__intro"><?php echo esc_html( $args['move_intro'] ); ?></p>

		<form class="bookify-booking__choose" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bookify-picker>
			<input type="hidden" name="action" value="bookify_reschedule" />
			<?php bookify_manage_nonce_field( 'bookify_reschedule', 'bookify_day_nonce' ); ?>
			<input type="hidden" name="bookify_step" value="choose" />
			<input type="hidden" name="bookify_manage" value="<?php echo esc_attr( $reference ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $token ); ?>" />

			<?php // The service never changes here; the script asks for times with whatever this holds. ?>
			<input type="hidden" name="bookify_service_id" value="<?php echo esc_attr( (string) $service_id ); ?>" data-bookify-service />

			<?php
				echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					'date',
					$args['label_date'],
					array(
						'tag'       => 'select',
						'value'     => $day,
						'options'   => $day_options,
						'id_suffix' => '-' . $booking->ID,
						'attrs'     => array(
							'required'          => true,
							'data-bookify-date' => true,
						),
						'after'     => sprintf(
							'<span class="bookify-booking__calendar" role="group" aria-label="%s" data-bookify-calendar></span>',
							esc_attr( $args['label_date'] )
						),
					)
				);
			?>

			<p class="bookify-booking__submit bookify-booking__submit--choose" data-bookify-choose-submit>
				<button type="submit"><?php echo esc_html( $args['choose_label'] ); ?></button>
			</p>
		</form>

		<form class="bookify-booking__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bookify-form<?php echo $slots ? '' : ' hidden'; ?>>
			<input type="hidden" name="action" value="bookify_reschedule" />
			<?php bookify_manage_nonce_field( 'bookify_reschedule', 'bookify_move_nonce' ); ?>
			<input type="hidden" name="bookify_manage" value="<?php echo esc_attr( $reference ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="bookify_service_id" value="<?php echo esc_attr( (string) $service_id ); ?>" data-bookify-times-service />
			<input type="hidden" name="bookify_date" value="<?php echo esc_attr( $day ); ?>" data-bookify-times-date />

			<?php
			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'time',
				$args['label_time'],
				array(
					'tag'       => 'select',
					'value'     => '',
					'options'   => array_combine( $slots, $slots ),
					'id_suffix' => '-' . $booking->ID,
					'attrs'     => array(
						'required'          => true,
						'data-bookify-time' => true,
					),
				)
			);
			?>

			<p class="bookify-booking__submit">
				<button type="submit"><?php echo esc_html( $args['move_label'] ); ?></button>
			</p>
		</form>

		<p class="bookify-booking__message bookify-booking__message--info" data-bookify-empty="full"<?php echo ( $slots || ! $day_is_open ) ? ' hidden' : ''; ?>>
			<?php echo esc_html( $args['no_slots'] ); ?>
		</p>
		<p class="bookify-booking__message bookify-booking__message--info" data-bookify-empty="closed"<?php echo ( $slots || $day_is_open ) ? ' hidden' : ''; ?>>
			<?php echo esc_html( $args['closed'] ); ?>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The "pay for this booking" block, or '' when there is nothing to pay.
 *
 * A post rather than a link, for the same reason the cancel button is one: starting a payment
 * changes something (it creates a session with a provider), and a plain link can be prefetched by
 * a browser or followed by a mail scanner. The token travels with the form and is checked again on
 * the way in, so the page offering the button proves nothing by itself.
 *
 * Two things are said from the booking's own stored values rather than from the service: the amount
 * and the deadline. They are the same number the confirmation email carried, because both read what
 * bookify_create_booking() decided. When the site has no Stripe key, the block says so and offers
 * no button — a button that cannot work is worse than an explanation.
 *
 * @param \WP_Post             $booking The booking.
 * @param string               $token   The token this request arrived with.
 * @param array<string,string> $args    The manage copy, merged with the form's.
 * @return string
 */
function bookify_booking_manage_pay_html( \WP_Post $booking, $token, array $args ) {
	if ( ! bookify_booking_payment_is_due( $booking->ID ) ) {
		return '';
	}

	$amount = (float) get_post_meta( $booking->ID, 'bookify_payment_amount', true );
	$due_at = bookify_booking_payment_due_at( $booking->ID );

	if ( $amount <= 0 ) {
		return '';
	}

	$due = $due_at
		? sprintf(
			/* translators: 1: the amount to pay, 2: the date and time the payment window closes. */
			$args['pay_due'],
			bookify_booking_amount_label( $amount ),
			wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $due_at, wp_timezone() )
		)
		: sprintf(
			/* translators: %s: the amount to pay. */
			$args['pay_due_any'],
			bookify_booking_amount_label( $amount )
		);

	ob_start();
	?>
	<div class="bookify-manage__pay">
		<h3 class="bookify-booking__title"><?php echo esc_html( $args['pay_title'] ); ?></h3>
		<p class="bookify-booking__intro"><?php echo esc_html( $due ); ?></p>

		<?php if ( bookify_booking_stripe_enabled() ) : ?>
			<form class="bookify-manage__pay-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bookify_pay" />
				<?php bookify_manage_nonce_field( 'bookify_pay', 'bookify_pay_nonce' ); ?>
				<input type="hidden" name="bookify_manage" value="<?php echo esc_attr( (string) get_post_meta( $booking->ID, 'bookify_reference', true ) ); ?>" />
				<input type="hidden" name="key" value="<?php echo esc_attr( $token ); ?>" />
				<p class="bookify-booking__submit">
					<button type="submit"><?php echo esc_html( $args['pay_label'] ); ?></button>
				</p>
			</form>
			<p class="bookify-booking__message bookify-booking__message--info"><?php echo esc_html( $args['pay_note'] ); ?></p>
		<?php else : ?>
			<p class="bookify-booking__message bookify-booking__message--info"><?php echo esc_html( $args['pay_off'] ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The booking the link names, with exactly the actions its status allows.
 *
 * @param \WP_Post $booking The booking.
 * @param array    $args    The manage copy, merged with the form's.
 * @param bool     $notice  Whether to print the notice above it. The My bookings page renders one
 *                          notice for the whole list and passes false, so a notice cannot appear
 *                          once per booking.
 * @return string
 */
function bookify_booking_manage_booking_html( \WP_Post $booking, array $args, $notice = true ) {
	$status    = (string) get_post_meta( $booking->ID, 'bookify_status', true );
	$statuses  = bookify_booking_statuses();
	$service   = bookify_booking_bookable_service( get_post_meta( $booking->ID, 'bookify_service_id', true ) );
	$reference = (string) get_post_meta( $booking->ID, 'bookify_reference', true );
	$token     = (string) get_post_meta( $booking->ID, 'bookify_manage_token', true );

	// T23: a booking waiting for money is one the customer can still act on — see it, pay it, move
	// it or cancel it — so "open" is exactly the set of statuses that are using a place in the
	// diary, which is also the set the capacity count reads.
	$open_for  = in_array( $status, bookify_booking_slot_holding_statuses(), true );

	// The move window is only asked about a booking that could be moved at all: a cancelled booking
	// shows its state and no reason why nothing can be done with it.
	$movable  = $open_for ? bookify_booking_can_move( $booking->ID ) : null;
	$can_move = ! is_wp_error( $movable );

	$notice = $notice ? bookify_booking_manage_notice( $args ) : null;

	ob_start();
	?>
	<div class="bookify-manage">
		<?php if ( $notice ) : ?>
			<div class="bookify-booking__notice <?php echo esc_attr( $notice['class'] ); ?>" role="status" aria-live="polite"><?php echo esc_html( $notice['text'] ); ?></div>
		<?php endif; ?>

		<h2 class="bookify-booking__title"><?php echo esc_html( $args['title'] ); ?></h2>

		<dl class="bookify-manage__details">
			<?php
			bookify_booking_manage_row( $args['label_reference'], $reference );
			bookify_booking_manage_row( $args['label_status'], isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status );
			/*
			 * What was booked, and the word for it, both read from the booking (Stage 7): a session's
			 * title under "Session", or an event and its tier under "Event". Asking the booking rather
			 * than a copy list is what keeps one word from being forced on both products. The price and
			 * length line below belongs to a session, which is why the session still guards it.
			 */
			bookify_booking_manage_row( bookify_booking_item_kind( $booking->ID ), bookify_booking_item_label( $booking->ID ) );

			if ( $service ) {
				$length = bookify_booking_service_price_and_length( $service, $args['currency'] );

				bookify_booking_manage_row( '', bookify_booking_service_meta_label( $length['price'], $length['minutes'] ) );
			}

			bookify_booking_manage_row( $args['label_date'], (string) get_post_meta( $booking->ID, 'bookify_date', true ) );
			bookify_booking_manage_row( $args['label_time'], (string) get_post_meta( $booking->ID, 'bookify_time', true ) );
			bookify_booking_manage_row( $args['label_party'], (string) absint( get_post_meta( $booking->ID, 'bookify_party_size', true ) ) );
			// Prints nothing at all on a booking with no payment involved, which is every booking
			// made on a site where no service asks for one (T23, acceptance 4).
			bookify_booking_manage_row( $args['label_payment'], bookify_booking_payment_label( $booking->ID ) );
			?>
		</dl>

		<?php if ( ! $open_for ) : ?>
			<p class="bookify-manage__state"><?php echo esc_html( $args['cancelled_note'] ); ?></p>
		<?php else : ?>
			<?php if ( ! $can_move ) : ?>
				<p class="bookify-booking__message bookify-booking__message--info">
					<?php echo esc_html( bookify_booking_error_message( $movable->get_error_code(), $args['error'] ) ); ?>
				</p>
			<?php endif; ?>

			<?php
			// Paying comes first: on a booking that is waiting for money that is the thing the
			// customer came to do, and the move picker below asks for more of their attention.
			echo bookify_booking_manage_pay_html( $booking, $token, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.

			// Moving first, cancelling last: the page reads as the thing the customer came to do, and
			// the button that cannot be undone is not sitting where the main one is.
			if ( $can_move ) {
				// Built above from escaped parts.
				echo bookify_booking_manage_picker_html( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			bookify_booking_manage_cancel_form( $booking, $token );
			?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The page a customer sees when their link proves nothing, and the way to ask for a new one.
 *
 * It says the same thing whether the address is known or not — which is the whole point of asking
 * by email rather than showing bookings on screen — and it never mentions a booking.
 *
 * @param array  $args  The manage copy, merged with the form's.
 * @param string $state What the request proved: '' or 'expired'.
 * @return string
 */
function bookify_booking_manage_lookup_html( array $args, $state ) {
	$notice = bookify_booking_manage_notice( $args );
	$asked  = 'link_asked' === bookify_booking_manage_state();

	ob_start();
	?>
	<div class="bookify-manage">
		<?php if ( $notice ) : ?>
			<div class="bookify-booking__notice <?php echo esc_attr( $notice['class'] ); ?>" role="status" aria-live="polite"><?php echo esc_html( $notice['text'] ); ?></div>
		<?php endif; ?>

		<h2 class="bookify-booking__title"><?php echo esc_html( $args['lookup_title'] ); ?></h2>

		<p class="bookify-booking__intro">
			<?php echo esc_html( 'expired' === $state ? $args['link_expired'] : $args['lookup_intro'] ); ?>
		</p>

		<?php // A link that proved nothing says so, unless the customer has just asked for another. ?>
		<?php if ( '' === $state && ! $asked ) : ?>
			<p class="bookify-booking__message"><?php echo esc_html( $args['link_invalid'] ); ?></p>
		<?php endif; ?>

		<form class="bookify-manage__lookup" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bookify_manage_lookup" />
			<?php bookify_manage_nonce_field( 'bookify_manage_lookup', 'bookify_lookup_nonce' ); ?>

			<?php
			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'email',
				$args['label_email'],
				array(
					'type'  => 'email',
					'value' => '',
					'attrs' => array(
						'autocomplete' => 'email',
						'required'     => true,
					),
				)
			);

			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'reference',
				$args['label_reference'],
				array(
					'value' => '',
					'attrs' => array( 'required' => true ),
				)
			);
			?>

			<p class="bookify-booking__submit">
				<button type="submit"><?php echo esc_html( $args['lookup_label'] ); ?></button>
			</p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the manage page.
 *
 * @return string
 */
function bookify_booking_manage_html() {
	$args    = wp_parse_args( bookify_manage_defaults(), bookify_booking_form_defaults() );
	$request = bookify_booking_manage_request_args( 'get' );
	$lookup  = bookify_booking_manage_lookup( $request );

	if ( $lookup['booking'] && 'act' === $lookup['state'] ) {
		return bookify_booking_manage_booking_html( $lookup['booking'], $args );
	}

	// The link was this booking's and has expired: the customer is told that, and nothing about the
	// booking itself is printed until a fresh link arrives.
	return bookify_booking_manage_lookup_html( $args, $lookup['state'] );
}

/**
 * Render the manage page from a shortcode.
 *
 * @return string
 */
function bookify_booking_manage_shortcode() {
	wp_enqueue_style( 'bookify-booking-form' );
	wp_enqueue_style( 'bookify-manage-booking' );

	return bookify_booking_manage_html();
}

/**
 * Handle the lookup form: send the link again, and say the same thing either way.
 *
 * The message goes to the address already on the booking, never to the address that was typed: the
 * typed one is only a claim, and an unverified claim must not be able to redirect a customer's own
 * link somewhere else.
 */
function bookify_booking_handle_manage_lookup() {
	$target = bookify_booking_manage_page_url();
	$nonce  = isset( $_POST['bookify_lookup_nonce'] ) ? sanitize_key( wp_unslash( $_POST['bookify_lookup_nonce'] ) ) : '';

	// Every outcome — a bad nonce, a reference nobody knows, an address that does not match, and a
	// link that is on its way — answers with this one redirect, so the page cannot be used to find
	// out whether a booking or an address is known (T20, do 3).
	if ( wp_verify_nonce( $nonce, 'bookify_manage_lookup' ) ) {
		$reference = isset( $_POST['bookify_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['bookify_reference'] ) ) : '';
		$email     = isset( $_POST['bookify_email'] ) ? sanitize_email( wp_unslash( $_POST['bookify_email'] ) ) : '';

		$booking = bookify_booking_find_by_reference( $reference );

		if ( $booking && '' !== $email ) {
			$stored = (string) get_post_meta( $booking->ID, 'bookify_customer_email', true );

			if ( '' !== $stored && 0 === strcasecmp( $stored, $email ) ) {
				bookify_booking_send_manage_link( $booking->ID );
			}
		}
	}

	wp_safe_redirect( add_query_arg( 'bookify', 'link_asked', $target ) );
	exit;
}

/**
 * Handle the customer's own cancel button.
 *
 * The token is checked again on the way in: the page offered the button, but a page can be
 * replayed from a form cache, and the token is what proves that whoever pressed it is whoever the
 * link was sent to.
 */
function bookify_booking_handle_manage_cancel() {
	$args   = bookify_booking_manage_request_args( 'post' );
	$lookup = bookify_booking_manage_lookup( $args );
	$target = bookify_booking_manage_page_url();
	$nonce  = isset( $_POST['bookify_cancel_nonce'] ) ? sanitize_key( wp_unslash( $_POST['bookify_cancel_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'bookify_manage_cancel' ) || 'act' !== $lookup['state'] ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	bookify_booking_cancel( $lookup['booking']->ID );

	/*
	 * Cancelling replaced the token, so the customer is sent to the booking's new link — built from
	 * the booking itself, so it cannot go out missing the reference — rather than to "that link is
	 * not valid", and any copy of the link that just cancelled it is dead.
	 */
	wp_safe_redirect( add_query_arg( 'bookify', 'cancelled', bookify_booking_manage_url( $lookup['booking']->ID ) ) );
	exit;
}

/**
 * Whether a picker form sent a valid nonce.
 *
 * Both of its forms post the same action, and each carries its own field name so a page never
 * holds two elements with one id — which one arrived is therefore the form that was submitted.
 *
 * @return bool
 */
function bookify_booking_manage_picker_nonce() {
	foreach ( array( 'bookify_move_nonce', 'bookify_day_nonce' ) as $name ) {
		if ( isset( $_POST[ $name ] ) ) {
			return (bool) wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ $name ] ) ), 'bookify_reschedule' );
		}
	}

	return false;
}

/**
 * Where the picker's "Show times" step sends the customer back to.
 *
 * The chosen day travels in the URL rather than in the body, because the page it returns to is a
 * GET the customer can reload, and because the day is a view of the diary and not a submission.
 *
 * @return string
 */
function bookify_booking_manage_day_url() {
	$args = bookify_booking_manage_request_args( 'post' );
	$day  = isset( $_POST['bookify_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bookify_date'] ) ) : '';

	return add_query_arg(
		array_filter(
			array(
				'bookify_manage' => $args['reference'],
				'key'            => $args['token'],
				'bookify_day'    => bookify_booking_is_date( $day ) ? $day : '',
			)
		),
		bookify_booking_manage_page_url()
	);
}

/**
 * Handle a reschedule.
 *
 * The rules live in bookify_booking_reschedule(), which is also where the advisory lock is taken:
 * this only proves the request is the booking's own, and turns the answer into a page.
 */
function bookify_booking_handle_manage_reschedule() {
	$args    = bookify_booking_manage_request_args( 'post' );
	$target  = bookify_booking_manage_page_url();
	$day_url = bookify_booking_manage_day_url();
	$step    = isset( $_POST['bookify_step'] ) ? sanitize_key( wp_unslash( $_POST['bookify_step'] ) ) : '';

	if ( ! bookify_booking_manage_picker_nonce() ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	$lookup = bookify_booking_manage_lookup( $args );

	if ( 'act' !== $lookup['state'] ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	// "Show times": the day changed, so the page is rendered again for that day and nothing is
	// written. This is the picker's no-JavaScript path, and the one the script falls back to.
	if ( 'choose' === $step ) {
		wp_safe_redirect( $day_url );
		exit;
	}

	$day  = isset( $_POST['bookify_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bookify_date'] ) ) : '';
	$time = isset( $_POST['bookify_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bookify_time'] ) ) : '';

	$result = bookify_booking_reschedule( $lookup['booking']->ID, $day, $time );

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'bookify'      => 'error',
					'bookify_code' => $result->get_error_code(),
				),
				$day_url
			)
		);
		exit;
	}

	wp_safe_redirect( add_query_arg( 'bookify', 'moved', bookify_booking_manage_url( $lookup['booking']->ID ) ) );
	exit;
}

/**
 * Register the manage page, its shortcode and its three handlers.
 */
function bookify_booking_register_manage() {
	add_shortcode( 'bookify_manage_booking', 'bookify_booking_manage_shortcode' );

	// The three things a customer can send from their own link. All three are open to visitors who
	// are not logged in — that is the point of the link — and the token is what guards each of them.
	add_action( 'admin_post_bookify_manage_lookup', 'bookify_booking_handle_manage_lookup' );
	add_action( 'admin_post_nopriv_bookify_manage_lookup', 'bookify_booking_handle_manage_lookup' );
	add_action( 'admin_post_bookify_manage_cancel', 'bookify_booking_handle_manage_cancel' );
	add_action( 'admin_post_nopriv_bookify_manage_cancel', 'bookify_booking_handle_manage_cancel' );
	add_action( 'admin_post_bookify_reschedule', 'bookify_booking_handle_manage_reschedule' );
	add_action( 'admin_post_nopriv_bookify_reschedule', 'bookify_booking_handle_manage_reschedule' );

	add_action( 'wp_enqueue_scripts', 'bookify_booking_register_manage_style' );
}

/**
 * Register the manage page's stylesheet.
 *
 * The design tokens and the field styling come from the booking form's stylesheet, which the page
 * enqueues as well; this file only holds what the manage and My bookings pages add on top of it.
 */
function bookify_booking_register_manage_style() {
	wp_register_style(
		'bookify-manage-booking',
		BOOKIFY_BOOKING_URL . 'assets/manage-booking.css',
		array( 'bookify-booking-form' ),
		BOOKIFY_BOOKING_VERSION
	);
}
