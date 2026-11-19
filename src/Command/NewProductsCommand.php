<?php

/**
 * `php bin/console plunkconnect:newsletter:new-products [--shop=] [--force] [--test=email] [--dry-run]`.
 *
 * When at least `minProducts` new products (active, visible, in stock, with a
 * cover image) appeared since the last send and `intervalDays` passed, creates
 * and sends a Plunk campaign to subscribed contacts. Settings come from the
 * `PLUNKCONNECT_NEWPROD` JSON: subject, from, fromName, replyTo, body (HTML
 * with `%PRODUCTS%` and `%NEW_URL%`), minProducts (3), count (3), intervalDays (7).
 * `--test` sends the campaign only to that address and keeps the schedule.
 */

namespace PlunkConnect\Command;

use PlunkConnect\Api\PlunkClient;
use PlunkConnect\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NewProductsCommand extends Command
{
    protected static $defaultName = 'plunkconnect:newsletter:new-products';

    protected function configure(): void
    {
        $this
            ->setName('plunkconnect:newsletter:new-products')
            ->setDescription('Send a "new products" Plunk campaign when enough new products appeared.')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop id (default: context shop)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore intervalDays')
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Send only a test to this email')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idShop = null !== $input->getOption('shop') ? (int) $input->getOption('shop') : (int) \Context::getContext()->shop->id;
        if (null !== $input->getOption('shop')) {
            // Prices, covers and links read the context shop.
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
            \Context::getContext()->shop = new \Shop($idShop);
        }
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $cfg = Config::getJson(Config::NEWPROD, $idShop);
        $test = (string) $input->getOption('test');
        foreach (['subject', 'from', 'body'] as $required) {
            if ('' === (string) ($cfg[$required] ?? '')) {
                $output->writeln('PLUNKCONNECT_NEWPROD.' . $required . ' is not set');

                return 1;
            }
        }
        $min = max(1, (int) ($cfg['minProducts'] ?? 3));
        $count = max(1, min(12, (int) ($cfg['count'] ?? 3)));
        $interval = max(1, (int) ($cfg['intervalDays'] ?? 7));

        $last = (string) Config::get(Config::NEWPROD_LAST, '', $idShop);
        if ('' === $test && !$input->getOption('force') && '' !== $last && strtotime($last) > time() - $interval * 86400) {
            $output->writeln('last sent ' . $last . ', next after ' . $interval . ' days');

            return 0;
        }
        $newDays = max(1, (int) \Configuration::get('PS_NB_DAYS_NEW_PRODUCT', null, null, $idShop) ?: 20);
        $since = '' !== $last ? $last : date('Y-m-d H:i:s', time() - $newDays * 86400);

        $candidates = self::candidates($idShop, $since);
        if (count($candidates) < $min) {
            $output->writeln(sprintf('%d new product(s) since %s, need %d', count($candidates), $since, $min));

            return 0;
        }
        $picked = self::pick($candidates, $count);

        $link = \Context::getContext()->link ?? new \Link();
        $cards = '';
        foreach ($picked as $idProduct) {
            $cards .= self::card($idProduct, $idLang, $idShop, $link);
        }
        $newUrl = $link->getPageLink('new-products', true, $idLang, null, false, $idShop);
        $body = strtr((string) $cfg['body'], ['%PRODUCTS%' => $cards, '%NEW_URL%' => $newUrl]);
        $output->writeln('products: ' . implode(', ', $picked));
        if ($input->getOption('dry-run')) {
            return 0;
        }

        $client = new PlunkClient($idShop);
        $secret = (string) Config::get(Config::SECRET_KEY, '', $idShop);
        $created = $client->request('POST', '/campaigns', $secret, [
            'name' => ('' !== $test ? '[TEST] ' : '') . 'Naujienos ' . date('Y-m-d'),
            'subject' => (string) $cfg['subject'],
            'body' => $body,
            'from' => (string) $cfg['from'],
            'fromName' => (string) ($cfg['fromName'] ?? '') ?: null,
            'replyTo' => (string) ($cfg['replyTo'] ?? '') ?: null,
            'type' => 'MARKETING',
            'audienceType' => 'FILTERED',
            'audienceCondition' => ['logic' => 'AND', 'groups' => [['filters' => [['field' => 'subscribed', 'operator' => 'equals', 'value' => true]]]]],
        ]);
        $id = (string) ($created['body']['data']['id'] ?? '');
        if ('' === $id) {
            $output->writeln('campaign create failed: HTTP ' . $created['status'] . ' ' . substr((string) $created['raw'], -300));

            return 1;
        }
        $sent = '' !== $test
            ? $client->request('POST', '/campaigns/' . $id . '/test', $secret, ['email' => $test])
            : $client->request('POST', '/campaigns/' . $id . '/send', $secret, []);
        if ($sent['status'] < 200 || $sent['status'] >= 300) {
            $output->writeln('campaign ' . $id . ' send failed: HTTP ' . $sent['status'] . ' ' . substr((string) $sent['raw'], -300));

            return 1;
        }
        if ('' === $test) {
            \Configuration::updateValue(Config::NEWPROD_LAST, date('Y-m-d H:i:s'), false, null, $idShop);
        }
        $output->writeln(('' !== $test ? 'test sent' : 'campaign sent') . ': ' . $id);

        return 0;
    }

    /**
     * @return array<int, array{id_product:int,id_category_default:int}>
     */
    private static function candidates(int $idShop, string $since): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT ps.`id_product`, ps.`id_category_default`
             FROM `' . _DB_PREFIX_ . 'product_shop` ps
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ims ON ims.`id_product` = ps.`id_product` AND ims.`id_shop` = ps.`id_shop` AND ims.`cover` = 1
             WHERE ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1 AND ps.`visibility` IN (\'both\', \'catalog\')
               AND ps.`date_add` > \'' . pSQL($since) . '\'
               AND (SELECT COALESCE(SUM(sa.`quantity`), 0) FROM `' . _DB_PREFIX_ . 'stock_available` sa
                    WHERE sa.`id_product` = ps.`id_product` AND sa.`id_product_attribute` = 0 AND sa.`id_shop` IN (0, ' . $idShop . ')) > 0
             ORDER BY ps.`date_add` DESC, ps.`id_product` DESC
             LIMIT 300'
        );

        return array_map(static fn (array $r): array => ['id_product' => (int) $r['id_product'], 'id_category_default' => (int) $r['id_category_default']], is_array($rows) ? $rows : []);
    }

    /**
     * Newest first, one product per default category before repeating one.
     *
     * @param array<int, array{id_product:int,id_category_default:int}> $candidates
     *
     * @return int[]
     */
    public static function pick(array $candidates, int $count): array
    {
        $picked = [];
        $categories = [];
        foreach ($candidates as $c) {
            if (count($picked) < $count && !isset($categories[$c['id_category_default']])) {
                $picked[] = $c['id_product'];
                $categories[$c['id_category_default']] = true;
            }
        }
        foreach ($candidates as $c) {
            if (count($picked) < $count && !in_array($c['id_product'], $picked, true)) {
                $picked[] = $c['id_product'];
            }
        }

        return $picked;
    }

    private static function card(int $idProduct, int $idLang, int $idShop, \Link $link): string
    {
        $product = new \Product($idProduct, false, $idLang, $idShop);
        $cover = \Product::getCover($idProduct);
        $image = $link->getImageLink((string) $product->link_rewrite, (string) ($cover['id_image'] ?? 0), 'home_default');
        $url = $link->getProductLink($product, null, null, null, $idLang, $idShop);
        $price = \Product::getPriceStatic($idProduct, true);
        $regular = \Product::getPriceStatic($idProduct, true, null, 2, null, false, false);
        $name = htmlspecialchars((string) $product->name, ENT_QUOTES, 'UTF-8');
        $priceHtml = self::money($price) . ($regular > $price + 0.009 ? ' <s style="color:#6b7478;font-weight:normal">' . self::money($regular) . '</s>' : '');

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #e5e9eb;border-radius:8px"><tr>'
            . '<td width="140" style="padding:12px"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '"><img src="' . htmlspecialchars($image, ENT_QUOTES) . '" alt="' . $name . '" width="120" style="display:block;border:0;width:120px;max-width:120px;height:auto"></a></td>'
            . '<td style="padding:12px 12px 12px 0;vertical-align:middle"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="color:#1d2a30;text-decoration:none;font-size:15px;line-height:1.4">' . $name . '</a>'
            . '<div style="margin-top:6px;font-weight:bold;font-size:15px">' . $priceHtml . '</div></td></tr></table>';
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ') . ' €';
    }
}
