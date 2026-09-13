<?php
/**
 * The booking write path.
 *
 * The only place in this plugin that writes to storage, and the only place that reads a
 * request. Values are sanitised here, so callers may hand over raw input.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The name of the advisory lock held while a slot is checked and booked (D16).
 */
const BOOKIFY_BOOKING_SLOT_LOCK = 'bookify_slot_lock';

/**
 * Whether a string is a real calendar date in YYYY-MM-DD form.
 *
 * @param string $date Candidate date.
 * @return bool
 */
function bookify_booking_is_date( $date ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $parts ) ) {
		return false;
	}

	return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
}

/**
 * Whether a string is a time of day in HH:MM form.
 *
 * @param string $time Candidate time.
 * @return bool
 */
function bookify_booking_is_time( $time ) {
	return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time );
}

/**
 * The messages a rejected booking can produce, by error code.
 *
 * One source of truth: the form shows these after a redirect, so the codes and the wording
 * cannot drift apart.
 *
 * @return array<string,string>
 */
function bookify_booking_error_messages() {
	return array(
		'bookify_unknown_service'    => __( 'Choose one of the sessions on offer.', 'bookify-booking' ),
		'bookify_missing_name'       => __( 'Tell us who the booking is for.', 'bookify-booking' ),
		'bookify_invalid_email'      => __( 'That email address does not look right.', 'bookify-booking' ),
		'bookify_invalid_date'       => __( 'Choose a date from today onwards.', 'bookify-booking' ),
		'bookify_invalid_time'       => __( 'Choose a time of day.', 'bookify-booking' ),
		'bookify_invalid_party_size' => __( 'How many guests should we expect?', 'bookify-booking' ),
		'bookify_closed_day'         => __( 'We are closed that day. Please choose one of the days on offer.', 'bookify-booking' ),
		'bookify_slot_unavailable'   => __( 'That time is not one of the times we offer. Please choose a time from the list.', 'bookify-booking' ),
		'bookify_slot_full'          => __( 'That time has just been taken. Please choose another time.', 'bookify-booking' ),
		'bookify_booking_busy'       => __( 'Someone else was booking at the same moment. Please send the form again.', 'bookify-booking' ),
		'bookify_unknown_booking'    => __( 'We could not find that booking.', 'bookify-booking' ),
		'bookify_move_too_soon'      => __( 'This booking is too close to its time to move it online. Please call us and we will sort it out.', 'bookify-booking' ),
		// Stage 7: an event and its tickets. The two "full" refusals are separate news on purpose —
		// one says the kind of ticket has gone, the other says the event has no room left at all.
		'bookify_unknown_event'      => __( 'That event is not on sale.', 'bookify-booking' ),
		'bookify_event_passed'       => __( 'That event has already started, so tickets are no longer on sale.', 'bookify-booking' ),
		'bookify_unknown_tier'       => __( 'Choose one of the tickets on offer.', 'bookify-booking' ),
		'bookify_tier_full'          => __( 'That kind of ticket has just sold out. Please choose another.', 'bookify-booking' ),
		'bookify_event_full'         => __( 'This event has no places left.', 'bookify-booking' ),
		'bookify_event_not_movable'  => __( 'Tickets cannot be moved to another time. Please contact us and we will sort it out.', 'bookify-booking' ),
	);
}

/**
 * The message for one error code.
 *
 * @param string $code     Error code.
 * @param string $fallback What to say when the code is not one of ours.
 * @return string
 */
function bookify_booking_error_message( $code, $fallback = '' ) {
	$messages = bookify_booking_error_messages();

	return isset( $messages[ $code ] ) ? $messages[ $code ] : $fallback;
}

/**
 * The form field an error code is about, so the message can be tied to it.
 *
 * Lives beside the codes themselves: a new code without an entry here would be announced
 * at the top of the form and nowhere near the field it is about.
 *
 * @param string $code Error code.
 * @return string Field key, or '' when the code is not about one field.
 */
