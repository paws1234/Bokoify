# bookify

A local WordPress site managed by `wpdev`, from a reusable kit at `/home/adminpaws/Desktop/dev/wp-kit`. Read this
before doing anything: it covers how to run commands, which MCP server does what, and the
rules that are easy to get wrong.

- Site: http://localhost:8890 — admin credentials are in `.env` (admin / password by default)
- Theme: `wp-content/themes/bookify-theme` — this project's own code
- `wp-agent-bridge` is **not** in this repo: it is mounted from the kit into
  `wp-content/plugins/`, so one edit affects every project

## Running commands

`wpdev` may not be on PATH for a non-login shell, so use the absolute path.

| Command | Purpose |
| --- | --- |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev status` | container status |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev up` / `down` | start / stop (data kept) |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev wp <args>` | WP-CLI, e.g. `wp post list --post_type=portfolio` |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev shell` | shell inside the cli container |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev smoke` | end-to-end MCP test — run this to verify a change |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev sync` | regenerate the MCP configs and this file from `.env` |
| `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev logs` | follow the WordPress logs |

## The MCP servers

All three run against this same site.

| Server | Use it for | Tools |
| --- | --- | --- |
| `elementor` | Designing pages, composing layouts, styling, global classes and variables, widget schemas | ~20 `elementor-*` tools, e.g. `elementor-create-page`, `elementor-build-composition`, `elementor-manage-elements`, `elementor-update-page-settings`, `elementor-publish-document`, `elementor-get-page-structure` |
| `wordpress` | Custom post types and fields, content entries, diagnostics, deterministic layouts | Three meta-tools — `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability` — wrapping the abilities below |
| `playwright` | Seeing the rendered site: screenshots at desktop, tablet and mobile widths, responsive behaviour, confirming a layout actually renders | Browser tools — navigate, screenshot, click, evaluate |

Prefer `elementor` for anything about how a page looks. Prefer `wordpress` for content, fields,
diagnostics, and when output must be identical every time. Use `playwright` to look at the
result instead of assuming it — the `visual-testing` skill covers how.

## Skills

Load these when they apply:

- **`wordpress-best-practices`** — YAGNI, WordPress coding standards, security, and evidence
  over assertion. Read it before writing PHP, and before reporting a change as done.
- **`visual-testing`** — screenshotting and inspecting the site through the Playwright server,
  including the three viewport widths and the usual Elementor rendering failures.

## Abilities on the `wordpress` server

Call them through `mcp-adapter-execute-ability` with
`{"ability_name": "...", "parameters": {...}}`. Names are `wp-agent-bridge/<name>`:

| Ability | Does |
| --- | --- |
| `get-environment-info` | WordPress and Elementor versions, container vs section mode, what is misconfigured. Call this first when something looks wrong |
| `list-widget-schema` | Supported widgets, their settings shape, container controls, recipe arguments |
| `create-elementor-page` | Create a page, post or portfolio entry and populate it with elements |
| `get-page-structure` | Outline of a page's ids, types, widget types, and optionally settings |
| `replace-elements` | Overwrite the whole top-level tree |
| `add-container` | Insert a container at the root or inside another container |
| `update-widget` | Merge settings into one element by id — the cheap way to fix copy or colours |
| `set-page-settings` | Page-level Elementor settings |
| `render-layout-recipe` | Deterministic layouts: `hero`, `feature-grid-3`, `cta-band`, `two-column`. Omit `post_id` for a dry run that returns the element JSON |
| `clear-elementor-cache` | Regenerate Elementor's CSS if the front end looks unstyled |
| `list-post-types` | Public post types and their registered meta keys |
| `create-post` | Create an entry. Only meta already registered for that post type is written; anything else comes back in `meta_skipped` |

`portfolio` is registered by the plugin with `portfolio_client`, `portfolio_url` and
`portfolio_year`.

## Rules

1. **Declare new plugin and theme directories.** Only `wp-content/themes/bookify-theme`,
   `wp-content/uploads`, and the kit's plugin are mounted into the container. Creating
   `wp-content/plugins/my-thing/` by hand does nothing — the container never sees it. Use
   `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev add plugin <slug>` (or `add theme <slug>`), which creates the directory,
   adds the mount, and recreates the container.
2. **Never hand-write Elementor data.** Use the MCP tools or the abilities above. Writing
   `_elementor_data` with `update_post_meta()` leaves Elementor's generated CSS stale; every
   path here goes through Elementor's Document API, which handles versioning and the cache.
3. **Do not change `PROJECT_NAME` in `.env`.** It names the Docker volumes. Changing it points
   the site at an empty database.
4. **Verify with `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev smoke`** after changing the theme or the plugin. It runs
   ten checks through the real MCP endpoint and cleans up after itself.

## Things that look like errors but are not

- `wp db check` / `wp db export` fail: the `wordpress:` image has no `mysql` client. Use
  `/home/adminpaws/Desktop/dev/wp-kit/bin/wpdev wp option get siteurl` or any command that goes through PHP.
- `wp rewrite structure ... --hard` warns about regenerating `.htaccess`. Harmless: the image
  already ships a working `.htaccess`, which is why permalinks work.
- A Playwright tool fails to launch a browser. Chromium is not installed yet; run
  `npx playwright install chromium` once and retry.
