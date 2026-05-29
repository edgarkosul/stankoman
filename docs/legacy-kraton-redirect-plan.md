# Legacy Kraton Redirect Plan

## Goal

Move selected product traffic from the abandoned static `kratonkuban.ru` site to the active Laravel `intertooler.ru` project, without breaking old pages that do not have a matching product.

If a request to `kratonkuban.ru` is for a known legacy product and that legacy product is matched to an `intertooler` product, return a redirect to the new product page. Otherwise, serve the original `kratonkuban.ru` page exactly as before.

## Known Production Paths

- Legacy site: `/var/www/kratonkuban.ru`
- Current Laravel app: `/var/www/intertooler/current`
- Current Laravel public root: `/var/www/intertooler/current/public`
- Current app domain: `intertooler.ru`
- Legacy domain: `kratonkuban.ru`, `www.kratonkuban.ru`

## Investigation Summary

The legacy site root contains many static PHP files.

- PHP files in root: about `11034`
- Product pages detected by `id="TovKart"` and `schema.org/Product`: about `10554`
- Product name was found in all detected product pages.
- SKU was found and non-empty in most product pages.
- Manufacturer was found in many product pages, but is often empty.

Example legacy file:

`/var/www/kratonkuban.ru/spiralny-val-helical-150mm-dlya-w0108-w0109d-w0106fl.php`

Parsed fields:

- Name: `Спиральный вал Helical 150мм для W0108, W0109D, W0106FL`
- SKU: `Helical 150мм`
- Manufacturer: `Warrior`

Important parsing detail: legacy files are encoded as `cp1251`, not UTF-8.

Direct slug matching is not useful. On production, only a handful of legacy product filenames matched current `products.slug`. Matching should be based mainly on SKU and product name.

## Core Decision

Do not parse legacy PHP files on user requests.

Parsing is a one-time or manually rerun import task because `kratonkuban.ru` is abandoned and will not receive product updates. Runtime redirect logic must use database lookups only.

## Data Model

Create a small table for parsed legacy products, for example `legacy_products`.

Suggested columns:

- `id`
- `source_site` string, default `kratonkuban.ru`
- `source_path` string, unique, for example `/spiralny-val-helical-150mm-dlya-w0108-w0109d-w0106fl.php`
- `name` string
- `sku` string nullable
- `manufacturer` string nullable
- `matched_product_id` foreign id nullable to `products.id`
- `match_strategy` string nullable, examples: `sku_exact`, `sku_normalized`, `name_exact`, `name_normalized`
- `redirect_enabled` boolean default false
- `created_at`
- `updated_at`

Recommended indexes:

- unique index on `source_site`, `source_path`
- index on `sku`
- index on `matched_product_id`
- optional index on `redirect_enabled`

The table does not need file hashes or sync metadata because the legacy site is treated as immutable.

## Import Command

Create an Artisan command, for example:

`php artisan legacy:kraton-import`

Responsibilities:

1. Scan `/var/www/kratonkuban.ru/*.php`.
2. Keep only files containing product markers:
   - `id="TovKart"`
   - `schema.org/Product`
3. Decode file contents from `cp1251` to UTF-8.
4. Extract:
   - `source_path` from filename
   - `name` from `<div itemprop="name">`
   - `sku` from the `Артикул:` block
   - `manufacturer` from the `Производитель:` block
5. Upsert rows by `source_site + source_path`.

This command should not create or update current `products`.

## Matching Command

Create a second Artisan command, for example:

`php artisan legacy:kraton-match`

Primary matching order:

1. Exact non-empty SKU match against `products.sku`.
2. Normalized SKU match.
3. Exact normalized name match against `products.name`.
4. Conservative soft name matching only if the result is unique enough.

Suggested normalization:

- trim
- lowercase with `mb_strtolower`
- collapse whitespace
- remove common punctuation differences for SKU/name comparisons

Redirect policy:

- Enable redirects automatically for high-confidence unique matches.
- Do not enable redirects for ambiguous matches.
- When in doubt, leave `redirect_enabled = false` so the old page continues to work.

Because `intertooler` continues to evolve, this command can be rerun later without reparsing the old site.

## Runtime Redirect Flow

Runtime should be a fast database lookup by old path.

Request:

`https://kratonkuban.ru/some-product.php`

Laravel resolver:

1. Receive the requested legacy path.
2. Find `legacy_products.source_site = kratonkuban.ru` and `source_path = /some-product.php`.
3. If there is a matched product and `redirect_enabled = true`, return redirect to:
   - `https://intertooler.ru/product/{product.slug}`
4. If no enabled match exists, return a "no redirect" response for nginx fallback.

The redirect should start as `302` during verification, then change to `301` after logs confirm correct behavior.

## Nginx Integration

Current `kratonkuban.ru` nginx config serves the legacy root directly:

- root: `/var/www/kratonkuban.ru`
- PHP via `/run/php/php8.4-fpm.sock`

To let Laravel decide about product redirects, nginx needs a small interception layer for top-level PHP requests.

Conceptual flow:

1. For `kratonkuban.ru/*.php`, call a Laravel resolver endpoint.
2. If Laravel returns a redirect, send it to the browser.
3. If Laravel says "no match", internally fall back to the original legacy PHP handler.

The exact nginx implementation should be tested carefully on production with `nginx -t` before reload.

Important: non-product pages and unmatched product pages must continue through the old legacy PHP flow.

## Laravel Endpoint

Add an internal route such as:

`/_legacy/kraton/resolve`

Expected input:

- `path=/some-product.php`

Expected outputs:

- Redirect response when a match exists and redirect is enabled.
- Not found or no-content response when there is no redirect, so nginx can fall back.

This endpoint should not be advertised in navigation or indexed. It is infrastructure glue.

## Tests

Minimum programmatic tests:

1. Import command parses a cp1251 fixture product page and stores name, SKU, manufacturer, and source path.
2. Import command ignores non-product pages.
3. Matching command links by exact SKU.
4. Matching command links by normalized name when SKU is empty.
5. Matching command does not enable redirect for ambiguous matches.
6. Resolver redirects when `redirect_enabled = true` and `matched_product_id` exists.
7. Resolver returns fallback/no-match response when there is no enabled match.

## Rollout Plan

1. Add migration, model, import command, match command, resolver route/controller, and tests.
2. Deploy Laravel changes without nginx interception.
3. Run import command on production.
4. Run match command on production.
5. Inspect counts and sample matches.
6. Enable nginx interception with temporary `302` redirects.
7. Watch access logs and redirect samples.
8. Switch successful redirects from `302` to `301`.

## Safety Rules

- Never remove old files from `/var/www/kratonkuban.ru`.
- Never make unmatched pages redirect.
- Avoid request-time file parsing.
- Avoid broad fuzzy matching that redirects ambiguous products.
- Keep fallback to the old site as the default behavior.
