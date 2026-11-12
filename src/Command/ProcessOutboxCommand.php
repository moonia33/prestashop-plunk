<?php

/**
 * PlunkConnect – Plunk email platform integration for PrestaShop.
 *
 * bin/console plunkconnect:outbox:process
 *
 * @author  TV Parkas
 * @license MIT
 */

declare(strict_types=1);

namespace PlunkConnect\Command;

use PlunkConnect\Outbox\OutboxProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessOutboxCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:outbox:process')
            ->setDescription('Send queued PlunkConnect outbox messages to the Plunk API')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max messages this run', OutboxProcessor::MAX_MESSAGES_PER_RUN)
            ->addOption('max-seconds', 't', InputOption::VALUE_OPTIONAL, 'Max run time in seconds', OutboxProcessor::MAX_RUNTIME_SECONDS)
            ->addOption('shop', 's', InputOption::VALUE_OPTIONAL, 'Only process this shop id')
            ->addOption('prune', 'p', InputOption::VALUE_NONE, 'Also delete sent rows older than 7 days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = \Module::getInstanceByName('plunkconnect');
        if (!$module instanceof \Plunkconnect || !$module->active) {
            $output->writeln('<error>Module plunkconnect is not installed/active</error>');

            return 1;
        }

        $processor = $module->getOutboxProcessor();
        $shop = $input->getOption('shop');

        $stats = $processor->process(
            (int) $input->getOption('limit'),
            (int) $input->getOption('max-seconds'),
            null !== $shop ? (int) $shop : null
        );

        $output->writeln(sprintf(
            'Processed: %d, sent: %d, retried: %d, dead: %d',
            $stats['processed'],
            $stats['sent'],
            $stats['retried'],
            $stats['dead']
        ));

        if ($input->getOption('prune')) {
            $output->writeln('Pruned sent rows: ' . $processor->prune(7));
        }

        return 0;
    }
}
