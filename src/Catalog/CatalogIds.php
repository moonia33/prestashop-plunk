<?php

/**
 * Plunk catalog ids (externalId), shared by catalog sync, events,
 * recommendations and live-product payloads.
 *
 * - sellable unit (simple product or combination): its PS `reference`
 *   (= SKU) when filled and unique across all sellable units;
 * - otherwise, and for parents of combinations: "PS-<id_product>[-<attr>]".
 *
 * The id last accepted by Plunk is stored in `plunk_catalog_id`, so a
 * fixed reference replaces the old catalog row (upsert new, then delete
 * old) and ids coming back from Plunk resolve without guessing.
 */

namespace PlunkConnect\Catalog;

final class CatalogIds
{
    public const TABLE = 'plunk_catalog_id';

    private const FALLBACK_PREFIX = 'PS-';
    private const MAX_LENGTH = 64;
    private const REF_CHUNK = 500;

    public static function fallback(int $idProduct, int $idProductAttribute = 0): string
    {
        return self::FALLBACK_PREFIX . $idProduct . ($idProductAttribute > 0 ? '-' . $idProductAttribute : '');
    }

    /** Case/whitespace-insensitive key used to detect duplicate references. */
    public static function refKey(string $reference): string
    {
        return mb_strtolower(trim($reference));
    }

    /**
     * Id for one sellable unit given how many sellable units share its
     * reference (case-insensitive).
     */
    public static function choose(string $reference, int $sameRefCount, int $idProduct, int $idProductAttribute = 0): string
    {
        $ref = trim($reference);
        if ('' === $ref
            || 1 !== $sameRefCount
            || mb_strlen($ref) > self::MAX_LENGTH
            || preg_match('/^PS-\d+(-\d+)?$/i', $ref)) {
            return self::fallback($idProduct, $idProductAttribute);
        }

        return $ref;
    }

    /**
     * Parses ids this module can build without the map table:
     * "PS-<p>[-<a>]" and the pre-1.3 "<p>[-<a>]" format.
     *
     * @return array{0:int,1:int}|null [id_product, id_product_attribute]
     */
    public static function parse(string $externalId): ?array
    {
        if (preg_match('/^(?:PS-)?(\d+)(?:-(\d+))?$/i', trim($externalId), $m)) {
            return [(int) $m[1], (int) ($m[2] ?? 0)];
        }

        return null;
    }

    /**
     * Current ids for products: [id_product => [id_attr => externalId]],
     * id_attr 0 = the product itself (simple product or parent).
     * Stored ids win over computed ones unless $fresh (catalog sync).
     *
     * @param int[] $ids
     *
     * @return array<int, array<int, string>>
     */
    public static function forProducts(array $ids, int $idShop, bool $fresh = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $out = self::compute($ids);
        if (!$fresh) {
            foreach (self::stored($ids, $idShop) as $idProduct => $units) {
                foreach ($units as $idAttr => $externalId) {
                    $out[$idProduct][$idAttr] = $externalId;
                }
            }
        }

        return $out;
    }

    public static function forUnit(int $idProduct, int $idProductAttribute, int $idShop): string
    {
        $ids = self::forProducts([$idProduct], $idShop);

        return $ids[$idProduct][$idProductAttribute] ?? self::fallback($idProduct, $idProductAttribute);
    }

