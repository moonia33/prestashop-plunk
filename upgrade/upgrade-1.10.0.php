<?php

/**
 * 1.10.0 - `plunk_winback`: one row per (email, last order) handled by
 * `plunkconnect:winback`, so each customer gets the series once per last order.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_10_0(Plunkconnect $module): bool
{
    if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_winback` (
    `id_plunk_winback` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `email` VARCHAR(255) NOT NULL,
    `last_order_at` DATETIME NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_plunk_winback`),
    UNIQUE KEY `email_order` (`email`, `id_shop`, `last_order_at`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'')) {
        return false;
    }

    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.10.0');
}
