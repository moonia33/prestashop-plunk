<?php

/**
 * `php bin/console plunkconnect:subscribers:reconcile`.
 *
 * Nightly consistency check: lists Plunk contacts (cursor-paginated) and
 * compares with PrestaShop subscription flags. Differences are REPORTED
 * only - nothing is subscribed or unsubscribed automatically.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Api\PlunkClient;
use PlunkConnect\Util\EmailMask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileSubscribersCommand extends Command
{
    protected static $defaultName = 'plunkconnect:subscribers:reconcile';
    private const MAX_PAGES = 200; // 200 * 250 = 50k contacts cap per run

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:subscribers:reconcile')
            ->setDescription('Report (never fix) subscription mismatches between PrestaShop and Plunk.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = new PlunkClient();
        $cursor = null;
        $checked = 0;
        $missingInPs = 0;
        $missingInPlunk = 0;
        $pages = 0;

        do {
            $response = $client->listContacts($cursor, 250);
            if (($response['status'] ?? 0) !== 200 || !is_array($response['body'])) {
                $output->writeln('<error>Plunk list failed: HTTP ' . ($response['status'] ?? 0) . '</error>');

                return 1;
            }
            $body = $response['body'];
            $contacts = $body['contacts'] ?? $body['data'] ?? (array_is_list($body) ? $body : []);
            foreach ((array) $contacts as $contact) {
                $email = strtolower((string) ($contact['email'] ?? ''));
                if (!\Validate::isEmail($email)) {
                    continue;
                }
                ++$checked;
                $plunkSubscribed = (bool) ($contact['subscribed'] ?? false);
                $psSubscribed = $this->isSubscribedInPs($email);
                if ($plunkSubscribed && !$psSubscribed) {
                    ++$missingInPs;
                    $output->writeln('  in_plunk_only: ' . EmailMask::mask($email));
                } elseif (!$plunkSubscribed && $psSubscribed) {
                    ++$missingInPlunk;
                    $output->writeln('  in_ps_only:    ' . EmailMask::mask($email));
                }
            }
            $cursor = $body['nextCursor'] ?? $body['cursor'] ?? null;
            ++$pages;
        } while ($cursor && $contacts && $pages < self::MAX_PAGES);

        $output->writeln(sprintf(
            'reconcile done: checked=%d, plunk_only=%d, ps_only=%d',
            $checked,
            $missingInPs,
            $missingInPlunk
        ));

        return 0;
    }

    /**
     * newsletter=1 on a customer row OR active=1 in ps_emailsubscription.
     */
    private function isSubscribedInPs(string $email): bool
    {
        $db = \Db::getInstance();
        $customer = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($email) . '\' AND `newsletter` = 1 AND `deleted` = 0'
        );
        if ($customer > 0) {
            return true;
        }
        if ($db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            return (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
                 WHERE `email` = \'' . pSQL($email) . '\' AND `active` = 1'
            ) > 0;
        }

        return false;
    }
}
