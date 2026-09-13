<?php
/**
 * The Supabase row shapes.
 *
 * Pure mapping and nothing else: a post id goes in, one associative array shaped like a Postgres
 * row comes out. No HTTP, no options, no hooks — which is what makes this the one half of the
 * mirror that can be proved without a Supabase project to talk to (_probes/supabase-sync.php does
 * exactly that, and it is why the mapping lives in a file of its own).
 *
 * **This is a transformation, not a dump.** WordPress keeps a booking's twenty-odd facts as
 * twenty-odd rows of `wp_postmeta`, all `longtext`, with no types, no foreign keys and no way to
 * ask "which bookings are on Friday?" without a join per fact. What is published here is the same
 * information as *columns*: a real `date`, a real `time`, a `numeric(10,2)` amount, an integer
 * party size, and ids that are actual foreign keys. The point of mirroring is to be able to query
 * the data somewhere else; a copy of `wp_postmeta` would be queryable exactly as badly as
 * `wp_postmeta` is.
 *
 * **Nothing here writes.** A row is built from what is stored at the moment it is asked for, and
 * the caller decides whether to send it.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The post statuses the mirror copies.
 *
 * Every status a post type can hold in normal use, `trash` included. A trashed booking keeps its
 * row with `status = 'trash'` rather than losing it: the mirror is a record of what this site
 * holds, and an operator who trashed a booking by mistake has not thereby decided that Supabase
 * should forget it existed. `auto-draft` is left out — it is a post id WordPress reserved and never
 * filled in, so there is nothing to publish.
 *
 * Not `'any'`: WP_Query's `any` silently drops the statuses marked `exclude_from_search`, which
 * includes `trash`, so the list has to be written out.
 *
 * @return string[]
 */
function bookify_booking_supabase_statuses() {
	return array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
}

/**
 * A GMT datetime from `wp_posts` as an ISO 8601 instant, or null.
 *
 * WordPress stores these as `YYYY-MM-DD HH:MM:SS` in UTC and uses `0000-00-00 00:00:00` for "never",
 * which is neither a valid Postgres timestamp nor a real instant. It becomes null, the same as an
 * unset value: the column is `timestamptz` and an empty string is an error rather than an absence.
 *
 * @param string $gmt Value as stored in post_date_gmt or post_modified_gmt.
 * @return string|null
 */
function bookify_booking_supabase_timestamp( $gmt ) {
	$gmt = trim( (string) $gmt );

	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return null;
	}

	// Constructed in UTC explicitly. The value is already UTC, so letting PHP read it against the
	// site's timezone would move it by the offset and store a correct-looking, wrong instant.
	$when = date_create_immutable( $gmt, new DateTimeZone( 'UTC' ) );

	return $when instanceof DateTimeImmutable ? $when->format( 'c' ) : null;
}

/**
 * A Unix timestamp as an ISO 8601 instant, or null.
 *
 * The plugin stores deadlines as Unix timestamps on purpose (T20's link expiry, T25's payment
 * window), so that they are compared with `time()` and have no timezone to get wrong. Converting at
 * the edge keeps that property in WordPress and gives Postgres a real `timestamptz`.
 *
 * @param int $stamp Unix timestamp.
 * @return string|null
 */
function bookify_booking_supabase_instant( $stamp ) {
	$stamp = absint( $stamp );

	return $stamp > 0 ? gmdate( 'c', $stamp ) : null;
}

/**
 * A stored date as a Postgres `date`, or null.
 *
 * Re-validated with the plugin's own test rather than trusted. The column really is a `date`, so
 * `2026-02-31` is a hard error on insert rather than a wrong answer, and one malformed value would
 * reject the whole batch it travelled in.
 *
 * @param string $ymd Candidate date.
 * @return string|null
 */
function bookify_booking_supabase_date( $ymd ) {
	$ymd = trim( (string) $ymd );

	return bookify_booking_is_date( $ymd ) ? $ymd : null;
}

/**
 * A stored time as a Postgres `time`, or null.
 *
 * @param string $time Candidate HH:MM.
 * @return string|null
 */
