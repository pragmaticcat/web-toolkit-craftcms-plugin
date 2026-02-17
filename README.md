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
- Domain CP pages exist and load.
- Frontend +18 and cookies injectors are implemented as baseline placeholders.
- Analytics tracking endpoint, cookies consent endpoint, and sitemap endpoint exist.

## Next parity tasks (feature-complete migration)
1. Move each old controller/service pair into domain modules and wire real actions.
2. Define fresh unified DB schemas (`pragmatic_toolkit_*`) and implement domain CRUD against them.
3. Port SEO field/variable rendering and translations filters fully.
4. Port MCP resource/query/tool services fully.
5. Replace placeholder frontend injectors with current production templates/assets.
6. Add full functional tests for route behavior and clean-install setup.