function bookify_booking_error_field( $code ) {
	$fields = array(
		'bookify_unknown_service'    => 'service_id',
		'bookify_missing_name'       => 'name',
		'bookify_invalid_email'      => 'email',
		'bookify_invalid_date'       => 'date',
		'bookify_invalid_time'       => 'time',
		'bookify_invalid_party_size' => 'party_size',
		'bookify_closed_day'         => 'date',
		'bookify_slot_unavailable'   => 'time',
		'bookify_slot_full'          => 'time',
		// Stage 7. A sold-out tier is about the ticket that was chosen; a full event is about how many
		// were asked for. An event that is no longer on sale belongs to neither field — the form has no
		// event control — so it is announced above the form, where its message is the whole answer.
		'bookify_unknown_tier'       => 'tier_id',
		'bookify_tier_full'          => 'tier_id',
		'bookify_event_full'         => 'party_size',
	);

	return isset( $fields[ $code ] ) ? $fields[ $code ] : '';
}

/**
 * The service a booking can be created against, or null.
 *
 * The one definition of "bookable": a published bookify_service post. The form offers
 * exactly what this returns and the write path accepts exactly what this returns, so a
 * service that cannot be booked can never be offered.
 *
 * @param int $service_id Candidate service id.
 * @return \WP_Post|null
 */
function bookify_booking_bookable_service( $service_id ) {
	$service = get_post( absint( $service_id ) );

	if ( ! $service instanceof WP_Post || 'bookify_service' !== $service->post_type || 'publish' !== $service->post_status ) {
		return null;
	}

	return $service;
}

/**
 * Build the error for a rejected booking.
 *
 * @param string $code One of bookify_booking_error_messages().
 * @return \WP_Error
 */
function bookify_booking_error( $code ) {
	$messages = bookify_booking_error_messages();

	return new WP_Error(
		$code,
		isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'That booking could not be accepted.', 'bookify-booking' )
	);
}

/**
 * The advisory lock every writer takes before it checks a slot.
 *
 * Reading "how many places are left" and then inserting is not atomic: two submissions a
 * millisecond apart can both read the same number and both write. MariaDB's advisory locks give
 * real mutual exclusion without the custom table the plan rules out (D16), and this one is
 * verified available here (`GET_LOCK` → 1, server 11.8.9-MariaDB).
 *
 * What it covers: writers running this code against this one database. What it does not cover:
 * a second application writing the same rows, and it is per connection, so a writer that dies
 * without releasing the lock holds it only until that connection goes away. The lock is held for
 * the check and the insert and for nothing else.
 *
 * @return true|\WP_Error True when the lock is held, an error when it could not be taken.
 */
function bookify_booking_lock_slot() {
	global $wpdb;

	// A short wait on purpose: the critical section is two queries, so anything longer than a
	// moment means a writer is stuck rather than busy.
	$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', BOOKIFY_BOOKING_SLOT_LOCK, 5 ) );

	return '1' === (string) $acquired ? true : bookify_booking_error( 'bookify_booking_busy' );
}

/**
 * Give the slot lock back.
 */
function bookify_booking_unlock_slot() {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', BOOKIFY_BOOKING_SLOT_LOCK ) );
}

/**
 * The page the booking form is on.
 *
 * Used by the confirmation's cancel link and by the redirect a cancellation lands on, so the
 * customer ends up where the form — and its notice — is. The page is found by its shortcode;
 * a site that places the form only through the Elementor widget falls back to /book/, which is
 * where the form lives unless the owner moves it.
 *
 * @return string
 */
function bookify_booking_page_url() {
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
		if ( has_shortcode( $page->post_content, 'bookify_booking_form' ) ) {
			$url = (string) get_permalink( $page );

			return $url;
		}
	}

	$url = home_url( '/book/' );

	return $url;
}

/**
 * The booking a reference names, or null.
 *
 * A lookup by the stored reference, never by post id: a customer's link carries a reference, and
 * letting a request argument name a post would hand out any booking to anyone (T17).
 *
 * @param string $reference Reference as it appears in the confirmation.
 * @return \WP_Post|null
 */
function bookify_booking_find_by_reference( $reference ) {
	$reference = sanitize_text_field( (string) $reference );

	if ( '' === $reference ) {
		return null;
	}

	$bookings = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_reference',
					'value' => $reference,
				),
			),
		)
	);

	return $bookings ? $bookings[0] : null;
}

