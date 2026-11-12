<?php

/**
 * `php bin/console plunkconnect:outbox:process [--limit=200] [--max-seconds=50]`
 * Cron every minute; also performs the sent-rows cleanup.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Outbox\OutboxProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessOutboxCommand extends Command
{
    protected static $defaultName = 'plunkconnect:outbox:process';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:outbox:process')
            ->setDescription('Send pending Plunk outbox rows (batch, retry, cleanup).')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max rows per run', 200)
            ->addOption('max-seconds', null, InputOption::VALUE_OPTIONAL, 'Time budget', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processor = new OutboxProcessor();
        $stats = $processor->process(
            (int) $input->getOption('limit'),
            (int) $input->getOption('max-seconds')
        );
        $deleted = $processor->cleanup();

        $output->writeln(sprintf(
            'claimed=%d sent=%d retried=%d dead=%d cleaned=%d',
            $stats['claimed'],
            $stats['sent'],
            $stats['retried'],
            $stats['dead'],
            $deleted
        ));

        return 0;
    }
}
