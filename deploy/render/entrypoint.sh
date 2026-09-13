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

# 0. The database, when the instance has to be its own.
#
#    Render has never offered managed MySQL, and on the free plan there is no private service either —
#    Free instances exist only for web services, Postgres, Key Value and static sites. So the only
#    database a free instance can reach is one running inside it, and `BOOKIFY_LOCAL_DB=1` is what asks
#    for that: MariaDB is started here, on the ephemeral filesystem, and WordPress is pointed at it over
#    the loopback address. Left unset, none of this runs and WORDPRESS_DB_* means what it always meant.
#
#    **This database survives nothing.** The filesystem is wiped on every deploy, every restart and every
#    spin-down after fifteen idle minutes, so each cold start finds it empty and step 4 rebuilds the site
#    from Supabase. That is exactly why the mirror matters on this plan: it is the durable copy, and this
#    is a cache of it. A booking is lost only if it is written and the instance dies before the push at
#    shutdown — so on a free instance, treat Supabase as the record and this as scratch.
#
#    The password is generated per boot when none is supplied. The server listens on 127.0.0.1 inside a
#    single-tenant container, so it protects nothing from the internet — but `wp-config.php` reads the
#    value from the environment, which means any later `wp` invocation needs the *same* one. The deploy
#    therefore passes a value in; this fallback exists so a hand-run `docker run` still works.
if [ "${BOOKIFY_LOCAL_DB:-0}" = "1" ]; then
	export WORDPRESS_DB_HOST="127.0.0.1:3306"
	export WORDPRESS_DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
	export WORDPRESS_DB_USER="${WORDPRESS_DB_USER:-wordpress}"
	export WORDPRESS_DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)}"

	mkdir -p /run/mysqld /var/lib/mysql
	chown -R mysql:mysql /run/mysqld /var/lib/mysql

	if [ ! -d /var/lib/mysql/mysql ]; then
		echo "bookify: initialising the bundled database"
		mariadb-install-db --user=mysql --datadir=/var/lib/mysql \
			--auth-root-authentication-method=normal >/dev/null
	fi

	echo "bookify: starting the bundled database"
	mariadbd --user=mysql --datadir=/var/lib/mysql --bind-address=127.0.0.1 --port=3306 \
		--socket=/run/mysqld/mysqld.sock >/var/log/mariadb-bookify.log 2>&1 &

	# Waited on with a query rather than on the process: the process exists immediately and knows
	# nothing about whether the server is ready to answer.
	attempt=0
	while [ "${attempt}" -lt 90 ]; do
		if mariadb --protocol=socket --socket=/run/mysqld/mysqld.sock -u root \
			-e 'select 1' >/dev/null 2>&1; then
			break
		fi
		attempt=$(( attempt + 1 ))
		sleep 1
	done

	if ! mariadb --protocol=socket --socket=/run/mysqld/mysqld.sock -u root <<SQL
