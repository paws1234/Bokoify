<?php
/**
 * The long description for each service — the copy a session's own page is made of.
 *
 * The listing card still carries the short excerpt, because a card is a glance. This is the page
 * at the end of "Read more", where the only two things are the description and a way to book, so
 * the description has to do the whole job: what the session is, what actually happens in it, and
 * the practical bits somebody wants answered before they will pay.
 *
 * Every service is written to the same shape but not the same words — a paragraph of what it is,
 * then two questions of its own with answers under them. The headings differ on purpose: a
 * counselling hour and a haircut are not explained by the same four words, and a page that repeats
 * one template eight times reads like one.
 *
 * **This replaces copy.** It is written for a catalogue that holds placeholder content, and it
 * overwrites whatever is stored — so run it once. If the owner has already rewritten a description
 * by hand, running this again would take it back; the run prints which services it changed so that
 * is visible rather than silent. A service whose stored copy already matches is skipped.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage2-service-copy.php
 */

$copy = array(
	'counselling-session'      => <<<'TXT'
An hour with someone whose whole job is to listen properly. You do not need a referral, a diagnosis, or a reason that sounds serious enough — most people arrive with something that has simply been going on too long.

<h2>In the first hour</h2>

The first appointment is mostly talking, and mostly you. Tomas will ask what brought you here and what you would like to be different, and the two of you will agree whether this is the right kind of support and how often to come.

Nothing is expected of you beforehand. You do not have to explain your history in order, in full, or in a way that makes sense to anybody but you. Most people find the first few minutes harder than the rest of the hour.

Sessions are confidential, and he will explain the few limits to that in your first hour. If you are already being seen by a GP or a psychiatrist, say so — this is easier to fit around ongoing care than to replace it.

<h2>Coming back</h2>

Most people come weekly to begin with and then less often as things settle, but there is no package and no minimum. Book one hour and decide afterwards; you can stop whenever you like, and nobody will ring to ask why.
TXT,
	'physical-therapy-session' => <<<'TXT'
Assessment and treatment in the same appointment, so you are not paying twice to find out what is wrong. Bring the problem, not a diagnosis.

<h2>What happens in an appointment</h2>

Expect the first appointment to spend more time on questions than the ones that follow. Nadia will ask what you have stopped doing, look at how you move, and treat what she finds — hands-on work on the joint or the muscle causing it, and the exercises that make the change hold.

You will leave with something specific to do before next time. If the honest answer is "rest it for a fortnight", that is what you will be told. Usually it is two or three things you can do in ten minutes at home, and they matter more than the twenty minutes on the couch.

<h2>Before your first visit</h2>

Wear something you can move in. If you have had a scan, a letter from a consultant or a diagnosis from somebody else, bring them — and if you have none of those, that is fine, because most people arrive without.

Nobody needs a GP referral to book here. If you are not sure whether this is the right thing to do about your shoulder, ring us and we will tell you honestly, including when the answer is no.
TXT,
	'sports-massage-session'   => <<<'TXT'
Deep, specific work on the tissue that is doing too much — a training block, a long week at a desk, or the fortnight you spent carrying everything yourself.

<h2>What the work is like</h2>

The pressure is yours to set and Priya will keep asking. Remedial work is not meant to be endured: if you are holding your breath, or gripping the table, it is too much, and saying so changes nothing except the next few minutes.

She works with what she finds rather than following a routine. Most sessions start broad and finish on the two or three places actually causing the trouble, which are often not where it hurts.

<h2>Afterwards</h2>

Drink water and leave the hard session for another day — the work carries on for a while after you leave the room. Most people come every three or four weeks; some come twice before an event and not again for months.

If you are training for something specific, say so when you book. It changes what the hour is spent on, and there is no point spending it on next month's race if this month's is the problem.
TXT,
	'haircut-at-the-studio'    => <<<'TXT'
A consultation first, and a proper one: how the hair actually lies, how much time you have on a weekday morning, and what grows back well. Then the cut, decided with you rather than for you.

<h2>In the chair</h2>

Wash and finish are included, so you leave with it looking the way it will on a good day rather than the way it looks when it is dry and you are in a hurry. Mira shows you what she is doing as she goes, instead of turning the chair round at the end and asking what you think.

You will get the two minutes of advice that keep it right between visits — what to use, how much of it, and which bits to leave alone. If you want to change the shape rather than just take the ends off, say so at the start: that is a longer conversation than a trim, and the appointment has room for it.

<h2>Practical bits</h2>

The chair is upstairs at the studio and there is parking on the street outside. If you are running late, ring us — we would rather move the appointment than cut the consultation short.
TXT,
	'haircut-at-your-home'     => <<<'TXT'
Everything the studio cut includes, at your own table: the consultation, the cut, and the advice to keep it. The extra time covers travel, and travel inside Brighton is in the price.

<h2>At your place</h2>

You will need a chair, a socket, and light you can actually see your hair in. Most people set up at a dressing table, or at a kitchen counter with a mirror in front of them — neither is any worse than a salon chair, and it is the same cut either way.

Mira brings everything else: cape, scissors, clippers, and something to sweep up with afterwards. Children, two of you in one visit, or somebody who finds getting to a salon difficult are all ordinary bookings here.

<h2>Booking and travel</h2>

We ring to confirm the address, how to get in and anywhere to park once your booking is in. Book the time here and we will sort the rest on the phone.

If you are outside Brighton, ring us before booking. Travel is charged by distance, and we will tell you what it comes to before you commit to anything.
TXT,
	'one-to-one-session'       => <<<'TXT'
A full hour of work on your own goals — treatment, coaching or training. We start from where you are and finish with something concrete to take away.

<h2>Working on your own goals</h2>

The hour is spent on one thing, chosen by you. If you are training for something, that is the thing: we look at what you are doing, change what is holding it back, and set the work for the weeks between.

It works the same way when it is not a training goal. Some people arrive with a shoulder that has been grumbling for a year, some with a habit they want gone, and some because standing up straight stopped being automatic about a decade ago. All of those are an hour of work rather than a chat about one.

<h2>What to bring</h2>

Wear something you can move in, and bring anything somebody else has told you — a scan, a letter, a training plan. None of it is required and none of it decides what happens in the hour.

Come early if you can. The first few minutes settle what the rest of the hour is spent on, and they are better spent with you than with a form.
TXT,
	'partner-session'          => <<<'TXT'
Bring someone with you: a partner, a training partner or a colleague. Seventy-five minutes of work for two, booked as a single slot so neither of you has to explain it twice.

<h2>Working as two</h2>

The extra fifteen minutes are there because there are two of you. You will be looked at separately as well as together — two people rarely have the same thing going on, and an hour works better when it is not treated as one problem with two bodies attached to it.

People book this for all sorts of reasons. Training for the same event, a shoulder each that has stopped cooperating, or simply because an hour of this is easier to keep to when somebody else is expecting you there.

<h2>Booking it</h2>

One booking, one payment, two people — which is why the form asks how many of you are coming. Bring what you would wear to move in, and bring whatever you already know about what hurts.
TXT,
	'small-group-class'        => <<<'TXT'
A class of up to eight, so there is room to correct what you are doing rather than just following along. One place per person — book two places on one booking if you are bringing someone.

<h2>In a class</h2>

The size is the point. With eight people in the room everybody gets looked at properly, twice, and the two or three things you are doing in a way that will cause trouble later get picked up before they do.

Classes run at a steady pace with room to work harder or easier than the person next to you. Nobody is asked to perform and nobody is left behind. If you have never done this before, say so when you arrive and the hour is adjusted around you rather than the other way round.

<h2>What to bring</h2>

Comfortable clothes and water. Mats and props are here, so bring nothing but yourself the first time — and if you would rather bring your own mat, that is fine too.
TXT,
);

$changed = 0;
$kept    = 0;

foreach ( $copy as $slug => $content ) {
	$service = get_page_by_path( $slug, OBJECT, 'bookify_service' );

	if ( ! $service instanceof WP_Post ) {
		printf( "MISSING  %s\n", $slug );
		continue;
	}

	$stored = (string) $service->post_content;

	if ( trim( $stored ) === trim( $content ) ) {
		printf( "unchanged %-26s %d words\n", $slug, str_word_count( wp_strip_all_tags( $content ) ) );
		$kept++;
		continue;
	}

	$result = wp_update_post(
		array(
			'ID'           => $service->ID,
			'post_content' => $content,
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		printf( "FAILED   %-26s %s\n", $slug, $result->get_error_message() );
		continue;
	}

	printf(
		"updated  %-26s %d -> %d words\n",
		$slug,
		str_word_count( wp_strip_all_tags( $stored ) ),
		str_word_count( wp_strip_all_tags( $content ) )
	);

	$changed++;
}

printf( "\n%d description(s) replaced, %d already current\n", $changed, $kept );
