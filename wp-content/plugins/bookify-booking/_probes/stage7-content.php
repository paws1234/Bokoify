<?php
/**
 * The site's first event, entered as content (T32 and T33).
 *
 * An event is content, like the sessions are: created here rather than by a theme file or an
 * Elementor layout, so it can be edited in wp-admin afterwards. This is the event T32's page, T33's
 * tiers and T35's form are looked at on.
 *
 * Re-runnable: it does nothing when an event with this title already exists.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage7-content.php
 */

$existing = get_posts(
	array(
		'post_type'      => 'bookify_event',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'title'          => 'Autumn reset workshop',
	)
);

if ( $existing ) {
	printf( "already there: event %d\n", (int) $existing[0] );
	return;
}

$date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +27 days' ) );

$event_id = wp_insert_post(
	array(
		'post_type'    => 'bookify_event',
		'post_status'  => 'publish',
		'post_title'   => 'Autumn reset workshop',
		'post_excerpt' => 'A day of movement, rest and planning for the season ahead.',
		'post_content' => 'A full day in the studio: a long practice in the morning, a shared lunch, and an afternoon of rest and planning. Mats, props and lunch are included — bring layers and a notebook.',
	),
	true
);

if ( is_wp_error( $event_id ) ) {
	echo 'event failed: ' . $event_id->get_error_message() . "\n";
	return;
}

update_post_meta( $event_id, 'bookify_event_date', $date );
update_post_meta( $event_id, 'bookify_event_start', '10:00' );
update_post_meta( $event_id, 'bookify_event_end', '16:00' );
// Twenty places for the day, shared between the tiers below: the event's own ceiling is what a
// studio sells, and the tiers are how it is priced.
update_post_meta( $event_id, 'bookify_event_capacity', 20 );

$tiers = array(
	'General admission' => array( 45, 18 ),
	'VIP'               => array( 75, 6 ),
);

foreach ( $tiers as $title => $values ) {
	$tier_id = wp_insert_post(
		array(
			'post_type'   => 'bookify_tier',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $tier_id ) ) {
		echo 'tier failed: ' . $tier_id->get_error_message() . "\n";
		continue;
	}

	update_post_meta( $tier_id, 'bookify_event_id', $event_id );
	update_post_meta( $tier_id, 'bookify_tier_price', $values[0] );
	update_post_meta( $tier_id, 'bookify_tier_capacity', $values[1] );

	printf( "tier %d %s — %s\n", $tier_id, $title, bookify_tier_price_label( $tier_id ) );
}

printf(
	"event %d on %s, %s, %d places left, permalink %s\n",
	$event_id,
	bookify_event_date( $event_id ),
	bookify_event_date_label( $event_id ),
	bookify_event_places_left( $event_id ),
	(string) get_permalink( $event_id )
);
