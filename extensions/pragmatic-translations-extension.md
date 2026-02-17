# Pragmatic Translations Extension

Extension reference for the Translations domain in the Pragmatic Web Toolkit ecosystem. This document is based on the standalone plugin README.

## Features
- CP section with two-level navigation: `Pragmatic > Translations`
- Subpages like Craft Utilities: **Entradas**, **Importar/exportar**, **Grupos**, **Opciones**
- Manage translation keys and groups (default group: `site`)
- Per-language translation values (Craft multisite)
- Search, tabs by group, and pagination (50/100/250)
- Bulk editing and delete from the grid
- Export to CSV, JSON, and PHP (ZIP with one file per locale + group)
- Import from CSV, JSON, and PHP (ZIP)
- Twig helper: `craft.pragmaticTranslations.t()`
- Twig filter override: `{{ 'hero.title'|t }}`
- Auto-creates missing keys on first access
- Autotranslate from another site for PlainText/CKEditor fields (Google Translate v3)

## Requirements
- Craft CMS `^5.0`
- PHP `>=8.2`

## Installation
1. Add the plugin to your Craft project and run `composer install`.
2. Install the plugin from the Craft Control Panel.
3. Run migrations when prompted.

## Usage
### Twig
```twig
{{ craft.pragmaticTranslations.t('hero.title') }}
{{ craft.pragmaticTranslations.t('hero.greeting', { name: 'Oriol' }) }}
{{ 'hero.title'|t }}
{{ 'hero.title'|t('site') }}
{{ 'hero.title'|t('profesionales') }}
```

### CP
- Go to `Pragmatic > Translations`.
- Use **Entradas** to edit translations.
- Use **Importar/exportar** for CSV/JSON/PHP ZIP.
- Use **Grupos** to add/rename/delete groups (except `site`).

## Export formats
### CSV
Header:
```
key,group,<language1>,<language2>,...
```

### JSON
```json
{
  "hero.title": {
    "group": "home",
    "translations": {
      "en-US": "Welcome",
      "es-ES": "Bienvenido"
    }
  }
}
```

### PHP (ZIP)
The export produces a ZIP with files in:
```
translations/<locale>/<group>.php
```
Each file returns a key/value array compatible with Craft i18n conventions.

## Fallback behavior
- If a translation is missing for the current site, the plugin returns the primary site value.
- If the primary site also has no value, it returns the key.

## Permissions
- `pragmatic-translations:manage`
- `pragmatic-translations:export`

## Autotranslate (Google Translate v3)
Autotranslate appears in the field 3-dot menu for **PlainText** and **CKEditor** fields:
- Select a source site
- The value is translated into the current site language
- The field is populated (no auto-save)

### Config
Create `config/pragmatic-translations.php` in your Craft project:
```php
<?php

return [
    'googleProjectId' => 'your-gcp-project-id',
    'googleLocation' => 'global',
    'googleApiKeyEnv' => 'GOOGLE_TRANSLATE_API_KEY',
    'languageMap' => [
        'es-ES' => 'es',
        'ca-ES' => 'ca',
        'en-US' => 'en',
        'en-GB' => 'en',
    ],
];
```

And set the env var:
```
GOOGLE_TRANSLATE_API_KEY=...
```
