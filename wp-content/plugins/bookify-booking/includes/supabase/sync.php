<?php
/**
 * Keeping Supabase in step with WordPress.
 *
 * **One direction only.** WordPress writes to MariaDB exactly as it always has, and this file watches
 * those writes and republishes the affected rows to Supabase afterwards. Nothing here is ever read
 * back: no page, no booking, no capacity check asks Supabase anything, so the mirror cannot change an
 * answer this site gives. If Supabase is down, slow, misconfigured, or holding an expired key, a
 * booking is still taken and a confirmation still goes out — the only thing lost is a copy, and the
 * failure is recorded (`bookify_supabase_last_result`) rather than swallowed.
 *
 * **Rows are built at the end of the request, not when the write happens.** This is the part that is
 * easy to get wrong. `wp_insert_post()` fires `save_post` *before* the plugin writes the twenty-odd
 * meta values that make the booking a booking, so a transform run at that moment would publish a row
 * of nulls to every column. The hooks below therefore only mark an id as dirty; the transform runs on
 * `shutdown`, by which time the post and all of its meta are stored.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cron hook that re-publishes everything.
 *
 * A constant rather than a literal repeated in three places, because a typo in one of them is an event
 * that is scheduled and never fires — which looks exactly like a working schedule until somebody
 * checks the clock.
 */
const BOOKIFY_SUPABASE_RECONCILE_HOOK = 'bookify_supabase_reconcile';

/**
 * How often the whole catalogue is re-published.
 *
 * Daily, because this is a safety net rather than a queue. Every write is an upsert keyed on the post
 * id, so re-sending a row that has not changed writes the values it already holds — which means a push
 * that failed at the time is simply corrected the next time this runs. That is what makes the mirror
 * self-healing without a queue, a retry counter or any state of its own: four batched requests a day
 * for a catalogue this size, set against the certainty that a transient failure is not permanent.
 *
 * It deliberately does **not** prune. Removing rows is the one operation here that can destroy data,
 * and it is driven entirely by what the local query returns — so a filter that suddenly returned
 * nothing would empty the mirror. `wp bookify-supabase sync --prune` stays an operator's decision.
 */
const BOOKIFY_SUPABASE_RECONCILE_INTERVAL = 'daily';

/**
 * The tables the mirror keeps, in dependency order.
 *
 * The order is load-bearing: a tier's event and a booking's session must exist before the row that
 * points at them, or the foreign key refuses the batch — so this is a topological order, not
 * alphabetical and not by size. Settings and customers point at nothing.
 *
 * Each entry says where its rows come from as well as how to build them: a `post_type` for the four
 * entity tables, `users` for the WordPress accounts, and `singleton` for the one settings row. Those
 * three sources are why `local_ids()` below is a switch rather than a single query.
 *
 * @return array<string,array{row:callable-string,post_type?:string,users?:bool,attachments?:bool,singleton?:bool}>
 */
function bookify_booking_supabase_tables() {
	return array(
		'bookify_settings'  => array(
			'singleton' => true,
			'row'       => 'bookify_booking_supabase_settings_row',
		),
		'bookify_services'  => array(
			'post_type' => 'bookify_service',
			'row'       => 'bookify_booking_supabase_service_row',
		),
		'bookify_events'    => array(
			'post_type' => 'bookify_event',
			'row'       => 'bookify_booking_supabase_event_row',
		),
		'bookify_tiers'     => array(
			'post_type' => 'bookify_tier',
			'row'       => 'bookify_booking_supabase_tier_row',
		),
		'bookify_customers' => array(
			'users' => true,
			'row'   => 'bookify_booking_supabase_customer_row',
		),
		'bookify_bookings'  => array(
			'post_type' => 'bookify_booking',
			'row'       => 'bookify_booking_supabase_booking_row',
		),
		// Last, and not because anything points at it. A row here is an image file rather than a record, the
		// largest thing this plugin sends, and it is wanted only once a reader has decided which session or
		// event to look at — so it goes after everything a reader needs.
		//
		// Both keys are needed, and for different reasons. `attachments` tells `local_ids()` how to find
		// them, because an attachment is neither a post type in the usual statuses nor a user; `post_type`
		// lets `table_for()` answer for `attachment`, which is what hooks `save_post_attachment` from the
		// loop at the bottom of this file.
		'bookify_media'     => array(
			'attachments' => true,
			'post_type'   => 'attachment',
			'row'         => 'bookify_booking_supabase_media_row',
		),
	);
}