/**
 * Turn a request into the booking it asks for, asking every rule before anything is written.
 *
 * Two products share this one write path (Stage 7). A request naming an `event_id` is a ticket: the
 * event and the tier are real records, its date and time are the event's own and are never taken
 * from the request, and what it costs is the tier's price for the number of tickets asked for. A
 * request naming a session instead goes through the diary's rules exactly as T16 fixed them.
 *
 * @param array $data Raw input. Recognised keys: service_id, or event_id with tier_id, plus name,
 *                    email, phone, date, time and party_size.
 * @return array|\WP_Error Everything the insert needs, or an error naming what was wrong.
 */
function bookify_booking_resolve_request( array $data ) {
	$name  = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
	$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	$phone = isset( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : '';
	$party = isset( $data['party_size'] ) ? absint( $data['party_size'] ) : 0;

	if ( '' === $name ) {
		return bookify_booking_error( 'bookify_missing_name' );
	}

	if ( ! is_email( $email ) ) {
		return bookify_booking_error( 'bookify_invalid_email' );
	}

	if ( $party < 1 ) {
		return bookify_booking_error( 'bookify_invalid_party_size' );
	}

	$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;

	/*
	 * An event booking. Everything about it that a customer could otherwise type — the date, the
	 * time, the price — is read from the event and its tier, so a hand-made POST can change neither:
	 * it chooses a ticket, not a moment.
	 */
	if ( $event_id ) {
		$event = bookify_booking_bookable_event( $event_id );

		if ( null === $event ) {
			return bookify_booking_error( 'bookify_unknown_event' );
		}

		if ( bookify_event_has_started( $event->ID ) ) {
			return bookify_booking_error( 'bookify_event_passed' );
		}

		$tier = bookify_booking_tier_of_event( isset( $data['tier_id'] ) ? $data['tier_id'] : 0, $event->ID );

		if ( null === $tier ) {
			return bookify_booking_error( 'bookify_unknown_tier' );
		}

		return array(
			'kind'       => 'event',
			'service_id' => 0,
			'event_id'   => (int) $event->ID,
			'tier_id'    => (int) $tier->ID,
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'date'       => bookify_event_date( $event->ID ),
			'time'       => bookify_event_start( $event->ID ),
			'party'      => $party,
			// A ticket is bought rather than held, so it is paid in full (D24).
			'amount_due' => bookify_booking_ticket_amount( $tier->ID, $party ),
		);
	}

	$service_id = isset( $data['service_id'] ) ? absint( $data['service_id'] ) : 0;

	if ( null === bookify_booking_bookable_service( $service_id ) ) {
		return bookify_booking_error( 'bookify_unknown_service' );
	}

	$date = isset( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : '';
	$time = isset( $data['time'] ) ? sanitize_text_field( (string) $data['time'] ) : '';

	if ( ! bookify_booking_is_date( $date ) || $date < current_time( 'Y-m-d' ) ) {
		return bookify_booking_error( 'bookify_invalid_date' );
	}

	// A closed day and a blocked date are the same refusal to a visitor: the diary is shut.
	if ( ! bookify_is_open_on( $date ) ) {
		return bookify_booking_error( 'bookify_closed_day' );
	}

	if ( ! bookify_booking_is_time( $time ) ) {
		return bookify_booking_error( 'bookify_invalid_time' );
	}

	// Is this one of the times the diary generates at all? A time that is not (too late in the
	// day, inside the lead time, off the interval) is a different refusal from a full slot.
	if ( ! bookify_slot_is_offered( $service_id, $date, $time ) ) {
		return bookify_booking_error( 'bookify_slot_unavailable' );
	}

	return array(
		'kind'       => 'session',
		'service_id' => $service_id,
		'event_id'   => 0,
		'tier_id'    => 0,
		'name'       => $name,
		'email'      => $email,
		'phone'      => $phone,
		'date'       => $date,
		'time'       => $time,
		'party'      => $party,
		'amount_due' => bookify_booking_amount_due( $service_id ),
	);
}

/**
 * Whether there is room, asked with the slot lock held.
 *
 * A ticket has two ceilings and both are real: the tier's own inventory, and the event's places for
 * that date (D21/D22). The tier is asked first because it is the more specific answer — "that kind
 * has gone" is more use than "the event is full" when both are true.
 *
 * @param array $resolved The resolved request.
 * @return true|\WP_Error True when there is room.
 */
function bookify_booking_check_capacity( array $resolved ) {
	if ( 'event' === $resolved['kind'] ) {
		if ( bookify_tier_places_left( $resolved['tier_id'] ) < $resolved['party'] ) {
			return bookify_booking_error( 'bookify_tier_full' );
		}

		// An event with no capacity of its own has no ceiling here: its tiers are the whole answer.
		$capacity = bookify_event_capacity( $resolved['event_id'] );

		if ( $capacity > 0 && bookify_event_places_left( $resolved['event_id'] ) < $resolved['party'] ) {
			return bookify_booking_error( 'bookify_event_full' );
		}

		return true;
	}

	if ( bookify_slot_remaining( $resolved['service_id'], $resolved['date'], $resolved['time'] ) < $resolved['party'] ) {
		return bookify_booking_error( 'bookify_slot_full' );
	}

	return true;
}

/**
 * Create a booking.
 *
 * Every rule is asked here rather than being assumed from the form. A session's rules are the
 * diary's: a day the business is open, a time bookify_opening_slots() generates, and room for the
 * party, which is the same list the form renders (T16). A ticket's rules are the event's: it is on
 * sale, and both its tier and its date have room (Stage 7). What the two have in common — one
 * reference, one cancel token, one payment decision, one confirmation, one reminder — happens once,
 * below, so the two products cannot drift apart in how a booking is made.
 *
 * @param array $data Raw input. Recognised keys: service_id, or event_id with tier_id, plus name,
 *                    email, phone, date, time and party_size.
 * @return int|\WP_Error The new booking's id, or an error naming what was wrong.
 */
function bookify_create_booking( array $data ) {
	$resolved = bookify_booking_resolve_request( $data );

	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	// From here on the answer depends on what else has been booked, so it is read and written
	// under the advisory lock.
	$lock = bookify_booking_lock_slot();

	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	$room = bookify_booking_check_capacity( $resolved );

	if ( is_wp_error( $room ) ) {
		bookify_booking_unlock_slot();

		return $room;
	}

	$booking_id = wp_insert_post(
		array(
			'post_type'   => 'bookify_booking',
			'post_status' => 'publish',
			'post_title'  => '',
		),
		true
	);

	if ( is_wp_error( $booking_id ) ) {
		bookify_booking_unlock_slot();

		return $booking_id;
	}

	// Derived from the id, so a reference cannot collide and needs no uniqueness check.
	$reference = sprintf( 'BK-%05d', $booking_id );

	wp_update_post(
		array(
			'ID'         => $booking_id,
			'post_title' => $reference,
		)
	);

	$meta = array(
		'bookify_customer_name'  => $resolved['name'],
		'bookify_customer_email' => $resolved['email'],
		'bookify_customer_phone' => $resolved['phone'],
		'bookify_date'           => $resolved['date'],
		'bookify_time'           => $resolved['time'],
		'bookify_party_size'     => $resolved['party'],
		'bookify_reference'      => $reference,
		// The customer's own way back to this booking, with no account (T17). Generated with
		// wp_generate_password( 32, false ), which is alphanumeric, so it survives a URL intact.
		'bookify_cancel_token'   => wp_generate_password( 32, false ),
	);

	/*
	 * What the booking is *for*: the session it names, or the event and tier of the ticket. A
	 * ticket's date and time above are the event's own, so the confirmation, the reminder, the
	 * admin list and the customer's page read one set of keys for both products.
	 */
	if ( 'event' === $resolved['kind'] ) {
		$meta['bookify_event_id'] = $resolved['event_id'];
		$meta['bookify_tier_id']  = $resolved['tier_id'];
	} else {
		$meta['bookify_service_id'] = $resolved['service_id'];
	}

	/*
	 * T23: what this booking costs is decided here, once, and stored with the booking — so the
	 * message the customer is sent, the amount T24 asks Stripe for and the operator's screen are
	 * one number that cannot drift, and a price the owner changes tomorrow does not rewrite what
	 * somebody was told today. A booking that costs nothing is 'pending', exactly as it was before
	 * Stage 5 touched anything (T23, acceptance 4); a ticket is always the full price (D24).
	 */
	$amount_due = (float) $resolved['amount_due'];

	if ( $amount_due > 0 ) {
		$meta['bookify_status']         = 'awaiting_payment';
		$meta['bookify_payment_status'] = 'unpaid';
		$meta['bookify_payment_amount'] = $amount_due;
		$meta['bookify_payment_due']    = time() + bookify_booking_payment_window_minutes() * MINUTE_IN_SECONDS;
	} else {
		$meta['bookify_status'] = 'pending';
	}

	// T22: a booking made while signed in belongs to that customer, and one made while signed out
	// belongs to nobody. The email address is deliberately not used to find a user: attaching by
	// address would let anyone plant a booking in a stranger's account by typing their address.
	if ( is_user_logged_in() ) {
		$meta['bookify_customer_user'] = get_current_user_id();
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $booking_id, $key, $value );
	}

	bookify_booking_unlock_slot();

	// T23's deadline, asked for after the slot lock is given back and before the mail, which is
	// where the customer is told what the deadline is.
	if ( $amount_due > 0 ) {
		bookify_booking_schedule_expiry( $booking_id );
	}

	// After the booking exists, never before: a rejected booking has already returned, and the
	// mail is not sent while the slot lock is held.
	bookify_booking_send_confirmation( $booking_id );

	// The reminder is an appointment rather than a message: nothing is written to the customer
	// until its own moment arrives, and a booking made inside the lead time never gets one (T30).
	bookify_booking_schedule_reminder( $booking_id );

	return $booking_id;
}

/**
 * Keep what the visitor typed for one read, and hand back the token that names it.
 *
 * The values themselves never go into the URL: they stay in a transient that the form
 * deletes as it reads it, so they cannot land in browser history or in the access log and
 * a refresh cannot replay them. Ten minutes is longer than it takes to correct a field and
 * short enough that an abandoned draft is not kept.
 *
 * @param array $data The submission as bookify_create_booking() received it.
 * @return string Token to put in the redirect URL.
 */
function bookify_booking_store_draft( array $data ) {
	$token = wp_generate_password( 20, false );

	set_transient( 'bookify_draft_' . $token, $data, 10 * MINUTE_IN_SECONDS );

	return $token;
}

/**
 * Handle a submitted booking form.
 *
 * The form is public, so there is no capability to require: the nonce is the guard, and the
 * handler can only ever create a booking, never read or change anything else. Two buttons post
 * here — "Show times", which asks for the same form again with another day, and the submit that
 * books.
 *
 * The order of defence, and the order it runs in, is:
 *
 *   1. the nonce (T3)          — the one thing a stranger cannot guess;
 *   2. the honeypot (T12)      — a field a real visitor never sees, let alone fills;
 *   3. the rate limiter (T19)  — a small budget per address and per email address;
 *   4. Turnstile (T19)         — last, and only when the owner has configured keys for it.
 *
 * Three of the four are in bookify_bots_guard(), and all four answer a refusal the same way
 * (?bookify=rejected), so a bot cannot tell which check turned it away. Nothing after the nonce
 * is a capability check, which is why every step is also cheap: none of them touches the diary.
 */
function bookify_booking_handle_submission() {
	$target = wp_get_referer() ? wp_get_referer() : home_url( '/' );

	$nonce = isset( $_POST['bookify_nonce'] ) ? sanitize_key( wp_unslash( $_POST['bookify_nonce'] ) ) : '';
	$step  = isset( $_POST['bookify_step'] ) ? sanitize_key( wp_unslash( $_POST['bookify_step'] ) ) : '';

	// A filled honeypot takes the same route as a bad nonce, so a bot cannot tell which
	// of its inputs was the one that gave it away.
	$honeypot = isset( $_POST['bookify_website'] ) ? trim( (string) wp_unslash( $_POST['bookify_website'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'bookify_booking_submit' ) || '' !== $honeypot ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'rejected', $target ) );
		exit;
	}

	// Raw on purpose: bookify_create_booking() is where sanitising happens.
	$data = array(
		'service_id' => isset( $_POST['bookify_service_id'] ) ? wp_unslash( $_POST['bookify_service_id'] ) : 0,
		// Stage 7: a ticket names an event and the tier it was bought on, and nothing else about
		// what it is for — the date, the time and the price all come from those two records.
		'event_id'   => isset( $_POST['bookify_event_id'] ) ? wp_unslash( $_POST['bookify_event_id'] ) : 0,
		'tier_id'    => isset( $_POST['bookify_tier_id'] ) ? wp_unslash( $_POST['bookify_tier_id'] ) : 0,
		'name'       => isset( $_POST['bookify_name'] ) ? wp_unslash( $_POST['bookify_name'] ) : '',
		'email'      => isset( $_POST['bookify_email'] ) ? wp_unslash( $_POST['bookify_email'] ) : '',
		'phone'      => isset( $_POST['bookify_phone'] ) ? wp_unslash( $_POST['bookify_phone'] ) : '',
		'date'       => isset( $_POST['bookify_date'] ) ? wp_unslash( $_POST['bookify_date'] ) : '',
		'time'       => isset( $_POST['bookify_time'] ) ? wp_unslash( $_POST['bookify_time'] ) : '',
		'party_size' => isset( $_POST['bookify_party_size'] ) ? wp_unslash( $_POST['bookify_party_size'] ) : 0,
	);

	// "Show times" is the same form posted with a step marker: the day (or the service) changed,
	// so the form is rendered again for that day. The values go into a draft exactly as a
	// rejection puts them there — which is what keeps a typed name and email from being lost on
	// the no-JavaScript path — and nothing that was typed reaches the URL.
	if ( 'choose' === $step ) {
		$day = bookify_booking_is_date( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : '';

		// The referer can carry a state and a draft token of its own; this is a fresh look at
		// the diary, so it starts from a clean address.
		$target = remove_query_arg(
			array( 'bookify', 'bookify_code', 'bookify_token', 'bookify_service', 'bookify_date' ),
			$target
		);

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'bookify_service' => absint( $data['service_id'] ) ? absint( $data['service_id'] ) : '',
						'bookify_date'    => $day,
						'bookify_token'   => bookify_booking_store_draft( $data ),
					)
				),
				$target
			)
		);
		exit;
	}

	/*
	 * The rate limiter and Turnstile, in that order — the counters are local and free, the
	 * verification is somebody else's server — and deliberately after the picker above, which
	 * writes nothing and so costs nothing to answer.
	 */
	$turnstile = isset( $_POST['cf-turnstile-response'] ) ? (string) wp_unslash( $_POST['cf-turnstile-response'] ) : '';

	$guard = bookify_bots_guard( $data['email'], $turnstile );

	if ( is_wp_error( $guard ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'rejected', $target ) );
		exit;
	}

	$result = bookify_create_booking( $data );

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'bookify'       => 'error',
					'bookify_code'  => $result->get_error_code(),
					'bookify_token' => bookify_booking_store_draft( $data ),
				),
				$target
			)
		);
		exit;
	}

	// The booking is in the diary, so it counts against that address's budget. Counted here and not
	// before the write, so a submission that was refused for any reason has spent nothing.
	bookify_bots_record_booking( $data['email'] );

	/*
	 * The confirmation carries the new booking's own link, so the page can offer the customer an
	 * account for the booking they have just made without asking them to type anything (T22). The
	 * notice is untouched: ?bookify=booked still means exactly what it meant before.
	 */
	$booked    = add_query_arg( 'bookify', 'booked', $target );
	$reference = (string) get_post_meta( $result, 'bookify_reference', true );
	$token     = bookify_booking_manage_token( $result );

	if ( '' !== $reference && '' !== $token ) {
		$booked = add_query_arg(
			array(
				'bookify_manage' => $reference,
				'key'            => $token,
			),
			$booked
		);
	}

	wp_safe_redirect( $booked );
	exit;
}

