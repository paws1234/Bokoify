<?php
/**
 * Throwaway: hold the slot lock for a few seconds, so two submissions fired at the same moment
 * have to queue behind it. Deleted after use.
 */

global $wpdb;

$taken = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', BOOKIFY_BOOKING_SLOT_LOCK ) );

echo 'holder took the lock: ' . var_export( $taken, true ) . ' at ' . microtime( true ) . "\n";

sleep( 4 );

$released = $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', BOOKIFY_BOOKING_SLOT_LOCK ) );

echo 'holder released: ' . var_export( $released, true ) . ' at ' . microtime( true ) . "\n";
