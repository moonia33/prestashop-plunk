<?php

/**
 * 1.4.0 - Plunk signup forms: registers `displayHeader`, which prints the
 * forms embed script when FEAT_FORMS is on (off by default).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0(Plunkconnect $module): bool
{
    $module->enableServicesYaml();

    return $module->registerHook('displayHeader')
        && (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.4.0');
}
