<?php

/**
 * `php bin/console plunkconnect:subscribers:reconcile [--apply] [--ps-only=report|unsubscribe]`.
 *
 * Compares Plunk contacts (cursor-paginated) with PrestaShop subscription
 * flags. Without --apply differences are only reported (nightly check).
 * With --apply Plunk is the source of truth: PS flags of emails Plunk knows
 * are set to Plunk's state; `--ps-only=unsubscribe` also unchecks PS
 * subscribers Plunk does not know at all.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Api\PlunkClient;
use PlunkConnect\Service\SubscriptionService;
use PlunkConnect\Util\EmailMask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileSubscribersCommand extends Command
{
    protected static $defaultName = 'plunkconnect:subscribers:reconcile';

    private const MAX_PAGES = 200; // 200 * 250 = 50k contacts cap per run

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:subscribers:reconcile')
            ->setDescription('Report subscription mismatches between PrestaShop and Plunk; --apply makes PS follow Plunk.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Set PS newsletter flags to the Plunk state (one-time sync)')
            ->addOption('ps-only', null, InputOption::VALUE_REQUIRED, 'PS subscribers unknown to Plunk: report|unsubscribe', 'report')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop id whose Plunk project and subscribers are compared (default: context shop)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $psOnlyMode = (string) $input->getOption('ps-only');
        if (!in_array($psOnlyMode, ['report', 'unsubscribe'], true)) {
            $output->writeln('<error>--ps-only must be report or unsubscribe</error>');

            return 1;
        }
        if ('unsubscribe' === $psOnlyMode && !$apply) {
            $output->writeln('<error>--ps-only=unsubscribe needs --apply</error>');

            return 1;
        }

        $idShop = null !== $input->getOption('shop') ? (int) $input->getOption('shop') : (int) \Context::getContext()->shop->id;
        $client = new PlunkClient($idShop);
        $cursor = null;
        $pages = 0;
        $known = [];
        $stats = ['checked' => 0, 'plunk_only' => 0, 'ps_only' => 0, 'subscribed_in_ps' => 0, 'unsubscribed_in_ps' => 0, 'not_in_ps' => 0, 'ps_unknown' => 0];

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
                ++$stats['checked'];
                $known[$email] = true;
                $plunkSubscribed = (bool) ($contact['subscribed'] ?? false);
                $target = self::targetPsState($plunkSubscribed, $this->psDiffers($email, $plunkSubscribed, $idShop));
                if (null === $target) {
                    continue;
                }
                ++$stats[$target ? 'plunk_only' : 'ps_only'];
                if (!$apply) {
                    $output->writeln(($target ? '  in_plunk_only: ' : '  in_ps_only:    ') . EmailMask::mask($email));
                    continue;
                }
                $result = SubscriptionService::applyWebhook($email, $target, $idShop);
                if (0 === $result['matched']) {
                    ++$stats['not_in_ps'];
                } else {
                    ++$stats[$target ? 'subscribed_in_ps' : 'unsubscribed_in_ps'];
                }
            }
            $cursor = $body['nextCursor'] ?? $body['cursor'] ?? null;
            ++$pages;
        } while ($cursor && $contacts && $pages < self::MAX_PAGES);

        if ($cursor && $pages >= self::MAX_PAGES) {
            // An incomplete Plunk list would make every unseen PS subscriber look unknown.
            $output->writeln('<comment>Page cap reached, PS subscribers unknown to Plunk were not checked.</comment>');
        } else {
            foreach ($this->psSubscriberEmails($idShop) as $email) {
                if (isset($known[$email])) {
                    continue;
                }
                ++$stats['ps_unknown'];
                if ($apply && 'unsubscribe' === $psOnlyMode) {
                    SubscriptionService::applyWebhook($email, false, $idShop);
                }
            }
        }

        $output->writeln(sprintf(
            'reconcile %s: checked=%d, plunk_only=%d, ps_only=%d, ps_unknown_to_plunk=%d%s',
            $apply ? 'applied' : 'report',
            $stats['checked'],
            $stats['plunk_only'],
            $stats['ps_only'],
            $stats['ps_unknown'],
            $apply ? sprintf(
                ' | ps subscribed=%d, ps unsubscribed=%d, no ps row=%d, unknown %s',
                $stats['subscribed_in_ps'],
                $stats['unsubscribed_in_ps'],
                $stats['not_in_ps'],
                'unsubscribe' === $psOnlyMode ? 'unsubscribed' : 'kept'
            ) : ''
        ));

        return 0;
    }

    /**
     * The PS state to write so PS follows Plunk, or null when every PS source already matches.
     */
    public static function targetPsState(bool $plunkSubscribed, bool $psDiffers): ?bool
    {
        return $psDiffers ? $plunkSubscribed : null;
    }

    /** @return string[] lower-cased emails subscribed in PS (customers and ps_emailsubscription) */
    private function psSubscriberEmails(int $idShop): array
    {
        $db = \Db::getInstance();
        $emails = [];
        $rows = $db->executeS('SELECT DISTINCT LOWER(`email`) AS email FROM `' . _DB_PREFIX_ . 'customer` WHERE `newsletter` = 1 AND `deleted` = 0 AND `id_shop` = ' . $idShop);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $emails[(string) $row['email']] = true;
        }
        if ($db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            $rows = $db->executeS('SELECT DISTINCT LOWER(`email`) AS email FROM `' . _DB_PREFIX_ . 'emailsubscription` WHERE `active` = 1 AND `id_shop` = ' . $idShop);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $emails[(string) $row['email']] = true;
            }
        }

        return array_keys($emails);
    }

    /**
     * Whether any PS source of the email (customer newsletter flag or
     * ps_emailsubscription row) is not in the given state: a guest row can
     * be active while the account checkbox is off, or the other way round.
     */
    private function psDiffers(string $email, bool $subscribed, int $idShop): bool
    {
        $db = \Db::getInstance();
        $flag = $subscribed ? 1 : 0;
        $customer = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($email) . '\' AND `newsletter` <> ' . $flag . ' AND `deleted` = 0 AND `id_shop` = ' . $idShop
        );
        if ($customer > 0) {
            return true;
        }
        if ($db->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . 'emailsubscription\'')) {
            return (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'emailsubscription`
                 WHERE `email` = \'' . pSQL($email) . '\' AND `active` <> ' . $flag . ' AND `id_shop` = ' . $idShop
            ) > 0;
        }

        return false;
    }
}
