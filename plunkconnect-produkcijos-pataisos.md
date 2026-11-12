# plunkconnect: produkcijoje rastos klaidos, kurias reikia perkelti į repo

**Svarbu:** modulis įdiegtas **produkcinėje** baltijoszvynas.lt (`/home/baltijoszvynas/htdocs/baltijoszvynas.lt/modules/plunkconnect`), ne lokalioje parduotuvėje. Su Ramūnu sutarta: produkcija yra testinė aplinka su „BZ test“ raktais, bet įjungtas **tik** `FEAT_CATALOG_SYNC` ir `FEAT_FEED`. Kitų jungiklių neįjunk be Ramūno leidimo. `rsync --delete` į produkciją nedaryk. Produkcijos failai dabar naujesni už GitHub (`df53113` dar nepush'intas), todėl žemiau aprašytus pakeitimus įkelk į repo ir tik tada push'ink.

## 1. BO lūžo: `Class "PlunkConnect\Command\ProcessOutboxCommand" ... cannot be found`

PrestaShop Symfony konteinerį kompiliuoja prieš įkeldamas modulio `autoload.php`. Modulių klases jis mato tik per `modules/<m>/vendor/autoload.php` (`AppKernel::enableComposerAutoloaderOnModules`). Produkcijoje paleista `composer dump-autoload -o --no-dev`.

Repo pataisa:
- Release zip'e privalo būti `vendor/` (composer `dump-autoload -o --no-dev`, be dev priklausomybių). Įdėk tai į CI ar build skriptą ir README.
- `install()` / `upgrade-1.2.0.php`: jei `vendor/autoload.php` nėra, `config/services.yml` neregistruojamas (pvz. failą laikyk `config/services.yml.dist` ir kopijuok tik tada, kai yra vendor), kad BO niekada nelūžtų.
- Po bet kokio `services.yml` pakeitimo išvalyk `var/cache/prod` ir patikrink BO.

## 2. Outbox eilutės laukdavo 1 val.

PHP (`date()`) naudoja parduotuvės laiko juostą Europe/Vilnius, o MySQL `NOW()` – serverio laiką Europe/Berlin. `next_attempt_at` rašomas su PHP laiku, o lyginamas su `NOW()`. Pataisyta `OutboxProcessor.php` 39 eil.:

```php
AND `next_attempt_at` <= \'' . pSQL(date('Y-m-d H:i:s')) . '\'
```

Ta pati problema yra kitur: `NOW()` naudojamas `date_upd` (48–61, 313 eil.), `CleanupCouponsCommand` (`date_exp < NOW()`), `CouponService` (188, 202 eil.). PrestaShop `date_exp`/`date_add` rašo PHP laiku, todėl visur lygink su PHP `date()`, o ne `NOW()`. Pridėk testą, kuris tai patikrina.

## 3. CatalogMapper (diff žemiau)

- `out_of_stock = 2` reiškia parduotuvės numatytąjį nustatymą (`PS_ORDER_OUT_OF_STOCK`, BZ = 0), o ne „leisti“. Anksčiau 0 vnt. prekės buvo `available:true`.
- CLI `getImageLink()` grąžina `http://` arba `//host`, todėl visos nuotraukos būdavo atmetamos (`imageUrl:null`). Dabar normalizuojama į `https://`, jei `PS_SSL_ENABLED`.
- Kategorijose buvo „Pagrindinis“ (Home, `id_parent = 1 > 0`). Dabar `PS_ROOT_CATEGORY` ir `PS_HOME_CATEGORY` išmetamos.

Testai: `out_of_stock` 0/1/2 × `PS_ORDER_OUT_OF_STOCK` 0/1, URL normalizacija, Home išmetimas.

## 4. Cron produkcijoje (`/etc/cron.d/baltijoszvynas`)

```
* * * * *          outbox:process --limit=200 --max-seconds=50 (flock)
7,22,37,52 * * * * catalog:sync (flock)
0 3 * * *          catalog:sync --full (flock)
```

README aprašyk tą patį ir `flock` naudojimą.

## 5. Patikrinta

200 prekių (506 su variantais) atkeliauja per ~2 s. Laukai teisingi: `1602169-20867`, `parentId`, SKU, kaina 59.00 / akcija 53.10, https nuotrauka, `translations` en/et/lv/pl, kategorijos be Home.

## CatalogMapper diff

```diff
--- /tmp/CatalogMapper.orig.php	2026-11-12 16:28:41.426355258 +0100
+++ src/Mapper/CatalogMapper.php	2026-11-12 16:28:41.444356442 +0100
@@ -274,7 +274,9 @@
      */
     private function isAvailable(array $row, int $quantity): bool
     {
-        $outOfStockAllowed = in_array((int) ($row['out_of_stock'] ?? 0), [1, 2], true); // allow, or shop default
+        // out_of_stock: 0 deny, 1 allow, 2 follow the shop default (PS_ORDER_OUT_OF_STOCK).
+        $mode = (int) ($row['out_of_stock'] ?? 2);
+        $outOfStockAllowed = 1 === $mode || (2 === $mode && (bool) \Configuration::get('PS_ORDER_OUT_OF_STOCK'));
 
         return 1 === (int) ($row['active'] ?? 0)
             && 'none' !== (string) ($row['visibility'] ?? 'both')
@@ -351,6 +353,12 @@
                 continue;
             }
             $url = $link->getImageLink((string) $row['link_rewrite'], $idProduct . '-' . (int) $row['id_image'], 'large_default');
+            // CLI has no request scheme, so the link may come back as http:// or //host.
+            if (0 === strpos($url, '//')) {
+                $url = 'https:' . $url;
+            } elseif (0 === strpos($url, 'http://') && \Configuration::get('PS_SSL_ENABLED')) {
+                $url = 'https://' . substr($url, 7);
+            }
             if (preg_match('#^https://#', $url)) {
                 $out[$idProduct][] = $url;
             }
@@ -427,7 +435,8 @@
              INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                 ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop . '
                 AND cl.`id_lang` = ' . $idLang . '
-             WHERE c.`active` = 1 AND c.`id_parent` > 0'
+             WHERE c.`active` = 1 AND c.`id_parent` > 0
+               AND c.`id_category` NOT IN (' . (int) \Configuration::get('PS_ROOT_CATEGORY') . ', ' . (int) \Configuration::get('PS_HOME_CATEGORY') . ')'
         ) ?: [];
 
         $map = [];
```

## 6. Įvykių testas (2026-11-12, info@inultimo.lt)

Veikia: `category.viewed` (10), `product.viewed` (8), `cart.updated` (4), `checkout.started` (1), visi `sent`, kontaktas su `locale`, `cartTotal`, `recentlyViewed`. `FEAT_SUBSCRIPTIONS` buvo išjungtas, todėl prenumeratos įvykio nebuvo. Taip ir turi būti.

Pataisyti:
- `categoryPath` turi „Šakninis“ ir „Pagrindinis“. Išmesk `PS_ROOT_CATEGORY` ir `PS_HOME_CATEGORY`, kaip `CatalogMapper`.
- Produkto įvykiuose `id` yra int, o `productId` – string. Palik `productId` kaip pagrindinį lauką. Kai pasirinkta kombinacija (`combinationId > 0`), `productId` turi būti `"<id_product>-<id_product_attribute>"`, kaip kataloge. Patikrink `cart.items` ir `order.items` su variantine preke.
- `recentlyViewed` siųsk kaip string ID masyvą (`["1602178", …]`), kad sutaptų su katalogu.
- Wrapper `{value, persistent:false}` palik. Plunk jį dabar išvynioja pats: šablonuose rašoma `event.cart.items`, ne `event.cart.value.items`.
