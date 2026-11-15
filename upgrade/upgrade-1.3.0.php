<?php

/**
 * 1.3.0 - catalog ids become SKU (PS reference) or "PS-<id>[-<attr>]"
 * (see PlunkConnect\Catalog\CatalogIds). Ids already sent to Plunk use
 * the old "<id>[-<attr>]" format: after upgrading, delete them from the
 * Plunk catalog and run `plunkconnect:catalog:sync --full`.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0(Plunkconnect $module): bool
{
    $ok = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_catalog_id` (
            `id_shop` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `external_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_shop`, `id_product`, `id_product_attribute`),
            UNIQUE KEY `shop_external_id` (`id_shop`, `external_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    return $ok && (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.3.0');
}
