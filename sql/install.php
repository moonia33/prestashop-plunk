<?php

declare(strict_types=1);
/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * @author  TV Parkas
 * @license MIT
 */
$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `_DB_PREFIX_plunk_outbox` (
    `id_outbox` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `id_shop` int(11) unsigned NOT NULL DEFAULT 1,
    `uuid` char(36) NOT NULL,
    `endpoint` varchar(16) NOT NULL,
    `dedupe_key` varchar(191) DEFAULT NULL,
    `payload` mediumtext NOT NULL,
    `status` varchar(16) NOT NULL DEFAULT \'pending\',
    `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
    `next_attempt_at` datetime NOT NULL,
    `locked_by` varchar(64) DEFAULT NULL,
    `last_error` varchar(500) DEFAULT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_outbox`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `status_next_attempt` (`status`, `next_attempt_at`),
    KEY `dedupe_key` (`dedupe_key`),
    KEY `locked_by` (`locked_by`)
) ENGINE=_MYSQL_ENGINE_ DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

return $sql;
