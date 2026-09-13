<?php
/**
 * When the business is open, and the slots the diary offers.
 *
 * One place answers "what can be booked": the settings screen stores the rules, the booking
 * form renders exactly what bookify_available_slots() returns, and the write path refuses
 * anything it would not return. That is the whole point of T16 — the offered times and the
 * accepted times are the same list, so they cannot drift apart.
 *
 * Days are numbered the way PHP numbers them: 0 is Sunday through 6 is Saturday. The screen
 * and the display helper both read bookify_booking_weekdays(), which prints them Monday first,
 * because that is the order a person reads a week in.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Weekday names, in the order they are shown, keyed the way the diary stores them.
 *
 * @return array<int,string>
 */
function bookify_booking_weekdays() {
	return array(
		1 => __( 'Monday', 'bookify-booking' ),
		2 => __( 'Tuesday', 'bookify-booking' ),
		3 => __( 'Wednesday', 'bookify-booking' ),
		4 => __( 'Thursday', 'bookify-booking' ),
		5 => __( 'Friday', 'bookify-booking' ),
		6 => __( 'Saturday', 'bookify-booking' ),
		0 => __( 'Sunday', 'bookify-booking' ),
	);
}

/**
 * The availability the site assumes before anything has been saved.
 *
 * The booking form generates its slots from these, so they are not a placeholder: a site whose
 * owner has never opened the screen still books Monday to Friday, 9 to 5, in half hours.
 *
 * @return array
 */
function bookify_availability_defaults() {
	$weekdays = array();

	foreach ( array_keys( bookify_booking_weekdays() ) as $day ) {
		// Sunday (0) closed; Saturday (6) a short day; Monday to Friday 9 to 5.
		$weekdays[ $day ] = array(
			'open' => 0 !== $day,
			'from' => 6 === $day ? '10:00' : '09:00',
			'to'   => 6 === $day ? '14:00' : '17:00',
		);
	}

	return array(
		'weekdays'      => $weekdays,
		'interval'      => 30,
		'lead_hours'    => 2,
		'move_hours'    => 2,
		'days_ahead'    => 60,
		'blocked_dates' => array(),
	);
}

/**
 * A time of day as minutes past midnight.
 *
 * @param string $time Time in HH:MM form. Callers check it first.
 * @return int
 */
function bookify_booking_minutes( $time ) {
	$parts = explode( ':', (string) $time );

	return ( (int) $parts[0] ) * 60 + (int) ( isset( $parts[1] ) ? $parts[1] : 0 );
}

/**
 * The diary's rules, with every stored value checked again on the way out.
 *
 * A value can be in the option without ever having gone through the sanitiser — `wp option
 * update` and an older version of this plugin are both ways in — so reading is defensive too.
 * A weekday whose stored hours are not times falls back to the default hours for that day
 * rather than being handed to the slot generator.
 *
 * @return array
 */
function bookify_availability() {
	$defaults = bookify_availability_defaults();
	$stored   = get_option( 'bookify_availability', array() );
	$stored   = is_array( $stored ) ? $stored : array();

	$weekdays = array();

	foreach ( $defaults['weekdays'] as $day => $default ) {
		// A weekday with nothing stored falls back to the default *row*: falling back to an
		// empty one would read as "closed", which is how a site that has never opened the
		// settings screen would end up with no bookable day at all.
		$row = isset( $stored['weekdays'][ $day ] ) && is_array( $stored['weekdays'][ $day ] )
			? $stored['weekdays'][ $day ]
			: $default;

		$weekdays[ $day ] = array(
			'open' => ! empty( $row['open'] ),
			'from' => isset( $row['from'] ) && bookify_booking_is_time( $row['from'] ) ? $row['from'] : $default['from'],
			'to'   => isset( $row['to'] ) && bookify_booking_is_time( $row['to'] ) ? $row['to'] : $default['to'],
		);
	}

	$blocked = array();

	foreach ( isset( $stored['blocked_dates'] ) && is_array( $stored['blocked_dates'] ) ? $stored['blocked_dates'] : array() as $date ) {
		if ( bookify_booking_is_date( $date ) ) {
			$blocked[] = $date;
		}
	}

	return array(
		'weekdays'      => $weekdays,
		'interval'      => isset( $stored['interval'] ) ? min( 240, max( 5, absint( $stored['interval'] ) ) ) : $defaults['interval'],
		'lead_hours'    => isset( $stored['lead_hours'] ) ? min( 720, absint( $stored['lead_hours'] ) ) : $defaults['lead_hours'],
		// How long before it starts a booking can no longer be moved by the customer (T21). Read
		// with the availability because that is where the business's rules already live.
		'move_hours'    => isset( $stored['move_hours'] ) ? min( 720, absint( $stored['move_hours'] ) ) : $defaults['move_hours'],
		'days_ahead'    => isset( $stored['days_ahead'] ) ? min( 365, max( 1, absint( $stored['days_ahead'] ) ) ) : $defaults['days_ahead'],
		'blocked_dates' => $blocked,
	);
}

