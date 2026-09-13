<?php
/**
 * Proves the Supabase mapping without needing a Supabase project.
 *
 * Three jobs:
 *
 *  1. Check every row the transforms produce against the schema that ships beside them. The column
 *     names and types are parsed out of `includes/supabase/schema.sql` rather than copied here, so
 *     this cannot pass while the two disagree — a renamed column or a dropped type is a failure the
 *     moment it happens, not a 400 from PostgREST six months later.
 *  2. Exercise the half of the mapping the live data does not reach. Every booking on this site is a
 *     session, so the ticket branch — `item_kind = 'ticket'`, an event and a tier instead of a
 *     service — is booked, checked and deleted here: a branch no row ever takes is a branch that is
 *     not tested.
 *  3. Emit the rows as JSON between markers, so the same payload can be handed to a real Postgres
 *     (`json_populate_recordset`, which is the coercion PostgREST itself performs) and inserted. That
 *     is the end-to-end proof: the site's actual data, through the actual transforms, into actual
 *     Postgres tables.
 *
 * Run it with:
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-sync.php
 *
 * The prose goes to stderr and the JSON to stdout, so `2>/dev/null` leaves something pipeable.
 *
 * @package bookify-booking
 */

// Nothing may leave the container (D6). The ticket check below creates a real booking, which composes
// and sends a real confirmation; the other probes suppress it in this same way.
add_filter(
	'pre_wp_mail',
	function () {
		return true;
	}
);

/**
 * The schema, parsed into `table => [ column => type ]`.
 *
 * Deliberately a parser over the shipped file and not a second copy of the truth: a copy is a thing
 * that can be wrong quietly, and this probe's whole value is that it cannot.
 *
 * @return array<string,array<string,string>>
 */
function probe_schema_columns() {
	$sql = (string) file_get_contents( dirname( __DIR__ ) . '/includes/supabase/schema.sql' );

	// `primary` and friends begin a table constraint rather than a column, and the second list is the
	// keywords that end a type: `numeric(10,2)` is a type with a comma in it, and `text not null
	// default ''` is a type followed by three words that are not part of it.
	$keywords = 'not\s+null|references|primary\s+key|default|unique|check|on\s+delete|generated';
	$skips    = array( 'primary', 'unique', 'check', 'constraint', 'foreign' );
	$tables   = array();

	if ( ! preg_match_all( '/create table if not exists\s+(\w+)\s*\((.*?)\n\);/s', $sql, $blocks, PREG_SET_ORDER ) ) {
		return $tables;
	}

	foreach ( $blocks as $block ) {
		$columns = array();

		foreach ( preg_split( '/\n/', $block[2] ) as $line ) {
			if ( ! preg_match( '/^\s*([a-z_][a-z0-9_]*)\s+(\S.*)$/', $line, $parts )
				|| in_array( $parts[1], $skips, true ) ) {
				continue;
			}

			$rest = preg_split( '/\s+(?:' . $keywords . ')\b/i', rtrim( trim( $parts[2] ), ',' ) );

			$columns[ $parts[1] ] = trim( $rest[0] );
		}

		$tables[ $block[1] ] = $columns;
	}

	return $tables;
}

/**
 * Whether a value is acceptable for a column of this type.
 *
 * The interesting cases are the typed columns: `''` is a perfectly good `text` but a hard error for
 * `date`, `time` and `numeric`, so an empty string where one of those is expected is exactly the bug
 * this is looking for. WordPress hands back `''` for an unset meta value, and flattening that into a
 * date column is the single most likely way this mapping breaks in production.
 *
 * @param mixed  $value The value.
 * @param string $type  The column type, as written in the schema.
 * @return string '' when it is fine, otherwise why not.
 */
