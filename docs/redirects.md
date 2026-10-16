# Redirects domain

Redirects runs when a Craft web request raises an HTTP 404. It matches the full request path from `getFullPath()`, including site path prefixes, and preserves the query string by default. The 404 log stores that same full path after normalizing its query string away, so “Create redirect” uses a source that matches. Craft's `config/redirects.php` continues to be handled by Craft's error handler.

## Rule fields

Each rule can apply to all sites or one site. Exact rules are looked up by an indexed source URI. Pattern rules use Craft-style path tokens, for example `news/<slug>` → `actualitat/<slug>`. Regex rules use capture groups and destinations may refer to them with `$1`, `$2`, and so on. Matching strips outer slashes, ignores query strings, decodes URL-encoded paths, and compares case-insensitively.

Supported response codes are 301, 302, 307, and 410. A 410 rule returns “Gone” without a destination. A destination can be a relative path or an absolute HTTP(S) URL. Setting `destinationElementId` resolves the element's current URL for the request site, so future slug changes do not create redirect chains.

Direct self-redirects and simple two-rule loops are rejected when rules are saved. The control panel warns when a saved rule leads through another exact redirect.

Automatic redirects capture each existing entry URI before its site save and create a 301 to the element after that site save completes. This includes URI changes propagated to other sites; drafts, revisions, provisional drafts, and unchanged URIs are skipped.

## 404 log

Unhandled 404s are deduplicated by path and site. The log stores hit counts, first and last seen timestamps, referrer, user agent, and a handled flag. Logging can be disabled, ignored paths can be configured, and rows older than the configured retention are pruned by the daily hook or manually with `pragmatic-web-toolkit/redirects/prune-404s`.

## CSV

CSV headers are `source,destination,statusCode` with optional `siteHandle,matchType` columns. Site handles scope rules to a site; omit the value for all sites. `matchType` accepts `exact`, `pattern`, or `regex`. A dry run reports each row as create, update, or error without writing changes.

```csv
source,destination,statusCode,siteHandle,matchType
old-page,new-page,301,,exact
news/<slug>,actualitat/<slug>,301,ca,pattern
^legacy/(.*)$,/new/$1,302,,regex
gone-page,,410,,exact
```

Commands:

```sh
php craft pragmatic-web-toolkit/redirects/import path/to/redirects.csv --dry-run=1
php craft pragmatic-web-toolkit/redirects/prune-404s
```
