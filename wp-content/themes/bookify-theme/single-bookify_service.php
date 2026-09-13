<?php
/**
 * One session, on a page of its own.
 *
 * The only file this project puts in the theme (plan D15, T26): the theme's own header and footer,
 * the title, the price and the length, the session's copy, and one button through to the booking
 * form with this session already chosen.
 *
 * Presentation only. There is no post type, no shortcode and nothing that reads a request here —
 * the booking rules live in the plugin, behind functions, so this page keeps working when the
 * theme is switched and the plugin keeps working when it is not.
 *
 * @package bookify-theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$bookify_session = get_post();

	// The plugin's own vocabulary, so this page and the listing cannot word the price differently.
	$bookify_parts = bookify_booking_service_price_and_length( $bookify_session );
	$bookify_meta  = bookify_booking_service_meta_label( $bookify_parts['price'], $bookify_parts['minutes'] );
	?>
	<main id="content" class="site-main bookify-session">
		<article class="bookify-session__article">
			<header class="bookify-session__header">
				<h1 class="bookify-session__title"><?php the_title(); ?></h1>

				<?php if ( '' !== $bookify_meta ) : ?>
					<p class="bookify-session__meta"><?php echo esc_html( $bookify_meta ); ?></p>
				<?php endif; ?>
			</header>

			<?php
			/*
			 * The photograph, when there is one, between the title and the copy: the price and the length
			 * have already said what this is, and the picture shows the room before the paragraphs
			 * explain it. A session with no image renders nothing here rather than an empty frame.
			 *
			 * Eager and high priority, unlike the listing's lazy ones: this is the first thing on the page
			 * and the largest thing in it, so it is the last image that should wait.
			 */
			$bookify_image = get_post_thumbnail_id( $bookify_session );
			?>
			<?php if ( $bookify_image ) : ?>
				<figure class="bookify-session__figure">
					<?php
					echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes it.
						$bookify_image,
						'large',
						false,
						array(
							'class' => 'bookify-session__image',
						)
					);
					?>
				</figure>
			<?php endif; ?>

			<div class="bookify-session__content">
				<?php the_content(); ?>
			</div>

			<?php
			/*
			 * One button, not the form.
			 *
			 * The form used to be embedded here with a single row in it, so the only session it could
			 * book was this one. The page is the description now — the price and the length are already
			 * above it — and the form stays where the form is.
			 *
			 * The link names the session exactly the way the listing's own link does, so the form opens
			 * with this session chosen and its times ready. The visitor can change the session once they
			 * are there, which is the one thing an embedded form could not offer: that is the trade, and
			 * it is the point of the change.
			 *
			 * The destination comes from the plugin rather than a hardcoded `/book/`, so the button keeps
			 * working if the page is moved or renamed. No plugin, no button — a link that cannot book
			 * anything would be worse than no link at all.
			 */
			$bookify_booking_url = function_exists( 'bookify_booking_page_url' ) ? bookify_booking_page_url() : '';
			?>
			<?php if ( '' !== $bookify_booking_url ) : ?>
				<p class="bookify-session__cta">
					<a class="bookify-session__book" href="<?php echo esc_url( add_query_arg( 'bookify_service_id', $bookify_session->ID, $bookify_booking_url ) ); ?>">
						<?php esc_html_e( 'Book now', 'bookify-theme' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
