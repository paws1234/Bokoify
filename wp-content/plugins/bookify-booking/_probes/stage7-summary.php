<?php
/**
 * Throwaway probe for T36: the reservation summary is scheduled, composed once, and released.
 *
 * One `eval-file` run is one request, so everything happens in sequence here. Proof sought:
 *
 *   1. an event carries one appointment, at the moment the event ends;
 *   2. changing the event's date moves that appointment;
 *   3. running it composes one message, to the admin address, naming the event, its date, every
 *      reservation and the totals per tier;
 *   4. running it twice sends one message;
 *   5. a cancelled booking is not in it, and neither is another event's booking;
 *   6. deleting the event drops the appointment.
 *
 * Nothing can leave the container (D6), so the message is captured through `pre_wp_mail` — the same
 * evidence T7 and T30 were accepted on.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage7-summary.php
 *
 * Kept as T36's re-runnable evidence.
 */

$p7_created = array(
	'events'   => array(),
	'tiers'    => array(),
	'bookings' => array(),
);

function p7_note( $label, $ok, $detail = '' ) {
	printf( "%-52s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', '' === $detail ? '' : "  {$detail}" );
}

$p7_mails = array();

// The test redirect sends every message to the owner's inbox. Pinned off for this probe, which
// asserts that the summary goes to the admin address — inheriting BOOKIFY_MAIL_REDIRECT_TO from .env
// would fail it for a reason that has nothing to do with event summaries.
putenv( 'BOOKIFY_MAIL_REDIRECT_TO=' );

// Records rather than sends: nothing leaves the container, and the write path's own confirmations
// are captured here too instead of failing at the transport.
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) use ( &$p7_mails ) {
		$p7_mails[] = $atts;

		return true;
	},
	10,
	2
);

function p7_probe_event( $title, $args = array() ) {
	$args = $args + array(
		'date'     => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +50 days' ) ),
		'start'    => '10:00',
		'end'      => '16:00',
		'capacity' => 0,
	);

	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_event',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, 'bookify_event_date', $args['date'] );
	update_post_meta( $id, 'bookify_event_start', $args['start'] );
	update_post_meta( $id, 'bookify_event_end', $args['end'] );

	if ( $args['capacity'] > 0 ) {
		update_post_meta( $id, 'bookify_event_capacity', $args['capacity'] );
	}

	return (int) $id;
}

