# Pragmatic Analytics Extension

Extension reference for the Analytics domain in the Pragmatic Web Toolkit ecosystem. This document is based on the standalone plugin README.

## Features
- CP section labeled `Pragmatic` with subnavigation item: `Analytics`
- Analytics section entry point redirects to `General`
- Two CP tabs: `General` (`/pragmatic-analytics/general`) and `Opciones` (`/pragmatic-analytics/options`)
- Base Twig layout for Analytics pages: `pragmatic-analytics/_layout`
- Built-in tracking for total visits, unique users, and top pages (aggregated data only)
- Exclusions for logged-in users, bot user agents, and configured environments
- Optional GA4 script injection via `Measurement ID`
- Plugin registered as `pragmatic-analytics` for Craft CMS 5 projects

## Requirements
- Craft CMS `^5.0`
- PHP `>=8.2`

## Installation
1. Add the plugin to your Craft project and run `composer install`.
2. Install the plugin from the Craft Control Panel.
3. Run migrations when prompted.

## Usage
### CP
- Go to `Pragmatic > Analytics`.
- Use the **General** tab to view last 30 days metrics.
- Use the **Opciones** tab to configure tracking and GA4 injection.

## Project structure
```
src/
  PragmaticAnalytics.php
  controllers/
    DefaultController.php
  templates/
    _layout.twig
    general.twig
    options.twig
```

## Notes
- This repository currently provides the control panel structure and routing scaffold.
- Business logic, settings models, and persistence can be added incrementally on top of this base.
