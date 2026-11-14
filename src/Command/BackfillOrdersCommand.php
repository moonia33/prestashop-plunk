<?php

/**
 * `php bin/console plunkconnect:orders:backfill [--since=YYYY-MM-DD] [--create] [--dry-run]`.
 *
 * Writes order-history contact fields (OrderMapper::contactAggregates)
 * for every customer with a valid order. No events are sent, so no
 * workflow fires. By default only contacts that already exist in Plunk are
 * updated (`contacts_ifexists`); --create also adds missing ones as
 * unsubscribed (`contacts_ifnew`, existing `subscribed` untouched).
 */

namespace PlunkConnect\Command;

use PlunkConnect\Mapper\CustomerMapper;
use PlunkConnect\Mapper\OrderMapper;
use PlunkConnect\Outbox\Outbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillOrdersCommand extends Command
{
    private const BATCH = 500;

    protected static $defaultName = 'plunkconnect:orders:backfill';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:orders:backfill')
            ->setDescription('Push order-history contact fields (totals, dates, purchased categories/brands/activities) to Plunk.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only customers with an order since this date')
            ->addOption('create', null, InputOption::VALUE_NONE, 'Also create missing contacts (as unsubscribed)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and print one sample, do not enqueue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = null;
        if (null !== $input->getOption('since')) {
            $ts = strtotime((string) $input->getOption('since'));
            if (false === $ts) {
                $output->writeln('<error>--since must be a valid date</error>');

                return 1;
            }
            $since = date('Y-m-d H:i:s', $ts);
        }
        $create = (bool) $input->getOption('create');
        $dryRun = (bool) $input->getOption('dry-run');
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);
        $mapper = new OrderMapper();

        $queued = 0;
        $cursor = '';
        while (true) {
            $rows = \Db::getInstance()->executeS(
                'SELECT LOWER(c.`email`) AS email, MAX(c.`id_lang`) AS id_lang
                 FROM `' . _DB_PREFIX_ . 'orders` o
                 INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
                 WHERE o.`id_shop` = ' . $idShop . ' AND o.`valid` = 1
                   AND LOWER(c.`email`) > \'' . pSQL($cursor) . '\''
                   . (null !== $since ? ' AND o.`date_add` >= \'' . pSQL($since) . '\'' : '') . '
                 GROUP BY LOWER(c.`email`)
                 ORDER BY email ASC
                 LIMIT ' . self::BATCH
            ) ?: [];
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $email = (string) $row['email'];
                $cursor = $email;
                if (!\Validate::isEmail($email)) {
                    continue;
                }
                $data = CustomerMapper::base((int) $row['id_lang'], $idShop) + $mapper->contactAggregates($email, $idShop);
                if ($dryRun) {
                    if (0 === $queued) {
                        $output->writeln('sample ' . preg_replace('/^[^@]{0,2}\K[^@]*/', '***', $email) . ': '
                            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                    ++$queued;
                    continue;
                }
                $payload = ['email' => $email, 'data' => $data];
                if ($create) {
                    $payload['subscribed'] = false;
                }
                if (Outbox::enqueue($create ? 'contacts_ifnew' : 'contacts_ifexists', $payload, 'orders-backfill:' . md5($email), 0, $idShop)) {
                    ++$queued;
                }
            }
        }

        $output->writeln(sprintf('%s: %d customer(s)%s', $dryRun ? 'dry-run' : 'queued', $queued, $create ? ' (create missing)' : ' (existing contacts only)'));

        return 0;
    }
}
