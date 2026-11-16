# Changelog

All notable changes to `plunkconnect` are documented here.

## [1.6.0] - 2026-11-16

### Added
- Anonymous product views (the last 20, buffered in the PS cookie) are now also flushed after a signup through a Plunk form, not only after PS login, registration or newsletter signup. The Plunk embed dispatches `plunk:form-submitted`; for an anonymous visitor with buffered views `displayHeader` adds a listener that posts the email to the new `identify` front controller. It requires consent, `FEAT_VIEWS` and a session nonce that expires after 48 h and works once (cleared from the cookie and recorded as the outbox `dedupe_key`, so a replayed cookie is rejected). It sends one `product.viewed` with `recentlyViewed`. The typed email is not verified, so the browser is not identified for later tracking.
- `upgrade-1.6.0`: version bump only.

## [1.5.0] - 2026-11-15

### Added
- Contact form protection for the `contactform` module (`FEAT_CONTACT_TURNSTILE`, off by default). The Turnstile widget renders through `displayGDPRConsent`, only inside the contact form. `actionFrontControllerInitAfter` checks the token with Siteverify before contactform handles the POST: `success`, action `contact_form` and hostname against `TURNSTILE_HOSTNAMES` (empty = the shop's own domains). If Siteverify cannot be reached, the message is rejected (fail closed). It also limits each IP to 3 messages per 10 min and each email to 5 per day, per shop. A rejected submission is not stored or mailed; the visitor sees a localized error and keeps the typed text.
- Site key, write-only secret and hostnames are stored per shop, so each shop or client uses its own widget.
- `upgrade-1.5.0`: registers both hooks.

## [1.4.1] - 2026-11-15

### Fixed
- `order.placed` and `order.<status>` events were never queued: the coupon lookup in `OrderMapper::map()` added `LIMIT 1` to a `Db::getValue()` query, which appends its own `LIMIT 1`, so MariaDB rejected the SQL and the hook swallowed the error. The same fix applies to the coupon reuse lookup in `CouponService`.
- `OrderMapper::map()` no longer fails when the context has no currency (payment webhooks, CLI); it falls back to 2 decimals.
- Errors in order hooks are now written to the PrestaShop log (Advanced Parameters → Logs) instead of being dropped silently.

## [1.4.0] - 2026-11-15

### Added
- Plunk signup forms: with `FEAT_FORMS` on (off by default), `displayHeader` prints `window.PlunkForms` (`key`, `locale`, `subscribed`, `customer`) and the Plunk `forms/embed.js` script. Forms and their display rules are managed in Plunk.
- `upgrade-1.4.0`: registers `displayHeader`.

## [1.3.0] - 2026-11-14

### Changed
- Catalog ids (Plunk `externalId`, event `productId`/`recentlyViewed`, recommendations, `/products`) are now the SKU: the sellable unit's `reference` when filled and unique across all sellable units (case/space-insensitive), otherwise `PS-<id_product>[-<id_product_attribute>]`. Parents of combinations always use `PS-<id_product>`. The same SKU-based id lets a shop move to another platform without remapping Plunk data.
- `/products` and `/recommendations` accept catalog ids (old numeric ids still resolve).

### Added
- `ps_plunk_catalog_id` stores the id last sent per unit; when a reference is fixed the next upsert deletes the old Plunk row (`catalog.delete`) and creates the new one.
- `upgrade-1.3.0`: creates the table. Delete old-format ids from Plunk and run `plunkconnect:catalog:sync --full` after upgrading.

## [1.2.0] - 2026-11-15

### Fixed (production, baltijoszvynas.lt)

- Webhook returned 500: `Db::getValue('SHOW TABLES LIKE …')` appends `LIMIT 1` and MariaDB answers 1064. All four sites now use `executeS()` (subscription apply/guests, back-in-stock table check, reconcile command) with a regression test.
- Double opt-in repeated signup no longer unsubscribes existing subscribers: the outbox worker looks the contact up in Plunk (`GET /contacts?search=`) before a `newsletter.signup_requested` and drops `subscribed:false` when the contact is already subscribed (protects the Newsman import).
- `applyWebhook` now returns `matched` (SELECT COUNT) alongside affected rows so an unchanged value doesn't look like a missing contact.


- BO fatal `Class "PlunkConnect\Command\ProcessOutboxCommand" cannot be found`: the Symfony container compiles module commands before the module autoloader loads, so `config/services.yml` now ships as `services.yml.dist` and is materialised by `install()`/`upgrade` only when `vendor/autoload.php` exists. Release zips must be built with `composer install --no-dev -o`.
- Outbox rows waited ~1 h: `next_attempt_at`/`date_upd`/`date_exp`/`date_add` are written with PHP time (Europe/Vilnius) but compared against MySQL `NOW()` (Europe/Berlin). All comparisons now use PHP `date()`; regression test `testNoNowInSqlSources` guards the rule.
- `available` was `true` for out-of-stock products when `out_of_stock = 2`: mode 2 means "shop default", it now correctly resolves `PS_ORDER_OUT_OF_STOCK`.
- All catalog images dropped (`imageUrl: null`): CLI `getImageLink()` returns `http://`/`//host` URLs; they are now normalised to `https://` when `PS_SSL_ENABLED` (`CatalogMapper::httpsUrl`).
- `categories` included the Home category: `PS_ROOT_CATEGORY` and `PS_HOME_CATEGORY` are now excluded.
- `categoryPath` in product events carried "Šakninis"/"Pagrindinis": the same Root/Home exclusion is applied.
- Event `recentlyViewed` now carries Plunk id strings (`"<id>"` / `"<id>-<attr>"`) instead of ints; `product.viewed` and the anonymous-view buffer now record the selected combination (`id_product_attribute`) so `productId` is `"<id>-<attr>"`, matching the catalog ids.

### Added
- Catalog sync to Plunk (`/catalog/products`, spec 5.4): `CatalogMapper` builds full product documents (canonical string ids `<id_product>` / `<id_product>-<id_product_attribute>`, `parentId`, sku, name, sanitized description <=1000 chars, HTTPS url with `#/<attr>` anchor, cover + up to 5 images, price/salePrice VAT-incl., `available`, `quantity`, brand, LT categories, `translations` for lv/pl/et/en skipping id_lang 5 and never sending `ru`).
- Outbox endpoints `catalog.upsert` (PUT batches <=500 items incl. combinations), `catalog.patch` (dedupe per id, 60 s coalescing, grouped PATCH <=1000, `notFound` ids re-queued as upserts) and `catalog.delete` (POST ids <=1000).
- Hooks: product add/update/delete, combination add/update/delete, specific-price add/update/delete, `actionUpdateQuantity`/`StockAvailable` -> patch; delete ids collected in `actionObjectProductDeleteBefore` so combination ids survive the product row.
- `plunkconnect:catalog:sync` command (`--full`, `--from=<id>`, incremental via `date_upd` + `specific_price` activation/expiry windows); cron integration: incremental every ~15 min, full sync nightly 03:00-05:00 max once per 20 h.
- Admin: `FEAT_CATALOG_SYNC` toggle, "Full sync now" button (queues outbox rows only) and last-sync diagnostics.
- `productId` string field (`<id>`/`<id>-<attr>`) on product payloads used by events, feeds and recommendations.

## [1.0.0] - 2026-11-12

### Added
- Module skeleton compatible with PrestaShop 8.1 - 9.x (PHP >= 8.1), multistore-aware configuration.
- Outbox table (`PREFIX_plunk_outbox`) with dedupe collapse, exponential retry (1m/5m/15m/1h/6h, dead after 8 attempts), locked worker claiming and 7-day sent-row cleanup.
- Outbox worker: `php bin/console plunkconnect:outbox:process`, cron front controller and `actionCronJob` hook.
- Newsletter sync PS -> Plunk: `newsletter.signup_requested` (double opt-in) / `newsletter.subscribed` / `newsletter.unsubscribed` via `ps_emailsubscription`, registration, account-update and checkout hooks.
- Plunk -> PS webhook (`/module/plunkconnect/webhook`) with `X-Plunk-Timestamp` + `X-Plunk-Signature` HMAC (300 s window) or static Bearer fallback; updates `ps_customer.newsletter` and `ps_emailsubscription.active` idempotently.
- Console commands `plunkconnect:subscribers:backfill` (`--since`, `--dry-run`) and `plunkconnect:subscribers:reconcile` (report-only).
- Behavioural events: `customer.registered`, `product.viewed` (30-min debounce), `category.viewed`, `cart.updated` (outbox collapse), `order.placed` + `order.{paid,shipped,delivered,cancelled,refunded}` via order-state mapping, `product.back_in_stock` (ps_emailalerts).
- Visitor identification: logged-in customer, guest-checkout email, `?pk_e=` email-link token -> `plunk_id` cookie (HMAC, 365 d, HttpOnly/Secure/SameSite=Lax). Anonymous views buffered and flushed as one event.
- Marketing-consent cookie gate for view tracking.
- Signed cart-restore links (`/module/plunkconnect/restorecart`, 30 d, cart secure_key HMAC).
- Catalog feed: `GET /module/plunkconnect/feed/{products,categories}` (cursor pagination, `updated_since`), `GET /module/plunkconnect/products` (live price/stock, max 50 ids), `GET /module/plunkconnect/recommendations` (accessories + category best sellers, in-stock only).
- Coupon endpoint `POST /module/plunkconnect/coupon` (HMAC) creating single-use CartRules; code persisted on the contact as `lastCouponCode`/`lastCouponValidUntil`.
- GDPR: `actionDeleteGDPRCustomer` queues contact lookup+delete; `actionExportGDPRData` reports exported fields.
- Admin configuration: API keys (masked), token regeneration, locale map (never `ru`), order-state map, feature toggles, outbox status panel with last errors, process-now and retry-dead actions.
- PHPUnit tests (HMAC, email masking, outbox retry/uuid, locale allow-list), phpstan + php-cs-fixer configs.
