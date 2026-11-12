<?php

/**
 * Single-use coupon (CartRule) creation for Plunk coupon workflows.
 * Replaces `newsmancoupons`.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Outbox\Outbox;

final class CouponService
{
    public const TYPES = ['percent', 'amount', 'free_shipping'];

    /**
     * Create a one-time CartRule restricted to the customer when they exist.
     *
     * @return array{code:string,validUntil:string}
     */
    public static function create(array $request): array
    {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        if (!\Validate::isEmail($email)) {
            throw new \InvalidArgumentException('invalid email');
        }
        $type = (string) ($request['type'] ?? 'percent');
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid type');
        }
        $value = (float) ($request['value'] ?? 0);
        $prefix = preg_replace('/[^A-Z0-9]/i', '', (string) ($request['prefix'] ?? 'PLK'));
        $validDays = max(1, min(365, (int) ($request['validDays'] ?? 30)));
        $minAmount = (float) ($request['minAmount'] ?? 0);
        $currencyIso = strtoupper((string) ($request['currency'] ?? ''));
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);

        $validUntil = date('Y-m-d H:i:s', strtotime('+' . $validDays . ' days'));
        $code = strtoupper($prefix) . '-' . strtoupper(bin2hex(random_bytes(4)));

        $rule = new \CartRule();
        $rule->name = [];
        foreach (\Language::getLanguages(true, $idShop) as $lang) {
            $rule->name[$lang['id_lang']] = 'Plunk ' . $code;
        }
        $rule->code = $code;
        $rule->description = 'Plunk generated coupon for ' . \PlunkConnect\Util\EmailMask::mask($email);
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->active = 1;
        $rule->highlight = 0;
        $rule->partial_use = 'amount' === $type ? 1 : 0;
        $rule->date_from = date('Y-m-d H:i:s');
        $rule->date_to = $validUntil;
        $rule->minimum_amount = $minAmount;
        $rule->minimum_amount_tax = 1;
        $rule->minimum_amount_currency = 0;
        $rule->minimum_amount_shipping = 0;

        if ('' !== $currencyIso && ($idCurrency = \Currency::getIdByIsoCode($currencyIso, $idShop))) {
            $rule->reduction_currency = (int) $idCurrency;
            $rule->minimum_amount_currency = (int) $idCurrency;
        }

        switch ($type) {
            case 'percent':
                $rule->reduction_percent = max(0, min(100, $value));
                break;
            case 'amount':
                $rule->reduction_amount = max(0, $value);
                $rule->reduction_tax = true;
                break;
            case 'free_shipping':
                $rule->free_shipping = true;
                break;
        }

        // Bind to the customer account when it exists - the code stays
        // usable by the address owner even if they check out as guest.
        $idCustomer = (int) \Customer::customerExists($email, true, true);
        if ($idCustomer > 0) {
            $rule->id_customer = $idCustomer;
        }

        if (!$rule->add()) {
            throw new \RuntimeException('CartRule creation failed');
        }

        // Persist the code on the contact so a workflow email sent after
        // this step can reference {{contact.lastCouponCode}}.
        Outbox::enqueue('contacts', [
            'email' => $email,
            'data' => [
                'lastCouponCode' => $code,
                'lastCouponValidUntil' => gmdate('Y-m-d\TH:i:s\Z', strtotime($validUntil)),
            ],
        ]);

        return ['code' => $code, 'validUntil' => gmdate('Y-m-d\TH:i:s\Z', strtotime($validUntil))];
    }
}
