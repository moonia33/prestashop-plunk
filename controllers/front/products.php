<?php

/**
 * GET /module/plunkconnect/products?ids=1,2,3&lang=lt (max 50 ids)
 * Live price + stock, fetched just before an email is sent. Inactive or
 * unknown products are returned as {"id":N,"available":false}.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

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

        $ids = array_filter(array_map('intval', explode(',', (string) Tools::getValue('ids'))));
        if (count($ids) > 50) {
            $this->respond(400, ['error' => 'too_many_ids']);
        }

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
