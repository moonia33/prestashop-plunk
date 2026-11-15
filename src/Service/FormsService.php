<?php

/**
 * Plunk signup forms: prints the embed script and the visitor context it
 * needs (`window.PlunkForms`) into the page head. Forms, their texts and
 * display rules are managed in Plunk; nothing is stored in PrestaShop.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Mapper\LocaleMapper;

final class FormsService
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    /**
     * `displayHeader` output: empty unless forms are enabled and the shop is connected.
     */
    public static function headerHtml(): string
    {
        if (!Config::getBool(Config::FEAT_FORMS) || IdentityService::isBot()) {
            return '';
        }
        $apiUrl = (string) Config::get(Config::API_URL);
        $publicKey = (string) Config::get(Config::PUBLIC_KEY);
        if ('' === $apiUrl || '' === $publicKey) {
            return '';
        }

        $context = \Context::getContext();
        $idLang = (int) ($context->language->id ?? 0);
        $customer = $context->customer ?? null;
        $loggedIn = $customer instanceof \Customer && $customer->id && $customer->isLogged();

        return self::render($apiUrl, [
            'key' => $publicKey,
            'locale' => LocaleMapper::forLang($idLang),
            'subscribed' => $loggedIn && (bool) $customer->newsletter,
            'customer' => $loggedIn && self::hasValidOrder((int) $customer->id),
        ]);
    }

    /**
     * @param array{key: string, locale: string, subscribed: bool, customer: bool} $config
     */
    public static function render(string $apiUrl, array $config): string
    {
        $src = rtrim($apiUrl, '/') . '/forms/embed.js';
        if (!preg_match('#^https?://#i', $src)) {
            return '';
        }
        $json = json_encode($config, self::JSON_FLAGS);
        if (false === $json) {
            return '';
        }

        return '<script>window.PlunkForms=' . $json . ';</script>'
            . '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" async></script>';
    }

    private static function hasValidOrder(int $idCustomer): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_customer` = ' . $idCustomer . ' AND `valid` = 1'
        );
    }
}
