# Plunk Connect (`plunkconnect`)

PrestaShop modulis, jungiantis parduotuvę su self-hosted Plunk (pakeičia `newsmanapp` + `newsmancoupons`).
PrestaShop module connecting a shop to a self-hosted Plunk instance (replaces `newsmanapp` + `newsmancoupons`).

- **Suderinamumas / Compatibility:** PrestaShop **8.1 – 9.x**, PHP **>= 8.1**, multistore (konfigūracija per parduotuvę / per-shop configuration).
- **Be BZ specifikos / Shop-agnostic:** viskas konfigūruojama per admin'ą / everything is configured in the back office.

---

## LT

### Diegimas

1. Nukopijuokite `plunkconnect/` į `modules/` (arba zip→Moduliai→Įkelti modulį). Jei reikalingos konsolės komandos (`bin/console plunkconnect:*`), release zip'e **privalo būti `vendor/`** (`composer install --no-dev -o` arba bent `composer dump-autoload -o --no-dev`). PrestaShop Symfony konteineris kompiliuojamas prieš įkeliant modulio `autoload.php` – komandų klases jis randa tik per `modules/plunkconnect/vendor/autoload.php`. Be `vendor/` modulis vis tiek veikia (yra PSR-4 fallback), tik `config/services.yml` tada nekopijuojamas (modulis automatiškai jį sukuria iš `services.yml.dist`, kai vendor atsiranda) – todėl BO niekada nelūš.
2. Įdiekite per *Moduliai → Module Manager* arba `php bin/console prestashop:module install plunkconnect`.
3. Modulis gali veikti kartu su `newsmanapp` perėjimo laikotarpiui – konfliktų nėra.

### Konfigūracija (admin)

| Laukas | Aprašymas |
|---|---|
| Plunk API URL | pvz. `https://api.mk.example.lt` |
| Public key `pk_…` | `POST /v1/track` |
| Secret key `sk_…` | `/contacts`, lookup, delete |
| Webhook secret | HMAC pasirašymui (webhook, restorecart, `pk_e`) + Bearer fallback webhook'ui |
| Feed token | katalogo feed ir gyvų produktų endpointai |
| Cron token | `/module/plunkconnect/cron?token=…` |
| Kalbų žemėlapis | `id_lang` → `lt/lv/et/en/pl`; `ru` → fallback (`ru` niekada tikslas negali būti) |
| Būsenų žemėlapis | `OrderState` → `paid/shipped/delivered/cancelled/refunded` |
| Funkcijų jungikliai | prenumeratos, double opt-in, peržiūros, krepšelis, užsakymai, feed, kuponai, back-in-stock, `?pk_e=` |
| `consentSource`, `shopCode` | siunčiami kiekviename payload'e |

### Cron (privaloma)

Jokio sinchroninio HTTP pirkėjo užklausoje – viskas eina per `PREFIX_plunk_outbox` ir worker'į:

```cron
# produkcinis layout (/etc/cron.d/<svetaine>), flock saugo nuo overlap'o
* * * * *        flock -n /tmp/plunk-outbox.lock   php bin/console plunkconnect:outbox:process --limit=200 --max-seconds=50
7,22,37,52 * * * *  flock -n /tmp/plunk-catalog.lock  php bin/console plunkconnect:catalog:sync
0 3 * * *        flock -n /tmp/plunk-catalog-full.lock php bin/console plunkconnect:catalog:sync --full
```

Atsarginis variantas be CLI: `GET /module/plunkconnect/cron?token=<cron token>` kas minutę
(paleidžia outbox worker'į ir, kai `FEAT_CATALOG_SYNC` įjungtas, inkrementinį sync kas ~15 min
bei pilną 03:00–05:00, ne dažniau 1×/20 h).

### Endpointai