create database if not exists \`${WORDPRESS_DB_NAME}\`;
create user if not exists '${WORDPRESS_DB_USER}'@'%' identified by '${WORDPRESS_DB_PASSWORD}';
grant all privileges on \`${WORDPRESS_DB_NAME}\`.* to '${WORDPRESS_DB_USER}'@'%';
flush privileges;
SQL
	then
		echo "bookify: the bundled database did not accept its grants; see /var/log/mariadb-bookify.log"
		tail -20 /var/log/mariadb-bookify.log || true
	fi
fi

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

# 2b. Make the uploads directory writable by the user PHP actually runs as.
#
#    Measured on a booted instance: `wp-content`, `themes` and `plugins` all arrive owned by
#    www-data, Apache's six workers run as www-data, and **`wp-content/uploads` alone is root:root** —
#    so `is_writable()` is false for every web request while being true for the `wp` command, which
#    runs as root. The visible symptom was the on-demand image serving answering 404 and writing no
#    file at all: the mirror held the photograph, the attachment resolved, the size was present in the
#    row, and the write was refused. Uploads through wp-admin would fail identically, and on a paid
#    service the same directory is the disk.
#
#    It cannot be a step beside this one. The image's own entrypoint copies WordPress into the web root
#    *after* this point and that copy is what leaves the directory root-owned, so this waits for the
#    last thing that entrypoint writes — `wp-config.php` — and then corrects it. A subshell, so nothing
#    here delays Apache, and a recursive chown rather than a check of the directory itself, because a
#    subdirectory can be wrong while its parent reads correctly.
#
#    **It has to run twice, and that is not belt-and-braces.** `wp_upload_dir()` creates the year and
#    month directories the first time any `wp` command asks for one, and a directory created by root is
#    root-owned no matter who owns its parent. Measured: after the boot work finished, `uploads` was
#    `www-data` and `uploads/2026/09` was `root:root` — so a photograph restored on demand still could
#    not be written, with `is_writable()` on the uploads directory cheerfully reporting `true` the whole
#    time. Step 4 runs as root and runs after this, so it calls the same function again when it is done.
bookify_fix_uploads() {
	install -d -o www-data -g www-data -m 775 /var/www/html/wp-content/uploads
	chown -R www-data:www-data /var/www/html/wp-content/uploads
}

if [ "$(id -u)" = "0" ]; then
	(
		attempt=0
		while [ "${attempt}" -lt 60 ] && [ ! -s /var/www/html/wp-config.php ]; do
			attempt=$(( attempt + 1 ))
			sleep 2
		done

		bookify_fix_uploads
	) &
fi

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

		# The copy was taken from a site running at a different address, and WordPress stores that
		# address in far more places than the two options WP_HOME and WP_SITEURL override: inside
		# `post_content`, inside the serialised values in `postmeta` and `_elementor_data` — which is
		# where every Elementor image and background lives — and inside the generated Elementor CSS.
		#
		# Measured before this was added: with RENDER_EXTERNAL_URL set, a freshly restored site still
		# emitted **98 URLs pointing at http://localhost:8890 and none at the deployment**, so every
		# photograph on the live site would have been requested from the visitor's own machine. The
		# constants cannot fix that; only rewriting the stored values can.
		#
		# `wp search-replace` is the supported way to move a WordPress site, and it understands
		# serialised PHP — which is the whole reason this is a command and not an `UPDATE`. It is
		# skipped once the addresses agree, so it costs nothing on a boot that has nothing to do.
		stored_url="$(wp option get home --path=/var/www/html --allow-root 2>/dev/null || true)"

		if [ -n "${RENDER_EXTERNAL_URL:-}" ] && [ -n "${stored_url}" ] && [ "${stored_url}" != "${RENDER_EXTERNAL_URL}" ]; then
			echo "bookify: moving the site from ${stored_url} to ${RENDER_EXTERNAL_URL}"
			wp search-replace "${stored_url}" "${RENDER_EXTERNAL_URL}" --all-tables --precise \
				--path=/var/www/html --allow-root || true

			# **Then again, with the slashes escaped.** Elementor keeps its layouts as JSON, and JSON
			# may escape `/` as `\/`, so a button's address is stored as
			# `http:\/\/localhost:8890\/book\/` — a string containing no `http://localhost:8890` at
			# all. The pass above therefore cannot see it, however precise it is.
			#
			# Measured: the first pass reported "Made 101 replacements", every image moved to the
			# deployment, and two "Book now" buttons still pointed at the development host — found by
			# reading the stored value in `wp_postmeta` rather than trusting the success message.
			# `--precise` is kept here too: for a plain JSON string it is an ordinary replace, and for
			# genuinely serialised data it adjusts the length prefixes that a blind replace would
			# corrupt.
			escaped_stored="$(printf '%s' "${stored_url}" | sed 's|/|\\/|g')"
			escaped_live="$(printf '%s' "${RENDER_EXTERNAL_URL}" | sed 's|/|\\/|g')"
			wp search-replace "${escaped_stored}" "${escaped_live}" --all-tables --precise \
				--path=/var/www/html --allow-root || true
		fi

		if [ -n "${BOOKIFY_ADMIN_PASSWORD:-}" ] && [ -n "${BOOKIFY_ADMIN_USER:-}" ]; then
			wp user update "${BOOKIFY_ADMIN_USER}" --user_pass="${BOOKIFY_ADMIN_PASSWORD}" --path=/var/www/html --allow-root || true
		fi

		# And once more, because every command above ran as root and may have created upload
		# directories on the way — see the note beside the function. Verified end to end: with this
		# call in place, an image requested from a page is fetched from Supabase, written to disk
		# and served, from an uploads directory that started empty.
		bookify_fix_uploads
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
