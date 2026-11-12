<?php

/**
 * Schema migrations live here, one file per module version.
 * Naming: upgrade-<version>.php; the function must return bool.
 *
 * This file is the template for the first real migration.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0(Plunkconnect $module): bool
{
    return (bool) Configuration::updateValue(
        PlunkConnect\Config::DB_VERSION,
        '1.1.0'
    );
}
