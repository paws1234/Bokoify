# Plan: Bookify — free custom PHP + Elementor booking and ticketing

> Restructured 2026-09-12 from the master architecture document the owner supplied (its five
> phases are carried forward in **Steps**). The owner was unavailable and asked for autonomous
> progress, so the open architectural questions were decided and are recorded below. A decision
> that departs from the source document is marked **DIVERGENCE** with its reason. Tasks derived
> from this file live in `docs/bookify-tasks.md`.
> Amended three times, all on 2026-09-12: D1's stated reason was corrected after the ability layer
> was read rather than assumed; then the plan was extended, at the owner's request, from a bookable
> *form* to a bookable *site* (Stage 1.5 and Stage 2); then, at the same request, from a bookable
> *site* to a **complete booking business** — a calendar, bot hardening, customer self-service,
> money and per-service pages. The stages are now 1, 1.5, 2, 3 (a booking interface), 4 (the
> booking the customer owns), 5 (money), 6 (found and fast) and 7 (events and ticket tiers). Two
> constraint rules are deliberately relaxed by D11 and D13, and each says so where it is relaxed.
> Anything the owner wants changed should be said in the tasks file.
> Amended a fourth time, 2026-09-13: the owner widened the audience from a massage studio to
> **therapists, life coaches, personal trainers and small studios**, which is what D17–D19 and
> **Stage 8** (T29–T31) record. The session noun changed, reminders became a feature, and capacity
> became a first-class way to sell a place in a class rather than a quirk of one "Couples" row.
> Amended a fifth time, 2026-09-13: **Stage 7 is tasked and built** (T32–T36). It was the one stage
> left as backlog, on the grounds that its tier question and its payment path had nothing to stand
> on; both now exist (T6 and T16 for the question, T24 for the path), so the stage was split and
> implemented rather than described again. D21–D24 are the answers it needed, and one constraint is
> relaxed by D23.

## Goal

A local-business **site** on which a stranger arrives at the home page, reads what the business
offers, picks a service, sees the times that are actually free, and books one — through a form
rendered by an Elementor widget in which every visible label, price and message is an editable
Elementor control. The booking is stored in WordPress, listed in wp-admin with a status, and
confirmed by email; the customer can cancel it from that email. Events with ticket tiers follow
the same pattern afterwards. Nothing in the stack costs money: WordPress, `bookify-theme` (a Hello
Elementor child), free Elementor, and this project's own PHP plugin.

Nine stages with nine different jobs, and it is worth keeping them apart:

| Stage | Tasks | What it adds |
| --- | --- | --- |
| 1 | T1–T8 | the booking **works** — form, storage, email, wp-admin |
| 1.5 | T9–T14 | it is a **site** — front page, navigation, services, a styled and civil form |
| 2 | T15–T17 | it is **true** — the times offered are the times that exist, capacity holds, the customer can cancel |
| 3 | T18–T19 | a real **booking interface** — a calendar, and a public endpoint that cannot be hammered |
| 4 | T20–T22 | the booking belongs to the **customer** — manage it, move it, or log in to see it |
| 5 | T23–T25 | **money** — deposit or full price through Stripe Checkout, confirmed by webhook |
| 6 | T26–T28 | being **found and fast** — per-service pages, LocalBusiness data, measured performance |
| 7 | T32–T36 | **events and ticket tiers** — a dated event with places, tiers that price and limit a ticket, and the summary the operator gets |
| 8 | T29–T31 | it speaks to **the whole practice** — sessions not services, reminders that arrive, and classes with a real capacity |

What Stage 1 leaves behind on this machine, measured rather than assumed: `/book/` answers 200
with a working form, the site's home page is the default blog index showing "Hello world!", no menu
exists, and nothing links to `/book/` at all (0 matches). A page nothing can reach, on a site that
does not introduce itself, is not a website.

## Decisions