function bookify_booking_supabase_time( $time ) {
	$time = trim( (string) $time );

	return bookify_booking_is_time( $time ) ? $time : null;
}

/**
 * A money value as a string Postgres will read as `numeric(10,2)`, or null.
 *
 * A string and not a float, deliberately. A JSON number is an IEEE double, and `0.1 + 0.2` is not
 * `0.3` in one; Postgres will accept `44.1` as a float and store something a hair off it. Formatted
 * to a fixed two places it arrives as an exact decimal, which is what the column and the currency
 * both mean.
 *
 * An empty value is null rather than zero: a booking that has not reached the payment step has no
 * amount, and "no amount" is a different fact from "an amount of nothing".
 *
 * @param mixed $value Raw stored value.
 * @return string|null
 */
function bookify_booking_supabase_amount( $value ) {
	$raw = is_string( $value ) ? trim( $value ) : ( null === $value ? '' : (string) $value );

	if ( '' === $raw ) {
		return null;
	}

	return number_format( (float) $raw, 2, '.', '' );
}

/**
 * An integer, or null when nothing is stored.
 *
 * Zero survives: an event's capacity of 0 is a real answer meaning "no ceiling of its own", so it
 * must not be flattened into the same value as an absent one.
 *
 * @param mixed $value Raw stored value.
 * @return int|null
 */
function bookify_booking_supabase_integer( $value ) {
	$raw = is_string( $value ) ? trim( $value ) : ( null === $value ? '' : (string) $value );

	return '' === $raw ? null : (int) $value;
}

/**
 * A string, or null when nothing is stored.
 *
 * Postgres `text` treats `''` and null as different values, and a report that groups by a column
 * should not have to know that WordPress sometimes means one with the other.
 *
 * @param mixed $value Raw stored value.
 * @return string|null
 */
function bookify_booking_supabase_text( $value ) {
	$raw = is_string( $value ) ? trim( $value ) : ( null === $value ? '' : (string) $value );

	return '' === $raw ? null : $raw;
}

/**
 * A post id, but only while the post it names still exists and is of the expected type.
 *
 * A booking holds its service in `bookify_service_id`, and WordPress does not clean that up when the
 * service is deleted — the write path treats a dangling id as "not bookable", which is right for
 * display but wrong for a foreign key. Publishing null instead keeps the row insertable and says
 * honestly that the thing it pointed at is gone. The probes hard-delete their bookings and their
 * services routinely, so this is the normal case, not a corner.
 *
 * @param mixed  $id        Raw stored id.
 * @param string $post_type The type that id is supposed to name.
 * @return int|null
 */
function bookify_booking_supabase_relation( $id, $post_type ) {
	$id = absint( $id );

	if ( ! $id ) {
		return null;
	}

	$post = get_post( $id );

	if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
		return null;
	}

	return $id;
}

/**
 * A post's featured image, as an attachment id or null.
 *
 * The same shape as `_relation()` and for much the same reason — a thumbnail can be deleted without
 * the post that named it being touched — but the id comes from WordPress's own `_thumbnail_id` meta
 * rather than from one of the plugin's, so it is read directly. Unlike `_relation()` the answer is
 * not a foreign key: the id is published whether or not the row it names exists, because a photo too
 * large to carry is still the photo the session uses.
 *
 * @param int $post_id The post whose featured image is wanted.
 * @return int|null
 */
function bookify_booking_supabase_thumbnail( $post_id ) {
	$thumbnail = absint( get_post_meta( absint( $post_id ), '_thumbnail_id', true ) );

	if ( ! $thumbnail ) {
		return null;
	}

	$attachment = get_post( $thumbnail );

	return $attachment instanceof WP_Post && 'attachment' === $attachment->post_type ? $thumbnail : null;
}

/**
 * The row for one session.
 *
 * The price, the duration and the capacity are read from their own meta; the payment terms are read
 * through `includes/payments/amounts.php`, which re-validates each one, so a value written by
 * `wp post meta` cannot reach Supabase in a shape the plugin would not itself have accepted.
 *
 * @param int $service_id The session.
 * @return array|null Null when this id is not a session.
 */
