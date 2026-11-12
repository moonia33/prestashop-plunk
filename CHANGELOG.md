# Changelog

All notable changes to `plunkconnect` are documented here.

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
