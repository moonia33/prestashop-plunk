<?php

declare(strict_types=1);
/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * @author  TV Parkas
 * @license MIT
 */
$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `_DB_PREFIX_plunk_outbox`;';

return $sql;
