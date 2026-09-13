<?php
/**
 * Events and ticket tiers (Stage 7, plan D21 and D22).
 *
 * A session (`bookify_service`) is offered on any day the diary is open; an event happens **once**,
 * on the date it stores, which is why its capacity *is* that date's inventory (D21). A tier is the
 * price-and-inventory pair a ticket is sold at — General Admission and VIP — and it is a record of
 * its own rather than a row inside the event, because a booking has to name the thing it bought by
 * an id that stays true (D22).
 *
 * This file is storage shape and the rules read from it, and nothing else: the wp-admin boxes live
 * in includes/event-fields.php, the form in includes/event-tickets.php and the summary in
 * includes/event-summary.php. Every counter here reads the same
 * `bookify_booking_slot_holding_statuses()` the session model uses, so a cancelled or expired ticket
 * gives its place back exactly as an appointment does (T23).
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the event and ticket-tier post types.
 *
 * The event is public and queryable because a ticket has to be bought from a page (D23), and the
 * theme holds exactly one template for that page. `has_archive` is false on purpose: an archive
 * would render through the template hierarchy and land on Hello Elementor's bare fallback, which is
 * the defect D9 recorded.
 *
 * A tier is not public, for the same reason a booking is not: nothing but the event's page needs to
 * address one, and a public type would hand out a bare URL per tier with nothing on it.
 */
function bookify_booking_register_event_types() {
	register_post_type(
		'bookify_event',
		array(
			'labels'       => array(
				'name'          => __( 'Events', 'bookify-booking' ),
				'singular_name' => __( 'Event', 'bookify-booking' ),
				'add_new_item'  => __( 'Add event', 'bookify-booking' ),
				'edit_item'     => __( 'Edit event', 'bookify-booking' ),
			),
			'description'  => __( 'A dated event with tickets for sale.', 'bookify-booking' ),
			'public'       => true,
			'publicly_queryable' => true,
			'rewrite'      => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-tickets-alt',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'has_archive'  => false,
		)
	);

	register_post_type(
		'bookify_tier',
		array(
			'labels'        => array(
				'name'          => __( 'Ticket tiers', 'bookify-booking' ),
				'singular_name' => __( 'Ticket tier', 'bookify-booking' ),
				'add_new_item'  => __( 'Add ticket tier', 'bookify-booking' ),
				'edit_item'     => __( 'Edit ticket tier', 'bookify-booking' ),
			),
			'description'   => __( 'One price-and-inventory pair on an event, for example General Admission or VIP.', 'bookify-booking' ),
			'public'        => false,
			'show_ui'       => true,
			// Nested under Events: a tier is meaningless on its own, and wp-admin should read that way.
			'show_in_menu'  => 'edit.php?post_type=bookify_event',
			'supports'      => array( 'title' ),
		)
	);
}

/**
 * Sanitise an event's date.
 *
 * A userland function, so it can be a sanitize callback: WordPress hands one four arguments and an
 * internal PHP function refuses the extras (T2's trap). Anything that is not a real calendar date is
 * dropped rather than stored, so an event without a date is read as "not for sale yet".
 *
 * @param mixed $value Raw value.
 * @return string YYYY-MM-DD, or ''.
 */
function bookify_booking_sanitize_event_date( $value ) {
	$date = is_string( $value ) ? trim( $value ) : '';

	return bookify_booking_is_date( $date ) ? $date : '';
}

/**
 * Sanitise an event's start or end time.
 *
 * @param mixed $value Raw value.
 * @return string HH:MM, or ''.
 */
function bookify_booking_sanitize_event_time( $value ) {
	$time = is_string( $value ) ? trim( $value ) : '';

	return bookify_booking_is_time( $time ) ? $time : '';
}

/**
 * Register the meta an event and a tier are made of.
 *
 * The readers below re-validate what they find, the way `bookify_availability()` does: a value can
 * be in the table without having gone through these sanitisers, and `wp post meta update` is one way
 * in.
 */