function bookify_booking_supabase_service_row( $service_id ) {
	$service = get_post( absint( $service_id ) );

	if ( ! $service instanceof WP_Post || 'bookify_service' !== $service->post_type ) {
		return null;
	}

	return array(
		'id'               => (int) $service->ID,
		'slug'             => (string) $service->post_name,
		'title'            => (string) $service->post_title,
		'excerpt'          => (string) $service->post_excerpt,
		'description_html' => (string) $service->post_content,
		'status'           => (string) $service->post_status,
		'price'            => bookify_booking_supabase_amount( get_post_meta( $service->ID, 'bookify_price', true ) ),
		'duration_minutes' => bookify_booking_supabase_integer( get_post_meta( $service->ID, 'bookify_duration', true ) ),
		'capacity'         => bookify_booking_supabase_integer( get_post_meta( $service->ID, 'bookify_capacity', true ) ),
		'payment_mode'     => bookify_booking_service_payment_mode( $service->ID ),
		'deposit_type'     => bookify_booking_service_deposit_type( $service->ID ),
		'deposit_value'    => bookify_booking_supabase_amount( bookify_booking_service_deposit_value( $service->ID ) ),
		'permalink'        => (string) get_permalink( $service ),
		'thumbnail_id'     => bookify_booking_supabase_thumbnail( $service->ID ),
		'created_at'       => bookify_booking_supabase_timestamp( $service->post_date_gmt ),
		'updated_at'       => bookify_booking_supabase_timestamp( $service->post_modified_gmt ),
	);
}

/**
 * The row for one event.
 *
 * A single date, because that is what a `bookify_event` is (plan D22): `bookify_event_date`,
 * `bookify_event_start`, an optional end and an optional capacity. The date is nullable here for the
 * same reason it is optional in the editor — an event the owner has not dated yet is a draft, and a
 * draft is still a row.
 *
 * @param int $event_id The event.
 * @return array|null Null when this id is not an event.
 */
function bookify_booking_supabase_event_row( $event_id ) {
	$event = get_post( absint( $event_id ) );

	if ( ! $event instanceof WP_Post || 'bookify_event' !== $event->post_type ) {
		return null;
	}

	return array(
		'id'               => (int) $event->ID,
		'slug'             => (string) $event->post_name,
		'title'            => (string) $event->post_title,
		'description_html' => (string) $event->post_content,
		'status'           => (string) $event->post_status,
		'event_date'       => bookify_booking_supabase_date( bookify_event_date( $event->ID ) ),
		'starts_at'        => bookify_booking_supabase_time( bookify_event_start( $event->ID ) ),
		'ends_at'          => bookify_booking_supabase_time( bookify_event_end( $event->ID ) ),
		'capacity'         => bookify_event_capacity( $event->ID ),
		'permalink'        => (string) get_permalink( $event ),
		'thumbnail_id'     => bookify_booking_supabase_thumbnail( $event->ID ),
		'created_at'       => bookify_booking_supabase_timestamp( $event->post_date_gmt ),
		'updated_at'       => bookify_booking_supabase_timestamp( $event->post_modified_gmt ),
	);
}

/**
 * The row for one ticket tier.
 *
 * Null when the tier's event is missing rather than absent from the row: `event_id` is a foreign key
 * and a tier cannot be published without the event it belongs to. A tier whose event has gone is not
 * something a reader of this data can act on, so it is left out until the event is back — the
 * backfill runs in dependency order for the same reason.
 *
 * @param int $tier_id The tier.
 * @return array|null Null when this id is not a tier, or its event has gone.
 */
