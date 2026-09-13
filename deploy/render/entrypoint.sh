#!/bin/sh
#
# Container entrypoint for the Render deploy.
#
# Four steps, all of which have to happen on every start because a Render instance's filesystem is
# ephemeral — only the attached disk survives a deploy. The order matters, and the reason for it is
# written against each step below:
#
#   1. normalise the database host into the `host:port` shape WordPress wants
#   2. put the project's own code where WordPress expects it
#   3. make Apache listen on the port Render routes to
#   4. hand over to the image's own entrypoint, which installs WordPress and writes wp-config.php
#
# Only the project's own theme and plugin are copied. Anything installed through wp-admin — Elementor,
# for instance — lives on the persistent disk under wp-content and is deliberately left alone, because
# a redeploy is not supposed to undo what the owner did in the admin.
#
# @package bookify-booking

set -e

# 1. Normalise the database host first, because the step after this one is what writes wp-config.php
#    and it reads this variable.
#
#    WordPress wants WORDPRESS_DB_HOST as `host:port`, and Render can hand over the private hostname on
#    its own. The Blueprint supports no string interpolation, and a `fromService` reference to
#    `hostport` is the service's *HTTP* port — not the one MariaDB is on — so the port is appended here.
case "${WORDPRESS_DB_HOST:-}" in
	"") ;;
	*:*) ;;
	*) export WORDPRESS_DB_HOST="${WORDPRESS_DB_HOST}:3306" ;;
esac

# 2. The project's own code, into the web root the step below will fill with WordPress.
#
#    Only staged code is copied: this project's theme and plugin, plus Elementor, which the pages are
#    built with and which is not in git. Everything installed through wp-admin instead — anything not in
#    the image — is lost on redeploy, because plugins and themes live in the image rather than on the
#    disk. The disk holds content: media, and Elementor's generated CSS. Treat the image as the source
#    of truth for code and update the Dockerfile's plugin versions to update it.
#
#    Doing this before the image's entrypoint runs is safe: that entrypoint warns about a web root
#    holding anything *besides* wp-content (it checks
#    `find -mindepth 1 -maxdepth 1 -not -name wp-content`), and everything here goes inside wp-content.
install -d /var/www/html/wp-content/themes /var/www/html/wp-content/plugins

cp -R /usr/src/bookify/themes/.  /var/www/html/wp-content/themes/
cp -R /usr/src/bookify/plugins/. /var/www/html/wp-content/plugins/

chown -R www-data:www-data /var/www/html/wp-content/themes /var/www/html/wp-content/plugins

# 3. Apache is built to listen on 80; Render assigns the service a port and routes to that. Without this
#    the service starts cleanly, passes its health check against nothing, and answers no requests — which
#    reads as a broken application rather than as a wrong port.
if [ -n "${PORT:-}" ] && [ "${PORT}" != "80" ]; then
	sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
	sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
	echo "Apache listening on ${PORT}"
fi

# 4. Make the site's content exist, beside step 5 rather than before it.
#
#    This is the step that makes the free plan viable. A free web service has no Shell and no
#    `render ssh`, so there is no way to get a database dump in, and a fresh service comes up with no
#    pages, no layouts and none of the sessions. With the site's own tables in Supabase it can: the
#    content is published to `bookify_content` from a machine that has the database, and a container
#    starting against an empty one restores it.
#
#    **Three things, in this order, and each one needs the one before.**
#
#    (a) Wait for `wp-config.php`. Until step 5 has written it, `wp` cannot talk to the database at all —
#        and `wp core is-installed` answers "not installed" both for an empty database and for a missing
#        wp-config, so waiting on that alone would sit here for the full timeout on an empty one.
#    (b) Install WordPress if it is not there. The image's own entrypoint writes wp-config.php and never
#        installs anything, so an empty database stays empty and the service answers with the setup
#        wizard. `wp core install` creates the twelve core tables — which the restore below needs, because
#        there is nothing to restore *into* until they exist — and one throwaway administrator.
#    (c) Restore, then set the administrator's password. The copy deliberately carries no password
#        hashes, so the account it restores cannot be logged into until a password is set — and with no
#        Shell on a free instance this is the only place that can happen.
#
#    `--if-empty` is what makes (c) safe to leave switched on: on an instance whose content is already
#    there it reads a marker, writes nothing, and exits. Everything is `|| true` and in a subshell,
#    because a mirror that cannot reach Supabase must not be the reason a site does not start, and
#    nothing may delay Apache.
if [ "${BOOKIFY_CONTENT_RESTORE:-1}" = "1" ] && [ -n "${BOOKIFY_SUPABASE_DB_URL:-}" ]; then
	(
		attempt=0

		while [ "${attempt}" -lt 60 ] && [ ! -s /var/www/html/wp-config.php ]; do
			attempt=$(( attempt + 1 ))
			sleep 2
		done

		if ! wp core is-installed --path=/var/www/html --allow-root >/dev/null 2>&1; then
			echo "bookify: the database is empty — creating WordPress's own tables"

			# The password is random and thrown away: the real account arrives with the restore below and
			# overwrites this row, so this one only has to satisfy the command. It is never printed.
			wp core install --path=/var/www/html --allow-root \
				--url="${RENDER_EXTERNAL_URL:-${WORDPRESS_SITE_URL:-http://localhost}}" \
				--title="${WORDPRESS_SITE_TITLE:-Bookify}" \
				--admin_user="${BOOKIFY_ADMIN_USER:-admin}" \
				--admin_email="${BOOKIFY_ADMIN_EMAIL:-admin@example.com}" \
				--admin_password="$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | cut -c1-24)" \
				--skip-email || true

			# A fresh install has no active plugins, so the restore below would not exist yet — the
			# `bookify-supabase` command is registered by the plugin, and an inactive plugin registers
			# nothing. Elementor is activated for the same reason and a stronger one: every page in the
			# copy is an Elementor layout, and without it the content arrives and renders as shortcodes.
			wp plugin activate bookify-booking elementor --path=/var/www/html --allow-root || true
		fi

		echo "bookify: restoring the site's content from Supabase"
		wp bookify-supabase content restore --if-empty --path=/var/www/html --allow-root || true

		if [ -n "${BOOKIFY_ADMIN_PASSWORD:-}" ] && [ -n "${BOOKIFY_ADMIN_USER:-}" ]; then
			wp user update "${BOOKIFY_ADMIN_USER}" --user_pass="${BOOKIFY_ADMIN_PASSWORD}" --path=/var/www/html --allow-root || true
		fi
	) &
fi

# 5. Hand over to the image's own entrypoint, which is what installs WordPress into an empty web root
#    and writes wp-config.php from the WORDPRESS_DB_* variables normalised in step 1.
#
#    **The command has to stay `apache2-foreground`.** That entrypoint gates every part of its setup —
#    the WordPress copy, the wp-config generation, the wp-content ownership fix — on `$1` matching
#    `apache2*` or `php-fpm`; with any other command it goes straight to `exec "$@"` and the site comes
#    up as an empty web root. Verified by handing it `true` and watching it install nothing.
#
#    It also has to come last: it ends in `exec`, so nothing after this line ever runs. The subshell
#    started above survives the `exec` — it is a separate process, and it is deliberately not waited on.
exec docker-entrypoint.sh "$@"
