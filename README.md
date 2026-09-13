# Bookify

A self-hosted booking and ticketing site for a small practice — therapists, coaches, personal
trainers, small studios. A visitor reads what the business offers, picks a session, sees the times
that are actually free, books one, pays a deposit or the full price, cancels or moves it from the
link in their confirmation email, and gets a reminder before it happens. It also sells places at
dated **events**, priced and limited by **ticket tiers**.

It is **WordPress + one plugin + one child theme**. Nothing is bought: WordPress core, free
Elementor, Hello Elementor as the parent theme, and this repository's own PHP.

- Every booking behaviour is PHP in `wp-content/plugins/bookify-booking/`.
- Everything visual is in `wp-content/themes/bookify-theme/` and in Elementor layouts stored in
  the database.
- It runs locally in Docker through the `wpdev` kit → **http://localhost:8890**
- It deploys to Render from `Dockerfile` plus one of two Blueprints.
- It mirrors its bookings, its content and its images to **Supabase (PostgreSQL)** — one direction
  only, so Supabase can be down and the site still takes bookings.

## Contents

- [What it does](#what-it-does)
- [The stack](#the-stack)
- [Repository layout](#repository-layout)
- [Running it locally](#running-it-locally)
- [URLs on the site](#urls-on-the-site)
- [wp-admin](#wp-admin)
- [Configuration](#configuration)
- [WP-CLI reference](#wp-cli-reference)
- [HTTP API](#http-api)
- [Shortcodes and Elementor widgets](#shortcodes-and-elementor-widgets)
- [Data model](#data-model)
- [The Supabase mirror](#the-supabase-mirror)
- [Deploying to Render](#deploying-to-render)
- [Verifying a change](#verifying-a-change)
- [Rules for changing this repository](#rules-for-changing-this-repository)
- [Before this goes live](#before-this-goes-live)
- [Things that look like errors but are not](#things-that-look-like-errors-but-are-not)
- [Documentation](#documentation)

---

## What it does

Each row names the file that implements it, so a feature can be found from the feature list.

| Feature | What it means | Where |
| --- | --- | --- |
| Booking form | Session, date, time, name, email, phone. Renders from one Elementor widget, every label and message an editable control. Books with JavaScript off. | `includes/booking-form.php`, `assets/booking-form.{css,js}` |
| Live calendar | Picking a day loads that day's free times without a page reload. Read-only endpoint, no side effects. | `includes/rest-slots.php` |
| Availability rules | Weekdays and hours, slot interval, lead time, how far ahead to sell, blocked dates. Stored in one option. | `includes/availability.php` |
| Capacity | A session's `bookify_capacity` is the people that fit in one slot; an event has its own ceiling and each tier has another. Enforced inside a MySQL advisory lock, so two simultaneous bookings cannot both take the last place. | `includes/bookings.php`, `includes/events.php` |
| Emails | Confirmation, manage link, cancellation, reminder, event summary. Table-based HTML with inline styles, no web font, light-only. | `includes/emails.php`, `includes/mailer.php` |
| Resend transport | Sends through Resend's HTTP API when a key and a sender are configured; calls `wp_mail()` unchanged when they are not. | `includes/mailer.php` |
| Reminders | One email before the session, on by default, lead time editable. Rides `wp_schedule_single_event` on the `bookify_booking_reminder` hook. | `includes/reminders.php` |
| Customer self-service | The customer cancels or moves their own booking from a signed, expiring link — a token compared with `hash_equals()`, never a post id in the URL. | `includes/manage-booking.php` |
| Accounts (opt-in) | A customer may create a `subscriber` account and see their own bookings. Never matched by email address to an existing user. | `includes/customer-accounts.php` |
| Payments | Stripe Checkout called directly with `wp_remote_post`. No card data reaches this server, and no plugin is installed for it. Off unless keys are present. | `includes/payments/stripe.php`, `includes/payments/amounts.php` |
| Payment webhook | Signature-verified REST endpoint. It is the webhook — not the browser returning — that marks a booking paid. | `includes/payments/webhook.php` |
| Bot protection | Cloudflare Turnstile, off by default, plus per-IP and per-email rate limits. | `includes/bot-protection.php` |
| Session pages | `/sessions/<slug>/` — header, title, price and length, the description, one **Book now** button. No form on the page. | `single-bookify_service.php` |
| Session list | Every published session, sorted by title. No limit or ordering control; a session is added by adding content. | `includes/service-list.php` |
| Events and tiers | A dated event with places, and tiers that price and limit a ticket. | `includes/events.php`, `includes/event-tickets.php`, `includes/event-fields.php` |
| Event summary | One email to the operator after bookings close: how many places went, to whom, and what is left. | `includes/event-summary.php` |
| Structured data | LocalBusiness site-wide and `Service` on session pages, emitted by the plugin from the stored options. No SEO plugin. | `includes/schema.php` |
| 404 page | Overrides `hello-elementor/template-parts/404.php` in the child theme, and answers a real `404`. | `template-parts/404.php` |
| Footer | Business details printed on `wp_footer`, plus a legal bar carrying the footer menu and a copyright whose year is `wp_date('Y')`. | `includes/business-details.php`, `functions.php` |
| Design layer | Colour, type, space, radius, shadow and motion tokens in one block, in both colour schemes. | `style.css` |
| Supabase mirror | Bookings, content and images published to Postgres; the site restores itself from the copy on a host with no Shell. | `includes/supabase/` |

## The stack

| | Version | Notes |
| --- | --- | --- |
| WordPress | 7.1 | Core is in a named volume locally, copied in on first start on Render |
| PHP | 8.3.33 | `wordpress:php8.3-apache`; `mysqli` (WordPress needs it) and `pdo_pgsql` (the mirror needs it) |
| MariaDB | 11.8.9 | `mariadb:11` container locally |
| Elementor | 4.3.0-beta2 | Pinned in the `Dockerfile`. It is **not** in git — locally it lives only in the `wp_data` volume, so without the pin a fresh deploy would render every layout as shortcode soup |
| Hello Elementor | 3.5.1 | The parent theme, pinned for the same reason: the child names it in `style.css`, and without it every page answers `200` with 0 bytes |
| `bookify-theme` | 0.6.1 | Version in `style.css`; `?ver=` is the theme's only cache-buster for CSS |
| `bookify-booking` | 0.6.4 | `BOOKIFY_BOOKING_VERSION`, the only cache-buster for the plugin's assets |
| `wp-agent-bridge` | — | Not in this repo. Mounted from the kit into `wp-content/plugins/`, so one edit affects every project |

PHP extensions the code needs beyond the base image: `pdo_pgsql` (direct Postgres), `mysqli`
(everything). There is **no** Postgres driver in the stock WordPress image — the `Dockerfile` adds
`pdo_pgsql`.

## Repository layout

```
CLAUDE.md                     the operating manual for an agent working in this repo
docker-compose.yml            the local stack: db, wordpress, cli (mounted code)
Dockerfile                    the Render image: WP-CLI, MySQL client, pdo_pgsql, MariaDB, pinned plugins
render.yaml                   Render Blueprint, FREE plan: web service only
render.production.yaml        Render Blueprint, the live shape: web + MariaDB + disk + cron
deploy/render/entrypoint.sh   image entrypoint: copy code in, install core, restore content, start Apache
deploy/render/create-service.sh  creates the Render service from .env via the Render CLI
docs/                         plan, tasks, local setup, Render deploy, prompt recipes
wp-content/themes/bookify-theme/
    style.css                 the design layer: tokens, components, both colour schemes
    functions.php             assets, fonts, tab icon, footer legal bar — presentation only
    single-bookify_service.php  one session
    single-bookify_event.php    one event and its ticket form
    template-parts/404.php    the not-found page
    assets/icon-32.png, icon-180.png   the tab icon, shipped as files rather than as a setting
wp-content/plugins/bookify-booking/
    bookify-booking.php       bootstrap: version, requires, the three Elementor widgets
    includes/                 see the table below
    includes/payments/        amounts.php, stripe.php, webhook.php
    includes/supabase/        transform.php, client.php, postgres.php, content.php, media.php, sync.php, cli.php, schema.sql
    assets/                   booking-form.{css,js}, service-list.css, manage-booking.css,
                              business-details.css, event-tickets.js
    _probes/                  re-runnable scripts, run with `wp eval-file`; not autoloaded
    _probe-stage2.php         a scratch script, same rule
```

`includes/`, file by file:

| File | Responsibility |
| --- | --- |
| `post-types.php` | `bookify_service` (labelled **Sessions**, rewrite slug `sessions`) and `bookify_booking` (**Bookings**). Registers every meta key for both. |
| `bookings.php` | The write path: create, cancel, reschedule, reference numbers, `GET_LOCK()` around check-and-insert. |
| `availability.php` | The diary. Defaults: Mon–Fri 09:00–17:00, Sat 10:00–14:00, Sun closed, 30-minute interval, 2-hour lead time, 60 days ahead. |
| `availability-cache.php` | Caches `bookify_available_days()` / `bookify_available_slots()` only. `bookify_slot_remaining()` and `bookify_slot_is_offered()` must stay uncached — the write path decides capacity with them. |
| `rest-slots.php` | `GET /wp-json/bookify/v1/slots`. |
| `booking-form.php` | `[bookify_booking_form]`, the request handler, and the notices reached with `?bookify=booked` / `?bookify=rejected`. |
| `manage-booking.php` | `[bookify_manage_booking]` — cancel and reschedule by token. |
| `customer-accounts.php` | `[bookify_my_bookings]` and the opt-in account offer. |
| `bot-protection.php` | `bookify_bots` option, Turnstile verification, rate limits. |
| `emails.php` | Composes every message. |
| `mailer.php` | Chooses the transport and is the single choke point for every send. |
| `reminders.php` | `bookify_reminders` option and the scheduled reminder. |
| `admin-bookings.php` | The Bookings list, its columns and its bulk status changes. |
| `service-fields.php` | The Session edit screen. |
| `service-list.php` | `[bookify_services]`, and the data the Session list widget prints. |
| `schema.php` | The JSON-LD, from the stored options. |
| `business-details.php` | `bookify_business` option, the `<address>` block, `[bookify_business_details]`. |
| `settings.php` | The **Bookings → Settings** screen: availability, reminders, payments, email read-out. |
| `events.php` | `bookify_event` and `bookify_tier`, the event's own meta, and live place counts. |
| `event-fields.php` | The Event and Ticket tier edit screens. |
| `event-tickets.php` | The tier form and `[bookify_event_tickets]`. |
| `event-summary.php` | The one email the operator gets after an event's bookings close. |
| `elementor-widget.php` | The three widgets, in an Elementor category named **Bookify**. |

## Running it locally

The project is managed by `wpdev`, a script in a sibling kit. It may not be on `PATH` in a
non-login shell, so use the absolute path.

```bash
# start the stack (data is kept between runs)
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev up

# is it up, and on which port?
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev status

# end-to-end check of the whole MCP chain
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev smoke

# WP-CLI inside the container
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev wp plugin list
```

| Command | Purpose |
| --- | --- |
| `wpdev status` | Container status |
| `wpdev up` / `down` | Start / stop. Volumes keep the data. |
| `wpdev restart` | Recreate the containers. **This is what re-reads `.env`.** |
| `wpdev destroy` | Stop and delete this project's volumes |
| `wpdev wp <args…>` | WP-CLI, e.g. `wpdev wp post list --post_type=bookify_service` |
| `wpdev shell` | Shell inside the cli container |
| `wpdev smoke` | End-to-end MCP test — ten checks, cleans up after itself |
| `wpdev creds [--rotate]` | Print or regenerate the MCP credentials |
| `wpdev add plugin <slug>` / `add theme <slug>` | Create, mount and (for plugins) activate |
| `wpdev logs` | Follow the WordPress logs |

**Editing `wp-content/themes/**` or `wp-content/plugins/**` takes effect immediately** — both are
bind-mounted into the container. Nothing needs rebuilding.

`wp-content/uploads/`, WordPress core and third-party plugins are **not** in the container's bind
mounts: core and Elementor live in the `wp_data` volume, uploads in `wp-content/uploads/`.

## URLs on the site

Permalinks are `/%postname%/`. As of 2026-09-13 the site has these pages:

| Path | Page id | Holds |
| --- | --- | --- |
| `/` | 111 (`page_on_front`) | Front page, built with Elementor |
| `/sessions/` | 95 | The session listing (Session list widget) |
| `/sessions/<slug>/` | — | One session: header, price, length, description, one **Book now** button |
| `/book/` | 22 | The booking form |
| `/manage-booking/` | 214 | Cancel or move a booking, by token from the email |
| `/my-bookings/` | 228 | The signed-in customer's bookings |
| `/contact/` | 116 | Contact page, with the business details inline |
| `/events/<slug>/` | — | One event and its ticket form |
| `/booking-form-widget/` | 31 | A draft page kept for working on the form |

There is no `page_for_posts`, so the blog index is not part of the site.

## wp-admin

`http://localhost:8890/wp-admin/` — the credentials are in `.env` on this machine, `admin` /
`password` by default.

| Menu | What is there |
| --- | --- |
| **Sessions** | The catalogue. Each session's price, length, capacity and payment mode are fields on its edit screen. |
| **Bookings** | Every booking, with its reference, status and session. **Bookings → Settings** (`edit.php?post_type=bookify_booking&page=bookify-settings`) is the one settings screen: business details, availability, reminders, payments, and a read-out of the email transport. |
| **Events** | Dated events, with **Ticket tiers** nested underneath because a tier is meaningless on its own. |

The settings screen is the only place placeholder data should be replaced — see
[Before this goes live](#before-this-goes-live).

## Configuration

Configuration is environment variables in `.env` (gitignored), read by the plugin in the order
**constant → environment → filter**. Compose interpolates `.env` into the service spec, and each
service's `environment:` block is what PHP's `getenv()` actually sees — a variable that is in
`.env` but not in `docker-compose.yml`'s `x-wordpress-env` anchor **never reaches PHP**. That is a
trap this project has been bitten by, so both places are edited together.

Every optional feature is **off when its variable is empty**, and the site behaves as it did before
that feature existed.

| Variable | Enables | Notes |
| --- | --- | --- |
| `RESEND_API_KEY` / `BOOKIFY_RESEND_API_KEY` | Sending through Resend instead of `wp_mail()` | Begins `re_`. Never commit it. |
| `BOOKIFY_MAIL_FROM` / `RESEND_FROM` | The sender address | Must be on a domain verified in Resend, or Resend refuses every recipient but the account owner |
| `BOOKIFY_MAIL_FROM_NAME` | The sender's display name | |
| `BOOKIFY_MAIL_REDIRECT_TO` | Sends **every** message to this one inbox | A testing switch. Subject becomes `[from <client>] <original>`, the client-only sentences and the manage button are dropped, and a `Client email` row is added. Empty = off, and off is what a live site wants. |
| `BOOKIFY_SUPABASE_URL` + `BOOKIFY_SUPABASE_KEY` | The mirror over PostgREST | The key is a `service_role` credential — a full-database one. Left unset here. |
| `BOOKIFY_SUPABASE_DB_URL` + `BOOKIFY_SUPABASE_DB_PASSWORD` | The mirror over a direct Postgres socket | Takes precedence when set. Use the **Session pooler** host (`aws-0-<region>.pooler.supabase.com`), not `db.<ref>.supabase.co`, which is IPv6-only |
| `BOOKIFY_SUPABASE_DB_SSLMODE` | TLS mode for that socket | Default `require`; `disable` is for a local server only |
| `BOOKIFY_SUPABASE_MEDIA_MAX_BYTES` | Ceiling on one image pushed to the mirror | Default 5 MB; `0` switches the ceiling off |
| `BOOKIFY_SUPABASE_RECONCILE_HOOK` | Daily reconciliation of the mirror | Corrects a failed push with no queue and no retry state. It never prunes. |
| `BOOKIFY_LOCAL_DB` | On Render, run MariaDB inside the instance | The free plan's database. Off locally. |
| `BOOKIFY_CONTENT_RESTORE` | On Render, restore the site's tables at boot | Default on when a Supabase URL is set |

Secrets never live in the database, and the mirror deliberately does **not** publish them: Stripe's
`secret_key` and `webhook_secret`, Turnstile's `secret_key`, `wp_users.user_pass`,
`wp_usermeta.session_tokens`, and the two booking tokens. The mirror publishes
`stripe_configured` / `turnstile_configured` booleans instead.

`.env` also holds the kit's own names (`PROJECT_NAME`, `PROJECT_THEME`, `WP_PORT`, admin
credentials) and the Render deploy's `WORDPRESS_DB_*`, which belong in the gitignored
`deploy/render/.env.render` instead: the local database is the kit's container and a live one is
somebody else's server.

## WP-CLI reference

The mirror has a command of its own. Everything else is core WP-CLI.

```bash
wp bookify-supabase status

wp bookify-supabase sync [--dry-run] [--prune] [--table=<name>]…
wp bookify-supabase content status|push|restore [--table=<name>]… [--dry-run] [--prune] [--if-empty]
wp bookify-supabase media status|push|verify|restore [--force] [--id=<id>]…

wp post list --post_type=bookify_service
wp post meta list <booking-id>
```

| Command | Does |
| --- | --- |
| `status` | Which transport is in use, and what the last sync did |
| `sync` | Publishes the booking domain — sessions, events, tiers, customers, bookings, and optionally media. Idempotent: every write is an upsert keyed on the WordPress post id |
| `sync --dry-run` | Builds the rows and reports them without sending. Needs no Supabase credentials |
| `sync --prune` | Also removes rows for posts that no longer exist. Never runs on its own, and never while writes are failing |
| `content status\|push\|restore` | The site's own tables, one row per row: pages, Elementor layouts, menus, options |
| `content restore --if-empty` | The gate the container's start-up uses. It restores only when the site has no content WordPress did not create itself |
| `media status\|push\|verify\|restore` | The images. `verify` decodes every stored file and hashes it against disk without writing — the difference between "the row arrived" and "the photograph arrived" |

A failed run exits non-zero, and a failed sync skips the prune. On-demand image serving, not
`restore`, is the normal path: a missing uploads file becomes a 404 that the plugin catches on
`template_redirect` at priority 0, fetches from Supabase, writes to disk and redirects.

## HTTP API

| Route | Method | Auth | Purpose |
| --- | --- | --- | --- |
| `/wp-json/bookify/v1/slots?service=<id>&date=YYYY-MM-DD` | `GET` | Public, read-only | The times the form may offer for one session on one day. It answers only with times the same page already shows. |
| `/wp-json/bookify/v1/stripe-webhook` | `POST` | Stripe signature | The only writer of a payment's outcome. The permission callback is open because the caller is Stripe; the signature is the guard, and it is checked before anything is read or written. |
| `/wp-json/mcp/mcp-adapter-default-server` | `POST` | Application password | The MCP endpoint the agent tools speak to |
| `/wp-json/elementor/mcp` | `POST` | Basic auth | Elementor's own MCP server (Elementor 4.3+) |

## Shortcodes and Elementor widgets

| Shortcode | Renders |
| --- | --- |
| `[bookify_booking_form]` | The booking form |
| `[bookify_services]` | Every published session, sorted by title |
| `[bookify_manage_booking]` | Cancel or move one booking, by token |
| `[bookify_my_bookings]` | The signed-in customer's bookings |
| `[bookify_event_tickets]` | An event's ticket form |
| `[bookify_business_details]` | The address, phone, email and opening hours block |

| Widget (Elementor panel: **Bookify**) | Is |
| --- | --- |
| **Booking form** (`bookify_booking_form`) | The shortcode above, with every visible label, price and message an editable control |
| **Session list** (`bookify_service_list`) | The catalogue. It renders **every** published session — there is no limit or ordering control |
| **Ticket form** (`bookify_event_tickets`) | An event's tiers |

The widgets are registered on `elementor/widgets/register`, and are only loaded when Elementor is
active, because their classes extend Elementor classes.

## Data model

Four post types, all registered by the plugin. There are **no plugin-owned tables** — the whole
domain is posts, `bookify_*` meta, options and the users table.

| Post type | Public | Label | Rewrite |
| --- | --- | --- | --- |
| `bookify_service` | queryable | **Sessions** | `/sessions/<slug>/` |
| `bookify_event` | queryable | **Events** | `/events/<slug>/` |
| `bookify_tier` | no | **Ticket tiers** | nested under Events in wp-admin |
| `bookify_booking` | no | **Bookings** | — |

Meta on `bookify_service`: `bookify_duration`, `bookify_price`, `bookify_capacity`,
`bookify_payment_mode` (`none` / `deposit` / `full`), `bookify_deposit_type`, `bookify_deposit_value`.

Meta on `bookify_event`: `bookify_event_date`, `bookify_event_start`, `bookify_event_end`,
`bookify_event_capacity` (`0` = no ceiling of its own, so its tiers decide).
Meta on `bookify_tier`: `bookify_event_id`, `bookify_tier_price`, `bookify_tier_capacity`.

Meta on `bookify_booking`: `bookify_service_id` *or* `bookify_event_id` + `bookify_tier_id` (never
both kinds), `bookify_customer_name` / `_email` / `_phone`, `bookify_date`, `bookify_time`,
`bookify_party_size`, `bookify_status` (`awaiting_payment`, `pending`, `confirmed`, `expired`,
`cancelled`), `bookify_reference`, `bookify_cancel_token`, `bookify_manage_token`,
`bookify_manage_expires`, `bookify_previous_slot`, `bookify_customer_user`, `bookify_reminder_sent`,
and the payment group: `bookify_payment_status` (`unpaid`, `paid`, `failed`, `refunded`),
`bookify_payment_amount`, `bookify_payment_due`, `bookify_payment_reference`, `bookify_payment_note`.

`bookify_cancel_token`, `bookify_manage_token` and `bookify_payment_reference` are registered with
`show_in_rest => false`: they are bearer secrets and identifiers, not fields to read over HTTP.

Options:

| Option | Holds |
| --- | --- |
| `bookify_business` | `name`, `street`, `locality`, `postcode`, `country`, `phone`, `email` — the one source for the footer, the Contact page and the published JSON-LD |
| `bookify_availability` | `weekdays` (open/from/to per day), `interval`, `lead_hours`, `move_hours`, `days_ahead`, `blocked_dates` |
| `bookify_reminders` | `enabled`, `hours_before` |
| `bookify_payments` | `currency`, `secret_key`, `webhook_secret`, `expiry_minutes` |
| `bookify_bots` | Turnstile keys and the rate limits |
| `bookify_mail_last_result` | The last send's outcome, not autoloaded |
| `bookify_supabase_last_result` | The last mirror push's outcome |

A booking's reference is also its post title (`BK-00271`), which is why the Bookings list can sort
by it.

## The Supabase mirror

The mirror is **one direction only**: WordPress watches its own writes and republishes rows, and
nothing ever reads Supabase back. A broken mirror cannot change an answer the site gives — a
booking is still taken and its place still consumed, and the failure is recorded in
`bookify_supabase_last_result`.

Rows are built at `shutdown`, never when the write happens: `wp_insert_post()` fires `save_post`
*before* the plugin writes the ~20 meta values that make a booking a booking, so a transform run
there would publish a row of nulls.

| Table | Built from |
| --- | --- |
| `bookify_settings` | The booking options, as one row of real columns, with opening hours and blocked dates as `jsonb` |
| `bookify_services` | `bookify_service` posts |
| `bookify_events` | `bookify_event` posts |
| `bookify_tiers` | `bookify_tier` posts |
| `bookify_customers` | WordPress accounts, from a **named column list**, never `SELECT *` |
| `bookify_bookings` | `bookify_booking` posts |
| `bookify_media` | The image files, base64 in a `jsonb` column, keyed on the attachment id |
| `bookify_content` | One row per row of every prefixed WordPress table, `table_name` / `row_id` / `data jsonb` |

The schema deliberately declares **no CHECK constraints** on enumerated columns: the sets are
defined in PHP and they grow, and a CHECK would turn "the site learned a new status" into "the
mirror silently rejected a batch". `includes/supabase/schema.sql` is applied as one transaction, so
a single ordering mistake rolls the whole file back.

Why there is a mirror at all: Render has no managed MySQL, and on the free plan there is **no
Shell**, so there is no dump to import. `bookify_content` is what lets a fresh instance rebuild
itself — see [Deploying to Render](#deploying-to-render).

## Deploying to Render

**WordPress requires MySQL or MariaDB. Supabase is PostgreSQL, so Supabase can never be WordPress's
database** — `wpdb` is `mysqli` and core's SQL is MySQL dialect. That is why the site keeps a MySQL
database and Supabase gets a transformed copy.

Two Blueprints, and they answer different questions:

| File | For | Has |
| --- | --- | --- |
| `render.yaml` | Trying the deploy on the **free** plan | One web service, `plan: free`, an external MySQL prompted as secrets, no disk, no cron. `DISABLE_WP_CRON` is deliberately unset so a page view can still run scheduled work. |
| `render.production.yaml` | A live booking business | Web service + MariaDB private service + disk + a cron job that hits `wp-cron.php`, plus Shell access to import a dump |

The free plan cannot host this site *as a business*: no disk (uploads are wiped on every restart
and spin-down), no cron (so reminders and summaries only run if a page view happens to arrive), and
no Shell. The image compensates where it can — the mirror carries the images and the content, and
`BOOKIFY_LOCAL_DB=1` runs MariaDB beside Apache — but Render's own documentation says of free
instances: *"Do not use them for production applications."*

The `Dockerfile` pins everything a fresh host cannot get from git: WP-CLI, Elementor, Hello
Elementor. `deploy/render/entrypoint.sh` copies the theme and plugin into the web root, waits for
`wp-config.php`, installs WordPress when the database is empty, activates the plugins, runs
`content restore --if-empty`, and sets the administrator's password from `BOOKIFY_ADMIN_*`.

To create the service without ever typing a secret into a shell:

```bash
render login
render workspace set
printf 'WORDPRESS_DB_HOST=…\nWORDPRESS_DB_USER=…\nWORDPRESS_DB_PASSWORD=…\n' > deploy/render/.env.render
deploy/render/create-service.sh --dry-run   # the plan, every value masked
deploy/render/create-service.sh             # create it, print the ids back
```

`render login` is a browser flow and is yours to run. Render's GitHub App must also have access to
the repository; a public repo does not remove that requirement.

## Verifying a change

The project's standard is **evidence, not assertion**. A claim without a captured number gets
rejected.

```bash
# 1. the whole MCP chain — ten checks, through the real endpoint
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev smoke

# 2. syntax, per changed PHP file
php -l wp-content/plugins/bookify-booking/includes/bookings.php

# 3. syntax, for changed JavaScript
node --check wp-content/plugins/bookify-booking/assets/booking-form.js

# 4. the probes that cover the area that changed
/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage7-booking.php
```

`wpdev smoke` is the baseline and should read 10/10: create application password, MCP initialize,
abilities exposed over MCP, `get-environment-info`, `create-elementor-page`, `render-layout-recipe`,
`get-page-structure`, `update-widget`, `create-post`, and the front end rendering the page it made.

The `_probes/` directory holds re-runnable scripts, one per stage of the build — availability,
payments, reminders, the mirror and so on (`_probes/stage5-webhook.php`,
`_probes/stage8-reminders.php`, `_probes/supabase-failure.php`, …). They must live in the plugin
directory: **`/tmp` is not visible to the container**, and `wp eval-file` inherits the container's
environment, so a probe that asserts a message's recipient has to clear
`BOOKIFY_MAIL_REDIRECT_TO` itself.

For anything visual, look at the rendered site instead of assuming it — screenshots at 1440, 768
and 390 px, with `scrollWidth === innerWidth` to prove there is no horizontal overflow. Elementor
layouts in particular are worth seeing rather than reading.

Two cache-busters to remember: **`BOOKIFY_BOOKING_VERSION`** for the plugin's CSS and JS, and the
theme version in `style.css` for the theme's. Bump the right one after a CSS edit, or a stale sheet
will make a correct rule look broken.

## Rules for changing this repository

1. **Declare new plugin and theme directories.** Only `wp-content/themes/bookify-theme`,
   `wp-content/plugins/bookify-booking`, `wp-content/uploads` and the kit's plugin are mounted
   into the container. Creating `wp-content/plugins/my-thing/` by hand does nothing — the container
   never sees it. Use `wpdev add plugin <slug>` or `wpdev add theme <slug>`.
2. **Never hand-write Elementor data.** Writing `_elementor_data` with `update_post_meta()` leaves
   Elementor's generated CSS stale. Use the MCP tools, or the bridge's `Elementor_Document` helper
   (`get_elements()` → `update_settings()` → `save()`), which goes through Elementor's Document API
   and handles versioning and the cache.
3. **Do not change `PROJECT_NAME` in `.env`.** It names the Docker volumes; changing it points the
   site at an empty database.
4. **Keep the theme presentation-only.** No post types, shortcodes, `$_POST` readers or queries in
   `wp-content/themes/bookify-theme/`. Behaviour goes in the plugin so it survives a theme switch.
5. **Content is data, never files.** Pages, menus and layouts are created through WP-CLI, the MCP
   tools or the bridge's abilities — not committed as SQL or JSON.
6. **No secrets in git.** `.env`, `.mcp.json`, `.vscode/mcp.json`, `.elementor-mcp-credential` and
   `deploy/render/.env.render` are all gitignored; keep it that way, and never print a value.
7. **Vocabulary.** People read "session"; the code keeps `bookify_service` — the post type key, the
   `bookify_service_id` meta, the `bookify_booking_service_*()` helpers, the `.bookify-services*`
   CSS and the `[bookify_services]` shortcode. Do not "finish" that rename.
8. **Run `wpdev smoke` after changing the theme or the plugin** and report the count.

## Before this goes live

Placeholder and test data is in the database, and two of these are visible to the public:

- [ ] **Replace the business details.** `bookify_business` holds a fictional studio — the footer,
      the Contact page and the published LocalBusiness JSON-LD all read that one option. Edit
      **Bookings → Settings**.
- [ ] **Empty `BOOKIFY_MAIL_REDIRECT_TO`.** While it is set, every message goes to one inbox in a
      testing shape; with it empty, customers get the real confirmations.
- [ ] **Verify a domain in Resend.** Until one is verified, Resend refuses every recipient but the
      account owner.
- [ ] **Remove the Stripe test keys** from `bookify_payments` (a leftover from the payment probes;
      `stripe_configured` is currently true) and set live ones deliberately, or clear them.
- [ ] **Delete the probe bookings.** The Bookings list still holds bookings created while testing.
- [ ] **Decide on the Elementor pin.** The `Dockerfile` pins `4.3.0-beta2` because it is the
      version the design layer was measured against; `4.2.4` is the stable alternative.
- [ ] **Put the process on paid Render, or move the database.** The free plan has no disk and no
      cron, so reminders and event summaries do not run reliably on it.

## Things that look like errors but are not

| Symptom | Explanation |
| --- | --- |
| `wp db check` / `wp db export` fail | The `wordpress:` image has no `mysql` client. Use any command that goes through PHP instead |
| `wp rewrite structure … --hard` warns about `.htaccess` | Harmless: the image ships a working `.htaccess`, which is why permalinks work |
| Playwright fails to launch a browser | Chromium is not installed yet — run `npx playwright install chromium` once |
| A `.env` change appears to be ignored | `docker compose restart` reuses the environment the container was created with. Use `wpdev restart` (or `up -d`). It is also easy to be misled: `wpdev wp` starts a **fresh** container each call and so sees the current `.env`, while the running web container does not |
| The Resend path records no failure at all | With no key the Resend branch is skipped entirely, so there is nothing to record. A *missing* result means "not configured", not "failed" |
| Front end looks unstyled | `wp elementor flush-css`, or `wpdev wp eval-file` with `clear-elementor-cache`; a bumped asset version also fixes a stale sheet |
| A WordPress `?<post_type>=` URL 404s | A registered post type's key is a public query var. Use `bookify_service_id`, not `bookify_service` |

## Documentation

| File | Is |
| --- | --- |
| `CLAUDE.md` | The operational manual: commands, MCP servers, abilities, the rules above |
| `docs/bookify-plan.md` | The goal, and every architectural decision (D1–D24) with its reason |
| `docs/bookify-tasks.md` | The whole task list, in stages, each with its evidence |
| `docs/SETUP.md` | The local kit: architecture, quick start, daily commands, and the email setup in depth |
| `docs/DEPLOY-RENDER.md` | What a Render deploy does and does not do, the free plan's limits, and the Supabase connection |
| `docs/PROMPTS.md` | Which route to use for which kind of work, with example prompts |

No build step, no test runner and no package manager: PHP and a few asset files, run by WordPress.
`wpdev smoke` and the probes are the test suite.

## Licence

`bookify-booking` is GPL-2.0-or-later. `bookify-theme` is GPL-3.0-or-later, as a child of Hello
Elementor. WordPress, Elementor and Hello Elementor keep their own licences.
