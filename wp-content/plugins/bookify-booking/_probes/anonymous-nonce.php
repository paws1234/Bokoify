<?php
/**
 * Throwaway: an anonymous form nonce, for posting the booking form the way a bot or a curl would.
 */

echo 'NONCE=' . wp_create_nonce( 'bookify_booking_submit' ) . "\n";