function bookify_booking_supabase_tier_row( $tier_id ) {
	$tier = get_post( absint( $tier_id ) );

	if ( ! $tier instanceof WP_Post || 'bookify_tier' !== $tier->post_type ) {
		return null;
	}

	$event_id = bookify_booking_supabase_relation( bookify_tier_event_id( $tier->ID ), 'bookify_event' );

	if ( null === $event_id ) {
		return null;
	}

	return array(
		'id'         => (int) $tier->ID,
		'event_id'   => $event_id,
		'title'      => (string) $tier->post_title,
		'status'     => (string) $tier->post_status,
		'price'      => bookify_booking_supabase_amount( bookify_tier_price( $tier->ID ) ),
		'capacity'   => bookify_tier_capacity( $tier->ID ),
		'created_at' => bookify_booking_supabase_timestamp( $tier->post_date_gmt ),
		'updated_at' => bookify_booking_supabase_timestamp( $tier->post_modified_gmt ),
	);
}

/**
 * What a booking is for, as one line, from the posts themselves.
 *
 * Deliberately *not* `bookify_booking_item_label()`, which is the one place the site names a
 * booking's "what". That function returns an empty string for a session that is no longer published,
 * because it exists to put a sentence in front of a customer and a withdrawn session has no name to
 * give them (T7). A mirror has the opposite duty: it must not silently lose the name of the thing a
 * booking was made against, or nobody can ever work out what the row refers to. The two ids remain
 * the authoritative answer either way; this column is for reading.
 *
 * @param int|null $event_id   Event id, when this is a ticket.
 * @param int|null $tier_id    Tier id, when a tier was chosen.
 * @param int|null $service_id Session id, when this is a session booking.
 * @return string
 */
function bookify_booking_supabase_item_label( $event_id, $tier_id, $service_id ) {
	if ( null !== $event_id ) {
		$event = get_post( $event_id );
		$tier  = null !== $tier_id ? get_post( $tier_id ) : null;

		$label = $event instanceof WP_Post ? (string) $event->post_title : '';

		if ( $tier instanceof WP_Post ) {
			$label = $label . ' — ' . $tier->post_title;
		}

		return trim( $label );
	}

	if ( null !== $service_id ) {
		$service = get_post( $service_id );

		return $service instanceof WP_Post ? (string) $service->post_title : '';
	}

	return '';
}

/**
 * The row for one booking.
 *
 * **Three keys are deliberately not published.** `bookify_cancel_token`, `bookify_manage_token` and
 * `bookify_manage_expires` are the only things standing between a stranger and somebody else's
 * booking: the reference is a lookup key, and the token is the secret that proves the link belongs
 * to the booking it names (T20). Supabase is a third party holding a copy; a leaked mirror would
 * hand out working cancel and manage links, so the secrets stay in WordPress and only the record of
 * the booking travels. Nothing in this file may be relaxed to "make the copy complete" without
 * re-reading this paragraph.
 *
 * The payment reference *is* published: it is a Stripe object id, kept so an operator can find the
 * payment, and it cannot be used to charge anything.
 *
 * @param int $booking_id The booking.
 * @return array|null Null when this id is not a booking.
 */
