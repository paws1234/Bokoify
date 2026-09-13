<?php
/**
 * The sessions a visitor can read.
 *
 * The listing speaks the form's own vocabulary: the same price and length wording from
 * bookify_booking_service_meta_label(), and the same destination for "book this", so the two
 * cannot drift apart.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The copy the list falls back to.
 *
 * 'booking_url' is where a row sends a visitor: the page T4 created for the form. It is an
 * argument rather than a constant so a later task can point it somewhere else without editing
 * this file.
 *
 * @return array<string,string>
 */
function bookify_service_list_defaults() {
	return array(
		'heading'      => __( 'Sessions', 'bookify-booking' ),
		'intro'        => __( 'Everything we offer, and how long it takes.', 'bookify-booking' ),
		'button_label' => __( 'Book this', 'bookify-booking' ),
		'more_label'   => __( 'Read more', 'bookify-booking' ),
		'empty'        => __( 'No sessions are on offer yet.', 'bookify-booking' ),
		'currency'     => '',
		'booking_url'  => home_url( '/book/' ),
	);
}

/**
 * Render the published sessions as a list.
 *
 * @param array $args Copy overrides; see bookify_service_list_defaults().
 * @return string
 */
function bookify_service_list_html( array $args = array() ) {
	$args = wp_parse_args( $args, bookify_service_list_defaults() );

	$services = bookify_booking_published_services();

	// Enqueued here rather than on wp_enqueue_scripts: WordPress prints a style enqueued this
	// late in the footer, so a page without the listing never loads the file.
	wp_enqueue_style( 'bookify-service-list' );

	ob_start();
	?>
	<div class="bookify-services">
		<?php if ( '' !== $args['heading'] ) : ?>
			<h2 class="bookify-services__heading"><?php echo esc_html( $args['heading'] ); ?></h2>
		<?php endif; ?>

		<?php if ( '' !== $args['intro'] ) : ?>
			<p class="bookify-services__intro"><?php echo esc_html( $args['intro'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! $services ) : ?>
			<p class="bookify-services__empty"><?php echo esc_html( $args['empty'] ); ?></p>
		<?php else : ?>
			<ul class="bookify-services__list">
				<?php foreach ( $services as $service ) : ?>
					<?php
					$parts   = bookify_booking_service_price_and_length( $service, $args['currency'] );
					$meta    = bookify_booking_service_meta_label( $parts['price'], $parts['minutes'] );
					$excerpt = get_the_excerpt( $service );
					?>
					<li class="bookify-services__item">
						<?php
						/*
						 * The card's photograph, when the session has one.
						 *
						 * `wp_get_attachment_image()` rather than an `<img>` written by hand: it carries the alt
						 * text from the media library and the srcset and sizes WordPress generated on import, so
						 * a phone is not handed the full-width file. A session with no photograph renders no
						 * figure at all rather than an empty box, and the card is a card either way.
						 *
						 * Lazy, because a listing is a grid of these and the ones below the fold have no business
						 * being fetched before the visitor scrolls.
						 */
						$thumbnail = get_post_thumbnail_id( $service );
						?>
						<?php if ( $thumbnail ) : ?>
							<figure class="bookify-services__figure">
								<?php
								echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes it.
									$thumbnail,
									'medium_large',
									false,
									array(
									'class'   => 'bookify-services__image',
									'sizes'   => '(min-width: 60rem) 30vw, (min-width: 40rem) 45vw, 90vw',
									'loading' => 'lazy',
									)
								);
								?>
							</figure>
						<?php endif; ?>

						<h3 class="bookify-services__name"><?php echo esc_html( $service->post_title ); ?></h3>

						<?php if ( '' !== $meta ) : ?>
							<p class="bookify-services__meta"><?php echo esc_html( $meta ); ?></p>
						<?php endif; ?>

						<?php if ( '' !== trim( $excerpt ) ) : ?>
							<p class="bookify-services__excerpt"><?php echo esc_html( $excerpt ); ?></p>
						<?php endif; ?>

						<p class="bookify-services__book">
							<?php
							/*
							 * Since T26 a session has a page of its own, so the card offers both: the page, which
							 * says what the session is, and the direct link, which skips straight to picking a time.
							 * The row is left without a page link if anything ever makes its URL unreachable.
							 *
							 * The direct link's argument is `bookify_service_id`, not `bookify_service`, and the
							 * longer name is load-bearing: `bookify_service` is this project's post type key, and
							 * registering a post type makes that key a public query var - so WordPress reads
							 * `?bookify_service=19` as "the session whose slug is 19", finds nothing, and answers
							 * 404 before any of this plugin's code runs. Measured 2026-09-13: that URL was 404
							 * while `/book/?foo=bar` was 200. An argument WordPress does not claim is simply
							 * ignored by it and read by the form. Do not shorten it back.
							 */
							$more_url = (string) get_permalink( $service );
							?>
							<?php if ( '' !== $args['more_label'] && '' !== $more_url ) : ?>
								<a class="bookify-services__more" href="<?php echo esc_url( $more_url ); ?>"><?php echo esc_html( $args['more_label'] ); ?></a>
							<?php endif; ?>

							<a
								class="bookify-services__link"
								href="<?php echo esc_url( add_query_arg( 'bookify_service_id', $service->ID, $args['booking_url'] ) ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the session's title. */ __( 'Book %s', 'bookify-booking' ), $service->post_title ) ); ?>"
							><?php echo esc_html( $args['button_label'] ); ?></a>
						</p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Render the list from a shortcode.
 *
 * @param array|string $atts Shortcode attributes, named after the list's copy keys.
 * @return string
 */
function bookify_service_list_shortcode( $atts = array() ) {
	return bookify_service_list_html( shortcode_atts( bookify_service_list_defaults(), $atts, 'bookify_services' ) );
}

/**
 * Register the listing's stylesheet.
 *
 * Nothing is enqueued here: the render function asks for the handle, so a page that does
 * not hold the listing never loads the file.
 */
function bookify_service_list_register_style() {
	wp_register_style(
		'bookify-service-list',
		BOOKIFY_BOOKING_URL . 'assets/service-list.css',
		array(),
		BOOKIFY_BOOKING_VERSION
	);
}

add_action( 'wp_enqueue_scripts', 'bookify_service_list_register_style' );

/**
 * Register the shortcode.
 */
function bookify_service_list_register() {
	add_shortcode( 'bookify_services', 'bookify_service_list_shortcode' );
}
