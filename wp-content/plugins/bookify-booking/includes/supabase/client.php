<?php
/**
 * Talking to Supabase.
 *
 * Supabase's Postgres is reached over its PostgREST API and not over a database socket, and not by
 * choice: the PHP this runs on has `mysqli` and `mysqlnd` but neither `pgsql` nor `pdo_pgsql`
 * (measured, `PHP 8.3.33`), so there is no way to open a Postgres connection from WordPress here even
 * if WordPress were the thing being replaced — which it is not. WordPress and its other three plugins
 * stay on MariaDB; this file is how a *copy* gets out.
 *
 * The shape follows `includes/mailer.php`, which is the one other place in this plugin that talks to
 * an external service: read the configuration every time rather than cache it, treat a value that
 * will not validate as absent instead of as configured, and answer `true` or a `WP_Error` carrying
 * the service's own explanation.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the last push's outcome is recorded.
 *
 * The same arrangement as `bookify_mail_last_result`, and for the same reason: the alternative is a
 * mirror that fails silently, and a mirror that fails silently is worse than none, because the copy
 * is trusted. Not autoloaded — it is a record, not configuration.
 */
const BOOKIFY_SUPABASE_LAST_RESULT_OPTION = 'bookify_supabase_last_result';

/**
 * How the API is addressed.
 *
 * A generous timeout on purpose. Nothing a visitor waits for depends on this: the push happens on
 * `shutdown`, after the page, the booking and the confirmation have all been dealt with. Failing
 * slowly is better than failing on a slow-but-working connection.
 *
 * @return array{endpoint:string,timeout:int}
 */
function bookify_booking_supabase_defaults() {
	return array(
		'endpoint' => 'rest/v1',
		'timeout'  => 15,
	);
}

/**
 * The project URL and the key, read from configuration every time.
 *
 * `service_role` is the key this needs, and it is a **full-database credential** — it bypasses row
 * level security entirely. It therefore follows the convention the Stripe and Resend secrets already
 * follow here: a constant, an environment variable, or a filter, and never the database, never a
 * settings field, and never a chat message.
 *
 * A URL that will not parse is treated as absent rather than as configured, which is the same rule
 * the from-address gets in `mailer.php`: a half-configured mirror has to look switched off, because
 * the alternative is something that looks switched on and fails every write.
 *
 * @return array{url:string,key:string}
 */
function bookify_booking_supabase_config() {
	$url = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_URL',
		array( 'BOOKIFY_SUPABASE_URL', 'SUPABASE_URL' ),
		'bookify_booking_supabase_url'
	);

	$key = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_KEY',
		// The constant's own name first, so the variable the CLI's error message names is the one that
		// works; the rest are aliases matching what Supabase's own tooling calls them.
		array( 'BOOKIFY_SUPABASE_KEY', 'BOOKIFY_SUPABASE_SERVICE_KEY', 'SUPABASE_SERVICE_ROLE_KEY' ),
		'bookify_booking_supabase_key'
	);

	$parts = '' === $url ? false : wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] )
		|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		$url = '';
	}

	return array(
		'url' => '' === $url ? '' : rtrim( $url, '/' ),
		'key' => $key,
	);
}

/**
 * Whether the mirror is switched on.
 *
 * Either transport is enough. A direct database URL is a complete configuration on its own — opening a
 * socket to Postgres involves no project URL and no API key — so the two are checked independently
 * rather than the HTTP pair being treated as the only way in.
 *
 * The HTTP pair still requires both halves, like the mail transport: a URL with no key would look
 * configured and then answer 401 on every push, and a key with no URL is not a destination.
 *
 * @return bool
 */
function bookify_booking_supabase_is_configured() {
	if ( bookify_booking_supabase_db_is_configured() ) {
		return true;
	}

	$config = bookify_booking_supabase_config();

	return '' !== $config['url'] && '' !== $config['key'];
}

/**
 * Make one request to PostgREST.
 *
 * @param string              $method HTTP method.
 * @param string              $path   Path after `rest/v1`, query string included.
 * @param array<string,mixed> $args   Optional. `body` is encoded as JSON; `prefer` becomes the
 *                                    `Prefer` header.
 * @return array{code:int,body:string,json:array|null}|\WP_Error
 */