function bookify_booking_supabase_booking_row( $booking_id ) {
	$booking = get_post( absint( $booking_id ) );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return null;
	}

	$id = $booking->ID;

	// The event is read twice on purpose: once raw, to answer "which product is this?", and once
	// resolved, to publish a foreign key. They are different questions, and a booking whose event has
	// been deleted still has to be a ticket — resolving first would quietly relabel it a session.
	$is_ticket = absint( get_post_meta( $id, 'bookify_event_id', true ) ) > 0;

	$service_id = bookify_booking_supabase_relation( get_post_meta( $id, 'bookify_service_id', true ), 'bookify_service' );
	$event_id   = bookify_booking_supabase_relation( get_post_meta( $id, 'bookify_event_id', true ), 'bookify_event' );
	$tier_id    = bookify_booking_supabase_relation( get_post_meta( $id, 'bookify_tier_id', true ), 'bookify_tier' );

	return array(
		'id'                => (int) $id,
		'reference'         => (string) get_post_meta( $id, 'bookify_reference', true ),
		'status'            => (string) get_post_meta( $id, 'bookify_status', true ),
		/*
		 * A stable slug, not `bookify_booking_item_kind()`. That function answers with a *translated*
		 * word ('Session' / 'Event'), and a translation is not a fact about the booking — it would
		 * make the column's value depend on the site's language, so a report written in English would
		 * stop matching rows after somebody switched the admin to French. Derived from the meta that
		 * is actually set, which is also what the write path decides with.
		 */
		'item_kind'         => $is_ticket ? 'ticket' : 'session',
		'item_label'        => bookify_booking_supabase_item_label( $event_id, $tier_id, $service_id ),
		'service_id'        => $service_id,
		'event_id'          => $event_id,
		'tier_id'           => $tier_id,
		'customer_name'     => (string) get_post_meta( $id, 'bookify_customer_name', true ),
		'customer_email'    => (string) get_post_meta( $id, 'bookify_customer_email', true ),
		'customer_phone'    => bookify_booking_supabase_text( get_post_meta( $id, 'bookify_customer_phone', true ) ),
		'customer_user_id'  => bookify_booking_supabase_integer( get_post_meta( $id, 'bookify_customer_user', true ) ),
		'booking_date'      => bookify_booking_supabase_date( get_post_meta( $id, 'bookify_date', true ) ),
		'booking_time'      => bookify_booking_supabase_time( get_post_meta( $id, 'bookify_time', true ) ),
		'party_size'        => bookify_booking_supabase_integer( get_post_meta( $id, 'bookify_party_size', true ) ),
		// Read through the payments file so '' — "no payment is involved" — becomes null rather than a
		// value that looks like a state somebody chose.
		'payment_status'    => bookify_booking_supabase_text( bookify_booking_payment_status( $id ) ),
		'payment_amount'    => bookify_booking_supabase_amount( get_post_meta( $id, 'bookify_payment_amount', true ) ),
		'payment_due_at'    => bookify_booking_supabase_instant( bookify_booking_payment_due_at( $id ) ),
		'payment_reference' => bookify_booking_supabase_text( get_post_meta( $id, 'bookify_payment_reference', true ) ),
		'payment_note'      => bookify_booking_supabase_text( get_post_meta( $id, 'bookify_payment_note', true ) ),
		'previous_slot'     => bookify_booking_supabase_text( get_post_meta( $id, 'bookify_previous_slot', true ) ),
		'created_at'        => bookify_booking_supabase_timestamp( $booking->post_date_gmt ),
		'updated_at'        => bookify_booking_supabase_timestamp( $booking->post_modified_gmt ),
	);
}

/**
 * A customer's WordPress account, as one row.
 *
 * **Every column here is named on purpose.** `wp_users` also holds `user_pass` — a password hash —
 * and `wp_usermeta` holds `session_tokens`, which are live logins: either would be a credential
 * leaving the site. A `SELECT *`, or a row built by looping the user object, would carry both to a
 * third party. So the row is written out field by field, and the fields that are not listed are the
 * point of the function as much as the fields that are.
 *
 * `registered_at` needs converting, unlike the post timestamps above. `wp_users.user_registered` is
 * stored in the *site's* local time — a WordPress quirk, and the opposite of `post_date_gmt` — so
 * treating it as UTC would store an instant that is wrong by the site's offset. `get_gmt_from_date()`
 * puts it right first.
 *
 * There is no `updated_at`: WordPress keeps no modified date for a user, and inventing one from the
 * registration date would be a lie about the data.
 *
 * @param int $user_id The WordPress user.
 * @return array|null Null when there is no such user.
 */
function bookify_booking_supabase_customer_row( $user_id ) {
	$user = get_userdata( absint( $user_id ) );

	if ( ! $user instanceof WP_User ) {
		return null;
	}

	return array(
		'id'            => (int) $user->ID,
		'user_login'    => (string) $user->user_login,
		'display_name'  => (string) $user->display_name,
		'email'         => (string) $user->user_email,
		'roles'         => implode( ',', array_map( 'sanitize_key', (array) $user->roles ) ),
		'registered_at' => bookify_booking_supabase_timestamp( get_gmt_from_date( (string) $user->user_registered ) ),
	);
}

