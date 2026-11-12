# Plunk Connect (`plunkconnect`)

PrestaShop modulis, jungiantis parduotuvę su self-hosted Plunk (pakeičia `newsmanapp` + `newsmancoupons`).
PrestaShop module connecting a shop to a self-hosted Plunk instance (replaces `newsmanapp` + `newsmancoupons`).

- **Suderinamumas / Compatibility:** PrestaShop **8.1 – 9.x**, PHP **>= 8.1**, multistore (konfigūracija per parduotuvę / per-shop configuration).
- **Be BZ specifikos / Shop-agnostic:** viskas konfigūruojama per admin'ą / everything is configured in the back office.

---

## LT

### Diegimas

1. Nukopijuokite `plunkconnect/` į `modules/` (arba zip→Moduliai→Įkelti modulį). Composer'io nereikia – yra integruotas PSR-4 autoloader'is (`autoload.php`); jei paleisite `composer install`, bus naudojamas `vendor/autoload.php`.
2. Įdiekite per *Moduliai → Module Manager* arba `php bin/console prestashop:module install plunkconnect`.
3. Modulis gali veikti kartu su `newsmanapp` perėjimo laikotarpiui – konfliktų nėra.

### Konfigūracija (admin)

| Laukas | Aprašymas |
|---|---|
| Plunk API URL | pvz. `https://api.mk.example.lt` |
| Public key `pk_…` | `POST /v1/track` |
| Secret key `sk_…` | `/contacts`, lookup, delete |
| Webhook secret | HMAC pasirašymui (webhook, coupon, restorecart, `pk_e`) |
| Feed token | katalogo feed ir gyvų produktų endpointai |
| Cron token | `/module/plunkconnect/cron?token=…` |
| Kalbų žemėlapis | `id_lang` → `lt/lv/et/en/pl`; `ru` → fallback (`ru` niekada tikslas negali būti) |
| Būsenų žemėlapis | `OrderState` → `paid/shipped/delivered/cancelled/refunded` |
| Funkcijų jungikliai | prenumeratos, double opt-in, peržiūros, krepšelis, užsakymai, feed, kuponai, back-in-stock, `?pk_e=` |
| `consentSource`, `shopCode` | siunčiami kiekviename payload'e |

### Cron (privaloma)

Jokio sinchroninio HTTP pirkėjo užklausoje – viskas eina per `PREFIX_plunk_outbox` ir worker'į:

```cron
* * * * * php /path/bin/console plunkconnect:outbox:process
```

Atsarginis variantas be CLI: `GET /module/plunkconnect/cron?token=<cron token>` kas minutę.

### Endpointai

| Metodas | Kelias | Auth | Paskirtis |
|---|---|---|---|
| POST | `/module/plunkconnect/webhook` | `X-Plunk-Timestamp` + `X-Plunk-Signature` (HMAC `timestamp + "." + rawBody`, ±300 s) arba `Authorization: Bearer <webhook secret>` | `contact.subscribed` / `contact.unsubscribed` → `ps_customer.newsletter`, `ps_emailsubscription.active` |
| GET | `/module/plunkconnect/cron?token=…` | token | outbox worker + valymas |
| GET | `/module/plunkconnect/feed/products?lang=lt&cursor=&limit=&updated_since=` | `Bearer <feed token>` | katalogo feed (cursor pagal `id_product`, ≤500/puslapis) |
| GET | `/module/plunkconnect/feed/categories?lang=lt` | `Bearer <feed token>` | kategorijų feed |
| GET | `/module/plunkconnect/products?ids=1,2&lang=lt` | `Bearer <feed token>` | gyva kaina/likutis (≤50 ID; neaktyvios → `available:false`) |
| GET | `/module/plunkconnect/recommendations?product_ids=…&lang=lt&limit=6` | `Bearer <feed token>` | priedai + kategorijos best selleriai, tik turimos |
| POST | `/module/plunkconnect/coupon` | HMAC kaip webhook | vienkartinis `CartRule` → `{code, validUntil}`; kodas ir galiojimas įrašomi kontaktui (`lastCouponCode`, `lastCouponValidUntil`) |
| GET | `/module/plunkconnect/restorecart?c=&e=&t=` | HMAC nuoroda (30 d.) | atkuria svečio krepšelį; registruoto – per prisijungimą |

### Įvykiai (visi per outbox → `POST /v1/track`)

`newsletter.signup_requested` / `newsletter.subscribed` / `newsletter.unsubscribed`,
`customer.registered`, `product.viewed` (30 min debounce), `category.viewed`,
`cart.updated` (sutraukimas per `cart:<id>`), `order.placed`, `order.{paid,shipped,delivered,cancelled,refunded}`,
`product.back_in_stock`.

Persistent `data` – tik maži skalai (`locale`, `shopCode`, `cartItemsCount`, `ordersCount`, `totalSpent`, …).
Masyvai (`product`, `cart`, `order`) siunčiami kaip `{value:…, persistent:false}` → prieinami workflow per `{{event.*}}`.

### Konsolės komandos

- `plunkconnect:outbox:process [--limit=200] [--max-seconds=50]`
- `plunkconnect:subscribers:backfill --since=YYYY-MM-DD [--dry-run]` – `contacts_ifnew`: esamiems kontaktams `subscribed` nekeičia
- `plunkconnect:subscribers:reconcile` – tik raportuoja neatitikimus

### Pastabos Plunk workflow'ams

- WEBHOOK žingsnis: jei Plunk nemoka skaičiuoti HMAC, naudokite statinę antraštę `Authorization: Bearer <webhook secret>`.
- Nuorodos laiškuose gali turėti `?pk_e=<token>` (token = `base64url(email).hmac(email)`) – modulis įsimins lankytoją `plunk_id` cookie (365 d).
- `{{contact.lastCouponCode}}` – kuponas, sukurtas `/coupon` endpoint'o.

---

## EN

### Install

1. Copy `plunkconnect/` to `modules/` (or upload the zip). Composer is optional – a bundled PSR-4 autoloader is used when `vendor/` is absent.
2. Install via *Modules → Module Manager* or `php bin/console prestashop:module install plunkconnect`.
3. Safe to run alongside `newsmanapp` during migration.

### Cron (required)

No synchronous HTTP in shopper requests – everything goes through `PREFIX_plunk_outbox`:

```cron
* * * * * php /path/bin/console plunkconnect:outbox:process
```

Fallback without CLI: `GET /module/plunkconnect/cron?token=<cron token>` every minute.

### Endpoints

See the LT table above – paths, auth and behaviour are identical.

### Events (all via outbox → `POST /v1/track`)

`newsletter.signup_requested` / `newsletter.subscribed` / `newsletter.unsubscribed`,
`customer.registered`, `product.viewed`, `category.viewed`, `cart.updated`,
`order.placed`, `order.{paid,shipped,delivered,cancelled,refunded}`, `product.back_in_stock`.

Persistent `data` holds small scalars only; arrays travel as `{value:…, persistent:false}` → `{{event.*}}`.

### Notes for Plunk workflows

- If the WEBHOOK step cannot compute HMAC, use the static `Authorization: Bearer <webhook secret>` fallback.
- Email links may carry `?pk_e=<token>` (token = `base64url(email).hmac(email)`) – the module stores a `plunk_id` cookie (365 d).
- `{{contact.lastCouponCode}}` is set by the `/coupon` endpoint.
