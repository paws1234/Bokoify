<?php
/**
 * The Bookify settings screen.
 *
 * One submenu under Bookings, gated on manage_options, holding the business details and the
 * availability rules (plan D7: one screen, not a settings framework).
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The option group the screen's form posts back to.
 */
const BOOKIFY_BOOKING_SETTINGS_GROUP = 'bookify_settings';

/**
 * Clean the submitted business details.
 *
 * A userland function, because WordPress hands a sanitise callback four arguments and an
 * internal PHP function refuses the extras (see T2). Anything that is not a non-empty string
 * is dropped rather than stored, so an array or a malformed email leaves nothing behind.
 *
 * @param mixed $value The submitted value.
 * @return array<string,string>
 */
function bookify_booking_sanitize_business( $value ) {
	$clean = array();

	if ( ! is_array( $value ) ) {
		return $clean;
	}

	foreach ( bookify_booking_business_fields() as $key => $field ) {
		if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) ) {
			continue;
		}

		$raw = trim( wp_unslash( $value[ $key ] ) );

		if ( '' === $raw ) {
			continue;
		}

		$clean[ $key ] = 'email' === $field['type'] ? sanitize_email( $raw ) : sanitize_text_field( $raw );

		if ( '' === $clean[ $key ] ) {
			unset( $clean[ $key ] );
		}
	}

	return $clean;
}

/**
 * Clean the submitted availability rules.
 *
 * Also a userland function, for the same reason (T2's four-argument trap). Every value is
 * re-validated rather than trusted: a `25:00` close time, a `2026-02-31`, an interval of zero or
 * a stray array is discarded instead of stored, and the option never holds a value the slot
 * generator would then have to guess about.
 *
 * A weekday whose hours cannot be read is stored as **closed**, not as its default hours. An
 * unparseable range means nobody knows when the business is open, and a day that is closed
 * cannot accept a booking, so the guess falls on the safe side.
 *
 * It reads the *stored* shape, not only the screen's field names — a number may arrive as an int
 * or as a string, and the blocked dates either as the screen's `blocked` textarea or as the
 * `blocked_dates` list. WordPress runs this callback on every update_option() as well as on the
 * form post, so an option written by a script must not be quietly replaced by the defaults; the
 * option has to round-trip through this function unchanged (measured: it does).
 *
 * @param mixed $value The submitted value.
 * @return array
 */
function bookify_booking_sanitize_availability( $value ) {
	$defaults = bookify_availability_defaults();
	$clean    = $defaults;

	if ( ! is_array( $value ) ) {
		return $clean;
	}

	$submitted = isset( $value['weekdays'] ) && is_array( $value['weekdays'] ) ? $value['weekdays'] : array();
	$weekdays  = array();

	foreach ( $defaults['weekdays'] as $day => $default ) {
		$row = isset( $submitted[ $day ] ) && is_array( $submitted[ $day ] ) ? $submitted[ $day ] : array();

		$from = isset( $row['from'] ) && is_string( $row['from'] ) ? trim( wp_unslash( $row['from'] ) ) : '';
		$to   = isset( $row['to'] ) && is_string( $row['to'] ) ? trim( wp_unslash( $row['to'] ) ) : '';

		if ( empty( $row['open'] ) || ! bookify_booking_is_time( $from ) || ! bookify_booking_is_time( $to ) || $to <= $from ) {
			$weekdays[ $day ] = array(
				'open' => false,
				'from' => bookify_booking_is_time( $from ) ? $from : $default['from'],
				'to'   => bookify_booking_is_time( $to ) ? $to : $default['to'],
			);

			continue;
		}

		$weekdays[ $day ] = array(
			'open' => true,
			'from' => $from,
			'to'   => $to,
		);
	}

	$clean['weekdays'] = $weekdays;

	$numbers = array(
		'interval'   => array( 5, 240 ),
		'lead_hours' => array( 0, 720 ),
		'move_hours' => array( 0, 720 ),
		'days_ahead' => array( 1, 365 ),
	);

	foreach ( $numbers as $key => $range ) {
		// An int from a script and a numeric string from the form are both numbers; anything
		// else — an empty box, a word — restores that rule's default rather than meaning zero,
		// because a zero lead time and a zero-day window are both real choices.
		if ( ! isset( $value[ $key ] ) || ! is_numeric( $value[ $key ] ) ) {
			continue;
		}

		$clean[ $key ] = min( $range[1], max( $range[0], (int) $value[ $key ] ) );
	}

	$lines = array();

	if ( isset( $value['blocked_dates'] ) && is_array( $value['blocked_dates'] ) ) {
		$lines = $value['blocked_dates'];
	}

	if ( isset( $value['blocked'] ) && is_string( $value['blocked'] ) ) {
		$lines = array_merge( $lines, preg_split( '/\R/', wp_unslash( $value['blocked'] ) ) );
	}

	$blocked = array();

	foreach ( $lines as $line ) {
		if ( ! is_string( $line ) ) {
			continue;
		}

		$line = trim( $line );

		if ( '' !== $line && bookify_booking_is_date( $line ) && ! in_array( $line, $blocked, true ) ) {
			$blocked[] = $line;
		}
	}

	sort( $blocked );

	$clean['blocked_dates'] = $blocked;

	return $clean;
}

