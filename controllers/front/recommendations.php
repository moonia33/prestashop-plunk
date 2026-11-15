<?php

/**
 * GET /module/plunkconnect/recommendations?product_ids=SKU1,PS-2&lang=lt&limit=6
 * product_ids: Plunk catalog ids (SKU or PS-<id>[-<attr>]) or id_product.
 * Accessories of the given products first, then best sellers of the same
 * categories; in-stock only; products already passed in are excluded.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Catalog\CatalogIds;
use PlunkConnect\Config;
use PlunkConnect\Mapper\ProductMapper;
use PlunkConnect\Service\RequestAuth;

class PlunkconnectRecommendationsModuleFrontController extends ModuleFrontController
{
    private const MAX = 24;

    public function postProcess(): void
    {
        if (!Config::getBool(Config::FEAT_FEED) || !RequestAuth::checkBearer(Config::FEED_TOKEN)) {
            $this->respond(401, ['error' => 'unauthorized']);
        }

        $limit = max(1, min((int) Tools::getValue('limit', 6), self::MAX));
        $idShop = (int) Context::getContext()->shop->id;
        $raw = array_slice(array_filter(array_map('trim', explode(',', (string) Tools::getValue('product_ids'))), fn ($v) => '' !== $v), 0, 50);
        $inputIds = array_values(array_unique(array_map(fn ($unit) => $unit[0], CatalogIds::resolve($raw, $idShop))));
        $iso = strtolower((string) Tools::getValue('lang'));
        $idLang = '' !== $iso && ($id = (int) Language::getIdByIso($iso)) > 0
            ? $id
            : (int) Context::getContext()->language->id;

        $exclude = array_flip($inputIds);
        $candidates = [];

        // 1) Accessories (ps_accessory) of the requested products.
        if ($inputIds) {
            $rows = Db::getInstance()->executeS(
                'SELECT DISTINCT a.`id_product_2` AS id
                 FROM `' . _DB_PREFIX_ . 'accessory` a
                 INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                    ON ps.`id_product` = a.`id_product_2` AND ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1
                 WHERE a.`id_product_1` IN (' . implode(',', array_map('intval', $inputIds)) . ')
                 LIMIT ' . self::MAX
            ) ?: [];
            foreach ($rows as $row) {
                $candidates[] = (int) $row['id'];
            }
        }

        // 2) Best sellers of the same default categories.
        if (count($candidates) < $limit && $inputIds) {
            $cats = Db::getInstance()->executeS(
                'SELECT DISTINCT `id_category_default` FROM `' . _DB_PREFIX_ . 'product`
                 WHERE `id_product` IN (' . implode(',', array_map('intval', $inputIds)) . ')'
            ) ?: [];
            $catIds = array_map('intval', array_column($cats, 'id_category_default'));
            if ($catIds) {
                $rows = Db::getInstance()->executeS(
                    'SELECT ps.`id_product`, COALESCE(s.`quantity`, 0) AS sold
                     FROM `' . _DB_PREFIX_ . 'product_shop` ps
                     INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = ps.`id_product`
                     LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` s ON s.`id_product` = ps.`id_product`
                     WHERE ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1
                       AND p.`id_category_default` IN (' . implode(',', $catIds) . ')
                     ORDER BY sold DESC
                     LIMIT ' . self::MAX
                ) ?: [];
                foreach ($rows as $row) {
                    $candidates[] = (int) $row['id_product'];
                }
            }
        }

        // De-dupe, drop requested ids, cap at limit.
        $picked = [];
        foreach ($candidates as $id) {
            if (isset($exclude[$id]) || in_array($id, $picked, true)) {
                continue;
            }
            $picked[] = $id;
            if (count($picked) >= $limit) {
                break;
            }
        }

        // Keep only in-stock products.
        $items = array_values(array_filter(
            (new ProductMapper())->liveByIds($picked, $idShop, $idLang),
            fn ($p) => !isset($p['available']) && !empty($p['inStock'])
        ));

        $this->respond(200, ['items' => $items]);
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        exit(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