| # | question | decision |
| --- | --- | --- |
| D1 | Where booking data lives | Registered post types + registered meta: `bookify_service`, `bookify_booking` |
| D2 | Where the custom PHP lives | A new plugin, `bookify-booking`, declared with `wpdev add plugin` |
| D3 | Theme | Keep `bookify-theme` unchanged |
| D4 | First slice | One service booking, end to end (Stage 1 below) |
| D5 | Repeaters without Pro | Free Elementor is enough |
| D6 | Email locally | Compose and hand to `wp_mail`; a catcher + SMTP is its own task |
| D7 | Where opening hours and availability live | One `bookify_availability` option, edited on one Settings screen under Bookings |
| D8 | Where the form's CSS lives | The plugin: `assets/booking-form.css`, loaded only on a page holding the form |
| D9 | Service URLs | `bookify_service` keeps `public => true` but becomes `publicly_queryable => false` — one Services page, no per-service URL yet |
| D10 | How pages, menus and the front page are created | As content: WP-CLI, the `wordpress` abilities, or the `elementor` MCP server |
| D11 | Taking money | Stripe Checkout called directly with `wp_remote_post`, no plugin, no card data on this server, off unless a key is present |
| D12 | The calendar | WordPress's bundled jQuery UI datepicker plus one small script and a read-only REST route; no vendored library |
| D13 | Bots | Cloudflare Turnstile, off by default, keys on the settings screen, verified with the vendor's fixed test keys |
| D14 | Customer identity | A signed, expiring link is the default; accounts are an explicit opt-in and `subscriber` only |
| D15 | Service URLs, revisited | `publicly_queryable` goes back to `true` and exactly one theme template is written |
| D16 | Two bookings at the same instant | MySQL advisory locks (`GET_LOCK()`) around check-and-insert — no custom table |
| D17 | "Services" or "Sessions" | **Sessions** everywhere a person reads it; the post type key `bookify_service` does not change |
| D18 | Reminding the customer | One email before the session, on by default, lead time on the existing Settings screen, riding a scheduled event |
| D19 | Selling a place in a class | A session's `bookify_capacity` is the party ceiling, shown on the form and enforced by the write path |
| D20 | How structured data is published | The plugin emits the JSON-LD itself, from the stored options, instead of installing Rank Math |
| D21 | What "capacity per date" means for an event | An event happens once, on the date it stores, so its capacity **is** that date's inventory — no third post type between the event and its tickets |
| D22 | Is a ticket tier a row inside the event or its own record | Its own post type, `bookify_tier`: a booking names the tier by id, the way it names a session |
| D23 | Where an event's page comes from | A second theme template, `single-bookify_event.php`, presentation only — the one-template rule is relaxed for this and nothing else |
| D24 | What a ticket costs | The tier's price is per ticket, and a ticket is paid in full — no deposits, which exist to hold an appointment rather than to buy a place |

- **D1 — post types, not custom tables. DIVERGENCE.** The source document names `$wpdb` and custom
  database interaction. Registered post types bring the admin list, the REST surface and the meta
  API with them and need no migrations; the document's own rule — isolate core logic — is kept by
  confining storage to one file rather than by choosing a table.
  **Corrected 2026-09-12 by reading `wp-agent-bridge/includes/abilities/class-content-abilities.php`:
  `list-post-types` enumerates `get_post_types( array( 'public' => true ) )`, while `create-post`
  accepts any *registered* type by slug and writes only meta that is registered.** So a bespoke table
  is unreachable by both abilities, and `bookify_booking` is reachable only by the second: it is
  deliberately **not** public, because every booking holds a customer's name, email and phone and a
  public post type makes each one individually addressable. The accepted cost is that bookings do not
  appear in `list-post-types`; `bookify_service` is public and is listed with its three meta keys.
- **D2 — `wpdev add plugin bookify-booking`.** Only declared directories are bind-mounted, so a
  hand-made `wp-content/plugins/bookify-booking/` would be invisible to the container and fail
  silently. The theme's own header says presentation only; business behaviour must survive a theme
  switch.
