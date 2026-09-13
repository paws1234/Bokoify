# Deploying Bookify to Render, with Supabase

## Read this first: the one thing that cannot change

**WordPress requires MySQL or MariaDB. Supabase is PostgreSQL. Therefore Supabase cannot be WordPress's
database — not with a wrapper, not with a translation layer, not with configuration.**

This is not a preference or a gap in this plugin. `wpdb`, WordPress's database class, is built on PHP's
`mysqli` extension and every core query is MySQL dialect: `AUTO_INCREMENT`, `LONGTEXT`, `ENUM`,
`ON DUPLICATE KEY UPDATE`, `utf8mb4_unicode_520_ci`, and so on. Elementor, the MCP adapter and the agent
bridge all ride on the same class with SQL of their own. There is a category of hack that sits between
the two — a `db.php` drop-in that rewrites MySQL to Postgres — and it is unmaintained and incomplete,
and standing it between WordPress and a booking system's data is not something I would do.

So the site keeps a MySQL database. What Supabase gets is **your data**, transformed into the shape it
should have had all along. That is what `includes/supabase/` does, and it is what makes the goal
reachable rather than blocked.

### What that means concretely

| | System of record | Where it lives |
| --- | --- | --- |
| WordPress itself — pages, Elementor layouts, users, options | MySQL | Render private service (or managed MySQL elsewhere) |
| The booking domain — sessions, events, tiers, bookings | MySQL, *and* a typed copy in Supabase | both; WordPress is authoritative |

Every session, event, tier and booking is published to Supabase as a **typed Postgres row** — a real
`date`, `time`, `numeric(10,2)`, and ids that are actual foreign keys — rewritten on every change, and
re-reconciled daily so a failed push corrects itself. WordPress never reads Supabase back, so Supabase
can be down, paused or misconfigured and the site still takes bookings.

The mirror is **comprehensive**: six tables, covering the whole domain rather than a convenient part of
it.

| Table | Built from |
| --- | --- |
| `bookify_settings` | The four booking options — business details, the diary's rules, reminders, payments — as one row of real columns, with the opening hours and blocked dates as `jsonb`. |
| `bookify_services` | `bookify_service` posts |
| `bookify_events` | `bookify_event` posts |
| `bookify_tiers` | `bookify_tier` posts |
| `bookify_customers` | The WordPress accounts |
| `bookify_bookings` | `bookify_booking` posts |

**What deliberately does not travel**, and why a named column list beats `SELECT *`:

| Not mirrored | Because |
| --- | --- |
| `wp_users.user_pass` | A password hash. |
| `wp_usermeta` | It holds `session_tokens` — live logins. |
| Stripe `secret_key`, `webhook_secret` | They can charge cards and forge webhooks. `bookify_settings.stripe_configured` says whether one is set. |
| Turnstile `secret_key` | Same shape of problem. `turnstile_configured` replaces it. |
| `bookify_cancel_token`, `bookify_manage_token`, `bookify_manage_expires` | Bearer secrets: the reference looks a booking up, the token proves the link belongs to it. |
| The Resend API key, the Supabase key | They live in the environment, never in the database. |

`_probes/supabase-sync.php` asserts this against the **real stored values** — it reads whatever secrets
the options and the users table actually hold and searches the exact bytes that would be sent for
them. A test looking for a fixed string like `sk_test_` would pass whether or not the code was right;
this one cannot.

If what you actually want is Supabase as the **source of truth** for the booking domain — bookings that
exist only in Postgres, read and written through a repository layer instead of `WP_Query` — that is a
different and much larger change, and it is described at the end of this document.

---

## Render's free plan: what it will and will not do

The three things this site needs are the three things a free instance does not have, and all of them
fail *silently* — no error, no alert, the site just quietly stops doing part of its job.

