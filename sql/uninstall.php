<?php

/**
 * Module uninstall SQL. Drops the outbox table; Plunk-side data is untouched.
 */
$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'plunk_outbox`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'plunk_coupon`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'plunk_winback`';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'plunk_catalog_id`';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