- **D3 — keep `bookify-theme`.** The document says install Hello Elementor. It already is Hello
  Elementor: `wp-content/themes/bookify-theme/style.css` declares `Template: hello-elementor`.
  Installing a second theme would mean re-declaring the mount and re-activating for no gain.
- **D5 — verified, not assumed.** Elementor 4.3.0-beta2 (free, no Pro installed) ships
  `includes/controls/repeater.php` and registers `Controls_Manager::REPEATER`; checked on the
  running site with `wp eval` on 2026-09-12. The document's repeater requirement is therefore
  satisfiable without Elementor Pro.
- **D6 — nothing can send mail from a container**, so "the confirmation arrives in Gmail" cannot be
  a local acceptance criterion. The plugin composes the message and hands it to `wp_mail`; that is
  what the task verifies. A mail catcher and real SMTP belong to a hosting step, not to the code.
- **D7 — one option, one screen. This supersedes the "no settings screen" constraint below, for
  this and nothing else.** Slots are generated from data that no Elementor control can hold
  authority over: which weekdays are open and between which times, how long a slot is, how soon
  ahead a booking must be made, how many days ahead the diary opens, and which dates are blocked.
  Widget controls belong to one page and cannot govern what a hand-made POST is allowed to store,
  so the authority has to be server-side. A single option edited by `manage_options` on one
  `add_submenu_page()` screen is the smallest thing that can be authoritative; a custom table or a
  settings framework would be more machinery for the same result. The owner accepted this cost.
- **D8 — the plugin ships the form's stylesheet.** The markup belongs to the plugin, so its default
  appearance should travel with it: a theme switch must not turn the form back into bare browser
  controls, which is what it is today (no file in the project contains a single `.bookify-booking`
  rule). A theme may still override the `.bookify-booking*` classes; it is simply not required to.
  Nothing is enqueued on a page that does not render the form.
- **D9 — one Services page, no per-service URL yet. The cost is recorded.** A public *and
  queryable* post type renders `/services/<slug>/` through whatever the template hierarchy finds,
  and with theme-builder templates unavailable in free Elementor that is Hello Elementor's bare
  fallback — a defect, not a feature. Setting `publicly_queryable => false` while keeping
  `public => true` removes the bare URL and keeps the type in wp-admin, in REST and in the
  `list-post-types` ability (which enumerates public types). Verified on this machine 2026-09-12 by
  registering a probe type with those two flags: `is_post_type_viewable()` → `false`,
  `get_post_type_archive_link()` → `false`, and `get_post_types( array( 'public' => true ) )` still
  lists it. The cost: a service has no landing page of its own until Stage 6 asks for one (D15),
  which is exactly the trade D15 reverses.
- **D10 — the site is content, not templates.** `CLAUDE.md` rule 2 makes the `elementor` MCP server
  the only way to lay out a page, so the front page, the Services page and the Contact page are all
  laid out with it, and their menus are created with WP-CLI. No file is added to `bookify-theme` in
  Stages 1.5–2: D9 removes the only reason one would be needed and D3's "keep the theme" survives
  intact.
- **D11 — Stripe Checkout, called directly. This is the first deliberate exception to "free tooling
  only".** That rule meant "no subscription and no paid plugin"; money cannot move without a
  processor, and a per-transaction fee is the cost of the feature rather than a dependency the site
  needs in order to work. Calling `/v1/checkout/sessions` through `wp_remote_post()` with no SDK and
  no plugin keeps the code auditable and keeps card data off this server entirely — Checkout is a
  redirect, so no card number ever reaches WordPress. The integration is off unless a secret key is
  present, so the site still runs with nothing configured, and every outbound call is
  `pre_http_request`-filterable so the local acceptance is the composed request and a signed
  webhook rather than a live charge — the same shape D6 uses for mail. Keys live on the settings
  screen or in `.env`, never in the repository, and the screen must not print a stored key back.
