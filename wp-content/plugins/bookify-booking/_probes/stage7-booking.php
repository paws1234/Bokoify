<?php
/**
 * Throwaway probe for T34: a ticket goes through the write path, and the two capacity rules hold.
 *
 * One `eval-file` run is one request, so everything happens in sequence here. Proof sought:
 *
 *   1. a valid ticket is created, and its date and time are the event's rather than the request's;
 *   2. what a booking is for is stored as an event and a tier, and what it costs is the tier's price
 *      for the number of tickets;
 *   3. a party larger than the tier's inventory is refused with `bookify_tier_full`;
 *   4. a party larger than the event's capacity is refused with `bookify_event_full`;
 *   5. a draft event, an event that has started and a tier belonging to another event are refused;
 *   6. cancelling a ticket gives its place back to both counts;
 *   7. a ticket cannot be moved, and says so rather than being refused as "too soon";
 *   8. a session booking still behaves exactly as it did.
 *
 * Everything it creates is deleted at the end, so it is safe to re-run:
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage7-booking.php
 *
 * Kept as T34's re-runnable evidence.
 */

$p7_created = array(
	'bookings' => array(),
	'events'   => array(),
	'tiers'    => array(),
);

function p7_note( $label, $ok, $detail = '' ) {
	printf( "%-52s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', '' === $detail ? '' : "  {$detail}" );
}

function p7_event( $title, $args = array() ) {
	$args = $args + array(
		'date'     => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +30 days' ) ),
		'start'    => '10:00',
		'end'      => '16:00',
		'capacity' => 0,
		'status'   => 'publish',
	);

	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_event',
			'post_status' => $args['status'],
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, 'bookify_event_date', $args['date'] );
	update_post_meta( $id, 'bookify_event_start', $args['start'] );

	if ( '' !== $args['end'] ) {
		update_post_meta( $id, 'bookify_event_end', $args['end'] );
	}

	if ( $args['capacity'] > 0 ) {
		update_post_meta( $id, 'bookify_event_capacity', $args['capacity'] );
	}

	return (int) $id;
}

function p7_tier( $event_id, $title, $price, $capacity, $status = 'publish' ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_tier',
			'post_status' => $status,
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, 'bookify_event_id', (int) $event_id );
	update_post_meta( $id, 'bookify_tier_price', $price );
	update_post_meta( $id, 'bookify_tier_capacity', $capacity );

	return (int) $id;
}

function p7_book( $data ) {
	$data = $data + array(
		'name'       => 'T34 probe',
		'email'      => 't34@example.com',
		'phone'      => '',
		'party_size' => 1,
	);

	$result = bookify_create_booking( $data );

	return is_wp_error( $result ) ? $result->get_error_code() : (int) $result;
}

function p7_count_bookings() {
	$counts = wp_count_posts( 'bookify_booking' );

	return (int) $counts->publish + (int) $counts->draft + (int) $counts->private;
}

// Nothing may leave the container (D6), so the mail the write path sends is captured here rather
// than attempted; this also keeps debug.log clean of transport errors.
add_filter(
	'pre_wp_mail',
	function () {
		return true;
	}
);

$p7_before = p7_count_bookings();

// ---------------------------------------------------------------- 1, 2, 3, 4: the happy path.

$p7_event    = p7_event( 'T34 probe event', array( 'capacity' => 10 ) );
$p7_ga       = p7_tier( $p7_event, 'General admission', 15, 2 );
$p7_vip      = p7_tier( $p7_event, 'VIP', 35, 5 );
$p7_created['events'][] = $p7_event;
$p7_created['tiers'][]  = $p7_ga;
$p7_created['tiers'][]  = $p7_vip;

p7_note( 'event is bookable', null !== bookify_booking_bookable_event( $p7_event ), 'event ' . $p7_event );
p7_note( 'two tiers listed', 2 === count( bookify_event_tiers( $p7_event ) ) );
p7_note( 'GA places left before any sale', 2 === bookify_tier_places_left( $p7_ga ) );
p7_note( 'event places left before any sale', 10 === bookify_event_places_left( $p7_event ) );

$p7_valid = p7_book(
	array(
		'event_id'   => $p7_event,
		'tier_id'    => $p7_ga,
		'party_size' => 2,
		// A request that tries to name its own date, time and price: none of them may be stored.
		'date'       => '2030-01-01',
		'time'       => '23:59',
	)
);

