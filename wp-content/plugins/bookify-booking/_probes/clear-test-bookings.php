<?php
/**
 * Throwaway: delete every booking the Stage 2 probes created. Deleted after use.
 */

$emails = array(
	'probe@example.com',
	'race@example.com',
	'stage2@example.com',
	'stage2click@example.com',
	'stage2nojs@example.com',
	'enter@example.com',
);

$deleted = array();

foreach ( get_posts(
	array(
		'post_type'      => 'bookify_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
) as $booking_id ) {
	if ( in_array( (string) get_post_meta( $booking_id, 'bookify_customer_email', true ), $emails, true ) ) {
		$deleted[] = $booking_id . ' ' . get_post_meta( $booking_id, 'bookify_reference', true );
		wp_delete_post( $booking_id, true );
	}
}

echo 'deleted: ' . ( $deleted ? implode( ', ', $deleted ) : 'none' ) . "\n";

$left = get_posts(
	array(
		'post_type'      => 'bookify_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

echo 'bookings left: ' . count( $left ) . "\n";

foreach ( $left as $booking_id ) {
	echo sprintf(
		"  %d %s %s %s %s %d guests %s\n",
		$booking_id,
		get_post_meta( $booking_id, 'bookify_reference', true ),
		get_post_meta( $booking_id, 'bookify_status', true ),
		get_post_meta( $booking_id, 'bookify_date', true ),
		get_post_meta( $booking_id, 'bookify_time', true ),
		(int) get_post_meta( $booking_id, 'bookify_party_size', true ),
		get_post_meta( $booking_id, 'bookify_customer_email', true )
	);
}