/**
 * Cancel a booking from the link in its confirmation.
 *
 * The link carries the reference and the token, never a post id, and the token is compared with
 * hash_equals() against the stored one. Nothing here says whether the reference exists: a wrong
 * reference, a wrong token and a booking that has no token all end in the same place, so the
 * link cannot be used to find out which references are real (T17).
 */
function bookify_booking_handle_cancellation() {
	if ( ! isset( $_GET['bookify_cancel'] ) ) {
		return;
	}

	$reference = sanitize_text_field( wp_unslash( $_GET['bookify_cancel'] ) );

	// Compared, never stored or printed, so it is taken as it arrived — sanitising a secret
	// before comparing it can only break the comparison.
	$token = isset( $_GET['bookify_key'] ) ? (string) wp_unslash( $_GET['bookify_key'] ) : '';

	$target = bookify_booking_page_url();

	$booking = bookify_booking_find_by_reference( $reference );

	$stored = $booking ? (string) get_post_meta( $booking->ID, 'bookify_cancel_token', true ) : '';

	if ( ! $booking || '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'cancel_invalid', $target ) );
		exit;
	}

	if ( 'cancelled' === (string) get_post_meta( $booking->ID, 'bookify_status', true ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'cancel_repeat', $target ) );
		exit;
	}

	// Only the status changes: the slot is offered again the moment the booking stops counting
	// towards the capacity (bookify_booking_guests_by_day() reads pending and confirmed only).
	bookify_booking_cancel( $booking->ID );

	wp_safe_redirect( add_query_arg( 'bookify', 'cancelled', $target ) );
	exit;
}