- **D12 — the calendar is WordPress's own.** `jquery-ui-datepicker` and its stylesheet are already
  in every install, so a month view costs no vendored file, no CDN and no licence to track, and it
  is maintained as part of WordPress. The picker is decoration over the server's decision:
  `beforeShowDay` disables days using the same `bookify_available_slots()` the write path uses, so
  the calendar cannot disagree with what is bookable, and the no-JavaScript day list T16 builds
  stays as the fallback. Vendoring flatpickr (MIT, pinned, shipped with its licence) is the
  documented alternative if the owner wants a modern picker and accepts owning it.
- **D13 — Turnstile, off by default. The second exception.** A honeypot and a time-trap stop the
  naive bots; they do not stop a determined one, and this endpoint is public and writes rows.
  Cloudflare Turnstile is free, needs no puzzle for a real visitor, and — the reason it is first
  choice here — publishes fixed test keys that always pass and always fail, so both paths are
  verifiable locally with no account and no hosted configuration. It is off unless keys are
  present, and the ability to book must never depend on it.
- **D14 — a link, not a password; accounts are opt-in.** A local business does not want to run a
  login: passwords mean resets, resets mean mail, and mail cannot leave this container (D6). A
  signed, expiring link does everything the customer needs — see the booking, move it, cancel it —
  with no credential to store and no account to breach. Real accounts are still planned (T22)
  because the owner asked for them, but they are a `subscriber`-only user created on first booking,
  with no custom role and no new capability, and they are the last task of Stage 4: the plan is
  complete without them, and better with them only if returning customers are the actual pattern.
- **D15 — the service gets its page back. This reverses D9, deliberately.** D9 removed
  `/services/<slug>/` because the template hierarchy would have rendered Hello Elementor's bare
  fallback; Stage 6 supplies the one template that was missing, so the flag returns to `true`. This
  is the only file any stage adds to `bookify-theme`, and it is presentation only: header, title,
  content, the price and length from `bookify_booking_service_label()`, and the booking widget. No
  post type, no shortcode, no request handler — the rule D10 kept is unchanged.
- **D16 — the race is closed with a lock, not a table.** T16's check-then-insert is not atomic: two
  submissions a millisecond apart can both read "3 of 4 places taken" and both write. The usual fix
  is a unique constraint on a custom table, which D1 rules out for good reasons. MariaDB's advisory
  locks give real mutual exclusion without one — verified on this machine 2026-09-12:
  `$wpdb->get_var( "SELECT GET_LOCK('bookify_probe', 2)" )` → `1`, `RELEASE_LOCK` → `1`, server
  `11.8.9-MariaDB`. The lock is held for the check-and-insert only, released on every exit path so
  an error cannot strand it, and its limit is stated in the code: it serialises writers against one
  database server, which is what this site has.
- **D17 — the words change, the key does not.** The audience is therapists, coaches and trainers,
  and every one of them sells a *session*; "service" is the word the software uses, not the word
  their client does. So every user-facing string changes — the wp-admin menu, the page and its slug,
  the form's field label, the listing's heading, the confirmation's line, the Elementor panel —
  while the post type key `bookify_service`, every `bookify_booking_service_*()` function and the
  `bookify_service_id` meta stay exactly as they were. Renaming the key would be a data migration
  across every session and every booking's meta for a word no visitor ever sees, and the abilities,
  the REST layer and every existing booking refer to the key. The cost is that the code and the
  screen use different nouns; that is recorded here so nobody "fixes" it later by writing a
  migration.
- **D18 — a reminder is an appointment, not a message.** One email before the session, asking
  nothing and carrying the manage link so a customer who cannot come can say so without a phone
  call. It is scheduled with `wp_schedule_single_event()` at the moment it is due and given up on
  every path that takes the booking away — cancelled, expired, deleted, or moved, where it is
  re-asked at the new time. A poll would cost a query on every page view to serve a message that is
  needed once, which is the wrong trade on a site being measured for speed (T28), so this follows
  T23's deadline rather than inventing a second mechanism. It is **on by default** — the feature
  exists so that nobody has to remember it — with the lead time on the screen D7 already owns, so no
  second settings screen is created. A booking made inside the lead time gets no reminder: its
  moment has gone, and the confirmation the customer just received is the mail they need.