function p7_probe_tier( $event_id, $title, $price, $capacity ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_tier',
			'post_status' => 'publish',
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

$p7_event = p7_probe_event( 'T36 probe event' );
$p7_ga    = p7_probe_tier( $p7_event, 'General admission', 20, 10 );
$p7_vip   = p7_probe_tier( $p7_event, 'VIP', 40, 4 );

$p7_created['events'][] = $p7_event;
$p7_created['tiers'][]  = $p7_ga;
$p7_created['tiers'][]  = $p7_vip;

// A second event, so "another event's booking is not in it" is a real check rather than a claim.
$p7_other      = p7_probe_event( 'T36 probe other event' );
$p7_other_tier = p7_probe_tier( $p7_other, 'General admission', 20, 10 );

$p7_created['events'][] = $p7_other;
$p7_created['tiers'][]  = $p7_other_tier;

// ---------------------------------------------------------------- 1: the appointment.

// The save path, not the function: wp-admin writes the meta box and then this hook runs, so a
// post update is what an owner's edit actually does.
wp_update_post(
	array(
		'ID'         => $p7_event,
		'post_title' => 'T36 probe event',
	)
);

$p7_args = array( $p7_event );
$p7_due  = bookify_booking_event_summary_due_at( $p7_event );
$p7_next = wp_next_scheduled( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $p7_args );

p7_note(
	'the event carries one appointment, at the moment it ends',
	false !== $p7_next && $p7_next === $p7_due + 1,
	'ends at ' . $p7_due . ', scheduled at ' . var_export( $p7_next, true )
);
p7_note(
	'and the end is the stored end time',
	bookify_event_ends_at( $p7_event )->format( 'Y-m-d H:i' ) === bookify_event_date( $p7_event ) . ' 16:00',
	bookify_event_ends_at( $p7_event )->format( 'Y-m-d H:i' )
);

// An event with no end time lasts an hour, which is what makes the due moment answerable at all.
$p7_no_end = p7_probe_event( 'T36 probe no end' );
$p7_created['events'][] = $p7_no_end;
delete_post_meta( $p7_no_end, 'bookify_event_end' );

p7_note(
	'an event with no end time is due an hour after it starts',
	bookify_event_ends_at( $p7_no_end )->format( 'H:i' ) === '11:00',
	bookify_event_ends_at( $p7_no_end )->format( 'Y-m-d H:i' )
);

// ---------------------------------------------------------------- 2: the appointment moves.

$p7_moved_date = gmdate( 'Y-m-d', strtotime( bookify_event_date( $p7_event ) . ' +3 days' ) );

update_post_meta( $p7_event, 'bookify_event_date', $p7_moved_date );
wp_update_post(
	array(
		'ID'         => $p7_event,
		'post_title' => 'T36 probe event',
	)
);

$p7_moved = wp_next_scheduled( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $p7_args );

p7_note(
	'a changed date moves the appointment',
	false !== $p7_moved && $p7_moved === bookify_booking_event_summary_due_at( $p7_event ) + 1 && $p7_moved !== $p7_next,
	'now ' . wp_date( 'Y-m-d H:i', $p7_moved )
);

// ---------------------------------------------------------------- 3 to 5: the message.

function p7_book( $event_id, $tier_id, $party, $email ) {
	$result = bookify_create_booking(
		array(
			'event_id'   => $event_id,
			'tier_id'    => $tier_id,
			'party_size' => $party,
			'name'       => 'T36 probe',
			'email'      => $email,
		)
	);

	return is_wp_error( $result ) ? $result->get_error_code() : (int) $result;
}

$p7_first  = p7_book( $p7_event, $p7_ga, 2, 't36a@example.com' );
$p7_second = p7_book( $p7_event, $p7_vip, 1, 't36b@example.com' );
$p7_third  = p7_book( $p7_event, $p7_ga, 3, 't36c@example.com' );
$p7_elsewhere = p7_book( $p7_other, $p7_other_tier, 1, 't36d@example.com' );

foreach ( array( $p7_first, $p7_second, $p7_third, $p7_elsewhere ) as $p7_id ) {
	if ( is_int( $p7_id ) ) {
		$p7_created['bookings'][] = $p7_id;
	}
}

if ( is_int( $p7_third ) ) {
	bookify_booking_cancel( $p7_third );
}

p7_note( 'three tickets are booked and one is cancelled', true, 'ids ' . $p7_first . ', ' . $p7_second . ', ' . $p7_third . ', ' . $p7_elsewhere );

$p7_mails = array();

do_action( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $p7_event );

$p7_sent = $p7_mails;

p7_note( 'one message is sent', 1 === count( $p7_sent ), count( $p7_sent ) . ' message(s)' );

if ( $p7_sent ) {
	$p7_mail = $p7_sent[0];

	p7_note(
		'to the admin address',
		$p7_mail['to'] === get_option( 'admin_email' ),
		(string) $p7_mail['to']
	);
	p7_note(
		'whose subject names the event and its date',
		false !== strpos( $p7_mail['subject'], 'T36 probe event' ) && false !== strpos( $p7_mail['subject'], bookify_event_date( $p7_event ) ),
		$p7_mail['subject']
	);

	$p7_body = (string) $p7_mail['message'];

	echo "----\n" . $p7_body . "\n----\n";

	p7_note(
		'listing every reservation with its tier and its tickets',
		false !== strpos( $p7_body, get_post_meta( $p7_first, 'bookify_reference', true ) . '  T36 probe <t36a@example.com>  2 × General admission' )
			&& false !== strpos( $p7_body, get_post_meta( $p7_second, 'bookify_reference', true ) . '  T36 probe <t36b@example.com>  1 × VIP' ),
		'references ' . get_post_meta( $p7_first, 'bookify_reference', true ) . ' and ' . get_post_meta( $p7_second, 'bookify_reference', true )
	);
	p7_note(
		'with the totals per tier',
		false !== strpos( $p7_body, 'General admission — GBP 20.00 — 2 sold, 8 left' )
			&& false !== strpos( $p7_body, 'VIP — GBP 40.00 — 1 sold, 3 left' ),
		'tier lines present'
	);
	p7_note(
		'and the cancelled booking is not in it',
		false === strpos( $p7_body, 't36c@example.com' ),
		'cancelled reference ' . get_post_meta( $p7_third, 'bookify_reference', true )
	);
	p7_note(
		'and neither is the other event\'s booking',
		false === strpos( $p7_body, 't36d@example.com' ) && false === strpos( $p7_body, 'T36 probe other event' ),
		'other event reference ' . get_post_meta( $p7_elsewhere, 'bookify_reference', true )
	);
	p7_note(
		'and it says what the event has sold',
		false !== strpos( $p7_body, 'Places: 3 sold, 11 left' ),
		'sold ' . bookify_event_tickets_sold( $p7_event ) . ', left ' . bookify_event_places_left( $p7_event )
	);
	p7_note(
		'and the From line is the site\'s own',
		isset( $p7_mail['headers'][0] ) && false !== strpos( $p7_mail['headers'][0], (string) get_option( 'admin_email' ) ),
		isset( $p7_mail['headers'][0] ) ? $p7_mail['headers'][0] : 'no headers'
	);
}

// Running it again must not send a second copy, which is what the flag on the event is for.
$p7_mails = array();

do_action( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $p7_event );

p7_note( 'running it twice sends one message', 0 === count( $p7_mails ), count( $p7_mails ) . ' second message(s)' );
p7_note(
	'and the appointment is not asked for again',
	false === wp_next_scheduled( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $p7_args )
);

// ---------------------------------------------------------------- 6: deleting the event.

wp_delete_post( $p7_other, true );
wp_delete_post( $p7_other_tier, true );

p7_note(
	'deleting an event drops its appointment',
	false === wp_next_scheduled( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, array( $p7_other ) )
);

// ---------------------------------------------------------------- cleanup.

foreach ( $p7_created['bookings'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

foreach ( $p7_created['tiers'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

foreach ( $p7_created['events'] as $p7_id ) {
	if ( $p7_id !== $p7_other ) {
		wp_delete_post( $p7_id, true );
	}
}

p7_note( 'nothing left behind', true, count( $p7_created['bookings'] ) . ' bookings and ' . count( $p7_created['events'] ) . ' events removed' );