/**
 * Cancel a booking.
 *
 * The one place a cancellation happens: the customer's own button on the manage page, an operator
 * changing the status on the Bookings screen, and T17's older cancel link all end here, so they
 * cannot come to mean three different things. Only the status changes — the slot is offered again
 * the moment the booking stops counting towards the capacity (bookify_booking_guests_by_day()
 * reads pending and confirmed only).
 *
 * @param int $booking_id The booking.
 * @return bool Whether this call was the one that cancelled it.
 */
function bookify_booking_cancel( $booking_id ) {
	$booking_id = absint( $booking_id );

	if ( 'cancelled' === (string) get_post_meta( $booking_id, 'bookify_status', true ) ) {
		return false;
	}

	update_post_meta( $booking_id, 'bookify_status', 'cancelled' );

	// A cancelled booking has no payment deadline to keep, and nobody is coming to it, so both of
	// its appointments go: the payment's (T23) and the reminder's (T30).
	bookify_booking_unschedule_expiry( $booking_id );
	bookify_booking_unschedule_reminder( $booking_id );

	/*
	 * The link that cancelled it stops being able to do anything: its token is replaced, and the
	 * token it replaced can still *show* the booking and change nothing (T20). Rotating without
	 * keeping the old token for reading would leave the customer who just cancelled looking at
	 * "that link is not valid", which reads like their cancellation did not happen.
	 */
	bookify_booking_rotate_manage_token( $booking_id );

	return true;
}