| | Free plan | What its absence costs |
| --- | --- | --- |
| Persistent disk | **No.** Free web services cannot attach one. | `wp-content/uploads` — the session photographs and Elementor's generated CSS — is wiped on every redeploy, every restart, and **every spin-down**, which happens after 15 minutes without traffic. The photographs survive anyway, because the mirror carries their bytes in `bookify_media` and puts them back on demand ([below](#carry-the-images-which-is-what-a-diskless-host-actually-costs)); Elementor's stylesheet is regenerated from `_elementor_data`, which is in the database. |
| Cron job | **No.** “Other service types don't support Free instances.” | Nothing runs `wp-cron.php` on a schedule, so the reminders, the reservation summaries and the expiry of unpaid bookings only run if a page view happens to arrive. |
| Private service | **No.** | The database has to be external. |
| Shell / `render ssh` | **No.** | **There is no way to import a database dump** — which is what the content mirror below exists to work around. |
| Free Postgres | Yes — 1 GB, expiring after 30 days. | Irrelevant: it is PostgreSQL, so WordPress cannot use it. |

Render's own documentation is blunt: *“Do not use them for production applications.”*

**The practical consequence used to be that the free plan could not host this site.** Not because of the
database — the mirror puts the booking data in Supabase either way — but because the existing content
could not be got in, and with no Shell there is no dump to import. That is solved by carrying the site's
own tables in Supabase too: see [Carry the site itself](#carry-the-site-itself) below. A free instance
now builds itself from the copy on first start, with no Shell, no dump and no manual step.

The mirror is worth *more* on the free plan than on a paid one, not less: with no disk and no backups,
the typed copy in Supabase is the only durable record of the booking data and the site that leaves the
instance.

**One thing the free plan still cannot do for itself**, and it is not about data: nothing in the image
can be edited on the instance. A plugin or theme installed through wp-admin lives in the volume — which
is to say only until the next spin-down — so anything the site needs has to be in the `Dockerfile`.

## The Render CLI

Render's CLI does have a Vercel-style browser login, and it is worth using: it validates a Blueprint
against Render's own rules rather than against a schema, and it can open a shell on a paid service.

```sh
curl -fsSL https://raw.githubusercontent.com/render-oss/cli/refs/heads/main/bin/install.sh | sh
render login          # opens your browser; the token is saved to ~/.render/cli.yaml
render workspace set  # choose the workspace
render blueprints validate render.yaml
render blueprints validate render.production.yaml
```

`render login` authorises through the browser, so no token is ever typed into a shell — and it should
not be pasted into a chat either. For CI the CLI reads `RENDER_API_KEY` from the environment instead.

Afterwards: `render services` lists what is deployed, `render deploys create <service> --wait`
triggers a deploy and blocks on it, and `render ssh <service>` opens a shell (paid plans only).

**The CLI cannot set environment variables.** The whole command set was checked (v2.28.0): `services
update` takes a plan, a branch, commands and a health check, and nothing else — `--environment-ids` is
a *filter*, not a way to set anything. So injecting a secret from the terminal is a Dashboard job, or a
Blueprint one:

- **A new deploy:** declare the variable in the Blueprint with `sync: false` and Render prompts for it
  during the Blueprint's creation. That is what the Supabase variables do.
- **An existing service:** the Dashboard's Environment tab. Render **ignores `sync: false` entries when
  updating an existing Blueprint**, so re-syncing will not add a new one.
- **CI:** `RENDER_API_KEY` authenticates the CLI, but there is still no env-var subcommand to use it
  with. The Render REST API has one.

---

## Connecting to Supabase's Postgres directly

The mirror writes through Supabase's HTTPS API by default. It can instead open a socket to the database,
which is what `BOOKIFY_SUPABASE_DB_URL` switches on. Nothing else changes — the same six tables, the
same rows, the same reconciliation — because the choice is made inside one function, so `sync.php` is
unaware of it either way. `wp bookify-supabase status` prints which transport is in use.

| | HTTPS API (default) | Direct connection |
| --- | --- | --- |
| Credential in the application | a `service_role` key | the database password |
| Needs a PHP extension | no | `pdo_pgsql` (in the image) |
| Round trip | one HTTPS request per table | one socket, one statement per table |
| On failure | that table fails | the whole run rolls back |
| Survives an IPv6-only host | yes | **no** — see below |

### The host in the URL is the part that bites

The dashboard's **“Direct connection”** string is `db.<project-ref>.supabase.co`, and on current
projects that name is **IPv6-only**. Measured for this project:

```
db.ltpadcfsurvrgfvqipbh.supabase.co    IPv4: no address    IPv6: 2406:da1c:16f1:f601:...
```

A host with no IPv6 route cannot reach it, and the failure names the host rather than the missing
route. Use the **Session pooler** string from the same Connect panel instead —
`aws-0-<region>.pooler.supabase.com`. Measured, the pooler hosts answer on IPv4:

```
aws-0-eu-central-1.pooler.supabase.com  ->  18.198.145.223
aws-0-us-east-1.pooler.supabase.com     ->  44.208.221.186
```

The pooler also solves connection counting, which matters: PHP opens a connection per request unless
told otherwise, and the direct port allows only a few dozen.

### Configuration

| Variable | |
| --- | --- |
| `BOOKIFY_SUPABASE_DB_URL` | The whole string, e.g. `postgresql://postgres.<ref>:…@aws-0-eu-central-1.pooler.supabase.com:5432/postgres`. |
| `BOOKIFY_SUPABASE_DB_PASSWORD` | Optional and recommended: overrides whatever the URL carries, so the URL can live in a Blueprint and only the password is a secret. |
| `BOOKIFY_SUPABASE_DB_SSLMODE` | Defaults to `require`. `disable` is for a local Postgres and must never be pointed at Supabase. |

A URL still holding the literal `[YOUR-PASSWORD]` — what the dashboard shows before you reveal the
password — is read as **not configured**. Read as a password it would produce “authentication failed”,
which sends you looking at the wrong thing.

### Security, plainly

- The `postgres` user is the project's **superuser**: it can read every row, drop every table and change
  the roles protecting them. It belongs in an environment variable and nowhere else — not in a
  Blueprint, not in the repository, and never pasted into a chat.
- A **dedicated role** with rights on the six `bookify_*` tables and nothing else is better. The mirror
  needs only `SELECT`, `INSERT`, `UPDATE` and `DELETE` on those.
- Everything reaching the DSN is validated first. A value containing a semicolon is refused outright,
  because the DSN is semicolon-separated and `host=evil;sslmode=disable` would silently turn off
  encryption on a connection carrying that password.

---

## What gets deployed

```
                    ┌──────────────────────────────┐
   public HTTPS ───▶│  web: bookify                │
                    │  Dockerfile → wordpress:8.3  │
                    │  + theme, plugin, Elementor  │
                    └───────┬──────────────┬───────┘
                            │ private      │ HTTPS
                            ▼              ▼
                    ┌───────────────┐  ┌──────────────────┐
                    │ pserv:        │  │ Supabase         │
                    │ bookify-db    │  │ bookify_services │
                    │ mariadb:11    │  │ bookify_events   │
                    │ + disk        │  │ bookify_tiers    │
                    └───────────────┘  │ bookify_bookings │
                            ▲          └──────────────────┘
                            │ private           ▲
                    ┌───────┴───────────────┐   │ (WP is the writer)
                    │ cron: bookify-wp-cron │   │
                    │ every 5 min → wp-cron │   │
                    └───────────────────────┘   │
                    ┌───────────────────────────┴──┐
                    │ web shell: wp bookify-supabase│
                    │ sync  (manual seeding/prune)  │
                    └───────────────────────────────┘
```

Two blueprints, because the free and paid plans buy genuinely different things:

| File | Services | Plan |
| --- | --- | --- |
| `render.yaml` | the web service only | free — see the section above for what that costs |
| `render.production.yaml` | web service + MariaDB private service + cron job + disk | paid |

`Dockerfile` and `deploy/render/entrypoint.sh` build the web image and are shared by both. Render
applies `render.yaml` by default; point it at `render.production.yaml` explicitly once you move to a
paid plan, or rename that file over `render.yaml`.

---

## Before you deploy

### 1. Create the Supabase project and its tables

Run `wp-content/plugins/bookify-booking/includes/supabase/schema.sql` in the Supabase SQL editor. It is
idempotent: running it twice is a no-op.

Note the two values you will need:

| Value | Where | Notes |
| --- | --- | --- |
| Project URL | Settings → API | `https://<ref>.supabase.co` |
| `service_role` key | Settings → API | **A full-database credential.** It bypasses row level security. It belongs in Render's environment and nowhere else — not in the repo, not in `.env`, never in a chat. |

Row level security is enabled on every table with no policies, so nothing but that key can reach the
data through the auto-generated REST API.

### 2. Know what you are migrating

The local database dumps to about **1.4 MB** across 13 tables, and it carries the Elementor layouts
(`_elementor_data`) that every page is built from. You want it, not a fresh install.

```sh
# On this machine. Credentials come from the container's own environment.
docker exec bookify-db-1 sh -c \
  'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction \
   --default-character-set=utf8mb4 "$MARIADB_DATABASE"' > bookify-db.sql
```

**Do not commit that file.** Put it somewhere the Render shell can fetch it over HTTPS with a
short-lived URL (an S3/GCS presigned URL, or a private release asset) and delete it when you are done.

The dump carries the old `siteurl`/`home` values, but you do not need to search-replace them:
`render.yaml` sets `WP_HOME` and `WP_SITEURL` from `RENDER_EXTERNAL_URL`, which overrides the stored
options.

---

## Deploy

1. **There is no git repository here yet.** This workspace has no `.git` directory, and Render deploys
   from a Git provider. So this comes first: `git init`, commit, and push to GitHub, GitLab or
   Bitbucket with `render.yaml` at the repository root. Before the first push, check that `.gitignore`
   covers `.env` (the database password and the Resend key) and the `bookify-db.sql` dump from step 2 —
   a dump in a repository is both a secret leak and an accidental permanent copy of customer data.
2. Render Dashboard → **New → Blueprint**, pick the repo. Render reads `render.yaml` and asks for the
   two `sync: false` secrets:
   - `BOOKIFY_SUPABASE_URL`
   - `BOOKIFY_SUPABASE_KEY` — the `service_role` key
3. Let it build. `bookify-db` comes up empty, which is expected.
4. **Import the database.** Open the **bookify** web service → **Shell** and run:

   ```sh
   curl -fsSL -o /tmp/db.sql '<your short-lived URL>'
   mysql -h "$WORDPRESS_DB_HOST" -u "$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" \
         "$WORDPRESS_DB_NAME" < /tmp/db.sql
   rm /tmp/db.sql
   ```

   The `mysql` client is in the image for exactly this reason — a private service is not reachable from
   your laptop, which is the point of it.
5. Reload the site. WordPress now finds its tables and serves the real site.

### Turn the mirror on and seed it

The mirror is **off by default** and says so. With both variables set it is live: every write publishes
itself, and a daily reconciliation re-sends everything.

```sh
wp bookify-supabase status          # configured? what would it send? how did the last push go?
wp bookify-supabase sync --dry-run  # build the rows, send nothing
wp bookify-supabase sync            # the first real push — 4 requests, 21 rows
wp bookify-supabase status
```

`status` prints the key's length and never the key. If it says *not configured*, the values did not
reach PHP — check the environment on the service, and remember that **only a redeploy applies a changed
environment variable**; restarting is not enough.

### Carry the images, which is what a diskless host actually costs

The entity tables describe the bookings. They do not describe what a page *looks* like, because a page
also needs the image files, and those live in `wp-content/uploads` — the directory that does not survive
here. So the mirror carries them too. `bookify_media` holds the bytes of every file WordPress generated
for each image, base64-encoded, and its rows travel over the same connection as everything else.

```sh
wp bookify-supabase media status     # what would be carried, how large, and whether Supabase has it
wp bookify-supabase media push       # send the image rows now
wp bookify-supabase media verify     # read them back and hash them against the files here
wp bookify-supabase media restore    # write back anything missing
```

The eight session photographs total **1 MB across 32 files** — the uploaded file and three generated
sizes each — about 1.3 MB once base64 adds its third, against a 500 MB allowance. This is not the
efficient way to move photographs and it is not meant to be. A bucket would be smaller and a CDN would
be faster; both would also be a second system, a second credential and a second thing to configure on
the host. What the space buys is that the copy is *complete*: one connection, one credential, one thing
to restore.

**Nobody has to run `restore`.** A request for an uploads file that is not on disk is answered from
Supabase, written back, and handed to the browser — so the first visitor after a spin-down waits a
moment for each image, and every request after that is served from disk by Apache. The command is there
for warming a machine deliberately and for repairing one.

Two things worth knowing about what is *not* carried:

- **Only images.** A PDF, an import file or a zip is not something a page renders, and carrying one
  would spend the database's own room on bytes nothing displays.
- **Nothing over the ceiling**, which is 5 MB for all of an image's files together —
  `BOOKIFY_SUPABASE_MEDIA_MAX_BYTES`, or a filter of the same name minus the `BOOKIFY_` prefix. A
  ceiling of `0` carries nothing at all, which is how this half of the mirror is switched off.
  `media status` names any attachment that produced no row rather than leaving it to be discovered as a
  broken picture later.

**Run `media verify` after the first push.** It decodes every file and hashes it against the file on
disk, it writes nothing, and it is the difference between knowing the rows arrived and knowing the
photographs did.

### Carry the site itself

The entity tables are the booking domain; `bookify_media` is the pictures. Neither contains the *site* —
the pages, the Elementor layouts those pages are built from, the menus, the options holding the business
details, the accounts. All of that is in WordPress's own tables, and that is the part that could not be
got in on a free instance, because no Shell means no dump to import.

`bookify_content` is the way in. Each row is one row of one WordPress table, stored as JSON, and the
container restores them on start.

```sh
wp bookify-supabase content status            # what would be published, and what is already there
wp bookify-supabase content push              # send the site's own tables
wp bookify-supabase content push --prune      # and remove what the site no longer has
wp bookify-supabase content restore --dry-run
wp bookify-supabase content restore --if-empty
```

**Each row is JSON, and every value is a string.** These are not this plugin's tables. `wp_postmeta` has
been the same four columns for fifteen years, while `wp_posts` gains a column every few releases and any
plugin may add a table of its own — `wp_e_events` is Elementor's and nobody declared it. A typed mirror
would need a migration on every WordPress upgrade and would silently drop what it had not been told
about. And every value is stored as MySQL sent it, because that is also what MySQL accepts back: the
round trip is text → json → text on both sides, so there is no type to get wrong in either direction.

**Four things are deliberately not carried:** password hashes, password-reset tokens, stored login
sessions and the cron schedule. Transients are left out too — restoring a cache restores stale answers.
A restored account therefore has an empty password hash, which **measured** cannot be logged into; the
command says so. On an instance with no Shell the container sets the password itself from
`BOOKIFY_ADMIN_USER` and `BOOKIFY_ADMIN_PASSWORD`.

**What the container does on start**, in `deploy/render/entrypoint.sh`, in the background so nothing
delays Apache: wait for `wp-config.php`; `wp core install` when the database is empty; activate the
plugins; `content restore --if-empty`; then set the administrator's password. `--if-empty` is what makes
it safe to leave switched on — on an instance whose content is already there it does nothing, so a
redeploy never overwrites edits made since the last push.

**Verified by doing it**: a container started against a database that was empty and a volume that was
empty, and came up holding 722 rows across the eight tables it mirrors — 9 pages, 8 sessions, the
Elementor layouts, the menus, the options, the bookings. Restoring the same rows into a scratch database
and comparing every value found **4240 of 4240 identical**.

**Pruning, for what is deleted on the site.** A push on its own sends what is there now and leaves
everything else alone — so a row deleted on the site would stay in the copy, and the next restore would
put it back. `--prune` removes those, under the same rule `sync --prune` follows: never on its own, and
only once every write has succeeded, because deleting is the one operation here that can destroy data.

Two guards sit underneath it. A table this database does not have right now — a plugin not activated on
this machine — is left alone, because its rows cannot be shown to be orphans. And an empty set of local
rows is trusted only when the table really is empty; if the count disagrees, the read failed, and that
table is skipped and named rather than emptied. Both are asserted by the probe as a dry run, so it still
writes nothing to Supabase.

**The parent theme is in the image too.** `bookify-theme` is a child, so the restored options say
`template = hello-elementor` — and the parent lived only in the `wp_data` volume, exactly as the
Elementor plugin did before it was pinned. Without it the deploy restored everything, served it, and
answered every page with `200` and **0 bytes**, because WordPress had no template to render with. It is
pinned in the `Dockerfile` beside the plugin.

---

## Day-2 operations

**What survives a redeploy.** The theme, `bookify-booking` and Elementor come from the image, so a
redeploy applies the code it deployed. Uploads and Elementor's generated CSS live on the
`bookify-uploads` disk. Anything you install through wp-admin that is *not* in the image is lost — add
it to the `Dockerfile` instead.

**On the free plan there is no disk, and the images survive anyway.** `wp-content/uploads` starts empty
on every container start; the photographs come back from `bookify_media` the first time each one is
asked for. Elementor's stylesheet is regenerated from `_elementor_data`, which is in the database. What
does *not* come back is anything that is neither an image nor in a mirrored table — a document someone
uploaded, say — so treat the disk as cache rather than storage.

**Updating Elementor.** Change the `ELEMENTOR_VERSION` build argument. It is pinned to `4.3.0-beta2`,
the version this site's design layer was measured against; `4.2.4` is current stable if you would
rather not ship a beta.

**WP-Cron is not optional.** `render.yaml` sets `DISABLE_WP_CRON` and adds a cron job that hits
`wp-cron.php` every five minutes over the private network. This is not only for Supabase: the reminders
(stage 8), the reservation summaries (stage 7) and the payment-expiry sweep (stage 5) are all
`wp_schedule_single_event` work, so without that cron job the site stops sending reminders and stops
expiring unpaid bookings. `wp bookify-supabase status` warns when `DISABLE_WP_CRON` is set, because it
is the setting that makes a missing cron job silent.

**Backups.** Render's disk is not a backup. `bookify-db` is a MariaDB you own — schedule a
`mariadb-dump` from a cron job, or move to a managed MySQL whose provider does that for you. Supabase is
a copy, not a backup: it holds the booking domain, the session photographs, and nothing of the layout,
users or options.

**Restoring the images to a machine that has none.** `wp bookify-supabase media restore` writes every
file that is missing and skips the ones already there, so it is safe to run whenever and however often.
`--force` rewrites everything, which is the repair path for a file that exists but is wrong. Both need
the uploads directory to be writable by the user running them; on Render that is `www-data`, which owns
it. Note that a restore run as the wrong user fails loudly and changes nothing — it does not leave
half-written files behind.

**Nothing prunes itself.** Rows are removed from Supabase only by `wp bookify-supabase sync --prune`,
which is deliberate: it is the one operation that can destroy mirrored data, and a filter returning
nothing would empty the tables. Run it by hand after deleting posts outright. Trashed posts are not
orphans — they mirror as `status = 'trash'`.

---

## What was verified, and what was not

Verified in this workspace:

- `Dockerfile` **builds**, and the image **boots**: WordPress core installed, `wp-config.php` generated,
  theme + `bookify-booking` + Elementor 4.3.0-beta2 in the web root, `mysql` and `wp` on PATH, Apache
  listening on `$PORT`, and PHP answering a real request.
- Both Blueprints **validate against Render's published JSON schema** — types, enums, required fields
  and unknown properties.
- The mirror's transforms, its Postgres schema, its HTTPS requests (intercepted and asserted), its
  failure behaviour, its connection-string parsing and its image handling — **124 checks across five
  probes**, all passing. The extended schema was applied to a real PostgreSQL 16 and all 23 rows of the
  site's live data inserted into it with no type errors.
- The **direct connection, end to end**: the built image ran the plugin's own `wp bookify-supabase sync`
  against a real PostgreSQL 16 over a socket and wrote all 23 rows across six tables — joins resolving,
  `jsonb` and booleans intact, `weekdays -> '6' ->> 'from'` reading back as `10:00`. Two further runs
  changed nothing, which is the idempotency the daily reconciliation depends on.
- The **connection-string parsing**: the dashboard's `[YOUR-PASSWORD]` placeholder is read as *not
  configured*, a pooler username of the form `postgres.<ref>` is accepted, a percent-encoded password is
  decoded, and a value trying to smuggle `;sslmode=disable` into the DSN is refused.
- The **images, against the live project**: all 8 photographs — 32 files, 1 MB — pushed into
  `bookify_media`, read back, and every file hashed against the file on disk: 32 identical, 0 differing.
  Then one image's four files were deleted and `wp bookify-supabase media restore` brought them back
  byte for byte. The schema was applied to the live project by the plugin's own probe, which is what
  caught a `comment on column` running ahead of the `alter table` that creates the column.
- The **on-demand path, on the deployed image**: the real container was started with an **empty**
  `wp-content/uploads` and asked for files that were not there. The uploaded file and two generated
  sizes each came back as the correct JPEG — a `302` after the write, then `200` from Apache — and each
  was byte-identical to the original. A filename with no attachment behind it stayed a `404` and cost
  no query. This is the case that matters on the free plan, where the directory is empty on every start.
- That the resolver finds **generated sizes**, not just the uploaded file: measured, WordPress's own
  `attachment_url_to_postid()` returns `0` for `photo-768x512.jpg` while returning the right id for
  `photo.jpg`, so the plugin looks the size suffix up itself. A probe asserts core still behaves that
  way, so the helper can be deleted if it ever changes.
- The **site's own tables, end to end**: 722 rows — pages, layouts, menus, options, accounts and the
  bookings — published to `bookify_content`, then restored into a scratch database that was empty, with
  every one of **4240 values compared against what Supabase holds and found identical**.
- A **container starting against an empty database and an empty volume**, which is what a first deploy
  is: it installed WordPress, activated the plugins, restored 713 rows, and served the real home page —
  51 KB, the Elementor headings, and 8 images, from an uploads directory that held nothing, fetching each
  picture from Supabase on first request. Nine pages, 24 layouts, and an auto-increment above the highest
  restored id. That run is also what found the missing parent theme and the absent prune, neither of which
  was visible any other way.
- **The prune, both ways round.** Against the live project as a dry run: it plans nothing to remove when
  the copy already matches the site — the property that stops `content push --prune` emptying the copy at
  the end of an ordinary push. And separately, for real: an option created, pushed, deleted on the site,
  then reported as **1 orphan** and removed, and confirmed gone from Supabase.
- The secret redaction, asserted against the values actually stored on this site rather than against a
  pattern.
- The dump command above, which produced a 1.4 MB, 13-table archive containing `_elementor_data`.

**Not verified — no Render account was available:**

- An actual Render deployment: the private-network wiring between the web service, the database and the
  cron job.
- `render blueprints validate` against a real workspace. The CLI installs and reports its version; the
  Blueprint checks above were done with a validator written against Render's published schema, because
  the CLI needs `render login` first — which is yours to run, with your own credentials.
- The cron job's `startCommand` and the `property: hostport` reference. The syntax follows Render's
  documentation, but it has never been executed.
- The `pserv` running `mariadb:11` from a registry image.
- How the free plan behaves in practice — in particular whether the disk-less filesystem really does
  lose uploads on the schedule the documentation describes.
- Anything at all on the Supabase side: no project exists, so **neither transport has ever spoken to
  Supabase itself**. The HTTPS requests were asserted against an intercepted transport, the rows were
  proven against a local PostgreSQL 16, and the IPv6 conclusion above comes from resolving your host's
  DNS records — not from a connection to it.

Treat the first deploy as a test of those things, starting with the free-plan questions — they are the
cheapest to answer and the most likely to change your mind about the plan.

---

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| `Error establishing a database connection` | The pserv is not up, or `WORDPRESS_DB_HOST` did not get `:3306` appended. Check the entrypoint's log line. |
| Deploy fails, health check never passes | You added `healthCheckPath`. It is deliberately absent — WordPress answers 500 before its database is reachable and 302 while uninstalled. |
| Plugin code is stale after a deploy | The entrypoint copies from `/usr/src/bookify` on every start; if a file is missing there, the `COPY` in the `Dockerfile` did not include it. |
| `wp bookify-supabase status` says *not configured* | The variables are not in the service's environment. Changing them needs a redeploy. |
| Uploads vanish on redeploy | Expected — uploads are not code. The session photographs come back from `bookify_media` as they are requested; anything that is not an image does not. |
| Every page is blank — `200` with **0 bytes** | The active theme's parent is missing. The restored options name `hello-elementor`; it is pinned in the `Dockerfile` beside the Elementor plugin, so this means the image is older than that change. |
| The site is restored but nobody can log in | Working as designed: password hashes are not in the copy. Set `BOOKIFY_ADMIN_PASSWORD` and restart, or `wp user update <id> --user_pass=…` where there is a Shell. |
| `content restore --if-empty` says the site *already has pages* on a database that was empty | It found content WordPress did not name — an import, or a page somebody made. Drop `--if-empty` to restore over it. |
| Options or pages deleted on the site come back after a restart | The copy still has them. `wp bookify-supabase content push --prune` removes the rows the site no longer has. |
| The first page load after a spin-down is slow, and images appear one by one | Expected. Each missing file is fetched from Supabase once and then served from disk. Run `wp bookify-supabase media restore` to warm the whole directory in one go instead. |
| An image never comes back, however often it is requested | It never got into `bookify_media`. Run `wp bookify-supabase media status`: it names any attachment that produced no row, which is a non-image mime type, a file not on disk, or an image over the 5 MB ceiling. |
| `media restore` reports failures and writes nothing | The uploads directory is not writable by whichever user ran it. On Render the process is `www-data` and owns it; if you ran it as another user, nothing was changed and nothing was lost. |
| Reminders never send | `DISABLE_WP_CRON` is set and the cron job is missing or failing — or you are on the free plan, where there is no cron job to have. |
| Nothing to import the database with | Free instances have no Shell and no `render ssh`. Upgrade the service briefly; the disk and the shell are what the paid plan buys. |
| `render blueprints validate` says “no workspace specified” | Run `render login` and then `render workspace set`; the CLI needs a workspace before it can check anything that touches the API. |
| Every Elementor page renders as shortcode soup | Elementor is not active — it is in the image but still needs activating once on the imported database. |
| A 401 from Supabase whose message is `JWT expired` | The `service_role` key is wrong or rotated. |

---

## If you really need Supabase to be the source of truth

The achievable version of that is a **repository layer for the booking domain only**: sessions, events,
tiers and bookings read and written in Postgres, with WordPress keeping pages, users, options, media and
Elementor. It means:

- giving up `WP_Query` and `meta_query` for those four entities, and the wp-admin list screens with them
  (the Bookings admin screen would have to be rebuilt);
- replacing the `GET_LOCK` advisory lock in `includes/bookings.php` with a Postgres-side lock — which is
  genuinely better, since a unique index or `SELECT … FOR UPDATE` also protects against a second
  application writing the same rows, which the current lock explicitly does not;
- and a one-time migration of the 21 rows, after which the plugin's transforms become the schema rather
  than a mirror of it.

It is a coherent design, and the transform layer already written (`includes/supabase/transform.php`) is
most of the mapping you would need. It is also a rewrite of the plugin's storage path and its admin
surface, and it reverses the plan's explicit *"no abstraction for a second backend"* (line 367). Say the
word and I will cost it out properly before touching anything.
