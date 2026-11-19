<?php

/**
 * 1.7.1 - widen `plunk_outbox.endpoint` to 32 chars: `contacts_ifexists`
 * (17) was truncated by VARCHAR(16) and every orders backfill row went dead.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_1(Plunkconnect $module): bool
{
    $table = _DB_PREFIX_ . 'plunk_outbox';
    $db = Db::getInstance();
    if (!$db->execute('ALTER TABLE `' . $table . '` MODIFY `endpoint` VARCHAR(32) NOT NULL')) {
        return false;
    }
    $db->execute('UPDATE `' . $table . '` SET `endpoint` = \'contacts_ifexists\' WHERE `endpoint` = \'contacts_ifexist\'');
    $db->execute('UPDATE `' . $table . '` SET `status` = \'pending\', `attempts` = 0, `last_error` = NULL
        WHERE `status` = \'dead\' AND `endpoint` = \'contacts_ifexists\' AND `last_error` LIKE \'unknown endpoint%\'');

    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.7.1');
}
