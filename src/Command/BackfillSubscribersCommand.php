<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * bin/console plunkconnect:subscribers:backfill --since=YYYY-MM-DD [--dry-run]
 *
 * Enqueues contact upserts (without `subscribed`) for PrestaShop subscribers
 * created after the Newsman export cut-off. Existing Plunk contacts keep
 * their subscription state; new ones become subscribed by Plunk's default.
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Command;

use PlunkConnect\Config\ModuleConfiguration;
use PlunkConnect\Outbox\OutboxRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillSubscribersCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:subscribers:backfill')
            ->setDescription('Enqueue contact upserts for PrestaShop newsletter subscribers added after a given date')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'ISO date, e.g. 2026-11-12')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count/report, do not enqueue')
            ->addOption('shop', 's', InputOption::VALUE_OPTIONAL, 'Only this shop id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = (string) $input->getOption('since');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) || !strtotime($since)) {
            $output->writeln('<error>--since must be a valid YYYY-MM-DD date</error>');

            return 1;
        }

        $module = \Module::getInstanceByName('plunkconnect');
        if (!$module instanceof \Plunkconnect || !$module->active) {
            $output->writeln('<error>Module plunkconnect is not installed/active</error>');

            return 1;
        }

        /** @var ModuleConfiguration $config */
        $config = $module->getConfiguration();
        $dryRun = (bool) $input->getOption('dry-run');
        $shopFilter = null !== $input->getOption('shop') ? (int) $input->getOption('shop') : null;

        $shopIds = null !== $shopFilter ? [$shopFilter] : $this->allShopIds();
        $outbox = new OutboxRepository();
        $grand = ['queued' => 0, 'subscribers' => 0];

        foreach ($shopIds as $idShop) {
            $subscribers = $this->collectSubscribers($idShop, $since);
            $grand['subscribers'] += count($subscribers);

            $output->writeln(sprintf('Shop %d: %d subscribers since %s', $idShop, count($subscribers), $since));

            if ($dryRun) {
                if ($config->isConfigured($idShop) && !empty($subscribers)) {
                    $this->reportExistence($config, $idShop, $subscribers, $output);
                }
                continue;
            }

            if (!$config->isConfigured($idShop)) {
                $output->writeln(sprintf('<comment>Shop %d not configured – skipped</comment>', $idShop));
                continue;
            }

            $consentSource = $config->get(ModuleConfiguration::CONSENT_SOURCE, $idShop) ?: 'website_signup';
            $shopCode = $config->get(ModuleConfiguration::SHOP_CODE, $idShop);

            foreach ($subscribers as $subscriber) {
                // No `subscribed` key on purpose: existing Plunk contacts keep
                // their state, new contacts default to subscribed=true.
                $payload = [
                    'email' => $subscriber['email'],
                    'data' => [
                        'locale' => $subscriber['locale'],
                        'source' => 'prestashop',
                        'consentSource' => $consentSource,
                        'shopCode' => $shopCode,
                        'backfill' => true,
                        'subscribedAt' => $subscriber['date'],
                    ],
                ];

                $outbox->enqueue(
                    $idShop,
                    OutboxRepository::ENDPOINT_CONTACTS,
                    $payload,
                    'backfill:' . $idShop . ':' . hash('sha256', strtolower($subscriber['email']))
                );
                ++$grand['queued'];
            }
        }

        $output->writeln(sprintf(
            '%s: %d subscribers found, %d queued',
            $dryRun ? 'DRY RUN' : 'DONE',
            $grand['subscribers'],
            $grand['queued']
        ));

        return 0;
    }

    /**
     * @return array<int, array{email:string, date:string, locale:string}>
     */
    private function collectSubscribers(int $idShop, string $since): array
    {
        $db = \Db::getInstance();
        $mapper = null;
        $result = [];

        $rows = (array) $db->executeS(
            'SELECT c.`email`, c.`newsletter_date_add` AS `date`, c.`id_lang`, l.`iso_code`
            FROM `' . _DB_PREFIX_ . 'customer` c
            LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = c.`id_lang`
            WHERE c.`newsletter` = 1
            AND c.`newsletter_date_add` >= \'' . pSQL($since . ' 00:00:00') . '\'
            AND c.`id_shop` = ' . (int) $idShop
        );

        $mapper = $this->localeMapper($idShop);
        foreach ($rows as $row) {
            $result[] = [
                'email' => (string) $row['email'],
                'date' => (string) $row['date'],
                'locale' => $mapper->localeForLang((int) $row['id_lang'], (string) ($row['iso_code'] ?? '')),
            ];
        }

        $guestRows = (array) $db->executeS(
            'SELECT e.`email`, e.`newsletter_date_add` AS `date`, e.`id_lang`, l.`iso_code`
            FROM `' . _DB_PREFIX_ . 'emailsubscription` e
            LEFT JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = e.`id_lang`
            WHERE e.`active` = 1
            AND e.`newsletter_date_add` >= \'' . pSQL($since . ' 00:00:00') . '\'
            AND e.`id_shop` = ' . (int) $idShop
        );

        foreach ($guestRows as $row) {
            $result[] = [
                'email' => (string) $row['email'],
                'date' => (string) $row['date'],
                'locale' => $mapper->localeForLang((int) $row['id_lang'], (string) ($row['iso_code'] ?? '')),
            ];
        }

        return $result;
    }

    private function localeMapper(int $idShop): \PlunkConnect\Config\LocaleMapper
    {
        $config = new ModuleConfiguration();

        return new \PlunkConnect\Config\LocaleMapper(
            $config->getLocaleMap($idShop),
            $config->getLocaleFallback($idShop)
        );
    }

    /**
     * Dry-run helper: reports how many of the found subscribers already
     * exist in Plunk (no state changes).
     *
     * @param array<int, array{email:string}> $subscribers
     */
    private function reportExistence(ModuleConfiguration $config, int $idShop, array $subscribers, OutputInterface $output): void
    {
        $client = $config->createClient($idShop);
        $emails = array_map(static function (array $s): string {
            return $s['email'];
        }, $subscribers);

        $found = 0;
        foreach (array_chunk($emails, 500) as $chunk) {
            $response = $client->lookupContacts($chunk);
            if ($response->isSuccess() && isset($response->body['found']) && is_array($response->body['found'])) {
                $found += count($response->body['found']);
            }
            usleep(100000);
        }

        $output->writeln(sprintf(
            '  lookup: %d already in Plunk, %d new',
            $found,
            count($emails) - $found
        ));
    }

    /**
     * @return int[]
     */
    private function allShopIds(): array
    {
        return array_map('intval', array_column((array) \Shop::getShops(false), 'id_shop'));
    }
}
