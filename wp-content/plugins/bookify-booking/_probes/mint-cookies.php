<?php
/**
 * Throwaway: mint admin and subscriber cookies + nonces for the settings screen. Deleted after use.
 */

function probe_report( $id ) {
	$exp   = time() + 3600;
	$token = WP_Session_Tokens::get_instance( $id )->create( $exp );

	$user = get_userdata( $id );

	// WP-CLI runs without cookies; wp_create_nonce() reads the logged-in cookie to salt the
	// nonce, so the cookies are put where the function will look before it is called.
	$auth     = wp_generate_auth_cookie( $id, $exp, 'auth', $token );
	$loggedin = wp_generate_auth_cookie( $id, $exp, 'logged_in', $token );

	$_COOKIE[ AUTH_COOKIE ]        = $auth;
	$_COOKIE[ SECURE_AUTH_COOKIE ] = $auth;
	$_COOKIE[ LOGGED_IN_COOKIE ]   = $loggedin;

	wp_set_current_user( $id );

	$nonce = wp_create_nonce( 'bookify_settings-options' );
	$can   = current_user_can( 'manage_options' );

	// Session-salted, like the settings nonce: these have to be minted in the same session as
	// the cookie above or wp_verify_nonce() refuses them.
	$edit   = wp_create_nonce( 'update-post_' . $GLOBALS['probe_booking_id'] );
	$status = wp_create_nonce( 'bookify_booking_status' );

	unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
	wp_set_current_user( 0 );

	printf( "%s: can_manage_options=%s nonce=%s\n", $user->user_login, $can ? 'yes' : 'no', $nonce );

	return array(
		'cookie' => sprintf(
			'%s=%s; %s=%s; wordpress_test_cookie=WP%%20Cookie%%20check',
			AUTH_COOKIE,
			$auth,
			LOGGED_IN_COOKIE,
			$loggedin
		),
		'nonce'  => $nonce,
		'edit'   => $edit,
		'status' => $status,
	);
}

$GLOBALS['probe_booking_id'] = 160;

$subscriber = get_user_by( 'login', 'bookify-probe-subscriber' );

if ( ! $subscriber ) {
	$subscriber_id = wp_insert_user(
		array(
			'user_login' => 'bookify-probe-subscriber',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'bookify-probe-subscriber@example.com',
			'role'       => 'subscriber',
		)
	);

	echo "created subscriber id=$subscriber_id\n";
} else {
	$subscriber_id = $subscriber->ID;
	echo "subscriber exists id=$subscriber_id\n";
}

$admin = probe_report( 1 );

$sub = probe_report( $subscriber_id );

file_put_contents(
	__DIR__ . '/cookies.env',
	implode(
		"\n",
		array(
			"ADMIN_COOKIE='" . $admin['cookie'] . "'",
			'ADMIN_NONCE=' . $admin['nonce'],
			'ADMIN_EDIT_NONCE=' . $admin['edit'],
			'ADMIN_STATUS_NONCE=' . $admin['status'],
			"SUB_COOKIE='" . $sub['cookie'] . "'",
			'SUB_NONCE=' . $sub['nonce'],
			'SUB_EDIT_NONCE=' . $sub['edit'],
			'SUB_STATUS_NONCE=' . $sub['status'],
		)
	) . "\n"
);

echo 'wrote ' . __DIR__ . "/cookies.env\n";