/**
 * Clean the submitted bot-protection keys.
 *
 * The one sanitiser here that does not simply replace what was stored with what was submitted, and
 * for a reason: the secret key is never rendered back into the screen, so it is never posted back
 * either. An empty box therefore means "keep the stored key", and the checkbox is the only way to
 * be rid of it — without one of the two, an owner who saved a key once could never remove it.
 *
 * @param mixed $value The submitted value.
 * @return array{site_key:string,secret_key:string}
 */
function bookify_booking_sanitize_bots( $value ) {
	$clean = bookify_bots();

	if ( ! is_array( $value ) ) {
		return $clean;
	}

	if ( ! empty( $value['clear'] ) ) {
		return bookify_bots_defaults();
	}

	// The site key is public — it is printed into every page that shows the form — so it is shown
	// back, and emptying its box is how the owner turns the widget off without losing the secret.
	if ( isset( $value['site_key'] ) && is_string( $value['site_key'] ) ) {
		$clean['site_key'] = sanitize_text_field( trim( wp_unslash( $value['site_key'] ) ) );
	}

	if ( isset( $value['secret_key'] ) && is_string( $value['secret_key'] ) ) {
		$secret = sanitize_text_field( trim( wp_unslash( $value['secret_key'] ) ) );

		if ( '' !== $secret ) {
			$clean['secret_key'] = $secret;
		}
	}

	return $clean;
}

/**
 * Clean the submitted payment settings.
 *
 * Like the bot keys, and for the same reason: a secret is never rendered back into the screen, so it
 * is never posted back either. An empty box therefore means "keep the stored key", and the checkbox
 * is the only way to be rid of the keys — without one of the two, an owner who saved a key once
 * could never remove it. The currency and the payment window are ordinary values and are simply
 * replaced with what was submitted, re-validated here rather than trusted.
 *
 * @param mixed $value The submitted value.
 * @return array{currency:string,secret_key:string,webhook_secret:string,expiry_minutes:int}
 */
function bookify_booking_sanitize_payments( $value ) {
	$clean = bookify_booking_payments();

	if ( ! is_array( $value ) ) {
		return $clean;
	}

	if ( ! empty( $value['clear'] ) ) {
		return bookify_booking_payments_defaults();
	}

	if ( isset( $value['currency'] ) && is_string( $value['currency'] ) ) {
		$clean['currency'] = bookify_booking_sanitize_currency( trim( wp_unslash( $value['currency'] ) ) );
	}

	// A number from a script and a numeric string from the form are both numbers; anything else —
	// an empty box, a word — keeps what was stored rather than meaning five minutes.
	if ( isset( $value['expiry_minutes'] ) && is_numeric( $value['expiry_minutes'] ) ) {
		$clean['expiry_minutes'] = min( 1440, max( 5, (int) $value['expiry_minutes'] ) );
	}

	foreach ( array( 'secret_key', 'webhook_secret' ) as $key ) {
		if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) ) {
			continue;
		}

		$secret = sanitize_text_field( trim( wp_unslash( $value[ $key ] ) ) );

		if ( '' !== $secret ) {
			$clean[ $key ] = $secret;
		}
	}

	return $clean;
}

