<?php

/**
 * `php bin/console plunkconnect:winback [--days=90] [--window=7] [--backlog=0] [--exclude-file=] [--shop=] [--dry-run]`.
 *
 * Tracks `customer.winback` for subscribed customers whose last valid order
 * (`orders.valid = 1`, so cancelled/refunded orders do not count) is `--days`
 * old. Run daily; `--window` days of grace cover missed runs. `--backlog=N`
 * also sends up to N older customers (newest first) for a one-time catch-up.
 * Each (email, last order) is sent once; a new order makes the customer due again.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Mapper\CustomerMapper;
use PlunkConnect\Outbox\Outbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WinbackCommand extends Command
{
    public const EVENT = 'customer.winback';
    private const TABLE = 'plunk_winback';

    protected static $defaultName = 'plunkconnect:winback';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:winback')
            ->setDescription('Track customer.winback for subscribed customers whose last valid order is --days old.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days since the last valid order', '90')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Grace days for missed runs', '7')
            ->addOption('backlog', null, InputOption::VALUE_REQUIRED, 'Also send up to N customers older than the window (newest first)', '0')
            ->addOption('exclude-file', null, InputOption::VALUE_REQUIRED, 'Emails (one per line) to mark as skipped for their current last order')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop id (default: context shop)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idShop = null !== $input->getOption('shop') ? (int) $input->getOption('shop') : (int) \Context::getContext()->shop->id;
        $days = max(1, (int) $input->getOption('days'));
        $window = max(1, (int) $input->getOption('window'));
        $backlog = max(0, (int) $input->getOption('backlog'));
        $dryRun = (bool) $input->getOption('dry-run');
        [$dueFrom, $dueTo] = self::bounds(time(), $days, $window);

        $skipped = 0;
        $file = (string) $input->getOption('exclude-file');
        if ('' !== $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (false === $lines ? [] : $lines as $email) {
                $email = strtolower(trim($email));
                $row = '' !== $email ? self::customers($idShop, null, null, 1, $email) : [];
                if ([] !== $row && !$dryRun) {
                    self::mark($idShop, $email, (string) $row[0]['last_at'], 'skipped');
                }
                $skipped += count($row);
            }
        }

        $rows = self::customers($idShop, $dueFrom, $dueTo, 5000);
        if ($backlog > 0) {
            $rows = array_merge($rows, self::customers($idShop, null, $dueFrom, $backlog));
        }

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $email = (string) $row['email'];
            $lastAt = (string) $row['last_at'];
            if (!$dryRun) {
                $data = CustomerMapper::base((int) $row['id_lang'], $idShop);
                $data['winback'] = [
                    'value' => ['lastOrderAt' => date('c', (int) strtotime($lastAt)), 'days' => $days],
                    'persistent' => false,
                ];
                if (!Outbox::enqueue('track', ['event' => self::EVENT, 'email' => $email, 'data' => $data], 'winback:' . md5($email . '|' . $lastAt), 0, $idShop)) {
                    ++$failed;
                    continue;
                }
                self::mark($idShop, $email, $lastAt, 'sent');
            }
            ++$sent;
        }

        $output->writeln(sprintf('%s%d customer(s), skipped %d, failed %d', $dryRun ? 'would send ' : 'sent ', $sent, $skipped, $failed));

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Last-order range [from, to] for customers who are due: `to` is `days`
     * ago, `from` is `window` days before that.
     *
     * @return array{0:string,1:string}
     */
    public static function bounds(int $now, int $days, int $window): array
    {
        return [
            date('Y-m-d H:i:s', $now - ($days + $window) * 86400),
            date('Y-m-d H:i:s', $now - $days * 86400),
        ];
    }

    /**
     * Subscribed customers (any account or guest row with newsletter = 1)
     * whose last valid order is in (from, to] and not yet handled.
     *
     * @return array<int, array{email:string,last_at:string,id_lang:string}>
     */
    private static function customers(int $idShop, ?string $from, ?string $to, int $limit, ?string $email = null): array
    {
        $where = 'w.`id_plunk_winback` IS NULL';
        if (null !== $from) {
            $where .= ' AND t.`last_at` > \'' . pSQL($from) . '\'';
        }
        if (null !== $to) {
            $where .= ' AND t.`last_at` <= \'' . pSQL($to) . '\'';
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT t.`email`, t.`last_at`, t.`id_lang` FROM (
                SELECT LOWER(c.`email`) AS email, MAX(o.`date_add`) AS last_at, MAX(c.`id_lang`) AS id_lang
                FROM `' . _DB_PREFIX_ . 'orders` o
                INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
                WHERE o.`valid` = 1 AND o.`id_shop` = ' . $idShop . ' AND c.`deleted` = 0'
                . (null !== $email ? ' AND c.`email` = \'' . pSQL($email) . '\'' : '') . '
                GROUP BY LOWER(c.`email`)
                HAVING MAX(c.`newsletter`) = 1
             ) t
             LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` w
               ON w.`email` = CONVERT(t.`email` USING utf8mb4) COLLATE utf8mb4_unicode_ci AND w.`id_shop` = ' . $idShop . ' AND w.`last_order_at` = t.`last_at`
             WHERE ' . $where . '
             ORDER BY t.`last_at` DESC
             LIMIT ' . $limit
        );

        return is_array($rows) ? $rows : [];
    }

    private static function mark(int $idShop, string $email, string $lastAt, string $status): void
    {
        \Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $idShop,
            'email' => pSQL($email),
            'last_order_at' => pSQL($lastAt),
            'status' => pSQL($status),
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, \Db::INSERT_IGNORE);
    }
}