function bookify_booking_register_event_meta() {
	$events = array(
		'bookify_event_date'     => array(
			'type'              => 'string',
			'description'       => __( 'The one day this event happens, YYYY-MM-DD.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_event_date',
		),
		'bookify_event_start'    => array(
			'type'              => 'string',
			'description'       => __( 'When it starts, HH:MM.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_event_time',
		),
		'bookify_event_end'      => array(
			'type'              => 'string',
			'description'       => __( 'When it finishes, HH:MM. The reservation summary is sent then.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_event_time',
		),
		'bookify_event_capacity' => array(
			'type'              => 'integer',
			'description'       => __( 'Places the event has on its date. Zero means the tiers alone decide.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
	);

	$tiers = array(
		'bookify_event_id'      => array(
			'type'              => 'integer',
			'description'       => __( 'The event this tier is sold on.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		'bookify_tier_price'    => array(
			'type'              => 'number',
			'description'       => __( 'Price of one ticket, in the site currency.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_price',
		),
		'bookify_tier_capacity' => array(
			'type'              => 'integer',
			'description'       => __( 'How many tickets of this kind are for sale.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
	);

	foreach ( array(
		'bookify_event' => $events,
		'bookify_tier'  => $tiers,
	) as $post_type => $fields ) {
		foreach ( $fields as $meta_key => $args ) {
			register_post_meta(
				$post_type,
				$meta_key,
				$args + array(
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => 'bookify_booking_meta_auth',
				)
			);
		}
	}
}

/**
 * The event a ticket can be sold on, or null.
 *
 * The one definition of "on sale": a published event with a readable date and start time. A draft,
 * an event whose date the owner has not filled in yet, and a trashed event are all null here, so
 * nothing downstream has to ask again.
 *
 * @param int $event_id Candidate event id.
 * @return \WP_Post|null
 */
function bookify_booking_bookable_event( $event_id ) {
	$event = get_post( absint( $event_id ) );

	if ( ! $event instanceof WP_Post || 'bookify_event' !== $event->post_type || 'publish' !== $event->post_status ) {
		return null;
	}

	if ( '' === bookify_event_date( $event->ID ) || '' === bookify_event_start( $event->ID ) ) {
		return null;
	}

	return $event;
}

/**
 * An event's date, re-validated on the way out.
 *
 * @param int $event_id The event.
 * @return string YYYY-MM-DD, or '' when nothing readable is stored.
 */
function bookify_event_date( $event_id ) {
	return bookify_booking_sanitize_event_date( get_post_meta( absint( $event_id ), 'bookify_event_date', true ) );
}

/**
 * An event's start time, re-validated on the way out.
 *
 * @param int $event_id The event.
 * @return string HH:MM, or ''.
 */
function bookify_event_start( $event_id ) {
	return bookify_booking_sanitize_event_time( get_post_meta( absint( $event_id ), 'bookify_event_start', true ) );
}

/**
 * An event's end time, if it has one.
 *
 * @param int $event_id The event.
 * @return string HH:MM, or ''.
 */
function bookify_event_end( $event_id ) {
	return bookify_booking_sanitize_event_time( get_post_meta( absint( $event_id ), 'bookify_event_end', true ) );
}

/**
 * How many people the event takes on its date.
 *
 * Zero is a real answer, not a missing one: it means the event has no ceiling of its own and the
 * tiers' own inventories are the only limit.
 *
 * @param int $event_id The event.
 * @return int
 */
function bookify_event_capacity( $event_id ) {
	return absint( get_post_meta( absint( $event_id ), 'bookify_event_capacity', true ) );
}

/**
 * When an event starts, on the site's own clock.
 *
 * @param int $event_id The event.
 * @return \DateTimeImmutable|null Null when the event has no readable date and start time.
 */
function bookify_event_datetime( $event_id ) {
	$date  = bookify_event_date( $event_id );
	$start = bookify_event_start( $event_id );

	if ( '' === $date || '' === $start ) {
		return null;
	}

	return date_create_immutable( $date . ' ' . $start, wp_timezone() );
}

/**
 * When an event finishes, on the site's own clock.
 *
 * An event with no end time lasts an hour, which is what makes "the event is over" answerable for an
 * owner who typed only a start. The summary is due at this moment (T36), so the fallback is stated
 * rather than hidden.
 *
 * @param int $event_id The event.
 * @return \DateTimeImmutable|null Null when the event has no readable date and start time.
 */
function bookify_event_ends_at( $event_id ) {
	$starts = bookify_event_datetime( $event_id );

	if ( null === $starts ) {
		return null;
	}

	$end = bookify_event_end( $event_id );

	return '' === $end
		? $starts->modify( '+1 hour' )
		: date_create_immutable( bookify_event_date( $event_id ) . ' ' . $end, wp_timezone() );
}

/**
 * Whether an event has already begun.
 *
 * The clock this decision uses is the site's own — wp_timezone(), the same one the diary's lead time
 * and the events' own dates are read in. This is what closes ticket sales: a ticket to something
 * that has started is not a ticket.
 *
 * @param int $event_id The event.
 * @return bool
 */
function bookify_event_has_started( $event_id ) {
	$starts = bookify_event_datetime( $event_id );

	return null === $starts || $starts <= new DateTimeImmutable( 'now', wp_timezone() );
}

/**
 * Whether an event is over.
 *
 * @param int $event_id The event.
 * @return bool
 */
function bookify_event_is_over( $event_id ) {
	$ends = bookify_event_ends_at( $event_id );

	return null === $ends || $ends <= new DateTimeImmutable( 'now', wp_timezone() );
}

/**
 * The event's date as a visitor reads it: the weekday, then the site's own date format.
 *
 * @param int $event_id The event.
 * @return string
 */
function bookify_event_date_label( $event_id ) {
	$date = bookify_event_date( $event_id );

	if ( '' === $date ) {
		return '';
	}

	$when = date_create_immutable( $date . ' 12:00:00', wp_timezone() );

	return wp_date( 'D', $when->getTimestamp() ) . ' ' . wp_date( (string) get_option( 'date_format' ), $when->getTimestamp() );
}

/**
 * The published tickets already sold for an event.
 *
 * "Sold" means holding a place, which is the same set the session model counts: an unpaid booking is
 * in it (the customer is paying for that seat), and a cancelled or expired one is not. The sweep runs
 * first for the reason it runs in the session counter — an availability question is exactly the
 * moment a stale unpaid booking starts to matter.
 *
 * @param int $event_id The event.
 * @return array<int,int> Booking id => tickets.
 */
function bookify_event_tickets_by_booking( $event_id ) {
	bookify_booking_sweep_expired();

	$bookings = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_event_id',
					'value' => absint( $event_id ),
				),
				array(
					'key'     => 'bookify_status',
					'value'   => bookify_booking_slot_holding_statuses(),
					'compare' => 'IN',
				),
			),
		)
	);

	$tickets = array();

	foreach ( $bookings as $booking_id ) {
		$tickets[ (int) $booking_id ] = max( 1, (int) get_post_meta( $booking_id, 'bookify_party_size', true ) );
	}

	return $tickets;
}

/**
 * The tickets sold for an event.
 *
 * @param int $event_id The event.
 * @return int
 */
function bookify_event_tickets_sold( $event_id ) {
	return array_sum( bookify_event_tickets_by_booking( $event_id ) );
}

/**
 * The places an event has left on its date.
 *
 * An event with no capacity stored has no ceiling of its own, and this says so by returning the
 * number of places its tiers still hold — never a number the ticket path would refuse.
 *
 * @param int $event_id The event.
 * @return int
 */
function bookify_event_places_left( $event_id ) {
	$capacity = bookify_event_capacity( $event_id );
	$sold     = bookify_event_tickets_sold( $event_id );

	if ( $capacity > 0 ) {
		return max( 0, $capacity - $sold );
	}

	$tiers = 0;

	foreach ( bookify_event_tiers( $event_id ) as $tier ) {
		$tiers += bookify_tier_places_left( $tier->ID );
	}

	return $tiers;
}

/**
 * The published ticket tiers of one event.
 *
 * @param int $event_id The event.
 * @return WP_Post[]
 */
function bookify_event_tiers( $event_id ) {
	$event_id = absint( $event_id );

	if ( ! $event_id ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => 'bookify_tier',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_event_id',
					'value' => $event_id,
				),
			),
		)
	);
}

/**
 * The event a tier belongs to.
 *
 * @param int $tier_id The tier.
 * @return int Event id, or 0.
 */
function bookify_tier_event_id( $tier_id ) {
	return absint( get_post_meta( absint( $tier_id ), 'bookify_event_id', true ) );
}

/**
 * The published tier a booking can be sold, or null.
 *
 * Bookable means a published tier that is attached to the event being booked: a tier belonging to
 * another event is not a cheaper way into this one.
 *
 * @param int $tier_id  Candidate tier id.
 * @param int $event_id The event being booked.
 * @return \WP_Post|null
 */
function bookify_booking_tier_of_event( $tier_id, $event_id ) {
	$tier = get_post( absint( $tier_id ) );

	if ( ! $tier instanceof WP_Post || 'bookify_tier' !== $tier->post_type || 'publish' !== $tier->post_status ) {
		return null;
	}

	if ( bookify_tier_event_id( $tier->ID ) !== absint( $event_id ) ) {
		return null;
	}

	return $tier;
}

/**
 * What one ticket costs, as stored.
 *
 * Read as it is written and then stored on the booking, so a price the owner changes afterwards
 * cannot rewrite what a customer was already told — the rule T23 set for a session's price.
 *
 * @param int $tier_id The tier.
 * @return float
 */
function bookify_tier_price( $tier_id ) {
	return max( 0.0, (float) get_post_meta( absint( $tier_id ), 'bookify_tier_price', true ) );
}

/**
 * A tier's price as a visitor reads it, in the site's own currency.
 *
 * The stored code rather than a page's symbol: this is the same string the booking stores, the
 * confirmation carries and Stripe is asked for, so there is one price and not two.
 *
 * @param int $tier_id The tier.
 * @return string
 */
function bookify_tier_price_label( $tier_id ) {
	return bookify_booking_amount_label( bookify_tier_price( $tier_id ) );
}

/**
 * How many tickets of this kind are for sale.
 *
 * @param int $tier_id The tier.
 * @return int
 */
function bookify_tier_capacity( $tier_id ) {
	return absint( get_post_meta( absint( $tier_id ), 'bookify_tier_capacity', true ) );
}

/**
 * The tickets of this tier already sold.
 *
 * @param int $tier_id The tier.
 * @return int
 */
function bookify_tier_tickets_sold( $tier_id ) {
	bookify_booking_sweep_expired();

	$bookings = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_tier_id',
					'value' => absint( $tier_id ),
				),
				array(
					'key'     => 'bookify_status',
					'value'   => bookify_booking_slot_holding_statuses(),
					'compare' => 'IN',
				),
			),
		)
	);

	$tickets = 0;

	foreach ( $bookings as $booking_id ) {
		$tickets += max( 1, (int) get_post_meta( $booking_id, 'bookify_party_size', true ) );
	}

	return $tickets;
}

