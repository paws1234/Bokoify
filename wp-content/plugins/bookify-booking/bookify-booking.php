<?php
/**
 * Plugin Name: Bookify Booking
 * Description: Services and bookings for Bookify: the post types, the booking write path, the form and its Elementor widget.
 * Version:     0.3.0
 * License:     GPL-2.0-or-later
 * Text Domain: bookify-booking
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

define( 'BOOKIFY_BOOKING_VERSION', '0.6.4' );
define( 'BOOKIFY_BOOKING_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/post-types.php';
require_once __DIR__ . '/includes/bookings.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/availability-cache.php';
require_once __DIR__ . '/includes/rest-slots.php';
require_once __DIR__ . '/includes/booking-form.php';
require_once __DIR__ . '/includes/manage-booking.php';
require_once __DIR__ . '/includes/customer-accounts.php';
require_once __DIR__ . '/includes/bot-protection.php';
require_once __DIR__ . '/includes/emails.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/admin-bookings.php';
require_once __DIR__ . '/includes/service-fields.php';
require_once __DIR__ . '/includes/service-list.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/business-details.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/events.php';
require_once __DIR__ . '/includes/event-fields.php';
require_once __DIR__ . '/includes/event-tickets.php';
require_once __DIR__ . '/includes/event-summary.php';
require_once __DIR__ . '/includes/payments/amounts.php';
require_once __DIR__ . '/includes/payments/stripe.php';
require_once __DIR__ . '/includes/payments/webhook.php';

// The Supabase mirror. Loaded last because the transforms call into the booking, event and payment
// helpers above, and a function has to exist before the closure that calls it is defined. Nothing
// here runs unless a project URL and key are configured — see includes/supabase/sync.php.
require_once __DIR__ . '/includes/supabase/transform.php';
require_once __DIR__ . '/includes/supabase/client.php';
require_once __DIR__ . '/includes/supabase/postgres.php';
require_once __DIR__ . '/includes/supabase/media.php';
require_once __DIR__ . '/includes/supabase/content.php';
require_once __DIR__ . '/includes/supabase/sync.php';

/**
 * Attach the Elementor widget and its panel category.
 *
 * Hooked to plugins_loaded rather than from bookify_booking_init(): Elementor fires both of
 * these actions from its own init, which runs at priority 0, ahead of ours. Neither action
 * fires on a site without Elementor, and nothing Elementor-specific is touched until one
 * does, so this costs an Elementor-less site nothing.
 */
function bookify_booking_register_elementor() {
	add_action( 'elementor/elements/categories_registered', 'bookify_booking_elementor_category' );
	add_action( 'elementor/widgets/register', 'bookify_booking_elementor_widgets' );
}

add_action( 'plugins_loaded', 'bookify_booking_register_elementor' );

/**
 * Put the booking widget in a category of its own in the Elementor panel.
 *
 * @param \Elementor\Elements_Manager $elements_manager Elementor's elements manager.
 */
function bookify_booking_elementor_category( $elements_manager ) {
	$elements_manager->add_category(
		'bookify',
		array(
			'title' => esc_html__( 'Bookify', 'bookify-booking' ),
			'icon'  => 'eicon-calendar',
		)
	);
}

/**
 * Hand the widgets to Elementor.
 *
 * The classes are loaded here and not at file scope: they extend Elementor classes, and
 * Elementor registers its autoloader on init, so loading them any earlier — or on a site
 * without Elementor — would be a fatal error.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's widgets manager.
 */
function bookify_booking_elementor_widgets( $widgets_manager ) {
	require_once __DIR__ . '/includes/elementor-widget.php';

	$widgets_manager->register( new Bookify_Booking_Widget() );
	$widgets_manager->register( new Bookify_Service_List_Widget() );
	$widgets_manager->register( new Bookify_Event_Tickets_Widget() );
}

/**
 * Register everything the plugin provides.
 *
 * Hooked to init rather than run at file scope, so nothing here fires before WordPress,
 * its post types and its meta API are ready.
 */
function bookify_booking_init() {
	bookify_booking_register_post_types();
	bookify_booking_register_meta();
	bookify_booking_register_request_handlers();
	bookify_booking_register_shortcodes();
	bookify_booking_register_manage();
	bookify_booking_register_customer_accounts();
	bookify_booking_register_admin();
	bookify_booking_register_service_fields();
	bookify_service_list_register();
	bookify_booking_register_business_details();
	bookify_booking_register_settings();
	bookify_booking_register_payments();
	bookify_booking_register_stripe();
	bookify_booking_register_reminders();
	bookify_booking_register_schema();
	bookify_booking_register_availability_cache();
	bookify_booking_register_events();
	bookify_booking_register_event_fields();
	bookify_booking_register_event_tickets();
	bookify_booking_register_event_summary();
	bookify_booking_register_supabase();
	bookify_booking_register_supabase_media();
}

add_action( 'init', 'bookify_booking_init' );
