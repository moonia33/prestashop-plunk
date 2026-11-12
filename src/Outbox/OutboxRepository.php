<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * Storage layer for the outbox table. Hooks only ever do one INSERT or one
 * indexed UPDATE here — never an HTTP call.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Outbox;

class OutboxRepository
{
    public const TABLE = 'plunk_outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    public const ENDPOINT_TRACK = 'track';
    public const ENDPOINT_CONTACTS = 'contacts';
    public const ENDPOINT_DELETE = 'delete';

    /**
     * Enqueues a message. When a $dedupeKey is given and a *pending* row with
     * the same key + shop already exists, that row's payload and uuid are
     * replaced instead of inserting a new row (collapse semantics: e.g. 50
     * cart updates become one message carrying the latest state).
     *
     * @param array<string, mixed> $payload
     *
     * @return int The outbox row id
     */
    public function enqueue(int $idShop, string $endpoint, array $payload, ?string $dedupeKey = null): int
    {
        $db = \Db::getInstance();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        $uuid = $this->generateUuid();

        if (null !== $dedupeKey) {
            $existingId = (int) $db->getValue(
                'SELECT `id_outbox` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                WHERE `dedupe_key` = \'' . pSQL($dedupeKey) . '\'
                AND `id_shop` = ' . (int) $idShop . '
                AND `status` = \'' . pSQL(self::STATUS_PENDING) . '\'
                ORDER BY `id_outbox` DESC
                LIMIT 1'
            );

            if ($existingId > 0) {
                $db->update(self::TABLE, [
                    'uuid' => pSQL($uuid),
                    'payload' => pSQL($json),
                    'last_error' => ['type' => 'sql', 'value' => 'NULL'],
                    'date_upd' => $now,
                ], '`id_outbox` = ' . $existingId . ' AND `status` = \'' . pSQL(self::STATUS_PENDING) . '\'');

                return $existingId;
            }
        }

        $db->insert(self::TABLE, [
            'id_shop' => $idShop,
            'uuid' => pSQL($uuid),
            'endpoint' => pSQL($endpoint),
            'dedupe_key' => null !== $dedupeKey ? pSQL($dedupeKey) : null,
            'payload' => pSQL($json),
            'status' => pSQL(self::STATUS_PENDING),
            'attempts' => 0,
            'next_attempt_at' => $now,
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return (int) $db->Insert_ID();
    }

    /**
     * Atomically claims a batch of due messages for processing.
     * Race-safe: the UPDATE only affects rows still 'pending', so two workers
     * cannot claim the same row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimBatch(int $limit, string $locker, ?int $idShop = null): array
    {
        $db = \Db::getInstance();
        $shopCond = null !== $idShop ? ' AND `id_shop` = ' . (int) $idShop : '';

        $ids = array_map('intval', (array) $db->executeS(
            'SELECT `id_outbox` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `status` = \'' . pSQL(self::STATUS_PENDING) . '\'
            AND `next_attempt_at` <= NOW()' . $shopCond . '
            ORDER BY `id_outbox` ASC
            LIMIT ' . (int) $limit,
            true,
            false
        ));

        if (empty($ids)) {
            return [];
        }

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `status` = \'' . pSQL(self::STATUS_PROCESSING) . '\',
                `locked_by` = \'' . pSQL($locker) . '\',
                `date_upd` = NOW()
            WHERE `id_outbox` IN (' . implode(',', $ids) . ')
            AND `status` = \'' . pSQL(self::STATUS_PENDING) . '\''
        );

        return (array) $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `locked_by` = \'' . pSQL($locker) . '\'
            AND `status` = \'' . pSQL(self::STATUS_PROCESSING) . '\'
            ORDER BY `id_outbox` ASC'
        );
    }

    public function markSent(int $idOutbox): void
    {
        \Db::getInstance()->update(self::TABLE, [
            'status' => pSQL(self::STATUS_SENT),
            'last_error' => ['type' => 'sql', 'value' => 'NULL'],
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_outbox` = ' . (int) $idOutbox);
    }

    /**
     * Schedules the next attempt; transitions to 'dead' when $dead is true.
     */
    public function markFailed(int $idOutbox, int $delaySeconds, string $error, bool $dead): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `status` = \'' . pSQL($dead ? self::STATUS_DEAD : self::STATUS_FAILED) . '\',
                `attempts` = `attempts` + 1,
                `next_attempt_at` = DATE_ADD(NOW(), INTERVAL ' . (int) $delaySeconds . ' SECOND),
                `last_error` = \'' . pSQL(mb_substr($error, 0, 500)) . '\',
                `date_upd` = NOW()
            WHERE `id_outbox` = ' . (int) $idOutbox
        );
    }