function bookify_booking_supabase_request( $method, $path, array $args = array() ) {
	$config = bookify_booking_supabase_config();

	if ( '' === $config['url'] || '' === $config['key'] ) {
		return new WP_Error(
			'bookify_supabase_not_configured',
			__( 'Supabase is not configured, so nothing was mirrored.', 'bookify-booking' )
		);
	}

	$defaults = bookify_booking_supabase_defaults();

	$headers = array(
		'apikey'        => $config['key'],
		// PostgREST reads the key from either header. Supabase's own client sends both, and the
		// gateway is what answers 401 first, before PostgREST ever sees the request.
		'Authorization' => 'Bearer ' . $config['key'],
		'Accept'        => 'application/json',
	);

	$body = null;

	if ( array_key_exists( 'body', $args ) ) {
		$body                    = wp_json_encode( $args['body'] );
		$headers['Content-Type'] = 'application/json';
	}

	if ( isset( $args['prefer'] ) && '' !== (string) $args['prefer'] ) {
		$headers['Prefer'] = (string) $args['prefer'];
	}

	$request = array(
		'method'  => $method,
		'timeout' => $defaults['timeout'],
		'headers' => $headers,
	);

	if ( null !== $body ) {
		$request['body'] = $body;
	}

	$response = wp_remote_request(
		$config['url'] . '/' . $defaults['endpoint'] . '/' . ltrim( $path, '/' ),
		$request
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	if ( $code >= 200 && $code < 300 ) {
		return array(
			'code' => $code,
			'body' => $raw,
			'json' => is_array( $json ) ? $json : null,
		);
	}

	return new WP_Error(
		'bookify_supabase_' . $code,
		sprintf(
			/* translators: 1: the HTTP status Supabase answered with, 2: Supabase's own explanation. */
			__( 'Supabase refused the write (HTTP %1$d): %2$s', 'bookify-booking' ),
			$code,
			bookify_booking_supabase_reason( $json, $raw )
		)
	);
}

/**
 * The most useful sentence in a PostgREST error body.
 *
 * A refusal is otherwise a bare status code, and the status is genuinely ambiguous here: 401 on a
 * wrong key and 401 on a key that was right until the row level security policy changed look
 * identical. PostgREST puts the difference in `message`, and often the fix in `hint`.
 *
 * @param mixed  $json Decoded body, if it was JSON.
 * @param string $raw  Raw body.
 * @return string
 */
function bookify_booking_supabase_reason( $json, $raw ) {
	if ( is_array( $json ) ) {
		foreach ( array( 'message', 'hint', 'details', 'error_description', 'error' ) as $field ) {
			if ( isset( $json[ $field ] ) && is_string( $json[ $field ] ) && '' !== trim( $json[ $field ] ) ) {
				return trim( $json[ $field ] );
			}
		}
	}

	$text = trim( wp_strip_all_tags( (string) $raw ) );

	return '' !== $text ? $text : __( 'no explanation given', 'bookify-booking' );
}

/**
 * Insert or update rows, keyed on `id`.
 *
 * `POST` with `Prefer: resolution=merge-duplicates` is PostgREST's upsert: without it the request is
 * a plain insert that fails the moment a row already exists, which would make every re-sync of an
 * unchanged row an error.
 *
 * Rows are sent in one request rather than one request each, so a backfill of the whole catalogue is
 * a handful of round trips instead of one per post.
 *
 * A configured database URL takes precedence and this is not used at all: `includes/supabase/
 * postgres.php` writes the same rows over a socket instead. The caller does not choose.
 *
 * @param string                 $table Table name.
 * @param array<int,array<mixed>> $rows  Rows to write.
 * @param string[]               $key   Optional. The columns the conflict is resolved on. Default `id`.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_upsert( $table, array $rows, array $key = array( 'id' ) ) {
	if ( ! $rows ) {
		return true;
	}

	if ( bookify_booking_supabase_db_is_configured() ) {
		return bookify_booking_supabase_pg_upsert( $table, $rows, $key );
	}

	$result = bookify_booking_supabase_request(
		'POST',
		rawurlencode( $table ) . '?on_conflict=' . rawurlencode( implode( ',', $key ) ),
		array(
			'body'   => array_values( $rows ),
			'prefer' => 'resolution=merge-duplicates,return=minimal',
		)
	);

	return is_wp_error( $result ) ? $result : true;
}

/**
 * Every id a table currently holds.
 *
 * @param string $table Table name.
 * @return int[]|\WP_Error
 */
function bookify_booking_supabase_ids( $table ) {
	if ( bookify_booking_supabase_db_is_configured() ) {
		return bookify_booking_supabase_pg_ids( $table );
	}

	$result = bookify_booking_supabase_request( 'GET', rawurlencode( $table ) . '?select=id&limit=10000' );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$ids = array();

	foreach ( (array) $result['json'] as $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) ) {
			$ids[] = (int) $row['id'];
		}
	}

	return $ids;
}

