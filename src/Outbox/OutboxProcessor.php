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
    private const PATCH_GROUP_MAX = 1000;
    private const UPSERT_CHUNK = 500;
    private const DELETE_CHUNK = 1000;
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
               AND `next_attempt_at` <= \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             ORDER BY `id_outbox` ASC
             LIMIT ' . max(1, $limit)
        ) ?: [], 'id_outbox'));

        // Rows stuck in `processing` (crashed worker) become pending again.
        $db->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\', `locked_by` = NULL
             WHERE `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\'
               AND `date_upd` < \'' . pSQL(date('Y-m-d H:i:s', time() - 600)) . '\''
        );

        if (!$ids) {
            return $stats;
        }

        $tag = uniqid('w', true);
        $db->execute(
            'UPDATE `' . $table . '`
             SET `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\',
                 `locked_by` = \'' . pSQL($tag) . '\',
                 `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             WHERE `id_outbox` IN (' . implode(',', $ids) . ')
               AND `status` = \'' . pSQL(Outbox::STATUS_PENDING) . '\''
        );

        $rows = $db->executeS(
            'SELECT * FROM `' . $table . '`
             WHERE `locked_by` = \'' . pSQL($tag) . '\' AND `status` = \'' . pSQL(Outbox::STATUS_PROCESSING) . '\''
        ) ?: [];

        $stats['claimed'] = count($rows);
        $clients = [];

        // catalog.patch rows are merged: up to 1000 products go out in a
        // single PATCH request (dedupe already guarantees one row per id).
        $patchGroups = [];
        $normalRows = [];
        $patchIndex = 0;
        foreach ($rows as $row) {
            if ('catalog.patch' === $row['endpoint']) {
                $patchGroups[intdiv($patchIndex, self::PATCH_GROUP_MAX)][] = $row;
                ++$patchIndex;
            } else {
                $normalRows[] = $row;
            }
        }

        foreach ($normalRows as $row) {
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

        $mapper = new \PlunkConnect\Mapper\CatalogMapper();
        foreach ($patchGroups as $groupRows) {
            if (microtime(true) > $deadline) {
                break;
            }
            $docs = [];
            $idShop = 0;
            foreach ($groupRows as $row) {
                $idShop = (int) $row['id_shop'];
                $payload = json_decode((string) $row['payload'], true);
                $plunkId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
                if ('' !== $plunkId) {
                    $doc = $mapper->buildPatch($plunkId, $idShop);
                    if (null !== $doc) {
                        $docs[$plunkId] = $doc;
                    }
                }
            }
            if (!isset($clients[$idShop])) {
                $clients[$idShop] = new PlunkClient($idShop);
            }
            $result = $docs
                ? $this->catalogPatchAndRequeue($clients[$idShop], array_values($docs), (string) $groupRows[0]['uuid'])
                : ['status' => 200, 'retryAfter' => null, 'error' => null];
            foreach ($groupRows as $row) {
                $this->settle($row, $result, $stats);
            }
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
     * If a Plunk contact matching the event email already is subscribed,
     * remove `subscribed:false` from the payload so the track event cannot
     * unsubscribe it (spec 7.2). Pure function - unit-tested.
     *
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $contacts
     *
     * @return array<string, mixed>
     */
    public static function dropSubscribedForSubscribedContact(array $payload, array $contacts): array
    {
        $email = (string) ($payload['email'] ?? '');
        if ('' === $email || !array_key_exists('subscribed', $payload)) {
            return $payload;
        }
        foreach ($contacts as $contact) {
            if (is_array($contact)
                && 0 === strcasecmp((string) ($contact['email'] ?? ''), $email)
                && !empty($contact['subscribed'])) {
                unset($payload['subscribed']);
                break;
            }
        }

        return $payload;
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
                // Double-opt-in guard (spec 7.2): a repeated signup must
                // not unsubscribe a contact that is already subscribed in
                // Plunk (e.g. subscribers imported from Newsman). Check in
                // the worker - never inside the customer's request.
                if ('newsletter.signup_requested' === ($payload['event'] ?? '')
                    && false === ($payload['subscribed'] ?? null)
                    && '' !== (string) ($payload['email'] ?? '')) {
                    $lookup = $client->searchContactResponse((string) $payload['email']);
                    $lookupStatus = (int) ($lookup['status'] ?? 0);
                    if (0 === $lookupStatus || 429 === $lookupStatus || $lookupStatus >= 500) {
                        return $lookup; // transient - retry the whole event
                    }
                    if (200 === $lookupStatus) {
                        $contacts = (array) ($lookup['body']['data'] ?? $lookup['body']['contacts'] ?? []);
                        $payload = self::dropSubscribedForSubscribedContact($payload, $contacts);
                    }
                }

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

            case 'catalog.upsert':
                // payload {ids:[id_product...]} -> full docs, PUT in <=500 chunks.
                $mapper = new \PlunkConnect\Mapper\CatalogMapper();
                $docs = $mapper->buildProducts(array_map('intval', (array) ($payload['ids'] ?? [])), (int) $row['id_shop']);
                foreach (array_chunk($docs, self::UPSERT_CHUNK) as $i => $chunk) {
                    $result = $client->catalogUpsert($chunk, $row['uuid'] . '-' . $i);
                    $status = (int) ($result['status'] ?? 0);
                    if ($status < 200 || $status >= 300) {
                        return $result;
                    }
                }

                return ['status' => 200, 'retryAfter' => null, 'error' => null];

            case 'catalog.delete':
                // payload {ids:[plunkId...]} -> POST /catalog/products/delete.
                $ids = array_values(array_unique(array_map('strval', (array) ($payload['ids'] ?? []))));
                foreach (array_chunk($ids, self::DELETE_CHUNK) as $i => $chunk) {
                    $result = $client->catalogDelete($chunk, $row['uuid'] . '-' . $i);
                    $status = (int) ($result['status'] ?? 0);
                    if ($status < 200 || $status >= 300) {
                        return $result;
                    }
                }

                return ['status' => 200, 'retryAfter' => null, 'error' => null];

            default:
                return ['status' => 400, 'retryAfter' => null, 'error' => 'unknown endpoint ' . $row['endpoint']];
        }
    }

    /**
     * PATCH the merged docs; ids Plunk reports as notFound are requeued as
     * full upserts (they need every field, not just price/stock).
     */
    private function catalogPatchAndRequeue(PlunkClient $client, array $docs, string $idempotencyKey): array
    {
        $result = $client->catalogPatch($docs, $idempotencyKey);
        if ((int) ($result['status'] ?? 0) >= 200 && (int) ($result['status'] ?? 0) < 300
            && is_array($result['body']) && !empty($result['body']['notFound'])) {
            $ids = array_map(
                fn ($plunkId) => (new \PlunkConnect\Mapper\CatalogMapper())->splitPlunkId((string) $plunkId)[0],
                (array) $result['body']['notFound']
            );
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids) {
                Outbox::enqueue('catalog.upsert', ['ids' => $ids], 'catalog.upsert:' . md5(implode(',', $ids)));
            }
        }

        return $result;
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
                   AND `date_upd` < \'' . pSQL(date('Y-m-d H:i:s', time() - 7 * 86400)) . '\'
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