/**
 * The hours the diary is open on a date, if it is open at all.
 *
 * The one definition of "open": a real date, not blocked, and its weekday is open. Everything
 * else in this file asks this question rather than reading the option again.
 *
 * @param string $date Date in YYYY-MM-DD form.
 * @return array{from:string,to:string}|null Hours, or null when the diary is shut that day.
 */
function bookify_opening_hours( $date ) {
	$date = (string) $date;

	if ( ! bookify_booking_is_date( $date ) ) {
		return null;
	}

	$settings = bookify_availability();

	if ( in_array( $date, $settings['blocked_dates'], true ) ) {
		return null;
	}

	// Noon, so a daylight-saving change at midnight cannot push this onto the neighbouring day.
	$day = (int) date_create_immutable( $date . ' 12:00:00', wp_timezone() )->format( 'w' );

	$hours = $settings['weekdays'][ $day ];

	if ( empty( $hours['open'] ) ) {
		return null;
	}

	// A range that ends before it starts has no slots in it; saying "closed" is clearer than
	// offering an empty day.
	if ( bookify_booking_minutes( $hours['to'] ) <= bookify_booking_minutes( $hours['from'] ) ) {
		return null;
	}

	return array(
		'from' => $hours['from'],
		'to'   => $hours['to'],
	);
}

/**
 * Whether the diary is open on a date.
 *
 * @param string $date Date in YYYY-MM-DD form.
 * @return bool
 */
function bookify_is_open_on( $date ) {
	return null !== bookify_opening_hours( $date );
}

/**
 * The soonest a booking may start: now, plus the lead time.
 *
 * The comparison is made in the site's own timezone (wp_timezone(), which follows the site's
 * timezone setting), so "two hours from now" means two hours on the clock the business reads.
 *
 * @return \DateTimeImmutable
 */
function bookify_booking_lead_cutoff() {
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	$lead = bookify_availability()['lead_hours'];

	return $lead > 0 ? $now->modify( '+' . $lead . ' hours' ) : $now;
}

/**
 * The times of day a slot may start, before anything booked is taken into account.
 *
 * Steps the day's opening hours by the interval, and keeps a slot only when the service's own
 * length fits before closing time and the slot does not start inside the lead time.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @return array<int,string> Times in HH:MM, in order.
 */
function bookify_opening_slots( $service_id, $date ) {
	$hours   = bookify_opening_hours( $date );
	$service = bookify_booking_bookable_service( $service_id );

	if ( null === $hours || null === $service ) {
		return array();
	}

	$settings = bookify_availability();
	$length   = max( 0, (int) get_post_meta( $service->ID, 'bookify_duration', true ) );
	$opens    = bookify_booking_minutes( $hours['from'] );
	$closes   = bookify_booking_minutes( $hours['to'] );
	$cutoff   = bookify_booking_lead_cutoff();

	$slots = array();

	for ( $minute = $opens; $minute + $length <= $closes; $minute += $settings['interval'] ) {
		$time = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );

		if ( date_create_immutable( $date . ' ' . $time, wp_timezone() ) < $cutoff ) {
			continue;
		}

		$slots[] = $time;
	}

	return $slots;
}

/**
 * How many guests one slot of a service can take.
 *
 * A service with no capacity set takes one guest, which is what an appointment means.
 *
 * @param int $service_id The service.
 * @return int
 */
function bookify_slot_capacity( $service_id ) {
	return max( 1, (int) get_post_meta( absint( $service_id ), 'bookify_capacity', true ) );
}

