# PlunkConnect — katalogo sinchronizacija (spec 5.4)

> Atkurtas dokumentas: originalus `plunkconnect-katalogo-sinchronizacija.md`
> buvo netyčia pašalintas sinchronizuojant modulio failus. Turinys atstatytas
> pagal užfiksuotus reikalavimus.

## 1. Tikslas

Siųsti PrestaShop produktų katalogą į Plunk (`/catalog/products`), kad
Plunk el. laiškų redaktoriaus „Insert products" funkcija ir product
blokai veiktų su realiais produkto duomenimis.

## 2. API

Autentifikacija: `Authorization: Bearer <sk_...>` (secret key).

| Veiksmas | Metodas | Payload | Limitai | Atsakymas |
|---|---|---|---|---|
| Pilnas upsert | `PUT /catalog/products` | `{"products":[…]}` | ≤500 produktų/užklausą | `{"upserted":N}` |
| Dalinis atnaujinimas | `PATCH /catalog/products` | `{"products":[{"id","price","salePrice","available","quantity"}]}` | ≤1000/užklausą | `{"updated":N,"notFound":[ids]}` |
| Trynimas | `POST /catalog/products/delete` | `{"ids":[…]}` | ≤1000 id/užklausą | `{"deleted":N}` |
| Verifikacija | `GET /catalog/products?limit=&cursor=&search=` | – | limit 1–100 | `{"products":[…],"cursor"}` |

- `PUT` perrašo visus laukus – neatsiųsti laukai Plunk'e ištrinami.
- `PATCH` keičia tik payload'e esančius laukus.
- HTTP elgsena: 4xx (išskyrus 408/429) → dead; 408, 429, 5xx → retry per outbox.

## 3. Produkto dokumentas

```json
{
  "id": "<id_product>" arba "<id_product>-<id_product_attribute>",
  "parentId": "<id_product>",        // tik kombinacijoms
  "sku": "reference",
  "name": "...",
  "description": "short_description be HTML, ≤1000 simbolių",
  "url": "https://…",                // kombinacijai su #/<id_product_attribute>
  "imageUrl": "cover, large_default",
  "images": ["…"],                   // ≤5
  "price": 19.99,                    // su PVM, be nuolaidų, 2 sk. po kablelio
  "salePrice": 14.99,                // tik jei < price, kitaip null
  "currency": "EUR",
  "available": true,                 // active+visible+(qty>0 arba backorders)
  "quantity": 7,
  "brand": "manufacturer",
  "categories": ["LT kategorijos, be Root/Home"],
  "translations": {
    "lv": {"name":"…","url":"…","description":"…"},
    "pl": {…}, "et": {…}, "en": {…}
  }
}
```

- ID formatas kanoninis visur: `product.viewed`, `cart.items[].productId`,
  `order.items[].productId`, rekomendacijos, back-in-stock, katalogas.
- Produktai su kombinacijomis siunčiami: 1× parent (`"<id>"`) + 1× per
  kombinaciją (`"<id>-<attr>"`, su `parentId`).
- Išjungti produktai siunčiami su `available:false` (ne trinami).
- Produktai be pavadinimo – praleidžiami.
- URL – tik HTTPS. Bazinė kalba LT; `id_lang` 5 praleidžiama; `ru` niekada.
- Vertimo raktai turi atitikti `^[a-z]{2}(-[A-Z]{2})?$`.

## 4. Outbox įvykiai

- `catalog.upsert` – `{ids:[id_product…]}`; worker'is resolv'ina dokumentus
  ir siunčia PUT batch'ais ≤500 (kombinacijos skaičiuojamos).
- `catalog.patch` – dedupe `catalog.patch:<id>`, `next_attempt_at = NOW()+60s`;
  kartotiniai per minutę suliejami į tą pačią eilutę. Worker'is grupuoja
  ≤1000 patch į vieną PATCH; `notFound` id → `catalog.upsert`.
- `catalog.delete` – `{ids:[…]}`, dedupe; trinant produktą pridedami ir
  jo kombinacijų id (surinkti `actionObjectProductDeleteBefore` hook'e).
- Hook'ai tik deda outbox eilutes (<5 ms) – jokių API kvietimų.

## 5. Hook'ai

- `actionProductAdd`, `actionProductUpdate`,
  `actionObjectCombinationAddAfter`, `actionObjectCombinationUpdateAfter`
  → `catalog.upsert`
- `actionUpdateQuantity`, `actionObjectStockAvailableUpdateAfter`,
  `actionObjectSpecificPriceAddAfter`, `actionObjectSpecificPriceUpdateAfter`,
  `actionObjectSpecificPriceDeleteAfter` → `catalog.patch`
- `actionObjectProductDeleteBefore` + `actionProductDelete`,
  `actionObjectCombinationDeleteAfter` → `catalog.delete`
- Registruojami per `upgrade/upgrade-1.2.0.php`.

## 6. Komanda ir cron

- `php bin/console plunkconnect:catalog:sync [--full] [--from=<id>]`
  - pilnas: cursor per `id_product`, 200 produktų/batch, sugrupuotos
    užklausos (kainos/likutis/nuotraukos/kategorijos/vertimai) – be N+1.
  - inkrementinis: `date_upd > CATALOG_LAST_SYNC` + `specific_price`
    įrašai, kurių `from`/`to` tapo aktyvūs arba pasibaigė nuo paskutinio
    paleidimo (kaina keičiasi nekeičiant `date_upd`).
- Cron: inkrementinis kas ~15 min; pilnas naktį 03:00–05:00, ne dažniau
  nei kartą per 20 h. Integracija per `actionCronJob`.

## 7. Admin

- `FEAT_CATALOG_SYNC` jungiklis.
- „Full sync now" mygtukas – tik užsarašo outbox eilę.
- Diagnostika: paskutinio sync laikas, paskutinio pilno sync laikas.

## 8. Priėmimo kriterijai

1. `plunkconnect:catalog:sync --full` + `outbox:process` →
   `GET /catalog/products?limit=5` grąžina LT produktus su kainomis ir
   `translations.lv`.
2. Pakeitus likutį/nuolaidą admin'e – po 1–2 min PATCH matomas.
3. Ištrynus produktą – `GET /catalog/products/<id>` → 404.
4. Plunk „BZ test" redaktoriaus „Insert products" randa produktus,
   preview su kontaktu rodo kainas.