function probe_value_problem( $value, $type ) {
	if ( null === $value ) {
		return '';
	}

	if ( 'text' === $type ) {
		return is_string( $value ) ? '' : 'not a string';
	}

	if ( '' === $value ) {
		return 'empty string';
	}

	if ( 'date' === $type ) {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? '' : 'not a date';
	}

	if ( 'time' === $type ) {
		return is_string( $value ) && preg_match( '/^\d{2}:\d{2}$/', $value ) ? '' : 'not a time';
	}

	if ( 0 === strpos( $type, 'numeric' ) ) {
		return is_string( $value ) && preg_match( '/^-?\d+\.\d{2}$/', $value ) ? '' : 'not a two-place decimal string';
	}

	if ( in_array( $type, array( 'bigint', 'integer' ), true ) ) {
		return is_int( $value ) ? '' : 'not an integer';
	}

	if ( 'boolean' === $type ) {
		return is_bool( $value ) ? '' : 'not a boolean';
	}

	// jsonb arrives as a real JSON object or array, not a string of one — sending the encoded string
	// would make PostgREST store a JSON *string* rather than an object, which is a different value.
	if ( 'jsonb' === $type ) {
		return is_array( $value ) ? '' : 'not an array';
	}

	if ( 'timestamptz' === $type || 0 === strpos( $type, 'timestamp' ) ) {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value ) ? '' : 'not an ISO 8601 instant';
	}

	return '';
}

/**
 * A value short enough to print.
 *
 * `var_export()` on a `bookify_media` row would put a couple of hundred kilobytes of base64 into a
 * failure message, which is a worse experience than the failure it is describing. Anything long is
 * replaced by its length: enough to recognise the value by, too short to be a disaster.
 *
 * @param mixed $value The value.
 * @return string
 */