| Metodas | Kelias | Auth | Paskirtis |
|---|---|---|---|
| POST | `/module/plunkconnect/webhook` | `X-Plunk-Timestamp` + `X-Plunk-Signature` (HMAC `timestamp + "." + rawBody`, ±300 s) arba `Authorization: Bearer <webhook secret>` | `contact.subscribed` / `contact.unsubscribed` → `ps_customer.newsletter`, `ps_emailsubscription.active` |
| GET | `/module/plunkconnect/cron?token=…` | token | outbox worker + valymas |
| GET | `/module/plunkconnect/feed/products?lang=lt&cursor=&limit=&updated_since=` | `Bearer <feed token>` | katalogo feed (cursor pagal `id_product`, ≤500/puslapis) |
| GET | `/module/plunkconnect/feed/categories?lang=lt` | `Bearer <feed token>` | kategorijų feed |
| GET | `/module/plunkconnect/products?ids=1,2&lang=lt` | `Bearer <feed token>` | gyva kaina/likutis (≤50 ID; neaktyvios → `available:false`) |
| GET | `/module/plunkconnect/recommendations?product_ids=…&lang=lt&limit=6` | `Bearer <feed token>` | priedai + kategorijos best selleriai, tik turimos |
| POST | `/module/plunkconnect/coupon` | `Bearer <coupon secret>` | vienkartinis `CartRule` iš modulio taisyklės → `{code, validUntil, value, type, minAmount}`; žr. skyrių žemiau |
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
- `plunkconnect:coupons:cleanup [--dry-run]` – trina pasibaigusius nepanaudotus modulio kuponus (`CartRule` + `plunk_coupon`)

### Katalogo sinchronizacija -> Plunk (`/catalog/products`)

- `FEAT_CATALOG_SYNC` (admin) jungia sinchronizaciją. Visi pakeitimai eina per outbox – hook'ai tik įdeda eilės įrašus (<5 ms), API kvietimų užklausų metu nėra.
- `catalog.upsert` – `PUT /catalog/products` (`{"products":[…]}`), <=500 įrašų užklausai (kombinacijos skaičiuojamos); `catalog.patch` – `PATCH` tik `id/price/salePrice/available/quantity` <=1000, su 60 s suliejimu; `catalog.delete` – `POST /catalog/products/delete` <=1000 id.
- Katalogo `id` (Plunk `externalId`) visur vienodas – katalogas, įvykiai (`productId`, `recentlyViewed`), rekomendacijos, `/products`:
  - parduodamas vienetas (prekė be kombinacijų arba kombinacija) – jo `reference` (SKU), jei jis užpildytas ir unikalus tarp visų parduodamų vienetų (be raidžių dydžio ir tarpų);
  - kitu atveju, ir kombinacijas turinčios prekės tėvui – `PS-<id_product>` arba `PS-<id_product>-<id_product_attribute>`;
  - kombinacija turi `parentId`.
- Išsiųstas id saugomas `ps_plunk_catalog_id`. Sutvarkius `reference` (užpildžius ar pašalinus dublikatą), kitas upsert seną id Plunk'e ištrina ir sukuria naują. Kitos prekės su tuo pačiu buvusiu dublikatu atsinaujina per naktinį pilną sync.
- Atnaujinant iš 1.2.x: Plunk kataloge likę seni `<id>`/`<id>-<attr>` įrašai ištrinami ir paleidžiamas `plunkconnect:catalog:sync --full`.
- `PUT` perrašo viską (neatsiųsti laukai Plunk'e ištrinami); `PATCH` keičia tik nurodytus laukus. Išjungti produktai siunčiami su `available:false`, ne trinami.
- Komanda: `php bin/console plunkconnect:catalog:sync [--full] [--from=<id>]`. Inkrementinis – `date_upd` + `specific_price` aktyvavimosi/pasibaigimo langai (kaina gali keistis nekeičiant `date_upd`). Cron: kas ~15 min inkrementinis, 03:00–05:00 pilnas (nebūtinas, jei naudojamas išorinis cron).
- Admin „Full sync now" tik užsarašo outbox eilę – nieko nesiunčia sinchroniškai.
- Autentifikacija – `Authorization: Bearer <sk_>`; 4xx (išskyrus 408/429) → dead, 408/429/5xx → retry.

### Pastabos Plunk workflow'ams

- WEBHOOK žingsnis: jei Plunk nemoka skaičiuoti HMAC, naudokite statinę antraštę `Authorization: Bearer <webhook secret>`.
- Nuorodos laiškuose gali turėti `?pk_e=<token>` (token = `base64url(email).hmac(email)`) – modulis įsimins lankytoją `plunk_id` cookie (365 d).
- `{{contact.lastCouponCode}}` – kuponas, sukurtas `/coupon` endpoint'o.
- Kuponų endpoint'o atsakymą WEBHOOK žingsnyje galima išsaugoti `saveResponseAs` (jei Plunk fork'e tai palaikoma) ir naudoti `{{event.coupon.code}}` vėlesniuose žingsniuose.