if ( is_int( $p7_valid ) ) {
	$p7_created['bookings'][] = $p7_valid;

	p7_note( 'a valid ticket is created', true, 'booking ' . $p7_valid );
	p7_note(
		'its date and time are the event\'s, not the request\'s',
		bookify_event_date( $p7_event ) === get_post_meta( $p7_valid, 'bookify_date', true )
			&& '10:00' === get_post_meta( $p7_valid, 'bookify_time', true ),
		get_post_meta( $p7_valid, 'bookify_date', true ) . ' ' . get_post_meta( $p7_valid, 'bookify_time', true )
	);
	p7_note(
		'it names the event and the tier',
		$p7_event === absint( get_post_meta( $p7_valid, 'bookify_event_id', true ) )
			&& $p7_ga === absint( get_post_meta( $p7_valid, 'bookify_tier_id', true ) )
	);
	p7_note(
		'it stores the tier price for the tickets bought',
		30.0 === (float) get_post_meta( $p7_valid, 'bookify_payment_amount', true )
			&& 'awaiting_payment' === get_post_meta( $p7_valid, 'bookify_status', true )
			&& 'unpaid' === get_post_meta( $p7_valid, 'bookify_payment_status', true ),
		'amount ' . get_post_meta( $p7_valid, 'bookify_payment_amount', true )
	);
	p7_note(
		'it names itself as an event booking',
		'Event' === bookify_booking_item_kind( $p7_valid )
			&& 'T34 probe event — General admission' === bookify_booking_item_label( $p7_valid ),
		bookify_booking_item_label( $p7_valid )
	);
	p7_note(
		'cancelling it would give both counts back',
		2 === bookify_tier_tickets_sold( $p7_ga ) && 2 === bookify_event_tickets_sold( $p7_event )
	);
	p7_note( 'GA places left now', 0 === bookify_tier_places_left( $p7_ga ) );
} else {
	p7_note( 'a valid ticket is created', false, 'refused with ' . $p7_valid );
}

// A party larger than the tier's own inventory, which still fits the event.
$p7_over_tier = p7_book(
	array(
		'event_id'   => $p7_event,
		'tier_id'    => $p7_ga,
		'party_size' => 3,
	)
);

p7_note( 'more tickets than the tier has left is refused', 'bookify_tier_full' === $p7_over_tier, $p7_over_tier );
p7_note( 'and nothing was written', $p7_before + 1 === p7_count_bookings() );

// A party the tier can cover but the event cannot: the event's capacity is the second ceiling.
$p7_vip_fill = p7_book(
	array(
		'event_id'   => $p7_event,
		'tier_id'    => $p7_vip,
		'party_size' => 5,
	)
);

if ( is_int( $p7_vip_fill ) ) {
	$p7_created['bookings'][] = $p7_vip_fill;
}

// The event's own capacity is a second ceiling, and it is the one that binds here: the fresh event
// takes 2 people and its single tier could sell 10, so the first ticket that does not fit is refused
// for the event rather than for the tier.
$p7_tight     = p7_event( 'T34 probe tight event', array( 'capacity' => 2 ) );
$p7_tight_vip = p7_tier( $p7_tight, 'VIP', 35, 10 );
$p7_created['events'][] = $p7_tight;
$p7_created['tiers'][]  = $p7_tight_vip;

$p7_tight_two = p7_book(
	array(
		'event_id'   => $p7_tight,
		'tier_id'    => $p7_tight_vip,
		'party_size' => 2,
	)
);

if ( is_int( $p7_tight_two ) ) {
	$p7_created['bookings'][] = $p7_tight_two;
}

$p7_over_event = p7_book(
	array(
		'event_id'   => $p7_tight,
		'tier_id'    => $p7_tight_vip,
		'party_size' => 1,
	)
);

p7_note(
	'a party the event has no room for is refused',
	'bookify_event_full' === $p7_over_event && 8 === bookify_tier_places_left( $p7_tight_vip ),
	$p7_over_event . ', tier still has ' . bookify_tier_places_left( $p7_tight_vip )
);

// ---------------------------------------------------------------- 5: refusals about the event.