/**
 * The guests already booked, day by day and time by time.
 *
 * Several statuses give their place back — cancelled since T17, expired since T23 — and one status
 * that sounds like it should not still holds one: `awaiting_payment`, because the customer who is
 * paying for a slot has the slot. The list itself is bookify_booking_slot_holding_statuses(), so
 * the capacity count and the manage page cannot disagree about what "using a place" means.
 *
 * The first thing this does is expire whatever is overdue, which is T23's second half of the belt
 * and braces: an availability question is exactly the moment a stale unpaid booking starts to
 * matter, so answering one is the right time to clean up. It stays a read in every way that counts —
 * the only bookings it touches are ones whose window has already closed, and all it does to them is
 * set the status that gives their place back and drop the expiry appointment that is now moot.
 *
 * @param int    $service_id The service.
 * @param string $from       Earliest date, YYYY-MM-DD.
 * @param string $to         Latest date, YYYY-MM-DD.
 * @return array<string,array<string,int>> date => time => guests.
 */
function bookify_booking_guests_by_day( $service_id, $from, $to ) {
	bookify_booking_sweep_expired();

	$bookings = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_service_id',
					'value' => absint( $service_id ),
				),
				array(
					'key'     => 'bookify_date',
					'value'   => array( (string) $from, (string) $to ),
					'compare' => 'BETWEEN',
				),
				array(
					'key'     => 'bookify_status',
					'value'   => bookify_booking_slot_holding_statuses(),
					'compare' => 'IN',
				),
			),
		)
	);

	$days = array();

	foreach ( $bookings as $booking_id ) {
		$date  = (string) get_post_meta( $booking_id, 'bookify_date', true );
		$time  = (string) get_post_meta( $booking_id, 'bookify_time', true );
		$party = (int) get_post_meta( $booking_id, 'bookify_party_size', true );

		if ( '' === $date || '' === $time ) {
			continue;
		}

		$days[ $date ][ $time ] = ( isset( $days[ $date ][ $time ] ) ? $days[ $date ][ $time ] : 0 ) + max( 1, $party );
	}

	return $days;
}

/**
 * The guests already booked into one service's slot.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @param string $time       Time in HH:MM form.
 * @return int
 */
function bookify_slot_booked_guests( $service_id, $date, $time ) {
	$days = bookify_booking_guests_by_day( $service_id, $date, $date );

	return isset( $days[ $date ][ $time ] ) ? (int) $days[ $date ][ $time ] : 0;
}

/**
 * The places still free in one slot.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @param string $time       Time in HH:MM form.
 * @return int
 */
function bookify_slot_remaining( $service_id, $date, $time ) {
	return max( 0, bookify_slot_capacity( $service_id ) - bookify_slot_booked_guests( $service_id, $date, $time ) );
}

/**
 * Whether the diary generates this slot at all, bookings aside.
 *
 * The distinction the write path needs: a time that is not on offer (too late in the day, inside
 * the lead time, a closed day) and a time that is on offer but full are two different refusals.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @param string $time       Time in HH:MM form.
 * @return bool
 */
function bookify_slot_is_offered( $service_id, $date, $time ) {
	return in_array( (string) $time, bookify_opening_slots( $service_id, $date ), true );
}

/**
 * The slots a service can actually be booked into on a date.
 *
 * This is the list the form renders and the REST route answers. It is cached since T28, because it
 * is asked for on every page view of a form and its answer only changes when a booking or a setting
 * does — see includes/availability-cache.php, including why the write path deliberately does not
 * read it: bookify_slot_remaining() and bookify_slot_is_offered() stay live, so a stale cache can
 * offer a slot and be refused, never accept one.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @return array<int,string> Times in HH:MM, in order.
 */
function bookify_available_slots( $service_id, $date ) {
	$service_id = absint( $service_id );
	$date       = (string) $date;

	return bookify_booking_availability_cached(
		'slots_' . $service_id . '_' . $date,
		static function () use ( $service_id, $date ) {
			$offered  = bookify_opening_slots( $service_id, $date );
			$capacity = bookify_slot_capacity( $service_id );
			$booked   = bookify_booking_guests_by_day( $service_id, $date, $date );
			$taken    = isset( $booked[ $date ] ) ? $booked[ $date ] : array();

			$slots = array();

			foreach ( $offered as $time ) {
				if ( ! isset( $taken[ $time ] ) || $taken[ $time ] < $capacity ) {
					$slots[] = $time;
				}
			}

			return $slots;
		}
	);
}

