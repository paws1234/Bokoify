<?php
/**
 * One event, on a page of its own.
 *
 * The second file this project puts in the theme (plan D23, T32): without it the event's own
 * permalink would render through the template hierarchy and land on Hello Elementor's bare
 * fallback, which is the defect D9 recorded and D15 fixed for sessions.
 *
 * Presentation only. There is no post type, no shortcode and nothing that reads a request here — the
 * date, the times, the places left, the tiers and the form all come from the plugin's own functions,
 * so the page keeps working when the theme is switched and the plugin keeps working when it is not.
 *
 * @package bookify-theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$bookify_event = get_post();

	$bookify_date  = bookify_event_date_label( $bookify_event->ID );
	$bookify_start = bookify_event_start( $bookify_event->ID );
	$bookify_end   = bookify_event_end( $bookify_event->ID );
	$bookify_times = implode( '–', array_filter( array( $bookify_start, $bookify_end ) ) );
	$bookify_left  = bookify_event_places_left( $bookify_event->ID );
	?>
	<main id="content" class="site-main bookify-event">
		<article class="bookify-event__article">
			<header class="bookify-event__header">
				<h1 class="bookify-event__title"><?php the_title(); ?></h1>

				<?php if ( '' !== $bookify_date ) : ?>
					<p class="bookify-event__when">
						<?php echo esc_html( trim( $bookify_date . ' ' . $bookify_times ) ); ?>
					</p>
				<?php endif; ?>

				<p class="bookify-event__places">
					<?php
					if ( bookify_event_is_over( $bookify_event->ID ) ) {
						esc_html_e( 'This event has finished.', 'bookify-theme' );
					} else {
						printf(
							/* translators: %d: places still for sale. */
							esc_html( _n( '%d place left', '%d places left', $bookify_left, 'bookify-theme' ) ),
							(int) $bookify_left
						);
					}
					?>
				</p>
			</header>

			<div class="bookify-event__content">
				<?php the_content(); ?>
			</div>

			<?php
			/*
			 * The tiers, as the plugin reads them: name, price per ticket and the places left. Nothing
			 * here is typed twice — the numbers are the ones the write path counts against.
			 */
			$bookify_tiers = bookify_event_tiers( $bookify_event->ID );
			?>
			<?php if ( $bookify_tiers ) : ?>
				<ul class="bookify-event__tiers">
					<?php foreach ( $bookify_tiers as $bookify_tier ) : ?>
						<li class="bookify-event__tier">
							<span class="bookify-event__tier-name"><?php echo esc_html( $bookify_tier->post_title ); ?></span>
							<span class="bookify-event__tier-price"><?php echo esc_html( bookify_tier_price_label( $bookify_tier->ID ) ); ?></span>
							<span class="bookify-event__tier-left">
								<?php
								$bookify_tier_left = bookify_tier_places_left( $bookify_tier->ID );

								if ( 0 === $bookify_tier_left ) {
									esc_html_e( 'Sold out', 'bookify-theme' );
								} else {
									printf(
										/* translators: %d: places left in this tier. */
										esc_html__( '%d left', 'bookify-theme' ),
										(int) $bookify_tier_left
									);
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="bookify-event__tickets">
				<?php
				// The form for this one event, and nothing else it could book.
				if ( function_exists( 'bookify_event_ticket_form_html' ) ) {
					echo bookify_event_ticket_form_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						array( 'event' => $bookify_event->ID )
					);
				}
				?>
			</div>
		</article>
	</main>
	<?php
endwhile;

get_footer();