/**
 * Clean the submitted reminder settings.
 *
 * Unlike the two key-bearing sections there is no secret here, so an empty submission simply means
 * "off": the checkbox is the whole answer, which is what an owner expects of a checkbox. The lead
 * time is re-validated here rather than trusted, in the same way the availability rules are.
 *
 * @param mixed $value The submitted value.
 * @return array{enabled:bool,hours_before:int}
 */
function bookify_booking_sanitize_reminders( $value ) {
	$clean = bookify_booking_reminder_defaults();

	if ( ! is_array( $value ) ) {
		return $clean;
	}

	$clean['enabled'] = ! empty( $value['enabled'] );

	if ( isset( $value['hours_before'] ) && is_numeric( $value['hours_before'] ) ) {
		$clean['hours_before'] = min( 336, max( 1, (int) $value['hours_before'] ) );
	}

	return $clean;
}

/**
 * Register the options and the screen.
 */
function bookify_booking_register_settings() {
	register_setting(
		BOOKIFY_BOOKING_SETTINGS_GROUP,
		'bookify_business',
		array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => 'bookify_booking_sanitize_business',
		)
	);

	register_setting(
		BOOKIFY_BOOKING_SETTINGS_GROUP,
		'bookify_availability',
		array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => 'bookify_booking_sanitize_availability',
		)
	);

	register_setting(
		BOOKIFY_BOOKING_SETTINGS_GROUP,
		BOOKIFY_BOTS_OPTION,
		array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => 'bookify_booking_sanitize_bots',
		)
	);

	register_setting(
		BOOKIFY_BOOKING_SETTINGS_GROUP,
		BOOKIFY_PAYMENTS_OPTION,
		array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => 'bookify_booking_sanitize_payments',
		)
	);

	register_setting(
		BOOKIFY_BOOKING_SETTINGS_GROUP,
		BOOKIFY_BOOKING_REMINDER_OPTION,
		array(
			'type'              => 'array',
			'default'           => array(),
			'sanitize_callback' => 'bookify_booking_sanitize_reminders',
		)
	);


	add_action( 'admin_menu', 'bookify_booking_settings_menu' );
}

/**
 * Add the screen under the Bookings menu.
 */
function bookify_booking_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=bookify_booking',
		__( 'Bookify settings', 'bookify-booking' ),
		__( 'Settings', 'bookify-booking' ),
		'manage_options',
		'bookify-settings',
		'bookify_booking_render_settings_page'
	);
}

/**
 * Print the seven weekday rows of the availability section.
 *
 * @param array $availability The stored rules. A row is only ever edited for its own day.
 */
function bookify_booking_availability_weekday_rows( array $availability ) {
	foreach ( bookify_booking_weekdays() as $day => $name ) :
		$row  = $availability['weekdays'][ $day ];
		$base = 'bookify_availability_weekdays_' . $day;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $base . '_from' ); ?>"><?php echo esc_html( $name ); ?></label>
			</th>
			<td>
				<label class="bookify-settings__toggle">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $base . '_open' ); ?>"
						name="bookify_availability[weekdays][<?php echo esc_attr( (string) $day ); ?>][open]"
						value="1"
						<?php checked( $row['open'] ); ?>
					/>
					<?php esc_html_e( 'Open', 'bookify-booking' ); ?>
				</label>
				<input
					type="time"
					id="<?php echo esc_attr( $base . '_from' ); ?>"
					name="bookify_availability[weekdays][<?php echo esc_attr( (string) $day ); ?>][from]"
					value="<?php echo esc_attr( $row['from'] ); ?>"
				/>
				<?php esc_html_e( 'to', 'bookify-booking' ); ?>
				<input
					type="time"
					name="bookify_availability[weekdays][<?php echo esc_attr( (string) $day ); ?>][to]"
					value="<?php echo esc_attr( $row['to'] ); ?>"
				/>
			</td>
		</tr>
		<?php
	endforeach;
}

/**
 * Print one numeric availability rule.
 *
 * @param string $key         Option key.
 * @param string $label       Field label.
 * @param int    $value       Stored value.
 * @param int    $min         Smallest value the sanitiser will keep.
 * @param int    $max         Largest value the sanitiser will keep.
 * @param string $description What the number means.
 */
function bookify_booking_availability_number_field( $key, $label, $value, $min, $max, $description ) {
	$id = 'bookify_availability_' . $key;
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		</th>
		<td>
			<input
				type="number"
				id="<?php echo esc_attr( $id ); ?>"
				name="bookify_availability[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( (string) $value ); ?>"
				min="<?php echo esc_attr( (string) $min ); ?>"
				max="<?php echo esc_attr( (string) $max ); ?>"
				step="1"
				class="small-text"
			/>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		</td>
	</tr>
	<?php
}