- **D19 — capacity is how a class is sold, not a quirk.** `bookify_capacity` was written in T2 and
  proven in T16 by one "Couples" row; for a studio selling places in a class it is the number that
  decides whether the product exists at all. So it becomes the ceiling on the party: the form's
  people field carries `max` from the same `bookify_slot_capacity()` the write path counts against,
  and the script re-reads it when the visitor changes session, so a class of eight does not keep the
  one-person ceiling of the session they were looking at first. Nothing about the write path
  changes — it already refused a party larger than the slot — so this is one rule stated where the
  visitor can read it rather than a second rule to keep in step.
- **D20 — the JSON-LD is ours. DIVERGENCE from T27's do-step.** T27 says to install Rank Math and
  point its LocalBusiness module at these options. The container keeps WordPress in a named volume
  (`wp_data:/var/www/html`), so a plugin installed through wp-admin would work and survive a
  restart — but it would exist only inside that volume: invisible to git, absent from a fresh clone,
  and impossible for `wpdev smoke` or a reviewer to reproduce. The values have to be read from
  `bookify_business` and `bookify_availability` either way, so the integration is the work and the
  plugin is only packaging, and an offer, an address and seven opening-hours rows do not need a
  site-wide SEO suite behind them. `includes/schema.php` is ~120 lines and T27's acceptance criteria
  are met against its parsed output rather than by eyeballing it. If the owner wants Rank Math later
  it can be added on top and this file deleted — the task's goal is the published data, not the
  plugin.
- **D21 — one event, one date: the capacity *is* the date's inventory.** Stage 7's shape names
  "capacity per date" and "date-specific inventory", which read like two features and are one. A
  tour with a run of dates would need a third post type between the event and its tickets so that a
  ticket could name a date; nothing in the stage needs that, and an event that happens once has its
  places on its own date by construction, so there is no per-date row to drift out of step with
  anything. An owner running the same event on three dates creates it three times, which is also
  what makes three summaries (T36) and three ticket lists. If a tour is ever wanted, the date is a
  new record then — not a rule bent now.
- **D22 — a tier is its own record, not a row inside the event.** T6 answered half of this question
  by showing what an Elementor repeater *is*: display rows whose only authority is which session
  they name, because a widget's controls belong to one page and cannot govern what a hand-made POST
  is allowed to store (D7). A tier is the other kind of thing — it carries the price a booking is
  sold at and the inventory the write path refuses against — so it has to be a stored record the
  server reads by id. A tier array inside the event's meta would also be server-side, but a booking
  would then name its tier by a key inside another post's meta, and every later read of "what did
  this person buy" becomes a lookup into a structure an edit can rewrite. `bookify_tier` is to an
  event what `bookify_booking`'s `bookify_service_id` is to a session: an id that stays true
  (D1).
- **D23 — the event's page is a second theme template. This relaxes the one-template rule of D10 and
  D15 for this and nothing else.** An event no URL renders is a ticket nobody can buy, and the
  alternative — the owner laying out an Elementor page per event and pointing a widget at it — makes
  every new event a design job and leaves the event's own permalink answering with Hello Elementor's
  bare fallback, which is the defect D9 recorded and D15 fixed for sessions. `single-bookify_event.php`
  is presentation only like its session counterpart: it prints what the plugin hands it — the date,
  the times, the places left, the tiers and the ticket form — and holds no rule of its own.
- **D24 — a ticket is priced per ticket and paid in full.** T23 fixed a session's price as what one
  slot costs regardless of party size, which is why the partners' session is priced for two; a tier
  is not that. "General Admission, GBP 15" is 15 each, so a booking of three tickets owes three
  times the price and the quantity is part of what was bought. Deposits are deliberately not offered
  here: a deposit exists so a business can hold an appointment somebody might not turn up to, and a
  ticket is bought rather than held. The two products therefore price differently, and this is the
  place that says so.