$p7_draft     = p7_event( 'T34 probe draft', array( 'status' => 'draft' ) );
$p7_draft_tier = p7_tier( $p7_draft, 'GA', 10, 5 );
$p7_created['events'][] = $p7_draft;
$p7_created['tiers'][]  = $p7_draft_tier;

p7_note(
	'a draft event is not on sale',
	'bookify_unknown_event' === p7_book( array( 'event_id' => $p7_draft, 'tier_id' => $p7_draft_tier ) )
);

$p7_past      = p7_event( 'T34 probe past', array( 'date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 days' ) ) ) );
$p7_past_tier = p7_tier( $p7_past, 'GA', 10, 5 );
$p7_created['events'][] = $p7_past;
$p7_created['tiers'][]  = $p7_past_tier;

p7_note(
	'an event that has started cannot be booked',
	'bookify_event_passed' === p7_book( array( 'event_id' => $p7_past, 'tier_id' => $p7_past_tier ) )
);
p7_note( 'and it is over', bookify_event_is_over( $p7_past ) );
p7_note( 'and it has started', bookify_event_has_started( $p7_past ) );

p7_note(
	'a tier belonging to another event is refused',
	'bookify_unknown_tier' === p7_book( array( 'event_id' => $p7_event, 'tier_id' => $p7_past_tier ) )
);

$p7_draft_tier_id = p7_tier( $p7_event, 'T34 probe draft tier', 10, 5, 'draft' );
$p7_created['tiers'][] = $p7_draft_tier_id;

p7_note(
	'a draft tier is refused',
	'bookify_unknown_tier' === p7_book( array( 'event_id' => $p7_event, 'tier_id' => $p7_draft_tier_id ) )
);
p7_note(
	'a tier with nothing left is refused',
	'bookify_tier_full' === p7_book( array( 'event_id' => $p7_event, 'tier_id' => $p7_ga, 'party_size' => 1 ) )
);

// ---------------------------------------------------------------- 6: a cancellation gives it back.

if ( is_int( $p7_valid ) ) {
	$p7_cancelled = bookify_booking_cancel( $p7_valid );

	p7_note(
		'cancelling gives the places back to both counts',
		$p7_cancelled
			&& 0 === bookify_tier_tickets_sold( $p7_ga )
			&& 2 === bookify_tier_places_left( $p7_ga )
			&& 5 === bookify_event_tickets_sold( $p7_event )
			&& 5 === bookify_event_places_left( $p7_event ),
		'event sold ' . bookify_event_tickets_sold( $p7_event ) . ', left ' . bookify_event_places_left( $p7_event )
	);
}

// ---------------------------------------------------------------- 7: a ticket cannot be moved.

if ( is_int( $p7_valid ) ) {
	$p7_move = bookify_booking_can_move( $p7_valid );

	p7_note(
		'a ticket cannot be moved to another time',
		is_wp_error( $p7_move ) && 'bookify_event_not_movable' === $p7_move->get_error_code(),
		is_wp_error( $p7_move ) ? $p7_move->get_error_code() : 'allowed'
	);
}

// ---------------------------------------------------------------- 8: the session path is unchanged.

$p7_service = bookify_booking_published_services() ? bookify_booking_published_services()[0] : null;

if ( $p7_service ) {
	$p7_days = bookify_available_days( $p7_service->ID );

	if ( $p7_days ) {
		$p7_slots = bookify_available_slots( $p7_service->ID, $p7_days[0] );

		if ( $p7_slots ) {
			$p7_session = p7_book(
				array(
					'service_id' => $p7_service->ID,
					'date'       => $p7_days[0],
					'time'       => $p7_slots[0],
					'party_size' => 1,
				)
			);

			if ( is_int( $p7_session ) ) {
				$p7_created['bookings'][] = $p7_session;
			}

			p7_note(
				'a session booking still works, and names its session',
				is_int( $p7_session )
					&& $p7_service->post_title === bookify_booking_item_label( $p7_session )
					&& 'Session' === bookify_booking_item_kind( $p7_session ),
				is_int( $p7_session ) ? 'booking ' . $p7_session : $p7_session
			);
		}
	}
}

// ---------------------------------------------------------------- cleanup.

foreach ( $p7_created['bookings'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

foreach ( $p7_created['tiers'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

foreach ( $p7_created['events'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

p7_note( 'nothing left behind', $p7_before === p7_count_bookings(), $p7_before . ' bookings before and after' );
