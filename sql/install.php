<?php

/**
 * Module install SQL. One indexed outbox table - no locks, no heavy queries.
 */
$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_outbox` (
    `id_outbox` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `uuid` VARCHAR(36) NOT NULL,
    `endpoint` VARCHAR(32) NOT NULL,
    `dedupe_key` VARCHAR(191) DEFAULT NULL,
    `payload` MEDIUMTEXT NOT NULL,
    `status` ENUM(\'pending\',\'processing\',\'sent\',\'failed\',\'dead\') NOT NULL DEFAULT \'pending\',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME NOT NULL,
    `locked_by` VARCHAR(64) DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_outbox`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `status_next` (`status`, `next_attempt_at`),
    KEY `dedupe_key` (`dedupe_key`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_winback` (
    `id_plunk_winback` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `email` VARCHAR(255) NOT NULL,
    `last_order_at` DATETIME NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_plunk_winback`),
    UNIQUE KEY `email_order` (`email`, `id_shop`, `last_order_at`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_coupon` (
    `id_plunk_coupon` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_cart_rule` INT UNSIGNED NOT NULL,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `email` VARCHAR(255) NOT NULL,
    `rule` VARCHAR(64) NOT NULL,
    `rule_group` VARCHAR(64) NOT NULL DEFAULT \'\',
    `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
    `execution_id` VARCHAR(64) NOT NULL DEFAULT \'\',
    `code` VARCHAR(64) NOT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT \'percent\',
    `value` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `min_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `date_add` DATETIME NOT NULL,
    `date_exp` DATETIME NOT NULL,
    PRIMARY KEY (`id_plunk_coupon`),
    UNIQUE KEY `id_cart_rule` (`id_cart_rule`),
    UNIQUE KEY `code` (`code`),
    UNIQUE KEY `idem` (`email`, `rule`, `id_cart`, `execution_id`),
    KEY `email_rule` (`email`, `rule`),
    KEY `email_group` (`email`, `rule_group`),
    KEY `date_exp` (`date_exp`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_catalog_id` (
    `id_shop` INT UNSIGNED NOT NULL,
    `id_product` INT UNSIGNED NOT NULL,
    `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
    `external_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_shop`, `id_product`, `id_product_attribute`),
    UNIQUE KEY `shop_external_id` (`id_shop`, `external_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
