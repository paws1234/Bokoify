<?php
/**
 * Child theme bootstrap.
 *
 * Styles, and the footer's legal bar. The parent's sheet, the design layer in style.css, the two
 * webfonts it names, and the one row of the footer the plugin's <footer> cannot reach - nothing
 * else. Site behaviour lives in the plugin so it survives a theme switch, and no post type,
 * shortcode or template is added here.
 *
 * @package bookify-theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the parent theme stylesheet.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		wp_enqueue_style(
			'hello-elementor-parent',
			get_template_directory_uri() . '/style.css',
			array(),
			$parent ? $parent->get( 'Version' ) : $theme->get( 'Version' )
		);
	},
	5
);

/**
 * Load the child stylesheet last.
 *
 * Priority 100 rather than 5 on purpose. Elementor and this project's plugin both
 * enqueue their stylesheets on wp_enqueue_scripts at the default priority, so a child
 * stylesheet loaded at 5 is printed before them and loses every tie on document order.
 * Printing it last makes the cascade predictable; the sheet still avoids !important, so
 * an editor can override any individual widget.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style(
			'hello-elementor-child',
			get_stylesheet_uri(),
			array( 'hello-elementor-parent' ),
			wp_get_theme()->get( 'Version' )
		);
	},
	100
);

/**
 * Load the two families the design layer is built on.
 *
 * The kit's typography is still Roboto, so Elementor loads Roboto and every token in
 * style.css that names Lora or Source Sans 3 would quietly fall back to Georgia and the
 * system stack - a serif and a sans, but not the pairing the design was measured against.
 * Loading the pair here is what makes it real, and it is also what makes the fallbacks in
 * style.css a genuine fallback rather than the normal case. Two weights per family, no
 * italics: only what the stylesheet actually asks for.
 *
 * When the kit's own typography is updated in the builder this becomes redundant, and the
 * Roboto request Elementor makes from the kit stops along with it.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style(
			'bookify-fonts',
			'https://fonts.googleapis.com/css2?family=Lora:wght@400;600&family=Source+Sans+3:wght@400;600&display=swap',
			array(),
			null
		);
	},
	6
);

/**
 * Print the tab icon from two files that ship with the theme.
 *
 * WordPress's own Site Icon setting is the other way to do this and the wrong one here: it stores an
 * attachment and an option, so the icon lives in the database and has to be carried across to a host
 * that starts with an empty one. Two small files beside the stylesheet travel with the code, so the
 * tab is branded on any host the moment this theme is.
 *
 * Both are square and opaque on purpose. A transparent corner is composited onto black by iOS for a
 * touch icon, and the source artwork's rounded corners would leave four of them.
 */
add_action(
	'wp_head',
	static function () {
		$assets = get_stylesheet_directory_uri() . '/assets';

		printf(
			'<link rel="icon" type="image/png" sizes="32x32" href="%s">' . "\n",
			esc_url( $assets . '/icon-32.png' )
		);

		printf(
			'<link rel="apple-touch-icon" href="%s">' . "\n",
			esc_url( $assets . '/icon-180.png' )
		);
	}
);

/**
 * Empty Hello Elementor's copyright setting, which stops it rendering that block at all.
 *
 * The parent prints it inside <footer>, and <footer> closes before wp_footer runs - so the
 * plugin's business details, printed on wp_footer, always land *underneath* it. While the
 * parent owns the line it can never be the last thing on the page, which is where it belongs.
 * It is also a stored string, so it could not carry a year that stays current.
 */
add_filter( 'hello_elementor_hello_footer_copyright_text', '__return_empty_string' );

/**
 * Print the footer's legal bar: the menu, and the copyright beside it.
 *
 * Priority 20, so it lands after the plugin's business details (wp_footer, priority 10) and is the
 * last row on the page. The parent's footer menu is hidden by the stylesheet and drawn again here,
 * because a bar with the menu on the left and the copyright on the right is the only arrangement
 * that fills the row - and the parent renders its menu inside <footer>, which closes before the
 * details arrive, so it can never reach this position.
 *
 * It is the same theme location the parent uses, so assigning a different menu still governs both
 * copies; asking for the footer menu to be hidden is honoured rather than overridden; and the id is
 * changed because the parent's copy is still in the document and two elements cannot share one.
 *
 * The year and the name both come from the site itself, so the line cannot go stale. Nothing here
 * is user supplied - no capability and no nonce is needed.
 */
add_action(
	'wp_footer',
	static function () {
		$menu = wp_nav_menu(
			array(
				'theme_location' => 'menu-2',
				'fallback_cb'    => false,
				'container'      => false,
				'menu_id'        => 'bookify-footer-menu',
				'echo'           => false,
			)
		);

		if ( ! $menu || ( function_exists( 'hello_show_or_hide' ) && 'hide' === hello_show_or_hide( 'hello_footer_menu_display' ) ) ) {
			$menu = '';
		}

		$legal = sprintf(
			/* translators: %1$s: the current year. %2$s: the site name. */
			__( '© %1$s %2$s. All rights reserved.', 'bookify-theme' ),
			wp_date( 'Y' ),
			get_bloginfo( 'name' )
		);

		echo '<div class="bookify-footer-bar">';

		if ( $menu ) {
			printf(
				'<nav class="bookify-footer-bar__nav" aria-label="%s">%s</nav>',
				esc_attr__( 'Footer menu', 'bookify-theme' ),
				$menu // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by wp_nav_menu().
			);
		}

		printf( '<p class="bookify-footer-bar__legal">%s</p>', esc_html( $legal ) );

		echo '</div>';
	},
	20
);
