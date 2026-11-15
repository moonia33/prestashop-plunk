<?php

/**
 * Single-use coupon (CartRule) creation for Plunk coupon workflows.
 * Replaces `newsmancoupons`.
 *
 * Spec: `POST /module/plunkconnect/coupon` with `Authorization: Bearer
 * <coupon secret>`. The request only carries {email, rule, cartId |
 * executionId}; all coupon parameters (type, value, validDays, minAmount,
 * prefix, excludeSpecial) come from the module's COUPON_RULES config, never
 * from the request body.
 */

namespace PlunkConnect\Service;

use PlunkConnect\Config;
use PlunkConnect\Outbox\Outbox;

final class CouponService
{
    public const TYPES = ['percent', 'amount', 'free_shipping'];

    /** Max coupons issued per email+rule within a rolling 30-day window. */
    private const MAX_PER_RULE_30D = 3;

    /** Unambiguous alphabet: no 0/O/1/I/L. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Create (or reuse) a one-time CartRule.
     *
     * Idempotent on email + rule + cartId/executionId; a live (unused,
     * unexpired) coupon for the same email+rule is also reused.
     *
     * @return array{code:string,validUntil:string,value:float,type:string,minAmount:float}
     *
     * @throws \InvalidArgumentException on bad input / unknown rule (-> 422)
     * @throws \RuntimeException on rate limit ('rate_limited') or DB failure (-> 429/500)
     */
    public static function create(string $ruleName, string $email, int $cartId = 0, string $executionId = ''): array
    {
        $email = strtolower(trim($email));
        if (!\Validate::isEmail($email)) {
            throw new \InvalidArgumentException('invalid email');
        }
        $ruleName = trim($ruleName);
        $rules = Config::getJson(Config::COUPON_RULES);
        $rule = $rules[$ruleName] ?? null;
        if (!is_array($rule)) {
            throw new \InvalidArgumentException('unknown rule');
        }

        $idShop = (int) (\Context::getContext()->shop->id ?? 0);

        // Idempotence: identical request returns the previously issued coupon.
        $existing = self::findCoupon($email, $ruleName, $cartId, $executionId);
        if (null !== $existing) {
            return $existing;
        }
        // Reuse a still-live coupon issued for another cart/execution.
        $live = self::findLiveCoupon($email, $ruleName);
        if (null !== $live) {
            return $live;
        }
        // Rate limit: rolling 30 days.
        if (self::countRecent($email, $ruleName) >= self::MAX_PER_RULE_30D) {
            throw new \RuntimeException('rate_limited');
        }

        $type = in_array((string) ($rule['type'] ?? ''), self::TYPES, true) ? (string) $rule['type'] : 'percent';
        $value = max(0.0, (float) ($rule['value'] ?? 0));
        $prefix = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) ($rule['prefix'] ?? 'PLK')));
        $validDays = max(1, min(365, (int) ($rule['validDays'] ?? 30)));
        $minAmount = max(0.0, (float) ($rule['minAmount'] ?? 0));
        $excludeSpecial = (bool) ($rule['excludeSpecial'] ?? true);
        $validUntilDate = date('Y-m-d', strtotime('+' . $validDays . ' days'));

        $code = self::generateCode('' !== $prefix ? $prefix : 'PLK');
        $idCustomer = (int) \Customer::customerExists($email, true, true);

        $cartRule = new \CartRule();
        $cartRule->name = [];
        foreach (\Language::getLanguages(true, $idShop) as $lang) {
            $cartRule->name[(int) $lang['id_lang']] = 'Plunk ' . $code;
        }
        $cartRule->code = $code;
        $cartRule->description = 'Plunk coupon ' . $ruleName . ' for ' . \PlunkConnect\Util\EmailMask::mask($email);
        $cartRule->quantity = 1;
        $cartRule->quantity_per_user = 1;
        $cartRule->active = 1;
        $cartRule->highlight = 0;
        $cartRule->partial_use = 0;
        // Not combinable with other cart rules.
        $cartRule->cart_rule_restriction = 1;
        $cartRule->date_from = date('Y-m-d H:i:s');
        $cartRule->date_to = $validUntilDate . ' 23:59:59';
        $cartRule->minimum_amount = $minAmount;
        $cartRule->minimum_amount_tax = 1;
        $cartRule->minimum_amount_currency = (int) (\Context::getContext()->currency->id ?? 0);
        $cartRule->minimum_amount_shipping = 0;
        $cartRule->reduction_exclude_special = $excludeSpecial ? 1 : 0;

        switch ($type) {
            case 'percent':
                $cartRule->reduction_percent = min(100.0, $value);
                break;
            case 'amount':
                $cartRule->reduction_amount = $value;
                $cartRule->reduction_tax = true;
                $cartRule->reduction_currency = (int) (\Context::getContext()->currency->id ?? 0);
                break;
            case 'free_shipping':
                $cartRule->free_shipping = true;
                break;
        }

        // Registered customers: bind the code to their account. Guests get a
        // plain one-time code (quantity=1 makes it single-use anyway).
        if ($idCustomer > 0) {
            $cartRule->id_customer = $idCustomer;
        }

        if (!$cartRule->add()) {
            throw new \RuntimeException('CartRule creation failed');
        }

        \Db::getInstance()->insert('plunk_coupon', [
            'id_cart_rule' => (int) $cartRule->id,
            'id_shop' => $idShop,
            'email' => pSQL($email),
            'rule' => pSQL($ruleName),
            'id_cart' => $cartId,
            'execution_id' => pSQL($executionId),
            'code' => pSQL($code),
            'type' => pSQL($type),
            'value' => $value,
            'min_amount' => $minAmount,
            'date_add' => date('Y-m-d H:i:s'),
            'date_exp' => $cartRule->date_to,
        ]);

        // Persist the code on the contact so a workflow email can also read
        // {{contact.lastCouponCode}} (in addition to saveResponseAs).
        Outbox::enqueue('contacts', [
            'email' => $email,
            'data' => [
                'lastCouponCode' => $code,
                'lastCouponValidUntil' => $validUntilDate,
            ],
        ]);

        return [
            'code' => $code,
            'validUntil' => $validUntilDate,
            'value' => $value,
            'type' => $type,
            'minAmount' => $minAmount,
        ];
    }

    /**
     * Idempotent lookup: same email+rule+cart/execution returns same coupon.
     *
     * @return array{code:string,validUntil:string,value:float,type:string,minAmount:float}|null
     */
    private static function findCoupon(string $email, string $rule, int $cartId, string $executionId): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `code`, `date_exp`, `type`, `value`, `min_amount`
             FROM `' . _DB_PREFIX_ . 'plunk_coupon`
             WHERE `email` = \'' . pSQL($email) . '\'
               AND `rule` = \'' . pSQL($rule) . '\'
               AND `id_cart` = ' . $cartId . '
               AND `execution_id` = \'' . pSQL($executionId) . '\''
        );

        return is_array($row) ? self::rowToResponse($row) : null;
    }

    /** @return array<string, mixed>|null */
    private static function findLiveCoupon(string $email, string $rule): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `code`, `date_exp`, `type`, `value`, `min_amount`
             FROM `' . _DB_PREFIX_ . 'plunk_coupon`
             WHERE `email` = \'' . pSQL($email) . '\'
               AND `rule` = \'' . pSQL($rule) . '\'
               AND `date_exp` > \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             ORDER BY `date_exp` DESC'
        );

        return is_array($row) ? self::rowToResponse($row) : null;
    }

    private static function countRecent(string $email, string $rule): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'plunk_coupon`
             WHERE `email` = \'' . pSQL($email) . '\'
               AND `rule` = \'' . pSQL($rule) . '\'
               AND `date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 30 * 86400)) . '\''
        );
    }

    /**
     * Rule params are persisted per row, so a repeated request replays the
     * original values even if the rule config changed since.
     *
     * @param array<string, mixed> $row
     *
     * @return array{code:string,validUntil:string,value:float,type:string,minAmount:float}
     */
    private static function rowToResponse(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'validUntil' => substr((string) $row['date_exp'], 0, 10),
            'value' => (float) ($row['value'] ?? 0),
            'type' => (string) ($row['type'] ?? 'percent'),
            'minAmount' => (float) ($row['min_amount'] ?? 0),
        ];
    }

    private static function generateCode(string $prefix): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        do {
            $suffix = '';
            for ($i = 0; $i < 8; ++$i) {
                $suffix .= $alphabet[random_int(0, $max)];
            }
            $code = $prefix . '-' . $suffix;
            $exists = (int) \Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `code` = \'' . pSQL($code) . '\''
            );
        } while ($exists > 0);

        return $code;
    }
}
