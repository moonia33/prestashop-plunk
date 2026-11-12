# plunkconnect – PrestaShop ↔ Plunk modulis

PrestaShop modulis, kuris pakeičia Newsman (`newsmanapp`, `newsmancoupons`) ir jungia parduotuvę su self-hosted Plunk instancija (pvz. `https://api.mk.tvparkas.lt`). Bendras modulis – visa parduotuvei specifika konfigūruojama admin'e.

**Statusas: 1 etapas** – skeletas + naujienlaiškio prenumeratos (double opt-in, webhook, backfill, GDPR).

## Kaip tai veikia

- **Niekada jokio sinchroninio HTTP kvietimo pirkėjo užklausoje.** Visi siuntimai eina per `ps_plunk_outbox` lentelę ir atskirą worker'į – Plunk neveikimas nepaveikia parduotuvės greičio.
- Hook'ai tik įrašo eilutę į outbox (< 2 ms). Worker'is siunčia į Plunk su `Idempotency-Key`, `Retry-After` ir eksponentiniu retry.
- Multistore: visa konfigūracija saugoma per parduotuvę (`id_shop`) – kiekviena parduotuvė gali turėti atskirus Plunk projektus/raktus.
- **`ru` kalba niekada nesiunčiama** – `ru` kontaktams taikoma konfigūruojama fallback locale (numatyta `lt`).

## Diegimas

1. `plunkconnect/` katalogą nukopijuoti į `modules/`.
2. Admin → Modules → Module Manager → įdiegti **Plunk Connect**.
3. Modulio konfigūracijoje (kontekste – konkreti parduotuvė):
   - **Plunk API URL**, **pk_**, **sk_** raktai → „Išsaugoti“ → „Tikrinti ryšį“.
   - Įjungti **Naujienlaiškio sinchronizaciją** (ir **Double opt-in**, jei Plunk'e sukonfigūruotas patvirtinimo workflow).
   - Peržiūrėti sugeneruotus tokenus (webhook/cron/feed) – jie reikalingi Plunk workflow ir crontab'e.
   - Kalbų žemėlapis: jei id_lang nesusietas, imamas `iso_code`; `ru` visada fallback'ina.
4. Uždėti cron'ą (žr. žemiau).

Modulis nieko nesiunčia, kol nėra API URL + raktų ir neįjungta funkcija.

## Cron / worker'is

Pirmenybė – CLI (kiekvieną minutę):

```bash
* * * * * cd /path/to/shop && php bin/console plunkconnect:outbox:process >> /dev/null 2>&1
```

Fallback be CLI – HTTP endpoint (rodomas modulio nustatymuose):

```bash
* * * * * curl -s "https://shop.example/module/plunkconnect/cron?token=<cron token>" > /dev/null
```

Worker'is: iki 200 žinučių per paleidimą, ≤ ~50 s, ≤ 20 req/s. `sent` eilutės senesnės nei 7 d. trinamos (`--prune` arba automatiškai ~1% HTTP run'ų).

## Plunk pusės konfigūracija (1 etapas)

### Double opt-in workflow (rekomenduojama)

1. Workflow trigger: įvykis `newsletter.signup_requested`.
2. `SEND_EMAIL` žingsnis: patvirtinimo laiškas su nuoroda `{{subscribeUrl}}`.
3. Paspaudus, Plunk pats nustato `subscribed=true` ir sukuria `contact.subscribed` įvykį.

### Webhook atgal į parduotuvę

