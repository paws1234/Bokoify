<?php
/**
 * Apply `includes/supabase/schema.sql` to the configured database.
 *
 * The schema is normally applied once by hand, in the Supabase SQL editor. This exists so that it can
 * be applied from the same place everything else runs, with the same credential — and so that the file
 * in the repository is provably the file that was applied, rather than a copy pasted into a web form.
 *
 * **Idempotent, and it only ever creates.** Every statement in that file is `if not exists`; there are
 * no drops and no data changes, and nothing outside the `bookify_*` tables is touched. Running it
 * against a database that already has them changes nothing at all.
 *
 * Run it in the deployed image, because the dev container has no `pdo_pgsql`:
 *
 *   wp eval-file .../_probes/supabase-schema.php
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
function ss_note( $label, $ok, $note = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $note ? '' : ' — ' . $note ) );
}

/**
 * The bookify tables the database currently holds, with their row counts.
 *
 * @param \PDO $pdo The connection.
 * @return array<string,int>
 */
function ss_tables( $pdo ) {
	$found = array();

	try {
		$statement = $pdo->query(
			"select table_name from information_schema.tables
			 where table_schema = current_schema() and table_name like 'bookify\\_%' order by table_name"
		);

		foreach ( (array) $statement->fetchAll() as $row ) {
			$name = (string) $row['table_name'];

			$count = $pdo->query( 'select count(*) as n from ' . '"' . $name . '"' );
			$found[ $name ] = (int) $count->fetch()['n'];
		}
	} catch ( \Throwable $e ) {
		return $found;
	}

	return $found;
}

$db = bookify_booking_supabase_db();

if ( ! $db['configured'] ) {
	ss_note( 'a database URL is configured', false );

	return;
}

$pdo = bookify_booking_supabase_pg_connect();

if ( is_wp_error( $pdo ) ) {
	ss_note( 'the connection opens', false, $pdo->get_error_message() );

	return;
}

ss_note( 'the connection opens', true, $db['host'] . ' as ' . $db['user'] );

$file = dirname( __DIR__ ) . '/includes/supabase/schema.sql';
$sql  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';

if ( '' === $sql ) {
	ss_note( 'schema.sql is readable', false, $file );

	return;
}

ss_note( 'schema.sql is readable', true, sprintf( '%d bytes', strlen( $sql ) ) );

$before = ss_tables( $pdo );
$first  = array() === $before;

// Both answers are fine and neither is a failure — the file is idempotent, so a first run and a re-run
// amount to the same thing from here. Which one it is, is worth saying, and worth *not* phrasing as a
// failure: a check that goes red on the correct path is a check people learn to ignore.
ss_note(
	'the database state is known, and either one is fine',
	true,
	$first
		? 'empty: this is a first run'
		: sprintf( 'already holds %d table(s): %s', count( $before ), implode( ', ', array_keys( $before ) ) )
);

try {
	// One `exec`, because libpq's PQexec takes multiple statements — which is what lets the file be
	// applied exactly as written, `begin` … `commit` and the `do $$ … $$` block included.
	$pdo->exec( $sql );
} catch ( \Throwable $e ) {
	ss_note( 'the schema applied', false, $e->getMessage() );

	return;
}

$expected = array_merge( array_keys( bookify_booking_supabase_tables() ), bookify_booking_supabase_content_store_tables() );

ss_note(
	'the schema applied',
	true,
	sprintf( '%s; %d table(s) expected', $before ? 'it was already there, so nothing changed' : 'created', count( $expected ) )
);

$after = ss_tables( $pdo );

foreach ( $expected as $table ) {
	ss_note(
		$table,
		isset( $after[ $table ] ),
		isset( $after[ $table ] ) ? sprintf( '%d row(s) in the database', $after[ $table ] ) : 'MISSING'
	);
}

$missing = array_diff( $expected, array_keys( $after ) );

ss_note( 'every expected table exists', array() === $missing, implode( ', ', $missing ) );

if ( array() === $missing ) {
	fwrite( STDERR, "\nthe database is ready. Next: wp bookify-supabase sync\n" );
}
