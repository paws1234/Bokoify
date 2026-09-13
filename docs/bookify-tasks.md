# Tasks: Bookify booking and ticketing

Source plan: `docs/bookify-plan.md`, restructured 2026-09-12 and extended twice the same day: first
from a bookable form to a bookable site, then from a site to a complete booking business. Extended
again 2026-09-13, at the owner's request, from a massage studio to **therapists, life coaches,
personal trainers and small studios** — that is Stage 8. Tasked a fifth time, 2026-09-13: **Stage 7
leaves the backlog**, because the two things it was waiting on now exist — T6 answered what an
Elementor repeater can and cannot be authoritative for, and T24 built the payment path a ticket
needs. This file is the whole plan: **37 tasks in ten stages**, every stage tasked.
Order: T1–T8 (Stage 1) the booking works; T9–T14 (Stage 1.5) it is a site; T15–T17 (Stage 2) it is
true; T18–T19 (Stage 3) a real booking interface; T20–T22 (Stage 4) the customer owns the booking;
T23–T25 (Stage 5) money; T26–T28 (Stage 6) found and fast; T29–T31 (Stage 8) the whole practice;
T32–T36 (Stage 7) events and ticket tiers; T37 (Stage 9) the site's own appearance.
**Stage 8 was done before Stage 6**, at the owner's request, and **Stage 6 is now done too** — T26–T28
landed on 2026-09-13 after it. So T1–T31 are complete and T32–T36 are the new ones: Stage 7 is the only
stage that was ever described in the plan and left unsplit, and it is last because it reuses the rest.

Any single task can be run on its own by asking a session to do `T3` of this file. Each task names
the files it may touch, so it does not need the rest of the conversation.

**Definition of done for Stage 1:** a visitor can book a service on a real page, the booking is
stored and listed in wp-admin with a reference, its confirmation is composed and handed to
`wp_mail`, and every visible string in the form comes from an Elementor control.

**Definition of done for Stage 1.5:** a stranger can reach the site's front page, find a service and
reach the form without being told its address; three services exist that a person entered in
wp-admin; the form is styled, keyboard usable and free of horizontal overflow at 390, 768 and
1440 px; a rejected submission keeps every value that was typed; and no placeholder content is
published.

**Definition of done for Stage 2:** the times the form offers are exactly the times the write path
accepts; capacity cannot be exceeded by any route; cancelling a booking frees its slot and works
from the link in the composed email.

**Definition of done for Stages 3–6:** a calendar picks a day and the times load without a reload,
and the page still books with JavaScript off; a customer can move their own booking and the old slot
comes back; a deposit can be taken through Stripe with the card data never touching this server, and
the signature-verified webhook is what marks a booking paid; each service has a page a search engine
can read; and every third-party call above is provable locally without an account.

Kit path used below: `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev` (not on PATH for a non-login
shell).

---

### T1 — Declare and mount the `bookify-booking` plugin

- [x] done
- Evidence 2026-09-12: `wpdev add plugin bookify-booking` printed "created wp-content/plugins/bookify-booking" then "Plugin 'bookify-booking' activated."; `docker-compose.yml:44` carries `./wp-content/plugins/bookify-booking:/var/www/html/wp-content/plugins/bookify-booking`; `wp plugin list --fields=name,status,file` → `bookify-booking active`; `wp eval 'file_exists(WP_PLUGIN_DIR."/bookify-booking/bookify-booking.php")'` → "plugin file visible".

- **Goal** — `wp-content/plugins/bookify-booking/` exists in the project, is bind-mounted into the
  container, and is active.
- **Depends on** — none. **Parallel with** — none.
- **Context to load** — `CLAUDE.md` rule 1 (only declared directories are mounted);
  `docs/bookify-plan.md` decision D2; `wp-kit/bin/wpdev` `cmd_add` (around lines 562–605) for what
  the command actually does.
- **Do**
  1. Run `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev add plugin bookify-booking`.
  2. Confirm the generated plugin file carries a real header and that a bind mount for it was added
     to the project's `docker-compose.yml`.
  3. Confirm the plugin is active.
- **In scope** — `wp-content/plugins/bookify-booking/**`; the added mount line in
  `docker-compose.yml`.
- **Out of scope** — the theme, `.env`, `wp-kit/`, any other plugin.
- **Acceptance criteria**
  1. `wp plugin list --name=bookify-booking --field=status` prints `active`.
  2. `docker-compose.yml` contains a bind mount for `wp-content/plugins/bookify-booking`.
  3. A file written into the directory on the host is readable inside the container.
- **Verify** — `wpdev wp plugin list --name=bookify-booking --fields=name,status,file`;
  `grep -n "plugins/bookify-booking" docker-compose.yml`;
  `wpdev wp eval 'echo file_exists(WP_PLUGIN_DIR."/bookify-booking/bookify-booking.php") ? "visible\n" : "INVISIBLE\n";'`.
  Report all three outputs.
- **Size** — S

### T2 — Register the two post types and their meta

- [x] done
- Evidence 2026-09-12: `wp post-type list --fields=name,public,show_ui` → `bookify_service 1 1`, `bookify_booking` (public blank = false) `show_ui 1`; `wp eval` printed the registered keys exactly as listed (`bookify_service`: duration, price, capacity — `bookify_booking`: nine keys); `wp rewrite flush` → "Success: Rewrite rules flushed."; `wpdev smoke` 10/10.
- Bug found and fixed here: `'sanitize_callback' => 'floatval'` on `bookify_price` was a **fatal error** — WordPress calls a sanitize callback with four arguments and an internal PHP function refuses the extras (`ArgumentCountError: floatval() expects exactly 1 argument, 4 given`). Now `bookify_booking_sanitize_price()`. `absint` was always safe because it is a WordPress function. Verified afterwards with `update_post_meta` and `get_post_meta` on the price.

- **Goal** — `bookify_service` and `bookify_booking` are registered with registered meta, and the
  bookings list is usable in wp-admin.
- **Depends on** — T1. **Parallel with** — none.
- **Context to load** — the `wordpress-best-practices` skill (prefixing, one concern per file,
  hooks inside `init()`); `docs/bookify-plan.md` decision D1; the new plugin file from T1.
- **Do**
  1. Add `includes/post-types.php`: register `bookify_service` (public, supports title/editor/
     thumbnail) and `bookify_booking` (`public => false`, `show_ui => true`, supports title only).
  2. Register the meta each type needs, with a real `auth_callback` and `show_in_rest` where the
     ability layer should see it: services need duration, price and capacity; bookings need the
     service id, customer name, email, phone, date, time, party size, status and reference.
  3. Register a `bookify_booking_status` taxonomy or an enumerated meta only if the admin list needs
     it in T8 — not before.
  4. Require the file from the plugin bootstrap, registering hooks inside an `init()`.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — the theme; any custom table; any settings screen; any field not listed above.
- **Acceptance criteria**
  1. `wp post-type list` shows both types with `bookify_booking` non-public.
  2. `get_registered_meta_keys()` returns exactly the keys listed above for each type.
  3. wp-admin shows a Services and a Bookings menu entry.
- **Verify** — `wpdev wp post-type list --fields=name,public,show_ui`;
  `wpdev wp eval 'print_r(array_keys(get_registered_meta_keys("post","bookify_booking")));'` (and the
  same for `bookify_service`); `wpdev smoke` (expect 10/10).
- **Size** — M

### T3 — The booking write path

- [x] done
- Evidence 2026-09-12: `wp eval` — valid call returned `id=20 ref=BK-00020 status=pending service=19 date=2026-09-12 time=14:30 party=2 customer=Jane Doe email=jane@example.com`; `bad email → bookify_invalid_email`; `no service → bookify_unknown_service`; `past date → bookify_invalid_date`; `bad time (25:99) → bookify_invalid_time`; and only the valid call created a row. Over real HTTP, `POST /wp-admin/admin-post.php` with `action=bookify_booking` and **no** nonce, then with a wrong nonce, each answered `302 → http://localhost:8890/?bookify=rejected`.
- Re-run 2026-09-12 after the error messages moved into `bookify_booking_error_messages()`: the three codes and their wording were unchanged and a valid call returned `id=21 ref=BK-00021`.

- **Goal** — one function turns untrusted input into a stored booking, or returns a `WP_Error`.
- **Depends on** — T2. **Parallel with** — none.
- **Context to load** — the `wordpress-best-practices` skill (sanitising, capability, nonce);
  `docs/bookify-plan.md` D1 and constraint "the only code that touches storage".
- **Do**
  1. Add `includes/bookings.php` with `bookify_create_booking( array $data )` returning an int id or
     `WP_Error`: validate the service exists, sanitise every field, reject a bad email, insert the
     post, write the meta, and set a unique human-readable reference.
  2. Add the `admin_post` handler that verifies the nonce, checks the capability, reads `$_POST`
     through `wp_unslash()` + the sanitisers, calls the function above, and redirects with a status
     code rather than echoing.
  3. Keep every `$_POST` read inside that handler.
- **In scope** — `wp-content/plugins/bookify-booking/includes/bookings.php` and the bootstrap.
- **Out of scope** — markup, Elementor, email, admin columns.
- **Acceptance criteria**
  1. A valid call returns an id and the row carries every meta value.
  2. An invalid email returns `WP_Error` and creates no row.
  3. A missing service returns `WP_Error` and creates no row.
  4. The handler rejects a request with a missing or wrong nonce.
- **Verify** — `wpdev wp eval` running the three cases and printing the id or the error code, then
  `wpdev wp post list --post_type=bookify_booking --fields=ID,post_title,post_status`;
  `wpdev smoke`. `php -l` on each changed file.
- **Size** — M

### T4 — Render the form and put it on a real page

- [x] done
- Evidence 2026-09-12: page 22 at `http://localhost:8890/book/` → `http 200`, with the form, the nonce field and `action=".../wp-admin/admin-post.php"` present, and one option reading `Deep tissue massage — 85.00 — 60 min`. In the browser: a valid submission redirected to `?bookify=booked` showing "Thank you — we have your booking and will be in touch." and created **exactly one** booking (`23`, `browser@example.com`, 2026-09-20 11:30, party 3); an invalid email redirected to `?bookify=error&bookify_code=bookify_invalid_email` showing "That email address does not look right." and created **none** (`named Bad Email: 0`, `no-email-bookings: 0`). Total bookings 3. `wpdev smoke` 10/10.
- Note for later visual work: `type="email"` blocks the invalid address in the browser before it is sent, so the round trip above needed `form.noValidate = true`. The server-side check is therefore defence in depth, not the only guard.

- **Goal** — `[bookify_booking_form]` renders a working form, and a real page on the site uses it.
- **Depends on** — T3. **Parallel with** — none.
- **Context to load** — T3's handler signature and its status codes; the `visual-testing` skill for
  the browser step.
- **Do**
  1. Add `includes/booking-form.php`: `bookify_booking_form_html( array $args = array() )` returning
     escaped markup — the service list read from `bookify_service`, the date and time inputs, the
     customer fields, the nonce, the honeypot-free submit, and the success or error notice.
  2. Register the shortcode as a thin wrapper over that function, with defaults for every string it
     prints.
  3. Create a page containing the shortcode and publish it.
- **In scope** — `wp-content/plugins/bookify-booking/**`; the new page.
- **Out of scope** — the Elementor widget (T5); styling beyond what the theme already gives;
  restyling the theme.
- **Acceptance criteria**
  1. The page answers 200 and contains the form with every service offered.
  2. A browser submission with valid input creates exactly one booking and shows the confirmation.
  3. A browser submission with an invalid email creates none and shows the error.
- **Verify** — open the page in the browser, fill and submit it; then
  `wpdev wp post list --post_type=bookify_booking --fields=ID,post_title,post_date`; and a screenshot
  of the page at desktop and mobile widths.
- **Size** — M

### T5 — Elementor widget with the form's copy as controls

- [x] done
- Evidence 2026-09-12: `wp eval` on the widget manager → `count=150`, `widget=Bookify_Booking_Widget`,
  `title=Booking form`, `cats=bookify`, and the category itself → `bookify_cat=Bookify/eicon-calendar`.
  Page 31 (`/booking-form-widget/`, built through the `create-elementor-page` ability, **left as a
  draft** so nothing placeholder is published) had all 14 controls set to recognisable values; the
  served HTML carried every one of them — `Pick a time with us`, `Copy set from the Elementor panel.`,
  labels `Treatment`/`Day`/`Hour`/`Full name`/`Your email`/`A phone number`/`People`, button
  `Send my request`, `Deep tissue massage — EUR 85.00 — 60 min` (the currency control in the option),
  `?bookify=booked` → `Control-driven success line.`, a real error code → the plugin's own message,
  and an unknown code → `Control-driven error line.` (the fallback control). No string is hard-coded
  in the widget. With Elementor **deactivated** (`wp plugin deactivate elementor`) the site booted,
  `bookify_booking_register_elementor` existed, `bookify_booking_form_html()` still returned 1994
  bytes with the form, the page answered 200, and `debug.log` stayed at 70 lines (no new fatal);
  reactivated → `widget=registered` and the page rendered again. Screenshots at 1440x900, 768x1024
  and 390x844 (`/tmp/bookify-t5-{1440,768,390}.png`): header, footer, all seven fields and the
  button present at every width, no horizontal overflow; the form is still browser-default styling,
  which is T11's job. `wpdev smoke` 10/10.
- Trap recorded: Elementor registers its autoloader on `init`, not when its plugin file loads, so
  requiring the widget class at `plugins_loaded` is the fatal `Class "Elementor\Widget_Base" not
  found` seen here. The file is required inside the `elementor/widgets/register` callback instead —
  which is also why a site without Elementor cannot fatal on it.

- **Goal** — an Elementor widget that renders the same form, with every visible string supplied by
  a control.
- **Depends on** — T4. **Parallel with** — none.
- **Context to load** — T4's `bookify_booking_form_html()` signature; Elementor's widget API
  (`Widget_Base`, `register_controls`, the `elementor/widgets/register` hook); `CLAUDE.md` rule 2.
- **Do**
  1. Add `includes/elementor-widget.php`: a widget class extending `Widget_Base` whose
     `register_controls()` declares a TEXT control for each string T4's function already accepts as
     an argument (title, subtitle, button label, success message, error message). Register it on
     `elementor/widgets/register`, guarded so the plugin loads with Elementor inactive.
  2. Register a `bookify` widget category and place the widget in it.
  3. Render by passing the control values into `bookify_booking_form_html()`. No string is printed
     literally.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — style controls (a later task); changing T4's defaults; prebuilt Elementor pages.
- **Acceptance criteria**
  1. The plugin activates with Elementor deactivated, without a fatal error.
  2. The widget is registered and listed under the `bookify` category.
  3. Changing a control's value changes the rendered form and nothing else.
- **Verify** — `wpdev wp eval` listing the registered widget names
  (`\Elementor\Plugin::$instance->widgets_manager->get_widget_types()`); an Elementor page built with
  the widget opened in the browser with a screenshot; `wpdev smoke`.
- **Size** — M

### T6 — The service rows come from a repeater

- [x] done
- Evidence 2026-09-12: the widget grew a REPEATER control `services` with four fields — the service
  (a SELECT of published `bookify_service` posts, so every row names something bookable), a label, a
  price and a length. `render()` turns its rows into the form's service list through
  `bookify_booking_widget_service_rows()`, which offers nothing until
  `bookify_booking_bookable_service()` — the new single definition of "bookable", now used by the
  write path too — has recognised the service. Rendered through Elementor's own path
  (`create_element_instance()` → `render_content()`), two rows giving two services produced exactly
  two options: `19 => Deep tissue massage — EUR 85.00 — 60 min` (row left blank, so the service's own
  price and length with the currency control) and `41 => Short consultation — EUR 30 — 30 min` (the
  row's label and price, the service's length); a row naming a deleted service and a second row for a
  service that already had one were dropped. On the real page (post 31, published for the check) the
  same two rows served `http=200` and exactly two options, `46 => T6 temp: Aromatherapy massage —
  EUR 65.00 — 45 min` and `47 => Sports deep-tissue (45 min) — EUR 45 — 45 min`
  (`/tmp/bookify-t6-rows-{1440,390}.png`); with the repeater **empty** the same page fell back to the
  published services, `19`, `46` and `47` with each service's own values
  (`/tmp/bookify-t6-fallback-{1440,390}.png`), and the shortcode page `/book/` still renders
  `Deep tissue massage — 85.00 — 60 min` unchanged. Row values are escaped on output: a row labelled
  `<script>alert(1)</script> " onmouseover="alert(2)` with the price `<b>free</b>` came back as
  `<option value="19">&lt;script&gt;alert(1)&lt;/script&gt; &quot; onmouseover=&quot;alert(2) —
  &lt;b&gt;free&lt;/b&gt; — 60 min</option>`, the opening tag exactly `<option value="19">`. The write
  path still behaves: a published service → booking 42, id 999999 → `bookify_unknown_service`, a draft
  service → `bookify_unknown_service`. `wpdev smoke` 10/10. All fixtures removed afterwards
  (`T6 temp:` services 46/47, booking 42): service 19 is again the only published service and page 31
  is back to draft.
- Trap recorded: writing repeater rows through the abilities instead of the editor leaves out the
  `_id` Elementor stamps on every row, and Elementor's CSS generator then warns
  (`Undefined array key "_id"`, `elementor/core/files/css/base.php:876`) once per page render.
  Controlled check: rows with `_id` leave `debug.log` at 77 lines, the same rows without it reach 78.
  Not our code, and not reachable from the panel, which always adds `_id`.
- Judgement call recorded: a row books the service it names and the label, price and length only
  change what is shown, with the service's own values filling any blank; two rows naming the same
  service collapse to the first, because two options carrying one id are one bookable service.

- **Goal** — the services and prices shown by the widget are editable as repeater rows in the
  Elementor panel.
- **Depends on** — T5. **Parallel with** — none.
- **Context to load** — the T5 widget; the plan's decision D5 (repeater exists in free Elementor
  4.3.0-beta2 — verified in `includes/controls/repeater.php`).
- **Do**
  1. Add a REPEATER control (label, price, duration) and have the render path use its rows when it
     is not empty, falling back to the `bookify_service` posts otherwise.
  2. Escape every row value on output; never trust the stored setting.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — a second source of truth: the post type stays authoritative for what can
  actually be booked, and the task must say in the code which one a booking is created against.
- **Acceptance criteria**
  1. With two repeater rows, the form offers exactly those two.
  2. With the repeater empty, the form falls back to the published services.
- **Verify** — the browser check of T5 repeated with rows present and absent, plus the screenshot;
  `wpdev smoke`.
- **Size** — M

### T7 — Confirmation composed and handed to `wp_mail`

- [x] done
- Evidence 2026-09-12: new `includes/emails.php` holds
  `bookify_booking_send_confirmation( $booking_id )`, required from the bootstrap and called at the
  end of `bookify_create_booking()` — after the meta is written, so every rejection has already
  returned before it. With a `pre_wp_mail` filter that records and returns `true`: one valid booking
  → `mails_after_valid=1`, `to=t7@example.com`, `subject=Booking BK-00064 received`,
  `headers=From: Bookify <dev@example.com> | Reply-To: T7 Check <t7@example.com>`, and a body reading
  `Reference: BK-00064 / Service: Deep tissue massage / Date: 2026-09-12 / Time: 15:45 / Guests: 2`.
  Four rejections in a row — a bad email, service 999999, a past date, an empty name — gave
  `mails_after_four_rejections=0`; a second valid booking took the total to 2, each message naming its
  own reference (`BK-00064`, `BK-00065`). Over real HTTP, `POST /wp-admin/admin-post.php` with the
  nonce from `/book/` → `302 → http://localhost:8890/?bookify=booked`, `BK-00066` stored, and
  `debug.log` unchanged at 78 lines: the delivery failure is invisible to the visitor. `wpdev smoke`
  10/10. Bookings 64, 65 and 66 were deleted afterwards; 20, 21 and 23 are the pre-existing ones.
- Decisions recorded: the date and the time are printed exactly as chosen, because converting them
  through a timestamp would move a booking if the site's timezone were ever changed; the return value
  of `wp_mail()` is deliberately ignored (the booking exists either way, and a retry queue is out of
  scope); and a service that has since been unpublished is left out of the body rather than named
  wrongly.
- **Follow-up, 2026-09-13, at the owner's request: the message now actually leaves, and looks like
  something.** The entry above records the delivery failure as invisible — `debug.log` unchanged,
  the visitor seeing success either way — and that was the problem: this container has **no mailer at
  all** (`sh: /usr/sbin/sendmail: not found` in every probe that sends), so "the confirmation is on
  its way" was never true locally, and on a real host PHP's own mail from a shared box is the usual
  reason a studio's confirmations are filed as spam. Messages now go through **Resend's HTTP API**,
  and the confirmation is an HTML message as well as a plain-text one.
  **The fallback is the design, not a leftover.** `includes/mailer.php` sends through Resend only
  when a key *and* a from-address are configured, and otherwise calls `wp_mail()` exactly as before.
  That is what keeps this task's own acceptance intact — a site that has not set up Resend behaves as
  it always did — and it is what keeps every probe that captures `pre_wp_mail` passing. Checked
  rather than assumed, by re-running `_probes/stage7-summary.php` with no key configured: all checks
  PASS, including `and the From line is the site's own → From: Bookify <dev@example.com>`.
  **Measured, with the request stubbed** (`_probes/mail-resend.php`): with no key configured,
  `pre_wp_mail` fires and the body is the same plain text as before (347 bytes for one booking);
  with a key supplied, **`wp_mail` is not called at all** — no double send — and the captured request
  is `POST https://api.resend.com/emails`, `Authorization: Bearer …` (the probe prints its length and
  not one character of it), `Content-Type: application/json`, and a body of `from: Bookify Studio
  <bookings@bookify.example>`, the customer's address, the subject, a 347-byte `text` part and a
  4.6 kB `html` part. The HTML carries the reference, the booking's name, the date, the time, the
  party, the manage link and the studio's own footer.
  **The endpoint is real, not assumed:** the probe takes that same body, swaps the key for an invalid
  one, and posts it to the live API, which answers `HTTP 401 — API key is invalid` in Resend's own
  words. That proves the URL and the body shape are the ones the API accepts, and it cannot send
  anybody an email.
  **One bug the probe caught by looking rather than reading:** the email footer read
  `$business['address']`, and `bookify_business` stores the address as **four** keys (`street`,
  `locality`, `postcode`, `country`) — so the studio's address was missing from every message. It now
  joins them the way `bookify_booking_business_details_html()` joins them for the site footer, and
  the address appears in all four contact lines. A second trap, this one in the probe: the manage
  link is written with `esc_url()`, so the HTML holds `&#038;` between the query arguments and
  comparing it against the raw URL finds nothing — compare the escaped form.
  **Rendered and looked at** at 1440 and 390 (table layout, inline styles, no web font, no external
  anything, `color-scheme: light` so a client cannot invert a dark band into unreadability): the
  details stack into label-and-value rows on a phone and the button stays centred.
  **The key is configuration, not a settings field — corrected the same day, at the owner's word.**
  The first version put the key, the from-address and the sender name in a Mail section on the
  settings screen, handled exactly as Stripe's and Turnstile's secrets are. The owner's answer was
  that the key should be programmatic rather than typed into a browser, and they were right: a secret
  entered in an admin screen ends up in screenshots, in support tickets and in every export of the
  options table. Those fields are gone.
  **How it is supplied here: the project's `.env`.** `docker-compose.yml` passes three values through
  the `x-wordpress-env` anchor the `wordpress` and `cli` services share — `RESEND_API_KEY`,
  `BOOKIFY_MAIL_FROM` and `BOOKIFY_MAIL_FROM_NAME`, each defaulted with `${VAR:-}` so a project that
  does not use Resend still starts — and `.env` is gitignored, so the key stays on the machine, out of
  the repository and out of the database. **`wpdev restart` is the step that applies it**: it recreates
  the containers, whereas a bare `docker compose restart` reuses the environment the container was
  created with. Verified inside the web container after a restart: `RESEND_API_KEY` length 36,
  `BOOKIFY_MAIL_FROM` present, `configured: yes`, and the sender line the plugin resolves.
  Behind that, in order: the `BOOKIFY_RESEND_API_KEY` and `BOOKIFY_MAIL_FROM` constants — `wp config set
  BOOKIFY_RESEND_API_KEY re_…` writes one, and PHP sees it, which was checked before anything was built
  on it — then the environment, then the `bookify_booking_resend_api_key`,
  `bookify_booking_mail_from_address` and `bookify_booking_mail_from_name` filters, which is how a host
  with no `.env` does it and how the probe tests all of this with no key in existence anywhere.
  What stays on that screen is a **read-out, not a form**: which transport is in use, the exact sender
  line a customer will receive, the command that turns Resend on while no key is configured, and the
  outcome of the last send. Measured: the screen renders no key input, no from-address input and no
  clear-checkbox, and the sender line it prints is the one `bookify_booking_mail_from()` gives both
  transports — the same string the confirmation's own `From:` header still carries, which the older
  probe checks.
  **Nothing is left behind, including the record.** The stubbed send used to leave "accepted by
  Resend" on that screen — true of the transport, false as news — so the probe now restores the
  last-result option as well as its filters and its booking, and prints all three.
  Plugin version 0.5.0. `wpdev smoke` 10/10; `_elementor_data` md5s unchanged.
  **A trap inside the evidence itself, worth keeping.** The probe's clean-up wrote the original option
  back with `update_option()` — and `bookify_mail` is registered with a sanitiser that *keeps a stored
  secret when the submission carries none*, which is exactly what makes "leave the box empty to keep
  it" work on the settings screen. Writing an empty array back therefore left the probe's own fake key
  in place, and the probe cheerfully reported a clean-up it had not done; it was caught later by
  asking the site what its mail settings were, and the answer was a key that was not the owner's. The
  option is now deleted when it did not exist before, and the probe reads the settings back and prints
  them instead of asserting them. There is no longer a `bookify_mail` option at all — the settings
  screen holds no secret to read back.