function probe_value_brief( $value ) {
	$text = var_export( $value, true );

	return strlen( $text ) > 120 ? sprintf( '<%d characters>', strlen( $text ) ) : $text;
}

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function probe_check( $label, $ok, $detail = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

/**
 * Check every row of one table against the schema, counting what is wrong.
 *
 * @param string                  $table     Table name.
 * @param array<int,array<mixed>> $rows      Rows to check.
 * @param array<string,string>    $expected  Column => type, from the schema.
 * @param string[]                $forbidden Keys that must never appear.
 * @param int                     $failures  Running failure count, by reference.
 * @return void
 */
function probe_validate_rows( $table, array $rows, array $expected, array $forbidden, &$failures ) {
	foreach ( $rows as $row ) {
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

		foreach ( array_keys( $row ) as $key ) {
			if ( in_array( $key, $forbidden, true ) ) {
				probe_check( sprintf( '%s %d: leaks %s', $table, $id, $key ), false );
				++$failures;
			}
		}

		$missing = array_diff( array_keys( $expected ), array_keys( $row ) );
		$extra   = array_diff( array_keys( $row ), array_keys( $expected ) );

		if ( $missing || $extra ) {
			probe_check(
				sprintf( '%s %d: columns match the schema', $table, $id ),
				false,
				sprintf( 'missing=%s extra=%s', implode( ',', $missing ), implode( ',', $extra ) )
			);
			++$failures;
		}

		foreach ( $row as $key => $value ) {
			if ( ! isset( $expected[ $key ] ) ) {
				continue;
			}

			$problem = probe_value_problem( $value, $expected[ $key ] );

			if ( '' !== $problem ) {
				probe_check( sprintf( '%s %d: %s', $table, $id, $key ), false, $problem . ' (' . probe_value_brief( $value ) . ')' );
				++$failures;
			}
		}
	}
}

$schema = probe_schema_columns();
$tables = bookify_booking_supabase_tables();
$payload = array();
$failures = 0;

// The one thing that must never travel. A mirror is a third party holding a copy, and these three
// keys are the only thing between a stranger and somebody else's booking.
$forbidden = array( 'bookify_cancel_token', 'bookify_manage_token', 'bookify_manage_expires', 'cancel_token', 'manage_token' );

$store  = bookify_booking_supabase_content_store_tables();
$mapped = array_merge( array_keys( $tables ), $store );
$named  = array_keys( $schema );

sort( $mapped );
sort( $named );

// Set equality, not a count. A count would pass with one table missing from the schema and a different
// one missing from the map, which is exactly the drift this probe exists to catch.
probe_check(
	'the schema and the map describe the same tables',
	$named === $mapped,
	sprintf(
		'%d in the schema, %d in the entity map, %d in the content store — schema only: [%s]; map only: [%s]',
		count( $named ),
		count( $tables ),
		count( $store ),
		implode( ', ', array_diff( $named, $mapped ) ),
		implode( ', ', array_diff( $mapped, $named ) )
	)
);

foreach ( $tables as $table => $shape ) {
	$posts = bookify_booking_supabase_local_ids( $table );
	$rows  = bookify_booking_supabase_rows( $table );

	// Captured before the ticket check below creates a row, so what comes out on stdout is the site's
	// own data and not the probe's fixture.
	$payload[ $table ] = $rows;

	probe_check( sprintf( '%s: %d source(s) → %d row(s)', $table, count( $posts ), count( $rows ) ), array() !== $rows );

	$expected = isset( $schema[ $table ] ) ? $schema[ $table ] : array();

	if ( ! $expected ) {
		probe_check( $table . ': exists in schema.sql', false );
		++$failures;

		continue;
	}

	probe_validate_rows( $table, $rows, $expected, $forbidden, $failures );
}

// Stage 7 is the reason this check exists: a booking is either a session or a ticket, and the two
// never both appear. If the mirror only followed `bookify_service_id` every ticket would read as a
// session with a null service.
$kinds = array();

foreach ( $payload['bookify_bookings'] as $row ) {
	$kind = (string) $row['item_kind'];

	$kinds[ $kind ] = isset( $kinds[ $kind ] ) ? $kinds[ $kind ] + 1 : 1;

	// Stage 7 is why this is checked: a booking is either a session or a ticket and the two never both
	// appear. If the mirror followed `bookify_service_id` alone, every ticket would read as a session
	// with a null service — a wrong answer, not a missing one.
	if ( ( 'ticket' === $kind ) !== ( null !== $row['event_id'] ) ) {
		probe_check( sprintf( 'booking %d: kind and event agree', $row['id'] ), false );
		++$failures;
	}
}

probe_check( 'every live booking is one product or the other', ! empty( $kinds ), 'kinds: ' . wp_json_encode( $kinds ) );

// ---- 2: the secrets, which must not be anywhere in the payload --------------------------------
//
// The strongest form of this test is the real values, taken from the options this site actually
// holds, searched for in the exact bytes that would be sent. A fixed string like "sk_test_" would
// pass whether or not the code was right; this cannot. Neither the values nor the key lengths are
// printed — only whether each was set and whether it was found.

$secrets = array(
	'stripe secret key'     => (string) bookify_booking_payments()['secret_key'],
	'stripe webhook secret' => (string) bookify_booking_payments()['webhook_secret'],
	'turnstile secret key'  => (string) bookify_bots()['secret_key'],
);

$configured  = 0;
$leaked      = array();
$payload_raw = (string) wp_json_encode( $payload );

foreach ( $secrets as $label => $secret ) {
	if ( '' === $secret ) {
		continue;
	}

	++$configured;

	if ( false !== strpos( $payload_raw, $secret ) ) {
		$leaked[] = $label;
	}
}

probe_check(
	sprintf( 'no stored secret is anywhere in the payload (%d of %d configured)', $configured, count( $secrets ) ),
	array() === $leaked,
	implode( ', ', $leaked )
);

// The substitute that IS published has to agree with them, or the boolean is worse than useless.
$settings = isset( $payload['bookify_settings'][0] ) ? $payload['bookify_settings'][0] : array();

probe_check(
	'and the booleans that replace them tell the truth',
	isset( $settings['stripe_configured'], $settings['turnstile_configured'] )
		&& $settings['stripe_configured'] === ( '' !== $secrets['stripe secret key'] )
		&& $settings['turnstile_configured'] === ( '' !== $secrets['turnstile secret key'] ),
	sprintf(
		'stripe=%s turnstile=%s',
		var_export( isset( $settings['stripe_configured'] ) ? $settings['stripe_configured'] : null, true ),
		var_export( isset( $settings['turnstile_configured'] ) ? $settings['turnstile_configured'] : null, true )
	)
);

// And `user_pass` — a password hash — is never in the customers table, because the row is built
// column by column rather than from the user object.
probe_check(
	'no password hash is in the payload',
	false === strpos( $payload_raw, '$P$' ) && false === strpos( $payload_raw, '$wp$2y$' )
);

// ---- the ticket branch, which no live booking takes -------------------------------------------

$probe_event = 0;
$probe_tier  = 0;

foreach ( get_posts(
	array(
		'post_type'      => 'bookify_event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
) as $candidate ) {
	if ( '' === bookify_event_date( $candidate ) ) {
		continue;
	}

	foreach ( bookify_event_tiers( $candidate ) as $candidate_tier ) {
		if ( bookify_tier_places_left( $candidate_tier->ID ) >= 2 ) {
			$probe_event = (int) $candidate;
			$probe_tier  = (int) $candidate_tier->ID;

			break 2;
		}
	}
}

if ( ! $probe_event ) {
	probe_check( 'a published event with a tier and room exists to test with', false );
	++$failures;
} else {
	$probe_booking = bookify_create_booking(
		array(
			'event_id'   => $probe_event,
			'tier_id'    => $probe_tier,
			'name'       => 'Supabase mirror probe',
			'email'      => 'mirror-probe@example.invalid',
			'party_size' => 2,
		)
	);

	probe_check(
		sprintf( 'a test ticket was booked on event %d / tier %d', $probe_event, $probe_tier ),
		! is_wp_error( $probe_booking ),
		is_wp_error( $probe_booking ) ? $probe_booking->get_error_message() : ''
	);

	if ( ! is_wp_error( $probe_booking ) ) {
		$row = bookify_booking_supabase_booking_row( $probe_booking );

		probe_check( 'the ticket maps to a row', is_array( $row ) );

		if ( is_array( $row ) ) {
			probe_validate_rows( 'bookify_bookings', array( $row ), $schema['bookify_bookings'], $forbidden, $failures );

			probe_check( 'it is a ticket, not a session', 'ticket' === $row['item_kind'], 'item_kind=' . var_export( $row['item_kind'], true ) );
			probe_check( 'it points at the event and the tier, and at no session', $probe_event === $row['event_id'] && $probe_tier === $row['tier_id'] && null === $row['service_id'] );
			probe_check(
				'it is named after the event and its tier',
				false !== strpos( (string) $row['item_label'], (string) get_post_field( 'post_title', $probe_tier ) ),
				'item_label=' . var_export( $row['item_label'], true )
			);
			probe_check( 'its own date is the event date', bookify_event_date( $probe_event ) === $row['booking_date'], 'booking_date=' . var_export( $row['booking_date'], true ) );
			// A ticket is bought rather than held, so it is paid in full (D24) — which is the one place
			// the amount on a booking reads as "the tier price times the party".
			probe_check(
				'two tickets are priced per ticket, not per slot',
				number_format( (float) bookify_tier_price( $probe_tier ) * 2, 2, '.', '' ) === $row['payment_amount'],
				'payment_amount=' . var_export( $row['payment_amount'], true )
			);
		}

		// The mirror learns about a hard delete through `deleted_post`; this is that path, and it is also
		// what keeps the probe from leaving a booking behind.
		wp_delete_post( $probe_booking, true );

		probe_check( 'a deleted booking stops producing a row', null === bookify_booking_supabase_booking_row( $probe_booking ) );
		probe_check( 'and it is gone from the list of bookings', ! in_array( (int) $probe_booking, bookify_booking_supabase_local_ids( 'bookify_bookings' ), true ) );
	}
}

probe_check( 'no leaks, no type errors, no column drift', 0 === $failures, sprintf( '%d failure(s)', $failures ) );

// ---- stdout: the images are summarised, never printed --------------------------------------
//
// A `bookify_media` row is a base64 JPEG, and there are eight of them. Printed in full that is a
// megabyte of binary in a terminal that may become a transcript, and it makes the payload below too
// large to paste anywhere useful — which is a real loss, because the whole point of the payload is to
// be fed to a real Postgres. Each value becomes a marker saying how long it was, so the shape is still
// visible and the row is still insertable. Every check above ran against the real bytes; this is only
// about what gets shown.
$summarised = 0;
$worst      = 0;

foreach ( $payload['bookify_media'] as $index => $media_row ) {
	$media_files = isset( $media_row['files'] ) ? (array) $media_row['files'] : array();

	foreach ( $media_files as $path => $encoded ) {
		$media_files[ $path ] = sprintf( 'base64:%d chars', strlen( (string) $encoded ) );
		++$summarised;
	}

	$payload['bookify_media'][ $index ]['files'] = $media_files;
}

foreach ( $payload['bookify_media'] as $media_row ) {
	foreach ( (array) ( isset( $media_row['files'] ) ? $media_row['files'] : array() ) as $encoded ) {
		$worst = max( $worst, strlen( (string) $encoded ) );
	}
}

probe_check(
	sprintf( 'the printed payload carries no image data (%d value(s) summarised, longest now %d characters)', $summarised, $worst ),
	$summarised > 0 && $worst < 64
);

// stdout stays pipeable: everything above went to stderr.
echo "--- BEGIN SUPABASE ROWS ---\n";
echo wp_json_encode( $payload ) . "\n";
echo "--- END SUPABASE ROWS ---\n";