/**
 * The places this tier has left.
 *
 * A tier with no capacity stored has none for sale, rather than being unlimited: an owner who has not
 * said how many of a kind exist has not put it on sale.
 *
 * @param int $tier_id The tier.
 * @return int
 */
function bookify_tier_places_left( $tier_id ) {
	return max( 0, bookify_tier_capacity( $tier_id ) - bookify_tier_tickets_sold( $tier_id ) );
}

/**
 * The event a booking is for, or null.
 *
 * Any status, not only published: a booking whose event has since been unpublished is still a
 * booking the customer paid for, and the name they are shown should be the event's rather than an
 * empty row.
 *
 * @param int $booking_id The booking.
 * @return \WP_Post|null
 */
function bookify_booking_event( $booking_id ) {
	$event = get_post( absint( get_post_meta( absint( $booking_id ), 'bookify_event_id', true ) ) );

	return $event instanceof WP_Post && 'bookify_event' === $event->post_type ? $event : null;
}

/**
 * The tier a booking was sold on, or null.
 *
 * Any status, for the same reason as the event above.
 *
 * @param int $booking_id The booking.
 * @return \WP_Post|null
 */
function bookify_booking_tier( $booking_id ) {
	$tier = get_post( absint( get_post_meta( absint( $booking_id ), 'bookify_tier_id', true ) ) );

	return $tier instanceof WP_Post && 'bookify_tier' === $tier->post_type ? $tier : null;
}