## Steps

### Stage 1 — one service booking, end to end (T1–T8, unchanged)

1. Declare and mount the `bookify-booking` plugin (T1).
2. Register `bookify_service` (public) and `bookify_booking` (admin-only) with their meta (T2).
3. The write path: nonce, sanitisation, capability, insert, reference code — the only code that
   touches storage (T3).
4. Render the form and put it on a real page, so it can be submitted from a browser (T4).
5. Wrap it in an Elementor widget whose every visible string is a control (T5), including a
   repeater so the service rows are editable without code (T6).
6. Compose the confirmation email and hand it to `wp_mail` (T7).
7. Make bookings usable in wp-admin: reference, service, date and time, customer, status (T8).

### Stage 1.5 — the site around the form (T9–T14)

1. Give the owner a way to enter a service at all: price, length and capacity are registered meta,
   and WordPress renders no field for registered meta — the post type does not even declare
   `custom-fields` support (T9).
2. Offer three real services, so the site is not a one-item demo (T9).
3. Show them on the front end — a listing widget plus a Services page, every row linking to the
   form with that service already chosen (T10).
4. Style the form. There is currently no CSS for its classes anywhere in the project (T11).
5. Give the form manners: keep what was typed when a submission is rejected, announce the notice,
   honour the service named in the link, and put a honeypot in front of the one public write
   endpoint (T12).
6. Replace the blog index with a real front page — hero, services, call to action — and assign it
   as the site's front page (T13).
7. Add navigation and a footer, a Contact page, and the business's own details stored once (T14).

### Stage 2 — availability you can trust (T15–T17)

Stage 1 accepts any time of day on any date, on any day of the week, any number of times over.
These three tasks make the times the form offers the times that exist.

1. Record when the business is open, how long a slot is, how far ahead a booking must be made and
   which dates are blocked — one option, one screen, rendered back out where a visitor sees it
   (T15).
2. Let one function decide what is bookable: the form renders exactly its output and the write path
   accepts nothing else, capacity included, inside an advisory lock so two simultaneous submissions
   cannot both win (T16, D16).
3. Give a booking a life — pending, confirmed, cancelled — and let the customer cancel from the
   confirmation email (T17).

### Stage 3 — a real booking interface (T18–T19)

1. Put a calendar in front of the slot list without giving up the no-JavaScript path, and without
   letting the calendar's idea of an available day differ from the server's (T18, D12).
2. Make the one public write endpoint expensive to abuse: the honeypot and time-trap first, rate
   limiting second, and a CAPTCHA last and off by default (T19, D13).

### Stage 4 — the booking the customer owns (T20–T22)

1. Replace the one-shot cancel link with a signed, expiring manage link that shows the booking and
   what can still be done with it (T20, D14).
2. Let the customer move a booking to another slot, with the same availability check the form uses
   and the old slot returned to the pool (T21).
3. Accounts, if the owner wants a login: a `subscriber` user created on first booking, a dashboard
   showing only their own bookings, and no capability beyond that (T22, D14).

### Stage 5 — money (T23–T25)

1. Decide what is paid and when — free, a deposit or the full price, per service — with the booking
   statuses and the unpaid-expiry that follow from it (T23).
2. Take the money through Stripe Checkout called directly from the plugin: no SDK, no plugin, no card
   data on this server (T24, D11).
3. Let Stripe, not the customer's browser, decide that a booking is paid — a signature-verified,
   idempotent webhook that confirms, expires or fails it (T25).

### Stage 6 — being found and being fast (T26–T28)

1. Give each service the page D9 took away, with exactly one theme template (T26, D15).
2. Publish LocalBusiness data that reads the address and the hours from where they are stored rather
   than restating them (T27).
3. Measure the four pages that matter and state the numbers rather than an opinion (T28).

### Stage 7 — events and ticket tiers (T32–T36)

