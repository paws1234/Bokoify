<?php
/**
 * Throwaway script for T29/T31: re-theme the content for therapists, coaches and studios.
 *
 * Every Elementor write goes through the bridge's Elementor_Document, so Elementor's own Document
 * API owns serialisation and CSS regeneration (CLAUDE.md rule 2). Nothing here touches
 * `_elementor_data` directly. Idempotent — it sets the same values every time — and kept as T31's
 * record of the content change.
 */

wp_set_current_user( 1 );

function t8_report( $label, $ok, $detail = '' ) {
	printf( "%-44s %s%s\n", $label, $ok ? 'ok  ' : 'FAIL', '' === $detail ? '' : "  {$detail}" );
}

// ---- the sessions -------------------------------------------------------------------------

$sessions = array(
	19 => array(
		'title'    => 'One-to-one session',
		'price'    => '85',
		'duration' => '60',
		'capacity' => '1',
		'body'     => '<p>A full hour of work on your own goals — treatment, coaching or training. We start from where you are and finish with something concrete to take away.</p>',
	),
	86 => array(
		'title'    => 'Partner session',
		'price'    => '150',
		'duration' => '75',
		'capacity' => '2',
		'body'     => '<p>Bring someone with you: a partner, a training partner or a colleague. Seventy-five minutes of work for two, booked as a single slot so neither of you has to explain it twice.</p>',
	),
	85 => array(
		'title'    => 'Small group class',
		'price'    => '15',
		'duration' => '45',
		'capacity' => '8',
		'body'     => '<p>A class of up to eight, so there is room to correct what you are doing rather than just following along. One place per person — book two places on one booking if you are bringing someone.</p>',
	),
);

foreach ( $sessions as $session_id => $session ) {
	$result = wp_update_post(
		array(
			'ID'           => $session_id,
			'post_title'   => $session['title'],
			'post_content' => $session['body'],
			'post_excerpt' => wp_strip_all_tags( $session['body'] ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		t8_report( "session {$session_id}", false, $result->get_error_message() );
		continue;
	}

	update_post_meta( $session_id, 'bookify_price', $session['price'] );
	update_post_meta( $session_id, 'bookify_duration', $session['duration'] );
	update_post_meta( $session_id, 'bookify_capacity', $session['capacity'] );

	t8_report(
		"session {$session_id} {$session['title']}",
		$session['title'] === get_the_title( $session_id ) && $session['capacity'] === (string) get_post_meta( $session_id, 'bookify_capacity', true ),
		"capacity={$session['capacity']} price={$session['price']} duration={$session['duration']}"
	);
}

// ---- the page the sessions live on ---------------------------------------------------------

wp_update_post(
	array(
		'ID'         => 95,
		'post_title' => 'Sessions',
		'post_name'  => 'sessions',
	)
);

t8_report( 'page 95 is now /sessions/', 'sessions' === get_post_field( 'post_name', 95 ), get_permalink( 95 ) );

// ---- Elementor copy, through the Document API ---------------------------------------------

$edits = array(
	111 => array(
		'c1cb00e' => array( 'title' => 'Book a session in a minute' ),
		'24dfc51' => array( 'editor' => '<p>Choose a session, pick the hour that suits you, and we confirm by email — no phone call, no waiting.</p>' ),
		'cd9e1d7' => array( 'title' => 'Sessions, without the back-and-forth' ),
		'99966d3' => array( 'heading' => 'Sessions' ),
	),
	95  => array(
		'b630b6a' => array(
			'heading' => 'Sessions',
			'empty'   => 'No sessions are on offer yet.',
		),
	),
	116 => array(
		'8c6d0fb' => array( 'editor' => '<p>Questions about a session, a gift voucher or a group booking? Call or email us, or book the time you want online — whichever suits you.</p>' ),
	),
);

foreach ( $edits as $page_id => $elements ) {
	$tree = \WP_Agent_Bridge\Elementor_Document::get_elements( $page_id );

	foreach ( $elements as $element_id => $settings ) {
		$updated = \WP_Agent_Bridge\Elementor_Document::update_settings( $tree, $element_id, $settings );

		t8_report( "page {$page_id} element {$element_id}", $updated, implode( ',', array_keys( $settings ) ) );
	}

	$saved = \WP_Agent_Bridge\Elementor_Document::save( $page_id, $tree );

	if ( is_wp_error( $saved ) ) {
		t8_report( "page {$page_id} save", false, $saved->get_error_message() );
	} else {
		t8_report( "page {$page_id} saved", true, "elements={$saved['element_count']} built=" . ( $saved['built_with_elementor'] ? 'yes' : 'no' ) );
	}
}

// ---- the menus ------------------------------------------------------------------------------

foreach ( array( 3, 4 ) as $menu_id ) {
	$items = wp_get_nav_menu_items( $menu_id );

	foreach ( $items as $item ) {
		if ( 'Services' !== $item->title ) {
			continue;
		}

		wp_update_post(
			array(
				'ID'         => $item->ID,
				'post_title' => 'Sessions',
			)
		);

		t8_report( "menu {$menu_id} item {$item->ID}", 'Sessions' === get_the_title( $item->ID ) );
	}
}

// ---- the business's own details, which were empty --------------------------------------------

update_option(
	'bookify_business',
	array(
		'name'     => 'Bookify Studio',
		'street'   => '14 Harbour Lane',
		'locality' => 'Brighton',
		'postcode' => 'BN1 2AB',
		'country'  => 'United Kingdom',
		'phone'    => '+44 1273 555 099',
		'email'    => 'hello@bookify.example',
	)
);

t8_report( 'business details restored', 'Bookify Studio' === bookify_booking_business()['name'] );

echo "\ndone\n";