Workflow su trigger `contact.subscribed` **ir** `contact.unsubscribed` (du workflow'ai), juose – `WEBHOOK` žingsnis:

- URL: `https://shop.example/module/plunkconnect/webhook`
- Method: `POST`
- Headers: `Authorization: Bearer <webhook secret>` (tokenas – modulio nustatymuose)
- Body (gali būti default arba custom):

```json
{"email": "{{email}}", "subscribed": true}
```

arba `{"email": "{{email}}", "event": "contact.unsubscribed"}`.

Modulis priima visas formas: `{"contact":{"email","subscribed"}}` (Plunk default), `{"email","subscribed"}`, `{"email","event":"contact.*"}`.

> **Kodėl Bearer, o ne HMAC?** Patikrinta `useplunk/plunk@next` (`WorkflowExecutionService::executeWebhook`): WEBHOOK žingsnio antraštės renderinamos kaip statiniai `{{var}}` šablonai – HMAC parašo jis suskaičiuoti negali. Modulis vis tiek priima ir HMAC variantą (`X-Plunk-Timestamp` + `X-Plunk-Signature = hex(HMAC_SHA256(secret, ts+"."+body))`, ±300 s), jei siuntėjas mokėtų jį formuoti.

> **Kuponams (4 etapas):** WEBHOOK žingsnio HTTP atsakymas saugomas į step output, bet **nepasiekiamas** vėlesnio `SEND_EMAIL` šablono – todėl kupono kodą modulis įrašys į kontaktą (`data.lastCouponCode`) per `POST /contacts`.

## Įvykiai (1 etapas)

| Įvykis | Kada | `subscribed` | `data` |
|---|---|---|---|
| `newsletter.signup_requested` | Naujas prenumeratorius, DOI įjungtas | `false` | `locale`, `source:'prestashop'`, `consentSource`, `consentRequestedAt`, `consentIp`, `consentReferer`, `shopCode`, `firstName?`, `lastName?`, `customerId?` |
| `newsletter.subscribed` | Prenumerata, DOI išjungtas | `true` | tie patys |
| `newsletter.unsubscribed` | Atsisakyta PS pusėje | `false` | `locale`, `consentSource:'website_unsubscribe'`, `shopCode` |

Šaltiniai: `ps_emailsubscription` forma (+ verification confirm), paskyros sukūrimas su newsletter varnele, `customer.newsletter` perjungimas paskyroje/checkout'e, svečio atsisakymas per formą (tikrinama shutdown funkcija).

## Komandos

```bash
# outbox worker
php bin/console plunkconnect:outbox:process [--limit=200] [--max-seconds=50] [--shop=1] [--prune]

# pradinis perkėlimas (subscribers, atsiradę po Newsman eksporto)
php bin/console plunkconnect:subscribers:backfill --since=2026-11-12 --dry-run
php bin/console plunkconnect:subscribers:backfill --since=2026-11-12

# naktinis suderinimas – TIK raportuoja neatitikimus
php bin/console plunkconnect:subscribers:reconcile [--shop=1]
```

Backfill siunčia `POST /contacts` **be `subscribed`** – esamų kontaktų būsena nekeičiama, nauji tampa subscribed pagal Plunk nutylėjimą.

## Endpoint'ai

| Endpoint | Metodas | Auth |
|---|---|---|
| `/module/plunkconnect/webhook` | POST | `Authorization: Bearer <webhook secret>` arba HMAC |
| `/module/plunkconnect/cron?token=…` | GET | `token` = cron token |

## GDPR

- `actionDeleteGDPRCustomer` → outbox `delete` žinutė (worker'is daro `GET /contacts?search=` + `DELETE /contacts/:id`).
- `actionExportGDPRData` → JSON su tuo, kas siunčiama į Plunk.

## Saugumas

- Slapto raktų (pk_, sk_, webhook/cron/feed token) reikšmės saugomos `ps_configuration`, admin'e rodomos užmaskuotos.
- El. paštai log'uose maskuojami (`j***@gmail.com`).
- Webhook/cron – `hash_equals` tikrinimas; HMAC su ±300 s langu.
- TLS verifikacija visada įjungta; connect 3 s / total 10 s timeout'ai.

## Vystymas

```bash
cd plunkconnect
composer install
./vendor/bin/phpunit          # 34 testai
./vendor/bin/phpstan analyse  # level 6
./vendor/bin/php-cs-fixer fix
```

Struktūra: `src/` – PSR-4 `PlunkConnect\`, `controllers/front/` – webhook+cron, `src/Command/` – bin/console komandos, `sql/` – schema, `translations/` – LT/EN XLF.

## Planas

- **2 etapas**: elgsenos įvykiai (`product.viewed`, `cart.updated`+`restorecart`, `order.*`), kontaktų identifikavimas (`plunk_id` cookie), consent cookie tikrinimas.
- **3 etapas**: katalogo feed (`/feed/products`, `/feed/categories`), gyvi produktų duomenys, rekomendacijos.
- **4 etapas**: kuponai (`/coupon`, vienkartiniai `CartRule`), back-in-stock.