/**
 * The table a post type is mirrored to, or ''.
 *
 * @param string $post_type Post type name.
 * @return string
 */
function bookify_booking_supabase_table_for( $post_type ) {
	foreach ( bookify_booking_supabase_tables() as $table => $shape ) {
		if ( isset( $shape['post_type'] ) && $shape['post_type'] === $post_type ) {
			return $table;
		}
	}

	return '';
}

/**
 * What this request has marked dirty.
 *
 * Held by reference so the queueing functions can add to it, and read whole by the flush.
 *
 * @return array{upsert:array<string,array<int,int>>,delete:array<string,array<int,int>>}
 */
function &bookify_booking_supabase_pending() {
	static $pending = array(
		'upsert' => array(),
		'delete' => array(),
	);

	return $pending;
}

/**
 * Mark one post as needing to be republished.
 *
 * Keyed by id, so a booking whose eleven meta values are written in one request is one row to send
 * rather than eleven. A queued delete for the same id is dropped: the row exists again, so removing it
 * afterwards would be wrong.
 *
 * @param string $table   Table name.
 * @param int    $post_id The post.
 * @return void
 */
function bookify_booking_supabase_queue_upsert( $table, $post_id ) {
	if ( '' === $table ) {
		return;
	}

	$pending = &bookify_booking_supabase_pending();

	$post_id = (int) $post_id;

	$pending['upsert'][ $table ][ $post_id ] = $post_id;

	unset( $pending['delete'][ $table ][ $post_id ] );
}

/**
 * Mark one post as needing to be removed.
 *
 * @param string $table   Table name.
 * @param int    $post_id The post.
 * @return void
 */
function bookify_booking_supabase_queue_delete( $table, $post_id ) {
	if ( '' === $table ) {
		return;
	}

	$pending = &bookify_booking_supabase_pending();

	$post_id = (int) $post_id;

	$pending['delete'][ $table ][ $post_id ] = $post_id;

	unset( $pending['upsert'][ $table ][ $post_id ] );
}

/**
 * Mark a session, event, tier or booking as dirty when it is saved.
 *
 * @param int           $post_id The post.
 * @param \WP_Post|null $post    The post object, when WordPress supplies it.
 * @return void
 */
function bookify_booking_supabase_watch_post( $post_id, $post = null ) {
	// A revision is a copy of a row and an autosave is a draft of a copy. Neither is the row itself,
	// and mirroring them would put ids in these tables that no session or booking owns.
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$post = $post instanceof WP_Post ? $post : get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	bookify_booking_supabase_queue_upsert( bookify_booking_supabase_table_for( $post->post_type ), $post_id );
}

/**
 * Mark a booking dirty when its own meta changes, even though the post did not.
 *
 * This is not belt-and-braces and the mirror is wrong without it: the Stripe webhook confirms a
 * booking by writing `bookify_status` and `bookify_payment_status` and nothing else
 * (`includes/payments/webhook.php`), which fires no `save_post` at all. A booking moved, cancelled or
 * confirmed by a code path that only touches meta has to reach Supabase too, so every `bookify_*` meta
 * write on one of these post types counts as a change to the row.
 *
 * @param int    $meta_id  Meta row id, unused.
 * @param int    $post_id  The post the meta belongs to.
 * @param string $meta_key The meta key.
 * @return void
 */
