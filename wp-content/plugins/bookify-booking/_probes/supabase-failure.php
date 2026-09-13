<?php
/**
 * Proves the mirror cannot break a booking.
 *
 * That is the whole reason the mirror runs on `shutdown` behind a try/catch and records its failures
 * instead of raising them, so it is the claim most worth testing rather than asserting. Two states:
 *
 *  1. **Unconfigured** — the state every site is in until somebody sets a URL and a key. The hooks are
 *     registered either way, so this checks they cost nothing and write nothing.
 *  2. **Configured and broken** — a project that does not exist, so the push fails at DNS. This needs
 *     no Supabase account and sends no credential anywhere, and it is the interesting case: a booking
 *     is taken, the confirmation is composed, the failure is recorded, and the booking is intact.
 *
 * It books a real slot and deletes it again at the end, which is what the other booking probes do.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-failure.php
 *
 * @package bookify-booking
 */

// Nothing may leave the container (D6), and this probe sends a real confirmation otherwise.
add_filter(
	'pre_wp_mail',
	function () {
		return true;
	}
);

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function sf_note( $label, $ok, $detail = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

$services   = bookify_booking_published_services();
$service_id = $services ? (int) $services[0]->ID : 0;

if ( ! $service_id ) {
	sf_note( 'a published session exists to book', false );

	return;
}

$days  = bookify_available_days( $service_id );
$day   = $days ? (string) $days[0] : '';
$slots = $day ? array_values( bookify_available_slots( $service_id, $day ) ) : array();
$time  = $slots ? (string) $slots[0] : '';

if ( '' === $day || '' === $time ) {
	sf_note( 'the session has an open slot to book', false, sprintf( 'day=%s time=%s', var_export( $day, true ), var_export( $time, true ) ) );

	return;
}

// ------------------------------------------------------------------ 1: unconfigured, the default state

delete_option( BOOKIFY_SUPABASE_LAST_RESULT_OPTION );

sf_note( 'with nothing configured, the mirror is off', ! bookify_booking_supabase_is_configured() );

// Queue something by hand, so the check is about the flush and not about whether a hook fired.
bookify_booking_supabase_queue_upsert( 'bookify_services', $service_id );
bookify_booking_supabase_flush();

sf_note( 'an unconfigured flush writes nothing at all', 0 === bookify_booking_supabase_last_result()['time'] );

// ------------------------------------------------------ 2: configured, but pointing at nothing

// A host that does not resolve: the request fails at DNS, so no key is ever sent anywhere and this
// needs no project. `.supabase.co` is deliberately not used — a real-looking name invites somebody to
// paste a real key in beside it later.
putenv( 'BOOKIFY_SUPABASE_URL=https://bookify-probe-does-not-exist.invalid' );
putenv( 'BOOKIFY_SUPABASE_KEY=probe-key-that-is-never-sent-anywhere' );

sf_note( 'a URL and a key is enough to switch it on', bookify_booking_supabase_is_configured() );

// Measured before the booking, because the assertion afterwards has to be about what the booking did
// rather than about what the diary offers: `bookify_slot_is_offered()` answers "is this a time the
// diary generates at all, bookings aside" and is true either way.
$remaining_before = bookify_slot_remaining( $service_id, $day, $time );

$booking = bookify_create_booking(
	array(
		'service_id' => $service_id,
		'name'       => 'Mirror failure probe',
		'email'      => 'mirror-failure@example.invalid',
		'date'       => $day,
		'time'       => $time,
		'party_size' => 1,
	)
);

sf_note(
	'a booking is still taken while the mirror is broken',
	! is_wp_error( $booking ),
	is_wp_error( $booking ) ? $booking->get_error_message() : 'booking ' . $booking
);

if ( is_wp_error( $booking ) ) {
	return;
}

sf_note( 'the booking is a real, stored booking', 'publish' === get_post_status( $booking ) );
sf_note( 'with its reference written', '' !== (string) get_post_meta( $booking, 'bookify_reference', true ) );
sf_note( 'and still maps to a row for the mirror', is_array( bookify_booking_supabase_booking_row( $booking ) ) );

// The booking above queued itself through the ordinary meta hooks; this is the push that fails.
bookify_booking_supabase_flush();

$result = bookify_booking_supabase_last_result();

sf_note( 'the flush ran', $result['time'] > 0 );
sf_note( 'and recorded a failure rather than throwing', ! $result['ok'] && '' !== $result['message'], $result['message'] );
sf_note( 'nothing was claimed as written', 0 === $result['upserted'] && 0 === $result['deleted'] );

// The point of the whole file: after a failed push the booking is exactly as it was.
sf_note( 'the booking survived the failed push', 'publish' === get_post_status( $booking ) );
sf_note(
	'and the place it took is really taken, so nothing was rolled back',
	$remaining_before - 1 === bookify_slot_remaining( $service_id, $day, $time ),
	sprintf( 'places left was %d, is %d', $remaining_before, bookify_slot_remaining( $service_id, $day, $time ) )
);

// Unset before the deletion below, so the shutdown flush is inert. `wp_delete_post()` queues a
// removal, and with the environment still pointing at a host that does not resolve that removal would
// fail again — re-creating, at shutdown, the very record this probe is about to clear.
putenv( 'BOOKIFY_SUPABASE_URL' );
putenv( 'BOOKIFY_SUPABASE_KEY' );

delete_option( BOOKIFY_SUPABASE_LAST_RESULT_OPTION );

wp_delete_post( $booking, true );

sf_note( 'cleaned up', null === bookify_booking_supabase_booking_row( $booking ) );
sf_note( 'and left no last-push record behind', 0 === bookify_booking_supabase_last_result()['time'] );