The source document's shape, tasked and built after the stages it reuses: capacity per date, ticket
tiers (General Admission vs VIP), date-specific inventory and automated reservation summaries.

1. Give an event a record of its own — one date, a start, an end, and the places it has for that
   date — and a page, because a ticket nobody can reach is not for sale (T32, D21, D23).
2. Put the tiers on it as records rather than rows: two price-and-inventory pairs a booking can
   name by id (T33, D22).
3. Sell a ticket through the write path that already exists, with the capacity rule asked twice —
   once for the tier, once for the event's date — inside the same advisory lock (T34, D16, D19).
4. Write the ticket form on top of that path, over Stage 5's payment, with the amount decided once,
   from the tier and the quantity, and stored on the booking (T35, D24).
5. Tell the operator who is coming: one summary, composed from the bookings themselves and sent
   when the event ends, so the list is final when it is written (T36).

### Stage 8 — the whole practice (T29–T31)

1. Say **session** everywhere a person reads it, and keep the key that holds the data (T29, D17).
2. Send the reminder that stops a customer forgetting, and give it up the moment the booking stops
   being something to attend (T30, D18).
3. Make capacity the thing that lets a studio sell a place in a class: raise it on the group
   session, show it on the form, and re-theme the three sessions for the wider practice (T31, D19).

Stage 7 is a different product from a place in a class: a tier prices a ticket rather than a chair,
and the event it belongs to is a date rather than a diary of open days. It was tasked and built last,
as T32–T36, once the stages it reuses existed.

## Constraints / Out of scope

- Free tooling only. No paid plugin, theme, subscription or hosted plan. Three hosted *services*
  are permitted, each off by default and each with its reason recorded — Stripe (D11), Cloudflare
  Turnstile (D13) and, at hosting time, a mail provider (D6). None of them may be required for the
  site to work, and none may hold data the site could avoid handing over.
- New plugin or theme directories only through `wpdev add plugin|theme`. Never create one by hand.
- The theme stays presentation-only. No post types, shortcodes or form handlers in it. The only
  files any stage adds to it are `single-bookify_service.php` (D15) and `single-bookify_event.php`
  (D23).
- No custom database tables, no framework, no dependency injection, no abstraction for a second
  use case that does not exist yet, and no settings screen — the single exception is availability
  (D7), which has nowhere else authoritative to live and is one screen, not a settings framework.
  Stage 8 adds a Reminders section to that same screen rather than a second one (D18).
- Elementor data is written only through Elementor's own APIs. Never `update_post_meta()` on
  `_elementor_data`, and never edit Elementor's generated CSS.
- Availability and the business's own details are stored once and rendered from there. Opening
  hours and the address are never re-typed into an Elementor control, a page or a footer widget.
- Pages, menus, the front page and their copy are **content**: created with WP-CLI, the `wordpress`
  abilities or the `elementor` MCP server. `bookify-theme` gains no file in Stages 1.5–5, and no
  theme file ever holds a post type, a shortcode or a form handler.
- Nothing before Stage 3 changes what a booking *is*: `bookify_create_booking()`'s signature and
  the shortcode's name stay as T2–T4 fixed them. Stages 3–5 add meta keys (`bookify_customer_user`,
  the payment fields, `bookify_previous_slot`) but never change the meaning of an existing one.
- Every third-party call must be verifiable locally, with no account and no hosted configuration —
  a published test key, a payload signed in `wp eval`, or a `pre_http_request` stub — or it does not
  belong in this plan.
- No card data ever reaches this site. Payment is a redirect to a hosted checkout (D11), and nothing
  in the plugin may store a card number, a CVC or a full payment token.
- **`bookify_business` holds placeholders, not a real business.** `Bookify Studio`, 14 Harbour Lane,
  Brighton, BN1 2AB, `+44 1273 555 099` and `hello@bookify.example` are demo values, restored on
  2026-09-13 so the footer block and T27's LocalBusiness node had a source at all. T27 publishes
  whatever is stored, so as things stand the site publishes a fictional address as machine-readable
  structured data. **Replace them on Bookings → Settings before the site is seen by anyone**, and
  change nothing else: the footer, the Contact page and the schema all read that one option.