/**
 * When a booking starts, on the site's own clock.
 *
 * @param int $booking_id The booking.
 * @return \DateTimeImmutable|null Null when the booking has no readable date and time.
 */
function bookify_booking_starts_at( $booking_id ) {
	$date = (string) get_post_meta( absint( $booking_id ), 'bookify_date', true );
	$time = (string) get_post_meta( absint( $booking_id ), 'bookify_time', true );

	if ( ! bookify_booking_is_date( $date ) || ! bookify_booking_is_time( $time ) ) {
		return null;
	}

	return date_create_immutable( $date . ' ' . $time, wp_timezone() );
}

/**
 * How long before it starts a booking stops being movable, in hours.
 *
 * A rule of its own rather than the lead time, defaulting to T15's lead time because "how soon is
 * too soon to change your mind" and "how soon can someone book" usually answer the same number.
 *
 * @return int
 */
function bookify_booking_move_hours() {
	return (int) bookify_availability()['move_hours'];
}

/**
 * Whether a booking is still far enough away for the customer to move it.
 *
 * The clock this decision uses is the site's own — wp_timezone(), the same one the opening hours
 * and the lead time are read in, and the booking's date and time are read as the business wrote
 * them. Never the visitor's browser and never UTC.
 *
 * @param int $booking_id The booking.
 * @return true|\WP_Error True when it may be moved.
 */
