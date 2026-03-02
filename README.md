# Pragmatic Web Toolkit

Pragmatic Web Toolkit helps webmasters run the essential website operations from one place in Craft CMS.

Instead of stitching together multiple disconnected tools, you get a clear control panel to manage privacy, SEO, analytics, translations, favicon setup, age gate flows, and AI/MCP content tools.

## Why teams use it

- Faster setup for common website operations
- Better consistency across multi-site projects
- Fewer manual frontend tweaks for recurring tasks
- Centralized control panel focused on day-to-day management

## Main Features by Domain

### Analytics

- Built-in page and visitor tracking
- Optional consent-aware tracking behavior
- Optional GA4 script injection
- CP dashboards for overview, daily stats, and top pages

### Cookies

- Cookie popup and preferences management
- Cookie categories and cookie inventory management
- Site-specific popup texts for multi-site projects
- Frontend consent table rendering helpers

### Favicon

- Site-level favicon management from CP
- Support for `.ico`, SVG, Apple touch icon, mask icon, and manifest
- Theme and tile color controls
- Automatic favicon/meta tag output (plus manual helper when needed)

### SEO

- Site-level SEO options and metadata defaults
- Content-level SEO field workflows
- Sitemap controls and XML endpoint support
- Social metadata settings (Open Graph / X)

### Translations

- Static translation key management
- Entry translation workflows
- Translation export tools
- Multi-site language operations from one UI

### +18

- Configurable age gate popup
- Per-site underage redirect targets
- Cookie-based gate memory and behavior options
- Frontend injection with minimal template work

### MCP

- MCP-oriented sections and settings area in CP
- Base tools/resources/query service wiring
- Foundation for AI and assistant integrations

## Quick Start

Use this sequence to launch each domain safely and quickly.

### 1. Install and run migrations

1. Install the plugin in your Craft project.
2. Run Craft migrations.
3. Confirm `Web Toolkit` appears in CP.

### 2. Grant permissions

1. Open Craft user group permissions.
2. Enable the domains your team should manage.
3. Verify each user role can see only the required sections.

### 3. Launch domains (recommended order)

1. `Favicon`: set icon assets and colors per site.
2. `Cookies`: configure popup text, categories, and consent behavior.
3. `SEO`: set site defaults, then review content/sitemap settings.
4. `Analytics`: enable tracking rules and optional GA4 integration.
5. `Translations`: configure static and entry translation flows.
6. `+18`: enable/configure only if your project requires age gating.
7. `MCP`: configure only when your team uses AI/MCP workflows.

### 4. Add frontend snippets

Frontend domains now render only when you place their Twig helper in your templates.

```twig
{{ craft.pragmaticToolkit.faviconTags() }}
{{ craft.pragmaticToolkit.seoTags(entry, 'seo') }}
{{ craft.pragmaticToolkit.analyticsScripts() }}
{{ craft.pragmaticToolkit.cookiesPopup() }}
{{ craft.pragmaticToolkit.plus18Gate() }}
```

If you need the cookie policy table on a privacy page:

```twig
{{ craft.pragmaticToolkit.cookiesTable() }}
```

Admin enable/disable settings still apply. If a domain is disabled in CP, its helper returns no output.

### 5. Verify frontend behavior

1. Check page source for expected tags/scripts for active domains.
2. Validate output per site in multi-site environments.
3. Confirm consent-sensitive behaviors in production-like conditions.

## Development-only edition override

If you want to switch plugin plan/edition during local development without editing `project.yml`, set an env var in `.env`:

```dotenv
PWT_EDITION_OVERRIDE=free
```

Allowed values: `free`, `lite`, `pro`.

When set, this overrides the plugin edition at runtime. Remove it before release.

For a step-by-step setup checklist, see [Quick Start guide](docs/quick-start.md).

## Extending Pragmatic Web Toolkit

Need premium or project-specific workflows? Extend Toolkit through dedicated extension plugins.

- Start with the [Extensions index](extensions/README.md)
- Review available extension docs in [extensions/](extensions)
- Follow the [Extension Contract](docs/extension-contract.md) when building your own extension
