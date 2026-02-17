# Pragmatic Web Toolkit

Pragmatic Web Toolkit is the unified Craft CMS core for the Pragmatic plugin suite.

It brings your main capabilities into one coherent plugin, with a clean path to grow through extensions.

## Why this core exists

Instead of maintaining multiple disconnected plugins, Toolkit gives you:
- One installation and one CP navigation entry (`Pragmatic`)
- Shared architecture across domains
- Faster feature rollout through a Core + Extensions model

## Core + Extensions

### Core (this plugin)
The core includes the baseline functionality for:
1. Analytics
2. Cookies
3. MCP
4. SEO
5. Translations
6. +18

It also provides the shared infrastructure used by every domain:
- Unified CP routing/navigation (`pragmatic-toolkit/*`)
- Domain registry and extension hooks
- Shared settings and permission patterns

### Extensions (separate plugins)
Extensions are where advanced or premium workflows live.

Each extension is expected to:
- Depend on the Toolkit core
- Plug into core contracts/events
- Add domain-specific premium functionality without patching core internals

## Current status

The six baseline modules above are already ported and working in the unified core.

## Migration stance

This refactor is intentionally a hard break:
- No legacy compatibility layer
- Clean install / reinstall is the intended flow

## Extension docs

Extension references are available in `/extensions`:
- `extensions/pragmatic-analytics-extension.md`
- `extensions/pragmatic-cookies-extension.md`
- `extensions/pragmatic-mcp-extension.md`
- `extensions/pragmatic-seo-extension.md`
- `extensions/pragmatic-translations-extension.md`
- `extensions/pragmatic-plus18-extension.md`

See also: `extensions/README.md`