function bookify_booking_can_move( $booking_id ) {
	// A ticket is for one date, the event's own: there is nothing to move it to, and saying so is
	// more use than refusing it as "too close to its time" (Stage 7). The manage page asks here,
	// so this one guard is what stops the picker being drawn and the move being written.
	if ( bookify_booking_event( $booking_id ) ) {
		return bookify_booking_error( 'bookify_event_not_movable' );
	}

	$starts = bookify_booking_starts_at( $booking_id );

	if ( null === $starts ) {
		return bookify_booking_error( 'bookify_move_too_soon' );
	}

	$cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . bookify_booking_move_hours() . ' hours' );

	if ( $starts < $cutoff ) {
		return bookify_booking_error( 'bookify_move_too_soon' );
	}

	return true;
}

/**
 * Move a booking to another slot.
 *
 * Every rule the booking form's write path asks is asked here again, through the same functions,
 * so the form and this cannot disagree about what is bookable; and the check-and-write happens
 * under the same advisory lock (D16), so two customers cannot both move into the last place.
 *
 * @param int    $booking_id The booking.
 * @param string $date       The new date, YYYY-MM-DD.
 * @param string $time       The new time, HH:MM.
 * @return true|\WP_Error
 */
function bookify_booking_reschedule( $booking_id, $date, $time ) {
	$booking_id = absint( $booking_id );
	$booking    = get_post( $booking_id );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return bookify_booking_error( 'bookify_unknown_booking' );
	}

	$movable = bookify_booking_can_move( $booking_id );

	if ( is_wp_error( $movable ) ) {
		return $movable;
	}

	$service_id = absint( get_post_meta( $booking_id, 'bookify_service_id', true ) );
	$party      = max( 1, (int) get_post_meta( $booking_id, 'bookify_party_size', true ) );
	$date       = sanitize_text_field( (string) $date );
	$time       = sanitize_text_field( (string) $time );

	if ( ! bookify_booking_is_date( $date ) || $date < current_time( 'Y-m-d' ) ) {
		return bookify_booking_error( 'bookify_invalid_date' );
	}

	// A closed day and a blocked date are the same refusal to a visitor: the diary is shut.
	if ( ! bookify_is_open_on( $date ) ) {
		return bookify_booking_error( 'bookify_closed_day' );
	}

	if ( ! bookify_booking_is_time( $time ) ) {
		return bookify_booking_error( 'bookify_invalid_time' );
	}

	if ( ! bookify_slot_is_offered( $service_id, $date, $time ) ) {
		return bookify_booking_error( 'bookify_slot_unavailable' );
	}

	// From here the answer depends on what else is booked, so it is read and written under the
	// same lock the booking form takes.
	$lock = bookify_booking_lock_slot();

	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	if ( bookify_slot_remaining( $service_id, $date, $time ) < $party ) {
		bookify_booking_unlock_slot();

		return bookify_booking_error( 'bookify_slot_full' );
	}

	$previous = trim(
		(string) get_post_meta( $booking_id, 'bookify_date', true ) . ' ' .
		(string) get_post_meta( $booking_id, 'bookify_time', true )
	);

	update_post_meta( $booking_id, 'bookify_date', $date );
	update_post_meta( $booking_id, 'bookify_time', $time );

	// One key, so an operator can answer "why did this move?" — printed exactly as the date and
	// time were stored, like every other date in this plugin.
	update_post_meta( $booking_id, 'bookify_previous_slot', $previous );

	// The link has to outlive the appointment it names, and the appointment has just moved.
	bookify_booking_refresh_manage_expiry( $booking_id );

	/*
	 * The reminder moves with it. The sent marker is cleared first on purpose: a customer who was
	 * reminded about Tuesday and then moved to Friday has earned a reminder for Friday, and that
	 * marker is exactly what would otherwise silence it.
	 */
	delete_post_meta( $booking_id, BOOKIFY_BOOKING_REMINDER_SENT_META );
	bookify_booking_schedule_reminder( $booking_id );

	bookify_booking_unlock_slot();

	return true;
}

/**
 * Register the form handler and the cancellation handler.
 *
 * Both actions point at the same callback: the form is open to visitors who are not logged
 * in, and the nonce is what guards it. The cancellation is a plain link in an email, so it is
 * handled on template_redirect — after WordPress is ready, before anything is printed.
 */
function bookify_booking_register_request_handlers() {
	add_action( 'admin_post_nopriv_bookify_booking', 'bookify_booking_handle_submission' );
	add_action( 'admin_post_bookify_booking', 'bookify_booking_handle_submission' );
	add_action( 'template_redirect', 'bookify_booking_handle_cancellation' );
}