/**
 * Rows, in full, for some ids or for all of them.
 *
 * The counterpart of `bookify_booking_supabase_upsert()`, and used by exactly one caller: putting the
 * image files back on disk after a host without a persistent disk has replaced them. A configured
 * database URL takes precedence here as it does on the write path, so the caller does not choose.
 *
 * The limit is explicit rather than left to PostgREST's default of 1000, which would silently
 * truncate a media library larger than that — the same reason `bookify_booking_supabase_ids()` spells
 * one out. Over HTTPS the answer is capped; over a socket it is not.
 *
 * @param string $table    Table name.
 * @param int[]  $ids      Optional. Ids to read. Default: every row.
 * @param string $order_by Optional. The column to sort by on the direct connection, or `''`.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function bookify_booking_supabase_select( $table, array $ids = array(), $order_by = 'id' ) {
	if ( bookify_booking_supabase_db_is_configured() ) {
		return bookify_booking_supabase_pg_select( $table, $ids, $order_by );
	}

	$path = rawurlencode( $table ) . '?select=*&limit=10000';

	if ( $ids ) {
		$path .= '&id=in.(' . implode( ',', array_map( 'absint', $ids ) ) . ')';
	}

	$result = bookify_booking_supabase_request( 'GET', $path );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return is_array( $result['json'] ) ? $result['json'] : array();
}

/**
 * Delete rows by id.
 *
 * Used only by the prune, which is the one operation that removes anything from Supabase: a row whose
 * WordPress post has been deleted outright. Trashing a post is not a deletion — it is mirrored as
 * `status = 'trash'` — so this only ever sees ids that no longer exist here at all.
 *
 * @param string $table Table name.
 * @param int[]  $ids   Ids to remove.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_delete( $table, array $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

	if ( ! $ids ) {
		return true;
	}

	if ( bookify_booking_supabase_db_is_configured() ) {
		return bookify_booking_supabase_pg_delete( $table, $ids );
	}

	$result = bookify_booking_supabase_request(
		'DELETE',
		rawurlencode( $table ) . '?id=in.(' . implode( ',', $ids ) . ')'
	);

	return is_wp_error( $result ) ? $result : true;
}

/**
 * Remove rows of `bookify_content` by their two-column key.
 *
 * The counterpart of `bookify_booking_supabase_delete()`, which removes entity rows by their integer
 * `id`. These are keyed on `( table_name, row_id )` and the row id is text — `wp_term_relationships`'s
 * is two key columns joined with a colon — so the two cannot share an implementation without one of them
 * being wrong.
 *
 * @param array<string,string[]> $by_table Table name to the row ids to remove.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_delete_content( array $by_table ) {
	if ( ! $by_table ) {
		return true;
	}

	if ( bookify_booking_supabase_db_is_configured() ) {
		return bookify_booking_supabase_pg_delete_content( $by_table );
	}

	foreach ( $by_table as $table => $ids ) {
		$ids = array_values( array_unique( array_map( 'strval', (array) $ids ) ) );

		if ( ! $ids ) {
			continue;
		}

		// Quoted, because PostgREST's `in.(...)` list splits on commas and a row id may contain anything a
		// primary key can. A backslash has to be escaped before a quote, or `a\` would leave the list open.
		$list = array();

		foreach ( $ids as $id ) {
			$list[] = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $id ) . '"';
		}

		$result = bookify_booking_supabase_request(
			'DELETE',
			rawurlencode( 'bookify_content' )
				. '?table_name=eq.' . rawurlencode( (string) $table )
				. '&row_id=in.(' . implode( ',', $list ) . ')'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return true;
}

/**
 * The record of the last push.
 *
 * @return array{time:int,ok:bool,message:string,upserted:int,deleted:int}
 */
function bookify_booking_supabase_last_result() {
	$stored = get_option( BOOKIFY_SUPABASE_LAST_RESULT_OPTION, array() );

	return array(
		'time'     => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
		'ok'       => ! empty( $stored['ok'] ),
		'message'  => isset( $stored['message'] ) ? (string) $stored['message'] : '',
		'upserted' => isset( $stored['upserted'] ) ? (int) $stored['upserted'] : 0,
		'deleted'  => isset( $stored['deleted'] ) ? (int) $stored['deleted'] : 0,
	);
}

/**
 * Record the outcome of a push.
 *
 * @param array{ok:bool,message:string,upserted:int,deleted:int} $summary What happened.
 * @return void
 */
function bookify_booking_supabase_record( array $summary ) {
	update_option(
		BOOKIFY_SUPABASE_LAST_RESULT_OPTION,
		array(
			'time'     => time(),
			'ok'       => ! empty( $summary['ok'] ),
			'message'  => isset( $summary['message'] ) ? (string) $summary['message'] : '',
			'upserted' => isset( $summary['upserted'] ) ? (int) $summary['upserted'] : 0,
			'deleted'  => isset( $summary['deleted'] ) ? (int) $summary['deleted'] : 0,
		),
		false
	);
}
