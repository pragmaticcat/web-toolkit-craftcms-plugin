# Pragmatic Web Toolkit (Unified Core)

This plugin is the new unified core for:
- Pragmatic Analytics
- Pragmatic Cookies
- Pragmatic MCP
- Pragmatic SEO
- Pragmatic Translations
- Pragmatic +18

## Implemented in this refactor
- New Craft plugin package and handle: `pragmatic-web-toolkit`
- Unified plugin bootstrap and CP section
- Shared feature provider contract (`FeatureProviderInterface`)
- Shared extension registration event for premium plugins
- Core domain registration for analytics/cookies/mcp/seo/translations/plus18
- Unified CP routes under `pragmatic-toolkit/*`
- Unified site routes for tracking/consent/sitemap entrypoints
- Unified CP navigation group with domain sub-navigation
- Unified Twig variable root: `craft.pragmaticToolkit`
- Install migration for toolkit migration log table
- Premium extension integration docs + extension stub

## Current baseline status
- Architecture, routing, extension contracts, and clean-install migration plumbing are in place.
- Cookies domain is now implemented end-to-end:
  - fresh unified tables (`pragmatic_toolkit_cookies_*`)
  - CP pages/actions for General, Appearance, Categories, and Cookies CRUD
  - frontend consent popup injection + consent logging endpoint
  - Twig helpers for cookie table + consent checks

## Next parity tasks (feature-complete migration)
1. Port SEO end-to-end on fresh unified schema.
2. Port Translations end-to-end on fresh unified schema.
3. Port Analytics end-to-end on fresh unified schema.
4. Port MCP end-to-end on fresh unified schema.
5. Port +18 end-to-end on fresh unified schema.
6. Add full functional tests for route behavior and clean-install setup.
