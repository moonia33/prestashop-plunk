<?php

/**
 * `php bin/console plunkconnect:coupons:cleanup [--dry-run]`
 * Deletes module-created CartRules that expired and were never used, plus
 * their plunk_coupon tracking rows.
 */

namespace PlunkConnect\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupCouponsCommand extends Command
{
    protected static $defaultName = 'plunkconnect:coupons:cleanup';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:coupons:cleanup')
            ->setDescription('Delete expired, unused Plunk coupons (CartRule + tracking rows).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $db = \Db::getInstance();

        $rows = $db->executeS(
            'SELECT c.`id_plunk_coupon`, c.`id_cart_rule`, c.`code`
             FROM `' . _DB_PREFIX_ . 'plunk_coupon` c
             LEFT JOIN `' . _DB_PREFIX_ . 'order_cart_rule` ocr
               ON ocr.`id_cart_rule` = c.`id_cart_rule`
             WHERE c.`date_exp` < NOW()
               AND ocr.`id_order_cart_rule` IS NULL'
        );

        $deleted = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!$dryRun) {
                $db->delete('plunk_coupon', '`id_plunk_coupon` = ' . (int) $row['id_plunk_coupon']);
                $rule = new \CartRule((int) $row['id_cart_rule']);
                if (\Validate::isLoadedObject($rule)) {
                    $rule->delete();
                }
            }
            ++$deleted;
        }

        $output->writeln(sprintf('%s%d expired unused coupon(s)', $dryRun ? 'would delete ' : 'deleted ', $deleted));

        return 0;
    }
}
