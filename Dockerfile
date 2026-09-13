# Bookify, as a Render web service.
#
# Same base image the local site runs on: WordPress on PHP 8.3, with mysqli — the extension WordPress
# requires and which Supabase, being PostgreSQL, cannot satisfy. Nothing here is installed to reach
# Supabase: the image already has curl, openssl and json, which is everything the PostgREST client in
# includes/supabase/client.php needs.
#
# See docs/DEPLOY-RENDER.md for what this deploys, what it deliberately does not, and why the site
# still needs MySQL.
FROM wordpress:php8.3-apache

# WP-CLI, for the two things that are not a web request: seeding the mirror the first time
# (`wp bookify-supabase sync`) and moving the site's content into a fresh database. Pinned to a
# released PHAR rather than `latest`, so a redeploy cannot silently change the tool doing the work.
ARG WP_CLI_VERSION=2.11.0

# Four things, for four different reasons:
#
#   * `unzip`, for the plugin below;
#   * `default-mysql-client`, because `wp db import` shells out to the `mysql` binary, and without one in
#     the image the only way to load a database dump into a Render private service would be to expose
#     that database to the internet — which the private service exists to avoid;
#   * `pdo_pgsql`, because the mirror can write to Supabase over a database socket instead of its HTTPS
#     API (`includes/supabase/postgres.php`). The base image ships `mysqli` and `mysqlnd` and *no*
#     Postgres driver at all, so without this the direct path cannot even open a connection — it fails
#     with "could not find driver", which reads like a credentials problem and is not one.
#   * `mariadb-server`, which is the whole database on the free plan. Render has never offered managed
#     MySQL, no Free instance type exists for private services, and Render warns it may suspend a free
#     web service that talks to an external database at high volume — so on free, MariaDB runs here,
#     beside Apache, in the same 512 MB. It is started only when BOOKIFY_LOCAL_DB=1; with that unset
#     this package sits unused and the WORDPRESS_DB_* variables point wherever they are told to.
#
# `policy-rc.d` refuses service starts during the build: Debian's `mariadb-server` postinst would
# otherwise try to start a daemon in a container that has no init, and fail the layer.
RUN set -eux; \
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; \
    chmod +x /usr/sbin/policy-rc.d; \
	apt-get update; \
    apt-get install -y --no-install-recommends default-mysql-client unzip libpq-dev mariadb-server; \
	docker-php-ext-install pdo_pgsql; \
	rm -rf /var/lib/apt/lists/*

# MariaDB's defaults assume a server with memory to spare. The free instance has 0.1 CPU and 512 MB for
# MariaDB, Apache and PHP together, and the stock buffer pool alone is larger than that. Every value here
# is sized for a small site with one user, and the file is numbered so it loads after the distribution's
# own settings rather than being overwritten by them.
RUN set -eux; \
    mkdir -p /etc/mysql/mariadb.conf.d; \
    printf '%s\n' \
    '[mysqld]' \
    'innodb_buffer_pool_size = 48M' \
    'innodb_log_file_size = 16M' \
    'key_buffer_size = 8M' \
    'performance_schema = OFF' \
    'max_connections = 20' \
    'skip-name-resolve = 1' \
    'character-set-server = utf8mb4' \
    'collation-server = utf8mb4_unicode_520_ci' \
    > /etc/mysql/mariadb.conf.d/99-bookify-free.cnf; \
    cat /etc/mysql/mariadb.conf.d/99-bookify-free.cnf

RUN set -eux; \
	curl -fsSL -o /usr/local/bin/wp \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"; \
	chmod +x /usr/local/bin/wp; \
	wp --info --allow-root > /dev/null

# Elementor, which every page on this site is built with.
#
# It is not in git — locally it lives in the wp_data volume — so without this the deployed site would
# render every Elementor layout as shortcode soup. Pinned so the deploy reproduces the environment the
# design layer was measured in; `4.2.4` is stable if you would rather not ship a beta.
ARG ELEMENTOR_VERSION=4.3.0-beta2

RUN set -eux; \
	curl -fsSL -o /tmp/elementor.zip \
		"https://downloads.wordpress.org/plugin/elementor.${ELEMENTOR_VERSION}.zip"; \
	mkdir -p /usr/src/bookify/plugins; \
	unzip -q /tmp/elementor.zip -d /usr/src/bookify/plugins/; \
	rm /tmp/elementor.zip

# Hello Elementor, the parent of bookify-theme.
#
# The same problem as the plugin above and found the same way — by deploying. `bookify-theme` is a child
# theme, so the restored options say `template = hello-elementor`; the parent lived only in the wp_data
# volume, exactly as the Elementor plugin did. Without it a fresh deploy restores the entire site, serves
# it, and answers every page with `200` and **0 bytes**, because the active theme cannot be loaded and
# WordPress has nothing to render with.
#
# Pinned to the version this site's design layer was measured against, and installed as a *theme* rather
# than merged into the child: the child's `style.css` header names this parent, and the parent supplies
# the templates, the header and the footer that the child deliberately does not override.
ARG HELLO_ELEMENTOR_VERSION=3.5.1

RUN set -eux; \
	curl -fsSL -o /tmp/hello-elementor.zip \
		"https://downloads.wordpress.org/theme/hello-elementor.${HELLO_ELEMENTOR_VERSION}.zip"; \
	mkdir -p /usr/src/bookify/themes; \
	unzip -q /tmp/hello-elementor.zip -d /usr/src/bookify/themes/; \
	rm /tmp/hello-elementor.zip

# The project's own code, staged outside the web root and copied into place on every start by the
# entrypoint below. The theme and the plugin are the only parts of wp-content that live in git.
COPY deploy/render/entrypoint.sh /usr/local/bin/bookify-entrypoint.sh
COPY wp-content/themes/bookify-theme /usr/src/bookify/themes/bookify-theme
COPY wp-content/plugins/bookify-booking /usr/src/bookify/plugins/bookify-booking

RUN chmod +x /usr/local/bin/bookify-entrypoint.sh

# The image's own entrypoint, wrapped. Everything it does — generating wp-config.php from the
# WORDPRESS_DB_* variables, copying WordPress into an empty web root — still has to happen.
ENTRYPOINT ["/usr/local/bin/bookify-entrypoint.sh"]
CMD ["apache2-foreground"]
