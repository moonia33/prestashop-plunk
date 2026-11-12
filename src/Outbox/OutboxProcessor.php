<?php

/**
 * Outbox worker. Called by `plunkconnect:outbox:process`, the cron front
 * controller and the actionCronJob hook.
 *
 * Claiming: ids are selected, then a single guarded UPDATE marks them
 * `processing` with a unique tag - two concurrent workers cannot grab the
 * same row without MariaDB-version-specific SKIP LOCKED syntax.
 */

namespace PlunkConnect\Outbox;

use PlunkConnect\Api\PlunkClient;

final class OutboxProcessor
{
    /** Exponential backoff in seconds: 1m, 5m, 15m, 1h, 6h. */
    public const RETRY_DELAYS = [60, 300, 900, 3600, 21600];
    public const MAX_ATTEMPTS = 8;
    private const REQUEST_DELAY_US = 50000; // ~20 req/s

    /**
     * @return array{claimed:int,sent:int,retried:int,dead:int}
     */
    public function process(int $limit = 200, int $maxSeconds = 50): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'dead' => 0];
        $deadline = microtime(true) + $maxSeconds;
        $table = _DB_PREFIX_ . Outbox::TABLE;
        $db = \Db::getInstance();

        $ids = array_map('intval', array_column($db->executeS(
            'SELECT `id_outbox` FROM `' . $table . '`
             WHERE `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\'
               AND `next_attempt_at` <= NOW()
             ORDER BY `id_outbox` ASC
             LIMIT ' . max(1, $limit)
        ) ?: [], 'id_outbox'));

        // Rows stuck in `processing` (crashed worker) become pending again.
        $db->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\', `locked_by` = NULL
             WHERE `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\'
               AND `date_upd` < DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
        );

        if (!$ids) {
            return $stats;
        }

        $tag = uniqid('w', true);
        $db->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\',
                 `locked_by` = \'' . pSQL($tag) . '\',
                 `date_upd` = NOW()
             WHERE `id_outbox` IN (' . implode(',', $ids) . ')
               AND `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\''
        );

        $rows = $db->executeS(
            'SELECT * FROM `' . $table . '`
             WHERE `locked_by` = \'' . pSQL($tag) . '\' AND `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\''
        ) ?: [];

        $stats['claimed'] = count($rows);
        $clients = [];

        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $idShop = (int) $row['id_shop'];
            if (!isset($clients[$idShop])) {
                $clients[$idShop] = new PlunkClient($idShop);
            }
            $result = $this->dispatch($clients[$idShop], $row);
            $this->settle($row, $result, $stats);

            usleep(self::REQUEST_DELAY_US);
        }

        // Unclaimed leftovers (rows we lost to a race or the deadline) go
        // back to pending so nothing stays stuck as `processing`.
        $db->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\', `locked_by` = NULL
             WHERE `locked_by` = \'' . pSQL($tag) . '\' AND `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\''
        );

        return $stats;
    }

    /**
     * Route one outbox row to the right Plunk endpoint.
     *
     * @return array{status:int,retryAfter:?int,error:?string}
     */
    private function dispatch(PlunkClient $client, array $row): array
    {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            return ['status' => 400, 'retryAfter' => null, 'error' => 'invalid payload json'];
        }

        switch ($row['endpoint']) {
            case 'track':
                return $client->track($payload, $row['uuid']);

            case 'contacts':
                return $client->upsertContact($payload, $row['uuid']);

            case 'contacts_ifnew':
                // Backfill: do not change `subscribed` of an existing contact.
                // A failed lookup is retried - it must not be treated as "not found".
                $lookup = isset($payload['email'])
                    ? $client->lookupContactResponse((string) $payload['email'])
                    : ['status' => 400, 'retryAfter' => null, 'error' => 'missing email'];
                $lookupStatus = (int) ($lookup['status'] ?? 0);
                if (0 === $lookupStatus || $lookupStatus >= 500 || 429 === $lookupStatus) {
                    return $lookup;
                }
                if (200 === $lookupStatus) {
                    unset($payload['subscribed']);
                }

                return $client->upsertContact($payload, $row['uuid']);

            case 'lookup_delete':
                // GDPR erasure: resolve the contact id first, then delete.
                $email = isset($payload['email']) ? (string) $payload['email'] : '';
                $contact = '' !== $email ? $client->lookupContact($email) : null;
                if (!is_array($contact)) {
                    // 404/no contact = nothing to delete; treat as done.
                    return ['status' => 200, 'retryAfter' => null, 'error' => null];
                }
                $contactId = (string) ($contact['id'] ?? '');

                return $client->deleteContact($contactId);

            default:
                return ['status' => 400, 'retryAfter' => null, 'error' => 'unknown endpoint ' . $row['endpoint']];
        }
    }

    /**
     * Success: 2xx (a repeated Idempotency-Key is rejected by Plunk and that
     * still counts as delivered). 408/429 and 5xx retry; other 4xx die.
     */
    private function settle(array $row, array $result, array &$stats): void
    {
        $status = (int) $result['status'];
        $attempts = (int) $row['attempts'] + 1;
        $now = date('Y-m-d H:i:s');
        $table = _DB_PREFIX_ . Outbox::TABLE;
        $id = (int) $row['id_outbox'];

        if ($status >= 200 && $status < 300) {
            \Db::getInstance()->execute(
                'UPDATE `' . $table . '`
                 SET `status` = \'' . pSQL(Outbox::STATUS_SENT) . '\', `attempts` = ' . $attempts . ',
                     `locked_by` = NULL, `last_error` = NULL, `date_upd` = \'' . pSQL($now) . '\'
                 WHERE `id_outbox` = ' . $id
            );
            ++$stats['sent'];

            return;
        }

        $error = Outbox::scrubError(
            $result['error'] ?: ('HTTP ' . $status . ' ' . \Tools::substr((string) ($result['raw'] ?? ''), 0, 300))
        );
        $retryable = 0 === $status || 408 === $status || 429 === $status || $status >= 500;

        if (!$retryable || $attempts >= self::MAX_ATTEMPTS) {
            \Db::getInstance()->execute(
                'UPDATE `' . $table . '`
                 SET `status` = \'' . pSQL(Outbox::STATUS_DEAD) . '\', `attempts` = ' . $attempts . ',
                     `locked_by` = NULL, `last_error` = \'' . pSQL($error) . '\',
                     `date_upd` = \'' . pSQL($now) . '\'
                 WHERE `id_outbox` = ' . $id
            );
            ++$stats['dead'];

            return;
        }

        $delay = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];
        if (429 === $status && !empty($result['retryAfter'])) {
            $delay = max($delay, (int) $result['retryAfter']);
        }
        $next = date('Y-m-d H:i:s', time() + $delay);

        \Db::getInstance()->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\', `attempts` = ' . $attempts . ',
                 `locked_by` = NULL, `last_error` = \'' . pSQL($error) . '\',
                 `next_attempt_at` = \'' . pSQL($next) . '\', `date_upd` = \'' . pSQL($now) . '\'
             WHERE `id_outbox` = ' . $id
        );
        ++$stats['retried'];
    }

    /**
     * Delete `sent` rows older than 7 days in batches of 1000.
     */
    public function cleanup(int $batches = 5): int
    {
        $deleted = 0;
        $table = _DB_PREFIX_ . Outbox::TABLE;
        for ($i = 0; $i < $batches; ++$i) {
            \Db::getInstance()->execute(
                'DELETE FROM `' . $table . '`
                 WHERE `status` = \'' . pSQL(Outbox::STATUS_SENT) . '\'
                   AND `date_upd` < DATE_SUB(NOW(), INTERVAL 7 DAY)
                 LIMIT 1000'
            );
            $affected = (int) \Db::getInstance()->Affected_Rows();
            $deleted += $affected;
            if ($affected < 1000) {
                break;
            }
        }

        return $deleted;
    }
}