/**
 * The site's configuration, as the single row of `bookify_settings`.
 *
 * The four booking options are the only place most of this is stored, and until now none of them
 * existed outside MySQL — so "how is this business actually configured?" was answerable only from the
 * admin. All four are read through the plugin's own accessors, which re-validate every value on the
 * way out, so a row written by `wp option update` cannot reach Supabase in a shape the plugin would
 * not itself have accepted.
 *
 * **Three secrets live in these options and none of them travel.** `bookify_payments` holds the
 * Stripe secret key and the webhook signing secret, and `bookify_bots` holds the Turnstile secret.
 * Only whether each is set is published, as a boolean: enough to answer "is checkout configured?"
 * from a dashboard, and not enough to charge anything or forge a webhook. The Turnstile *site* key is
 * skipped too — it is public, printed into the page, but it answers no question.
 *
 * `snapshot_at` is named that on purpose. This row is a picture of the configuration as at the moment
 * it was built, not a record of when anything was last changed; calling it `updated_at` would invite
 * exactly the wrong reading.
 *
 * @param int $id Unused. The row is a singleton, and the signature matches the other row builders.
 * @return array
 */
function bookify_booking_supabase_settings_row( $id = 1 ) {
	unset( $id );

	$business     = bookify_booking_business();
	$availability = bookify_availability();
	$reminders    = bookify_booking_reminders();
	$payments     = bookify_booking_payments();
	$bots         = bookify_bots();
	$mail         = bookify_booking_mail();

	return array(
		'id'                     => 1,
		'site_name'              => (string) get_option( 'blogname' ),
		'site_url'               => home_url( '/' ),
		'timezone'               => wp_timezone_string(),
		'business_name'          => (string) $business['name'],
		'business_street'        => (string) $business['street'],
		'business_locality'      => (string) $business['locality'],
		'business_postcode'      => (string) $business['postcode'],
		'business_country'       => (string) $business['country'],
		'business_phone'         => (string) $business['phone'],
		'business_email'         => (string) $business['email'],
		// A map of weekday number to {open, from, to}, which is why it is jsonb rather than six sets of
		// columns: nothing queries an individual weekday's opening time, they are read together.
		'weekdays'               => $availability['weekdays'],
		'slot_interval_minutes'  => (int) $availability['interval'],
		'lead_hours'             => (int) $availability['lead_hours'],
		'move_hours'             => (int) $availability['move_hours'],
		'days_ahead'             => (int) $availability['days_ahead'],
		'blocked_dates'          => array_values( (array) $availability['blocked_dates'] ),
		'reminders_enabled'      => (bool) $reminders['enabled'],
		'reminder_hours_before'  => (int) $reminders['hours_before'],
		'payment_currency'       => bookify_booking_supabase_text( $payments['currency'] ),
		'payment_expiry_minutes' => (int) $payments['expiry_minutes'],
		// Booleans, never the keys themselves — see the docblock.
		'stripe_configured'      => '' !== $payments['secret_key'],
		'turnstile_configured'   => '' !== $bots['secret_key'] && '' !== $bots['site_key'],
		'mail_transport'         => '' !== $mail['api_key'] && '' !== $mail['from_email'] ? 'resend' : 'wordpress',
		'mail_from'              => bookify_booking_supabase_mail_from(),
		'snapshot_at'            => gmdate( 'c' ),
	);
}

/**
 * The sender line a customer would see, or null.
 *
 * `bookify_booking_mail_from()` falls back to the site title and the admin address when nothing is
 * configured, which is a perfectly good answer to "what will a customer see" and a slightly odd one
 * to store as configuration. So the fallback is used here exactly as the mailer uses it — the point
 * is that the two cannot disagree — and the address is *not* a secret: no key is involved.
 *
 * @return string|null
 */
function bookify_booking_supabase_mail_from() {
	return bookify_booking_supabase_text( bookify_booking_mail_from() );
}
