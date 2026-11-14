<?php

/**
 * GET /module/plunkconnect/products?ids=SKU1,PS-2,3&lang=lt (max 50 ids)
 * Ids are Plunk catalog ids (SKU or PS-<id>[-<attr>]) or id_product.
 * Live price + stock, fetched just before an email is sent. Inactive or
 * unknown products are returned as {"id":N,"available":false}.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Catalog\CatalogIds;
use PlunkConnect\Config;
use PlunkConnect\Mapper\ProductMapper;
use PlunkConnect\Service\RequestAuth;

class PlunkconnectProductsModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if (!Config::getBool(Config::FEAT_FEED) || !RequestAuth::checkBearer(Config::FEED_TOKEN)) {
            $this->respond(401, ['error' => 'unauthorized']);
        }

        $raw = array_filter(array_map('trim', explode(',', (string) Tools::getValue('ids'))), fn ($v) => '' !== $v);
        if (count($raw) > 50) {
            $this->respond(400, ['error' => 'too_many_ids']);
        }
        $units = CatalogIds::resolve($raw, (int) Context::getContext()->shop->id);
        $ids = array_values(array_unique(array_map(fn ($unit) => $unit[0], $units)));

        $iso = strtolower((string) Tools::getValue('lang'));
        $idLang = '' !== $iso && ($id = (int) Language::getIdByIso($iso)) > 0
            ? $id
            : (int) Context::getContext()->language->id;

        $items = (new ProductMapper())->liveByIds($ids, (int) Context::getContext()->shop->id, $idLang);

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
