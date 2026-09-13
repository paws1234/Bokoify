<?php
/**
 * Verifies what the mirror actually sends, with no network and no Supabase project.
 *
 * `supabase-sync.php` proves the rows and `schema.sql` proves the tables, but neither says anything
 * about what leaves the server: the URL, the method, or the header that turns a POST into an upsert
 * rather than an insert that fails on every row that already exists. This closes that gap by
 * intercepting at `pre_http_request` — the last point before WordPress hands the request to the
 * transport — and answering with a canned response. So the assertions are about the real client, not a
 * description of it.
 *
 * It covers the four paths that matter:
 *
 *   1. a successful push: 4 requests, one per table, in dependency order, upserting on `id`
 *   2. the reconciliation the cron runs: the same 4 requests, and the outcome recorded
 *   3. a refused push: 401, recorded as a failure with Supabase's own explanation, nothing thrown
 *   4. the schedule itself: present while configured, cleared when not
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-request.php
 *
 * @package bookify-booking
 */

/**
 * The requests the client tried to make.
 *
 * @var array<int,array{url:string,args:array}> $probe_sent
 */
$probe_sent = array();

/**
 * What the stub answers with, so the failure path can be provoked on demand.
 *
 * @var int $probe_status
 */
$probe_status = 201;

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( &$probe_sent, &$probe_status ) {
		$probe_sent[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);

		return array(
			'headers'  => array(),
			'body'     => $probe_status >= 300 ? '{"message":"JWT expired","hint":"Refresh the service_role key"}' : '',
			'response' => array(
				'code'    => $probe_status,
				'message' => $probe_status >= 300 ? 'Unauthorized' : 'Created',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function sr_note( $label, $ok, $detail = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

/**
 * The table name a request was aimed at, from its URL.
 *
 * @param string $url The request URL.
 * @return string
 */
function sr_table_of( $url ) {
	if ( preg_match( '#/rest/v1/([a-z_]+)#', $url, $parts ) ) {
		return $parts[1];
	}

	return '';
}

// A project that exists only for the length of this probe. Set through the filters rather than the
// environment, so nothing leaks into the shutdown flush at the end of the request. Held in variables
// rather than registered inline, so the off path below can take them away again.
$probe_url = static function () {
	return 'https://probe-probe-probe.supabase.co';
};

$probe_key = static function () {
	return 'probe-service-role-key';
};

add_filter( 'bookify_booking_supabase_url', $probe_url );
add_filter( 'bookify_booking_supabase_key', $probe_key );

sr_note( 'the mirror can be switched on by a filter', bookify_booking_supabase_is_configured() );

// ------------------------------------------------------- 1: a successful push, request by request

$probe_sent = array();
$report     = bookify_booking_supabase_backfill();

$expected_order = array_keys( bookify_booking_supabase_tables() );

/*
 * And the half the reconciliation also publishes, which is not the same list.
 *
 * The entity tables go one request each, in dependency order. The content tables are grouped by
 * WordPress table and only the ones that actually hold rows are sent at all, so a site with no comments
 * sends nothing for `wp_comments` and a table added by a plugin is included without this probe knowing
 * its name. Computed here from the plugin's own functions rather than written down, so this cannot go
 * stale the next time a table is added — which is how it went stale this time.
 */
$content_tables = array();
$content_rows   = 0;

foreach ( bookify_booking_supabase_content_tables() as $table ) {
	$built = bookify_booking_supabase_content_rows( $table );

	if ( ! $built['rows'] ) {
		continue;
	}

	$content_tables[] = $table;
	$content_rows    += count( $built['rows'] );
}

sr_note( 'one request per table', count( $expected_order ) === count( $probe_sent ), sprintf( '%d request(s) for %d table(s)', count( $probe_sent ), count( $expected_order ) ) );

$order = array_map( 'sr_table_of', array_column( $probe_sent, 'url' ) );

sr_note(
	'and in dependency order, so a foreign key always has its row first',
	$expected_order === $order,
	implode( ' → ', $order )
);

$problems = 0;

foreach ( $probe_sent as $sent ) {
	$table    = sr_table_of( $sent['url'] );
	$args     = $sent['args'];
	$headers  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$body     = isset( $args['body'] ) ? (string) $args['body'] : '';
	$decoded  = json_decode( $body, true );
	$expected = count( bookify_booking_supabase_rows( $table ) );

	$checks = array(
		'POST'                                       => 'POST' === $args['method'],
		'on_conflict=id, or the upsert is an insert' => false !== strpos( $sent['url'], 'on_conflict=id' ),
		'the key is sent in apikey'                  => 'probe-service-role-key' === ( $headers['apikey'] ?? '' ),
		'and in Authorization, as the gateway wants' => 'Bearer probe-service-role-key' === ( $headers['Authorization'] ?? '' ),
		'merge-duplicates is what makes it upsert'   => false !== strpos( (string) ( $headers['Prefer'] ?? '' ), 'resolution=merge-duplicates' ),
		'and it returns nothing, because nothing reads it' => false !== strpos( (string) ( $headers['Prefer'] ?? '' ), 'return=minimal' ),
		'the body is JSON'                           => is_array( $decoded ),
		'with one object per row'                    => is_array( $decoded ) && count( $decoded ) === $expected,
		'and no cancel token in it'                  => false === strpos( $body, 'cancel_token' ),
		'and no manage token in it'                  => false === strpos( $body, 'manage_token' ),
		'and a timeout, so a booking is never held for long' => isset( $args['timeout'] ) && $args['timeout'] <= 30,
	);

	foreach ( $checks as $label => $ok ) {
		if ( ! $ok ) {
			sr_note( sprintf( '%s: %s', $table, $label ), false );
			++$problems;
		}
	}
}

sr_note( 'every request is well formed', 0 === $problems, sprintf( '%d problem(s)', $problems ) );

$pushed = 0;

foreach ( $report as $table => $row ) {
	$pushed += (int) $row['rows'];

	if ( '' !== $row['error'] ) {
		sr_note( sprintf( '%s: pushed', $table ), false, $row['error'] );
	}
}

$expected_total = 0;

foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
	$expected_total += count( bookify_booking_supabase_rows( $table ) );
}

sr_note( 'the report claims every row was written', $pushed === $expected_total, sprintf( '%d row(s) written, %d expected', $pushed, $expected_total ) );

// ---------------------------------------------------------- 2: the reconciliation the cron runs

delete_option( BOOKIFY_SUPABASE_LAST_RESULT_OPTION );

$probe_sent = array();
bookify_booking_supabase_reconcile();

$result = bookify_booking_supabase_last_result();

$reconcile_order = array_merge(
	$expected_order,
	// Every content table's rows go to the *same* Supabase table, so a request's URL cannot say which
	// WordPress table it carried: eight of the fifteen requests below are all to `bookify_content`. That
	// is the correct expectation, and saying it this way makes the comparison mean something rather than
	// be a number that happens to match.
	array_fill( 0, count( $content_tables ), 'bookify_content' )
);

$sent_tables = array_map( 'sr_table_of', array_column( $probe_sent, 'url' ) );

sr_note(
	sprintf( 'the reconciliation re-sends all %d table(s), without anything having queued it', count( $reconcile_order ) ),
	$reconcile_order === $sent_tables,
	sprintf( '%d request(s) — %d entity, then %d WordPress table(s) into bookify_content', count( $probe_sent ), count( $expected_order ), count( $content_tables ) )
);

sr_note( 'and records a clean outcome', $result['ok'] && 0 === $result['deleted'], $result['message'] );

// The content table is keyed on `( table_name, row_id )` and not on `id`. With the wrong conflict target
// the first push would succeed and the second would be a plain insert that fails on a duplicate key —
// which is exactly what the daily reconciliation would do, an hour after everything looked fine.
$wrong_key = array();

foreach ( $probe_sent as $sent ) {
	$table = sr_table_of( $sent['url'] );

	if ( in_array( $table, $content_tables, true ) && false === strpos( $sent['url'], 'on_conflict=table_name,row_id' ) ) {
		$wrong_key[] = $table;
	}
}

sr_note( 'and the content rows are upserted on their own key, not on id', array() === $wrong_key, implode( ', ', $wrong_key ) );

sr_note(
	sprintf( 'having written every row it holds (%d entity, %d content)', $pushed, $content_rows ),
	$result['upserted'] === $pushed + $content_rows,
	sprintf( 'upserted=%d, expected=%d', $result['upserted'], $pushed + $content_rows )
);

// ----------------------------------------------------------------- 3: a push Supabase refuses

$probe_status = 401;
$probe_sent   = array();

$refused = bookify_booking_supabase_backfill();

$failed = 0;
$said   = '';

foreach ( $refused as $table => $row ) {
	if ( '' !== $row['error'] ) {
		++$failed;
		$said = $row['error'];
	}
}

sr_note( 'a refusal is reported per table rather than thrown', count( $expected_order ) === $failed, sprintf( '%d of %d tables reported an error', $failed, count( $expected_order ) ) );
sr_note( 'and repeats what Supabase said, which is the only thing that says what to fix', false !== strpos( $said, 'JWT expired' ), $said );

bookify_booking_supabase_reconcile();

$result = bookify_booking_supabase_last_result();

sr_note( 'the reconciliation records the failure too, so it cannot fail quietly', ! $result['ok'] && '' !== $result['message'] );

// ---------------------------------------------------------------------------- 4: the schedule

wp_clear_scheduled_hook( BOOKIFY_SUPABASE_RECONCILE_HOOK );
bookify_booking_supabase_schedule();

$scheduled = wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK );

sr_note( 'a configured mirror puts the reconciliation on the cron', false !== $scheduled );

// A second call while it is already scheduled must not add a second entry.
bookify_booking_supabase_schedule();

sr_note(
	'and calling it again does not add a second entry',
	$scheduled === wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK ),
	sprintf( 'next=%s, again=%s', var_export( $scheduled, true ), var_export( wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK ), true ) )
);

// ------------------------------------------------------------------------------- the off path

remove_filter( 'bookify_booking_supabase_url', $probe_url );
remove_filter( 'bookify_booking_supabase_key', $probe_key );

sr_note( 'switching the mirror off is seen', ! bookify_booking_supabase_is_configured() );

bookify_booking_supabase_schedule();

sr_note( 'and takes the cron entry away with it', false === wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK ) );

// --------------------------------------------------------------------------------- cleanup

delete_option( BOOKIFY_SUPABASE_LAST_RESULT_OPTION );

sr_note( 'cleaned up: no schedule, no record', false === wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK ) && 0 === bookify_booking_supabase_last_result()['time'] );