- `PROJECT_NAME` in `.env` must not change — it names the Docker volumes.
- Nothing in `wp-kit/` is edited as part of this plan.
- No API keys, credentials or mail accounts in the repository. Stripe and Turnstile keys live on the
  settings screen or in `.env`; the settings screen must never render a stored key back to the
  browser, and `.env` is gitignored.

## Done when

Stage 1 is done when, on this machine:

- a page holding the booking form answers 200 and renders it;
- submitting a valid entry in a browser creates one `bookify_booking` with its meta, and an invalid
  entry creates none and shows the error;
- the booking is visible and readable in wp-admin with its reference, service, date and customer;
- the confirmation message is composed and handed to `wp_mail`, evidenced by the filter capture;
- `wpdev smoke` still reports 10/10, and the widget's every visible string comes from a control.

### Done when events are done

Stage 7 is done when, on this machine, an event entered through wp-admin answers 200 at its own URL
with its date, its start and end times and the places left; two tiers exist on it with different
prices and different inventories and the page offers both; a party larger than a tier's own inventory
is refused with `bookify_tier_full`, and a party larger than the event's capacity with
`bookify_event_full`, while a place held by an unpaid booking counts against both; a ticket for a
paid tier is written with the amount that tier charges for the quantity bought, and the confirmation,
the customer's own page and the operator's screen all carry it; and one summary of the reservations
is composed and scheduled for the moment the event ends. `wpdev smoke` still reports 10/10.

### Done when the site — not only the form — is done

Stage 1.5 is done when, on this machine, a stranger who was never told the URL can reach
`http://localhost:8890/` and get a page that is not the blog index; read what the business offers
and follow one link to a service; reach the booking form from the page they landed on; and find it
laid out as a designed component that is keyboard usable at 390, 768 and 1440 px with no
horizontal overflow. A rejected submission shows its reason and keeps every value that was typed.
Three services exist, each one entered through wp-admin by a person rather than by `wp eval`.
No placeholder content — "Hello world!", "Sample Page" — is published on the site.

Stage 2 is done when the times the form offers are exactly the times the write path accepts: a
slot outside opening hours, inside the lead time, on a blocked date, already at capacity or already
booked cannot be created by any route, including a hand-made POST; cancelling a booking frees its
place; and that cancellation works from the link in the composed email.

### Done when the whole booking business is done

Stage 3 is done when a visitor can pick a day from a calendar on a phone, the times for that day
appear without a page reload, the same page still books with JavaScript switched off, and the
calendar never offers a day or a time the write path would refuse.

Stage 4 is done when a customer can open the link from their confirmation a week later, see the
booking, move it to another slot and watch the old slot become available again — and when a
customer who has an account sees their own bookings and provably no one else's.

Stage 5 is done when a service can require a deposit, the request the site composes to Stripe
carries the right amount, currency and reference, a signed `checkout.session.completed` event turns
the booking into paid and confirmed, an unsigned one does nothing at all, an expired unpaid booking
gives its slot back — and the site behaves exactly as it did in Stage 2 when no key is configured.

Stage 6 is done when a service has a page of its own, the LocalBusiness data on the site matches the
address and hours stored in wp-admin rather than restating them, and the four important pages carry
measured numbers instead of an opinion about speed.

### Done when the site speaks to the whole practice

Stage 8 is done when no user-facing screen says "service" — the wp-admin menu, the form, the
listing, the confirmation and the Elementor panel all say **session** — while the stored data and
every ability still address `bookify_service`; when booking a session that starts tomorrow leaves a
reminder scheduled at exactly the lead time before it, cancelling the booking takes that appointment
away, moving it moves the appointment and earns a new reminder, and a booking made inside the lead
time is never scheduled one at all; and when a session with a capacity of eight can be booked for
eight people through the form, refuses nine, and refuses two on a session that only takes one.
