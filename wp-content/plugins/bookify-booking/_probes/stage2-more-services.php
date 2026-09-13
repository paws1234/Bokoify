<?php
/**
 * The rest of the studio's catalogue, entered as content.
 *
 * The studio described itself as "a small studio in Brighton for therapists, coaches and trainers"
 * from the start, while the catalogue held three coaching sessions, so this adds the other two
 * halves of that sentence: hair, and two more therapists.
 *
 * Everything here is data, and deliberately nothing else. Two of the requests behind it could have
 * become plugin features — a location choice with an address field for the home visits, and a
 * practitioner a visitor picks — and both were left as content instead, because the diary already
 * answers the questions a feature would be built to answer:
 *
 * - A home visit is a **service of its own** ("Haircut at your home") with its own price, length
 *   and capacity, so it books through the same form, lands in the same admin list and takes the
 *   same confirmation email with no new code. The address is arranged on the phone after booking —
 *   which is how the two haircut services are worded, because the form has no field for it and a
 *   booking that silently collected nothing would be worse than one that says so.
 * - A therapist is a **service of its own** too: choosing the row is choosing the person. Each
 *   one's copy names them, which is what the listing, the service page and every email print.
 *
 * The diary needs no per-service configuration: `bookify_opening_slots()` steps the studio's own
 * opening hours for each service's `bookify_duration` and closes the list before closing time, so
 * a 45-minute cut and a 60-minute massage simply get different last slots on the same day.
 *
 * Re-runnable: a service whose title already exists is left exactly as it is, so this can be run
 * again after a database reset and an owner's later edits are never overwritten.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage2-more-services.php
 */

$services = array(
	array(
		'title'    => 'Counselling session',
		'price'    => 70,
		'duration' => 60,
		'capacity' => 1,
		'excerpt'  => 'With Tomas Reyes. An hour for the thing you have been carrying on your own — no referral, no diagnosis, and nothing you have to have worked out before you arrive.',
		'content'  => "An hour with someone whose whole job is to listen properly. You do not need a referral, a diagnosis, or a reason that sounds serious enough — most people arrive with something that has simply been going on too long.\n\nThe first appointment is mostly talking, and mostly you. Tomas will ask what brought you here and what you would like to be different, and the two of you will agree whether this is the right kind of support and how often to come.\n\nSessions are confidential, and he will explain the few limits to that in your first hour. If you are already being seen by a GP or a psychiatrist, say so — this is easiest to fit around ongoing care rather than to replace it.",
	),
	array(
		'title'    => 'Physical therapy session',
		'price'    => 55,
		'duration' => 45,
		'capacity' => 1,
		'excerpt'  => 'With Nadia Okafor. Assessment and hands-on treatment for a shoulder, a back or a knee that has stopped doing what you need it to — and the exercises that keep it that way.',
		'content'  => "Assessment and treatment in the same appointment, so you are not paying twice to find out what is wrong. Bring the problem, not a diagnosis.\n\nExpect the first appointment to spend more time on questions than the ones that follow. Nadia will ask what you have stopped doing, look at how you move, and treat what she finds — hands-on work, and the exercises that make it hold. You will leave with something specific to do before next time.\n\nWear something you can move in. If you have had a scan or a letter from a consultant, bring it; if you have not, that is fine too.",
	),
	array(
		'title'    => 'Sports massage session',
		'price'    => 60,
		'duration' => 60,
		'capacity' => 1,
		'excerpt'  => 'With Priya Raghavan. Deep, focused work on the muscles doing too much: training weeks, desk weeks, and the weeks where both happen at once.',
		'content'  => "Deep, specific work on the soft tissue that is doing too much — a training block, a long week at a desk, or the fortnight you spent carrying everything yourself.\n\nThe pressure is yours to set and Priya will keep asking. Remedial work is not meant to be endured; if you are holding your breath, it is too much.\n\nDrink water afterwards and leave the hard session for another day. Most people come every three or four weeks, and some come twice before an event and not again for months.",
	),
	array(
		'title'    => 'Haircut at the studio',
		'price'    => 38,
		'duration' => 45,
		'capacity' => 1,
		'excerpt'  => 'With Mira Lawson, upstairs at the studio. A consultation first, then a cut decided with you rather than for you — and the two minutes of advice that keep it right between visits.',
		'content'  => "A consultation first, and a proper one: how the hair actually lies, how much time you have on a weekday morning, and what grows back well. Then the cut, decided with you rather than for you.\n\nWash and finish are included — you leave with it looking the way it will on a good day, rather than the way it looks when it is dry and you are in a hurry.\n\nThe chair is upstairs at the studio. If you are running late, ring us: we would rather move the appointment than cut the consultation short.",
	),
	array(
		'title'    => 'Haircut at your home',
		'price'    => 55,
		'duration' => 60,
		'capacity' => 1,
		'excerpt'  => 'The same consultation and cut at your own address, with travel inside Brighton included — we ring to confirm the address and the parking once you have booked.',
		'content'  => "Everything the studio cut includes, at your own table: the consultation, the cut, and the advice to keep it. The extra time covers travel, and travel inside Brighton is in the price.\n\nYou will need a chair, a socket, and light you can actually see your hair in — most people set up at a dressing table or a kitchen counter with a mirror.\n\nWe ring to confirm the address, how to get in and anywhere to park once your booking is in. Book the time here and we will sort the rest on the phone.",
	),
);

foreach ( $services as $service ) {
	$existing = get_posts(
		array(
			'post_type'      => 'bookify_service',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'title'          => $service['title'],
		)
	);

	if ( $existing ) {
		printf( "already there: service %d %s\n", (int) $existing[0], $service['title'] );
		continue;
	}

	$service_id = wp_insert_post(
		array(
			'post_type'    => 'bookify_service',
			'post_status'  => 'publish',
			'post_title'   => $service['title'],
			'post_excerpt' => $service['excerpt'],
			'post_content' => $service['content'],
		),
		true
	);

	if ( is_wp_error( $service_id ) ) {
		echo 'service failed: ' . $service['title'] . ' — ' . $service_id->get_error_message() . "\n";
		continue;
	}

	update_post_meta( $service_id, 'bookify_price', $service['price'] );
	update_post_meta( $service_id, 'bookify_duration', $service['duration'] );
	update_post_meta( $service_id, 'bookify_capacity', $service['capacity'] );

	$parts = bookify_booking_service_price_and_length( get_post( $service_id ) );

	printf(
		"service %d %s — %s — permalink %s\n",
		$service_id,
		$service['title'],
		bookify_booking_service_meta_label( $parts['price'], $parts['minutes'] ),
		(string) get_permalink( $service_id )
	);
}

printf( "\ncatalogue now holds %d published services\n", count( bookify_booking_published_services() ) );