/**
 * What kind of thing a booking is for, as one word: an event or a session.
 *
 * @param int $booking_id The booking.
 * @return string
 */
function bookify_booking_item_kind( $booking_id ) {
	return bookify_booking_event( $booking_id )
		? __( 'Event', 'bookify-booking' )
		: __( 'Session', 'bookify-booking' );
}

/**
 * What a booking is for, as one line.
 *
 * The one place a booking's "what" is named, so the admin list, the customer's own page, the
 * confirmation and the reminder cannot disagree about it. A session that has since been unpublished
 * is left out rather than named wrongly (T7); an event is named whatever its status, because the
 * customer is holding a ticket for it.
 *
 * @param int $booking_id The booking.
 * @return string
 */
function bookify_booking_item_label( $booking_id ) {
	$event = bookify_booking_event( $booking_id );

	if ( $event ) {
		$tier = bookify_booking_tier( $booking_id );

		return $tier ? $event->post_title . ' — ' . $tier->post_title : $event->post_title;
	}

	$service = bookify_booking_bookable_service( get_post_meta( absint( $booking_id ), 'bookify_service_id', true ) );

	return $service ? $service->post_title : '';
}

/**
 * Register the event types and their meta.
 */
function bookify_booking_register_events() {
	bookify_booking_register_event_types();
	bookify_booking_register_event_meta();
}
