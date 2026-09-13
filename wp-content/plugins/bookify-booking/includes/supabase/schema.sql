-- bookify → Supabase Postgres.
--
-- Run this once, in the Supabase SQL editor (or with psql against the project's connection string).
-- PostgREST has no DDL endpoint, so the plugin cannot create these tables itself, and it will not
-- try: the mirror writes rows and nothing else.
--
-- Safe to re-run. Every statement is `if not exists`, so applying it twice is a no-op.
--
-- ## What this is, and what it deliberately is not
--
-- It is not a copy of `wp_postmeta`. WordPress keeps a booking's two dozen facts as rows of
-- `wp_postmeta`, every one of them `longtext`, with no types, no foreign keys and no way to ask
-- "which bookings are on Friday?" without a join per fact. These tables hold the same information as
-- real columns — a real `date`, a real `time`, a `numeric(10,2)` amount, real foreign keys — because
-- a copy that is queryable only as badly as the original is not worth making.
--
-- `id` is the **WordPress post id**, in every table. That is what makes a row here traceable back to
-- the thing it describes, and it is also the conflict target the mirror upserts on.
--
-- ## The two things a reader must not "fix"
--
-- 1. **The booking tokens are not here, and must not be added.** `bookify_cancel_token`,
--    `bookify_manage_token` and `bookify_manage_expires` are the only thing between a stranger and
--    somebody else's booking: the reference is a lookup key and the token is the secret that proves a
--    manage link belongs to the booking it names. This database is a third party holding a copy; a
--    leaked mirror that carried the tokens would hand out working cancel and manage links for every
--    booking on the site.
-- 2. **There are no CHECK constraints on the enumerated columns** (`status`, `payment_mode`,
--    `item_kind`, …). The sets are defined in PHP and they grow — a new booking status was added in
--    stage 7. A CHECK here would turn "the site learned a new status" into "the mirror silently
--    rejected a batch and nobody noticed", which is the worse failure. The permitted values are
--    listed in the column comments instead.
--
-- ## Access
--
-- Row level security is enabled on every table here with **no policies**, which denies every read and
-- write to `anon` and `authenticated`. The plugin uses a `service_role` key, which bypasses RLS, so
-- the mirror works and nothing else can reach the data through the auto-generated REST API.

begin;

-- ---------------------------------------------------------------------------
-- Sessions: the bookable catalogue.
-- ---------------------------------------------------------------------------
create table if not exists bookify_services (
	id               bigint primary key,
	slug             text not null default '',
	title            text not null default '',
	excerpt          text not null default '',
	description_html text not null default '',
	status           text not null,
	price            numeric(10,2),
	duration_minutes integer,
	capacity         integer,
	payment_mode     text,
	deposit_type     text,
	deposit_value    numeric(10,2),
	permalink        text not null default '',
	thumbnail_id     bigint,
	created_at       timestamptz,
	updated_at       timestamptz
);

comment on table bookify_services is
	'One row per bookify_service post. price is the whole slot, whatever the party size (a couples session is priced for two).';
comment on column bookify_services.status is
	'WordPress post status: publish, draft, pending, private, future or trash.';
comment on column bookify_services.payment_mode is 'none, deposit or full.';
comment on column bookify_services.deposit_type is 'amount or percent.';
comment on column bookify_services.capacity is 'People that fit in one slot.';

-- ---------------------------------------------------------------------------
-- Events: one date each.
-- ---------------------------------------------------------------------------
create table if not exists bookify_events (
	id               bigint primary key,
	slug             text not null default '',
	title            text not null default '',
	description_html text not null default '',
	status           text not null,
	event_date       date,
	starts_at        time,
	ends_at          time,
	capacity         integer not null default 0,
	permalink        text not null default '',
	thumbnail_id     bigint,
	created_at       timestamptz,
	updated_at       timestamptz
);

comment on table bookify_events is
	'One row per bookify_event post. An event is a single date, so there is no recurrence to model.';
comment on column bookify_events.event_date is
	'Nullable: an event the owner has not dated yet is a draft, and a draft is still a row.';
comment on column bookify_events.ends_at is
	'Nullable. An event with no end time lasts one hour, which is what the reminder and summary code assumes.';
comment on column bookify_events.capacity is
	'0 means the event has no ceiling of its own and its tiers'' inventories are the only limit.';

-- ---------------------------------------------------------------------------
-- Ticket tiers.
-- ---------------------------------------------------------------------------
create table if not exists bookify_tiers (
	id         bigint primary key,
	event_id   bigint not null references bookify_events (id) on delete cascade,
	title      text not null default '',
	status     text not null,
	price      numeric(10,2),
	capacity   integer not null default 0,
	created_at timestamptz,
	updated_at timestamptz
);

comment on table bookify_tiers is
	'One row per bookify_tier post. price is per ticket, which is the one way a ticket prices differently from a session.';
comment on column bookify_tiers.capacity is '0 means no ceiling of its own.';

-- ---------------------------------------------------------------------------
-- Bookings.
-- ---------------------------------------------------------------------------
create table if not exists bookify_bookings (
	id                bigint primary key,
	reference         text not null default '',
	status            text not null,
	item_kind         text not null,
	item_label        text not null default '',
	service_id        bigint references bookify_services (id) on delete set null,
	event_id          bigint references bookify_events (id) on delete set null,
	tier_id           bigint references bookify_tiers (id) on delete set null,
	customer_name     text not null default '',
	customer_email    text not null default '',
	customer_phone    text,
	customer_user_id  bigint,
	booking_date      date,
	booking_time      time,
	party_size        integer,
	payment_status    text,
	payment_amount    numeric(10,2),
	payment_due_at    timestamptz,
	payment_reference text,
	payment_note      text,
	previous_slot     text,
	created_at        timestamptz,
	updated_at        timestamptz
);

comment on table bookify_bookings is
	'One row per bookify_booking post. The cancel and manage tokens are deliberately absent — see the note at the top of this file.';
comment on column bookify_bookings.status is
	'awaiting_payment, pending, confirmed, expired or cancelled.';
comment on column bookify_bookings.item_kind is
	'ticket or session. A slug, not the translated word the admin shows.';
comment on column bookify_bookings.item_label is
	'The name of what was booked, kept for reading. The ids are the authoritative answer.';
comment on column bookify_bookings.service_id is
	'Null when the session has been deleted outright. WordPress does not clean the meta up, and a dangling id cannot be a foreign key.';
comment on column bookify_bookings.payment_amount is
	'The amount decided when the booking was made, not today''s price of the session.';
comment on column bookify_bookings.payment_due_at is
	'When an unpaid booking stops holding its place. Null when no deadline applies.';
comment on column bookify_bookings.payment_reference is
	'The Stripe object that paid this booking. An identifier, not a reusable instrument — nothing here can be used to charge anything.';
comment on column bookify_bookings.previous_slot is
	'The date and time a moved booking was given up, as stored by the plugin.';

-- ---------------------------------------------------------------------------
-- Customers: the WordPress accounts.
-- ---------------------------------------------------------------------------
create table if not exists bookify_customers (
	id            bigint primary key,
	user_login    text not null default '',
	display_name  text not null default '',
	email         text not null default '',
	roles         text not null default '',
	registered_at timestamptz
);

comment on table bookify_customers is
	'One row per WordPress user. A named column list and not a copy of wp_users: that table also holds user_pass, and wp_usermeta holds session_tokens, and neither may leave the site.';
comment on column bookify_customers.roles is
	'Comma-separated WordPress role keys, for example subscriber. A customer account is always a subscriber and nothing more.';
comment on column bookify_customers.registered_at is
	'Converted on the way in: WordPress stores user_registered in the site-local timezone, not UTC.';

-- ---------------------------------------------------------------------------
-- Settings: one row, the whole configuration.
-- ---------------------------------------------------------------------------
create table if not exists bookify_settings (
	id                     integer primary key,
	site_name              text not null default '',
	site_url               text not null default '',
	timezone               text not null default '',
	business_name          text not null default '',
	business_street        text not null default '',
	business_locality      text not null default '',
	business_postcode      text not null default '',
	business_country       text not null default '',
	business_phone         text not null default '',
	business_email         text not null default '',
	weekdays               jsonb not null default '{}'::jsonb,
	slot_interval_minutes  integer,
	lead_hours             integer,
	move_hours             integer,
	days_ahead             integer,
	blocked_dates          jsonb not null default '[]'::jsonb,
	reminders_enabled      boolean not null default false,
	reminder_hours_before  integer,
	payment_currency       text,
	payment_expiry_minutes integer,
	stripe_configured      boolean not null default false,
	turnstile_configured   boolean not null default false,
	mail_transport         text,
	mail_from              text,
	snapshot_at            timestamptz
);

comment on table bookify_settings is
	'A single row (id = 1) holding the whole configuration. The Stripe secret key, the Stripe webhook secret and the Turnstile secret live in the options this is built from and are deliberately NOT here — only whether each is set, as a boolean.';
comment on column bookify_settings.weekdays is
	'Map of weekday number (0 = Sunday) to {"open", "from", "to"}: the hours the booking form generates its slots from.';
comment on column bookify_settings.blocked_dates is
	'Array of YYYY-MM-DD strings the diary refuses.';
comment on column bookify_settings.snapshot_at is
	'When this row was built — not when anything changed.';
comment on column bookify_settings.mail_from is
	'The sender line a customer sees. Not a secret: the API key stays in the environment.';

-- ---------------------------------------------------------------------------
-- Media: the image files themselves, as data rather than as files.
--
-- This table exists for one reason. `wp-content/uploads` is a directory on the web server, and on a
-- host without a persistent disk it is gone every time the container is replaced — the rows in
-- MariaDB survive, the photos they point at do not, and every page comes back with eight broken
-- images. Carrying the bytes here makes the copy genuinely complete: the catalogue, the bookings and
-- the pictures are all in one place that outlives any one machine.
--
-- ## Why a JSON object of base64, and not `bytea`
--
-- `bytea` is the smaller and the more correct type, and this is neither. It is `jsonb` because the
-- write path that already exists — one prepared statement per table, a PHP array bound as `?::jsonb`
-- — carries it unchanged, and because base64 survives a round trip through a pooler, a text-mode
-- proxy and a JSON encoder without a binary-unsafe step anywhere. A `bytea` column would need a
-- second binding rule, a decode on read, and would put escaping between the bytes and the database
-- for the sake of about a third of a megabyte. The photographs on this site total a little over a
-- megabyte; the allowance is 500 MB.
--
-- ## `files`
--
-- A JSON object of **relative path to base64 content**, and it holds every file WordPress generated
-- for the image, not just the one that was uploaded. That matters more than it looks: a page
-- references `photo-300x200.jpg` as often as `photo.jpg`, `srcset` names four of them at once, and
-- restoring only the original would leave every intermediate size as a 404. The keys are relative to
-- `wp-content/uploads`, which is the one detail that has to match between the two machines.
--
-- ## What is not here
--
-- `_wp_attachment_metadata` is not a column, and neither is anything else WordPress stores about an
-- attachment. Those are rows in MariaDB — which is where the mirror's `file`, `width` and `height`
-- come from — and this table is the content, not the catalogue of it.
-- ---------------------------------------------------------------------------
create table if not exists bookify_media (
	id          bigint primary key,
	file        text not null default '',
	title       text not null default '',
	alt         text not null default '',
	mime_type   text not null default '',
	width       integer,
	height      integer,
	total_bytes bigint not null default 0,
	files       jsonb not null default '{}'::jsonb,
	created_at  timestamptz,
	updated_at  timestamptz
);

comment on table bookify_media is
	'One row per image attachment, carrying the bytes of every size WordPress generated for it. Images only — an image is what a page needs to render, and this is not a general file store.';
comment on column bookify_media.id is
	'The WordPress attachment post id. Also the id a bookify_services or bookify_events thumbnail_id names.';
comment on column bookify_media.file is
	'The path of the uploaded file relative to wp-content/uploads, as WordPress stores it in _wp_attached_file.';
comment on column bookify_media.alt is
	'The alt text, which is the one piece of an image a reader of this table may actually want.';
comment on column bookify_media.total_bytes is
	'The sum of the decoded sizes of everything in files — not the size of the base64, which is about a third larger.';
comment on column bookify_media.files is
	'Relative path (to wp-content/uploads) to base64 content, for the uploaded file and every generated size. Empty only if nothing on disk could be read.';
comment on column bookify_media.width is
	'Pixel width of the original, as WordPress recorded it when the attachment was created.';

-- ---------------------------------------------------------------------------
-- Content: WordPress's own tables, carried a row at a time.
--
-- The seven tables above describe the booking domain. They say nothing about what the site *is* — the
-- pages, the Elementor layouts those pages are built from, the menus, the options holding the business
-- details, the user accounts. All of that lives in WordPress's own tables, and on a host with no Shell
-- there is no way to get it in: no dump, no import, so a fresh deploy comes up as an empty site. This
-- table is the way in, and it is what makes the free plan viable.
--
-- ## Why JSON, and not a typed column per WordPress column
--
-- The domain tables above are typed because those columns are *this plugin's* and change when it
-- changes. WordPress's tables are the opposite. `wp_postmeta` is `meta_id, post_id, meta_key,
-- meta_value` and has been for fifteen years, while `wp_posts` gains a column every few releases and
-- any plugin may add a table of its own at any moment. A typed mirror would need a migration on every
-- WordPress upgrade and would silently drop anything it had not been told about; one `jsonb` column
-- copies whatever is there, including a table this plugin has never heard of.
--
-- ## Why every value is a string
--
-- Because `wpdb` hands them over that way and MySQL accepts them back. The round trip is text → json →
-- text on both sides, so there is no type mapping to get wrong in either direction: a `bigint` arrives
-- as `"111"` and is coerced on the way in, a `datetime` as `"2026-09-13 11:19:00"`, and NULL stays
-- NULL. Measured, not assumed — `SELECT ID, post_parent FROM wp_posts` returns `string` for both.
--
-- ## Nothing here is read back for display
--
-- Like the rest of the mirror, WordPress runs on MariaDB and this is a copy. It is read by
-- `wp bookify-supabase content restore`, on a machine that has none of it.
-- ---------------------------------------------------------------------------
create table if not exists bookify_content (
	table_name text not null,
	row_id     text not null,
	data       jsonb not null,
	updated_at timestamptz,
	primary key (table_name, row_id)
);

comment on table bookify_content is
	'One row per row of WordPress''s own tables — pages, post meta, options, users, terms — so that a host with no dump and no Shell can rebuild the whole site from Supabase. Four things are deliberately left out: password hashes, session tokens, transients and the cron schedule.';
comment on column bookify_content.table_name is
	'The WordPress table with its prefix, e.g. wp_posts. Not a foreign key: the tables it names exist in MariaDB, not here.';
comment on column bookify_content.row_id is
	'The primary key of the WordPress row, as text. Where a table has a multi-column key they are joined with a colon — wp_term_relationships has no single-column key.';
comment on column bookify_content.data is
	'The entire row, column name to value, every value a string. MySQL sends strings and accepts them back, so no type has to be guessed in either direction.';
comment on column bookify_content.updated_at is
	'When this row was last published to Supabase — not when the WordPress row changed.';

-- ---------------------------------------------------------------------------
-- Columns added after the first version of this file. `create table if not
-- exists` is a no-op on a database that already has the table, so a column
-- added to a block above reaches existing projects only through a statement
-- like these.
--
-- The comments belong here too, and not beside the columns they describe: a
-- `comment on column` naming a column that does not exist yet is an error, and
-- these run top to bottom in one transaction, so putting the comment above the
-- statement that creates the column would roll the whole file back.
-- ---------------------------------------------------------------------------
alter table bookify_services add column if not exists thumbnail_id bigint;
alter table bookify_events   add column if not exists thumbnail_id bigint;

comment on column bookify_services.thumbnail_id is
	'The featured image: a bookify_media id, and a WordPress attachment id. Deliberately not a foreign key — a photo too large to carry is still a session''s photo, so the id is published whether or not the row it names exists.';
comment on column bookify_events.thumbnail_id is
	'The featured image, of the same kind as bookify_services.thumbnail_id.';

-- ---------------------------------------------------------------------------
-- Indexes. Chosen for the questions a mirror exists to answer: what is booked
-- when, for which session or event, and in what state.
-- ---------------------------------------------------------------------------
create index if not exists bookify_bookings_when_idx    on bookify_bookings (booking_date, booking_time);
create index if not exists bookify_bookings_status_idx  on bookify_bookings (status);
create index if not exists bookify_bookings_service_idx on bookify_bookings (service_id);
create index if not exists bookify_bookings_event_idx   on bookify_bookings (event_id);
create index if not exists bookify_bookings_tier_idx    on bookify_bookings (tier_id);
create index if not exists bookify_bookings_email_idx   on bookify_bookings (lower(customer_email));
create index if not exists bookify_bookings_created_idx on bookify_bookings (created_at desc);
create index if not exists bookify_tiers_event_idx      on bookify_tiers (event_id);
create index if not exists bookify_events_date_idx      on bookify_events (event_date);
create index if not exists bookify_services_status_idx  on bookify_services (status);create index if not exists bookify_customers_email_idx on bookify_customers (lower(email));
create index if not exists bookify_content_table_idx    on bookify_content (table_name);
-- ---------------------------------------------------------------------------
-- Row level security: on, with no policies, so only the service_role key
-- (which bypasses RLS) can read or write. Nothing leaks through the
-- auto-generated REST API.
-- ---------------------------------------------------------------------------
alter table bookify_services  enable row level security;
alter table bookify_events    enable row level security;
alter table bookify_tiers     enable row level security;
alter table bookify_bookings  enable row level security;
alter table bookify_customers enable row level security;
alter table bookify_settings  enable row level security;
alter table bookify_media     enable row level security;
alter table bookify_content   enable row level security;

-- Belt and braces, and written as a guard because `anon`/`authenticated` are Supabase's roles and do
-- not exist on a plain Postgres — where this file should still apply cleanly.
do $$
begin
	if exists ( select 1 from pg_roles where rolname = 'anon' ) then
		execute 'revoke all on bookify_services, bookify_events, bookify_tiers, bookify_bookings, bookify_customers, bookify_settings, bookify_media, bookify_content from anon, authenticated';
	end if;
end $$;

commit;
