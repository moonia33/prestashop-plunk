<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * bin/console plunkconnect:subscribers:reconcile
 *
 * Nightly comparison of PrestaShop newsletter flags against Plunk
 * subscription state. Only REPORTS mismatches — it never (re)subscribes
 * anyone automatically.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Command;

use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Util\EmailMask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileSubscribersCommand extends Command
{
    private const MAX_PAGES = 1000; // 100k contacts safety cap

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:subscribers:reconcile')
            ->setDescription('Report differences between PrestaShop newsletter flags and Plunk subscription state')
            ->addOption('shop', 's', InputOption::VALUE_OPTIONAL, 'Only this shop id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = \Module::getInstanceByName('plunkconnect');
        if (!$module instanceof \Plunkconnect || !$module->active) {
            $output->writeln('<error>Module plunkconnect is not installed/active</error>');

            return 1;
        }

        /** @var ModuleConfiguration $config */
        $config = $module->getConfiguration();
        $shopFilter = null !== $input->getOption('shop') ? (int) $input->getOption('shop') : null;
        $shopIds = null !== $shopFilter ? [$shopFilter] : $this->allShopIds();
        $exitCode = 0;

        foreach ($shopIds as $idShop) {
            if (!$config->isConfigured($idShop)) {
                continue;
            }
            if ('' === $config->get(ModuleConfiguration::SECRET_KEY, $idShop)) {
                $output->writeln(sprintf('<comment>Shop %d: no secret key – skipped</comment>', $idShop));
                continue;
            }

            $output->writeln(sprintf('Reconciling shop %d…', $idShop));

            $plunk = $this->fetchPlunkStates($config, $idShop, $output);
            if (null === $plunk) {
                $exitCode = 1;
                continue;
            }

            $mismatches = $this->compare($idShop, $plunk);

            $output->writeln(sprintf(
                '  Plunk contacts: %d | PS subscribed: %d | mismatches: %d',
                count($plunk),
                $this->countPsSubscribed($idShop),
                count($mismatches)
            ));

            foreach (array_slice($mismatches, 0, 50) as $line) {
                $output->writeln('  ' . $line);
            }
            if (count($mismatches) > 50) {
                $output->writeln(sprintf('  …and %d more (see log)', count($mismatches) - 50));
            }
            foreach ($mismatches as $line) {
                \PrestaShopLogger::addLog('PlunkConnect reconcile: ' . $line, 2, null, 'PlunkConnect', null, true);
            }
        }

        return $exitCode;
    }

    /**
     * Pulls every Plunk contact's email+subscribed state via cursor pages.
     *
     * @return array<string, bool>|null lowercase email => subscribed
     */
    private function fetchPlunkStates(ModuleConfiguration $config, int $idShop, OutputInterface $output): ?array
    {
        $client = $config->createClient($idShop);
        $cursor = null;
        $states = [];

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $response = $client->listContacts(100, $cursor);
            if (!$response->isSuccess() || !is_array($response->body)) {
                $output->writeln(sprintf(
                    '<error>Shop %d: GET /contacts failed (HTTP %d)</error>',
                    $idShop,
                    $response->statusCode
                ));

                return null;
            }

            $items = $response->body['data'] ?? [];
            if (!is_array($items)) {
                break;
            }

            foreach ($items as $contact) {
                if (isset($contact['email']) && is_string($contact['email'])) {
                    $states[strtolower($contact['email'])] = !empty($contact['subscribed']);
                }
            }

            $hasMore = !empty($response->body['hasMore']);
            $next = isset($response->body['cursor']) ? (string) $response->body['cursor'] : '';
            if (!$hasMore || '' === $next) {
                break;
            }
            $cursor = $next;

            usleep(50000); // stay well under rate limits
        }

        return $states;
    }

    /**
     * @param array<string, bool> $plunk
     *
     * @return string[] human-readable mismatch lines (masked emails)
     */
    private function compare(int $idShop, array $plunk): array
    {
        $db = \Db::getInstance();
        $mismatches = [];
        $seen = [];

        $rows = (array) $db->executeS(
            'SELECT `email`, `newsletter` AS sub, \'customer\' AS src, `id_lang` FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `id_shop` = ' . (int) $idShop . ' AND `newsletter` = 1
            UNION ALL
            SELECT `email`, `active` AS sub, \'emailsubscription\' AS src, `id_lang` FROM `' . _DB_PREFIX_ . 'emailsubscription`
            WHERE `id_shop` = ' . (int) $idShop . ' AND `active` = 1'
        );

        foreach ($rows as $row) {
            $email = strtolower((string) $row['email']);
            $seen[$email] = true;
            if (!array_key_exists($email, $plunk)) {
                $mismatches[] = sprintf('%s subscribed in PS (%s) but missing in Plunk', EmailMask::mask($email), $row['src']);
            } elseif (!$plunk[$email]) {
                $mismatches[] = sprintf('%s subscribed in PS (%s) but unsubscribed in Plunk', EmailMask::mask($email), $row['src']);
            }
        }

        foreach ($plunk as $email => $subscribed) {
            if ($subscribed && !isset($seen[$email])) {
                $mismatches[] = sprintf('%s subscribed in Plunk but not in PS', EmailMask::mask($email));
            }
        }

        return $mismatches;
    }

    private function countPsSubscribed(int $idShop): int
    {
        $db = \Db::getInstance();

        return (int) $db->getValue(
            'SELECT
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_shop` = ' . (int) $idShop . ' AND `newsletter` = 1) +
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription` WHERE `id_shop` = ' . (int) $idShop . ' AND `active` = 1)'
        );
    }

    /**
     * @return int[]
     */
    private function allShopIds(): array
    {
        return array_map('intval', array_column((array) \Shop::getShops(false), 'id_shop'));
    }
}
