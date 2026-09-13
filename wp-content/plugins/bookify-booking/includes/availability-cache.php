<?php
/**
 * The availability cache.
 *
 * T28 measured the read path before touching it: `bookify_available_days()` walked 51 open days and
 * their slots in 8.58 ms over 4 queries, and `bookify_booking_form_html()` cost 13.03 ms in total,
 * on every page view of the booking form. That is the cost this file removes.
 *
 * **What is cached, and what deliberately is not.** Only the two answers that exist to be *shown* —
 * the day list and a day's slots. `bookify_slot_remaining()` is not cached and neither is anything
 * it reads, because the write path decides capacity with it (bookings.php, under the advisory lock);
 * a stale answer there is not a slow page, it is an overbooked class. The two can therefore differ
 * for as long as one cache lifetime, and the direction they differ in is the safe one: the form
 * could offer a slot the write path then refuses with `bookify_slot_full`, never the reverse.
 *
 * **Why a version rather than deleting keys.** Every cached value is keyed by a counter that any
 * write to any booking bumps, so a write cannot leave a stale answer behind even if the code that
 * wrote it has never heard of this file — including a future code path, a WP-CLI script, or an
 * operator's own `wp post meta` call. Deleting named keys would need every writer to remember the
 * right names, and forgetting one is silent. The cost is that a write invalidates more than it had
 * to, which on a business taking a handful of bookings a day is nothing. The transient's own expiry
 * is only hygiene: orphaned keys fall out of the options table by themselves.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The option the cache keys are built from.
 */
const BOOKIFY_BOOKING_AVAILABILITY_VERSION_OPTION = 'bookify_availability_version';

/**
 * How long one cached answer is reused, in seconds.
 *
 * This is a staleness ceiling, not just hygiene, because the answers depend on the clock as well as
 * on the data: bookify_opening_slots() drops slots that fall inside the lead time, so a slot can
 * stop being offered simply because time passed. Every cache key carries the bucket this number
 * divides the clock into, so an answer cannot outlive the moment it was true for by more than this
 * — and the same number is the transient's expiry, so nothing outlives its usefulness.
 *
 * Five minutes is the smallest window that is worth having: a page view that arrives inside it
 * reuses the previous answer, and one that arrives after it pays for a fresh one. Correctness does
 * not rest on this: bookings and settings invalidate through the version below, and the write path
 * never reads this cache at all.
 */
const BOOKIFY_BOOKING_AVAILABILITY_TTL = 300;

/**
 * The version every cache key is built from.
 *
 * @return int
 */
function bookify_booking_availability_version() {
	return max( 1, (int) get_option( BOOKIFY_BOOKING_AVAILABILITY_VERSION_OPTION, 1 ) );
}

/**
 * Make every cached availability answer stale.
 *
 * Bumped at most once per request, which is enough: the point is that a version read after the
 * first write cannot be the one a value was cached under, and every later write in the same request
 * is already covered by that. Measured before this guard: one booking wrote eleven meta keys and
 * therefore bumped the option eleven times. It is not deferred to shutdown, because a request that
 * books and then reads — a probe, a cron run, an operator's own script — has to see its own write.
 */
function bookify_booking_availability_invalidate() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	update_option( BOOKIFY_BOOKING_AVAILABILITY_VERSION_OPTION, bookify_booking_availability_version() + 1 );
}

/**
 * The slice of the clock the keys are built from.
 *
 * @return int
 */
function bookify_booking_availability_bucket() {
	return (int) floor( time() / BOOKIFY_BOOKING_AVAILABILITY_TTL );
}

/**
 * Read one availability answer through the cache.
 *
 * The expiry sweep runs first and outside the cache on purpose: an unpaid booking whose window has
 * closed must give its place back on the first availability question after that moment, and it must
 * do so whether or not the answer to that question happens to be cached.
 *
 * @param string   $key     What is being cached, unique to the question.
 * @param callable $compute Called when there is nothing cached.
 * @return mixed
 */
function bookify_booking_availability_cached( $key, $compute ) {
	bookify_booking_sweep_expired();

	$cache_key = 'bookify_avail_' . bookify_booking_availability_version() . '_' . bookify_booking_availability_bucket() . '_' . $key;
	$stored    = get_transient( $cache_key );

	// Wrapped, because an empty list is a real answer — a day whose every slot is taken — and
	// get_transient() cannot otherwise tell that from "nothing was stored".
	if ( is_array( $stored ) && array_key_exists( 'value', $stored ) ) {
		return $stored['value'];
	}

	$value = $compute();

	set_transient( $cache_key, array( 'value' => $value ), BOOKIFY_BOOKING_AVAILABILITY_TTL );

	return $value;
}

/**
 * Make the cache stale when a booking's meta changes.
 *
 * Every meta key, not only the four that decide a slot. A key that is missed here is a stale
 * capacity, and a stale capacity is an overbooking — so this errs towards invalidating too much,
 * and a booking write is rare enough that the difference does not matter. It also means a meta key
 * this plugin adds later, or a script writes by hand, cannot quietly bypass the invalidation.
 *
 * @param int    $meta_id    Meta row id, unused.
 * @param int    $object_id  The post the meta belongs to.
 * @param string $meta_key   The meta key, unused.
 * @param mixed  $meta_value The value, unused.
 */
function bookify_booking_availability_invalidate_on_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
	if ( 'bookify_booking' !== get_post_type( absint( $object_id ) ) ) {
		return;
	}

	bookify_booking_availability_invalidate();
}

/**
 * Make the cache stale when a booking is deleted.
 *
 * Deleting a booking gives its place back, and the meta hooks above do not fire for a post that is
 * being removed.
 *
 * @param int           $post_id The post being deleted.
 * @param \WP_Post|null $post    The post, when WordPress passes it along.
 */
function bookify_booking_availability_invalidate_on_delete( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && 'bookify_booking' !== $post->post_type ) {
		return;
	}

	bookify_booking_availability_invalidate();
}

/**
 * Register the cache, its invalidation and the option it reads.
 *
 * The option is created here rather than left to the first invalidation because a missing option
 * costs a query on every read — the one thing this file exists to avoid — and an autoloaded one
 * costs nothing.
 */
function bookify_booking_register_availability_cache() {
	if ( false === get_option( BOOKIFY_BOOKING_AVAILABILITY_VERSION_OPTION ) ) {
		add_option( BOOKIFY_BOOKING_AVAILABILITY_VERSION_OPTION, 1, '', 'yes' );
	}

	add_action( 'update_option_bookify_availability', 'bookify_booking_availability_invalidate' );

	foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
		add_action( $hook, 'bookify_booking_availability_invalidate_on_meta', 10, 4 );
	}

	add_action( 'deleted_post', 'bookify_booking_availability_invalidate_on_delete', 10, 2 );
}
