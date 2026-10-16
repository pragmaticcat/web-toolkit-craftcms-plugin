# Changelog

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
