<?php

/**
 * `php bin/console plunkconnect:catalog:sync [--full] [--from=<id>]`
 * Queues catalog upsert/patch outbox rows; a separate outbox run sends
 * them to Plunk (PUT/PATCH /catalog/products).
 */

namespace PlunkConnect\Command;

use PlunkConnect\Config;
use PlunkConnect\Service\CatalogSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCatalogCommand extends Command
{
    protected static $defaultName = 'plunkconnect:catalog:sync';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:catalog:sync')
            ->setDescription('Queue catalog sync jobs (incremental by default, --full for everything).')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Queue a full catalog sync')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Resume full sync from id_product', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Config::getBool(Config::FEAT_CATALOG_SYNC)) {
            $output->writeln('catalog sync disabled (FEAT_CATALOG_SYNC)');

            return 0;
        }
        $idShop = (int) (\Context::getContext()->shop->id ?? 0);

        if ($input->getOption('full')) {
            $stats = CatalogSyncService::queueFull($idShop, (int) $input->getOption('from'));
            $output->writeln(sprintf('queued %d upsert batch(es), %d product(s)', $stats['batches'], $stats['products']));
        } else {
            $stats = CatalogSyncService::queueIncremental($idShop);
            $output->writeln(sprintf('queued %d upsert batch(es), %d patch product(s)', $stats['upsert'], $stats['patch']));
        }

        return 0;
    }
}
