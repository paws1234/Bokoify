<?php
/**
 * One booking, shown as it is stored on each side.
 *
 * This is the wrapper doing its job, in one screen: on the left, the booking as WordPress keeps it —
 * twenty-odd rows of `wp_postmeta`, every value a string of text, no types and nothing joining them to
 * anything. On the right, the same booking after the translation — one row, a real `date`, a real
 * `time`, a `numeric(10,2)` amount, and ids that are foreign keys to the session it was booked against.
 *
 * Read-only. It writes nothing to either side.
 *
 *   wp eval-file .../_probes/supabase-compare.php
 *
 * Run it in the deployed image, with the database URL configured.
 *
 * @package bookify-booking
 */

$db = bookify_booking_supabase_db();

if ( ! $db['configured'] ) {
	fwrite( STDERR, "no database URL configured\n" );

	return;
}

$pdo = bookify_booking_supabase_pg_connect();

if ( is_wp_error( $pdo ) ) {
	fwrite( STDERR, 'connection failed: ' . $pdo->get_error_message() . "\n" );

	return;
}

global $wpdb;

// The most recent booking WordPress holds.
$ids = bookify_booking_supabase_local_ids( 'bookify_bookings' );

if ( ! $ids ) {
	fwrite( STDERR, "no bookings in WordPress\n" );

	return;
}

$booking_id = (int) end( $ids );

// ------------------------------------------------------------------ the WordPress side: wp_postmeta

$meta = $wpdb->get_results(
	$wpdb->prepare(
		"select meta_key, meta_value from {$wpdb->postmeta} where post_id = %d order by meta_key",
		$booking_id
	)
);

// Two of these are personal data and two are bearer credentials. The values are not printed; the point
// is the *shape* on each side, and the shape is visible without them.
$hidden = array( 'bookify_customer_email', 'bookify_customer_phone', 'bookify_cancel_token', 'bookify_manage_token' );

echo "WordPress: one booking is " . count( $meta ) . " rows of wp_postmeta\n";
echo str_repeat( '-', 78 ) . "\n";

foreach ( $meta as $row ) {
	$key = (string) $row->meta_key;

	if ( in_array( $key, $hidden, true ) ) {
		printf( "  %-30s %s\n", $key, sprintf( '(%d characters, not shown)', strlen( (string) $row->meta_value ) ) );

		continue;
	}

	printf( "  %-30s %s\n", $key, mb_strimwidth( (string) $row->meta_value, 0, 40, '…' ) );
}

echo "\nEvery value above is a string. Nothing is typed, nothing is indexed by what it means, and there\n";
echo "is no row anywhere that says which session this booking is for.\n";

// ------------------------------------------------------------- the Supabase side: one typed row

$statement = $pdo->prepare( 'select * from bookify_bookings where id = ?' );
$statement->execute( array( $booking_id ) );
$row = $statement->fetch();

if ( ! $row ) {
	echo "\nnot in Supabase yet — run: wp bookify-supabase sync\n";

	return;
}

// The **column** names, which are not the meta keys above — `customer_email`, not
// `bookify_customer_email`. Reusing the list above hid nothing here, which is how a customer's address
// and number came to be printed. The two tokens need no entry: they are absent from this table on
// purpose.
$hidden_columns = array( 'customer_email', 'customer_phone' );

echo "\nSupabase: the same booking is one row of bookify_bookings\n";
echo str_repeat( '-', 78 ) . "\n";

$typed = 0;

foreach ( $row as $column => $value ) {
	if ( in_array( $column, $hidden_columns, true ) ) {
		printf(
			"  %-18s %-26s %s\n",
			$column,
			'text',
			sprintf( '(%d characters, not shown)', strlen( (string) $value ) )
		);

		continue;
	}

	// The type Postgres reports for the column, so the difference is visible rather than claimed.
	$type = $pdo->query(
		"select data_type from information_schema.columns
		 where table_name = 'bookify_bookings' and column_name = " . $pdo->quote( (string) $column )
	)->fetch();

	$kind = $type ? (string) $type['data_type'] : '?';

	if ( null !== $value && '' !== $value ) {
		++$typed;
	}

	printf(
		"  %-18s %-26s %s\n",
		$column,
		$kind,
		null === $value ? 'null' : mb_strimwidth( (string) $value, 0, 24, '…' )
	);
}

printf( "\n%d rows of wp_postmeta became 1 row with %d non-empty columns, each with a declared type.\n", count( $meta ), $typed );

// The join that is impossible on the left and trivial here.
$service = $pdo->prepare(
	'select s.title, s.price, s.duration_minutes
	 from bookify_bookings b join bookify_services s on s.id = b.service_id
	 where b.id = ?'
);
$service->execute( array( $booking_id ) );
$joined = $service->fetch();

if ( $joined ) {
	printf(
		"and the session it is for is one join away: \"%s\", %s for %s minutes.\n",
		$joined['title'],
		$joined['price'],
		$joined['duration_minutes']
	);
}
