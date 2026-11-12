<?php

/**
 * 1.2.0 - catalog sync (spec 5.4): new hook registrations, feature flag
 * and config keys. The plunk_coupon table already ships in install.sql;
 * create it here too for shops upgrading from 1.0.x.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0(Plunkconnect $module): bool
{
    $module->enableServicesYaml();

    $hooks = [
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectProductDeleteBefore',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
    ];
    foreach ($hooks as $hook) {
        if (!$module->registerHook($hook)) {
            return false;
        }
    }

    PlunkConnect\Config::set(PlunkConnect\Config::FEAT_CATALOG_SYNC, 1);
    PlunkConnect\Config::set(PlunkConnect\Config::COUPON_RULES, '{}');

    Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_coupon` (
            `id_plunk_coupon` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart_rule` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `email` VARCHAR(255) NOT NULL,
            `rule` VARCHAR(64) NOT NULL,
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
            KEY `date_exp` (`date_exp`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.2.0');
}
