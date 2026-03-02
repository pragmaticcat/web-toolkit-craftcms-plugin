# Pragmatic Web Toolkit Quick Start

This guide covers the minimum setup to get Pragmatic Web Toolkit working correctly in a Craft CMS project.

## 1. Install the plugin

Add and install the plugin in your Craft project (via Composer + CP/console install flow used in your project).

## 2. Run project migrations

Run Craft migrations so Toolkit tables are created, including domain tables (Cookies, SEO, Translations, Analytics, Favicon).

## 3. Confirm CP access and permissions

In Craft CP:
1. Go to user group permissions.
2. Enable the required `Pragmatic Web Toolkit` permissions per domain (for example `Manage SEO`, `Manage Cookies`, `Manage Favicon`).
3. Verify the `Web Toolkit` CP section is visible for target users.

## 4. Configure domain basics

Open `Web Toolkit` in CP and configure each active domain:

1. `Favicon`: set site-level favicon assets and colors.
2. `SEO`: review meta/site options and sitemap behavior.
3. `Cookies`: configure popup texts/settings and cookie categories.
4. `Analytics`: decide tracking/consent behavior and optional GA settings.
5. `Translations`, `+18`, `MCP`: configure only if used in your project.

## 5. Frontend integration check

Toolkit injects frontend HTML for domains that require it (for example cookies popup, +18 gate, favicon tags).

Add the frontend snippets you need in your site templates:

```twig
{{ craft.pragmaticToolkit.faviconTags() }}
{{ craft.pragmaticToolkit.seoTags(entry, 'seo') }}
{{ craft.pragmaticToolkit.analyticsScripts() }}
{{ craft.pragmaticToolkit.cookiesPopup() }}
{{ craft.pragmaticToolkit.plus18Gate() }}
```

For a cookie policy page:

```twig
{{ craft.pragmaticToolkit.cookiesTable() }}
```

If a domain is disabled in admin, its helper renders nothing.

## 6. Validate in browser

1. Open a frontend page source and confirm expected tags/scripts appear.
2. Confirm favicon tags are inside `<head>` (auto-injected or manual).
3. Confirm cookies/analytics behavior matches your consent policy.
4. Check multi-site outputs by switching site context in CP and frontend.

## 7. Optional: add extensions

If you use premium/extended features, follow:
- [Extensions index](../extensions/README.md)
- [Extension Contract](extension-contract.md)

## Troubleshooting checklist

1. Plugin installed but no CP section: check user permissions and plugin install state.
2. Settings not saving: verify migrations ran and DB user has write permissions.
3. Frontend output missing: verify the domain is enabled in plugin settings and configured for the active site.
4. Multi-site mismatch: confirm you are editing the intended site in CP (site switcher).