function bookify_booking_supabase_watch_meta( $meta_id, $post_id, $meta_key ) {
	$meta_key = (string) $meta_key;

	/*
	 * Two kinds of key are watched.
	 *
	 * The plugin's own, because the Stripe webhook confirms a booking by writing `bookify_status` and
	 * `bookify_payment_status` and nothing else, which fires no `save_post` at all.
	 *
	 * And the four pieces of WordPress's own meta that are *columns* of a mirrored table rather than
	 * internal bookkeeping: a featured image is `bookify_services.thumbnail_id` and
	 * `bookify_events.thumbnail_id`, and the other three are the file, the generated sizes and the alt
	 * text of a `bookify_media` row. Without these, choosing a new photo for a session — or editing the
	 * alt text of one already chosen — would leave the mirror holding the previous answer.
	 */
	$columns = array( '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' );

	if ( ! str_starts_with( $meta_key, 'bookify_' ) && ! in_array( $meta_key, $columns, true ) ) {
		return;
	}

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	bookify_booking_supabase_queue_upsert( bookify_booking_supabase_table_for( $post->post_type ), $post_id );
}

/**
 * Mark a row for removal when its post is deleted outright.
 *
 * `deleted_post` fires *after* the post row is gone, so `get_post()` answers null here and the object
 * WordPress hands over is the only way left to learn the post type. This is the one path that removes
 * anything from Supabase, and it is what keeps the mirror honest when a probe cleans up after itself
 * with `wp_delete_post( $id, true )`.
 *
 * @param int           $post_id The post.
 * @param \WP_Post|null $post    The post object, as it was.
 * @return void
 */
function bookify_booking_supabase_watch_delete( $post_id, $post = null ) {
	$post = $post instanceof WP_Post ? $post : get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	bookify_booking_supabase_queue_delete( bookify_booking_supabase_table_for( $post->post_type ), $post_id );
}

/**
 * The rows for a table: either named ids, or everything WordPress holds.
 *
 * @param string $table Table name.
 * @param int[]  $ids   Optional. Ids to build. Default: every id of that post type.
 * @return array<int,array<string,mixed>>
 */
