# Changelog

## [1.0.0] – 2026-11-12

### 1 etapas – skeletas ir prenumeratos (pakeičia Newsman naujienlaiškiams)

- Modulio skeletas: PSR-4 `PlunkConnect\`, multistore konfigūracija (viskas per `id_shop`).
- Admin konfigūracija: Plunk API URL, `pk_`/`sk_` raktai (rodomi užmaskuoti), „Tikrinti ryšį“, webhook/cron/feed tokenai (generuojami, pergeneruojami), kalbų žemėlapis + fallback (`ru` niekada nesiunčiama), užsakymų būsenų žemėlapis, funkcijų jungikliai, outbox būsena ir paskutinės klaidos, „re-queue dead“.
- Outbox (`ps_plunk_outbox`): hook'ai tik rašo eilutę; sutraukimas per `dedupe_key`; `Idempotency-Key` (UUID) kiekvienai žinutei.
- Worker'is: `bin/console plunkconnect:outbox:process` + `GET /module/plunkconnect/cron?token=…` (iki 200 žinučių, ~50 s, ≤20 req/s, `Retry-After` gerbiamas, eksponentinis retry 1/5/15/60 min/6 h, 8 bandymai → dead, 4xx → dead, 409 idempotency → sėkmė). `sent` senesni nei 7 d. trinami partijomis.
- Prenumeratos PS → Plunk: `actionNewsletterRegistrationBefore/After` (svečio forma, įsk. atsisakymą per shutdown patikrą), `actionCustomerAccountAdd`, `actionObjectCustomerUpdateBefore/After` (newsletter 0↔1). Double opt-in režimas siunčia `newsletter.signup_requested` su `subscribed=false`; be DOI – `newsletter.subscribed`. Atsisakymas – `newsletter.unsubscribed` su `subscribed=false`. Visada `data.locale`, `consentSource`, `consentRequestedAt`, `consentIp`, `consentReferer`, `shopCode`.
- Webhook Plunk → PS: `POST /module/plunkconnect/webhook` su `Authorization: Bearer <webhook secret>` (Plunk WEBHOOK žingsnis HMAC nemoka — patikrinta `useplunk/plunk@next` `WorkflowExecutionService::executeWebhook`); papildomai priimamas ir HMAC (`X-Plunk-Timestamp` + `X-Plunk-Signature`, ±300 s). Atnaujina `ps_customer.newsletter` ir `ps_emailsubscription` (delete on unsubscribe / insert+activate on subscribe). Idempotentiška.
- `bin/console plunkconnect:subscribers:backfill --since=YYYY-MM-DD [--dry-run]` – upsert'ai be `subscribed` (esami kontaktai nekeičiami; nauji pagal Plunk nutylėjimą tampa subscribed).
- `bin/console plunkconnect:subscribers:reconcile` – naktinis skirtumų raportas (tik log'ina).
- GDPR (psgdpr): `actionDeleteGDPRCustomer` → `delete` outbox žinutė (lookup per `GET /contacts?search=` + `DELETE /contacts/:id`); `actionExportGDPRData` → kas siunčiama į Plunk.
- Kokybė: PHPStan level 6, php-cs-fixer (@Symfony), 34 PHPUnit testai, GitHub Actions CI.
