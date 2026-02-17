# Pragmatic Cookies Extension

Extension reference for the Cookies domain in the Pragmatic Web Toolkit ecosystem. This document is based on the standalone plugin README.

## Requirements

- Craft CMS `^5.0`
- PHP `>=8.2`

## Installation

1. Add the plugin to your Craft project and run `composer install`.
2. Install the plugin from the Craft Control Panel (Settings → Plugins).
3. Migrations will create the required database tables and seed default categories (Necessary, Analytics, Marketing, Preferences).

## Control Panel

Navigate to **Pragmatic → Cookies** in the CP sidebar. The plugin provides five tabs:

- **General** — Popup title, description, button labels, and cookie policy URL.
- **Categories** — Manage cookie categories. Each category has a name, handle, description, and required flag.
- **Cookies** — Define individual cookies with name, provider, description, duration, and category assignment.
- **Scanner** — Crawl your site to discover cookies set via HTTP headers.
- **Appearance** — Layout (bar/box/modal), position, colors, overlay, consent expiry, and logging.

## Frontend Usage

### Consent Popup

The popup is injected automatically on all frontend pages when **Auto Show Popup** is enabled (default). It appears on first visit and respects the configured layout and position.

No template code is needed for the popup to work.

### Reopen Preferences

Add a link anywhere in your templates to let visitors reopen the consent popup:

```twig
<a href="#" data-pragmatic-open-preferences>Cookie Settings</a>
```

### Cookie Table

Display a table of all defined cookies grouped by category (useful on cookie policy pages):

```twig
{{ pragmaticCookieTable() }}
```

### Server-side Consent Check

Check if a visitor has consented to a specific category:

```twig
{% if craft.pragmaticCookies.hasConsent('analytics') %}
  {# Visitor has accepted analytics cookies #}
{% endif %}
```

Or using the Twig function:

```twig
{% if pragmaticHasConsent('marketing') %}
  {# ... #}
{% endif %}
```

### Blocking Scripts Until Consent

Change `type` to `text/plain` and add a `data-cookie-category` attribute. The plugin's JavaScript will activate the script when the visitor consents to that category:

```html
<script type="text/plain" data-cookie-category="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>

<script type="text/plain" data-cookie-category="analytics">
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXX');
</script>
```

### JavaScript API

A global `PragmaticCookies` object is available on the frontend:

```js
// Check consent
PragmaticCookies.hasConsent('analytics'); // true/false

// Run callback when category is accepted (fires immediately if already consented)
PragmaticCookies.onConsent('analytics', function() {
  // Initialize analytics
});

// Open preferences popup programmatically
PragmaticCookies.openPreferences();

// Accept or reject all
PragmaticCookies.acceptAll();
PragmaticCookies.rejectAll();
```

### Variable API

Additional methods available via `craft.pragmaticCookies`:

```twig
{# All categories #}
{% for category in craft.pragmaticCookies.getCategories() %}
  {{ category.name }} ({{ category.handle }})
{% endfor %}

{# All cookies #}
{% for cookie in craft.pragmaticCookies.getCookies() %}
  {{ cookie.name }}
{% endfor %}

{# Cookies grouped by category #}
{% for group in craft.pragmaticCookies.getCookiesGroupedByCategory() %}
  {{ group.category.name }}
  {% for cookie in group.cookies %}
    {{ cookie.name }}
  {% endfor %}
{% endfor %}

{# Current consent state #}
{% set consent = craft.pragmaticCookies.getCurrentConsent() %}
```

## Cookie Scanner

The scanner crawls live entry URLs using cURL and detects cookies set via `Set-Cookie` HTTP headers. It runs as a Craft Queue job with progress tracking.

Limitations:
- Only detects HTTP cookies (headers). JavaScript-set cookies (e.g. `_ga`) must be added manually.
- URLs are discovered from entries with `status('live')` and a URI.

## Consent Storage

Consent is stored in a browser cookie named `pragmatic_cookies_consent` as URL-encoded JSON:

```
{"necessary":true,"analytics":false,"marketing":false,"preferences":true}
```

When **Log Consent** is enabled, consent records are also stored in the database with visitor ID, IP address, and user agent for compliance purposes.
