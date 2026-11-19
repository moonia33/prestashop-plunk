<?php

/**
 * 1.8.0 - newsletter form protection (FEAT_NEWSLETTER_TURNSTILE, off by
 * default): Turnstile widget via `displayNewsletterRegistration`.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_8_0(Plunkconnect $module): bool
{
    PlunkConnect\Config::set(PlunkConnect\Config::FEAT_NEWSLETTER_TURNSTILE, 0);
    if (!$module->registerHook('displayNewsletterRegistration')) {
        return false;
    }

    return (bool) Configuration::updateValue(PlunkConnect\Config::DB_VERSION, '1.8.0');
}