function bookify_booking_supabase_rows( $table, array $ids = array() ) {
	$tables = bookify_booking_supabase_tables();

	if ( ! isset( $tables[ $table ] ) ) {
		return array();
	}

	$ids = $ids ? $ids : bookify_booking_supabase_local_ids( $table );

	$rows = array();

	foreach ( $ids as $id ) {
		$row = call_user_func( $tables[ $table ]['row'], $id );

		// Null means "this id is not a row of this table" — a booking whose post type is something
		// else, or a tier whose event has gone. Skipping it is the whole point of the null.
		if ( is_array( $row ) ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Every id WordPress holds for a table.
 *
 * Three sources, because the mirror covers three kinds of thing: posts, user accounts, and one row of
 * settings that has no identity of its own — the singleton answers with the id 1, which is also the
 * conflict target its upsert needs.
 *
 * @param string $table Table name.
 * @return int[]
 */
function bookify_booking_supabase_local_ids( $table ) {
	$tables = bookify_booking_supabase_tables();

	if ( ! isset( $tables[ $table ] ) ) {
		return array();
	}

	$shape = $tables[ $table ];

	if ( ! empty( $shape['singleton'] ) ) {
		return array( 1 );
	}

	if ( ! empty( $shape['users'] ) ) {
		return array_map( 'intval', (array) get_users( array( 'fields' => 'ID' ) ) );
	}

	if ( ! empty( $shape['attachments'] ) ) {
		// Not the status list the other post types use. WordPress puts every attachment in `inherit`,
		// because an attachment has no publication state of its own and takes the state of whatever it is
		// attached to; asked for the usual six statuses this query comes back empty, which is exactly the
		// kind of silent nothing a mirror must not have. The mime types are the same whitelist the row
		// builder applies, so the two cannot disagree about which attachments the table holds.
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => bookify_booking_supabase_media_types(),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
		);
	}

	$ids = get_posts(
		array(
			'post_type'      => $shape['post_type'],
			'post_status'    => bookify_booking_supabase_statuses(),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Send everything this request marked dirty.
 *
 * @param array{upsert:array<string,array<int,int>>,delete:array<string,array<int,int>>} $work What to send.
 * @return array{ok:bool,message:string,upserted:int,deleted:int}
 */
function bookify_booking_supabase_push( array $work ) {
	$upserted = 0;
	$deleted  = 0;
	$problems = array();

	// Upserts first, in dependency order: a booking whose session and tier are dirty in the same
	// request has to have those rows written before it, or the foreign key refuses the batch.
	foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
		$ids = isset( $work['upsert'][ $table ] ) ? array_values( (array) $work['upsert'][ $table ] ) : array();

		if ( ! $ids ) {
			continue;
		}

		$rows = bookify_booking_supabase_rows( $table, $ids );

		if ( ! $rows ) {
			continue;
		}

		$result = bookify_booking_supabase_upsert( $table, $rows );

		if ( is_wp_error( $result ) ) {
			$problems[] = $table . ': ' . $result->get_error_message();

			continue;
		}

		$upserted += count( $rows );
	}

	// Then the removals, so a row re-created later in the same request is not the one deleted.
	foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
		$ids = isset( $work['delete'][ $table ] ) ? array_values( (array) $work['delete'][ $table ] ) : array();

		if ( ! $ids ) {
			continue;
		}

		$result = bookify_booking_supabase_delete( $table, $ids );

		if ( is_wp_error( $result ) ) {
			$problems[] = $table . ': ' . $result->get_error_message();

			continue;
		}

		$deleted += count( $ids );
	}

	return array(
		'ok'       => ! $problems,
		'message'  => implode( ' | ', $problems ),
		'upserted' => $upserted,
		'deleted'  => $deleted,
	);
}

/**
 * Push whatever this request marked dirty.
 *
 * Hooked to `shutdown` rather than run inline, for two reasons: the rows are only complete once the
 * whole request has finished writing them (see the file header), and nothing a visitor is still
 * waiting for sits behind a call to an external service.
 *
 * @return void
 */
function bookify_booking_supabase_flush() {
	if ( ! bookify_booking_supabase_is_configured() ) {
		return;
	}

	$pending = &bookify_booking_supabase_pending();

	if ( ! $pending['upsert'] && ! $pending['delete'] ) {
		return;
	}

	// Taken and emptied before the request goes out, so anything written *during* the push is queued
	// for the next one rather than being lost with the batch or sent a second time.
	$work    = $pending;
	$pending = array(
		'upsert' => array(),
		'delete' => array(),
	);

	try {
		$summary = bookify_booking_supabase_push( $work );
	} catch ( \Throwable $e ) {
		// The mirror is a passenger. A bug in it, or one row it cannot shape, must not become a fatal
		// error at the end of a request that has already taken a booking and sent a confirmation. The
		// failure is recorded instead, because a mirror that fails silently is worse than none.
		$summary = array(
			'ok'       => false,
			'message'  => $e->getMessage(),
			'upserted' => 0,
			'deleted'  => 0,
		);
	}

	bookify_booking_supabase_record( $summary );
}

/**
 * Publish the whole catalogue and every booking.
 *
 * Idempotent, and safe to run as often as wanted: every write is an upsert keyed on the WordPress post
 * id, so a row that has not changed is written with the same values it already holds.
 *
 * @param string[] $only    Optional. Table names to send. Default: all of them.
 * @param bool     $dry_run Optional. Build the rows and report them without sending anything.
 * @return array<string,array{sources:int,rows:int,pushed:bool,skipped:string,error:string}>
 */
function bookify_booking_supabase_backfill( array $only = array(), $dry_run = false ) {
	$report = array();

	foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
		if ( $only && ! in_array( $table, $only, true ) ) {
			continue;
		}

		$posts = bookify_booking_supabase_local_ids( $table );
		$rows  = bookify_booking_supabase_rows( $table, $posts );

		$report[ $table ] = array(
			// "sources", not "posts": two of these tables come from users and from one row of options.
			'sources' => count( $posts ),
			'rows'    => count( $rows ),
			'pushed'  => false,
			'skipped' => '',
			// A push that failed and a row that could not be built are different things and are kept
			// apart: one is Supabase's problem and worth retrying, the other is this site's data and is
			// not an error at all.
			'error'   => '',
		);

		// A post that produced no row — a tier whose event has gone — is the difference between these
		// two counts, and it is worth naming rather than leaving as arithmetic to be noticed.
		if ( count( $rows ) < count( $posts ) ) {
			$report[ $table ]['skipped'] = sprintf(
				/* translators: %d: how many posts produced no row. */
				__( '%d post(s) produced no row', 'bookify-booking' ),
				count( $posts ) - count( $rows )
			);
		}

		if ( $dry_run || ! $rows ) {
			continue;
		}

		$result = bookify_booking_supabase_upsert( $table, $rows );

		if ( is_wp_error( $result ) ) {
			$report[ $table ]['error'] = $result->get_error_message();

			continue;
		}

		$report[ $table ]['pushed'] = true;
	}

	return $report;
}

/**
 * Remove the rows Supabase holds that WordPress no longer has.
 *
 * Only for posts deleted outright. A trashed post is not an orphan — it is mirrored with
 * `status = 'trash'` — so this is narrower than it sounds, and it exists because the probes here
 * hard-delete their fixtures constantly.
 *
 * @param string[] $only    Optional. Table names to check. Default: all of them.
 * @param bool     $dry_run Optional. Report the orphans without removing them.
 * @return array<string,array<string,mixed>>
 */
function bookify_booking_supabase_prune( array $only = array(), $dry_run = false ) {
	$report = array();

	foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
		if ( $only && ! in_array( $table, $only, true ) ) {
			continue;
		}

		$remote = bookify_booking_supabase_ids( $table );

		if ( is_wp_error( $remote ) ) {
			$report[ $table ] = array( 'error' => $remote->get_error_message() );

			continue;
		}

		$local = bookify_booking_supabase_local_ids( $table );
		$gone  = array_values( array_diff( $remote, $local ) );

		$report[ $table ] = array(
			'remote'  => count( $remote ),
			'local'   => count( $local ),
			'removed' => 0,
			'orphans' => $gone,
			'error'   => '',
		);

		if ( ! $gone || $dry_run ) {
			continue;
		}

		$result = bookify_booking_supabase_delete( $table, $gone );

		if ( is_wp_error( $result ) ) {
			$report[ $table ]['error'] = $result->get_error_message();

			continue;
		}

		$report[ $table ]['removed'] = count( $gone );
	}

	return $report;
}

/**
 * Put the reconciliation on the cron, or take it off again.
 *
 * Only while the mirror is switched on: a site that has never configured Supabase should not carry a
 * scheduled event that wakes up every day to do nothing. The check is two reads of the cron option,
 * which is autoloaded, so this costs nothing on a normal request.
 *
 * @return void
 */
function bookify_booking_supabase_schedule() {
	$scheduled = wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK );

	if ( ! bookify_booking_supabase_is_configured() ) {
		if ( $scheduled ) {
			wp_clear_scheduled_hook( BOOKIFY_SUPABASE_RECONCILE_HOOK );
		}

		return;
	}

	if ( ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, BOOKIFY_SUPABASE_RECONCILE_INTERVAL, BOOKIFY_SUPABASE_RECONCILE_HOOK );
	}
}

