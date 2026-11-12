<?php

/**
 * `php bin/console plunkconnect:subscribers:backfill --since=YYYY-MM-DD [--dry-run]`.
 *
 * Sends subscribers created after the Newsman export to Plunk via the
 * `contacts_ifnew` endpoint: existing contacts keep their `subscribed`
 * flag, new contacts are created as subscribed. Prints a report: how many
 * new, how many already present, how many opted out.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Mapper\CustomerMapper;
use PlunkConnect\Outbox\Outbox;
use PlunkConnect\Service\SubscriptionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillSubscribersCommand extends Command
{
    protected static $defaultName = 'plunkconnect:subscribers:backfill';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:subscribers:backfill')
            ->setDescription('Push newsletter subscribers created after a given date to Plunk.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'ISO date, e.g. 2026-11-12')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count only, do not enqueue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = (string) $input->getOption('since');
        $ts = strtotime($since);
        if (false === $ts) {
            $output->writeln('<error>--since must be a valid date</error>');

            return 1;
        }
        $since = date('Y-m-d H:i:s', $ts);
        $dryRun = (bool) $input->getOption('dry-run');

        $customers = SubscriptionService::customerSubscribers($since);
        $guests = SubscriptionService::guestSubscribers($since);

        $queued = 0;
        $inactive = 0;
        foreach ($customers as $row) {
            if ($this->queue($row['email'], (int) $row['id_lang'], (int) $row['id_shop'], $dryRun, [
                'firstName' => (string) $row['firstname'],
                'lastName' => (string) $row['lastname'],
                'customerId' => (int) $row['id_customer'],
                'backfilled' => true,
            ])) {
                ++$queued;
            }
        }
        foreach ($guests as $row) {
            if (1 !== (int) $row['active']) {
                ++$inactive;
                continue;
            }
            if ($this->queue($row['email'], (int) $row['id_lang'], (int) $row['id_shop'], $dryRun, [
                'backfilled' => true,
            ])) {
                ++$queued;
            }
        }

        $output->writeln(sprintf(
            '%s: %d customer + %d guest rows since %s; queued=%d, opted_out_skipped=%d',
            $dryRun ? 'dry-run' : 'done',
            count($customers),
            count($guests),
            $since,
            $queued,
            $inactive
        ));

        return 0;
    }

    private function queue(string $email, int $idLang, int $idShop, bool $dryRun, array $extra): bool
    {
        $email = strtolower(trim($email));
        if (!\Validate::isEmail($email)) {
            return false;
        }
        if ($dryRun) {
            return true;
        }
        $data = CustomerMapper::base($idLang, $idShop);
        $data += $extra;

        return Outbox::enqueue('contacts_ifnew', [
            'email' => $email,
            'subscribed' => true,
            'data' => $data,
        ], 'backfill:' . md5($email));
    }
}
