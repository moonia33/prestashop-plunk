<?php

/**
 * 1.6.0 - `identify` front controller: flushes anonymous views after a
 * Plunk form signup. No hooks or schema changes.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0(Plunkconnect $module): bool
{
    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.6.0');
}
