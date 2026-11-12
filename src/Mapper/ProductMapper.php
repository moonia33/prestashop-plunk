<?php

/**
 * Builds the canonical product JSON used by events, the catalog feed and
 * the live-products endpoint. Feed pages use one indexed SQL query plus
 * per-page price computation (Product::getPriceStatic) - never one
 * `new Product()` per row.
 */

namespace PlunkConnect\Mapper;

final class ProductMapper
{
    private const FEED_MAX_LIMIT = 500;
    private const LIVE_MAX_IDS = 50;

    /**
     * One catalog feed page, cursor-paginated by id_product.
     *
     * @return array{items:array<int,array>,nextCursor:?int}
     */
    public function feed(int $idShop, int $idLang, int $cursor = 0, int $limit = 500, ?string $updatedSince = null): array
    {
        $limit = max(1, min($limit, self::FEED_MAX_LIMIT));
        $where = 'p.`id_product` > ' . $cursor . ' AND ps.`active` = 1';
        if (null !== $updatedSince && ($ts = strtotime($updatedSince)) !== false) {
            $where .= ' AND p.`date_upd` >= \'' . pSQL(date('Y-m-d H:i:s', $ts)) . '\'';
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`ean13`, p.`reference`, p.`id_category_default`,
                    p.`id_manufacturer`, p.`date_upd`, pl.`name`, pl.`link_rewrite`,
                    sa.`quantity`, m.`name` AS manufacturer, i.`id_image`
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . $idShop . '
                AND pl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0
                AND sa.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
             LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON ish.`id_product` = p.`id_product` AND ish.`id_shop` = ' . $idShop . '
                AND ish.`cover` = 1
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON i.`id_image` = ish.`id_image`
             WHERE ' . $where . '
             ORDER BY p.`id_product` ASC
             LIMIT ' . $limit
        ) ?: [];

        $items = [];
        $lastId = $cursor;
        foreach ($rows as $row) {
            $lastId = (int) $row['id_product'];
            $items[] = $this->mapRow($row, $idShop, $idLang);
        }

        return [
            'items' => $items,
            'nextCursor' => count($rows) === $limit ? $lastId : null,
        ];
    }

