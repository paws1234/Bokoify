<?php
/**
 * Does the configured database URL actually work?
 *
 * Connects, says what it connected as, and reads one table. **It writes nothing at all** — the writes
 * are `wp bookify-supabase sync`. This exists to answer the question before that one, because a
 * connection failure, a rejected password, a host with no route and a database without the schema all
 * look the same from a sync that prints "NO" down a column.
 *
 * It never prints the password, and it never prints the URL's password either — only the host, the user,
 * the database, the sslmode, and how long the password is.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-connect.php
 *
 * Run it in the deployed image rather than the dev container: the dev container has no `pdo_pgsql`, and
 * the whole point is to test the connection rather than the extension's absence.
 *
 * @package bookify-booking
 */

/**
 * Report one line.
 *
 * @param string $label What was checked.
 * @param bool   $ok    Whether it passed.
 * @param string $note  Extra detail.
 * @return void
 */
function sc_note( $label, $ok, $note = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $note ? '' : ' — ' . $note ) );
}

$db = bookify_booking_supabase_db();

if ( ! $db['configured'] ) {
	sc_note( 'a database URL is configured', false, 'BOOKIFY_SUPABASE_DB_URL is unset, or still holds the [YOUR-PASSWORD] placeholder' );

	return;
}

sc_note( 'a database URL is configured', true );
fwrite( STDERR, '     host     : ' . $db['host'] . "\n" );
fwrite( STDERR, '     user     : ' . $db['user'] . "\n" );
fwrite( STDERR, '     database : ' . $db['database'] . "\n" );
fwrite( STDERR, '     sslmode  : ' . $db['sslmode'] . "\n" );
fwrite( STDERR, '     password : ' . strlen( $db['password'] ) . " characters\n" );

// The single most likely reason this is about to fail, and the one thing about the URL that cannot be
// seen from the outside: that hostname is IPv6-only, so a host without an IPv6 route cannot reach it,
// and the error mentions the host rather than the missing route.
if ( false !== strpos( $db['host'], '.supabase.co' ) && 0 === strpos( $db['host'], 'db.' ) ) {
	fwrite( STDERR, "\n     NOTE: that is the \"Direct connection\" host. It is IPv6-only on current\n" );
	fwrite( STDERR, "     projects — an AAAA record and no A record. Use the Session pooler host\n" );
	fwrite( STDERR, "     from the dashboard's Connect panel instead: aws-0-<region>.pooler.supabase.com\n\n" );
}

if ( ! extension_loaded( 'pdo_pgsql' ) ) {
	sc_note( 'pdo_pgsql is available', false, 'this PHP has no Postgres driver — the dev container does not have one; the deployed image does' );

	return;
}

sc_note( 'pdo_pgsql is available', true );

$pdo = bookify_booking_supabase_pg_connect();

if ( is_wp_error( $pdo ) ) {
	sc_note( 'the connection opens', false, $pdo->get_error_message() );

	return;
}

sc_note( 'the connection opens', true, 'host, user, password and TLS all accepted' );

/*
 * How far away the database is.
 *
 * This is the number that decides whether the database can be where the site *reads* from. The mirror
 * pays it once per request, on `shutdown`, after the page and the booking are done. A design that stored
 * the data there and read it back instead of WordPress's own storage would pay it on every query — and a
 * page view runs several.
 */
$trips   = 10;
$started = microtime( true );

for ( $i = 0; $i < $trips; $i++ ) {
	$pdo->query( 'select 1' )->fetch();
}

sc_note(
	'a round trip costs',
	true,
	sprintf( '%.0f ms per query, averaged over %d (from here, not from your host)', ( microtime( true ) - $started ) / $trips * 1000, $trips )
);

// A read, so that a missing schema is reported as a missing schema rather than discovered later as a
// write that mysteriously failed.
$ids = bookify_booking_supabase_pg_ids( 'bookify_bookings' );

if ( is_wp_error( $ids ) ) {
	sc_note( 'the schema is applied', false, $ids->get_error_message() );
	sc_note( 'hint', true, 'run includes/supabase/schema.sql in the Supabase SQL editor' );

	return;
}

sc_note( 'the schema is applied', true, sprintf( 'bookify_bookings holds %d row(s)', count( $ids ) ) );

$empty = array();

foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
	$table_ids = bookify_booking_supabase_pg_ids( $table );

	if ( is_wp_error( $table_ids ) ) {
		sc_note( $table, false, $table_ids->get_error_message() );

		continue;
	}

	if ( ! $table_ids ) {
		$empty[] = $table;
	}

	sc_note( $table, true, sprintf( '%d row(s) in the database, %d to send', count( $table_ids ), count( bookify_booking_supabase_rows( $table ) ) ) );
}

sc_note( 'everything reads', true, $empty ? 'empty, which is expected before the first sync: ' . implode( ', ', $empty ) : 'all six tables already hold rows' );
