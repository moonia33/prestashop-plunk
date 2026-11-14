<?php

/**
 * Catalog sync queueing (spec 5.4/5). Everything goes through the outbox -
 * this service never calls the Plunk API itself.
 *
 * - full sync: all products, batches of 200 id_product per upsert row
 * - incremental (every 15 min): date_upd > last sync -> upsert rows;
 *   specific_price rows that activated/expired since last run -> patch rows
 *   (sale price can change without touching date_upd)
 */

namespace PlunkConnect\Service;

use PlunkConnect\Catalog\CatalogIds;
use PlunkConnect\Config;
use PlunkConnect\Outbox\Outbox;

final class CatalogSyncService
{
    private const UPSERT_BATCH = 200;

    /**
     * Queue upsert rows for every active product of the shop.
     *
     * @return array{batches:int,products:int}
     */
    public static function queueFull(int $idShop, int $fromId = 0): array
    {
        $batches = 0;
        $products = 0;
        $lastId = max(0, $fromId);
        while (true) {
            $ids = array_map('intval', array_column(\Db::getInstance()->executeS(
                'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
                 INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                    ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
                 WHERE p.`id_product` > ' . $lastId . '
                 ORDER BY p.`id_product` ASC
                 LIMIT ' . self::UPSERT_BATCH
            ) ?: [], 'id_product'));
            if (!$ids) {
                break;
            }
            Outbox::enqueue('catalog.upsert', ['ids' => $ids]);
            $products += count($ids);
            ++$batches;
            $lastId = max($ids);
        }
        Config::set(Config::CATALOG_FULL_SYNC_AT, date('Y-m-d H:i:s'));
        Config::set(Config::CATALOG_LAST_SYNC, date('Y-m-d H:i:s'));

        return ['batches' => $batches, 'products' => $products];
    }

    /**
     * Incremental sync since CATALOG_LAST_SYNC.
     *
     * @return array{upsert:int,patch:int}
     */
    public static function queueIncremental(int $idShop): array
    {
        $since = (string) Config::get(Config::CATALOG_LAST_SYNC, '1970-01-01 00:00:00', $idShop);
        $now = date('Y-m-d H:i:s');

        // Products changed since last sync -> full field refresh.
        $changed = array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE p.`date_upd` > \'' . pSQL($since) . '\'
             ORDER BY p.`id_product` ASC
             LIMIT 5000'
        ) ?: [], 'id_product'));

        $upsert = 0;
        foreach (array_chunk($changed, self::UPSERT_BATCH) as $ids) {
            Outbox::enqueue('catalog.upsert', ['ids' => $ids]);
            ++$upsert;
        }

        // Specific prices that activated or expired since last sync: the
        // sale price flips without touching product.date_upd -> patch.
        $spIds = array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT DISTINCT sp.`id_product` FROM `' . _DB_PREFIX_ . 'specific_price` sp
             WHERE (sp.`from` BETWEEN \'' . pSQL($since) . '\' AND \'' . pSQL($now) . '\'
                OR sp.`to` BETWEEN \'' . pSQL($since) . '\' AND \'' . pSQL($now) . '\')
             LIMIT 2000'
        ) ?: [], 'id_product'));

        $patch = 0;
        $spIds = array_values(array_diff($spIds, $changed)); // upserts already cover the rest
        foreach (array_chunk($spIds, self::UPSERT_BATCH) as $chunk) {
            foreach (CatalogIds::forProducts($chunk, $idShop) as $units) {
                foreach ($units as $plunkId) {
                    Outbox::enqueue('catalog.patch', ['id' => $plunkId], 'catalog.patch:' . $plunkId, 60);
                }
                ++$patch;
            }
        }

        Config::set(Config::CATALOG_LAST_SYNC, $now);

        return ['upsert' => $upsert, 'patch' => $patch];
    }
}
