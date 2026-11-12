<?php

/**
 * Module install SQL. One indexed outbox table - no locks, no heavy queries.
 */
$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'plunk_outbox` (
    `id_outbox` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
    `uuid` VARCHAR(36) NOT NULL,
    `endpoint` VARCHAR(16) NOT NULL,
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

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
