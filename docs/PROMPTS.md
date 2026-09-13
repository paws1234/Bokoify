# Prompts

Two routes, and it is worth knowing which one you are on.

| Route | Where | Use for |
| --- | --- | --- |
| **Code** | VS Code, in this repo | PHP: post types, fields, templates, CSS, plugin logic |
| **Elementor (native)** | `elementor` MCP server | Building and restyling layouts; the richest tool set |
| **Content (bridge)** | `wordpress` MCP server | The portfolio post type, its meta, and diagnostics |
| **Recipes (bridge)** | `wordpress` MCP server | Deterministic named layouts when you want predictable output |

For layout work, prefer the **native Elementor MCP server**. Its 20 tools include
`elementor-create-page`, `elementor-build-composition`, `elementor-manage-elements`,
`elementor-get-page-structure`, `elementor-update-page-settings`, `elementor-publish-document`,
`elementor-get-widget-schema`, and `elementor-list-widget-schemas`. The bridge's layout
abilities exist as a smaller, deterministic alternative.

---

## 1. Custom PHP

Open or name a file in `wp-content/plugins/` or `wp-content/themes/<slug>-theme/` and ask:

> Add a `portfolio_service` taxonomy registered for the `portfolio` post type, hierarchical
> like categories, exposed in REST. Follow the existing style in the wp-agent-bridge plugin:
> namespaced class, an `init()` that registers hooks, docblocks on every method.

Why it works: the agent is editing real files that are bind-mounted into the container, so
`wpdev wp post-type list` reflects the change without a rebuild.

## 2. A landing page — native Elementor MCP

> Create a new Elementor landing page draft on my local site with a hero banner, a three-column
> feature grid, and a call-to-action button.

Expected tool sequence:

1. `elementor-list-widget-schemas` (or `elementor-get-widget-schema`) to learn valid settings
2. `elementor-create-page` to make the draft
3. `elementor-build-composition` and/or `elementor-manage-elements` to add the sections
4. `elementor-get-page-structure` to confirm the tree
5. `elementor-create-preview-link` so you can look at it

Follow-ups the same server handles:

> Restyle the hero: darker background, larger heading, and add a secondary outline button.

> Publish that page and give me the URL.

## 3. The same page — deterministic recipes on the bridge

When you want identical output rather than a model composing a layout:

> Call render-layout-recipe with recipe "hero", then "feature-grid-3", then "cta-band",
> appending each to the page you just created.

Recipe names and arguments are in `wp-agent-bridge/list-widget-schema`:

| Recipe | Arguments |
| --- | --- |
| `hero` | `heading`, `subheading`, `button_text`, `button_url`, `background_color`, `text_color` |
| `feature-grid-3` | `items` — array of `{ title, description, icon }`, max 3 |
| `cta-band` | `heading`, `subheading`, `button_text`, `button_url`, `background_color`, `text_color` |
| `two-column` | `left_heading`, `left_items`, `right_heading`, `right_body` |

Pass `post_id` to write immediately, or omit it for a dry run that returns the element JSON.

## 4. Content — portfolio entries

> Create three portfolio projects as drafts: "Northwind Rebrand" for client Northwind (2026),
> "Atlas Dashboard" for Atlas Labs (2025), and "Harbor Site" for Harbor Co (2025). Set the meta
> fields and give me the edit links.

This exercises `wp-agent-bridge/create-post`, which only writes meta keys that are actually
registered for the post type — anything else comes back in `meta_skipped` rather than being
silently stored.

## 5. Diagnostics

> Call get-environment-info and tell me what is misconfigured.

It reports the WordPress and Elementor versions, Elementor's layout mode (container vs section),
whether the Abilities API and MCP Adapter are present, whether application passwords are
available, whether the portfolio post type registered, and what the current user can do.

---

## Inspecting what is available

> Call mcp-adapter-discover-abilities and list the abilities whose name starts with
> `wp-agent-bridge/`.

> Call mcp-adapter-get-ability-info for `wp-agent-bridge/render-layout-recipe`.

From a terminal:

```bash
wpdev smoke                            # 10 checks, end to end through MCP
wpdev wp plugin list
wpdev wp post-type list --fields=name,public
```