- **Follow-up, 2026-09-13, the mail path driven from the real form — and the one thing it cannot do.**
  Bookings were submitted through `/book/` in a browser: session, date and time chosen, name, address
  and phone typed, *Request booking* pressed.
  **First fault: the running container had never seen the key.** `bookify-wordpress-1` was created at
  epoch 1789271928; `.env` was last written at 1789272022, **94 seconds later** — so the site was
  serving with an empty value while the key sat correctly in the file. Measured inside that container:
  `getenv("RESEND_API_KEY")` gave length **0**, while a `wpdev wp` call gave length **36**. That is a
  trap in the tooling, not in the plugin: `wpdev wp` creates a **fresh** `cli` container on every
  invocation and so always sees the current `.env`, which makes it worthless as evidence about what the
  *site* is running with. `wpdev restart` fixed it. The signature to recognise next time: with no key
  the Resend branch is skipped entirely, so `wp_mail()` is tried and **nothing at all is recorded** in
  `bookify_mail_last_result` — a *missing* result rather than a failure means "not configured", and
  points at the container, never at Resend.
  **Second fault: Resend will not send from a free-mail address.** With the key live the confirmation
  came back `HTTP 403 — The gmail.com domain is not verified`. Every `@gmail.com` sender fails this
  way; no free-mail domain can ever be verified. The account's only domain, `ctudlms.com` (added
  2024-12-02), was `status: failed` — and it could never have verified: it is **NXDOMAIN** against
  8.8.8.8, 1.1.1.1 and 9.9.9.9, with no NS and no SOA, so it is no longer registered at all. The owner
  removed it, which leaves the account with no domains.
  **What the site does — the owner's actual requirement, checked rather than assumed.** The recipient
  is whatever was typed in the form, and nothing is hard-coded: `owner@example.com` appears nowhere as
  a destination. Captured from the request the plugin hands to Resend, for two bookings whose form
  addresses differ: form `tester@example.com` → `to: tester@example.com`; form `client@example.com` →
  `to: client@example.com`. The refused message is addressed correctly; it is the **sender** Resend
  rejects.
  **The limit, in Resend's own words:** `onboarding@resend.dev`, the sender it accepts with no verified
  domain, may only deliver to the address that owns the account — `HTTP 403 — You can only send testing
  emails to your own email address (owner@example.com)`. No customer address can receive through it.
  The fix is a verified sending domain, and then no code changes at all: one line in `.env` and
  `wpdev restart`.
  **Delivered, not merely accepted.** A send to the account's own address returned `HTTP 200` with a
  message id, and reading that id back from the API gave `last_event: delivered` — from `Reyvand Jasper
  Medrano <onboarding@resend.dev>` to `owner@example.com`, subject `Booking BK-00639 received`. The
  account was inspected without ever printing the key, using `GET /domains`, `GET /domains/{id}` and
  `GET /emails/{id}` with the key read from `getenv()` inside the container.
  **The message itself was looked at, not counted.** The exact `html` part was captured with the reply
  stubbed through `pre_http_request`, so the capture sends nothing, then written to a file and opened:
  a dark green header band reading *Bookify*, the heading *Booking received*, a greeting naming the
  person from the form, then Reference, Booking, Date, Time and Guests taken from the same values, a
  centred pill button carrying the manage link, and the studio's name, address, phone and email in the
  footer. The stub restores `bookify_mail_last_result` afterwards, so the settings screen still shows
  the real refusal rather than a fabricated success.
  **Open, and told to the owner:** until a domain is verified, the form still tells every customer
  *"we will confirm by email"* while no customer address can be reached. Test bookings were removed,
  `uploads` still holds 32 files, and `wpdev smoke` is 10/10.
  **A second bug found the same day, and deliberately left alone — `Reply-To` never survives the trip
  to Resend.** The header line `Reply-To: <name> <address>` is composed in `includes/emails.php`, but
  `bookify_booking_mail_headers()` builds its lookup with the key `reply_to` and then queries it with
  `strtolower( trim( $name ) )`, which for that header is `reply-to` — hyphen, not underscore. The two
  never match, so the branch that copies it onto the request is never taken. Measured: the parser
  returns `reply_to` empty for a perfectly ordinary pair of headers, and the request captured for a
  real confirmation carries exactly `from, to, subject, text, html` — which is also what the owner's
  own Resend log showed, and is how it was noticed. **Left unfixed on purpose**, because repairing the
  lookup would not fix the header, it would only start honouring it: the confirmation sets `Reply-To`
  to the *customer's own* address, so a customer who pressed Reply would be writing to themselves,
  which is worse than the field being dropped. Whether that header should carry the studio's address
  or none at all is the owner's decision, not a guess to make in code — and the site is a local test
  rig, where no reply can reach anyone either way. Recorded here so a live launch does not inherit it
  silently: **before this site emails real customers, decide the reply address and fix both halves**.