    /**
     * 'failed' rows become eligible again once next_attempt_at passes —
     * treat them like pending for claiming purposes. Call this cheap sweep
     * at the start of each worker run.
     */
    public function requeueDueFailures(): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `status` = \'' . pSQL(self::STATUS_PENDING) . '\'
            WHERE `status` = \'' . pSQL(self::STATUS_FAILED) . '\'
            AND `next_attempt_at` <= NOW()'
        );
    }

    /**
     * Rows stuck 'processing' (worker died mid-flight) go back to pending
     * after the lock expires.
     */
    public function releaseStaleProcessing(int $staleSeconds = 300): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `status` = \'' . pSQL(self::STATUS_PENDING) . '\',
                `locked_by` = NULL
            WHERE `status` = \'' . pSQL(self::STATUS_PROCESSING) . '\'
            AND `date_upd` < DATE_SUB(NOW(), INTERVAL ' . (int) $staleSeconds . ' SECOND)'
        );
    }

    /**
     * Re-queues all 'dead' rows (admin "retry dead" button).
     */
    public function retryDead(?int $idShop = null): int
    {
        $db = \Db::getInstance();
        $shopCond = null !== $idShop ? ' AND `id_shop` = ' . (int) $idShop : '';

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `status` = \'' . pSQL(self::STATUS_PENDING) . '\',
                `attempts` = 0,
                `next_attempt_at` = NOW(),
                `last_error` = NULL,
                `date_upd` = NOW()
            WHERE `status` = \'' . pSQL(self::STATUS_DEAD) . '\'' . $shopCond
        );

        return (int) $db->Affected_Rows();
    }

    /**
     * Deletes 'sent' rows older than $days in batches.
     */
    public function pruneSent(int $days = 7, int $batchSize = 1000): int
    {
        $db = \Db::getInstance();
        $total = 0;

        do {
            $db->execute(
                'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
                WHERE `status` = \'' . pSQL(self::STATUS_SENT) . '\'
                AND `date_upd` < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                LIMIT ' . (int) $batchSize
            );
            $deleted = (int) $db->Affected_Rows();
            $total += $deleted;
        } while ($deleted >= $batchSize);

        return $total;
    }

    /**
     * @return array<string, int> status => count
     */
    public function countsByStatus(?int $idShop = null): array
    {
        $shopCond = null !== $idShop ? ' AND `id_shop` = ' . (int) $idShop : '';
        $rows = \Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS c FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE 1' . $shopCond . ' GROUP BY `status`'
        );

        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_SENT => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_DEAD => 0,
        ];
        foreach ((array) $rows as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>> most recent errors, newest first
     */
    public function lastErrors(?int $idShop = null, int $limit = 10): array
    {
        $shopCond = null !== $idShop ? ' AND `id_shop` = ' . (int) $idShop : '';

        return (array) \Db::getInstance()->executeS(
            'SELECT `id_outbox`, `endpoint`, `status`, `attempts`, `last_error`, `date_upd`
            FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `last_error` IS NOT NULL' . $shopCond . '
            ORDER BY `date_upd` DESC
            LIMIT ' . (int) $limit
        );
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