/**
 * Re-publish everything, and record how it went.
 *
 * The answer to "what happens if Supabase is unreachable when somebody books?". The push at the end of
 * that request failed and was recorded; this runs later, re-sends the whole catalogue, and the booking
 * that was missed is simply one of the rows in it. Nothing has to remember what failed.
 *
 * It also re-sends the WordPress tables — the pages, the layouts, the options — which are the larger
 * and slower half and are pushed nowhere else. Same reasoning: a row that has not changed is written
 * with the values it already holds, so this is what corrects a content push that failed.
 *
 * @return void
 */
function bookify_booking_supabase_reconcile() {
	if ( ! bookify_booking_supabase_is_configured() ) {
		return;
	}

	$written  = 0;
	$problems = array();

	foreach ( bookify_booking_supabase_backfill() as $table => $report ) {
		$written += (int) $report['rows'];

		if ( '' !== $report['error'] ) {
			$problems[] = $table . ': ' . $report['error'];
		}
	}

	foreach ( bookify_booking_supabase_content_push() as $table => $report ) {
		$written += (int) $report['rows'];

		if ( '' !== $report['error'] ) {
			$problems[] = $table . ': ' . $report['error'];
		}
	}

	bookify_booking_supabase_record(
		array(
			'ok'       => ! $problems,
			'message'  => implode( ' | ', $problems ),
			'upserted' => $written,
			'deleted'  => 0,
		)
	);
}