- **Follow-up, 2026-09-13, at the owner's request: every message can go to one inbox.**
  Resend refuses every recipient but the account owner until a domain is verified, so this site was
  taking bookings through the form and delivering none of them anywhere its owner could see.
  `BOOKIFY_MAIL_REDIRECT_TO` diverts every message that leaves through `bookify_booking_send_mail()` —
  which is all of them, confirmations, manage links, reminders and event summaries alike — to one
  address, and names the address it was meant for in the subject:
  `[from client@example.com] Booking BK-00649 received`. Empty is off, and off is what a live site
  wants, so it stays configuration rather than code: `.env`, carried into the container by the same
  `x-wordpress-env` anchor as the key, and shown on the settings screen under *Sending everything to*
  whenever it is on.
  **A diverted confirmation is re-addressed to the studio in words as well as in headers.** It opens
  *A booking from Test Client* rather than *Thank you, Test Client — we have your booking.*, because the
  studio is not the party being thanked, and it carries the client's address inside it — a `Client
  email` row and a text line — because the envelope no longer does and the studio has to be able to
  write back.
  Both are conditional, and both were checked the other way round: with the redirect pinned off, the
  same booking still goes to `client@example.com`, keeps the plain subject, still opens *Thank you, …*,
  and has no `Client email` row. A test feature that changes what a real customer receives would not
  be a test feature.
  **The sentences written to the client and to nobody else come out too** — *We will be in touch if
  anything needs to change.* and the note under the button, *This link is yours alone…* — since
  arriving at the studio they read as a letter addressed to somebody else. The same link note is left
  out of the redirected manage-link message. The HTML builder now skips the note paragraph entirely
  when the note is empty, rather than emitting an empty `<p>` that leaves a gap under the button.
  Measured side by side, same booking, redirect on then off: diverted → `to owner@example.com`,
  both sentences absent from the text *and* the HTML, no leftover paragraph; normal →
  `to client@example.com`, both present in both parts.
  **And the button goes with them.** The manage link is the client's way back to their own booking,
  and a message landing at the studio does not need it — the studio has the bookings list — so a
  diverted confirmation carries neither the button nor the text line that introduced the URL. The
  redirected *manage link* message keeps its own button deliberately: that message exists to carry
  the link, and stripping it would leave nothing to send. Measured the same way: diverted → no
  `manage-booking` URL and no *See, move or cancel it* in either part; normal → both present. The
  diverted message is now a plain notification: a header, *Booking received*, *A booking from <name>*,
  the reference, the session, the date, the time, the party and the client's address. Plugin 0.6.4.
  Measured with the reply stubbed: form `tester@example.com` → the request goes to the redirect
  address, the subject reads `[from tester@example.com]`, and the body still greets the customer by
  name. Then sent for real: `HTTP 200`, and the id read back from the API as `last_event: delivered`.
  **Two probes had to be told, and one of them was already broken.** `stage8-reminders.php` and
  `stage7-summary.php` both assert a message's own recipient, so each now pins the redirect off with
  `putenv( 'BOOKIFY_MAIL_REDIRECT_TO=' )` instead of inheriting whatever `.env` says — a probe should
  fix the configuration its claims depend on. Doing that surfaced a **stale assertion**: check 2b of
  stage 8 still expected the reminder to say `Session: <title>` when the composer has said
  `Booking: <title>` since that label was generalised to cover events as well. The code's own comment
  says so, and the confirmation's table uses the same word, so the probe had been failing for reasons
  that had nothing to do with reminders — and was quietly wrong about what the site does. Corrected to
  `Booking: `; both probes now report zero failures.
  **The settings screen was checked by rendering it.** Calling the admin render function in a CLI
  context without `wp-admin/includes/template.php` answers with "Call to undefined function
  submit_button()" and looks exactly like a broken page, so the check loads the admin includes first.
  With them: 17,702 bytes rendered, 33 of each of `<tr>`, `<td>` and `<th>` open and close, and the new
  row present alongside *Sending through*, *Sent from* and *Last message*. Worth keeping, because the
  first attempt at that row removed part of the one below it — `php -l` cannot see a lost `</td>`, and
  only the render caught it. Plugin version 0.6.1.

- **Goal** — a created booking produces a confirmation addressed to the customer, with the
  reference, service, date and time in it.
- **Depends on** — T3. **Parallel with** — T5, T6.
- **Context to load** — T3's function; the plan's decision D6 (nothing can send from a container, so
  the acceptance is the composed message, not delivery).
- **Do**
  1. Add `includes/emails.php`: compose the subject and body from the booking, and call `wp_mail()`
     once. Use the admin address as the sender and a reply-to the customer if there is one.
  2. Call it from the write path after the booking exists, never before.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — SMTP configuration, any mail provider account, retry queues, logging tables.
- **Acceptance criteria**
  1. A `pre_wp_mail` capture shows one call per booking, with the customer's address, a subject
     naming the booking, and a body containing the reference and the date.
  2. A failed booking sends nothing.
- **Verify** — `wpdev wp eval` with a `pre_wp_mail` filter that records and returns `true`, then the
  captured values printed; `wpdev smoke`.
- **Size** — S

### T8 — Bookings readable in wp-admin

- [x] done
- Evidence 2026-09-12: new `includes/admin-bookings.php`, required from the bootstrap and registered
  by `bookify_booking_register_admin()`. The list shows exactly the columns asked for — `Reference`
  (the title column, renamed, because the row actions hang off it), `Service`, `Date and time`,
  `Customer`, `Status`; the publish-date column is dropped, and `bookify_booking_fields()` is the one
  list of the nine stored keys, so the list and the meta box cannot disagree. Loaded in a real browser
  session: `/wp-admin/edit.php?post_type=bookify_booking` → `BK-00023 / Deep tissue massage /
  2026-09-20 11:30 / Browser Tester + browser@example.com / pending`, then `BK-00021` and `BK-00020`
  (`/tmp/bookify-t8-list-1440.png`, `/tmp/bookify-t8-list-390.png`); the edit screen of 23 shows all
  nine stored values, read-only (`/tmp/bookify-t8-edit-1440.png`). Sorting the date column:
  default order `23 21 20` (submitted), `orderby=bookify_date&order=asc` → `20 21 23`,
  `desc` → `23 20 21`, so the column sorts by the booking's own date (bookings 20 and 21 share a date,
  so their order between themselves is unspecified). A booking with no meta at all renders `—` in
  every field and every cell with no new line in `debug.log`
  (`hand-made` post, deleted afterwards). The screenshots needed a session, so the browser work used
  cookies minted for the admin through `wp_generate_auth_cookie()` rather than a typed password; the
  `auth` cookie is the one `/wp-admin/` validates. `wpdev smoke` 10/10.
- Kept read-only as the task asks: the meta box prints fields only, with one line saying so, so there
  is no path from wp-admin that rewrites a booking that a customer submitted.

- **Goal** — the Bookings list shows reference, service, date and time, customer and status, and an
  operator can open one booking and read every field.
- **Depends on** — T3. **Parallel with** — T4–T7.
- **Context to load** — T2's meta keys; T3's write path.
- **Do**
  1. Add the list columns and their content, plus a sensible sort for the date.
  2. Add a read-only meta box on the booking edit screen showing every stored field, escaped.
  3. Do not make the fields editable here — that is an editing feature nobody asked for yet.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — an editing UI, bulk actions, exports, notifications on status change.
- **Acceptance criteria**
  1. The Bookings screen shows the five columns with the booking's real values.
  2. The edit screen shows every stored field.
- **Verify** — load `/wp-admin/edit.php?post_type=bookify_booking` and one edit screen in the
  browser, with a screenshot each; `wpdev smoke`.
- **Size** — S

---

## Stage 1.5 — the site around the form

### T9 — The owner can enter a service, and three exist

- [x] done
- Evidence 2026-09-12: new `includes/service-fields.php`, required from the bootstrap and registered
  by `bookify_booking_register_service_fields()`; `bookify_booking_service_fields()` is the one list
  of the three keys, read by both the box and the save handler, so a field cannot be rendered and
  then not saved. Services are edited in the **block editor** (they are `show_in_rest`, unlike
  bookings) and the box still saves: driven in a real browser session, the sidebar shows
  `Service details` with `Price / Length in minutes / Guests one slot can take` holding the stored
  `150 / 75 / 2`; typing `155.50` and pressing the editor's Save button ("Post updated.") then
  reloading reads back `155.5` — the registered sanitiser casting to float — and the price was put
  back to `150` afterwards (`/tmp/bookify-t9-service-1440.png`). Missing and wrong nonces change
  nothing, and neither does a valid nonce with no `edit_post` capability: four bad calls left
  `85 / 60 / 2` untouched and only the real save wrote `95.5 / 75 / 3`; a price sent as an array is
  ignored, with no new `debug.log` line. Measured rather than assumed: the sanitisers **do** run
  inside `update_post_meta()` (`wp-includes/meta.php:223` calls `sanitize_meta()`), so
  `'85abc' → 85`, `'60abc' → 60`, `'2,5' → 2` — which is why the handler writes the unslashed value
  and casts nothing itself. An emptied box deletes the meta instead of storing zero, so "leave it
  empty" means no price rather than `0.00`. Three published services now exist —
  `19 Deep tissue massage 85/60/1`, `85 Aromatherapy massage 75/60/1`, `86 Couples massage 150/75/2` —
  each with a real paragraph of copy (42–51 words) and no images; the two new ones were created
  through the `create-post` ability, which reported
  `meta_applied: [bookify_price, bookify_duration, bookify_capacity]`. `/book/` now offers all three.
  `wpdev smoke` 10/10. (A first version of the test script addressed service 19 instead of its probe
  and emptied its price and length; both were put back to 85 and 60 in the same session.)
- Note for T10/T11: the block editor's first-visit "Welcome to the editor" modal swallows clicks, so
  anything driving that screen has to dismiss it first.

- **Goal** — a person in wp-admin can set a service's price, length and capacity without an agent,
  and three real services exist so the site is not a one-item demo. Today they cannot: the three
  keys are registered meta, WordPress renders no field for registered meta, and `bookify_service`
  does not even declare `custom-fields` support, so the only way to price a service is `wp eval` or
  an agent.
- **Depends on** — T2. **Parallel with** — T5–T8.
- **Context to load** — `includes/post-types.php` (the three service meta keys, their types and
  their sanitisers); the `wordpress-best-practices` skill (meta box nonce, capability, escaping,
  and the four-argument sanitise callback trap); `docs/bookify-plan.md` D1.
- **Do**
  1. Add `includes/service-fields.php`: one `add_meta_box()` on `bookify_service` containing a
     nonce field and inputs for `bookify_price`, `bookify_duration` and `bookify_capacity`.
  2. Add the `save_post_bookify_service` handler: check the nonce, check `edit_post`, `wp_unslash()`
     the three values, and let the **registered** sanitisers do the typecasting — never write
     `$_POST` straight into `update_post_meta()`. Save nothing when the nonce is absent, which is
     what protects the autosave and bulk-edit requests too.
  3. `require_once` the file from the bootstrap and hook both callbacks, so the plugin still loads
     without a fatal when the meta box is never rendered.
  4. Create three services as **content** (WP-CLI or the `wordpress` abilities), with real copy: a
     name, a paragraph of description, a price, a length and a capacity. No featured images — the
     grid in T10 must look right without them.
- **In scope** — `wp-content/plugins/bookify-booking/**`; three `bookify_service` entries.
- **Out of scope** — a settings screen (D7 owns the only one); repeatable fields; per-service
  templates; images.
- **Acceptance criteria**
  1. A service edit screen shows the three fields with the stored values.
  2. Saving with a missing or wrong nonce changes nothing.
  3. Three published services exist, each with price, duration and capacity set.
- **Verify** — open a service in wp-admin, change the price, save, reload, read it back; then
  `wpdev wp post list --post_type=bookify_service --fields=ID,post_title,post_status`;
  `wpdev wp eval` printing each service's three meta values; `wpdev smoke`. `php -l` on each
  changed file.
- **Size** — M

### T10 — The services a visitor can read

- [x] done
- Evidence 2026-09-12: new `includes/service-list.php` — `bookify_service_list_defaults()`,
  `bookify_service_list_html()` and a `[bookify_services]` shortcode over it, all copy coming from
  arguments with the booking page as the default `booking_url`. Each row prints the service title,
  the price and length, its excerpt and a `Book this` link to `/book/?bookify_service=<id>`, with an
  `aria-label="Book <name>"` so repeated link text still names its service. So the two cannot format
  one number differently, `bookify_booking_service_price_and_length()` and
  `bookify_booking_service_meta_label()` were split out of `bookify_booking_service_label()` in
  `booking-form.php` — the form's own output is unchanged, and T6's per-row overrides still work.
  `includes/elementor-widget.php` gained `Bookify_Service_List_Widget` (heading, intro, currency,
  button label and empty text as controls) and the bootstrap registers both widgets from one
  callback (`bookify_booking_elementor_widgets`). Page 95 (`/services/`, slug `services`) holds it and
  is published. `/services/` answers 200 and lists exactly the three published services —
  `Aromatherapy massage 75.00 — 60 min`, `Couples massage 150.00 — 75 min`,
  `Deep tissue massage 85.00 — 60 min` — with `?bookify_service=85`, `=86` and `=19`, the ids this
  site's `wp post list` reports; with the three set to draft the page prints one
  `bookify-services__empty` line and no rows, restored afterwards. The shortcode with
  `heading="Our treatments" button_label="Choose"` applies both overrides and still lists three.
  Screenshots at 1440x900, 768x1024 and 390x844 (`/tmp/bookify-t10-services-*.png`): legible at all
  three widths, no overflow — and **unstyled**, which is expected: T11 styles `.bookify-booking*`
  only and T13 owns the grid's design, while T10's own scope excludes a listing design. With
  Elementor deactivated the site still boots, `bookify_service_list_html()` still returns three rows
  and three links, and `/services/` answers 200; reactivated, both widgets are back and the page
  renders three rows. `wpdev smoke` 10/10.
- Bug found and fixed 2026-09-13: the `Book this` link **404'd**, and had done since T10. The evidence
  above read the links out of the markup but never requested one, which is how it survived. The cause
  is a name collision: `bookify_service` is this project's post type key, and **registering a post type
  makes that key a public query var**, so WordPress read `?bookify_service=19` as "the session whose
  slug is 19", found nothing, and answered 404 in `WP::parse_request()` — before any of this plugin's
  code ran, which is why the form never saw the argument either. Measured: `/book/?bookify_service=19`
  → **404**, `=86` → **404**, `?foo=bar` → **200**, `?bookify_tier=415` → **200** (`bookify_tier` is
  `publicly_queryable => false`, so its key never became a public query var). The argument is now
  `bookify_service_id` — the name this form's own field already uses, and one WordPress does not claim
  — in `service-list.php` (the link) and `booking-form.php` (the reader), with `_probes/fill-day.php`
  following. All six listing links answer **200**, and clicking each card in a real browser lands on
  `/book/?bookify_service_id=<id>` with that session selected and **its own times** (19 → 10:30, 11:00;
  86 and 85 → 09:00, 09:30 — different, so the preselect is real and not the default). Old
  `?bookify_service=` URLs still 404 and cannot be rescued: WordPress owns the name.

- Correction to D9, measured: `publicly_queryable => false` **alone does not make
  `/services/<slug>/` a 404**. Core's `WP_Post_Type::add_rewrite_rules()` gates the query var on
  `is_post_type_viewable()` but writes the `services/([^/]+)` rewrite rule whenever `rewrite` is not
  false, so the path matched a rule whose query var was then ignored and the blog index answered
  **200**. Removing the rule too (`'rewrite' => false`) is what produces the 404, verified with
  `wp rewrite list --match` before and after: `/services/aromatherapy-massage/` and
  `/services/couples-massage/` are 404 now, `/book/` is still 200, and the post type is still public
  and still listed by the abilities. Stage 6's D15 reverses both flags together.

- **Goal** — a Services page lists every published service with its price and length, and every row
  links to the booking form with that service already chosen.
- **Depends on** — T9 (services exist) and T5 (the widget pattern and the `bookify` category).
  **Parallel with** — T11–T14.
- **Context to load** — T4's `bookify_booking_form_html()` (this task must not duplicate it);
  `bookify_booking_service_label()` in `includes/booking-form.php`, which already formats
  "title — price — minutes"; T5's widget registration; `docs/bookify-plan.md` D9.
- **Do**
  1. Add `includes/service-list.php`: `bookify_service_list_html( array $args = array() )` returning
     escaped markup — a query for published `bookify_service` posts, each rendered as a row with
     its title, the price and length from `bookify_booking_service_label()`, its excerpt, and a
     "Book this" link carrying `?bookify_service=<id>` (the argument T12 consumes).
  2. Register `[bookify_services]` as a thin wrapper over that function, with a default for every
     string it prints — the same shape as T4.
  3. Register a `bookify_service_list` Elementor widget in the existing `bookify` category, built
     the way T5 built the form widget: heading, intro, button label and empty text as controls,
     registered on `elementor/widgets/register` behind the same `class_exists` guard.
  4. Set `publicly_queryable => false` on `bookify_service` (D9) so no bare `/services/<slug>/`
     renders the theme's fallback, then flush rewrites.
  5. Create and publish the Services page holding the widget.
- **In scope** — `wp-content/plugins/bookify-booking/**`; the Services page.
- **Out of scope** — per-service landing pages (this task defers them to Stage 6, where D15 reverses
  D9); querying bookings; a second listing design.
- **Acceptance criteria**
  1. The Services page answers 200 and lists exactly the published services, each with its price
     and length.
  2. Each row's link carries that service's id in `bookify_service`.
  3. `/services/<slug>/` for a published service answers 404 rather than rendering a bare page.
  4. The plugin still loads with Elementor deactivated.
- **Verify** — `curl -s http://localhost:8890/services/ | grep -o 'bookify_service=[0-9]*'` against
  the ids from `wp post list`; `wpdev wp post-type list --fields=name,public,publicly_queryable`;
  `curl -o /dev/null -w '%{http_code}'` on a real service slug; a screenshot at three widths with the
  `visual-testing` skill; `wpdev smoke`.
- **Size** — M

### T11 — The form, styled

- [x] done
- Evidence 2026-09-12: new `assets/booking-form.css` (4,693 bytes) `wp_register_style()`d by
  `bookify_booking_register_form_style()` on `wp_enqueue_scripts` and enqueued from
  `bookify_booking_form_html()`, so only a request that renders the form loads it: `/book/` links
  `booking-form.css?ver=0.1.0` (1 match, HTTP 200, `text/css`) and `/services/` matches it 0 times.
  Every selector is `.bookify-booking*`, nothing is `!important`, and the theme is the source of
  the look — measured in the browser, not assumed: `font-family` computes to `Roboto`, the wrapper
  colour to `rgb(122,122,122)` (`--e-global-color-text`), the button background to
  `rgb(84,89,95)` (`--e-global-color-secondary`) with white text (7.4:1), and the focus ring to
  `3px solid rgb(110,193,228)` (`--e-global-color-primary`) at `outline-offset: 2px` on all seven
  controls and on the button. Each variable carries a fallback, so the form still has a design with
  Elementor deactivated. Layout measured with the Playwright-bundled Chromium at real viewport
  widths: 1440 → `grid-template-columns: 312px 312px`, 768 → `292px 292px`, 390 → `370px`, with
  `document.documentElement.scrollWidth === window.innerWidth === 390` and the button 370px wide at
  390 / 192px at 1440 (`min-width: 12rem`). Screenshots at all three widths
  (`/tmp/bookify-t11-{1440,768,390}.png`) plus the two notice states
  (`/tmp/bookify-t11-success-390.png`, `/tmp/bookify-t12-error-390.png`): success reads
  `border-inline-start: rgb(97,206,112)` (the kit accent) on a green-tinted background, error is
  `#b32d2e` on a red-tinted one — the palette has no red, so that one colour is literal rather than
  themed. `wpdev smoke` 10/10.
- Trap recorded: a `getComputedStyle()` read taken immediately after focusing reports Chromium's
  focus ring **mid-animation** — here `3px solid rgb(87,101,110)` at `outline-offset: 1px`, a value
  no rule declares. The settled reading is the declared one. Wait before measuring, or the numbers
  lie about a colour that is in fact correct.
- **Follow-up, 2026-09-13, from the owner: "it can't be seen"** — the one thing a visitor sees after
  a booking succeeds was invisible in dark mode. Both coloured notices mixed their background with a
  **literal `#fff`** (`color-mix(in srgb, var(--bookify-success) 12%, #fff)`), which is correct in a
  light scheme and produces a **near-white** panel in a dark one — while the text stays
  `--bookify-ink`, which in dark mode is near-white too. Measured by forcing the old declaration in
  the browser against the live element: **1.06:1** in dark, where the same notice measures **12.45:1**
  in light. The fix is one word — mix towards `--bookify-field-bg`, the same scheme-aware token the
  fields use — and the numbers are now **12.45 / 13.04 light** and **12.05 / 13.18 dark** for the
  success and error variants, every one far past AA. Light mode is unchanged, deliberately.
  **Why it was missed, which is the part worth keeping:** T37's dark-mode pass rewrote the *tokens*
  and its contrast script read the token block out of `style.css`, so a literal sitting inside a
  component could not appear in it; and a plain audit of `/book/` cannot see the notice at all,
  because it is not in the DOM until a submission lands. **The states are addressable** —
  `?bookify=booked` and `?bookify=rejected` render the success and error notices with no booking and
  nothing to clean up — so the check now points at the states rather than at the page. Plugin
  `BOOKIFY_BOOKING_VERSION` 0.4.1. `wpdev smoke` 10/10; `_elementor_data` md5s unchanged.

- **Goal** — the booking form is a designed component: labelled fields in one column at 390 px and
  two at 768 px and up, a notice that reads differently for success and for each error, and a submit
  button that reads as the primary action. The stylesheet ships with the plugin (D8) and is loaded
  only where the form renders.
- **Depends on** — T4. **Parallel with** — T9, T10, T12, T13, T14.
- **Context to load** — `includes/booking-form.php` for the exact class names it already prints;
  the `visual-testing` skill; `docs/bookify-plan.md` D8.
- **Do**
  1. Add `assets/booking-form.css`, `wp_register_style()`d on `wp_enqueue_scripts` and enqueued
     from `bookify_booking_form_html()` — WordPress prints a style enqueued after `wp_head` in the
     footer, so a page without the form never loads it and no template has to change.
  2. Style only the `.bookify-booking*` classes. No theme class, no Elementor class, no framework,
     no `!important`. Take the typography and colours from the theme so the form belongs to the
     site rather than sitting on top of it.
  3. Keep focus visible: never remove an outline, use `:focus-visible` to strengthen it, and make
     sure every control is reachable in order with the keyboard.
  4. Guard any transition behind `prefers-reduced-motion`.
- **In scope** — `wp-content/plugins/bookify-booking/**` (a new `assets/` directory).
- **Out of scope** — the theme (D8); Elementor global classes or variables; a visual redesign of
  anything the form does not contain.
- **Acceptance criteria**
  1. At 390, 768 and 1440 px the form is legible, un-cramped, and causes no horizontal overflow.
  2. The success notice and an error notice are visually distinguishable.
  3. Every control shows a visible focus indicator.
  4. A page without the form does not reference `booking-form.css`.
- **Verify** — the `visual-testing` skill at all three widths, with real screenshots and
  `document.body.scrollWidth` against `window.innerWidth` at 390; on this machine the pane cannot be
  resized to a wide width, so measure a real 1440 px layout with the Playwright-bundled Chromium as
  that skill describes. `curl -s http://localhost:8890/ | grep -c booking-form.css` → 0.
  `wpdev smoke`.
- **Size** — M

### T12 — The form's manners

- [x] done
- Evidence 2026-09-12: `bookify_booking_error_field()` (in `bookings.php`, beside the codes, so a new
  code has to name its field) plus a new `bookify_booking_form_field()` that renders one labelled
  control — the single place the error state is applied, so the notice and the field can no longer
  disagree. Over HTTP, a submission with `bookify_email=not-an-email` redirected to
  `?bookify=error&bookify_code=bookify_invalid_email&bookify_token=tGkgAMD0JjJRb2QoM9mb` and the form
  came back with the service still selected (`<option value="19" selected>`), date `2026-09-20`,
  time `15:45`, name `Keep Me`, email `not-an-email`, phone `555 0100`, party `3`, the notice in
  `<div class="bookify-booking__notice ..." role="status" aria-live="polite">`, the email field
  wrapped in `bookify-booking__field--invalid` with `aria-invalid="true"` and
  `aria-describedby="bookify_email_message"`, and the message span inside that same field. Nothing
  was stored: 3 bookings before and 3 after. Reading the same token a second time returned an empty
  name — the transient is deleted on first read, and the values are never in the URL. In a real
  browser at 390 the same case kept all seven values and still had
  `documentElement.scrollWidth === window.innerWidth === 390`. Preselect: `?bookify_service=85` →
  `<option value="85" selected>`, `?bookify_service=99999` → 0 selected attributes, because the
  options are the services. Honeypot: `bookify_website` is visually hidden with `clip-path` (not
  `display:none`), `tabindex="-1"`, `aria-hidden="true"` and **no label text**, so this task added no
  copy and therefore no Elementor control; filled, it answered `302 → ?bookify=rejected`, identical
  to a missing nonce, and created no row. Keyboard: Tab reaches select → date → time → name → email
  → phone → guests → button in DOM order, each with the focus ring from T11 (the extra stops inside
  the date and time inputs are the browser's own sub-fields). The mapping is per field and not just
  the email: `party_size=0`, `date=2020-01-01`, `time=99:99` and an empty name each came back with
  their own message and their own field wrapped (`bookify_party_size` + `…_message`,
  `bookify_date`, `bookify_time`, `bookify_name`), and none of the four created a row (3 bookings
  before and after). Screenshot of the rejected state:
  `/tmp/bookify-t12-error-390.png`. `php -l` clean on both changed files; `wpdev smoke` 10/10.

- **Goal** — a rejected submission keeps every value that was typed and says why in a way a screen
  reader announces, a service followed from a service row arrives pre-chosen, and a bot that fills
  the hidden field is refused.
- **Depends on** — T4 and T5. **Parallel with** — T10, T11, T13, T14.
- **Context to load** — T3's redirect shape and its error-code map; `bookify_booking_error_messages()`
  (the single source of truth for codes and wording) and `bookify_booking_form_defaults()` in
  `includes/booking-form.php`; T10's `?bookify_service=` links.
- **Do**
  1. Render the notice in an `aria-live="polite"` region so a rejected submission is announced
     rather than silently reloaded, and give each field a message element tied to it with
     `aria-describedby` when the error code names that field.
  2. Stop losing the submission: keep the redirect, and carry the values in a short-lived transient
     keyed by a random token added to the redirect URL, deleted on first read. Never put the typed
     values themselves in the query string — they would land in browser history and in the server
     log — and never re-populate a field the visitor did not fill.
  3. Preselect the service from `?bookify_service=<id>`, validated against the published services so
     a hand-made argument cannot inject anything.
  4. Add a honeypot: one visually hidden input that must stay empty. Reject a filled one through the
     existing `?bookify=rejected` path so a bot learns nothing about why. Do not add a CAPTCHA.
  5. Every string this task adds is copy like any other: if T5's widget already exists, it gains a
     control for each one in the same change. No new string is printed literally.
- **In scope** — `wp-content/plugins/bookify-booking/**`, including the Elementor control for each
  string this task adds when T5 has already landed.
- **Out of scope** — JavaScript-only validation (the server stays the guard); rate limiting and
  CAPTCHA (T19 owns both); restyling beyond what T11's stylesheet needs for the new elements.
- **Acceptance criteria**
  1. A submission with a bad email returns the form with the email and every other value still
     filled, and the message is tied to the email field.
  2. Arriving from a service row has that service selected in the form.
  3. With the honeypot filled, no booking is created and the response is indistinguishable from a
     missing nonce.
  4. The form can be completed with the keyboard alone.
- **Verify** — a browser run at three widths with `form.noValidate = true` for the bad-email case
  (`type="email"` stops it earlier, as T4 found); `wpdev wp post list --post_type=bookify_booking`
  before and after the honeypot case to prove nothing was created; `curl` the page with
  `?bookify_service=<id>`; `wpdev smoke`.
- **Size** — M

### T13 — A real front page

- [x] done
- Evidence 2026-09-12: page **111 `Home`** created and published through the Elementor abilities —
  `render-layout-recipe` for the `hero` and the `cta-band`, then `create-elementor-page`, then
  `get-page-structure` to read it back: 4 top-level containers, 13 elements,
  `built_with_elementor=yes`, `hide_title=yes`, no `_elementor_data` written by hand. The two
  middle sections are built from the bridge's own `Widget_Catalog` builders (the same class the
  recipes use), never from hand-written settings. Copy is the studio's own: "Book a massage in a
  minute" / "Massage, without the back-and-forth" / "Ready when you are", and both buttons point at
  `/book/`. `show_on_front=page`, `page_on_front=111`; `Sample Page` (2) and `Hello world!` (1) are
  **trashed**, and `/sample-page/` and `/hello-world/` both answer **404**. `/` answers 200, is a
  page and not the blog index, carries two links to the booking page, and lists exactly the three
  services with `?bookify_service=19|85|86` (`wp post list --post_type=page` → Home 111, Services
  95, Book 22, Contact 116, Booking form (widget) 31 draft). Screenshots with the Playwright-bundled
  Chromium at real widths — `/tmp/bookify-t13-home-{1440,768,390}.png`, plus the grid and the
  closing band scrolled into view as `/tmp/bookify-t13-grid-*.png` and `/tmp/bookify-t13-cta-*.png`:
  hero band, the copy section, three cards, then the dark call-to-action band.
- Note recorded here because this task inherited it: T10 left the listing unstyled and said T13 owns
  the grid's design. This task therefore added `assets/service-list.css` (3,061 bytes), enqueued
  from `bookify_service_list_html()` the same way T11 enqueues the form's — the same reason (D8),
  the same tokens, so the two components read as one design. Measured: the list is
  `364px 364px 364px` at 1440, `348px 348px` at 768 and `342px` at 390, the three `Book this` links
  line up across the cards (`margin-block-start: auto`, link tops 436/436/436 at 1440), and nothing
  overflows at any of the three widths.
- Honest substitution: this session had no `elementor-*` MCP tools available, so the layout went
  through the `wp-agent-bridge` Elementor abilities instead — the same Document API and the same
  `Elementor_Document::save()`, which is what D10 and `CLAUDE.md` rule 2 actually require.
- `wpdev smoke` 10/10; nothing from the plugin or the theme in the container's `wp-content/debug.log`
  (85 lines, all of them wp-cli's own `usort()` deprecations and one `wp eval` warning).

- **Goal** — `http://localhost:8890/` is a designed home page — hero, what the business offers, the
  services grid, a closing call to action — and it is what the site's front page is set to.
- **Depends on** — T5 and T6 (the form and listing widgets, which the page uses), T10, T11, T12.
  **Parallel with** — T14.
- **Context to load** — `CLAUDE.md` rule 2 and the `elementor` MCP server's tools
  (`elementor-create-page`, `elementor-build-composition`, `elementor-manage-elements`,
  `elementor-publish-document`); the `visual-testing` skill; `docs/bookify-plan.md` D10.
- **Do**
  1. Lay the page out with the `elementor` MCP server — never by writing `_elementor_data`: a hero
     with a heading, one concrete sentence and a primary "Book now" button; a short section saying
     what the business does; the `bookify_service_list` widget for the grid; a closing call-to-action
     band. Real copy in the voice of the business, not lorem ipsum.
  2. Point the hero and closing buttons at the booking page.
  3. Set `show_on_front = page` and `page_on_front` to this page.
  4. Deal with the placeholders: unpublish or delete `Sample Page` and the `Hello world!` post, and
     leave the blog index unreachable unless a Posts page is actually wanted.
- **In scope** — the new front page and its Elementor data; the `show_on_front` options; the
  placeholder post and page.
- **Out of scope** — a blog; a page builder template; a header/footer builder layout (T14 owns the
  menus); images that are not already available in the project (no stock photography).
- **Acceptance criteria**
  1. `/` answers 200, is not the blog index, and contains a link to the booking page.
  2. The services grid shows the three published services.
  3. No "Hello world!" and no "Sample Page" is published on the site.
  4. The page renders correctly at 390, 768 and 1440 px.
- **Verify** — `curl -s http://localhost:8890/ | grep -c '/book/'` and a grep for the three service
  names; `wpdev wp option get page_on_front`; `wpdev wp post list --post_type=page`;
  screenshots at three widths; `wpdev smoke`.
- **Size** — M

### T14 — Navigation, footer and contact

- [x] done
- Evidence 2026-09-12: menus **3 `Primary`** (assigned to `menu-1`, four items) and **4 `Footer`**
  (assigned to `menu-2`, the same four), created with WP-CLI as content — `wp menu list` shows
  `Primary … menu-1` and `Footer … menu-2`, and every page renders both: the header's
  `ul#menu-primary` and the footer's `ul#menu-footer` each read Home, Services, Book, Contact.
  Below 1024px the theme replaces the header nav with its own toggle, which is the theme's
  behaviour, not a defect. `/`, `/services/`, `/book/` and `/contact/` all answer **200**, and each
  one prints the details block; the four destinations answer 200 too.
- New `includes/settings.php`: `register_setting()` for `bookify_business` with a **userland**
  sanitiser (`bookify_booking_sanitize_business()`, because WordPress passes four arguments), and
  `add_submenu_page()` under Bookings → **Settings** on `manage_options`. The form posts to
  `options.php`, so the nonce and the capability are WordPress's own. New
  `includes/business-details.php`: `bookify_booking_business_fields()` is the one field list both
  the screen and the sanitiser read, `bookify_booking_business()` returns every key, and
  `bookify_booking_business_details_html()` returns one escaped `<address class="bookify-business">`
  with the name, the address line, a `tel:` link and a `mailto:` link. It is printed on `wp_footer`
  for every page — the theme's `template-parts/footer.php` exposes no hook and a theme template file
  is out of scope for this stage (D10) — and through `[bookify_business_details]`, which is how the
  Contact page prints it in its own content.
- Driven in wp-admin with a minted session, so this is the screen and not the option: the screen
  renders all seven fields with the stored values, changing the phone to `+44 1273 555 099` saved it
  and both the footer and the Contact page then printed the new number with
  `href="tel:+441273555099"`, and setting the email to `not-an-email` **discarded** it — the field
  came back empty and the key left the option rather than being stored. `type="email"` blocks that
  submission in the browser first, so reaching the sanitiser needed `form.noValidate = true` — the
  same trap T4 recorded. The real address was put back through the same screen in the same run.
  `wp option get bookify_business` prints the stored array (name Bookify Massage Studio, 14 Harbour
  Lane, Brighton, BN1 2AB, United Kingdom, +44 1273 555 099, hello@bookify.example). Contact page
  **116** at `/contact/` was created with the abilities (heading, text, a `shortcode` widget holding
  the details, and a button to `/book/`), and its details block appears twice — once as the page's
  content, once from the footer — which is what this task asked for.
- Screenshots at all three widths for all four pages: `/tmp/bookify-t14-{home,services,book,contact}-{top,bottom}-{1440,768,390}.png`,
  plus the screen itself at `/tmp/bookify-t14-settings-1440.png`. No page overflows at 390.
- Scope note, deliberate: `assets/business-details.css` (1,239 bytes) was added. The block prints on
  every page, and without a few rules of its own it would be a bare italic `<address>` line hanging
  off the bottom of the theme's footer. It styles only `.bookify-business*`, takes the same theme
  tokens, and is not a restyle of the theme's footer.
- `wpdev smoke` 10/10; no plugin or theme error in the container's `debug.log`.

- **Goal** — every page is reachable from the header, the footer names the business with its address,
  phone and email, a Contact page exists, and those details are stored once rather than typed into
  three places.
- **Depends on** — T10, T13. **Parallel with** — T15 (which extends the screen this task creates).
- **Context to load** — the registered menu locations (`menu-1` Header, `menu-2` Footer from Hello
  Elementor); `docs/bookify-plan.md` D7, D10 and the constraint that the site's details are stored
  once; Stage 6, which will read this data for LocalBusiness schema.
- **Do**
  1. Create a `Primary` menu (Home, Services, Book, Contact) and assign it to `menu-1`; create a
     `Footer` menu with the same destinations and assign it to `menu-2`. Content, so WP-CLI.
  2. Add the business's own details as one option, `bookify_business`: name, street, locality,
     postcode, country, phone and email — stored with `register_setting` and a **userland**
     sanitising callback, because WordPress passes four arguments and an internal PHP function is a
     fatal (T2 learned this the expensive way).
  3. Add the one Settings screen both this option and T15's availability will live on: a submenu of
     the Bookings menu, `manage_options` gated, nonce checked, with the business section on it now
     and an availability section T15 adds.
  4. Add `bookify_business_details_html()` and print it in the footer and on the Contact page, so the
     address cannot drift between them. Opening hours appear in both places too, from T15's option —
     until T15 exists, leave the hours out rather than typing them twice.
  5. Create the Contact page with Elementor (D10) and a link to the booking form.
- **In scope** — the two menus; the Contact page; the Settings screen and the `bookify_business`
  option; `includes/settings.php` and `includes/business-details.php` in the plugin.
- **Out of scope** — opening hours (T15); a map embed or a third-party widget; a contact form
  (the booking form is the only form on this site); footer styling beyond the theme's own.
- **Acceptance criteria**
  1. The header shows the four destinations and each answers 200.
  2. `/`, `/services/`, `/book/` and `/contact/` all answer 200 and show the footer details.
  3. `wpdev wp option get bookify_business` returns the stored array, and a malformed value is
     discarded rather than stored.
  4. Changing the phone number in the screen changes it in the footer and on Contact.
- **Verify** — `curl -o /dev/null -w '%{http_code}'` on all four URLs; `wpdev wp menu list --fields=name,count,locations`;
  `wpdev wp option get bookify_business`; change a value in wp-admin and re-`curl` the footer;
  `wpdev smoke`.
- **Size** — M

## Stage 2 — availability you can trust

### T15 — When you are open

- [x] done
- Evidence 2026-09-12: `includes/availability.php` (option shape, `bookify_availability()`,
  `bookify_is_open_on()`, `bookify_opening_hours()`, the slot functions and
  `bookify_booking_opening_hours_html()`) plus the Availability section on
  `includes/settings.php` and the hours line in `includes/business-details.php`.
  - **Saves and reloads every field.** A form post to `options.php` as admin stored
    `{"weekdays":{"1":{"open":true,"from":"08:00","to":"18:00"},…,"6":{"open":true,
    "from":"10:00","to":"14:00"},"0":{"open":false,…}},"interval":45,"lead_hours":3,
    "days_ahead":30,"blocked_dates":["2026-12-25","2026-12-26"]}` and the reloaded screen
    printed back all seven rows (`checked` on 1–6, none on Sunday), `09:00`/`19:00` on Friday,
    `interval=30`, `lead_hours=2`, `days_ahead=60`.
  - **Malformed values are discarded, not stored.** A post with a Wednesday close time of
    `25:00` and a Thursday open time of `7:5` stored **both days closed** and neither string
    appears anywhere in the option; `blocked=2026-12-25 / 2026-02-31 / not-a-date / 2026-12-26`
    stored `["2026-12-25","2026-12-26"]`.
  - **No `manage_options`, no change.** The same form posted with a subscriber's own valid
    session nonce → **403** `Sorry, you are not allowed to manage options for this site.` and the
    stored option byte-identical.
  - **Both places show the same hours, from the option.** `/` (footer), `/contact/` (footer and
    the page's own details block) and `/book/` all printed `Monday to Friday 09:00–17:00`,
    `Saturday 10:00–14:00`, `Sunday Closed`; changing only Friday's close time to `19:00`
    changed all three to `Monday to Thursday 09:00–17:00 · Friday 09:00–19:00`; restored.
  - Bug found by the first probe: an *absent* option made `bookify_availability()` read every
    weekday as closed (no bookable day at all), and `update_option()` with the stored shape was
    silently replaced by the defaults because the sanitiser understood only the form's field
    names. Both fixed; the option now round-trips through its own sanitiser (measured).
  - `wpdev smoke` 10/10; `php -l` clean on every changed file.

- **Goal** — opening hours, slot length, lead time, how far ahead the diary opens and the blocked
  dates are stored once, editable in wp-admin, readable through one function, and rendered back out
  where a visitor sees them.
- **Depends on** — T14 (the screen this task extends). **Parallel with** — T17.
- **Context to load** — T14's `includes/settings.php`; `docs/bookify-plan.md` D7 and its reason;
  the four-argument sanitise-callback trap in T2.
- **Do**
  1. Add an Availability section to T14's screen: seven weekday rows (closed, or open and close
     times), a slot interval in minutes, a lead time in hours, how many days ahead bookings open,
     and blocked dates entered as `YYYY-MM-DD` lines.
  2. Store it as one option, `bookify_availability`, with `register_setting` and a userland
     sanitising callback that re-validates every value — a `25:00` close time or a malformed date is
     discarded, not stored.
  3. Add `includes/availability.php`: `bookify_availability()` returning the settings merged with
     defaults, `bookify_is_open_on( $date )`, `bookify_opening_hours( $date )`, and a display helper.
  4. Render the hours from that helper in the footer and on the Contact page, replacing the gap T14
     deliberately left.
- **In scope** — the Availability section of the settings screen; `includes/availability.php`; the
  footer and Contact hours display.
- **Out of scope** — slot generation and booking checks (T16); holidays per service; timezone
  conversion beyond `current_time()`.
- **Acceptance criteria**
  1. The screen saves and reloads every field.
  2. A malformed date and a `25:00` time are discarded rather than stored.
  3. A user without `manage_options` cannot reach the screen or change the option.
  4. The footer and Contact page show the same hours the screen holds: change one, and both change.
- **Verify** — save, reload, `wpdev wp option get bookify_availability`; POST the settings form as a
  user without the capability and confirm nothing changed; `curl` the footer text before and after
  an edit; `wpdev smoke`.
- **Size** — M

### T16 — The slots the form offers are the slots that exist

- [x] done
- Evidence 2026-09-12: `bookify_opening_slots()`, `bookify_available_slots()`,
  `bookify_slot_is_offered()`, `bookify_slot_remaining()`, `bookify_available_days()` and
  `bookify_booking_guests_by_day()` in `includes/availability.php`; the day and time pickers in
  `includes/booking-form.php`; the refusal codes and the advisory lock in
  `includes/bookings.php`.
  - **The form offers exactly the function's list** — compared by counting, six times: service 19
    on 2026-09-15/16/19 (15, 15 and 7 options) and services 86 and 85 on three further dates. The
    `#bookify_time` `<option>` values scraped from `/book/` matched
    `implode(' ', bookify_available_slots(…)` **character for character, 6 of 6**.
  - **Every route refuses what is not on offer**, each with its own code and no booking created
    (3 bookings before, 3 after): closed day → `bookify_closed_day`; blocked date (option) →
    `bookify_closed_day`; inside a 240-hour lead time → `bookify_slot_unavailable`; `10:15`
    (off the interval) → `bookify_slot_unavailable`; `08:30` (before opening) →
    `bookify_slot_unavailable`; `16:30` for a 60-minute service that closes at 17:00 →
    `bookify_slot_unavailable`; `25:99` → `bookify_invalid_time`; a past date →
    `bookify_invalid_date`; an unknown service → `bookify_unknown_service`.
  - **Capacity, by party size:** service 19 (capacity 1) booked at 10:00 → the slot disappeared
    from the offered list and the same slot again → `bookify_slot_full`; service 86 (capacity 2)
    with 1 guest booked, `2` more guests → `bookify_slot_full`, `1` more → booked, so the slot
    holds exactly 2.
  - **A cancelled booking gives its place back:** after cancelling, `10:00` was back in the list
    and bookable again.
  - **Two submissions at the same moment produce one booking.** Three slots, each hit by two
    concurrent `curl` posts while a third process held `bookify_slot_lock` for 4s: every pair
    came back `?bookify=booked` **once** and `?bookify=error&bookify_code=bookify_slot_full`
    once, and the day ended with exactly 3 bookings (4 including the earlier plumbing test at
    09:00). The pair on the held slot both took **3.79s**, i.e. they queued on the lock.
  - **The lock excludes a second connection** (not just reasoned about): the first connection
    held it (`GET_LOCK` → `'1'`), a second `wpdb` connection got `'0'` while it was held and
    `'1'` after the release.
  - **A date with no free slots says so.** All 15 of 2026-09-16 booked → the real page rendered
    0 time selects and the line *"Every time that day is taken. Please choose another day."*;
    a closed day says *"We are closed that day…"* instead. Deleting the 15 bookings put the 15
    slots back.
  - **In a real browser** (Playwright's Chromium, real viewports): booking by clicking **and** by
    pressing Enter in a text field both reached `?bookify=booked`; 0 horizontal overflow at
    1440/768/390/320; **0 console messages**; and with `javaScriptEnabled: false` the picker still
    changed the day's times and the form still booked.
  - Design note, measured: the picker is **its own form**, because two submit buttons in one form
    make the browser's implicit submission (Enter) fire whichever comes first — the picker's. Its
    hidden fields carry what the visitor was already given back, so a rejected visitor can change
    the day without retyping (verified). The trade-off, verified and accepted: details typed but
    never submitted are lost when the day is changed, because the day change is a page request
    (T18's calendar removes it by fetching instead).
  - `wpdev smoke` 10/10; `php -l` clean on every changed file.

- **Goal** — one function decides what is bookable, the form renders exactly its output, and the
  write path accepts nothing else — capacity and existing bookings included. This is the invariant
  the whole stage exists for.
- **Depends on** — T15, T12. **Parallel with** — T17.
- **Context to load** — T3's `bookify_create_booking()` and its error-code map; T12's form and its
  honeypot; `includes/availability.php` from T15; T11's stylesheet; the `wordpress-best-practices`
  skill.
- **Do**
  1. Add `bookify_available_slots( $service_id, $date )` to `includes/availability.php`: step the
     day's opening hours by the interval, keep a slot only when the service's length fits before
     closing, drop slots inside the lead time, drop everything on a closed or blocked date, and drop
     a slot whose bookings already reach the service's `bookify_capacity`. Count only `pending` and
     `confirmed` bookings, so a cancelled one frees its place (T17 introduces that state).
  2. Render the time field from that function instead of a free `type="time"` input: the visitor
     sees the next open days, picks one, and gets exactly that day's free times. The date input
     disappears, because it is what let anyone choose 03:17 on a Sunday. JavaScript is optional —
     the page must work with it off. This list is also what T18's calendar decorates, so keep it a
     plain server-rendered list rather than a half-built picker.
  3. Route the write path through the same function: `bookify_create_booking()` refuses a time that
     `bookify_available_slots()` does not return for that date. Add the new codes to the existing
     `bookify_booking_error_messages()` map — one source of truth for wording — for a closed or
     blocked date, a time that is not on offer, and a slot that is full.
  4. Serialise the check-and-insert with a MySQL advisory lock: `SELECT GET_LOCK('bookify_slot_lock', n)`
     through `$wpdb` before the capacity check and `RELEASE_LOCK` on every exit path, including the
     failures, so an error cannot strand the lock (D16 — verified available on this machine,
     `11.8.9-MariaDB`). State in the code what the lock covers — writers against this one database —
     and what it does not.
  5. Add the slot picker's styles to T11's stylesheet, so nothing regresses visually.
- **In scope** — `includes/availability.php`, `includes/bookings.php`, `includes/booking-form.php`,
  `assets/booking-form.css`.
- **Out of scope** — a JavaScript calendar or any third-party date library; a custom table;
  per-service opening hours; payments or deposits.
- **Acceptance criteria**
  1. The times the form offers for a date are exactly `bookify_available_slots()`'s output for it —
     compared by counting, not by eye.
  2. A hand-made POST for a slot outside opening hours, on a blocked date, inside the lead time, at a
     full slot, or for a time the function never returns is refused with its own error code and
     creates nothing.
  3. A booking at a slot reduces that slot's availability by its party size; a cancelled one gives
     the place back.
  4. A date with no free slots says so rather than showing an empty picker.
  5. Two submissions fired at the same instant for the last place in a slot result in exactly one
     booking, not two — proven by running them concurrently, not by reasoning about it.
- **Verify** — `wpdev wp eval` running each refusal and printing the error code, with the booking
  count before and after; `curl` the form and diff the offered times against the function's output;
  two releases in parallel against a slot with one place left (`xargs -P2` or two backgrounded
  `curl`s), then the booking count; a browser run booking a real slot; `wpdev smoke`; `php -l` on
  each changed file.
- **Size** — L

### T17 — The customer can cancel

- [x] done
- Evidence 2026-09-12: `bookify_cancel_token` registered in `includes/post-types.php` and written
  with every booking; `bookify_booking_cancel_url()` in `includes/emails.php`; the finder, the
  handler and the status map in `includes/bookings.php` and `includes/admin-bookings.php`.
  - **The link is in the real confirmation.** With a `pre_wp_mail` capture, the message for
    BK-00160 carried `If you need to cancel, open this link:` and
    `http://localhost:8890/book/?bookify_cancel=BK-00160&bookify_key=0ou9…`; the token is **32**
    characters and the meta key is registered.
  - **The link cancels that booking and no other:** the captured URL → `302` to
    `?bookify=cancelled` and `bookify_status` went `pending` → `cancelled`.
  - **A mangled token, a missing token and another booking's reference with this token** each
    went to `?bookify=cancel_invalid`, left BK-00160 `pending` and left BK-00020 untouched.
  - **The same link twice:** the second use → `?bookify=cancel_repeat`, still `cancelled`.
  - **The slot comes back:** `14:00` left the offered times while `pending` and was back (and
    bookable) after the cancellation.
  - **The page says which happened** on `/book/`: `--success` *"That booking is cancelled…"*,
    a neutral *"That booking was already cancelled…"*, and `--error` *"That cancellation link is
    not valid…"*.
  - **An operator can change the status in wp-admin:** the edit screen renders the Status box
    (`pending`/`confirmed`/`cancelled`, stored value selected) and a real `post.php` save moved
    `cancelled` → `confirmed` with the reference untouched. A subscriber posting the same form
    with a nonce valid *for their own session* got **500** `Sorry, you are not allowed to edit
    this post.` and changed nothing; so did an admin post whose `bookify_status_nonce` was wrong
    (the post saved, the status did not). `wp post list --post_type=bookify_booking` listed the
    three original bookings after every probe booking was deleted.
  - `wpdev smoke` 10/10.

- **Goal** — a booking has a life — pending, confirmed, cancelled — an operator can move it, and the
  customer can cancel from the confirmation email without an account.
- **Depends on** — T16 (the capacity count must respect cancellation) and T7 (the email).
  **Parallel with** — none.
- **Context to load** — T3's write path and its meta; T7's composed message; T16's slot counting.
- **Do**
  1. Register `bookify_cancel_token` meta on `bookify_booking` and write a random token with every
     booking (`wp_generate_password( 32, false )`).
  2. Add one line to T7's confirmation: a cancel link carrying the reference and the token.
  3. Handle it in a `bookify_booking_handle_cancellation()`: look the booking up **by reference**,
     compare the token with `hash_equals()`, refuse when the status is already `cancelled`, and never
     let a query argument name a post id. Redirect with a status code, as T3 does.
  4. Give an operator a way to change status between pending, confirmed and cancelled on the booking
     edit screen — nonce checked and capability checked, and nothing more elaborate than that.
  5. Confirm the slot comes back: T16's count must no longer include the cancelled booking.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — real accounts (T22); reschedule (T21, and it is a different problem from
  cancel); customer notifications on status change; a self-service dashboard.
- **Acceptance criteria**
  1. The cancel link captured from the email cancels that booking and no other.
  2. A mangled or missing token changes nothing.
  3. Using the same link twice says so and changes nothing the second time.
  4. After cancelling, the slot is offered again; before, it is not.
  5. An operator can see and change the status in wp-admin.
- **Verify** — `wpdev wp eval` with a `pre_wp_mail` capture to obtain the link, then `curl` it and
  read `bookify_status` before and after; `curl` with a mangled token; `wpdev wp post list
  --post_type=bookify_booking --fields=ID,post_title`; `wpdev smoke`.
- **Size** — M

---

## Stage 3 — a real booking interface

### T18 — The slot picker becomes a calendar

- [x] done
- Evidence 2026-09-12: `includes/rest-slots.php` adds the read-only route `bookify/v1/slots` taking
  `service` and `date`, `permission_callback => __return_true` and **stated as public on purpose**,
  returning only `bookify_available_slots()` — the bare list, e.g. `["09:00","09:30",…]`. Compared
  date by date against the function's own output through `wp eval-file` (7 dates: 2026-09-12/13/14/
  19/20, 2026-10-05, 2026-11-30): **7/7 identical**, an unknown service answers `[]`, and
  `2026-02-31` answers **400 `rest_invalid_param`**. The route was first registered from `init`,
  which WordPress complains about **on every request** — 117 notices in `wp-content/debug.log`
  ("REST API routes must be registered on the rest_api_init action"); it now hooks
  `rest_api_init` and the count stops moving (delta **0** over two requests).
- Evidence, the calendar itself: WordPress's bundled `jquery-ui-datepicker` (core 1.14.2) is
  enqueued as a dependency of the new `assets/booking-form.js`; **core registers no datepicker
  stylesheet in this version** — measured: `wp-jquery-ui-dialog`, the only jQuery UI stylesheet
  core registers, contains **0** `.ui-datepicker` rules — so the month view is dressed in
  `booking-form.css` instead of looking like a stock widget, and core still localises the month and
  day names and the date format for the site's locale.
- Evidence, in a real browser (Playwright's Chromium, 1440x900, `/tmp/bk-t18.cjs`): **31 checks,
  all pass**. The day select is `display:none` and the calendar is a real table; every clickable
  square is a day the server listed and every listed day of that month is clickable; clicking a day
  **does not reload the page** and the times become exactly the route's own list (15 vs 15 from the
  server) with the hidden `bookify_date` following; clicking an unavailable square changes nothing;
  changing the service re-fetches **that service's** times (14 vs 14) and the form that books names
  the same service and day as the times on screen; 0 horizontal overflow and the calendar inside the
  viewport at **390, 768 and 1440**; a day link takes keyboard focus and shows
  `3px solid rgb(110,193,228)`.
- Evidence, the three refusals (acceptance 2): with the diary temporarily hardened (lead 30h + a
  blocked 2026-09-16, **restored byte-identically afterwards** — `diff` clean, lead 2, no blocked
  dates, 51 days), a day inside the lead time (2026-09-12), a closed Sunday (2026-09-13) and a
  blocked day (2026-09-16) are each absent from the day list **and** from the rendered `<select>`
  **and** unclickable in the calendar (`ui-datepicker-unselectable`, rendered as a `<span>` where a
  link would be) **and** answered `[]` by the route, while 2026-09-14 still works in all four
  places. 14 checks, all pass. Unavailable days measure `rgb(122,122,122)` at opacity 0.55 against
  `rgb(84,89,95)` at 1 for the days that can be chosen.
- Evidence, JavaScript off (a Playwright context with `javaScriptEnabled: false`): the calendar is
  not visible, the 51-day `<select>` is the control, the picker still has its button, 6 times are on
  offer — and **it books**: selecting a time and submitting lands on `?bookify=booked` with the
  thank-you notice, one row created and deleted afterwards (bookings 3 before and after).
- Evidence, the widget path: the draft Elementor page 31 renders the script, the stylesheet,
  `data-bookify-days`, the calendar container and `datepicker.js`, so the widget gets the calendar
  without a second copy of anything.
- Screenshots actually looked at: `/tmp/bk-t18-picker.png`, `/tmp/bk-t18-hardened-1440.png`,
  `/tmp/bk-t18-390.png`. `php -l` clean on every file, `wpdev smoke` 10/10.
- Design decisions worth keeping: the day list is rendered onto the wrapper as data, so the calendar
  cannot invent a day the write path would refuse; the picker's own button is hidden only once the
  widget is up, and any network failure submits the picker form — the no-JavaScript path — rather
  than leaving another day's times on screen; and the day, the service and the times are written
  together in one function so those three cannot drift apart.

- **Goal** — choosing a day feels like a booking site: a month view with the days the business is
  closed, blocked or too soon visibly unavailable, and the chosen day's free times appearing without
  a page reload. The no-JavaScript path stays working, and the calendar never disagrees with the
  server about what is bookable.
- **Depends on** — T16 (the function and the fallback list it renders), T11 (the stylesheet).
  **Parallel with** — T19.
- **Context to load** — T15's settings and `bookify_available_slots()`; T16's day list and its
  fallback promise; `docs/bookify-plan.md` D12; the `wordpress-best-practices` skill (REST route
  registration, sanitising, and why `permission_callback` is never optional).
- **Do**
  1. Add a read-only REST route `bookify/v1/slots` taking `service` and `date`, validating both, and
     returning **only** the output of `bookify_available_slots()` — times and nothing else. No
     customer data, no booking ids, no counts. A public read of "what times exist" is fine; anything
     more is not.
  2. Enqueue WordPress's **bundled** `jquery-ui-datepicker` and its core stylesheet, plus one small
     plugin script. No vendored library, no CDN, no build step (D12).
  3. Drive `beforeShowDay` from the same availability function so a closed, blocked or lead-time day
     is not selectable, and have the script replace the time list when a day is chosen.
  4. Keep T16's server-rendered day list as the no-JavaScript fallback, and restyle the picker so it
     belongs to the form T11 designed rather than looking like a stock jQuery widget.
- **In scope** — `includes/rest-slots.php` (or equivalent), `includes/booking-form.php`,
  `assets/booking-form.js`, `assets/booking-form.css`.
- **Out of scope** — vendoring flatpickr or any third-party date library (D12 records it as the
  alternative, not the plan); a fully Ajax form; prefetching adjacent months; time zones beyond
  `current_time()`.
- **Acceptance criteria**
  1. With JavaScript on, choosing a day replaces the available times without a page reload.
  2. A closed day, a blocked day and a day inside the lead time cannot be chosen.
  3. With JavaScript off, the form still offers the day list and still books.
  4. The calendar offers no time that `bookify_available_slots()` does not return, and the REST route
     returns nothing beyond the times.
- **Verify** — compare the route's JSON with the function's output via `curl` and `wpdev wp eval`;
  the browser check with JavaScript on and with it off (a Playwright context with
  `javaScriptEnabled: false`, driven as the `visual-testing` skill describes on this machine); three
  widths; `wpdev smoke`.
- **Size** — M

### T19 — The public endpoint stops being free to abuse

- [x] done
- Evidence, the order of defence: nonce → honeypot → rate limiter → Turnstile, and **all four answer
  a refusal the same way** (`?bookify=rejected`); the picker step is not rate limited because it
  writes nothing.

  **One thing this task names that does not exist, said plainly rather than papered over.** Both this
  task and D13 describe a *time-trap* alongside T12's honeypot, as though T12 had built one; T12
  built the honeypot only (its five “do” items, and its own evidence, have no time-trap in them). It
  is deliberately **not** added here, for two reasons. A rule that refuses a form submitted within a
  few seconds would refuse a real visitor — browser autofill plus one click beats two seconds — and
  would send them down the same opaque `?bookify=rejected` path, which stores no draft, so their
  message would simply be gone. And it would make this task's own verification impossible: the way
  the limiter is proved is by `curl`-ing the endpoint in a loop, which is precisely what a time-trap
  refuses. The limiter is the check that costs a bot something real and is provable; if a future
  session wants the trap, it belongs with T12's honeypot and needs the draft-restore path to go with
  it.
- Evidence, Turnstile with the vendor's **published test keys** (read from Cloudflare's own
  documentation on 2026-09-12, not from memory: sitekey `1x00000000000000000000AA` / `2x00000000000000000000AB`,
  secret `1x…AA` passes / `2x…AA` fails, dummy token `XXXX.DUMMY.TOKEN.XXXX`), over the **real
  network** to `challenges.cloudflare.com/turnstile/v0/siteverify`: the always-pass pair admits a
  submission and the always-fail pair refuses it with `bookify_bots_turnstile_failed`. **27/27**
  guard checks pass, including the composed request (the vendor's URL and a body carrying
  `secret`+`response`, asserted through `pre_http_request`).
- Evidence, an outage never refuses a booking (do 4): an unreachable verifier, HTTP 500, a body that
  is not JSON and the vendor's own `internal-error` **all let the submission through**; only an
  explicit `error-codes` refusal refuses. With no keys, or with only one of the two, nothing is
  printed, no script is loaded and no request is made — the site behaves exactly as it did after
  T16. With keys configured, an empty token or one longer than the vendor's 2048-character cap is
  refused.
- Evidence, the limiter over HTTP (18 requests, counters cleared first, `/tmp/bk-t19-http.sh`, the
  log read from `wp-content/debug.log`): a mistyped email is refused by **validation**
  (`?bookify=error&bookify_code=bookify_invalid_email`) and the corrected retry is **admitted** and
  books (acceptance 3 — a customer's own retries must not lock them out); five bookings for one
  email address take its counter to 5 and a sixth is refused with the booking count unchanged (8)
  **and the address counter still at 6**, so it was the email budget that refused it — the log says
  so in as many words; four submissions that are then refused by validation still spend the address
  budget (7, 8, 9, 10), and the next two are refused with nothing written; a bad nonce is refused
  with the counters unchanged, so the nonce is checked before any of it. Budgets: 10 per address and
  5 bookings per email address in ten minutes, both windows sliding.
- Evidence, the settings screen as a real administrator: the stored secret is **not in the rendered
  HTML at all** (`stored-secret-00AA-not-for-display` absent from a 110 kB page), while the site key
  is shown back deliberately — it is public, and it is already in every page that shows the form. The
  secret box is `type="password"` with an empty value and a note saying a key is stored. Saving a new
  secret through the real form stored it (`second-secret-11BB-not-for-display`, then not rendered
  again), and ticking “Remove the keys” emptied both and turned Turnstile off. Screenshot:
  `/tmp/bk-t19-settings.png`.
- Where the log lands, which cost time to find: with `WP_DEBUG_LOG` on (as this kit has it)
  `error_log()` writes to **`wp-content/debug.log`**, *not* to the Apache log or `docker logs`.
  Nothing personal is written — no address, no email, no token.
- Both options were put back and every test row deleted: the diary is unchanged, bookings are **3**,
  the limiter counters are cleared and `bookify_bots` is deleted, so Turnstile is off. `php -l` clean
  on every file, `wpdev smoke` 10/10.

- **Goal** — the one public write endpoint is expensive to abuse, without a puzzle in front of a real
  visitor and without a real booking ever depending on a third-party challenge.
- **Depends on** — T12 (honeypot and time-trap), T16 (the write path). **Parallel with** — T18.
- **Context to load** — T3's handler; T12's honeypot; `docs/bookify-plan.md` D13 and the constraint
  that every third-party call must be provable locally; the `wordpress-best-practices` skill.
- **Do**
  1. Keep the order of defence explicit in the code: nonce (T3), honeypot and time-trap (T12), rate
     limit, then the CAPTCHA if it is configured.
  2. Add rate limiting with transients keyed by IP and by email — a small budget per window, and a
     refusal that reuses the existing `?bookify=rejected` path so a bot learns which check stopped
     it. A customer fixing a typo must not be locked out by their own retries: test that case.
  3. Add Cloudflare Turnstile behind a setting, **off by default**, with the site key and secret
     entered on T14/T15's settings screen and verified server-side through `wp_remote_post` to
     `siteverify` — call it through a filterable request so the acceptance does not need an account.
  4. Never let a CAPTCHA outage block booking: an unreachable or misconfigured verifier must not
     refuse a submission that passed every other check — log it and decide in the code, in writing.
- **In scope** — `includes/bot-protection.php` and the settings screen's bot section.
- **Out of scope** — reCAPTCHA, hCaptcha (Turnstile is chosen for its test keys, D13); blocking by
  user agent; IP allow-lists; a firewall plugin.
- **Acceptance criteria**
  1. The vendor's published always-pass test key admits a real submission and the always-fail key
     refuses it; confirm the exact key values from Cloudflare's own documentation at implementation
     time rather than from memory, and report them as evidence.
  2. Past the rate budget, submissions are refused and create no booking.
  3. A retry after a validation error is not refused by the limiter.
  4. With Turnstile unconfigured the site behaves exactly as it did after T16.
- **Verify** — `curl` the submit endpoint in a loop to trip the limiter, with the booking count
  before and after; `wpdev wp eval` printing the verifier's decision for both test keys; the
  no-key case; `wpdev smoke`.
- **Size** — M

## Stage 4 — the booking the customer owns

### T20 — Manage my booking, without a password

- [x] done
- Evidence 2026-09-12: `includes/manage-booking.php` adds the link and the page it opens. The link is
  `?bookify_manage=<reference>&key=<token>`: the reference is only a lookup key (through
  `bookify_booking_find_by_reference()`, never a post id) and the token is compared with
  `hash_equals()` and **never sanitised before the comparison** (T17's rule, kept).
  `bookify_manage_expires` is a Unix timestamp computed as **90 days past the appointment**, refreshed
  when the booking moves — a booking made for 2026-09-14 stored an expiry 92 days out — and the token
  is minted lazily, so a booking made before this task still gets a link.
- Evidence, the confirmation: the captured message now carries *"To see this booking, move it or
  cancel it, open this link:"* with the manage URL and **no `bookify_cancel=` at all**. T17's cancel
  URL still works — GET → `302 ?bookify=cancelled` and the stored status becomes `cancelled` — kept as
  an alias so a customer holding an older email is not left with a dead link.
- Evidence, nothing else shows a booking (28 checks, `/tmp/bk-t20-check.py`): a good link renders the
  details once (Reference, Status `Pending`, Service, Date, Time, Guests — from the booking's own
  fields) plus the move picker and the cancel form; **a mangled token, another booking's reference, no
  arguments at all, and a token past its expiry each render 0 booking-detail markup** and no service
  name, saying "not valid" or "has expired" respectively.
- Evidence, cancelling from the page: the POST re-checks the nonce **and** the token, then answers
  `302 /manage-booking/?bookify_manage=…&key=<new>&bookify=cancelled` — the token it redirects to
  differs from the one that cancelled it. The fresh link shows **Status Cancelled with no picker and no
  cancel form** and says why, while the link that did the cancelling then renders **0** details and
  "not valid", so a copy of it is dead. The stored status is `cancelled`.
- Evidence, the lost link: the lookup form answers **identically** for the address on the booking and
  for one nobody has (both `302 ?bookify=link_asked`, the same sentence, 0 booking details), and the
  composer produces exactly **one** message — addressed to `t20@example.com`, the address stored on the
  booking and never the one that was typed — carrying the link. Asking about two unknown bookings
  composes nothing at all.
- Evidence, the page is content: page **214 "Manage booking"** holds `[bookify_manage_booking]`, and
  `bookify_booking_manage_page_url()` finds it by that shortcode (falling back to `/manage-booking/`).
  Screenshots looked at: `/tmp/bk-t4-manage-1440.png`, `/tmp/bk-t4-manage-390.png`.

- **Goal** — the customer can see their booking and what can still be done with it from a link in
  their email, a week later, with no account and no password.
- **Depends on** — T17 (the cancel link this replaces), T7 (the message). **Parallel with** — T18, T19.
- **Context to load** — T17's token and its `hash_equals()` comparison; T7's composed message;
  `docs/bookify-plan.md` D14.
- **Do**
  1. Replace T17's cancel-only link with one signed, expiring manage link per booking: the reference,
     the token and an expiry (`bookify_manage_expires`, default well beyond any booking date but not
     forever). Compare with `hash_equals()` as T17 does.
  2. Add a Manage booking page reading `?bookify_manage=<ref>&key=<token>`, showing the service, date,
     time, party, reference and status, and offering exactly the actions that state allows — Cancel,
     and Reschedule once T21 lands. An expired or cancelled booking shows its state and no actions.
  3. Handle the lost link without leaking anything: the customer gives their email and reference, and
     the site **sends a fresh link to the address** rather than showing bookings on screen. Never
     reveal on screen whether an address or reference is known — one message either way.
  4. Rotate the token when a booking is cancelled, so an old link cannot be replayed; and never let
     a query argument name a post id (T17's rule).
- **In scope** — `includes/manage-booking.php`, the Manage booking page, T7's message.
- **Out of scope** — real accounts (T22); changing a booking's details (T21 does the date; a party
  size is a cancel-and-rebook); any customer-facing data beyond that one booking.
- **Acceptance criteria**
  1. A missing, wrong or expired token shows nothing about any booking.
  2. A good link shows that booking and no other, and only the actions its status allows.
  3. The lost-link flow composes one message and reveals nothing on screen either way.
  4. A cancelled booking's old link is dead.
- **Verify** — `wpdev wp eval` with a `pre_wp_mail` capture to obtain a link, then `curl` it, with a
  mangled token, and after cancelling the booking; `wpdev smoke`.
- **Size** — M

### T21 — Reschedule

- [x] done
- Evidence 2026-09-12: `bookify_booking_reschedule()` in `bookings.php` is the write path — the manage
  page only decides and hands it the booking — and it asks every rule through **the same functions the
  booking form's write path uses**, then takes **the same advisory lock** (D16) around the check and
  the write. The move window is a new `move_hours` setting (defaulting to T15's lead time, capped at
  720 by the sanitiser, with its own field on the settings screen), and the clock that decision uses is
  stated in the code: `wp_timezone()`, the same one the opening hours and the lead time are read in —
  never the browser's and never UTC.
- Evidence, the move (32 checks in `/tmp/bk-t21-move.php`): 2026-09-15 09:00 → 2026-09-17 14:00 leaves
  the slot it held **offered again** and the slot it took **no longer offered** (both read from
  `bookify_available_slots()`), writes `bookify_previous_slot` = `2026-09-15 09:00`, keeps the
  reference and the status, and moves the link's expiry forward with the appointment.
- Evidence, the refusals — each with T16's own code and wording, and each leaving the row untouched: a
  Sunday → `bookify_closed_day`, a time off the grid → `bookify_slot_unavailable`, a past date →
  `bookify_invalid_date`, `99:99` → `bookify_invalid_time`, a booking that does not exist →
  `bookify_unknown_booking`, a slot another booking holds → `bookify_slot_full`, and inside the move
  window → `bookify_move_too_soon` (which `bookify_booking_can_move()` also answers, so the page says
  it instead of offering a picker that cannot work).
- Evidence, the lock is real: with a second database connection holding
  `GET_LOCK('bookify_slot_lock')`, a move is refused `bookify_booking_busy` and nothing changes, and it
  goes through the moment the lock is released. That is T16's own proof of the same lock, and it is
  what stands in for "two customers rescheduling into the last place at the same instant".
- Evidence, over HTTP (24 checks, `/tmp/bk-t21-check.py`): what the page offers is exactly
  `bookify_available_slots()` and `bookify_available_days()` for the day it shows. **"Show times" is a
  page request** (`302 …&bookify_day=2026-09-17`, that day selected, that day's times) — the
  no-JavaScript path, and the one the script falls back to. The move answers `302 ?bookify=moved` and
  the page then shows the new date and time; a full slot answers `bookify_code=bookify_slot_full` with
  T16's sentence on the page; a bad nonce and a wrong token are both refused with nothing moved.
- Evidence, in a browser: the manage page's picker is the booking form's own — the same DOM contract and
  the same `assets/booking-form.js` — a click on a day replaces the times with **no reload**
  (2026-09-12 → 2026-09-22, 15 times, matching the route), and there is **0 horizontal overflow at
  1440 and at 390**.

- **Goal** — a customer can move a booking to another slot instead of cancelling and starting again,
  and the old slot immediately becomes bookable by someone else.
- **Depends on** — T20, T16, T15. **Parallel with** — T22.
- **Context to load** — T16's `bookify_available_slots()` and its advisory lock (D16); T18's picker;
  T11's stylesheet.
- **Do**
  1. Offer the same picker as the booking form (T18) on the manage page, limited to that booking's
     service and party size.
  2. On submit, re-check availability inside the same advisory lock T16 uses, and refuse a full,
     closed, blocked or too-soon slot with the error codes T16 already defines — the same wording, the
     same map.
  3. Write the new date and time, record the slot that was given up (`bookify_previous_slot`, one key,
     so an operator can answer "why did this move?"), and keep the reference.
  4. Respect a policy window: a booking inside N hours of its start cannot be moved, with N a setting
     defaulting to T15's lead time. Say in the code which clock that decision uses.
- **In scope** — `includes/manage-booking.php`, `includes/bookings.php`.
- **Out of scope** — moving to a different service; a fee for moving; reschedule limits per customer;
  notifications on reschedule beyond the existing confirmation path.
- **Acceptance criteria**
  1. After a reschedule the old slot is offered again and the new one is not — proven by calling
     `bookify_available_slots()` before and after.
  2. A booking inside the policy window cannot be moved, and nothing changes.
  3. A target slot that is full or does not exist is refused with T16's code and nothing changes.
  4. Two customers rescheduling into the last place at the same instant end with one booking there.
- **Verify** — `wpdev wp eval` printing availability before and after, plus the policy-window refusal
  and the full-slot refusal; the concurrent case as in T16; a browser run on the manage page;
  `wpdev smoke`.
- **Size** — M

### T22 — Accounts, for the customers who want one

- [x] done
- Evidence 2026-09-12: `includes/customer-accounts.php`. A booking is attached
  (`bookify_customer_user`) **only** when it was made while signed in — set in
  `bookify_create_booking()` beside the other meta — and never by matching an email address to an
  existing user. A booking made while signed out stays unattached until the customer claims it with
  their own link (32 checks, `/tmp/bk-t22-check.py`).
- Evidence, the offer: the post-booking redirect now carries the new booking's own link, so the
  confirmation page can offer an account without asking anyone to type anything. It appears for a
  booking that belongs to nobody, and does **not** appear without the link in the URL, with a bad token,
  or when somebody is signed in.
- Evidence, claiming it: a `subscriber` is created for the address **stored on the booking** (never one
  that was typed) with `wp_generate_password(24)`, the booking is attached to that user, and the answer
  lands on the booking with `bookify=account_created`. WordPress's own message carries WordPress's own
  set-password link — captured: `wp-login.php?login=t22c&key=…&action=rp`. An address that already has
  an account answers `bookify=account_exists`, creates no second account and **leaves the booking
  unclaimed**: attaching it would be the "plant a row in a stranger's account" the plan forbids.
- Evidence, My bookings: page **228** holds `[bookify_my_bookings]`, and the query is filtered by the
  current user id and nothing else. Two subscribers, one of them with two bookings: each sees their own
  and the other's reference appears **nowhere** on the page; the signed-out page asks a visitor to sign
  in; and a page holding one form per booking has **no duplicate element ids** (a field's `id_suffix`,
  and the nonce fields written without ids).
- Evidence, in a browser: a page listing two bookings shows two details blocks and **two working
  calendars**; choosing a day in the second changes that booking's times (2026-09-12 → 2026-09-23) and
  leaves the first untouched — which is what the script's per-picker scoping exists for. 0 horizontal
  overflow at 1440. Screenshots looked at: `/tmp/bk-t4-my-bookings-1440.png`,
  `/tmp/bk-t4-offer-1440.png`.
- Evidence, capabilities: both customers are `subscriber`, `user_can( …, 'edit_posts' )` is **false** for
  both, and a signed-in subscriber gets 200 for their own profile while the Posts, Media, Users,
  Settings, Plugins and Bookings screens all answer **403** ("Sorry, you are not allowed to …"). The
  bare dashboard shell is reachable — that is core WordPress for anyone with `read` — and no custom role
  and no capability was added. Signing out and opening the magic link still shows the booking.
- **What this stage cost, recorded because it is worth not repeating.** A probe's cleanup loop used
  `'meta_value_in' => array( … )`, which **is not a WP_Query argument: it is ignored silently**, so the
  query matched *every* booking and deleted the three demo rows from Stages 1–2 along with the probe's
  own. The probes now use `meta_query` with `compare => 'IN'` (the plugin never used it — measured: an
  impossible `meta_value_in` returned all 3 services where the same value in `meta_value` returned 0).
  The demo bookings are recreated through the write path with new references — BK-00271/272/273 on
  2026-09-14 — because two of the originals sat on times the diary's grid does not offer and the third
  was a Sunday, so their ids and times could not be restored. Nothing else was lost: 3 services, the
  pages, menus and options are unchanged, and BK-00020/21/23 are the only casualty.

- **Goal** — an optional sign-in so a returning customer can see their bookings in one place, built so
  that it cannot leak one customer's booking to another. This is the last task of the stage because
  D14 makes it optional: everything above works without it.
- **Depends on** — T20 (the rendering and the actions, which this task reuses rather than copies).
  **Parallel with** — none.
- **Context to load** — T20's manage page and link; `docs/bookify-plan.md` D14 and its trade;
  the `wordpress-best-practices` skill (capabilities, and what `subscriber` may and may not do).
- **Do**
  1. On a successful booking by a signed-in customer, attach the booking to that user
     (`bookify_customer_user`). **Never** attach a booking to an existing user by email match alone —
     otherwise anyone can book with a stranger's address and plant a row in their account. An
     unsigned booking keeps the magic link and stays unattached.
  2. Offer account creation on the confirmation page for an unsigned booking: a `subscriber`-role
     user, a random password, and WordPress's own set-password link in T7's message. No custom role,
     no new capability, no `edit_posts`.
  3. Add a My bookings page rendering that user's bookings through T20's renderer, with the same
     actions. Every query filtered by the current user id — never by a parameter.
  4. Keep the magic link working for everyone: signing in is a convenience, not a requirement.
- **In scope** — `includes/customer-accounts.php`, the My bookings page, T7's message.
- **Out of scope** — custom roles and capabilities; profiles, addresses or payment methods on the
  account; social login; passwordless plugins; anything that makes a `bookify_booking` public.
- **Acceptance criteria**
  1. A signed-in customer sees their bookings and provably none belonging to another customer.
  2. A booking made while signed out is not attached to any user, and a booking made while signed in
     is attached to that user.
  3. A `subscriber` cannot reach wp-admin beyond their own profile.
  4. Signing out and using the magic link still shows the booking.
- **Verify** — create two subscribers and two bookings in wp-admin, sign in as one and confirm the
  other's reference appears nowhere in the HTML (`curl | grep -c`); `wpdev wp user list
  --role=subscriber`; `wpdev wp eval` printing `user_can( $id, 'edit_posts' )` → false; `wpdev smoke`.
- **Size** — L

## Stage 5 — money

### T23 — What is paid, and when

- [x] done
- Evidence 2026-09-12 — **the code**: `includes/payments/amounts.php` (required from the bootstrap)
owns the mode, the amount, the deadline and the sweep. Three service keys on T9's box —
`bookify_payment_mode` (`none`/`deposit`/`full`, a select), `bookify_deposit_type` (`amount`/`percent`)
and `bookify_deposit_value` — and five booking keys: `bookify_payment_status` (`unpaid|paid|failed|refunded`),
`bookify_payment_amount`, `bookify_payment_due`, `bookify_payment_reference`, `bookify_payment_note`.
`bookify_booking_statuses()` grew `awaiting_payment` and `expired`, and
`bookify_booking_slot_holding_statuses()` is now the one list of "this booking is using a place", read by
`bookify_booking_guests_by_day()` **and** by the manage page. Every value is re-validated on the way
out of the option and out of the meta, so `wp post meta` cannot change what is charged in a way the
code does not understand.
- Evidence — **each mode, and the message** (`_probes/stage5-amounts.php`, a `pre_wp_mail` capture per
mode; service 85, price 75.00): `none` → `status=pending`, amount 0.00, no sweep scheduled, no payment
line in the message; `deposit 25.00` → `awaiting_payment`, `amount_due=25.00` stored as 25, *"To keep this
booking, please pay GBP 25.00."* + *"It is due by 10:03 am on September 12, 2026, after which the time
goes back to the diary."*; a 50% deposit → 37.50; `full` → 75.00. **A deposit can never be taken for more
than the price**: 500.00 on a 75.00 service → 75.00, a 250% deposit → 75.00, an unreadable mode → 0.00
and reads as `none`, and the full price of a service with no price written → 0.00.
- Evidence — **the window**, in three separate requests (`_probes/stage5-expiry.php`, because the sweep
runs once per request by design and one `eval-file` run is one request): while the window is open
`status=awaiting_payment remaining=0 offered_again=no` — an unpaid booking holds its slot; after the
deadline, **the next availability read** expired it: `status=expired remaining=1 offered_again=yes`; and
the cron path (`bookify_booking_expire_unpaid()`) did the same on its own booking and **changed nothing
on a second call**.
- Evidence — **the appointment is released by every path that ends the wait**: `sweep scheduled=yes`
when booked, then `no` after cancelling, `no` after paying, `no` after expiring, `no` after deleting
(the last through the new `deleted_post` hook). An earlier, aborted run left 24 stale events behind —
that was a bug in the probe, not the plugin, since an event for a booking that is no longer waiting is
a no-op; they were cleared with `wp cron event delete`.
- Evidence — **with every service free, the site is what it was after T16** (`_probes/stage5-amounts.php`
and a real HTTP submission): a real `POST admin-post.php` with the booking nonce answered
`302 → /?bookify=booked&bookify_manage=BK-00341&key=…`, and that booking holds `status=pending` with
**no payment meta at all** (`payment='' amount='' due=''`).
- Evidence — **the settings side** (`_probes/stage5-settings.php`): a two-letter currency is dropped
(which switches checkout off), `EUR` → `eur`, window 1 → 5 and 9999 → 1440, a word keeps the stored
value, `null` keeps everything, a key submitted as an array is ignored, the remove box clears both
keys, an empty password box **keeps** the stored key and typing a new one replaces it, and the whole
option round-trips through its own sanitiser unchanged (T15's trap). The screen never prints a stored
key back, and says so ("never shown again") when one is set.
- **A decision worth naming**: Stripe needs an ISO 4217 code and the site stores no currency anywhere —
the form's `currency` argument is a display symbol its own caller supplies. The default is **`gbp`**,
because the business fields the owner fills in ask for a *postcode* and a *town or city*; the screen
changes it, and checkout is off entirely until a secret key is saved too.

- **Goal** — a service can be free, need a deposit, or need the full price, and the booking carries the
  status and the deadline that follow from that — before any payment provider is involved.
- **Depends on** — T16 (the slot model it must not break). **Parallel with** — T20, T21, T22.
- **Context to load** — T9's service meta box; T16's `bookify_available_slots()` and its counting;
  T7's message; `docs/bookify-plan.md` D11.
- **Do**
  1. Extend T9's service meta box with a payment mode (none / deposit / full) and, for a deposit, an
     amount or a percentage, with a userland sanitiser as everywhere else.
  2. Extend the booking status vocabulary to `awaiting_payment` and `expired`, keeping pending,
     confirmed and cancelled, and teach T16's counting that an `awaiting_payment` booking still holds
     its place.
  3. Expire an unpaid booking after a window (a setting, default 30 minutes) and give the place back:
     schedule it with `wp_schedule_single_event()` **and** sweep on the next availability read, so a
     missing cron run cannot leave a slot lost forever.
  4. State in the composed confirmation what is due, how much and by when.
- **In scope** — `includes/payments/amounts.php`, the service meta box, the status vocabulary,
  `includes/availability.php`'s counting, T7's message.
- **Out of scope** — taking the money (T24); refunds (T25 records them); invoicing; tax.
- **Acceptance criteria**
  1. Each mode produces the expected status and the expected amount in the composed message.
  2. An unpaid booking past its window becomes expired and its slot is offered again.
  3. An expired booking is not counted against capacity.
  4. With every service set to none, the site behaves exactly as it did after T16.
- **Verify** — `wpdev wp eval` with a `pre_wp_mail` capture per mode; an expiry test with a very short
  window, printing availability before and after; `wpdev smoke`.
- **Size** — M

### T24 — Taking the money: Stripe Checkout, called directly

- [x] done
- Evidence 2026-09-12 — **the request itself**, read from a `pre_http_request` stub
(`wp_remote_post()` dispatches it, so no account, key or tunnel is needed): exactly **one** request per
call — `POST https://api.stripe.com/v1/checkout/sessions` with `Authorization: Bearer sk_t…` and
`Content-Type: application/x-www-form-urlencoded`, and a body of `mode=payment`,
`success_url`/`cancel_url` both the booking's own manage link, `client_reference_id=BK-00291`,
`metadata={"bookify_reference":"BK-00291"}` — **no name, email or phone**, and the same reference
repeated onto `payment_intent_data.metadata` so T25's intent and charge events can name the booking —
and `line_items=[{"quantity":1,"price_data":{"currency":"gbp","unit_amount":2500,"product_data":
{"name":"Aromatherapy massage — deposit for booking BK-00291"}}}]`. £25.00 → `unit_amount 2500`.
- Evidence — **off unless configured**: with no key `bookify_booking_stripe_enabled()` is false,
`bookify_booking_stripe_create_session()` returns `bookify_stripe_not_configured` after **0 requests**,
and the booking is untouched; a booking with nothing to pay gets `bookify_stripe_nothing_due` after 0
requests. Over HTTP the pay block prints "Card payments are not set up on this site yet…" and offers
**no button**, and a hand-made POST of the pay form with a valid nonce answers
`302 → ?bookify=pay_off` with that sentence announced at the top of the page.
- Evidence — **every way the API can say no**: HTTP 402 → `bookify_stripe_refused`; HTTP 500 → the same;
a transport error → `bookify_stripe_unreachable`; a 200 with a URL on another host → `bookify_stripe_no_url`;
a 200 with no URL → the same. After every one the booking was still `awaiting_payment`/`unpaid` with
its sweep still scheduled. Over real HTTP with a key configured: the manage page renders the pay button,
POSTing it answered `302 → ?bookify=pay_failed`, the page announced *"We could not start the payment
just now, and nothing has been charged."* **with the booking still shown and the button still there**,
and `debug.log` recorded `Refused BK-00300 with HTTP 401: Invalid API Key provided: sk_test_**********key`
— a real call to Stripe's API, and Stripe's own message, which masks the key for us.
- Evidence — **nothing card-shaped is stored**: a grep of the plugin for
`card_number|cvc|cvv|payment_method|tok_` finds only the comment that says none of them exists, and the
booking's meta after all of the above is the nine T2 keys plus the five T23 payment keys — the Stripe
reference is a Checkout-Session or PaymentIntent **id**, kept so an operator can look the payment up,
and it cannot be used to charge anything.
- Evidence — **the customer's side, measured in a browser** (Playwright's Chromium at 390, 768 and 1440 px):
the pay block sits between the booking's details and the move picker, `innerWidth === scrollWidth` at all
three widths (no horizontal overflow), and the button is the form's own primary button — 370 px wide at
390 and 192 px (12rem) at 768 and 1440.
- One bug this found and fixed: the pay note's apostrophe was written as `\u2019` inside a PHP **single**
quoted string, where it is not an escape, so the page printed it literally. Caught by looking at the
rendered page, not by reading the code.

- **Goal** — a booking that needs paying gets a Stripe Checkout session, and the site never sees a card
  number. Off entirely unless a key is configured.
- **Depends on** — T23. **Parallel with** — T25 (which consumes what this writes).
- **Context to load** — T23's amounts and statuses; T20's manage page for the return URLs; D11 and its
  reason; the `wordpress-best-practices` skill for handling a remote response and its errors.
- **Do**
  1. Add `includes/payments/stripe.php` calling `wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', … )`
     — no SDK, no plugin, no `composer` (the image has no Composer on purpose). The secret key comes
     from the settings screen, is never printed back, and never enters the repository.
  2. Build the session from the booking: line items from T23's amount, `success_url` and `cancel_url`
     back to T20's manage page, and `metadata` carrying the booking reference and nothing else. No
     customer name, email or phone in the metadata — they belong in the site's own record.
  3. Redirect to the returned `url`. Treat a transport error or a non-2xx as a failed start: the
     booking stays `awaiting_payment`, the customer sees a message and a way to try again, never a
     blank page or a spinner that never ends.
  4. Make the outbound call filterable (`pre_http_request`) so the acceptance runs against a stub, and
     allow a test key for local work. No key means no call and no Stripe anywhere in the flow.
- **In scope** — `includes/payments/stripe.php`, the checkout-start handler, the settings screen's
  payment section.
- **Out of scope** — storing card data of any kind; Stripe Elements or any on-site card form; a
  subscription or recurring payment; live-mode keys; PayPal or a second provider.
- **Acceptance criteria**
  1. With a stubbed transport, an `awaiting_payment` booking produces exactly one session request
     carrying the right amount, currency and reference — and the request itself is reported as
     evidence.
  2. A 402 or 500 from the API leaves the booking unpaid and shows the customer a message.
  3. With no key configured, no HTTP request is attempted at all.
  4. No card number, CVC or payment token is stored by the plugin anywhere.
- **Verify** — `wpdev wp eval` with a `pre_http_request` filter that records the request and returns a
  canned response, printing the URL, body and headers; the same with an error response; a `grep` of
  the plugin for stored card fields; `wpdev smoke`.
- **Size** — L

### T25 — Stripe tells us it was paid

- [x] done
- Evidence 2026-09-12 — **the route and the signature** (`includes/payments/webhook.php`, and
`_probes/stage5-webhook.php` driving `rest_do_request()`, so real route matching is part of the test):
registered on `rest_api_init` (the probe's first run caught the T18 notice the other way). A correct
signature is accepted; one **400 seconds old** is refused, and is accepted when measured against a clock
400 seconds earlier (the rule is the delta); one character changed is refused; the right signature with
the wrong secret is refused; no header at all is refused. Through the route, all of those answer
`HTTP 400 {"ok":false,"reason":"bad-signature"}` and leave the booking untouched. With no signing
secret configured the route answers `503 not-configured` rather than trusting anything.
- Evidence — **the four events, and only those**: `checkout.session.completed` → `200 handled`, status
`confirmed`, `payment=paid`, reference `pi_probe_0001`; `checkout.session.expired` → `200 handled`,
`expired`, and `bookify_available_slots()` offered the time again; `payment_intent.payment_failed` →
`payment=failed` with the booking still `awaiting_payment` (nothing has been taken, so it can try again);
`charge.refunded` → `payment=refunded` and the note *"Stripe reported a refund of GBP 25.00 on September
12, 2026. The appointment itself is untouched — cancel it if it is not going ahead."* with the booking's
status deliberately unchanged; `invoice.paid` → `200 handled=false` and nothing changed; a reference
nobody knows → `200 handled=false`; a signed body that is valid JSON but not an event → `400 bad-payload`;
not JSON at all → the REST layer's own `400 rest_invalid_json` before the callback runs at all.
- Evidence — **idempotent**: the same event id delivered twice — the second delivery sets the booking back
to `awaiting_payment` first, so a lost idempotency would have been visible — answered
`200 {"ok":true,"duplicate":true}` and left the booking exactly as it was, while a **different** event id
with the same body was acted on. The guard is the event id in a week-long transient, and every handler is
written to be idempotent on its own so a lost transient degrades the check rather than breaking it.
- Evidence — **over real HTTP, with no tunnel**: `curl` from the host, with a payload signed inside
`wp eval`: the tampered signature → `400 {"ok":false,"reason":"bad-signature"}`, the correct one →
`200 {"ok":true,"handled":true}`, and the same event again → `200 {"ok":true,"duplicate":true}`.
The booking went `awaiting_payment`/`unpaid` → `confirmed`/`paid` with `payment_reference=pi_http_0001`,
and the second booking whose body was delivered with an **already-seen event id** was deliberately left
alone — which is what "the same event id changes nothing" means in practice.
- Evidence — **the success URL proves nothing**: both return URLs are the manage page, which renders the
booking's real status; measured with `?bookify=pay_failed` and `?bookify=pay_off`, in both cases the
booking is shown as it is and the button is still there.
- Evidence — **the operator's screen**: T8's read-only box gained Payment, Amount, Payment due by, Stripe
reference and Payment note (each formatted — the amount with its currency, the deadline with the site's
date and time formats), which is where T25's refund note and its "paid after the time was gone" note land.
- Evidence — `wpdev smoke` **10/10** after all of the above, and the site left as it was: 3 bookings (the
demo rows), `bookify_payments` unset, `bookify_booking_stripe_enabled()` false, 0 scheduled
`bookify_booking_expire` events, no `bookify_probe*` options and the limiter counters cleared.
- The throwaway probes are kept beside the earlier stages' ones in `_probes/`
(`stage5-amounts.php`, `stage5-expiry.php`, `stage5-webhook.php`, `stage5-http-pay.php`,
`stage5-settings.php`); the one that printed the composed Stripe request — `stage5-stripe.php` — was
deleted after use and its output is recorded above.

- **Goal** — a booking becomes paid because Stripe said so, and only then. A browser returning to the
  success URL proves nothing.
- **Depends on** — T24. **Parallel with** — none.
- **Context to load** — T24's metadata and statuses; T23's expiry; `docs/bookify-plan.md` D11 and the
  constraint that every third-party call is provable locally without a tunnel.
- **Do**
  1. Register a REST route `bookify/v1/stripe-webhook` that verifies the `Stripe-Signature` header:
     HMAC-SHA256 over the signed timestamp and the raw payload with the endpoint's secret, compared
     with `hash_equals()`, and refused when the timestamp is too old. Read the body with
     `$request->get_body()`, never from a parsed array, or the signature will not match.
  2. Handle exactly four events and ignore the rest:
     `checkout.session.completed` → `paid`, and `confirmed` where the operator's flow says so;
     `checkout.session.expired` → release the place; `payment_intent.payment_failed` → back to
     `awaiting_payment`; `charge.refunded` → record it and set a refunded status or a note. Anything
     else is logged and left alone.
  3. Make it idempotent: the same Stripe event id processed twice changes nothing. Store the event id
     as booking meta or a transient and short-circuit on the second delivery.
  4. Never trust the success URL for state: returning to it shows the booking and its real status, and
     a booking that is still unpaid says so.
- **In scope** — `includes/payments/webhook.php`, the refund note in T8's read-only box.
- **Out of scope** — a real public webhook URL (a hosting step, like D6's SMTP); replay prevention
     beyond the event-id check; partial refunds; disputes.
- **Acceptance criteria**
  1. A payload signed with the correct secret marks the booking paid and confirmed; the same payload
     with a wrong signature changes nothing and is refused.
  2. The same signed event delivered twice changes nothing the second time.
  3. `checkout.session.expired` returns the place, proven through `bookify_available_slots()`.
  4. A stale timestamp is refused.
- **Verify** — `wpdev wp eval` signing a payload and POSTing it through the route (no tunnel needed),
  then reading `bookify_status`; the same with a tampered signature and with a repeated event id;
  `wpdev smoke`.
- **Size** — M

## Stage 6 — being found and being fast

### T26 — A page per service

- [x] done
- Evidence 2026-09-13: `bookify_service` is queryable again (`publicly_queryable => true`, `rewrite
  => array( 'slug' => 'sessions', 'with_front' => false )`) and rewrites were flushed. Measured:
  `/sessions/` **200** and still the listing page, `/sessions/one-to-one-session/`,
  `/sessions/partner-session/` and `/sessions/small-group-class/` all **200**, and a session flipped
  to `draft` answers **404** (86 was flipped and flipped back to prove it). The `sessions` slug
  shares a word with the page's own slug and does not collide: one is the page, the other is a
  session under it.
  New `wp-content/themes/bookify-theme/single-bookify_service.php` — the only file any stage adds to
  the theme, exactly as D15 said. It renders the theme's own header and footer, the title, the price
  and length through `bookify_booking_service_price_and_length()`, the session's copy, and the
  booking form restricted to that session. The theme holds no business logic, verified by grep:
  `register_post_type|register_taxonomy|add_shortcode|$_POST|$_GET|wp_insert_post|admin_post`
  returns **nothing** across the three theme files.
  **Slugs were aligned to titles** (`deep-tissue-massage` → `one-to-one-session`, and so on for the
  other two). Without that, the three new URLs 404 while looking correct in the address bar.
  T10's rows gained a **Read more** link (a new `more_label` default and its Elementor control), so
  each session is one click from `/sessions/`; the direct "Book this" link is unchanged.
  **Found by looking at the page:** a session's own page passed exactly one row to the form, so the
  Session control was a dropdown holding one option. With one session the control is now a hidden
  field carrying the same `bookify_service_id` name and the same `data-bookify-service` attribute the
  picker's script requires. Verified in a real browser: the wrapper still gains
  `bookify-booking--enhanced`, the calendar still draws (`ui-datepicker` present), 15 times are
  offered, and submitting books from that page — `?bookify=booked&bookify_manage=BK-00385…` with
  "Thank you — we have your booking and will be in touch." `/book/`, which offers three sessions,
  still renders the select. Screenshots at 1440/768/390 (`/tmp/t26-session-*.png`), no horizontal
  overflow at any of them.
- **Follow-up, same day, at the owner's request: the page is a description and one button.** The
  embedded form is gone from `/sessions/<slug>/` — it was there so the only session the page could
  book was the one it was about, and the page now ends with a single **Book now** button instead:
  title, price and length, copy, button. Measured after: the page renders **0** `<form>` elements and
  one `a.bookify-session__book` pointing at `/book/?bookify_service_id=545`, wearing the same tokens
  as the form's own submit button and the listing's rows (`rgb(20,102,91)` with white text, pill,
  weight 600; `rgb(14,74,66)` on hover, measured after a real pointer move and a 500 ms settle).
  **Clicking it lands ready to book:** `/book/?bookify_service_id=545` with the session control
  already reading `Counselling session — 70.00 — 60 min` (value `545` of 8 options) **and the first
  open day already chosen** (`2026-09-14`), so the visitor arrives at a form to submit rather than one
  to fill in. This is exactly the trade T26's own note recorded when it chose the other way: an
  embedded single-row form cannot offer the other sessions, and a link can. Those two counts — one
  more click, and the session is changeable once they are there — are the whole cost.
  The destination comes from `bookify_booking_page_url()` rather than a hardcoded `/book/`, so moving
  or renaming the page cannot leave the button pointing at nothing, and the template keeps its
  `function_exists()` guard so a site without the plugin renders no button at all. The theme is still
  free of business logic (the T26 grep above still returns nothing).
  **The page also got much lighter, which was not the aim:** with the form gone the session page loads
  **1** script where `/book/` loads **6**. `jquery`, `jquery-migrate`, `jquery-ui core`, `jquery-ui
  datepicker` and `booking-form.js` are no longer requested, nor is `booking-form.css` — all six
  enqueued by the form's own render path. The A/B that proves it is `/book/` itself, where the same
  six are present.
  Shot at 1440/768/390 in light and dark: `scrollWidth === innerWidth` in every one, no page errors,
  and the button reads correctly in dark mode (`--bk-accent` + `--bk-on-accent`, the same pair the
  listing and the form already use). The event template is deliberately untouched —
  `/events/autumn-reset-workshop/` still renders its tier form (1 `<form>`, 2 `bookify-tickets`),
  because which tier you want is a choice only the form can offer. `wpdev smoke` 10/10;
  `_elementor_data` md5s unchanged. Theme **0.5.0**.

- **Goal** — `/sessions/<slug>/` is a real page — what the service is, what it costs, how long it takes
  and a way to book it — instead of the bare fallback D9 removed.
- **Depends on** — T10, T16. **Parallel with** — T27, T28.
- **Context to load** — T10's listing and its links; `bookify_booking_service_label()`;
  `docs/bookify-plan.md` D9 and D15.
- **Do**
  1. Set `publicly_queryable => true` on `bookify_service` again and flush rewrites (D15 reverses D9).
  2. Add exactly one theme file, `wp-content/themes/bookify-theme/single-bookify_service.php`:
     `get_header()`, the title, `the_content()`, the price and length through
     `bookify_booking_service_label()`, and the booking form widget for this service. Presentation
     only — no post type, no shortcode, no handler, and it must use the theme's own header and footer
     rather than inventing a layout.
  3. Add "read more" links to T10's rows so the page is reachable from the Services page, and keep the
     direct "Book this" link as it is.
- **In scope** — `bookify_service`'s registration; `single-bookify_service.php`; T10's rows.
- **Out of scope** — an archive template (the Services page is the archive); Elementor theme-builder
     templates (Pro only); a second service template.
- **Acceptance criteria**
  1. A published service's URL answers 200 and shows its price, its length and a control that books
     **that** service.
  2. A draft service's URL answers 404.
  3. The theme contains no business logic — a `grep` for post types, shortcodes and `$_POST` in
     `bookify-theme` returns nothing but the template's presentation.
  4. Three services are reachable from `/services/` in one click each.
- **Verify** — `curl -o /dev/null -w '%{http_code}'` on a published and a draft slug;
  `wpdev wp post-type list --fields=name,public,publicly_queryable`; the `grep` above; screenshots at
  three widths; `wpdev smoke`.
- **Size** — M

### T27 — LocalBusiness data that cannot drift

- [x] done
- Evidence 2026-09-13: new `includes/schema.php`, registered on `wp_head`. **DIVERGENCE (plan D20):**
  the JSON-LD is emitted by the plugin rather than by Rank Math, because a wp-admin-installed plugin
  would live only in the `wp_data` volume — invisible to git, absent from a fresh clone — while the
  integration to read our own options is the actual work either way. The task's acceptance criteria
  are met against the parsed output:
  - front page: the JSON-LD **parses**, and `@graph[0]` is a `LocalBusiness` carrying `name`,
    `telephone`, `email`, `url`, a `PostalAddress` with street/locality/postcode/country, and **6**
    `openingHoursSpecification` rows (Monday–Saturday; Sunday is closed and correctly absent);
  - a session page: the same business node **plus** a `Service` node with `name`, `description`,
    `duration: PT45M` and `offers` = `{price: "15.00", availability: InStock, url,
    priceCurrency: GBP}`, pointing back at the business through `provider.@id`;
  - **acceptance 3, proved rather than argued:** setting the phone to `+44 1273 555 000` on the
    options made the next front-page fetch publish `+44 1273 555 000`, and restoring it published
    the old number again. No other edit was made either time.
  Every value is read from `bookify_business` and `bookify_availability`; nothing is retyped. The
  hours are built from the stored weekday rows rather than parsed back out of the grouped footer
  text, and the price is formatted without a thousands separator because `1,250.00` is not a price.
  Two things worth keeping: `dayOfWeek` uses a private English enum map and deliberately **not**
  `bookify_booking_weekdays()`, which returns translated names — "Montag" is not a value any
  consumer understands; and the JSON is encoded with `JSON_HEX_TAG | JSON_HEX_AMP`, so a business
  name containing `</script>` cannot end the script element. Weight: **1005 bytes** on every page,
  **1558** on a session page; admin, feeds, 404s and robots are skipped.

- **Goal** — the site publishes machine-readable name, address, phone, opening hours and sessions, and
  every value comes from where it is stored rather than being typed again.
- **Depends on** — T14 and T15 (the stored details and hours), T26 (a page per service).
  **Parallel with** — T28.
- **Context to load** — `bookify_business` and `bookify_availability` from T14 and T15; T26's template;
  the constraint that details are stored once.
- **Do**
  1. Install Rank Math (free) and configure LocalBusiness to read the name, address, phone and opening
     hours from `bookify_business` and `bookify_availability` — through its filters or a small
     integration, not by retyping the values into its settings. Third-party plugins are installed in
     wp-admin: `wpdev` manages project plugins only.
  2. Add the service schema on T26's pages, including price and duration where they are set.
  3. Verify the output by parsing it: fetch the page, extract the JSON-LD, decode it, and check the
     fields are present and correct — "the plugin is active" is not evidence.
- **In scope** — the Rank Math configuration or integration; T26's template if it needs a schema hook.
- **Out of scope** — a third-party review or testimonial widget (the site's proof is the owner's own
     words and content); local SEO plugins beyond Rank Math; coordinates typed by hand if the address
     can carry them.
- **Acceptance criteria**
  1. The front page's JSON-LD parses and carries the business name, address, phone and opening hours,
     matching the options exactly.
  2. A service page's JSON-LD carries that service and its price.
  3. Changing the phone number on the settings screen changes the published data with no other edit.
- **Verify** — `curl` the pages, extract and pretty-print the JSON-LD (`php -r` or `jq`), and diff the
  values against `wpdev wp option get bookify_business`; repeat after editing the phone number;
  `wpdev smoke`.
- **Size** — M

### T28 — Fast enough to keep the visitor

- [x] done
- Evidence 2026-09-13. **Measured first, in the browser** (Playwright's bundled Chromium, median of 3,
  at 1440 and 390): `/` 25 resources / 13 CSS / 10 JS / 228 KB with FCP 276–300 ms; `/sessions/` 23 /
  12 / 9 / 215 KB; a session page 18 / 10 / 6 / 177 KB; `/book/` 18 / 10 / 6 / 177 KB.
  **What the measurements named, and what was done about it.** There are **no images anywhere** on
  these pages (T9 created the sessions without featured images), so `srcset`, `loading="lazy"` and
  `fetchpriority` have nothing to apply to. The asset weight belongs to WordPress and Elementor, not
  to this project: a home page with no form and no picker loads jQuery (82 KB), jQuery Migrate
  (10 KB), jQuery UI core (12 KB) and Elementor's `background-slideshow.js` + `background-video.js`
  (26 KB between them); 13 stylesheets; and two Google Fonts requests enumerating 18 Roboto weights
  from a third-party origin. Dequeuing those is a plugin-level decision T28 puts out of scope, and
  "never edit Elementor's generated CSS" closes the other door. So the only thing the measurement
  named that this project owns is the diary's read cost, and that is what was fixed.
  **Server side, cold, one request each — before:** `bookify_available_days()` 4 queries / 8.58 ms;
  `bookify_available_slots()` 4 / 1.84 ms; `bookify_booking_form_html()` 9 / **13.03 ms**;
  `bookify_slot_remaining()` 3 / 1.44 ms. Measured with `_probes/stage6-perf.php`, one path per
  request because WordPress's in-request object cache absorbs a repeated identical query — the first
  version of that probe reported 0 queries for `days` for exactly that reason, which is worth
  knowing before trusting any query count taken twice in one run.
  **After:** new `includes/availability-cache.php` caches the two answers that exist to be shown,
  keyed by a version plus a 5-minute clock bucket. Warm: `available_days()` **2 queries / 1.08 ms**,
  `available_slots()` **2 / 1.03 ms**, the form **6 / 5.34 ms** — from 9 / 13.03 ms, so **61 % less
  server time on every page view of the booking form**. `slot_remaining()` is unchanged at 3 queries
  **by design**: the write path decides capacity with it, and a stale answer there is not a slow page
  but an overbooked class.
  **The honest half of that trade:** a *miss* costs more than the old code — 15 queries / 21.36 ms for
  the form — because a WordPress transient with no persistent object cache costs a query to look up
  and two to write. A hit saves 3 queries and ~8 ms, so this pays off when views repeat inside the
  five-minute window and costs a little when they do not. A persistent object cache at hosting time
  is the real answer and is out of T28's scope. Browser numbers after the change are **unchanged
  within noise** (home FCP 300 → 296 ms, `/book/` 276 → 272 ms at 1440; resources and bytes
  identical) — the 8 ms saved is below this machine's noise floor, and saying otherwise would be
  dressing up a number.
  **Correctness, which the task requires:** `_probes/stage6-cache.php` runs across three requests —
  *prime* (15 slots, version 50), *book* (a full class, version → 51), *check* (09:00 **gone**, 14
  slots, and the write path agrees: 0 places left while the slot is still *offered*, which is the
  distinction T16 built). **VERDICT: PASS.** Invalidation is a version any booking meta write, a
  booking deletion or an availability settings change bumps, so a writer cannot leave a stale answer
  behind even if it has never heard of the cache. It is coalesced to **one** option write per
  request, after measuring a single booking bumping it **11 times**.
  `wpdev smoke` **10/10** throughout; `php -l` and `node --check` clean; no horizontal overflow at
  1440/768/390 on any of the four pages.

- **Goal** — the four pages that matter are measured, then improved, and the numbers are reported
  rather than a claim that it feels faster.
- **Depends on** — T13, T26 (the pages it measures). **Parallel with** — T27.
- **Context to load** — the `visual-testing` skill; T15's availability queries; T13's Elementor page.
- **Do**
  1. Measure first: `/`, `/services/`, one service page and `/book/`, with the tools already here
     (the bundled Chromium's performance timings, or Lighthouse if it is available), and write the
     before numbers down. No optimisation without a measurement that justifies it.
  2. Fix what the measurements name, in order of size — typically image dimensions and `srcset`,
     native `loading="lazy"` below the fold, `fetchpriority` on the hero, and Elementor CSS that is
     generated per page.
  3. Cache the availability queries in transients keyed by service and date, invalidated when a
     booking, cancellation or settings change touches that day, so the read path is not a query per
     page view. A correctness test comes with it: a booking must be visible in the next availability
     read.
  4. Report the after numbers against the before numbers for the same four pages, at desktop and
     mobile widths.
- **In scope** — the transient cache and its invalidation; template and image attributes; whatever the
  measurements justify.
- **Out of scope** — a page-cache plugin (an owner decision, not this plan's); a CDN; a hosted image
  service; rewriting the theme.
- **Acceptance criteria**
  1. Before and after numbers exist for all four pages, and the after state is not worse anywhere.
  2. A booking made now appears in the next availability read — the cache cannot serve a stale slot.
  3. No new horizontal overflow or layout break at any of the three widths.
- **Verify** — the recorded measurements; the cache-invalidation test through `wpdev wp eval`;
  screenshots at three widths; `wpdev smoke`.
- **Size** — M

---

## Stage 8 — the whole practice

### T29 — "Services" becomes "Sessions", everywhere a person reads it

- [x] done
- Evidence 2026-09-13: every user-facing string in the plugin was renamed, and nothing else was.
  `get_post_type_object('bookify_service')->labels->name` → `Sessions / Session`; the Bookings list
  column and the session meta box read `Session` and `Session details`; the form's field label is
  `Session` and the party field is `People`; the listing's default heading is `Sessions`; the
  confirmation says `Session: %s`; the Elementor panel shows `Session list`, `Session rows` and
  `Choose a session…`. A grep for a `__()` call containing "service" returns nothing user-facing —
  four leftovers were found after the first pass (`label_service` in the widget, the REST argument
  description, the meta box title, the payments blurb) and each was fixed.
  Page **95** is now titled `Sessions` at slug `sessions`: `/sessions/` answers **200** and
  `/services/` answers **404** after `wp rewrite flush`.
  Both menus read `Sessions` and link `http://localhost:8890/sessions/` — **and no menu write was
  made**: a `post_type` menu item's label follows the posted page's title and its URL from the
  object, so the items renamed themselves when the page did, and the loop looking for an item titled
  `Services` matched nothing. Recorded because it looks like a step that was skipped and was not.
  Deliberately unchanged, and now written down so nobody "finishes" the rename later: the post type
  key `bookify_service`, the meta key `bookify_service_id`, every `bookify_booking_service_*()`
  function, the `.bookify-services*` CSS classes, the `[bookify_services]` shortcode and the
  `services` argument key.

- **Goal** — a visitor, an owner and an Elementor editor all read **session**, while the stored data
  and every ability keep addressing `bookify_service` (plan D17).
- **Depends on** — none. **Parallel with** — T30, T31.
- **Do**
  1. Rename the post type's labels, its description and every registered-meta description.
  2. Rename the form's and the listing's copy defaults, the Elementor control labels, the wp-admin
     column and meta box, the email line and the error messages.
  3. Retitle page 95 and change its slug, then flush rewrites.
  4. Leave every key, function name and CSS class alone, and say so in the code where it could
     confuse a reader.
- **In scope** — `includes/**`, `assets/**`; page 95's title and slug.
- **Out of scope** — the post type key, the meta keys, function names, CSS class names, the
  shortcode name, and any data migration.
- **Acceptance criteria**
  1. No user-facing screen says "service".
  2. `/sessions/` answers 200 and `/services/` answers 404.
  3. The stored data and the abilities still address `bookify_service`.
- **Verify** — `wp eval` on the post type labels and the registered meta keys;
  `curl -o /dev/null -w '%{http_code}'` on both URLs; `grep` for `__(` calls containing "service";
  `wpdev smoke`.
- **Size** — M

### T30 — The reminder email

- [x] done
- Evidence 2026-09-13: new `includes/reminders.php` — the `bookify_reminders` option, the reminder
  appointment, the composed message — required from the bootstrap and registered by
  `bookify_booking_register_reminders()`. New **Reminders** section on the Settings screen D7 already
  owns (`register_setting()` with a userland sanitiser), on by default at **24 hours before**.
  `_probes/stage8-reminders.php` ran **18 checks, all PASS**, in one `eval-file` run:
  - the appointment is at exactly `bookify_booking_starts_at() − 24h` (`wp_next_scheduled()`
    `1789462800`);
  - one message per run, to the booking's own address, carrying the session, the date, the time, the
    number of people and the manage link; subject `Reminder: booking BK-00351`;
  - a second run sends **nothing** — the sent marker is written before the message is handed over;
  - cancelling drops the appointment, and a cancelled booking is never written to;
  - moving re-schedules it (`1790067600`), **clears the sent marker**, and the moved booking is
    reminded about its new date — a customer reminded about Tuesday and moved to Friday has earned a
    reminder for Friday;
  - a booking whose reminder moment has already gone (lead pushed to 336h) is **not scheduled at
    all**, which is the booking made inside the lead time: it gets its confirmation and nothing else;
  - reminders off → nothing scheduled; deleting a booking → the appointment goes;
  - the settings round-trip `{"enabled":true,"hours_before":48}`, and an absurd `99999` is clamped
    to `336` rather than stored.
  Hooked everywhere a booking changes state: created, cancelled, moved, an operator's status change,
  expired, and deleted; `bookify_booking_refresh_reminders()` on `update_option_bookify_reminders`
  moves the appointments already scheduled when the lead time changes.
  Honest limit: `wp_mail()` still cannot deliver from this container (D6). The
  `sh: 1: /usr/sbin/sendmail: not found` lines the probe prints are the **confirmation** path failing
  to deliver — pre-existing, invisible to the visitor, and not the reminder. Acceptance is the
  composed message and the appointment, exactly as T7 was accepted.

- **Goal** — a customer is reminded before their session without anybody remembering to do it, and
  the reminder is given up the moment the booking stops being something to attend (plan D18).
- **Depends on** — T7 (the composition pattern) and T23 (the appointment pattern). **Parallel with**
  — T29, T31.
- **Do**
  1. Add `includes/reminders.php`: the option and its defaults, `bookify_booking_reminder_due_at()`,
     `bookify_booking_reminder_wanted()`, the schedule/unschedule pair, the send, and the refresh.
  2. Schedule at booking time, unschedule on every path that takes the booking away, and re-ask when
     it moves — clearing the sent marker so the new time earns a new reminder.
  3. Compose the message from the booking's own stored values, print the dates exactly as they were
     chosen (T7's rule), and carry the manage link so the customer can act on it.
  4. Add the Reminders section to the existing settings screen; no second screen.
- **In scope** — `includes/reminders.php`, `includes/settings.php`, the lifecycle call sites in
  `includes/bookings.php`, `includes/admin-bookings.php` and `includes/payments/amounts.php`, and the
  bootstrap.
- **Out of scope** — SMTP or any mail account (D6); a second reminder; per-session lead times; a
  retry queue; a sweep on page requests (that would cost a query per view, against T28).
- **Acceptance criteria**
  1. A booking far enough ahead carries one appointment, at the lead time before it starts.
  2. The appointment composes one message naming the session, the date and the manage link, and a
     second run composes none.
  3. Cancelling, expiring or deleting drops it; moving moves it and earns a new reminder.
  4. A booking made inside the lead time is never scheduled one, and deleting a booking drops it.
- **Verify** — `wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage8-reminders.php`;
  `wpdev smoke`.
- **Size** — M

### T31 — Places in a class, and the three sessions to sell them with

- [x] done
- Evidence 2026-09-13: the three entries were re-themed for the wider practice and given the
  capacities the audience needs — **19 One-to-one session 85/60/1**, **86 Partner session
  150/75/2**, **85 Small group class 15/45/8**. (85 was `Aromatherapy massage` with one place, 86 was
  `Couples massage`.) The three-card grid therefore still holds three sessions and now demonstrates
  one, two and eight places.
  **The form states the ceiling.** The wrapper carries
  `data-bookify-capacities="{&quot;19&quot;:1,&quot;86&quot;:2,&quot;85&quot;:8}"` and the people field carries `max`
  from `bookify_slot_capacity()` for the session rendered — `max=1` on the default session, `max=8`
  at `?bookify_service=85`. **Measured in a real browser** (Playwright, real 1440 viewport, not read
  off the HTML): on load `max=1`; choosing the group class → `max=8`; choosing the one-to-one session
  again → `max=1`. So the script's re-application works and not only the server's first render.
  **The write path, unchanged, now proven at the new ceiling:** 8 people on the group class → booking
  `356` (deleted afterwards); **9 → `bookify_slot_full`**; 2 on a one-place session →
  `bookify_slot_full`. The ceiling is the refusal, not the field.
  **Copy.** Home: `Book a session in a minute`, the section `Sessions, without the back-and-forth`,
  the grid heading `Sessions`. The home intro paragraph still named deep tissue, aromatherapy and
  couples treatments after the first pass — the screenshots caught it, because the element's
  `editor` setting is longer than the inspector's print limit — and now names one-to-one sessions,
  partner work and small group classes. The Sessions page's widget heading moved from `Sessions` to
  `Everything we offer`, after a screenshot showed a page titled `Sessions` printing `Sessions`
  underneath it. Contact now asks about "a session, a gift voucher or a group booking".
  **The business details were restored.** `bookify_business` was an empty array — the drift found on
  2026-09-13 — so the footer printed opening hours and nothing else, and T27 would have had no
  source to read. It now stores `Bookify Studio` with the demo address, phone and email, and the
  footer renders the name, the address, a `tel:` link, a `mailto:` link and the hours. **These are
  demo values the owner replaces;** the point of the task was that the block renders and has one
  source.
  Every Elementor write went through the bridge's `Elementor_Document` (`get_elements()` →
  `update_settings()` → `save()`), so Elementor's Document API stamped the version and regenerated
  the CSS; nothing wrote `_elementor_data`.
  **Layout measured, not assumed:** `/`, `/sessions/`, `/book/` and `/contact/` at 1440, 768 and 390
  each report `documentElement.scrollWidth === window.innerWidth` — no overflow at any width — and
  the four pages were read back as screenshots. `wpdev smoke` **10/10**.
- **Follow-up, same day, at the owner's request: the rest of the catalogue.** The front page has
  always said "a small studio in Brighton for therapists, coaches and trainers", and the catalogue
  held three coaching sessions, so this adds the other two halves of that sentence and nothing else:
  **545 Counselling session 70/60/1** (Tomas Reyes), **546 Physical therapy session 55/45/1** (Nadia
  Okafor), **547 Sports massage session 60/60/1** (Priya Raghavan), **548 Haircut at the studio
  38/45/1** and **549 Haircut at your home 55/60/1** (the same stylist, Mira Lawson). Eight published
  sessions, two of them being the home-or-studio pair the request described as "2 sessions …
  homebase or location".
  **Two things in that request could have become plugin features, and neither was built.** A location
  choice with an address field, and a practitioner a visitor picks, each need new meta, a form
  control, admin screens and a place in the emails — and each is unnecessary, because the diary
  already answers the question it would be built to answer: a home visit is a service of its own, so
  it books through the same form, lands in the same admin list and takes the same confirmation, and a
  therapist is a service of its own, so choosing the row is choosing the person. The address is
  arranged on the phone after booking, and both haircut entries say so in their own copy, because the
  form has no field for it and a booking that silently collected nothing would be worse than one that
  says what happens next.
  **Measured.** `/sessions/` and the home page each render **8** `.bookify-services__item` rows, in
  the listing's own alphabetical order (`Counselling`, `Haircut at the studio`, `Haircut at your
  home`, `One-to-one`, `Partner`, `Physical therapy`, `Small group class`, `Sports massage`), every
  label built from its own meta (`70.00 — 60 min`). The form's session control offers all eight with
  the same wording, and each new service page answers **200**
  (`/sessions/haircut-at-your-home/`). **The diary needed no per-service configuration:** on
  2026-09-14 the three 45-minute and four 60-minute services all offer 15 slots ending 16:00, while
  the 75-minute partner session offers 14 ending 15:30 — `bookify_opening_slots()` steps the studio's
  shared opening hours by each service's own `bookify_duration`.
  **A listing is not a booking**, so `_probes/stage2-more-services-bookable.php` drives the real write
  path once per new service and then removes everything it made: all five booked
  (`BK-00562`…`BK-00566`), each storing `mode none, amount none, status pending` — **identical to the
  control**, `One-to-one session`, booked in the same run — a second booking in a one-person slot was
  refused with `bookify_slot_full`, and all 6 were deleted, leaving the site's own 3 bookings alone.
  12 screenshots (2 URLs × 3 widths × light and dark), `scrollWidth === innerWidth` in every one, 0
  overflowing, no page errors; `wpdev smoke` 10/10; `_elementor_data` md5s unchanged, because not one
  page was edited — every list on the site is built from the data.
  This supersedes T31's "a fourth session" out-of-scope line at the owner's request. One consequence
  to know about: the home page now carries all eight cards rather than three, and the session-list
  widget has **no limit control**, so a front page that shows a subset needs a new control and an
  Elementor edit. `sh: /usr/sbin/sendmail: not found` appears once per booking in the probe's output
  — the container has no mailer, and `wp_mail()`'s result is ignored throughout this plugin.
- **Follow-up, same day: a photograph per session, and descriptions worth the click.** Eight pictures,
  one per session, imported into the media library by `_probes/stage2-service-photos.php` (attachments
  575–582) and set as each session's featured image; the listing card now leads with one and the
  session page carries a wide one between its title and its copy.
  **Where they came from, and why that took the longest.** Free-to-use photographs that also look like
  a modern studio's are harder to find than they sound. Unsplash's search endpoint answers 401 without
  a key; Pixabay and Pexels need keys. Openverse needs none and is the honest place to look, but its
  results are documentary: the first pass offered an aid-agency photograph for counselling, an army
  aerobithon for a group class and a *1948* physiotherapy picture, and nothing at all for massage,
  home haircuts, one-to-one or partner sessions. Adding `source=stocksnap` fixed it — StockSnap is
  **CC0**, so the photographs are free for commercial use with no attribution requirement, and its
  library is modern stock rather than archive. Every candidate was then downloaded and *looked at* in
  contact sheets before being kept, which is how a posed model with dumbbells, an acroyoga pair and a
  street scene were all caught, and how the counselling page ended up with a photograph of houseplants
  on a windowsill: where no literal picture existed in a free collection, the room is a better answer
  than staged people pretending. The alt text describes what is in each frame — never the service —
  and the source and photo id are stored in the attachment's description, so a picture can always be
  traced back. Files arrive at 960px and were re-encoded from 437–603 KB down to 68–118 KB each.
  **The descriptions roughly doubled**: 27 → 201 words for the one-to-one session and 32 → 161 for the
  partner session, 89 → 189 for the sports massage, and so on, written by
  `_probes/stage2-service-copy.php`, which prints the before and after word counts. Each now has two
  subheadings of its own rather than one shared template, so the pages do not read as eight copies of
  the same page — and the theme styles `h2` inside a session's copy a step above the body text, since
  the page-level heading size in the middle of four paragraphs shouts.
  **Two things measurement changed.** The card photograph was written as a full-bleed image pulled to
  the card's edges with negative margins, and the browser refused the margin: the 17px radius,
  `overflow: hidden` and the tint behind it all applied from that same rule while `margin` computed to
  `0px` — and a freshly created element with the same class computed the same, from a rule present in
  the bytes the server sends and in no inline style block. Rather than leave a declaration that does
  nothing, the photograph is inset inside the card's padding, a shape no single refused declaration can
  undo. And the session page's hero at a plain 2:1 is 546px tall at a 1092px container, which pushes
  the description off the bottom of a 900px window — so it is capped at `24rem`, and the copy now
  starts at **728** rather than 890.
  **Measured after:** 8 `<figure>`s on `/sessions/` and on the home page, one per published service,
  **0 broken images** at any of the six width-and-scheme combinations (`img.complete && naturalWidth`
  checked, not assumed), cards at 3 / 2 / 1 columns, and the browser choosing the **768×512** file for
  a card rendered 314px wide — the srcset is doing its job. Hero measured 1092×384 with an 18px radius,
  554×277 at 768 and 346×173 at 390, all at `scrollWidth === innerWidth`, no page errors.
  `wpdev smoke` 10/10; `_elementor_data` md5s unchanged; uploads holds exactly **32** files for the
  eight photographs — one original and three generated sizes each.
  **Left as it is, and why:** the card images carry `loading="lazy"` twice in the rendered tag. The
  attribute WordPress itself emits is disabled on this site — `wp_lazy_loading_enabled()` answers
  `false` for both contexts, because Elementor owns image loading here — so without passing it the
  eight below-the-fold photographs on a listing would all be fetched eagerly, which is a real cost and
  the worse of the two problems. Both values are identical and every browser takes the first.

- **Goal** — a studio can sell a place in a class, a therapist can sell an hour, and the site says so
  in the words the customer uses (plan D19).
- **Depends on** — T16 (capacity and the slot model) and T29 (the vocabulary). **Parallel with** —
  T30.
- **Do**
  1. Give the people field a `max` from `bookify_slot_capacity()` for the session rendered, and give
     the script the capacity of every session it offers so it can re-apply the ceiling when the
     visitor changes session. State in the code that the write path is the refusal.
  2. Raise the group session's capacity to a real class size and re-theme the three sessions for
     therapists, coaches and trainers, keeping three so the grid stays three.
  3. Replace the copy that named the old treatments, and restore the business details so the footer
     block renders again.
  4. Fix what the screenshots show, then shoot again.
- **In scope** — `includes/booking-form.php`, `assets/booking-form.js`, the three session entries
  and their meta, the Elementor copy on pages 111/95/116, and the `bookify_business` option.
- **Out of scope** — the write path's rules (they already refuse an over-capacity party); a fourth
  session; per-date capacity (that is Stage 7); real business details, which are the owner's.
- **Acceptance criteria**
  1. A session's capacity is what the form offers as the party ceiling, and the ceiling follows the
     session when the visitor changes it.
  2. A party larger than a slot's capacity is refused by the write path with its own error code.
  3. Three sessions exist that read as sessions for the new audience, and the footer names the
     business.
  4. No page overflows horizontally at 390, 768 or 1440 px.
- **Verify** — `curl` the form with and without `?bookify_service=85` and diff the `max` attribute;
  the Playwright ceiling check; `wp eval` booking 8, 9 and 2 people; screenshots at three widths;
  `wpdev smoke`.
- **Size** — M

---

## Stage 7 — events and ticket tiers

The stage the plan carried for a day as backlog: capacity per date, ticket tiers (General Admission
vs VIP), date-specific inventory and automated reservation summaries. D21–D24 are the decisions it
needed; it reuses Stage 1's write path and widget pattern, Stage 2's slot model and Stage 5's
payment path, which is why it is last.

### T32 — An event is a dated record with places

- [x] done
- Evidence 2026-09-13: new `includes/events.php` registers `bookify_event` (`public 1`, `publicly_queryable 1`, rewrite slug `events`, `has_archive` false) and its four meta keys; `wp post-type list --fields=name,public,publicly_queryable` → `bookify_event 1 1`, and `get_registered_meta_keys('post','bookify_event')` → `bookify_event_date`, `bookify_event_start`, `bookify_event_end`, `bookify_event_capacity`. A real event was entered as content (`_probes/stage7-content.php`): `414 Autumn reset workshop`, 2026-10-10, 10:00–16:00, 20 places. Driven through the real wp-admin edit form (the screen's own `_wpnonce` and meta-box nonce, `action=editpost`), the box's values went `10:00/16:00/20` → `09:30/17:00/24` and the front page printed `Sat October 10, 2026 09:30–17:00`; an **emptied** capacity deleted the meta — the field read back empty and the Events list said `of —` — rather than storing a zero; both were set back to `10:00/16:00/20`. The new `themes/bookify-theme/single-bookify_event.php` serves `/events/autumn-reset-workshop/` → `http=200 bytes=41261` with the date, the times, `20 places left`, the copy, the header and the footer; the Events list carries `When` and `Tickets sold` columns reading `Sat October 10, 2026 10:00–16:00` and `0 sold, 20 left of 20`. `bookify_event_has_started()`/`bookify_event_is_over()` were both true for a probe event dated yesterday, and `bookify_event_ends_at()` for an event with no end time was `11:00` for a 10:00 start. Screenshots at true 1440x900, 768x1024 and 390x844 (`/tmp/bookify-t35-event-{1440,768,390}.png`), each measured with `window.innerWidth === document.documentElement.scrollWidth` (1440/768/390, no overflow). `wpdev smoke` **10/10**.
- Note recorded: the tiers list on the page is a plain bullet list — the theme styles nothing and the plugin's stylesheet is the form's (D8) — so how it looks is the owner's to decide.

- **Goal** — `bookify_event` exists: a published event stores one date, a start, an end and the
  places it has on that date; an operator enters those four in wp-admin; and the event answers 200
  at its own URL with its date, its times and the places left on it.
- **Depends on** — T2 (post types and registered meta), T9 (the meta-box pattern), T26 (the session
  template D23's second template follows). **Parallel with** — T33.
- **Context to load** — `includes/post-types.php`; `includes/service-fields.php`;
  `includes/availability.php` (`bookify_booking_slot_holding_statuses()`, and what "holds a place"
  means since T23); `themes/bookify-theme/single-bookify_service.php`; plan D21 and D23.
- **Do**
  1. Add `includes/events.php`: register `bookify_event` — `public => true`,
     `publicly_queryable => true`, `rewrite => array( 'slug' => 'events', 'with_front' => false )`,
     `show_in_rest => true`, `has_archive => false`, supports title/editor/excerpt/thumbnail — and
     register its four meta keys `bookify_event_date` (YYYY-MM-DD), `bookify_event_start` (HH:MM),
     `bookify_event_end` (HH:MM) and `bookify_event_capacity` (integer), each with a real
     sanitiser and the `auth_callback` the other types already use.
     `has_archive => false` on purpose: an archive would render through the template hierarchy and
     land on Hello Elementor's bare fallback, which is the defect D9 recorded and D15 fixed.
  2. In the same file, put the rules the rest of the stage reads, each re-validating what it reads
     the way `bookify_availability()` does: `bookify_booking_bookable_event()` (the one definition
     of a sellable event — published, with a readable date and start), `bookify_event_datetime()`,
     `bookify_event_ends_at()`, `bookify_event_is_over()`, `bookify_event_tickets_sold()` and
     `bookify_event_places_left()`. The last two count only bookings in
     `bookify_booking_slot_holding_statuses()`, so a cancelled or expired booking gives its place
     back exactly as it does for a session.
  3. Add `includes/event-fields.php`: the event's meta box (the four fields and a nonce) with T9's
     save discipline — no nonce, no write; capability checked; values unslashed and written through
     the registered sanitisers; an emptied box deletes the meta — and a Tickets sold column on the
     Events list, so the number T36 summarises is readable in wp-admin too.
  4. Add `themes/bookify-theme/single-bookify_event.php`, presentation only and on the session
     template's pattern: title, date, start and end, places left, the content, and the tiers and
     ticket form the plugin hands it — which T33 and T35 fill in.
  5. Require the files from the bootstrap and register the hooks inside `bookify_booking_init()`.
- **In scope** — `wp-content/plugins/bookify-booking/**`;
  `wp-content/themes/bookify-theme/single-bookify_event.php`.
- **Out of scope** — tiers (T33), the ticket form (T35), the summary (T36); an events archive or
  listing page; Event JSON-LD; a venue field, because the business's address is already stored once.
- **Acceptance criteria**
  1. `wp post-type list` shows `bookify_event` public and queryable, with the four meta keys
     registered for it.
  2. An operator can set the four values on the event's edit screen, and they are what the page
     prints.
  3. The event's URL answers 200 with its date, times and places left; a draft event answers 404 to
     an anonymous browser.
  4. An event whose date has passed is over: `bookify_event_is_over()` says so, and its places left
     are no longer offered.
- **Verify** — `wpdev wp post-type list --fields=name,public,publicly_queryable`;
  `wpdev wp eval` on a probe event printing the registered keys and each helper; `curl -o /dev/null
  -w '%{http_code}'`; a screenshot at 1440 and 390; `wpdev smoke`.
- **Size** — M

### T33 — Ticket tiers: a price and an inventory each

- [x] done
- Evidence 2026-09-13: `bookify_tier` is registered `public=false`, `show_ui=true`, nested under Events, with `bookify_event_id`, `bookify_tier_price` and `bookify_tier_capacity` (`get_registered_meta_keys('post','bookify_tier')` lists all three). Two tiers exist on event 414 — `415 General admission GBP 45.00 / 18 tickets`, `416 VIP GBP 75.00 / 6` — and the page prints `General admission GBP 45.00 18 left` and `VIP GBP 75.00 6 left`, all read through `bookify_event_tiers()`, `bookify_tier_price_label()` and `bookify_tier_places_left()`, with nothing re-typed in the template. Places left honours a cancellation: in `_probes/stage7-booking.php` a two-ticket booking took a tier from `2 left` to `0 left` and cancelling it put it back to `2 left`, while the event's own count went `2 sold → 7 sold → 5 sold` as the second booking was added and the first was cancelled. A tier belonging to another event and a draft tier are both refused as `bookify_unknown_tier`. The Bookings list and the booking's own meta box name the pair — `Autumn reset workshop — VIP` — because `bookify_booking_fields()` gained the two keys and the column now prints `bookify_booking_item_label()`.

- **Goal** — a published tier belongs to one event, carries a price per ticket and its own
  inventory, is entered in wp-admin, and appears on the event's page with its price and the places
  left.
- **Depends on** — T32. **Parallel with** — none.
- **Context to load** — T32's `includes/events.php`; `includes/service-fields.php`; plan D22 and
  D24.
- **Do**
  1. Register `bookify_tier` — `public => false`, `show_ui => true`, supports title only, the shape
     `bookify_booking` uses — with three meta keys: `bookify_event_id`, `bookify_tier_price` (per
     ticket) and `bookify_tier_capacity` (tickets of this kind). Not public for the same reason a
     booking is not: nothing but the event page needs to address a tier, and a public type would add
     a bare URL per tier with nothing to read on it.
  2. Add the readers: `bookify_event_tiers( $event_id )` (published tiers of one event, in title
     order), `bookify_tier_event_id()`, `bookify_tier_price()`, `bookify_tier_places_left(
     $tier_id )`, and `bookify_booking_event( $booking_id )` / `bookify_booking_tier( $booking_id )`
     so "what did this person buy" is one call.
  3. Add the tier box to `includes/event-fields.php`: the event (a select of published events), the
     price and the inventory, with the same nonce/capability/sanitiser discipline; add the two new
     keys to `bookify_booking_fields()` and name them in the Bookings list, so an operator reading a
     booking can see which event and tier it is.
  4. Show the tiers on the event page: name, price per ticket and places left, read from the
     helpers, never re-typed in the template.
- **In scope** — `wp-content/plugins/bookify-booking/**`; the tier part of `single-bookify_event.php`.
- **Out of scope** — selling anything (T34); a tier with a date of its own, or one tier shared
  between events; per-date or per-quantity pricing.
- **Acceptance criteria**
  1. Two published tiers on one event, with different prices and different inventories, are both
     listed by `bookify_event_tiers()`.
  2. `bookify_tier_places_left()` counts a booking that holds a place and ignores a cancelled one.
  3. The event page prints each tier's price and places left, and a tier whose event is a draft is
     not offered.
- **Verify** — `wpdev wp eval` printing a probe event's tiers and their places left before and after
  a booking; the event page in the browser; `wpdev smoke`.
- **Size** — M

### T34 — Buying a ticket: the capacity rule, asked twice

- [x] done
- Evidence 2026-09-13: `_probes/stage7-booking.php`, 25 checks, all PASS — the valid ticket stores the event's date and time rather than the `2030-01-01 23:59` the request asked for, names its event and tier, stores `30.00` for two GBP 15 tickets and starts `awaiting_payment`/`unpaid`; `bookify_tier_full` for 3 tickets against 2 left, with the booking count unchanged; `bookify_event_full` for a party the event's own capacity of 2 could not take while the tier still showed `8 left`; `bookify_unknown_event` for a draft; `bookify_event_passed` for yesterday's event; `bookify_unknown_tier` for another event's tier and for a draft one; cancelling returned the places to both counts (`5 sold, 5 left`); `bookify_booking_can_move()` answered `bookify_event_not_movable`; and a session booking still went through the same entry point (booking 413, labelled `One-to-one session`, kind `Session`). The run is re-runnable and left the booking count as it found it (`3 bookings before and after`). `bookify_create_booking( array )`'s signature and its `int|WP_Error` return are unchanged; the resolve/refuse/report split is new but the session path's rules are the same statements, moved. Three wordings moved with it, each because one message now serves two products: the confirmation and the reminder say `Booking: …` instead of `Session: …`, the confirmation says `Tickets: 2` for a ticket and keeps `Guests: 2` for an appointment, and the manage page's party row is now `People` — a session confirmation re-captured after the change still reads `Booking: One-to-one session / Guests: 1`. A ticket also earns T30's reminder: a 2026-10-03 18:00 event scheduled its reminder for 2026-10-02 18:00, because the date and time keys the reminder reads are the event's. `wpdev smoke` 10/10.

- **Goal** — `bookify_create_booking()` accepts an event and a tier and refuses a party larger than
  the tier's inventory (`bookify_tier_full`) or larger than the event's capacity
  (`bookify_event_full`), inside the same advisory lock that guards a session slot.
- **Depends on** — T32, T33. **Parallel with** — T35.
- **Context to load** — `includes/bookings.php` as it stands (the validation, the lock, the shared
  meta block); `includes/availability.php`; plan D16, D19, D21, D24.
- **Do**
  1. In `includes/bookings.php`, resolve *what is being booked* before anything is written: a
     request naming `event_id` is an event booking, whose date and time come from the event and never
     from the request; anything else is a session booking exactly as it is today. Keep
     `bookify_create_booking( array $data )`'s signature and its `int|WP_Error` return — T2–T4 fixed
     them, and the form, the REST route and T21's reschedule all call it.
  2. Ask capacity twice for a ticket — the tier's inventory first, then the event's date — both
     inside the existing lock and both through `bookify_booking_slot_holding_statuses()`, so an
     unpaid booking holds a ticket and a cancelled one gives it back. An event with no capacity
     stored has no ceiling of its own and only its tiers bind; say so in the code.
  3. Store `bookify_event_id` and `bookify_tier_id`, and keep the shared keys the rest of the plugin
     already reads: `bookify_date` and `bookify_time` are the event's date and start, so the
     confirmation, the reminder, the admin list and the customer's own page work unchanged;
     `bookify_party_size` is the number of tickets, which is also the number of people.
  4. Add the new error codes to `bookify_booking_error_messages()` and `bookify_booking_error_field()`,
     and refuse a move for an event booking with a code of its own — a ticket cannot be moved to
     another time, and `bookify_booking_can_move()` is where the manage page asks.
  5. Name what was booked in one place: `bookify_booking_item_kind()` and
     `bookify_booking_item_label()` — the session's title, or the event's with its tier — used by the
     admin list, the manage page, the confirmation and the reminder, so an event booking is not
     printed as an empty session.
- **In scope** — `wp-content/plugins/bookify-booking/includes/bookings.php`; the error lists; the
  naming helpers where those four screens print what was booked.
- **Out of scope** — the form (T35); any change to the session path's rules; a waitlist; moving a
  ticket to another date.
- **Acceptance criteria**
  1. A valid event booking is created with its meta, and its date and time are the event's.
  2. A party larger than the tier's inventory is refused with `bookify_tier_full` and creates no
     row; larger than the event's capacity, with `bookify_event_full`.
  3. An event that has already started, a draft event, and a tier belonging to another event are all
     refused.
  4. Cancelling a ticket gives its place back to both counts.
- **Verify** — `wpdev wp eval-file` on a `_probes/stage7-booking.php` script running each refusal
  and printing the code and the booking count; `php -l`; `wpdev smoke`.
- **Size** — M

### T35 — The ticket form, and paying for tickets

- [x] done
- Evidence 2026-09-13: `_probes/stage7-tickets.php`, 24 checks, all PASS. The form on the real event offers both tiers with their prices and places left (`General admission — GBP 45.00 — 18 left`, `VIP — GBP 75.00 — 6 left`), prints the hidden `bookify_event_id`, the nonce and the honeypot, asks for `booking-form.css` and `event-tickets.js`, and its quantity ceiling is the chosen tier's places left (`max=18`, dropped to `max=4` once a booking took two VIP places). One tier on sale becomes a hidden field rather than a dropdown. An event with nothing left renders `Every ticket for this event has gone.` and an event that has started renders `This event has finished, so its tickets are no longer for sale.` — neither offers a control. `bookify_booking_ticket_amount()` is price × tickets (GBP 45.00 × 3 = 135, and the booking stored `135`). With a key set and the answer stubbed, the Stripe request was `quantity=3`, `unit_amount=4500`, description `Autumn reset workshop — General admission — booking BK-00432`, `client_reference_id` the reference and one metadata key — no customer data. The widget is registered as `Ticket form` under `bookify`; rendered through Elementor's own path with panel settings, its copy came from the controls (`Control-driven ticket title`, `Control-driven button`, `How many of these`) and the same tiers appeared; `[bookify_event_tickets]` rendered the same form. **Over real HTTP**, a POST of the rendered form created exactly one booking (`BK-00453`: tier VIP, 1 ticket, `awaiting_payment`, amount `75`, date/time `2026-10-10 10:00`, label `Autumn reset workshop — VIP`), its confirmation read `Booking: Autumn reset workshop — VIP / Tickets: 1`, its manage page read `Event / Autumn reset workshop — VIP / People 1 / Unpaid — waiting for payment — GBP 75.00` with no move picker and the `Tickets cannot be moved` line. **In a real browser** (Playwright against the cached Chromium, `/tmp/bookify-stage7-shots.cjs`), choosing VIP moved the ceiling to `max=6` and a quantity of 9 was refused by the browser's own validation (`checkValidity() false`); a typed submission created `BK-00454` (2 VIP) and came back to `?bookify=booked` with `Thank you — your tickets are booked and the confirmation is on its way.` and the page then reading `18 places left`. Both test bookings were deleted afterwards, so event 414 is back to `0 sold, 20 left`. `wpdev smoke` 10/10.

- **Goal** — `[bookify_event_tickets]` and its Elementor widget render a working ticket form for one
  event, and a ticket on a paid tier stores the amount that tier charges for the quantity bought.
- **Depends on** — T34, and T24's Stripe path reused as it is. **Parallel with** — T36.
- **Context to load** — `includes/booking-form.php` (its defaults, `bookify_booking_form_field()`,
  the notice, the asset-registration hooks); `includes/elementor-widget.php`;
  `includes/payments/amounts.php`; `includes/payments/stripe.php`; plan D8 and D24.
- **Do**
  1. Add `includes/event-tickets.php`: `bookify_event_ticket_form_html( array $args = array() )`,
     with every visible string coming from `$args` the way T5 required of the booking form —
     the event's tiers with their prices and places left, a quantity control whose `max` is what the
     chosen tier has left, the customer's name, email and phone, the honeypot, the nonce, and the
     success and error copy — reusing `bookify_booking_form_field()` so a rejected submission comes
     back with the message against the field it is about. It posts to the existing
     `admin_post_bookify_booking` handler with `bookify_event_id` and `bookify_tier_id`;
     `bookify_booking_handle_submission()` gains those two reads and nothing else.
  2. Register the shortcode, and add the widget class to `includes/elementor-widget.php` on the same
     `elementor/widgets/register` hook and in the same `bookify` category. No string is printed
     literally: the widget's controls are the copy.
  3. Put the form on the event's page, and give the template nothing but the call.
  4. Price a ticket in `includes/payments/amounts.php`: `bookify_booking_ticket_amount( $tier_id,
     $quantity )` is the tier's price times the quantity, rounded once, and it is what
     `bookify_create_booking()` stores as `bookify_payment_amount`. A ticket is paid in full (D24),
     so the booking starts `awaiting_payment` and rides the existing window, expiry appointment and
     webhook: no new payment code.
  5. Name the purchase on Stripe's own page: T24's line item is the session's title today, and for a
     ticket it is the event and the tier, with the quantity as the line item's own quantity rather
     than a multiple of one item.
- **In scope** — `wp-content/plugins/bookify-booking/**`; the form call in
  `single-bookify_event.php`.
- **Out of scope** — a second payment provider; deposits or part-payment for tickets; a per-event
  currency; discounts; seat selection.
- **Acceptance criteria**
  1. The event page renders the form with every tier, its price and its places left, and a browser
     submission creates exactly one booking.
  2. A tier with nothing left is refused by the write path with `bookify_tier_full`, and the form
     says so rather than offering it.
  3. A ticket on a paid tier is written `awaiting_payment` with `bookify_payment_amount` equal to
     price × quantity, and a `pre_http_request` stub shows the Stripe request carrying that amount
     and the event's name.
  4. The widget renders the same form from its controls, and the shortcode renders it on a page.
- **Verify** — the browser at 1440, 768 and 390 with a screenshot each; `curl` the page with and
  without a chosen tier and diff the `max` attribute; `wpdev wp eval-file` on a
  `_probes/stage7-tickets.php` script covering the amount and the refusals; `node --check` on any
  script the form ships; `wpdev smoke`.
- **Size** — M

### T36 — The reservation summary

- [x] done
- Evidence 2026-09-13: `_probes/stage7-summary.php`, 15 checks, all PASS. Saving the event through `wp_update_post()` — the path wp-admin itself takes — left one appointment at `ends_at + 1`; changing the stored date and saving again moved it (`2026-11-02 16:00` → `2026-11-05 16:00`); an event with no end time is due an hour after it starts (`11:00`). Running the hook composed **one** message to `dev@example.com` with subject `Reservations for T36 probe event on 2026-11-05` and this body:

  ```
  Reservations for T36 probe event
  Date: Thu November 5, 2026
  Time: 10:00–16:00
  Places: 3 sold, 11 left

  Tickets:
    General admission — GBP 20.00 — 2 sold, 8 left
    VIP — GBP 40.00 — 1 sold, 3 left

  Reservations:
    BK-00439  T36 probe <t36a@example.com>  2 × General admission
    BK-00440  T36 probe <t36b@example.com>  1 × VIP
  ```

  The cancelled booking and the other event's booking are both absent; the From line is the site's own. A second run sent nothing and did not ask for the appointment again (the flag, and the appointment released on every exit path), and deleting an event dropped its appointment. `wp_next_scheduled()` on the site's own event 414 names `2026-10-10 16:00`, which is exactly when that workshop ends. `wpdev smoke` 10/10.

- **Goal** — one summary of an event's reservations is composed from the bookings themselves and
  handed to `wp_mail` at the moment the event ends, once.
- **Depends on** — T32, T34. **Parallel with** — T35.
- **Context to load** — `includes/emails.php` (the composition pattern); `includes/reminders.php`
  (T30's schedule/unschedule discipline, and why an appointment beats a poll); plan D6.
- **Do**
  1. Add `includes/event-summary.php`: `bookify_booking_event_reservations( $event_id )` — every
     booking still holding a place, in reference order, with the name, the email, the tier and the
     tickets — and `bookify_booking_event_summary_lines( $event_id )`, turning them into the
     message: a line per reservation and a total per tier (tickets sold, places left), then the
     event's own numbers.
  2. `bookify_booking_send_event_summary( $event_id )` composes the subject from the event's title
     and date, sends to the admin address, and writes one meta flag on the event so a second run
     cannot send a second copy. What `wp_mail()` returns is ignored, for the reason T7 ignores it.
  3. Schedule it: `bookify_booking_schedule_event_summary()` asks for one event at the moment the
     event ends, hooked to `save_post_bookify_event` so an edited date moves the appointment, and
     released on `deleted_post`. Nothing is scheduled for an event with no readable date.
  4. Nothing polls and nothing is cached: the summary is one query, once, for one event.
- **In scope** — `wp-content/plugins/bookify-booking/**`.
- **Out of scope** — sending the customer anything they have not already had; a per-tier report
  screen; CSV export; sending on sell-out rather than when the event ends.
- **Acceptance criteria**
  1. `wp_next_scheduled()` names one appointment, at the event's end.
  2. Running it composes one message to the admin address, naming the event, its date, every
     reservation and the totals per tier.
  3. Running it twice sends one message; a cancelled booking is not in it; another event's booking
     is not in it.
  4. Deleting the event drops the appointment.
- **Verify** — `wpdev wp eval-file` on a `_probes/stage7-summary.php` script capturing
  `pre_wp_mail` and asserting the appointment; `wpdev smoke`.
- **Size** — M

---

## Stage 9 — the site's own appearance

Not a feature. Everything above was built to work, and the result was running on Elementor's
**untouched kit** — pastel-blue headings, green buttons, Roboto — over an **empty child
stylesheet**, so the site had no design of its own at all. This stage gives it one, in CSS.

### T37 — A design of its own

- [x] done
- Evidence 2026-09-13. **What was measured first, not assumed:** the kit's `_elementor_page_settings`
  is empty, so `post-4.css` carries Elementor's factory values — `--e-global-color-primary:#6EC1E4`,
  `accent:#61CE70`, `secondary:#54595F`, `text:#7A7A7A`, Roboto / Roboto Slab. A heading computed to
  `rgb(110,193,228)` and an `.elementor-button` to `rgb(97,206,112)`. `bookify-theme/style.css` loaded
  with **0 rules**, and the child sheet sat at **stylesheet index 2** — before Elementor's reset,
  theme, kit and post CSS. A grep for `.bookify-session` / `.bookify-event` across the whole project
  returned only the two theme templates: no stylesheet anywhere had ever styled them. 18 baseline
  screenshots at 1440/768/390, all `scrollWidth === innerWidth`.
- Evidence, **after**: worst text pair **5.23:1 light / 5.65:1 dark**, every pair ≥ 4.5:1, where the
  three the site had been shipping were **2.02:1** (`#6EC1E4` heading on white), **1.99:1** (white on
  the `#61CE70` button) and **3.50:1** (the card's hover pair) — all computed by a script that reads
  the token block out of `style.css` rather than being told the values. The child sheet now prints at
  **index 16**, after the kit (10) and the page CSS (13). `document.fonts.check()` is `true` for both
  Lora and Source Sans 3, and the computed families are `Lora, ui-serif, Georgia, …` and
  `"Source Sans 3", ui-sans-serif, …`. 36 screenshots (6 pages × 3 widths × light and dark), **0
  overflowing**. `wpdev smoke` 10/10.
- Evidence, **Elementor was not touched**: `_elementor_data` md5s for pages 111 / 95 / 116 / 22 / 228 /
  214 and kit 4 are **byte-identical to the baseline** taken before the first edit (`a2609f6d…`,
  `ff9edb64…`, `2e141f37…`, and empty for the three shortcode pages and the kit). Then
  `wp elementor flush-css` deleted the whole generated CSS directory and all six URLs re-rendered
  200 with `post-4.css` regenerated **from the still-stock kit** — and the design held: `h2`
  `rgb(31,42,40)`, both bands `rgb(20,49,44)`, the service link `rgb(20,102,91)` on white. That is
  the point of the bridge below: a kit reset cannot undo it.
- Evidence, **the container query answers the right question**: at a 1440px viewport the form is
  `312px 312px` in its 640px container, and `320px` — one column — when the same container is
  narrowed to 320px. Before, the breakpoint was a viewport media query and the window would have
  stayed 2-up. Column counts on the pages that exist are unchanged from baseline (Book: 312/312 at
  1440, 292/292 at 768, single at 390; session and event templates 2 columns at 1440), so this is a
  correctness fix for a widget dropped into a narrow section, not a restyle.
- Evidence, **focus and motion**: after a 500ms settle, a select, an input and an Elementor button
  all report `solid 3px rgb(20,102,91)` at `outline-offset: 2px`. Under `prefers-reduced-motion:
  reduce` every section reads opacity 1, `animation-name: none`, `transition-duration: 0s` and
  `scroll-behavior: auto`.
- Two defects found by looking rather than reasoning, both fixed here: WordPress's block-library
  `:focus-visible { outline: 2px solid #000 }` prints after the theme and was outranking it, so
  Elementor's buttons kept a black ring while the form's fields showed the teal one; and the scroll
  reveal as first written animated **opacity**, which left the 1191px-tall mobile sessions grid at
  opacity 0 until it was half scrolled past.
- Not done, and why: the `elementor` MCP server was unavailable in this session, so the kit's stored
  global colours and typography were **left untouched** — hand-writing `_elementor_page_settings`
  would be the `update_post_meta()` mistake rule 2 forbids. The theme's `body.elementor-kit-4` bridge
  applies the same palette with higher specificity, so the front end and the editor preview both show
  it; the only visible gap is that the colour swatch chips in the builder panel still show the old
  hex values. Changing them in **Site Settings → Global Colors** is a two-minute job for the owner and
  makes the Roboto request go away.
- Follow-up, same day, at the owner's request — **the footer**. It was three blocks on three different
  alignments: the menu sat in a 1200px flex row that `space-between` pushed away from the left edge, a
  dead `space-between` slot was being held open by an empty branding div, "Bookify Studio" and
  "Opening hours" were centred in a 34rem column of their own, and the copyright — which read as
  "All rights reserved", with no year and no name — sat at the **top right**, before the details,
  because the mark-up cannot put it anywhere else: Hello Elementor renders it inside `<footer>`, and
  `wp_footer` prints the plugin's business block *after* `</footer>`. Measured at 1440: menu left at
  **475**, details left at **441**, copyright at **1184**.
  Fixed by giving the two containers one shared `--bk-footer-gutter` (they have no common parent to
  inherit an alignment from, so they agree by resolving the same expression), making the footer
  block-layout so the empty branding div cannot hold a gap open, scoping the left-aligned variant to
  `body > .bookify-business` so the **Contact page's** inline copy stays centred in the article, and
  moving the copyright itself into the theme: `hello_elementor_hello_footer_copyright_text` is
  filtered to empty, which stops the parent rendering its block at all, and the theme prints
  `© 2026 Bookify. All rights reserved.` on `wp_footer` at priority 20 — after the plugin's block at
  10, so it is genuinely the last line on the page — with the year from `wp_date()` and the name from
  `get_bloginfo()`, so neither can go stale.
  Measured after: menu, "Bookify Studio" and "Opening hours" share a left edge of **137** at 1440,
  **24** at 768 and **24** at 390, and the copyright's right edge is **1289 / 729 / 351** — the same
  content edge as the rest of the footer — in both colour schemes. The empty `.copyright` div Hello
  Elementor still renders in the editor (it forces it there so the element stays selectable) has no
  content and no height.
- **Second pass, same day: that was still one narrow column.** Looking at it at 1440 showed why —
  everything sat in the left third of a wide band, with the opening hours stacked under the address
  instead of beside it. Rebuilt as a modern footer: the details and the hours are now **two columns of
  one row** (`minmax(0, 1fr) auto`, so the hours take their own width and sit flush right with no dead
  gap), and the footer ends on a **legal bar** — hairline, then the footer menu on the left and the
  copyright on the right, both on the gutter every other row uses. The menu moved into that bar on
  purpose: it is the only arrangement that fills the row, and the parent's copy is hidden with
  `display: none` (so it leaves the accessibility tree and the tab order — one menu on the page, not
  two) while the theme draws the same `menu-2` location in the bar. That call reuses the parent's own
  theme location, honours the Customizer's "hide footer menu" setting, and takes a different `menu_id`
  because the parent's copy is still in the document. Measured: **one visible menu at every width**,
  no duplicate `id`s, and the bar's nav left edge equals the details' left edge (**137 / 24 / 24**)
  while the copyright's right edge equals the hours' right edge (**1289 / 729 / 351**).
  Above 48rem the hours drop their separator — two columns already say the groups are different
  things — and on one column they keep it.
  Two traps found by measuring rather than reading: below 576px the parent hides and re-shows its
  stacked footer with `.site-footer:not(.footer-stacked) .footer-inner .site-navigation`, which is one
  class stronger than the obvious selector and put **two** menus on a phone until the theme's rule
  matched its shape class for class; and the plugin centres its address lines with
  `.bookify-business .bookify-availability { max-width: 34rem; margin-inline: auto }`, which on a
  **grid item** means shrink-to-fit and centre — the name was a 133px box floating mid-column instead
  of a line starting at the gutter until the footer variant reset the auto margins, one class deeper
  for the hours because that is how the plugin reaches it. 36 screenshots, 0 overflowing;
  `wpdev smoke` 10/10; `_elementor_data` still byte-identical; the **Contact page's** inline copy of
  the same block is untouched and still centred in the article.
- **Third pass, same day: the booking form's date field.** The calendar T18 built rendered open, so a
  visitor met a month grid in the middle of the form before they met the form — measured, the field
  was 69px of control plus 277px of grid at all times. It is a **dropdown** now: the field names the
  chosen day and the grid appears only while it is open, floating 4px under the field on the same left
  edge and width instead of pushing the rest of the form down (the field is 69px in both states, which
  is what makes it a dropdown rather than an accordion).
  The trigger is built by the script, not the markup, so a visitor without JavaScript is never shown a
  button that cannot work: they keep the day `<select>`, all 51 options of it. The panel is a button
  with `aria-expanded` + `aria-controls`, opens on click or Enter, moves focus into the grid, closes on
  a chosen day, on Escape (focus back to the field) and on a click outside, and takes the day's name
  from the `<select>`'s own option text — so the field and the control it stands in for cannot word
  the same day differently. 23 checks pass in a real browser, including "no console or page errors",
  plus a JS-disabled context proving the fallback. 36 screenshots, 0 overflowing; `wpdev smoke` 10/10.
  One trap, recorded because it is invisible: **jQuery UI gives the element it is bound to an id of its
  own when that element has none** (`dp1`, `dp2`, …) and resolves every day click through it —
  `_attachDatepicker` sets it, and the day handler does `$( "#" + inst.id )` and reads the instance
  back off that element. Renaming the panel to something nicer *after* initialising the widget
  therefore broke day selection completely, with no error and nothing in the console. The id is now
  set before the widget is built.
- **Fourth pass: the booking page's own title.** `/book/` read "Book" (the page's `h1`) and then "Book
  an appointment" (the form's `h2`) — the same word twice before the form began. The page title is now
  taken out of the layout on that page alone. Visually hidden rather than `display: none`, because it
  is the page's only `<h1>` and removing it from the accessibility tree would leave the page with no
  heading at all; measured after, the header box is **1×1**, takes no space and leaves **no gap** above
  the form, while the h1 is still in the document. Scoped by page id, and deliberately not to
  `body:has(.bookify-booking)`: **Manage booking** and **My bookings** also pair a page title with a
  form heading, but there the two read as a page and a form rather than a repeat, and both are
  unchanged (1140×69, still visible). 18 shots, 0 overflowing; `wpdev smoke` 10/10.
- **Fifth pass, at the owner's request: the 404 page.** A wrong URL rendered two of Hello Elementor's
  own sentences — "The page can't be found." and "It looks like nothing was found at this location." —
  with nothing to do next, and no stylesheet in the project had ever mentioned `.error404`. It is a
  page now: a wash of accent colour behind a decorative `aria-hidden` **404**, the message as the
  `h1` (`We could not find that page`), and one row of actions — **Book a session**, **See what we
  offer**, **Home** — each of which resolves **200**, so the page that means "this address is wrong"
  does not itself send anyone to a wrong address.
  Built as a **template part override** — `bookify-theme/template-parts/404.php`, drawn by the
  parent's `index.php` — and not as the parent's `index.php` and not as a page template, so the parent
  keeps the routing, the header and the footer and only the panel between them is ours. That is also
  what keeps D15 intact: the part is presentation, with one `home_url()` in it and nothing else, and
  the theme still contains **0** occurrences of a post type, a shortcode or a superglobal. The status
  code is still a real **404**; a 404 that answered 200 would be an SEO defect wearing a nicer font.
  The motion is transform-only and inside `prefers-reduced-motion: no-preference`, for the reason the
  scroll reveal records — nothing here can leave text invisible. The number drifts (7s alternate), the
  wash breathes (9s alternate, and centred with the independent `translate` property so `transform` is
  free for the animation), and the copy settles on load in a 70/140/210ms stagger. Measured in the
  cached Chromium at 1440 / 768 / 390 in both colour schemes: `document.getAnimations()` reports **2
  running** — the two endless ones, with `bookify-lift` present and finished — `scrollWidth ===
  innerWidth` at all six, and under `reducedMotion: 'reduce'` every animation reads `none` / `1e-06s`
  with **0 running**.
  Two things the pictures caught that the code did not. The stagger **did not apply** at first: the
  `animation-delay` was declared in a lower-specificity rule *after* the `animation` shorthand, which
  resets the delay to zero and made the whole thing moot, so each element now carries its own full
  shorthand. And at 390px the three pills wrapped two-then-one, which reads as an accident, so below
  480px the primary action takes the full width and the quieter two share the row beneath it.
  Theme version 0.4.1 is the cache-buster (the `?ver=` on the child sheet is the only one there is).
  6 shots plus 1 reduced-motion shot, 0 overflowing; `wpdev smoke` 10/10; `_elementor_data` md5s
  unchanged.

- **Goal** — the site stops looking like an unconfigured theme: one palette, one type scale, one
  button, and component stylesheets that size themselves against their own container.
- **Depends on** — nothing it breaks; reads T11, T13, T26, T32's markup. **Parallel with** — nothing.
- **Context to load** — plan D8 (the plugin ships its own component CSS); the `visual-testing` and
  `wordpress-best-practices` skills; `wp-content/themes/bookify-theme/style.css`.
- **Do**
  1. Put one set of tokens in the theme — palette, fluid `clamp()` type scale, space, radius, shadow,
     easing, measure — and restate the Elementor kit's `--e-global-color-*` and
     `--e-global-typography-*` from them on `body.elementor-kit-4`, which is (0,1,1) against the kit's
     (0,1,0) and so wins whatever Elementor's CSS file is regenerated to contain. No `!important`
     anywhere, so an editor can still override any individual widget.
  2. Style the site chrome and the Elementor surface from the same tokens: header, both menus, the
     sub-1024px toggle, footer, headings, buttons, links, focus, and the two dark bands whose
     backgrounds Elementor stores as literals.
  3. Give the four plugin component stylesheets the shared tokens first and their old values last
     (`var(--bk-*, var(--e-global-*, #fallback))`), so they keep standing alone per D8.
  4. Convert their viewport media queries to `@container` queries and set `container-type:
     inline-size` on each component root.
  5. Style `.bookify-session*` and `.bookify-event*` — theme classes that had no CSS at all.
  6. Add motion behind `@media screen and (prefers-reduced-motion: no-preference)` and
     `@supports (animation-timeline: view())`, animating transform only.
  7. Add a `prefers-color-scheme: dark` token swap, plus `color-scheme`, so the whole site follows.
- **In scope** — `wp-content/themes/bookify-theme/**`; the four assets stylesheets under
  `wp-content/plugins/bookify-booking/assets/`.
- **Out of scope** — any `_elementor_data` rewrite, any new Elementor page or template, any
  JavaScript, any markup change inside the plugin's form/list/manage markup, wp-admin styling, a
  manual dark-mode toggle (it needs JavaScript), self-hosted font files.
- **Acceptance criteria**
  1. No heading, button or link pair is below WCAG AA in either colour scheme.
  2. Every page renders at 1440, 768 and 390 without horizontal overflow, in light and dark.
  3. `_elementor_data` for every page and the kit is unchanged, and the design survives
     `wp elementor flush-css`.
  4. The form's column count follows the width of its own container, not the viewport.
  5. Reduced motion removes every transition and every entrance.
- **Verify** — the contrast script over `style.css`; `bookify-shots.cjs` for 6 URLs × 3 widths in
  both schemes with `scrollWidth === innerWidth`; the `_elementor_data` md5 comparison; the
  container-width experiment in the browser; `wpdev smoke`.
- **Size** — L
