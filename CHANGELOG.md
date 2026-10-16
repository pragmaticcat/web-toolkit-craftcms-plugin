# Changelog

## 1.6.0

- Render the new redirect form when its rule has no ID.
- Make analytics tracking POST-only with a 204 response and noindex headers.
- Mark registered front-end action responses as noindex and disallow toolkit paths in generated robots.txt.
- Add redirect form rendering and front-end route contract tests.

## 1.5.9

- Exclude Craft's internal path parameter from preserved redirect query strings.
- Prevent same-host, same-path redirect loops and record the request as a 404.
- Add query preservation and loop-guard regression coverage.

## 1.5.8

- Resolve element-based redirect destinations using Craft's base Element class.
- Disable redirect rules when an entry URI becomes the rule source, preventing self-redirects after URI reversions.
- Add redirect matcher and response coverage for entry URI changes and reversions.
- Run PHPStan analysis in CI.

## 1.5.7

- Import the plugin class in RedirectService to prevent runtime failures in 404 logging, pruning, and entry saves.
- Register automatic entry redirect hooks for both web and console application requests.
- Add PHPStan level 1 to development tooling.

## 1.5.6

- End the request after preparing a redirect or 410 response so the error handler does not replace it with a 404.

## 1.5.5

- Fix redirect CSV imports and rule saves when no reverse rule exists.
- Persist each redirect hit counter update after the request.
- Support the documented `--dry-run` option for redirect CSV imports.

## 1.5.4

- Match redirect rules and record 404s with the full request path, including site prefixes.
- Create automatic redirects after each site's URI save, including propagated URI changes.
- Expand redirect regex capture groups without corrupting double-digit references.
- Correct the cookie consent save fallback route and require POST requests.
- Exclude temporary translation exports from source and Composer archives.