/**
 * The dates the diary is open within the window the settings allow.
 *
 * @return array<int,string> Dates in YYYY-MM-DD, earliest first.
 */
function bookify_open_days() {
	$settings = bookify_availability();

	// Midday, so no offset or daylight-saving change can move the day being counted.
	$today = date_create_immutable( 'now', wp_timezone() )->setTime( 12, 0 );

	$days = array();

	for ( $offset = 0; $offset < $settings['days_ahead']; $offset++ ) {
		$date = $today->modify( '+' . $offset . ' day' )->format( 'Y-m-d' );

		if ( bookify_is_open_on( $date ) ) {
			$days[] = $date;
		}
	}

	return $days;
}

/**
 * The open days a service can still be booked on, so the picker never offers an empty day.
 *
 * One query for the whole window rather than one per day: the bookings are read as a range and
 * the slots are then computed day by day in memory. Cached since T28 — this is the expensive one,
 * and T28 measured it at 8.58 ms and 4 queries before the cache. It is shown, never enforced:
 * nothing on the write path reads it.
 *
 * @param int $service_id The service.
 * @return array<int,string> Dates in YYYY-MM-DD, earliest first.
 */
function bookify_available_days( $service_id ) {
	$service_id = absint( $service_id );

	return bookify_booking_availability_cached(
		'days_' . $service_id,
		static function () use ( $service_id ) {
			$days = bookify_open_days();

			if ( ! $days ) {
				return array();
			}

			$capacity = bookify_slot_capacity( $service_id );
			$booked   = bookify_booking_guests_by_day( $service_id, $days[0], $days[ count( $days ) - 1 ] );

			$available = array();

			foreach ( $days as $date ) {
				$taken = isset( $booked[ $date ] ) ? $booked[ $date ] : array();

				foreach ( bookify_opening_slots( $service_id, $date ) as $time ) {
					if ( ! isset( $taken[ $time ] ) || $taken[ $time ] < $capacity ) {
						$available[] = $date;

						break;
					}
				}
			}

			return $available;
		}
	);
}

/**
 * The opening hours as markup, grouped so the footer is not seven lines long.
 *
 * Printed from the option on both the footer and the Contact page, so the hours a visitor reads
 * and the hours the form books against cannot be two different things (T15, acceptance 4).
 * Times are shown exactly as the screen holds them — no reformatting, so what the owner typed
 * is what a visitor sees, and a test can compare the two strings.
 *
 * @return string
 */
function bookify_booking_opening_hours_html() {
	$settings = bookify_availability();
	$names    = bookify_booking_weekdays();

	$groups = array();

	foreach ( $names as $day => $name ) {
		$hours = $settings['weekdays'][ $day ];
		$open  = ! empty( $hours['open'] ) && bookify_booking_minutes( $hours['to'] ) > bookify_booking_minutes( $hours['from'] );

		$text = $open
			? $hours['from'] . '–' . $hours['to']
			: __( 'Closed', 'bookify-booking' );

		$last = $groups ? count( $groups ) - 1 : -1;

		if ( $last >= 0 && $groups[ $last ]['text'] === $text ) {
			// Same hours as the day before: extend that run rather than repeating it.
			$groups[ $last ]['to'] = $name;
			continue;
		}

		$groups[] = array(
			'from' => $name,
			'to'   => $name,
			'text' => $text,
		);
	}

	$lines = array();

	foreach ( $groups as $group ) {
		/* translators: 1: first weekday, 2: last weekday. Only used when they differ. */
		$label = $group['from'] === $group['to']
			? $group['from']
			: sprintf( __( '%1$s to %2$s', 'bookify-booking' ), $group['from'], $group['to'] );

		$lines[] = '<span class="bookify-availability__line"><span class="bookify-availability__days">' . esc_html( $label ) . '</span> ' . esc_html( $group['text'] ) . '</span>';
	}

	return '<p class="bookify-availability"><span class="bookify-availability__title">' . esc_html__( 'Opening hours', 'bookify-booking' ) . '</span>' . implode( '', $lines ) . '</p>';
}