/**
 * Render the screen.
 *
 * The form posts to options.php, so the nonce and the capability are checked by WordPress
 * rather than by hand: settings_fields() writes the nonce it will verify, and options.php
 * refuses the save to anyone without manage_options.
 */
function bookify_booking_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$business     = bookify_booking_business();
	$availability = bookify_availability();
	$reminders    = bookify_booking_reminders();
	$bots         = bookify_bots();
	$payments     = bookify_booking_payments();
	$mail         = bookify_booking_mail();
	$last_mail    = bookify_booking_mail_last_result();
	$mail_to      = bookify_booking_mail_redirect_to();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Bookify settings', 'bookify-booking' ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( BOOKIFY_BOOKING_SETTINGS_GROUP ); ?>

			<h2><?php echo esc_html__( 'Business details', 'bookify-booking' ); ?></h2>
			<p><?php echo esc_html__( 'Printed in the footer of every page and on the Contact page.', 'bookify-booking' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( bookify_booking_business_fields() as $key => $field ) : ?>
						<tr>
							<th scope="row">
								<label for="bookify_business_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							</th>
							<td>
								<input
									type="<?php echo esc_attr( $field['type'] ); ?>"
									id="bookify_business_<?php echo esc_attr( $key ); ?>"
									name="bookify_business[<?php echo esc_attr( $key ); ?>]"
									value="<?php echo esc_attr( $business[ $key ] ); ?>"
									class="regular-text"
								/>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Availability', 'bookify-booking' ); ?></h2>
			<p><?php echo esc_html__( 'The diary the booking form offers and the write path enforces. A day with no hours, or a blocked date, cannot be booked at all.', 'bookify-booking' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<?php bookify_booking_availability_weekday_rows( $availability ); ?>

					<?php
					bookify_booking_availability_number_field(
						'interval',
						__( 'Slot interval in minutes', 'bookify-booking' ),
						$availability['interval'],
						5,
						240,
						__( 'How far apart the times on offer are, counted from opening time.', 'bookify-booking' )
					);

					bookify_booking_availability_number_field(
						'lead_hours',
						__( 'Lead time in hours', 'bookify-booking' ),
						$availability['lead_hours'],
						0,
						720,
						__( 'How long before it starts a slot stops being offered. Zero allows a booking at any free time today.', 'bookify-booking' )
					);

					bookify_booking_availability_number_field(
						'move_hours',
						__( 'Move window in hours', 'bookify-booking' ),
						$availability['move_hours'],
						0,
						720,
						__( 'How long before it starts a booking can no longer be moved by the customer. They can still see it; they just have to call instead.', 'bookify-booking' )
					);

					bookify_booking_availability_number_field(
						'days_ahead',
						__( 'Days ahead', 'bookify-booking' ),
						$availability['days_ahead'],
						1,
						365,
						__( 'How far into the future the diary is offered.', 'bookify-booking' )
					);
					?>

					<tr>
						<th scope="row">
							<label for="bookify_availability_blocked"><?php echo esc_html__( 'Blocked dates', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<textarea
								id="bookify_availability_blocked"
								name="bookify_availability[blocked]"
								rows="4"
								cols="20"
								class="code"
							><?php echo esc_textarea( implode( "\n", $availability['blocked_dates'] ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'One date per line, as YYYY-MM-DD. Any day listed here is closed, whatever the weekday hours say.', 'bookify-booking' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Reminders', 'bookify-booking' ); ?></h2>
			<p><?php echo esc_html__( 'One message before a session starts, carrying the same link the confirmation does, so a customer can move or cancel it. A booking made inside the lead time gets none — its reminder moment has already gone, and the confirmation is the mail it needs.', 'bookify-booking' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Send reminders', 'bookify-booking' ); ?></th>
						<td>
							<label class="bookify-settings__toggle">
								<input type="checkbox" name="bookify_reminders[enabled]" value="1" <?php checked( $reminders['enabled'] ); ?> />
								<?php echo esc_html__( 'Email the customer before their session.', 'bookify-booking' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bookify_reminders_hours"><?php echo esc_html__( 'Hours before', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="bookify_reminders_hours"
								name="bookify_reminders[hours_before]"
								value="<?php echo esc_attr( (string) $reminders['hours_before'] ); ?>"
								min="1"
								max="336"
								step="1"
								class="small-text"
							/>
							<p class="description"><?php echo esc_html__( 'How long before the session starts the reminder goes out. Changing this moves the reminders already scheduled.', 'bookify-booking' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Payments', 'bookify-booking' ); ?></h2>
			<p>
				<?php echo esc_html__( 'A session can ask for a deposit or for the whole price, chosen on the session itself. Payment is taken on Stripe’s own page: this site never sees a card number, and nothing here charges anything until both keys below are filled in.', 'bookify-booking' ); ?>
				<?php echo esc_html__( 'No key means no card payments at all — the booking form and everything else stay exactly as they are without them.', 'bookify-booking' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="bookify_payments_currency"><?php echo esc_html__( 'Currency', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="bookify_payments_currency"
								name="bookify_payments[currency]"
								value="<?php echo esc_attr( $payments['currency'] ); ?>"
								maxlength="3"
								class="small-text"
							/>
							<p class="description"><?php echo esc_html__( 'Three letters, as Stripe asks for it — for example gbp. It is what a customer sees in front of an amount, and checkout is off while it is empty.', 'bookify-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bookify_payments_secret_key"><?php echo esc_html__( 'Stripe secret key', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="bookify_payments_secret_key"
								name="bookify_payments[secret_key]"
								value=""
								autocomplete="new-password"
								class="regular-text"
							/>
							<p class="description">
								<?php
								echo esc_html(
									'' === $payments['secret_key']
										? __( 'The private key from the Stripe dashboard. A key beginning sk_test_ is Stripe\'s test mode, which is what local work should use.', 'bookify-booking' )
										: __( 'A key is stored here. It is never shown again — leaving this box empty keeps it, and typing a new one replaces it.', 'bookify-booking' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bookify_payments_webhook_secret"><?php echo esc_html__( 'Webhook signing secret', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="bookify_payments_webhook_secret"
								name="bookify_payments[webhook_secret]"
								value=""
								autocomplete="new-password"
								class="regular-text"
							/>
							<p class="description">
								<?php
								echo esc_html(
									'' === $payments['webhook_secret']
										? __( 'From the webhook\'s own page in Stripe, beginning whsec_. This is what proves a webhook really came from Stripe; a booking is only ever confirmed by an event that passes that check.', 'bookify-booking' )
										: __( 'A signing secret is stored here. It is never shown again — leaving this box empty keeps it.', 'bookify-booking' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bookify_payments_expiry_minutes"><?php echo esc_html__( 'Payment window in minutes', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="bookify_payments_expiry_minutes"
								name="bookify_payments[expiry_minutes]"
								value="<?php echo esc_attr( (string) $payments['expiry_minutes'] ); ?>"
								min="5"
								max="1440"
								step="1"
								class="small-text"
							/>
							<p class="description"><?php echo esc_html__( 'How long an unpaid booking holds its time before the time is offered to somebody else.', 'bookify-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Remove the keys', 'bookify-booking' ); ?></th>
						<td>
							<label class="bookify-settings__toggle">
								<input type="checkbox" name="bookify_payments[clear]" value="1" />
								<?php echo esc_html__( 'Forget both keys. Sessions keep their payment settings, but no payment can be started.', 'bookify-booking' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Email', 'bookify-booking' ); ?></h2>
			<p><?php echo esc_html__( 'Confirmations go out through Resend when a key is configured, and the ordinary WordPress way when it is not. There is deliberately nothing to type in here: a key entered in a browser ends up in screenshots, in support tickets and in every export of the options table. It is configuration — see below.', 'bookify-booking' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Sending through', 'bookify-booking' ); ?></th>
						<td>
							<p>
								<strong>
									<?php echo esc_html( '' !== $mail['api_key'] ? __( 'Resend', 'bookify-booking' ) : __( 'WordPress', 'bookify-booking' ) ); ?>
								</strong>
							</p>
							<?php if ( '' === $mail['api_key'] ) : ?>
								<p class="description">
									<?php
									echo esc_html__(
										'No key is configured, so every message is handed to wp_mail(). To send through Resend, put RESEND_API_KEY and BOOKIFY_MAIL_FROM in the project\'s .env and run “wpdev restart” — see docs/SETUP.md. Nothing here accepts the key itself: it stays on the machine, out of screenshots and out of any export of the database.',
										'bookify-booking'
									);
									?>
								</p>
								<p class="description">
									<?php echo esc_html__( 'On a host that is not this kit, the same values are read from the environment, from the BOOKIFY_RESEND_API_KEY and BOOKIFY_MAIL_FROM constants, or through the bookify_booking_resend_api_key filter.', 'bookify-booking' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Sent from', 'bookify-booking' ); ?></th>
						<td>
							<p><code><?php echo esc_html( bookify_booking_mail_from() ); ?></code></p>
							<p class="description">
								<?php
								echo esc_html(
									'' !== $mail['from_email']
										? __( 'From BOOKIFY_MAIL_FROM. Resend only sends from a domain verified in its dashboard.', 'bookify-booking' )
										: __( 'The site title and the admin address, because no address is configured. Resend needs one on a domain verified in its dashboard, so sending through it also needs BOOKIFY_MAIL_FROM.', 'bookify-booking' )
								);
								?>
							</p>
						</td>
					</tr>
					<?php if ( '' !== $mail_to ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Sending everything to', 'bookify-booking' ); ?></th>
							<td>
								<p><code><?php echo esc_html( $mail_to ); ?></code></p>
								<p class="description">
									<?php
									echo esc_html__(
										'Testing mode: every message goes to this address instead of the one on the booking, and its subject names the address it was meant for. That is what makes bookings visible while no domain is verified, because Resend refuses every other recipient. Empty BOOKIFY_MAIL_REDIRECT_TO in the project\'s .env and run “wpdev restart” to send to customers again.',
										'bookify-booking'
									);
									?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last message', 'bookify-booking' ); ?></th>
						<td>
							<?php if ( null === $last_mail ) : ?>
								<p class="description"><?php echo esc_html__( 'Nothing has been sent yet.', 'bookify-booking' ); ?></p>
							<?php else : ?>
								<p>
									<strong style="color:<?php echo $last_mail['ok'] ? '#2f6b46' : '#b32d2e'; ?>">
										<?php echo $last_mail['ok'] ? esc_html__( 'Delivered', 'bookify-booking' ) : esc_html__( 'Failed', 'bookify-booking' ); ?>
									</strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: a human time difference, 2: the transport's own explanation. */
											__( '%1$s ago — %2$s', 'bookify-booking' ),
											human_time_diff( $last_mail['time'], time() ),
											$last_mail['message']
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Bot protection', 'bookify-booking' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Cloudflare Turnstile is off until both keys below are filled in. With keys in place the booking form shows the challenge, and every submission is checked with Cloudflare before anything is stored.', 'bookify-booking' ); ?>
				<?php echo esc_html__( 'Every submission is also rate limited: ten from one address, and five bookings per email address, in ten minutes.', 'bookify-booking' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="bookify_bots_site_key"><?php echo esc_html__( 'Turnstile site key', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="bookify_bots_site_key"
								name="bookify_bots[site_key]"
								value="<?php echo esc_attr( $bots['site_key'] ); ?>"
								class="regular-text"
							/>
							<p class="description"><?php echo esc_html__( 'The public key, from the Cloudflare dashboard. It is printed into every page that shows the form, so it is shown back here as it is stored.', 'bookify-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bookify_bots_secret_key"><?php echo esc_html__( 'Turnstile secret key', 'bookify-booking' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="bookify_bots_secret_key"
								name="bookify_bots[secret_key]"
								value=""
								autocomplete="new-password"
								class="regular-text"
							/>
							<p class="description">
								<?php
								echo esc_html(
									'' === $bots['secret_key']
										? __( 'The private key, from the Cloudflare dashboard. Nothing is verified until this and the site key are both set.', 'bookify-booking' )
										: __( 'A key is stored here. It is never shown again — leaving this box empty keeps it, and typing a new one replaces it.', 'bookify-booking' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Remove the keys', 'bookify-booking' ); ?></th>
						<td>
							<label class="bookify-settings__toggle">
								<input type="checkbox" name="bookify_bots[clear]" value="1" />
								<?php echo esc_html__( 'Forget both keys and turn Turnstile off again.', 'bookify-booking' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
