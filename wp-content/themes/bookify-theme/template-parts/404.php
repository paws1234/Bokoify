<?php
/**
 * The page a visitor lands on when an address does not exist.
 *
 * This overrides the parent theme's `template-parts/404.php` and nothing else: the routing in
 * `index.php` that chose this file, the header and the footer are all still the parent's, so only
 * the panel in the middle of the page changes.
 *
 * Presentation only - no post type, no shortcode, nothing read from the request. The three links
 * are built from the site's own permalinks, and the booking page is named the way the plugin's own
 * listing defaults name it (`home_url( '/book/' )`), so there is still one place that decides
 * where "book" is.
 *
 * The parent wraps its message in the `hello_elementor_page_title` filter, which exists to hide a
 * page header that would repeat a page's title. There is no title to repeat here, and the heading
 * below is the page's whole message rather than a heading block, so that filter is deliberately not
 * consulted.
 *
 * The big number is decorative: it is `aria-hidden`, and the heading underneath is what a screen
 * reader announces. "404" read aloud is noise; "We could not find that page" is the message.
 *
 * @package bookify-theme
 */

defined( 'ABSPATH' ) || exit;
?>
<main id="content" class="site-main bookify-404">
	<div class="bookify-404__inner">
		<p class="bookify-404__code" aria-hidden="true">404</p>

		<h1 class="bookify-404__title"><?php esc_html_e( 'We could not find that page', 'bookify-theme' ); ?></h1>

		<p class="bookify-404__lead">
			<?php esc_html_e( 'The link may be out of date, or the address may have a character or two out of place. Nothing is broken on your side — and everything below is one click away.', 'bookify-theme' ); ?>
		</p>

		<p class="bookify-404__actions">
			<a class="bookify-404__action bookify-404__action--primary" href="<?php echo esc_url( home_url( '/book/' ) ); ?>">
				<?php esc_html_e( 'Book a session', 'bookify-theme' ); ?>
			</a>
			<a class="bookify-404__action" href="<?php echo esc_url( home_url( '/sessions/' ) ); ?>">
				<?php esc_html_e( 'See what we offer', 'bookify-theme' ); ?>
			</a>
			<a class="bookify-404__action" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Home', 'bookify-theme' ); ?>
			</a>
		</p>
	</div>
</main>