    /**
     * externalId -> [id_product, id_product_attribute]; unknown ids are
     * omitted.
     *
     * @param string[] $externalIds
     *
     * @return array<string, array{0:int,1:int}>
     */
    public static function resolve(array $externalIds, int $idShop): array
    {
        $externalIds = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $externalIds), fn ($v) => '' !== $v)));
        if (!$externalIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($externalIds, self::REF_CHUNK) as $chunk) {
            $rows = \Db::getInstance()->executeS(
                'SELECT `id_product`, `id_product_attribute`, `external_id` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                 WHERE `id_shop` = ' . $idShop . ' AND `external_id` IN (' . self::quoteList($chunk) . ')'
            ) ?: [];
            foreach ($rows as $row) {
                $out[(string) $row['external_id']] = [(int) $row['id_product'], (int) $row['id_product_attribute']];
            }
        }
        foreach ($externalIds as $id) {
            if (!isset($out[$id]) && null !== ($parsed = self::parse($id))) {
                $out[$id] = $parsed;
            }
        }

        return $out;
    }

    /**
     * Store the ids just sent to Plunk. Returns previously sent ids that
     * no unit uses any more - the caller deletes them from Plunk.
     *
     * @param array<int, array<int, string>> $assigned [id_product => [id_attr => externalId]]
     *
     * @return string[]
     */
    public static function remember(array $assigned, int $idShop): array
    {
        if (!$assigned) {
            return [];
        }
        $db = \Db::getInstance();
        $table = _DB_PREFIX_ . self::TABLE;
        $previous = self::stored(array_keys($assigned), $idShop);

        $stale = [];
        $values = [];
        $now = pSQL(date('Y-m-d H:i:s'));
        foreach ($assigned as $idProduct => $units) {
            foreach ($units as $idAttr => $externalId) {
                $old = $previous[$idProduct][$idAttr] ?? null;
                if (null !== $old && $old !== $externalId) {
                    $stale[] = $old;
                }
                $values[] = '(' . $idShop . ', ' . (int) $idProduct . ', ' . (int) $idAttr . ', \''
                    . pSQL($externalId) . '\', \'' . $now . '\')';
            }
            foreach (array_diff_key($previous[$idProduct] ?? [], $units) as $old) {
                $stale[] = $old; // combination no longer exists
            }
        }

        $db->execute(
            'DELETE FROM `' . $table . '` WHERE `id_shop` = ' . $idShop
            . ' AND `id_product` IN (' . implode(',', array_map('intval', array_keys($assigned))) . ')'
        );
        foreach (array_chunk($values, self::REF_CHUNK) as $chunk) {
            // REPLACE: an id taken over by another unit moves to it.
            $db->execute(
                'REPLACE INTO `' . $table . '` (`id_shop`, `id_product`, `id_product_attribute`, `external_id`, `date_upd`)
                 VALUES ' . implode(',', $chunk)
            );
        }

        $stale = array_values(array_unique($stale));
        if (!$stale) {
            return [];
        }
        $stillUsed = array_column($db->executeS(
            'SELECT `external_id` FROM `' . $table . '`
             WHERE `id_shop` = ' . $idShop . ' AND `external_id` IN (' . self::quoteList($stale) . ')'
        ) ?: [], 'external_id');

        return array_values(array_diff($stale, array_map('strval', $stillUsed)));
    }

    /**
     * The given ids that a unit currently owns in the map table.
     *
     * @param string[] $externalIds
     *
     * @return string[]
     */
    public static function owned(array $externalIds, int $idShop): array
    {
        $owned = [];
        foreach (array_chunk(array_values(array_unique($externalIds)), self::REF_CHUNK) as $chunk) {
            $rows = \Db::getInstance()->executeS(
                'SELECT `external_id` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                 WHERE `id_shop` = ' . $idShop . ' AND `external_id` IN (' . self::quoteList($chunk) . ')'
            ) ?: [];
            foreach ($rows as $row) {
                $owned[] = (string) $row['external_id'];
            }
        }

        return $owned;
    }

    /** Ids stored for a product (what Plunk has) - used before deletes. */
    public static function storedForProduct(int $idProduct, int $idShop): array
    {
        return array_values(self::stored([$idProduct], $idShop)[$idProduct] ?? []);
    }

    public static function forget(int $idShop, int $idProduct, ?int $idProductAttribute = null): void
    {
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_shop` = ' . $idShop
            . ' AND `id_product` = ' . $idProduct
            . (null !== $idProductAttribute ? ' AND `id_product_attribute` = ' . $idProductAttribute : '')
        );
    }

    // ------------------------------------------------------------------

    /**
     * @param int[] $ids
     *
     * @return array<int, array<int, string>>
     */
    private static function compute(array $ids): array
    {
        $db = \Db::getInstance();
        $idList = implode(',', $ids);
        $products = $db->executeS(
            'SELECT `id_product`, `reference` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` IN (' . $idList . ')'
        ) ?: [];
        $combos = $db->executeS(
            'SELECT `id_product`, `id_product_attribute`, `reference` FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE `id_product` IN (' . $idList . ')'
        ) ?: [];

        $combosByProduct = [];
        foreach ($combos as $row) {
            $combosByProduct[(int) $row['id_product']][(int) $row['id_product_attribute']] = (string) $row['reference'];
        }

        $units = []; // [id_product, id_attr, reference]
        foreach ($products as $row) {
            $idProduct = (int) $row['id_product'];
            if (isset($combosByProduct[$idProduct])) {
                foreach ($combosByProduct[$idProduct] as $idAttr => $ref) {
                    $units[] = [$idProduct, $idAttr, $ref];
                }
            } else {
                $units[] = [$idProduct, 0, (string) $row['reference']];
            }
        }

        $counts = self::referenceCounts(array_column($units, 2));
        $out = [];
        foreach ($products as $row) {
            $idProduct = (int) $row['id_product'];
            if (isset($combosByProduct[$idProduct])) {
                $out[$idProduct][0] = self::fallback($idProduct);
            }
        }
        foreach ($units as [$idProduct, $idAttr, $ref]) {
            $out[$idProduct][$idAttr] = self::choose($ref, $counts[self::refKey($ref)] ?? 0, $idProduct, $idAttr);
        }

        return $out;
    }

    /**
     * How many sellable units (simple products + combinations, whole shop
     * database) use each reference.
     *
     * @param string[] $references
     *
     * @return array<string, int> refKey => count
     */
    private static function referenceCounts(array $references): array
    {
        $refs = array_values(array_unique(array_filter(array_map('trim', $references), fn ($v) => '' !== $v)));
        $counts = [];
        foreach (array_chunk($refs, self::REF_CHUNK) as $chunk) {
            $in = self::quoteList($chunk);
            $rows = \Db::getInstance()->executeS(
                'SELECT p.`reference` FROM `' . _DB_PREFIX_ . 'product` p
                 WHERE p.`reference` IN (' . $in . ')
                   AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.`id_product` = p.`id_product`)
                 UNION ALL
                 SELECT pa.`reference` FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                 WHERE pa.`reference` IN (' . $in . ')'
            ) ?: [];
            foreach ($rows as $row) {
                $key = self::refKey((string) $row['reference']);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array<int, string>>
     */
    private static function stored(array $ids, int $idShop): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_product`, `id_product_attribute`, `external_id` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_product` IN (' . implode(',', array_map('intval', $ids)) . ')'
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id_product']][(int) $row['id_product_attribute']] = (string) $row['external_id'];
        }

        return $out;
    }

    /** @param string[] $values */
    private static function quoteList(array $values): string
    {
        return implode(',', array_map(fn ($v) => '\'' . pSQL((string) $v) . '\'', $values));
    }
}