/**
 * The options the settings row is built from.
 *
 * `bookify_availability_version` is deliberately absent. It is a cache counter that every booking
 * write bumps, so watching it would re-send the settings row on every booking — and the row would
 * still say exactly the same thing.
 *
 * @return string[]
 */
function bookify_booking_supabase_option_names() {
	return array( 'bookify_business', 'bookify_availability', 'bookify_bots', 'bookify_payments', 'bookify_reminders' );
}

/**
 * Mark the settings row dirty when one of the options behind it changes.
 *
 * @param string $option Option name.
 * @return void
 */
function bookify_booking_supabase_watch_option( $option ) {
	if ( in_array( (string) $option, bookify_booking_supabase_option_names(), true ) ) {
		bookify_booking_supabase_queue_upsert( 'bookify_settings', 1 );
	}
}

/**
 * Mark a customer dirty when the account is created or changed.
 *
 * @param int $user_id The user.
 * @return void
 */
function bookify_booking_supabase_watch_user( $user_id ) {
	bookify_booking_supabase_queue_upsert( 'bookify_customers', $user_id );
}

/**
 * Mark a customer for removal when the account is deleted.
 *
 * `deleted_user` fires after the row is gone, so the id is all there is and the row cannot be built
 * again — which is exactly why this queues a removal rather than an update.
 *
 * @param int $user_id The user.
 * @return void
 */
function bookify_booking_supabase_watch_user_delete( $user_id ) {
	bookify_booking_supabase_queue_delete( 'bookify_customers', $user_id );
}

/**
 * Start watching for changes worth mirroring.
 */
function bookify_booking_register_supabase() {
	foreach ( bookify_booking_supabase_tables() as $shape ) {
		if ( isset( $shape['post_type'] ) ) {
			add_action( 'save_post_' . $shape['post_type'], 'bookify_booking_supabase_watch_post', 20, 2 );
		}
	}

	add_action( 'added_post_meta', 'bookify_booking_supabase_watch_meta', 10, 3 );
	add_action( 'updated_post_meta', 'bookify_booking_supabase_watch_meta', 10, 3 );
	add_action( 'deleted_post_meta', 'bookify_booking_supabase_watch_meta', 10, 3 );

	add_action( 'deleted_post', 'bookify_booking_supabase_watch_delete', 10, 2 );

	// The settings row is built from options, and the customer rows from users, so each needs a watch
	// of its own kind. `updated_option` fires for every option on the site, so the name is checked
	// rather than assumed.
	add_action( 'added_option', 'bookify_booking_supabase_watch_option', 10, 1 );
	add_action( 'updated_option', 'bookify_booking_supabase_watch_option', 10, 1 );

	add_action( 'user_register', 'bookify_booking_supabase_watch_user', 10, 1 );
	add_action( 'profile_update', 'bookify_booking_supabase_watch_user', 10, 1 );
	add_action( 'deleted_user', 'bookify_booking_supabase_watch_user_delete', 10, 1 );

	add_action( 'shutdown', 'bookify_booking_supabase_flush', 20 );

	add_action( BOOKIFY_SUPABASE_RECONCILE_HOOK, 'bookify_booking_supabase_reconcile' );

	bookify_booking_supabase_schedule();

	if ( class_exists( 'WP_CLI' ) ) {
		require_once __DIR__ . '/cli.php';
	}
}
