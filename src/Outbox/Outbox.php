<?php

/**
 * Transactional-outbox writer.
 *
 * Hooks only INSERT/UPDATE one row (< 2 ms); no HTTP call is ever made in
 * a shopper request. The worker sends rows to Plunk asynchronously.
 */

namespace PlunkConnect\Outbox;

use PlunkConnect\Util\EmailMask;

final class Outbox
{
    public const TABLE = 'plunk_outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    /**
     * Enqueue a Plunk API call.
     *
     * @param string $endpoint track|contacts|contacts_ifnew|delete|lookup_delete
     * @param array $payload request body (JSON-encoded as-is)
     * @param string|null $dedupeKey when set, an existing pending row with the
     *                               same key is updated in place instead of
     *                               adding a new one (cart spam collapse)
     */
    public static function enqueue(string $endpoint, array $payload, ?string $dedupeKey = null): bool
    {
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $table = _DB_PREFIX_ . self::TABLE;

        if (null !== $dedupeKey) {
            // Collapse: refresh the pending row so bursts become one event.
            $updated = \Db::getInstance()->execute(
                'UPDATE `' . $table . '`
                 SET `payload` = \'' . pSQL($json) . '\',
                     `uuid` = \'' . pSQL(self::uuid()) . '\',
                     `next_attempt_at` = \'' . pSQL($now) . '\',
                     `date_upd` = \'' . pSQL($now) . '\',
                     `last_error` = NULL
                 WHERE `dedupe_key` = \'' . pSQL($dedupeKey) . '\'
                   AND `id_shop` = ' . $idShop . '
                   AND `status` = \'' . pSQL(self::STATUS_PENDING) . '\'
                 LIMIT 1'
            );
            if ($updated && \Db::getInstance()->Affected_Rows() > 0) {
                return true;
            }
        }

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . $table . '`
             (`id_shop`, `uuid`, `endpoint`, `dedupe_key`, `payload`, `status`,
              `attempts`, `next_attempt_at`, `date_add`, `date_upd`)
             VALUES (
                ' . $idShop . ',
                \'' . pSQL(self::uuid()) . '\',
                \'' . pSQL($endpoint) . '\',
                ' . (null !== $dedupeKey ? '\'' . pSQL($dedupeKey) . '\'' : 'NULL') . ',
                \'' . pSQL($json) . '\',
                \'' . pSQL(self::STATUS_PENDING) . '\',
                0,
                \'' . pSQL($now) . '\',
                \'' . pSQL($now) . '\',
                \'' . pSQL($now) . '\')'
        );
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Counts by status for the admin status panel.
     */
    public static function counts(): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS c FROM `' . _DB_PREFIX_ . self::TABLE . '` GROUP BY `status`'
        );
        $out = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_SENT => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_DEAD => 0,
        ];
        foreach ($rows ?: [] as $row) {
            $out[$row['status']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * Last failed/dead rows for the admin panel (errors already PII-scrubbed).
     */
    public static function lastErrors(int $limit = 10): array
    {
        return \Db::getInstance()->executeS(
            'SELECT `id_outbox`, `endpoint`, `attempts`, `last_error`, `date_upd`
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `status` IN (\'' . pSQL(self::STATUS_FAILED) . '\',\'' . pSQL(self::STATUS_DEAD) . '\')
             ORDER BY `date_upd` DESC
             LIMIT ' . max(1, $limit)
        ) ?: [];
    }

    /**
     * Requeue all dead rows (admin "retry dead" button).
     */
    public static function retryDead(): int
    {
        $now = date('Y-m-d H:i:s');
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = \'' . pSQL(self::STATUS_PENDING) . '\',
                 `attempts` = 0,
                 `next_attempt_at` = \'' . pSQL($now) . '\',
                 `last_error` = NULL,
                 `date_upd` = \'' . pSQL($now) . '\'
             WHERE `status` = \'' . pSQL(self::STATUS_DEAD) . '\''
        );

        return (int) \Db::getInstance()->Affected_Rows();
    }

    /**
     * Store an error without PII, capped at 500 chars.
     */
    public static function scrubError(string $error): string
    {
        return \Tools::substr(EmailMask::scrub($error), 0, 500);
    }
}
