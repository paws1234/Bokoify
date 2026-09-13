<?php
/**
 * Throwaway probe for T28: what one diary read costs, cold.
 *
 * One `eval-file` run is one request, and WordPress's in-memory object cache absorbs a repeated
 * identical query within a request — measured: the second call to bookify_available_days() in the
 * same request costs 0 queries. So each path is measured in its own request, chosen by the
 * `bookify_probe_what` option:
 *
 *   wp option update bookify_probe_what slots && wp eval-file …/stage6-perf.php
 *   wp option update bookify_probe_what days  && wp eval-file …/stage6-perf.php
 *   wp option update bookify_probe_what form  && wp eval-file …/stage6-perf.php
 *   wp option update bookify_probe_what write && wp eval-file …/stage6-perf.php
 *
 * Kept as T28's evidence.
 */

global $wpdb;

$what       = (string) get_option( 'bookify_probe_what', 'slots' );
$service_id = 85;

// Deliberately not bookify_available_days(): calling it here would warm the object cache and the
// `days` case would then measure 0, which is what the first version of this probe got wrong.
$open = bookify_open_days();
$day  = $open ? $open[0] : current_time( 'Y-m-d' );

$before = $wpdb->num_queries;
$started = microtime( true );

switch ( $what ) {
	case 'days':
		$result = bookify_available_days( $service_id );
		$detail = count( $result ) . ' open days';
		break;

	case 'slots':
		$result = bookify_available_slots( $service_id, $day );
		$detail = count( $result ) . ' slots on ' . $day;
		break;

	case 'form':
		$result = bookify_booking_form_html();
		$detail = strlen( (string) $result ) . ' bytes of markup';
		break;

	case 'write':
		$result = bookify_slot_remaining( $service_id, $day, '10:00' );
		$detail = $result . ' places left at 10:00 on ' . $day;
		break;

	default:
		$result = array();
		$detail = 'unknown probe';
}

printf(
	"what=%-6s queries=%d  time=%.2f ms  %s\n",
	$what,
	$wpdb->num_queries - $before,
	( microtime( true ) - $started ) * 1000,
	$detail
);
