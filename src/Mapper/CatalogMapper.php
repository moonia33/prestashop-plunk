<?php

/**
 * Builds the Plunk catalog product document (spec 5.4).
 *
 * Batch-oriented: one query per aspect per batch (products, images,
 * combinations, stocks, categories, translations) - no N+1.
 */

namespace PlunkConnect\Mapper;

final class CatalogMapper
{
    /**
     * Plunk-wide product id: "<id_product>" or "<id_product>-<attr>".
     * The same format is used by catalog, events, recommendations,
     * back-in-stock and delete payloads.
     */
    public static function plunkId(int $idProduct, int $idProductAttribute = 0): string
    {
        return (string) $idProduct . ($idProductAttribute > 0 ? '-' . $idProductAttribute : '');
    }

    /** Shop languages that must never be used for catalog translations. */
    private const SKIP_LANG_IDS = [5];

    /** Locales sent as base language. */
    public const BASE_LOCALE = 'lt';

    /** Translation target locales (if the shop has them). */
    private const TRANSLATION_LOCALES = ['lv', 'pl', 'et', 'en'];

    /**
     * Build catalog docs for a set of product ids (id_product ints).
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> ready for PUT /catalog/products
     */
    public function buildProducts(array $ids, int $idShop): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn ($id) => $id > 0);
        if (!$ids) {
            return [];
        }
        $idList = implode(',', $ids);
        $db = \Db::getInstance();

        $langs = $this->translationLangIds($idShop);
        $baseLangId = $this->baseLangId($idShop);

        $products = $db->executeS(
            'SELECT p.`id_product`, p.`reference`, p.`id_category_default`, p.`id_manufacturer`,
                    ps.`active`, ps.`visibility`, ps.`available_for_order`, p.`out_of_stock`,
                    pl.`name`, pl.`link_rewrite`, pl.`description_short`,
                    m.`name` AS manufacturer
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . $idShop . '
                AND pl.`id_lang` = ' . $baseLangId . '
             LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
             WHERE p.`id_product` IN (' . $idList . ')'
        ) ?: [];

        $images = $this->imagesByProduct($ids, $idShop, $baseLangId);
        $stocks = $this->stocksByProduct($ids, $idShop);
        $combos = $this->combinationsByProduct($ids, $idShop);
        $categories = $this->categoryNames($idShop, $baseLangId);
        $productCats = $this->productCategories($ids);
        $translations = $this->translations($ids, $idShop, array_keys($langs));

        $docs = [];
        foreach ($products as $row) {
            $idProduct = (int) $row['id_product'];
            $name = trim((string) ($row['name'] ?? ''));
            if ('' === $name) {
                continue; // products without a name are never sent
            }
            $catNames = [];
            foreach ($productCats[$idProduct] ?? [] as $idCat) {
                if (!empty($categories[$idCat])) {
                    $catNames[] = $categories[$idCat];
                }
            }
            $doc = $this->productDoc(
                $row,
                (string) $idProduct,
                null,
                $images[$idProduct] ?? [],
                $stocks[$idProduct][0] ?? 0,
                array_values(array_unique($catNames)),
                $translations[$idProduct] ?? [],
                $langs,
                $idShop,
                $baseLangId
            );
            if (null !== $doc) {
                $docs[] = $doc;
            }
            // Combination docs reference the parent id.
            foreach ($combos[$idProduct] ?? [] as $combo) {
                $comboDoc = $this->productDoc(
                    $row,
                    self::plunkId($idProduct, (int) $combo['id_product_attribute']),
                    $combo,
                    $images[$idProduct] ?? [],
                    (int) $combo['quantity'],
                    array_values(array_unique($catNames)),
                    $translations[$idProduct] ?? [],
                    $langs,
                    $idShop,
                    $baseLangId
                );
                if (null !== $comboDoc) {
                    $comboDoc['parentId'] = (string) $idProduct;
                    $docs[] = $comboDoc;
                }
            }
        }

        return $docs;
    }

    /**
     * Lightweight PATCH document for one Plunk product id
     * ("<id_product>" or "<id_product>-<id_product_attribute>").
     *
     * @return array{id:string,price:float,salePrice:?float,available:bool,quantity:int}|null
     */
    public function buildPatch(string $plunkId, int $idShop): ?array
    {
        [$idProduct, $idAttribute] = $this->splitPlunkId($plunkId);
        if ($idProduct <= 0) {
            return null;
        }
        $db = \Db::getInstance();
        $row = $db->getRow(
            'SELECT ps.`active`, ps.`visibility`, p.`out_of_stock`,
                    sa.`quantity`
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.`id_product` = p.`id_product`
                AND sa.`id_product_attribute` = ' . $idAttribute . '
                AND sa.`id_shop` = ' . $idShop . '
             WHERE p.`id_product` = ' . $idProduct
        );
        if (!is_array($row)) {
            return null;
        }

        $quantity = (int) ($row['quantity'] ?? 0);
        $price = \Product::getPriceStatic($idProduct, true, $idAttribute, 2, null, false, false);
        $sale = \Product::getPriceStatic($idProduct, true, $idAttribute, 2, null, false, true);

        return [
            'id' => $plunkId,
            'price' => $this->money($price),
            'salePrice' => ($sale > 0 && $sale < $price) ? $this->money($sale) : null,
            'available' => $this->isAvailable($row, $quantity),
            'quantity' => max(0, $quantity),
        ];
    }

    /**
     * All Plunk ids for a product (parent + combos) - used by delete flows.
     *
     * @return string[]
     */
    public function plunkIds(int $idProduct, int $idShop): array
    {
        $ids = [(string) $idProduct];
        $combos = $this->combinationsByProduct([$idProduct], $idShop);
        foreach ($combos[$idProduct] ?? [] as $combo) {
            $ids[] = self::plunkId($idProduct, (int) $combo['id_product_attribute']);
        }

        return $ids;
    }

    /**
     * @return array{0:int,1:int} [id_product, id_product_attribute]
     */
    public function splitPlunkId(string $plunkId): array
    {
        if (preg_match('/^(\d+)-(\d+)$/', $plunkId, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) $plunkId, 0];
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $combo
     * @param string[] $images
     * @param string[] $categoryNames resolved LT category names
     * @param array<int, array{name:string,link_rewrite:string,description_short:string}> $translations
     * @param array<int, string> $langs id_lang => iso for translation targets
     *
     * @return array<string, mixed>|null
     */
    private function productDoc(
        array $row,
        string $plunkId,
        ?array $combo,
        array $images,
        int $quantity,
        array $categoryNames,
        array $translations,
        array $langs,
        int $idShop,
        int $baseLangId,
    ): ?array {
        $idProduct = (int) $row['id_product'];
        $idAttribute = null !== $combo ? (int) $combo['id_product_attribute'] : 0;
        $precision = 2;
        $price = \Product::getPriceStatic($idProduct, true, $idAttribute, $precision, null, false, false);
        $sale = \Product::getPriceStatic($idProduct, true, $idAttribute, $precision, null, false, true);

        $url = \Context::getContext()->link->getProductLink($idProduct, null, null, null, $baseLangId);
        if ($idAttribute > 0) {
            $url .= '#/' . $idAttribute;
        }
        if (!preg_match('#^https://#', $url)) {
            return null; // URLs must be https
        }

        $doc = [
            'id' => $plunkId,
            'sku' => '' !== (string) ($combo['reference'] ?? '') ? (string) $combo['reference'] : (string) ($row['reference'] ?? ''),
            'name' => (string) $row['name'],
            'description' => $this->cleanDescription((string) ($row['description_short'] ?? '')),
            'url' => $url,
            'imageUrl' => $images[0] ?? null,
            'images' => array_slice($images, 0, 5),
            'price' => $this->money($price),
            'salePrice' => ($sale > 0 && $sale < $price) ? $this->money($sale) : null,
            'currency' => 'EUR',
            'available' => $this->isAvailable($row, $quantity),
            'quantity' => max(0, $quantity),
            'brand' => (string) ($row['manufacturer'] ?? ''),
            'categories' => $categoryNames,
        ];

        // Translations for each target locale (skip id_lang 5, skip base).
        $tr = [];
        foreach ($langs as $idLang => $iso) {
            $t = $translations[$idLang] ?? null;
            if (is_array($t) && '' !== trim((string) $t['name'])) {
                $trUrl = \Context::getContext()->link->getProductLink($idProduct, null, null, null, (int) $idLang);
                $tr[$iso] = [
                    'name' => (string) $t['name'],
                    'url' => preg_match('#^https://#', $trUrl) ? $trUrl : $url,
                    'description' => $this->cleanDescription((string) $t['description_short']),
                ];
            }
        }
        if ($tr) {
            $doc['translations'] = $tr;
        }

        return $doc;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isAvailable(array $row, int $quantity): bool
    {
        $outOfStockAllowed = in_array((int) ($row['out_of_stock'] ?? 0), [1, 2], true); // allow, or shop default

        return 1 === (int) ($row['active'] ?? 0)
            && 'none' !== (string) ($row['visibility'] ?? 'both')
            && ($quantity > 0 || $outOfStockAllowed);
    }

    private function cleanDescription(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        return mb_substr($text, 0, 1000);
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function baseLangId(int $idShop): int
    {
        foreach (\Language::getLanguages(true, $idShop) as $lang) {
            if ('lt' === strtolower((string) ($lang['iso_code'] ?? '')) && !in_array((int) $lang['id_lang'], self::SKIP_LANG_IDS, true)) {
                return (int) $lang['id_lang'];
            }
        }

        return (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * @return array<int, string> id_lang => iso for translation locales
     */
    private function translationLangIds(int $idShop): array
    {
        $out = [];
        foreach (\Language::getLanguages(true, $idShop) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $iso = strtolower((string) ($lang['iso_code'] ?? ''));
            if (in_array($idLang, self::SKIP_LANG_IDS, true)) {
                continue;
            }
            if (in_array($iso, self::TRANSLATION_LOCALES, true) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $iso)) {
                $out[$idLang] = $iso;
            }
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string[]> id_product => image URLs (cover first)
     */
    private function imagesByProduct(array $ids, int $idShop, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT ish.`id_product`, i.`id_image`, pl.`link_rewrite`, ish.`cover`, i.`position`
             FROM `' . _DB_PREFIX_ . 'image` i
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = ish.`id_product` AND pl.`id_shop` = ' . $idShop . '
                AND pl.`id_lang` = ' . $idLang . '
             WHERE ish.`id_product` IN (' . implode(',', $ids) . ')
             ORDER BY ish.`cover` DESC, i.`position` ASC'
        ) ?: [];

        $out = [];
        $link = \Context::getContext()->link;
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            if (empty($row['link_rewrite']) || count($out[$idProduct] ?? []) >= 5) {
                continue;
            }
            $url = $link->getImageLink((string) $row['link_rewrite'], $idProduct . '-' . (int) $row['id_image'], 'large_default');
            if (preg_match('#^https://#', $url)) {
                $out[$idProduct][] = $url;
            }
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<int, int>> id_product => [id_product_attribute => quantity]
     */
    private function stocksByProduct(array $ids, int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_product`, `id_product_attribute`, `quantity`
             FROM `' . _DB_PREFIX_ . 'stock_available`
             WHERE `id_product` IN (' . implode(',', $ids) . ')
               AND `id_shop` = ' . $idShop
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_product']][(int) $row['id_product_attribute']] = (int) $row['quantity'];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<int, array<string, mixed>>> id_product => combos
     */
    private function combinationsByProduct(array $ids, int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pa.`id_product`, pa.`id_product_attribute`, pa.`reference`,
                    sa.`quantity`
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                ON pas.`id_product_attribute` = pa.`id_product_attribute`
                AND pas.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.`id_product` = pa.`id_product`
                AND sa.`id_product_attribute` = pa.`id_product_attribute`
                AND sa.`id_shop` = ' . $idShop . '
             WHERE pa.`id_product` IN (' . implode(',', $ids) . ')'
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_product']][] = $row;
        }

        return $out;
    }

    /**
     * id_category => LT name (excluding root/home handled by caller list).
     *
     * @return array<int, string>
     */
    private function categoryNames(int $idShop, int $idLang): array
    {
        static $cache = [];
        if (isset($cache[$idShop . ':' . $idLang])) {
            return $cache[$idShop . ':' . $idLang];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT c.`id_category`, cl.`name`, c.`id_parent`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop . '
                AND cl.`id_lang` = ' . $idLang . '
             WHERE c.`active` = 1 AND c.`id_parent` > 0'
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_category']] = (string) $row['name'];
        }

        return $cache[$idShop . ':' . $idLang] = $map;
    }

    /**
     * Category ids per product (Root/Home excluded via id_parent > 0).
     *
     * @param int[] $ids
     *
     * @return array<int, int[]> id_product => id_category[]
     */
    private function productCategories(array $ids): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT cp.`id_product`, cp.`id_category`
             FROM `' . _DB_PREFIX_ . 'category_product` cp
             INNER JOIN `' . _DB_PREFIX_ . 'category` c ON c.`id_category` = cp.`id_category`
               AND c.`active` = 1 AND c.`id_parent` > 0
             WHERE cp.`id_product` IN (' . implode(',', $ids) . ')'
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_product']][] = (int) $row['id_category'];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @param int[] $langIds
     *
     * @return array<int, array<int, array{name:string,link_rewrite:string,description_short:string}>>
     */
    private function translations(array $ids, int $idShop, array $langIds): array
    {
        if (!$langIds) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_product`, `id_lang`, `name`, `link_rewrite`, `description_short`
             FROM `' . _DB_PREFIX_ . 'product_lang`
             WHERE `id_product` IN (' . implode(',', $ids) . ')
               AND `id_shop` = ' . $idShop . '
               AND `id_lang` IN (' . implode(',', array_map('intval', $langIds)) . ')'
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_product']][(int) $row['id_lang']] = $row;
        }

        return $out;
    }
}
