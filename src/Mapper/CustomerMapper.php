<?php

/**
 * Shared contact `data` fields common to all events/upserts.
 * Only small scalars are persistent; arrays go to the event body.
 */

namespace PlunkConnect\Mapper;

use PlunkConnect\Config;

final class CustomerMapper
{
    /**
     * Base fields attached to every payload: locale + shopCode.
     */
    public static function base(int $idLang, ?int $idShop = null): array
    {
        return [
            'locale' => LocaleMapper::forLang($idLang, $idShop),
            'shopCode' => (string) Config::get(Config::SHOP_CODE, '', $idShop),
        ];
    }

    /**
     * Contact fields for a known Customer object.
     */
    public static function contact(\Customer $customer, ?int $idShop = null): array
    {
        $data = self::base((int) $customer->id_lang, $idShop);
        $data['firstName'] = (string) $customer->firstname;
        $data['lastName'] = (string) $customer->lastname;
        $data['customerId'] = (int) $customer->id;
        if (!empty($customer->newsletter)) {
            $data['isCustomer'] = true;
        }

        return $data;
    }
}
