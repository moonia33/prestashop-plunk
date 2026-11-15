<?php

/**
 * 1.5.0 - contact form protection: registers `actionFrontControllerInitAfter`
 * (Siteverify + limits) and `displayGDPRConsent` (widget). Off by default.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_5_0(Plunkconnect $module): bool
{
    PlunkConnect\Config::set(PlunkConnect\Config::FEAT_CONTACT_TURNSTILE, 0);

    return $module->registerHook(['actionFrontControllerInitAfter', 'displayGDPRConsent'])
        && (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.5.0');
}
