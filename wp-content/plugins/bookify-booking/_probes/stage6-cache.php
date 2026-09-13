<?php
/**
 * Throwaway probe for T28's correctness requirement: a booking must be visible in the next
 * availability read, and a cache must not be able to serve a slot that is gone.
 *
 * Three requests, because the point is what survives between them:
 *
 *   wp option update bookify_probe_phase prime && wp eval-file …/stage6-cache.php
 *   wp option update bookify_probe_phase book  && wp eval-file …/stage6-cache.php
 *   wp option update bookify_probe_phase check && wp eval-file …/stage6-cache.php
 *
 * Kept as T28's evidence.
 */

$phase      = (string) get_option( 'bookify_probe_phase', '' );
$service_id = 85;
$open       = bookify_open_days();
$day        = $open ? $open[0] : '';
$version    = bookify_booking_availability_version();

function probe_slots( $service_id, $day ) {
	return array_values( bookify_available_slots( $service_id, $day ) );
}

switch ( $phase ) {
	case 'prime':
		$slots = probe_slots( $service_id, $day );

		update_option( 'bookify_probe_state', array( 'day' => $day, 'time' => $slots[0] ?? '', 'version' => $version ) );

		printf(
			"prime: version=%d day=%s slots=%d first=%s\n",
			$version,
			$day,
			count( $slots ),
			$slots[0] ?? '(none)'
		);
		break;

	case 'book':
		$state  = get_option( 'bookify_probe_state', array() );
		$result = bookify_create_booking(
			array(
				'service_id' => $service_id,
				'name'       => 'T28 cache probe',
				'email'      => 't28-cache@example.com',
				'date'       => $state['day'],
				'time'       => $state['time'],
				'party_size' => 8, // The whole class: the slot must vanish, not just lose a place.
			)
		);

		update_option( 'bookify_probe_state', array_merge( $state, array( 'booking' => is_wp_error( $result ) ? 0 : (int) $result ) ) );

		printf(
			"book:  version=%d booked=%s (was %d)\n",
			bookify_booking_availability_version(),
			is_wp_error( $result ) ? $result->get_error_code() : '#' . $result,
			$version
		);
		break;

	case 'check':
		$state   = get_option( 'bookify_probe_state', array() );
		$slots   = probe_slots( $service_id, $day );
		$before  = (int) ( $state['version'] ?? 0 );
		$current = bookify_booking_availability_version();

		printf(
			"check: version=%d (was %d)\n",
			$current,
			$before
		);
		printf(
			"       the booked slot %s is %s the list of %d slots\n",
			$state['time'] ?? '?',
			in_array( $state['time'] ?? '', $slots, true ) ? 'STILL IN' : 'gone from',
			count( $slots )
		);

		$booking = (int) ( $state['booking'] ?? 0 );

		if ( $booking ) {
			printf(
				"       write path agrees: %d places left, slot offered=%s\n",
				bookify_slot_remaining( $service_id, $day, (string) $state['time'] ),
				bookify_slot_is_offered( $service_id, $day, (string) $state['time'] ) ? 'yes' : 'no'
			);
		}

		printf(
			"       VERDICT: %s\n",
			( ! in_array( $state['time'] ?? '', $slots, true ) && $current > $before )
				? 'PASS — the write invalidated the cache'
				: 'FAIL — a booking is invisible to the next availability read'
		);

		if ( $booking ) {
			bookify_booking_unschedule_reminder( $booking );
			wp_delete_post( $booking, true );
		}

		delete_option( 'bookify_probe_state' );
		break;

	default:
		echo "set bookify_probe_phase to prime, book or check\n";
}