### Kupono endpoint'as (`POST /module/plunkconnect/coupon`)

Auth: `Authorization: Bearer <coupon secret>` (admin → Security tokens → Coupon secret).

Body: `{"email": "...", "rule": "welcome10", "cartId": 123}` arba `{"email": "...", "rule": "welcome10", "executionId": "wf-exec-42"}`.
`cartId` arba `executionId` – bet kuris (abu kartu idempotencijai geriau).

Visi kupono parametrai imami iš admin → Coupon rules (JSON), **ne** iš užklausos:
```json
{"welcome10": {"type": "percent", "value": 10, "validDays": 30, "minAmount": 30, "prefix": "BZ", "excludeSpecial": true}}
```

Atsakymas: `{"code": "BZ-7Q2K9XMP", "validUntil": "2026-12-11", "value": 10, "type": "percent", "minAmount": 30}`

- Kodas: `PREFIX-` + 8 simboliai be `0/O/1/I/L`; `quantity=1`, `quantity_per_user=1`, nesujungiamas su kitais kuponais, `reduction_exclude_special` pagal taisyklę; registruotam klientui pririšamas prie `id_customer`.
- Idempotencija: tas pats `email+rule+cartId/executionId` → tas pats kuponas; gyvas (nepasibaigęs) kuponas tam pačiam `email+rule` taip pat pernaudojamas.
- Rate limit: ≤3 kuponai / `email+rule` / 30 d. → HTTP 429.
- Kiekvienas sukurtas kodas sekamas `PREFIX_plunk_coupon`; `order.placed` įvykio `data.order.couponCode` nurodo panaudotą kodą.
- Kodas taip pat įrašomas kontaktui: `lastCouponCode`, `lastCouponValidUntil`.

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
- The coupon response can be captured by the WEBHOOK step via `saveResponseAs` (if supported by the Plunk fork) and used as `{{event.coupon.code}}` in later steps.

### Coupon endpoint (`POST /module/plunkconnect/coupon`)

Auth: `Authorization: Bearer <coupon secret>` (admin → Security tokens → Coupon secret).

Body: `{"email": "...", "rule": "welcome10", "cartId": 123}` or `{"email": "...", "rule": "welcome10", "executionId": "wf-exec-42"}`.

All coupon parameters come from admin → Coupon rules (JSON), never from the request:
```json
{"welcome10": {"type": "percent", "value": 10, "validDays": 30, "minAmount": 30, "prefix": "BZ", "excludeSpecial": true}}
```

Response: `{"code": "BZ-7Q2K9XMP", "validUntil": "2026-12-11", "value": 10, "type": "percent", "minAmount": 30}`

- Code: `PREFIX-` + 8 unambiguous chars; `quantity=1`, `quantity_per_user=1`, not combinable, `reduction_exclude_special` per rule; bound to `id_customer` for registered customers.
- Idempotent on `email+rule+cartId/executionId`; a live coupon for the same `email+rule` is reused.
- Rate limit: ≤3 coupons / `email+rule` / 30d → HTTP 429.
- Tracked in `PREFIX_plunk_coupon`; `order.placed` carries `data.order.couponCode`; unused expired rules are removed by `plunkconnect:coupons:cleanup`.
