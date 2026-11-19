<?php

/**
 * 1.7.0 - coupon rule groups: `plunk_coupon.rule_group`. A new coupon of a
 * group deactivates the customer's earlier unused coupons of the same group.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_0(Plunkconnect $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'plunk_coupon';
    $hasColumn = (bool) $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE \'rule_group\'');
    if (!$hasColumn) {
        $ok = $db->execute('ALTER TABLE `' . $table . '`
            ADD COLUMN `rule_group` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `rule`,
            ADD KEY `email_group` (`email`, `rule_group`)');
        if (!$ok) {
            return false;
        }
    }

    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.7.0');
}
