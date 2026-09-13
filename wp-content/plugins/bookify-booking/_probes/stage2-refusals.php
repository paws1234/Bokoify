<?php
/**
 * Throwaway probe for T16: every refusal, the slot list, and the advisory lock. Deleted after use.
 */

$GLOBALS['probe_created'] = array();

function probe_booking( $service_id, $date, $time, $party = 1, $name = 'Probe Guest' ) {
	$result = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => $name,
			'email'      => 'probe@example.com',
			'phone'      => '555 0100',
			'date'       => $date,
			'time'       => $time,
			'party_size' => $party,
		)
	);

	if ( ! is_wp_error( $result ) ) {
		$GLOBALS['probe_created'][] = $result;
	}

	return $result;
}

function probe_line( $label, $result ) {
	$code = is_wp_error( $result ) ? $result->get_error_code() : 'BOOKED id=' . $result;

	printf( "%-48s %s\n", $label, $code );
}

function probe_count() {
	return count(
		get_posts(
			array(
				'post_type'      => 'bookify_booking',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);
}

/**
 * Run a case with the option changed, then put the option back.
 */
function probe_with_option( array $change, callable $callback ) {
	$original = get_option( 'bookify_availability' );

	update_option( 'bookify_availability', array_merge( (array) $original, $change ) );

	$result = $callback();

	update_option( 'bookify_availability', $original );

	return $result;
}

$single = 19;  // Deep tissue massage: 60 minutes, 1 guest per slot.
$pair   = 86;  // Couples massage: 75 minutes, 2 guests per slot.

echo "today=" . current_time( 'Y-m-d H:i' ) . " weekday=" . current_time( 'w' ) . "\n";

$stored = get_option( 'bookify_availability' );

echo 'option round-trips through its own sanitiser: '
	. ( bookify_booking_sanitize_availability( $stored ) === $stored ? 'yes' : 'NO' )
	. ' (' . count( $stored ) . " keys)\n";

$target = '';
$cursor = date_create_immutable( 'now', wp_timezone() )->setTime( 12, 0 );

for ( $offset = 3; $offset < 20; $offset++ ) {
	$candidate = $cursor->modify( '+' . $offset . ' day' )->format( 'Y-m-d' );

	if ( bookify_is_open_on( $candidate ) ) {
		$target = $candidate;
		break;
	}
}

echo "target day=$target\n";
echo 'slots, 60-minute service: ' . implode( ' ', bookify_available_slots( $single, $target ) ) . "\n";
echo 'slots, 75-minute service: ' . implode( ' ', bookify_available_slots( $pair, $target ) ) . "\n";

$before = probe_count();

echo "\n--- refusals: each one must create nothing ---\n";

probe_line( 'closed day (Sunday 2026-09-13) 10:00', probe_booking( $single, '2026-09-13', '10:00' ) );
probe_line(
	'blocked date, 10:00',
	probe_with_option(
		array( 'blocked_dates' => array( $target ) ),
		static function () use ( $single, $target ) {
			return probe_booking( $single, $target, '10:00' );
		}
	)
);
probe_line(
	'inside the lead time (lead_hours=240)',
	probe_with_option(
		array( 'lead_hours' => 240 ),
		static function () use ( $single, $target ) {
			return probe_booking( $single, $target, '10:00' );
		}
	)
);
probe_line( 'off the grid (10:15)', probe_booking( $single, $target, '10:15' ) );
probe_line( 'before opening (08:30)', probe_booking( $single, $target, '08:30' ) );
probe_line( 'cannot fit before closing (16:30)', probe_booking( $single, $target, '16:30' ) );
probe_line( 'nonsense time (25:99)', probe_booking( $single, $target, '25:99' ) );
probe_line( 'past date', probe_booking( $single, '2026-01-05', '10:00' ) );
probe_line( 'unknown service', probe_booking( 99999, $target, '10:00' ) );

echo 'bookings after the refusals: ' . probe_count() . " (was $before)\n";

echo "\n--- a real booking, then the slot is full ---\n";

probe_line( 'slot on offer, 1 guest, capacity 1', probe_booking( $single, $target, '10:00' ) );

echo 'slots now (10:00 must be gone): ' . implode( ' ', bookify_available_slots( $single, $target ) ) . "\n";

probe_line( 'the same slot again', probe_booking( $single, $target, '10:00', 1, 'Probe Rival' ) );

echo "\n--- a party bigger than the room left ---\n";

probe_line( 'couples slot, 1 of 2 guests', probe_booking( $pair, $target, '10:00', 1, 'Probe Couple' ) );
probe_line( 'couples slot, 2 more (only 1 place left)', probe_booking( $pair, $target, '10:00', 2, 'Probe Overfill' ) );
probe_line( 'couples slot, 1 more (fits)', probe_booking( $pair, $target, '10:00', 1, 'Probe Fits' ) );

echo 'couples bookings at that slot: '
	. count(
		array_filter(
			$GLOBALS['probe_created'],
			static function ( $id ) use ( $pair, $target ) {
				return $pair === (int) get_post_meta( $id, 'bookify_service_id', true )
					&& $target === get_post_meta( $id, 'bookify_date', true );
			}
		)
	)
	. " (expected 2)\n";

echo "\n--- cancelling gives the place back ---\n";

foreach ( $GLOBALS['probe_created'] as $booking_id ) {
	$matches = $single === (int) get_post_meta( $booking_id, 'bookify_service_id', true )
		&& $target === get_post_meta( $booking_id, 'bookify_date', true )
		&& '10:00' === get_post_meta( $booking_id, 'bookify_time', true );

	if ( $matches ) {
		update_post_meta( $booking_id, 'bookify_status', 'cancelled' );
		echo 'cancelled ' . get_post_meta( $booking_id, 'bookify_reference', true ) . "\n";
	}
}

echo 'slots after cancelling (10:00 must be back): ' . implode( ' ', bookify_available_slots( $single, $target ) ) . "\n";
probe_line( 'booking 10:00 again', probe_booking( $single, $target, '10:00', 1, 'Probe After Cancel' ) );

echo "\n--- the lock really excludes a second connection ---\n";

global $wpdb;

$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

$held  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', BOOKIFY_BOOKING_SLOT_LOCK ) );
$rival = $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 1)', BOOKIFY_BOOKING_SLOT_LOCK ) );

echo 'first connection took the lock: ' . var_export( $held, true ) . "\n";
echo 'second connection while it is held: ' . var_export( $rival, true ) . " (expected '0')\n";

bookify_booking_unlock_slot();

echo 'second connection after the release: ' . var_export( $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 1)', BOOKIFY_BOOKING_SLOT_LOCK ) ), true ) . " (expected '1')\n";

$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', BOOKIFY_BOOKING_SLOT_LOCK ) );

echo "\n--- cleaning up the bookings this probe created ---\n";

$deleted = 0;

foreach ( $GLOBALS['probe_created'] as $booking_id ) {
	wp_delete_post( $booking_id, true );
	$deleted++;
}

echo "deleted $deleted bookings, bookings left: " . probe_count() . "\n";