    /**
     * Live data for explicit product ids (max 50) - used just before an
     * email send so prices/stock are current. Missing/inactive products are
     * returned with `"available": false`.
     */
    public function liveByIds(array $ids, int $idShop, int $idLang): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_slice(array_filter($ids, fn ($id) => $id > 0), 0, self::LIVE_MAX_IDS);
        if (!$ids) {
            return [];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`ean13`, p.`reference`, p.`id_category_default`,
                    p.`id_manufacturer`, p.`date_upd`, pl.`name`, pl.`link_rewrite`,
                    sa.`quantity`, m.`name` AS manufacturer, i.`id_image`,
                    ps.`active`
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . $idShop . '
                AND pl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0
                AND sa.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
             LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON ish.`id_product` = p.`id_product` AND ish.`id_shop` = ' . $idShop . '
                AND ish.`cover` = 1
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON i.`id_image` = ish.`id_image`
             WHERE p.`id_product` IN (' . implode(',', $ids) . ')'
        ) ?: [];

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id_product']] = $row;
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && 1 === (int) $byId[$id]['active']) {
                $out[] = $this->mapRow($byId[$id], $idShop, $idLang);
            } else {
                $out[] = ['id' => $id, 'productId' => (string) $id, 'available' => false];
            }
        }

        return $out;
    }

    /**
     * Single product for behavioural events (same shape as the feed).
     */
    public function mapProduct(int $idProduct, int $idShop, int $idLang, int $idProductAttribute = 0): ?array
    {
        $items = $this->liveByIds([$idProduct], $idShop, $idLang);
        $product = $items[0] ?? null;
        if (!is_array($product) || isset($product['available'])) {
            return null;
        }
        if ($idProductAttribute > 0) {
            $product['combinationId'] = $idProductAttribute;
        }
        // Plunk-wide id format: "<id_product>" or "<id_product>-<attr>".
        $product['productId'] = $idProduct . ($idProductAttribute > 0 ? '-' . $idProductAttribute : '');

        return $product;
    }

    /**
     * Cart/order line item -> canonical product entry with quantity.
     */
    public function mapLine(array $productRow, int $idShop, int $idLang): ?array
    {
        $idProduct = (int) ($productRow['id_product'] ?? 0);
        if ($idProduct <= 0) {
            return null;
        }
        $product = $this->mapProduct($idProduct, $idShop, $idLang, (int) ($productRow['id_product_attribute'] ?? 0));
        if (!$product) {
            return null;
        }
        $product['quantity'] = (int) ($productRow['cart_quantity'] ?? $productRow['product_quantity'] ?? 1);
        if (isset($productRow['total_wt'])) {
            $product['lineTotal'] = $this->round((float) $productRow['total_wt']);
        }

        return $product;
    }

    private function mapRow(array $row, int $idShop, int $idLang): array
    {
        $idProduct = (int) $row['id_product'];
        $precision = \Context::getContext()->getComputingPrecision();
        $price = \Product::getPriceStatic($idProduct, true, 0, $precision, null, false, true);
        $regular = \Product::getPriceStatic($idProduct, true, 0, $precision, null, false, false);
        $quantity = (int) ($row['quantity'] ?? 0);
        $categoryId = (int) ($row['id_category_default'] ?? 0);

        $image = null;
        if (!empty($row['id_image']) && !empty($row['link_rewrite'])) {
            $image = \Context::getContext()->link->getImageLink(
                (string) $row['link_rewrite'],
                $idProduct . '-' . (int) $row['id_image'],
                'large_default'
            );
        }

        $discountPercent = null;
        if ($regular > 0 && $price < $regular) {
            $discountPercent = (int) round((1 - $price / $regular) * 100);
        }

        return [
            'id' => $idProduct,
            'productId' => (string) $idProduct,
            'combinationId' => 0,
            'sku' => (string) ($row['reference'] ?? ''),
            'ean' => (string) ($row['ean13'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'url' => \Context::getContext()->link->getProductLink($idProduct, null, null, null, $idLang),
            'image' => $image,
            'price' => $this->round((float) $price),
            'regularPrice' => $this->round((float) $regular),
            'discountPercent' => $discountPercent,
            'currency' => (string) \Context::getContext()->currency->iso_code,
            'inStock' => $quantity > 0,
            'quantity' => max(0, $quantity),
            'categoryId' => $categoryId,
            'categoryPath' => $this->categoryPath($categoryId, $idShop, $idLang),
            'manufacturer' => (string) ($row['manufacturer'] ?? ''),
            'updatedAt' => !empty($row['date_upd']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $row['date_upd'])) : null,
        ];
    }

    /**
     * Category breadcrumb names, root-last. Iterative, depth-capped.
     */
    public function categoryPath(int $idCategory, int $idShop, int $idLang, int $maxDepth = 5): array
    {
        $path = [];
        $seen = [];
        $current = $idCategory;
        while ($current > 0 && count($path) < $maxDepth && !isset($seen[$current])) {
            $seen[$current] = true;
            $row = \Db::getInstance()->getRow(
                'SELECT c.`id_parent`, cl.`name`
                 FROM `' . _DB_PREFIX_ . 'category` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop . '
                    AND cl.`id_lang` = ' . $idLang . '
                 WHERE c.`id_category` = ' . $current
            );
            if (!$row) {
                break;
            }
            if (!empty($row['name'])) {
                $path[] = (string) $row['name'];
            }
            $current = (int) $row['id_parent'];
        }

        return array_reverse($path);
    }

    public function mapCategory(int $idCategory, int $idShop, int $idLang): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT c.`id_category`, cl.`name`, cl.`link_rewrite`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop . '
                AND cl.`id_lang` = ' . $idLang . '
             WHERE c.`id_category` = ' . $idCategory . ' AND c.`active` = 1'
        );
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id_category'],
            'name' => (string) ($row['name'] ?? ''),
            'url' => \Context::getContext()->link->getCategoryLink($idCategory, null, $idLang),
        ];
    }

    /**
     * Category feed: all active categories for the shop.
     */
    public function categoriesFeed(int $idShop, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`date_upd`, cl.`name`, cl.`link_rewrite`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop . '
                AND cl.`id_lang` = ' . $idLang . '
             WHERE c.`active` = 1
             ORDER BY c.`id_category` ASC'
        ) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id_category'],
                'parentId' => (int) $row['id_parent'],
                'name' => (string) ($row['name'] ?? ''),
                'url' => \Context::getContext()->link->getCategoryLink((int) $row['id_category'], null, $idLang),
                'updatedAt' => !empty($row['date_upd']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $row['date_upd'])) : null,
            ];
        }

        return $items;
    }

    private function round(float $value): float
    {
        return \Tools::ps_round($value, \Context::getContext()->getComputingPrecision());
    }
}
