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
        // Reuse a still-usable coupon of this rule issued for another cart/execution.
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
        $group = substr((string) preg_replace('/[^a-z0-9_-]/i', '', (string) ($rule['group'] ?? '')), 0, 64);
        $excludeManufacturers = array_values(array_filter(array_map('intval', (array) ($rule['excludeManufacturers'] ?? [])), static fn (int $id): bool => $id > 0));
        if ([] !== $excludeManufacturers && 'percent' !== $type) {
            // PrestaShop only computes "selected products" reductions for percentages.
            throw new \RuntimeException('excludeManufacturers needs a percent rule');
        }

        // Issues of one series are serialized, so retirement always sees the other coupon's row.
        $lock = '' !== $group ? 'plunk_coupon_' . md5($idShop . '|' . $email . '|' . $group) : '';
        if ('' !== $lock && 1 !== (int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($lock) . '\', 10)')) {
            throw new \RuntimeException('lock_timeout');
        }

        try {
            return self::issue($ruleName, $email, $cartId, $executionId, $idShop, $group, $type, $value, $prefix, $minAmount, $excludeSpecial, $validUntilDate, $excludeManufacturers);
        } finally {
            if ('' !== $lock) {
                \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')');
            }
        }
    }

    /**
     * @return array{code:string,validUntil:string,value:float,type:string,minAmount:float}
     */
    private static function issue(
        string $ruleName,
        string $email,
        int $cartId,
        string $executionId,
        int $idShop,
        string $group,
        string $type,
        float $value,
        string $prefix,
        float $minAmount,
        bool $excludeSpecial,
        string $validUntilDate,
        array $excludeManufacturers = [],
    ): array {
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

        if ([] !== $excludeManufacturers) {
            $cartRule->product_restriction = 1;
            // Percent reductions apply to the restricted ("selected") products only.
            $cartRule->reduction_product = -2;
        }

        if (!$cartRule->add()) {
            throw new \RuntimeException('CartRule creation failed');
        }
        if ([] !== $excludeManufacturers) {
            self::restrictManufacturers((int) $cartRule->id, $excludeManufacturers);
        }

        \Db::getInstance()->insert('plunk_coupon', [
            'id_cart_rule' => (int) $cartRule->id,
            'id_shop' => $idShop,
            'email' => pSQL($email),
            'rule' => pSQL($ruleName),
            'rule_group' => pSQL($group),
            'id_cart' => $cartId,
            'execution_id' => pSQL($executionId),
            'code' => pSQL($code),
            'type' => pSQL($type),
            'value' => $value,
            'min_amount' => $minAmount,
            'date_add' => date('Y-m-d H:i:s'),
            'date_exp' => $cartRule->date_to,
        ]);

        // A series (10% -> 15% -> 20%) shares a group: only the newest coupon stays usable.
        if ('' !== $group) {
            self::retireGroup($idShop, $email, $group, (int) $cartRule->id);
        }

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

    /**
     * A coupon of this rule the customer can still use: unexpired, active and
     * not yet redeemed (a used or retired code must not be handed out again).
     *
     * @return array<string, mixed>|null
     */
    private static function findLiveCoupon(string $email, string $rule): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT pc.`code`, pc.`date_exp`, pc.`type`, pc.`value`, pc.`min_amount`
             FROM `' . _DB_PREFIX_ . 'plunk_coupon` pc
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.`id_cart_rule` = pc.`id_cart_rule`
             WHERE pc.`email` = \'' . pSQL($email) . '\'
               AND pc.`rule` = \'' . pSQL($rule) . '\'
               AND pc.`date_exp` > \'' . pSQL(date('Y-m-d H:i:s')) . '\'
               AND cr.`active` = 1
               AND cr.`quantity` > 0
             ORDER BY pc.`date_exp` DESC'
        );

        return is_array($row) ? self::rowToResponse($row) : null;
    }

    /**
     * Deactivate the customer's older unused coupons of the same group. Only
     * older ids: of two concurrent issues the newer one survives.
     */
    private static function retireGroup(int $idShop, string $email, string $group, int $keepCartRuleId): void
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pc.`id_cart_rule`
             FROM `' . _DB_PREFIX_ . 'plunk_coupon` pc
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.`id_cart_rule` = pc.`id_cart_rule`
             WHERE pc.`id_shop` = ' . $idShop . '
               AND pc.`email` = \'' . pSQL($email) . '\'
               AND pc.`rule_group` = \'' . pSQL($group) . '\'
               AND pc.`id_cart_rule` < ' . $keepCartRuleId . '
               AND cr.`active` = 1
               AND cr.`quantity` > 0'
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) $row['id_cart_rule'];
            \Db::getInstance()->update('cart_rule', ['active' => 0, 'date_upd' => date('Y-m-d H:i:s')], '`id_cart_rule` = ' . $id);
            // Drop it from open carts so checkout does not keep a retired code.
            \Db::getInstance()->delete('cart_cart_rule', '`id_cart_rule` = ' . $id);
        }
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

    /**
     * Restrict a cart rule to every manufacturer except `$excluded`; PrestaShop
     * rules can only include, so the excluded brands get no reduction.
     *
     * @param int[] $excluded
     */
    private static function restrictManufacturers(int $idCartRule, array $excluded): void
    {
        $db = \Db::getInstance();
        $all = array_map('intval', array_column((array) $db->executeS('SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . 'manufacturer`'), 'id_manufacturer'));
        $db->insert('cart_rule_product_rule_group', ['id_cart_rule' => $idCartRule, 'quantity' => 1]);
        $idGroup = (int) $db->Insert_ID();
        $db->insert('cart_rule_product_rule', ['id_product_rule_group' => $idGroup, 'type' => 'manufacturers']);
        $idRule = (int) $db->Insert_ID();
        foreach (self::manufacturerValues($all, $excluded) as $id) {
            $db->insert('cart_rule_product_rule_value', ['id_product_rule' => $idRule, 'id_item' => $id]);
        }
    }

    /**
     * Rule values for "all manufacturers but these": 0 keeps products without
     * a manufacturer eligible (PrestaShop compares `id_manufacturer` loosely).
     *
     * @param int[] $all
     * @param int[] $excluded
     *
     * @return int[]
     */
    public static function manufacturerValues(array $all, array $excluded): array
    {
        $values = array_values(array_unique(array_diff(array_merge([0], $all), $excluded)));
        sort($values);

        return $values;
    }
}
