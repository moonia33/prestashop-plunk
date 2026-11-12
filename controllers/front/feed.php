<?php

/**
 * GET /module/plunkconnect/feed/products?lang=lt&cursor=&limit=&updated_since=
 * GET /module/plunkconnect/feed/categories?lang=lt
 * Auth: Bearer <feed token>. Cache-Control: no-store.
 */
require_once dirname(__DIR__, 2) . '/autoload.php';

use PlunkConnect\Config;
use PlunkConnect\Mapper\ProductMapper;
use PlunkConnect\Service\RequestAuth;

class PlunkconnectFeedModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        if (!Config::getBool(Config::FEAT_FEED) || !RequestAuth::checkBearer(Config::FEED_TOKEN)) {
            $this->respond(401, ['error' => 'unauthorized']);
        }

        $type = (string) Tools::getValue('type', 'products');
        $idShop = (int) Context::getContext()->shop->id;
        $idLang = $this->langId();

        $mapper = new ProductMapper();
        if ('categories' === $type) {
            $this->respond(200, ['items' => $mapper->categoriesFeed($idShop, $idLang), 'nextCursor' => null]);
        }

        $cursor = max(0, (int) Tools::getValue('cursor', 0));
        $limit = (int) Tools::getValue('limit', 500);
        $since = Tools::getValue('updated_since');
        $page = $mapper->feed($idShop, $idLang, $cursor, $limit, is_string($since) ? $since : null);

        $this->respond(200, $page);
    }

    private function langId(): int
    {
        $iso = strtolower((string) Tools::getValue('lang'));
        if ('' !== $iso) {
            $id = (int) Language::getIdByIso($iso);
            if ($id > 0) {
                return $id;
            }
        }

        return (int) Context::getContext()->language->id;
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        exit(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
