<?php

/**
 * Minimal PrestaShop API surface for PHPStan analysis.
 * These signatures mirror the real classes' public API as used by this module.
 */
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.4');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

/**
 * @param string $string
 */
function pSQL($string, bool $htmlOK = false): string
{
    return (string) $string;
}

class Db
{
    /** @return Db */
    public static function getInstance(bool $master = true)
    {
        return new self();
    }

    /** @return bool */
    public function execute(string $sql, bool $useCache = true)
    {
        return true;
    }

    /**
     * @param string|DbQuery $sql
     *
     * @return array<int, array<string, mixed>>|false
     */
    public function executeS($sql, bool $array = true, bool $useCache = true)
    {
        return [];
    }

    /** @return array<string, mixed>|false */
    public function getRow(string $sql, bool $useCache = true)
    {
        return false;
    }

    /** @return string|false */
    public function getValue(string $sql, bool $useCache = true)
    {
        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data, bool $nullValues = false, bool $useCache = true, int $type = Db::INSERT): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data, string $where = '', int $limit = 0, bool $nullValues = false, bool $useCache = true, bool $addPrefix = true): bool
    {
        return true;
    }

    public function delete(string $table, string $where = '', int $limit = 0, bool $useCache = true, bool $addPrefix = true): bool
    {
        return true;
    }

    /** @return int|string */
    public function Insert_ID()
    {
        return 0;
    }

    /** @return int */
    public function Affected_Rows()
    {
        return 0;
    }

    public const INSERT = 1;
    public const REPLACE = 3;
}

class DbQuery
{
    public function select(string $fields): self
    {
        return $this;
    }

    public function from(string $table, string $alias = ''): self
    {
        return $this;
    }

    public function where(string $conditions): self
    {
        return $this;
    }

    public function leftJoin(string $table, string $alias, string $on): self
    {
        return $this;
    }

    public function build(): string
    {
        return '';
    }
}

class Validate
{
    public static function isEmail($email): bool
    {
        return true;
    }

    public static function isAbsoluteUrl(string $url): bool
    {
        return true;
    }

    /** @param object $object */
    public static function isLoadedObject($object): bool
    {
        return true;
    }
}

class Tools
{
    public static function substr(string $str, int $start, ?int $length = null, string $encoding = 'utf-8'): string
    {
        return (string) substr($str, $start, $length);
    }

    public static function redirect(string $url = '', string $baseUri = __PS_BASE_URI__, ?Link $link = null, array $headers = []): void
    {
    }

    public static function usingSecureMode(): bool
    {
        return true;
    }

    /**
     * @param string $key
     */
    public static function getValue($key, $defaultValue = false)
    {
        return $defaultValue;
    }

    /** @return string|int|false */
    public static function getRemoteAddr()
    {
        return '127.0.0.1';
    }

    public static function isSubmit(string $submit): bool
    {
        return false;
    }

    public static function getAdminTokenLite(string $tab): string
    {
        return '';
    }

    /** @param float|int|string $value */
    public static function ps_round($value, int $precision = 0, ?int $roundMode = null): float
    {
        return (float) $value;
    }
}

class Configuration
{
    public static function deleteByName(string $key): bool
    {
        return true;
    }

    /**
     * @param string $key
     */
    /** @return string|int|float|bool|null */
    public static function get($key, ?int $idLang = null, ?int $idShopGroup = null, ?int $idShop = null, $default = false)
    {
        return self::$testValues[$key] ?? $default;
    }

    /** @var array<string, string|int|float|bool|null> */
    public static array $testValues = [];

    public static function updateValue(string $key, $value, bool $html = false, ?int $idShopGroup = null, ?int $idShop = null): bool
    {
        return true;
    }
}

class PrestaShopLogger
{
    /** @param int|string|null $idObject */
    public static function addLog(string $message, int $severity = 1, $errorCode = null, ?string $objectType = null, $idObject = null, bool $allowDuplicate = false, ?int $idEmployee = null): void
    {
    }
}

class ObjectModel
{
    /** @var int|string */
    public $id;

    public function save(): bool
    {
        return true;
    }

    /** @param bool $autoDate */
    public function add($autoDate = true, bool $nullValues = false): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
}

class Language extends ObjectModel
{
    /** @var string */
    public $iso_code;

    /** @var string */
    public $name;

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getLanguages(bool $active = true, $idShop = false, bool $idsOnly = false): array
    {
        return [];
    }

    /** @return int|false */
    public static function getIdByIso(string $isoCode, int $idShop = 0)
    {
        return 1;
    }

    /** @return string|false */
    public static function getIsoById(?int $idLang = null)
    {
        return 'en';
    }
}

class Customer extends ObjectModel
{
    /** @var string */
    public $email = '';
    /** @var string */
    public $firstname = '';
    /** @var string */
    public $lastname = '';
    /** @var int */
    public $id_lang = 0;
    /** @var int */
    public $id_shop = 0;
    /** @var int|bool */
    public $newsletter = 0;
    /** @var string|null */
    public $ip_registration_newsletter;
    /** @var string */
    public $date_add = '';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    public function isLogged(bool $withGuest = false): bool
    {
        return false;
    }

    /** @return int|bool */
    public static function customerExists(string $email, bool $returnId = false, bool $ignoreGuest = true)
    {
        return true;
    }
}

class Shop
{
    public const CONTEXT_SHOP = 1;
    public const CONTEXT_GROUP = 2;
    public const CONTEXT_ALL = 4;

    public static function getContext(): int
    {
        return self::CONTEXT_SHOP;
    }

    /** @return int|null */
    public static function getContextShopID(bool $nullValueWithoutShopContext = false)
    {
        return 1;
    }

    /** @var int */
    public $id = 1;
    /** @var int */
    public $id_shop_group = 1;

    /** @return array<int, array<string, mixed>> */
    public static function getShops(bool $active = true, ?int $idShopGroup = null, bool $getAsListId = false): array
    {
        return [];
    }

    public function getBaseURL(bool $autoSecureMode = false, bool $addBaseUri = true): string
    {
        return '';
    }
}

class Context
{
    /** @var Shop */
    public $shop;
    /** @var Language */
    public $language;
    /** @var Customer */
    public $customer;
    /** @var Cart */
    public $cart;
    /** @var Currency */
    public $currency;
    /** @var Link */
    public $link;
    /** @var Cookie */
    public $cookie;
    /** @var FrontController|null */
    public $controller;

    public static function getContext(): self
    {
        return new self();
    }

    public function getComputingPrecision(): int
    {
        return 2;
    }
}

class Module
{
    /** @return int|false */
    public static function getModuleIdByName(string $name)
    {
        return false;
    }

    /** @var string */
    public $name = '';
    /** @var bool */
    public $active = false;
    /** @var int|string|null */
    public $id = 0;
    /** @var Context */
    public $context;
    /** @var string */
    public $displayName = '';
    /** @var string */
    public $version = '1.0.0';
    /** @var string */
    public $tab = '';
    /** @var string */
    public $author = '';
    /** @var int */
    public $need_instance = 0;
    /** @var bool */
    public $bootstrap = false;
    /** @var array{min: string, max: string} */
    public $ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
    /** @var string */
    public $display = '';
    /** @var string */
    public $description = '';
    /** @var string */
    public $confirmUninstall = '';

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /** @param string|string[] $hookName */
    public function registerHook($hookName, ?array $shopList = null): bool
    {
        return true;
    }

    /** @return static|false */
    public static function getInstanceByName(string $moduleName)
    {
        return false;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function trans(string $id, array $params = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function l($string, bool $specific = false, ?string $locale = null): string
    {
        return (string) $string;
    }

    public function displayConfirmation(string $string): string
    {
        return $string;
    }

    /** @param string|string[] $string */
    public function displayError($string): string
    {
        return '';
    }
}

class FrontController
{
    /** @var Context */
    public $context;
    /** @var string */
    public $php_self = '';
    /** @var string[] */
    public $errors = [];
    /** @var Module */
    public $module;

    public function init(): void
    {
    }

    public function postProcess(): void
    {
    }
}

class ModuleFrontController extends FrontController
{
    /** @var Module */
    public $module;
}

class AdminController
{
    /** @var string */
    public static $currentIndex = '';
}

class HelperForm
{
    /** @var Module|null */
    public $module;
    /** @var string */
    public $name_controller = '';
    /** @var string */
    public $token = '';
    /** @var string */
    public $currentIndex = '';
    /** @var int */
    public $default_form_language = 1;
    /** @var int */
    public $allow_employee_form_lang = 0;
    /** @var string */
    public $title = '';
    /** @var string */
    public $submit_action = '';
    /** @var array<string, mixed> */
    public $fields_value = [];

    /** @param array<int, array<string, mixed>> $forms */
    public function generateForm(array $forms): string
    {
        return '';
    }
}

class OrderState extends ObjectModel
{
    /** @return array<int, array<string, mixed>> */
    public static function getOrderStates(int $idLang): array
    {
        return [];
    }
}

class Currency extends ObjectModel
{
    /** @var string */
    public $iso_code = 'EUR';
    /** @var string */
    public $sign = '€';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    /** @return int|false */
    public static function getIdByIsoCode(string $isoCode, int $idShop = 0)
    {
        return 1;
    }
}

/**
 * @property mixed $id_cart
 * @property mixed $plunk_views
 * @property mixed $plunk_nonce
 * @property mixed $plunk_vd
 * @property mixed $plunk_rv
 */
class Cookie
{
    public function __get(string $key)
    {
        return null;
    }

    public function __set(string $key, $value): void
    {
    }

    /** @param string|int|null $expire */
    public function setExpire($expire): void
    {
    }

    public function write(): void
    {
    }
}

class Link
{
    /** @param array<string, mixed> $params */
    public function getModuleLink(string $module, string $controller = 'default', array $params = [], ?bool $ssl = null, ?int $idLang = null, $idShop = null, bool $relativeProtocol = false): string
    {
        return '';
    }

    public function getPageLink(string $controller, ?bool $ssl = null, $idLang = null, $request = null, bool $requestUrlEncode = false, $idShop = null, bool $relativeProtocol = false): string
    {
        return '';
    }

    /** @param Category|int $category */
    public function getCategoryLink($category, ?string $alias = null, $idLang = null, ?string $selectedFilters = null, $idShop = null, bool $relativeProtocol = false): string
    {
        return '';
    }

    /** @param Product|int $product */
    /** @param Product|string $product */
    public function getImageLink($product, string $idImage, ?string $type = null): string
    {
        return '';
    }

    public function getProductLink($product, ?string $alias = null, $category = null, $ean13 = null, $idLang = null, $idShop = null, $idProductAttribute = null, bool $force_routes = false, bool $relativeProtocol = false, bool $withIdInAnchor = false, array $extraParams = [], ?bool $addAnchor = null): string
    {
        return '';
    }
}

class Cart extends ObjectModel
{
    public const BOTH = 1;

    /** @var int */
    public $id_customer = 0;
    /** @var int */
    public $id_lang = 0;
    /** @var int */
    public $id_currency = 0;
    /** @var int */
    public $id_shop = 0;
    /** @var string */
    public $secure_key = '';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    /** @param bool $full */
    public function getProducts($full = false): array
    {
        return [];
    }

    public function getOrderTotal(bool $withTaxes = true, int $type = 1, ?array $products = null, ?int $id_carrier = null, bool $use_cache = true, bool $keepOrderPrices = false): float
    {
        return 0.0;
    }

    /** @return int|false */
    public function orderExists()
    {
        return false;
    }
}

class Order extends ObjectModel
{
    /** @var int */
    public $id_customer = 0;
    /** @var int */
    public $id_cart = 0;
    /** @var int */
    public $id_currency = 0;
    /** @var int */
    public $id_lang = 0;
    /** @var int */
    public $current_state = 0;
    /** @var int */
    public $id_carrier = 0;
    /** @var float */
    public $total_paid = 0.0;
    /** @var float */
    public $total_shipping = 0.0;
    /** @var string */
    public $reference = '';
    /** @var int */
    public $id_shop = 0;
    /** @var float */
    public $total_paid_tax_incl = 0.0;
    /** @var float */
    public $total_shipping_tax_incl = 0.0;
    /** @var float */
    public $total_discounts_tax_incl = 0.0;
    /** @var string */
    public $payment = '';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function getProducts(): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getShipping(): array
    {
        return [];
    }
}

class Product extends ObjectModel
{
    /** @var string */
    public $name = '';
    /** @var float */
    public $price = 0.0;
    /** @var int */
    public $id_category_default = 0;
    /** @var string */
    public $link_rewrite = '';
    /** @var string */
    public $reference = '';
    /** @var string */
    public $ean13 = '';
    /** @var bool|int */
    public $active = true;

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    public static function getPriceStatic(int $idProduct, bool $withTaxes = true, $idProductAttribute = null, int $decimals = 6, ?int $divisor = null, bool $onlyReduction = false, bool $useReduction = false, int $quantity = 1, bool $forceAssociatedTax = false, $idCustomer = null, $idCart = null, $idAddress = null, &$specificPriceOutput = null, bool $withEcotax = true, bool $useGroupReduction = true, ?int $idCustomization = null, bool $useCustomerPrice = true, $idShop = null, bool $useTax = true): ?float
    {
        return 0.0;
    }
}

class Category extends ObjectModel
{
    /** @var string */
    public $name = '';
    /** @var string */
    public $link_rewrite = '';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }
}

class Carrier extends ObjectModel
{
    /** @var string */
    public $name = '';
    /** @var string */
    public $url = '';

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }
}

class CartRule extends ObjectModel
{
    /** @var string|array<int, string> */
    public $name = '';
    /** @var string */
    public $description = '';
    /** @var bool|int */
    public $highlight = false;
    /** @var bool|int */
    public $partial_use = false;
    /** @var bool|int */
    public $minimum_amount_tax = false;
    /** @var int */
    public $minimum_amount_currency = 0;
    /** @var bool|int */
    public $minimum_amount_shipping = false;
    /** @var bool|int */
    public $reduction_tax = true;
    /** @var bool|int */
    public $free_shipping = false;
    /** @var string */
    public $code = '';
    /** @var int */
    public $quantity = 1;
    /** @var int */
    public $quantity_per_user = 1;
    /** @var int */
    public $id_customer = 0;
    /** @var float|string */
    public $minimum_amount = 0;
    /** @var bool|int */
    public $reduction_exclude_special = false;
    /** @var bool|int */
    public $cart_rule_restriction = false;
    /** @var float */
    public $reduction_percent = 0.0;
    /** @var float */
    public $reduction_amount = 0.0;
    /** @var int */
    public $reduction_currency = 0;
    /** @var string */
    public $date_from = '';
    /** @var string */
    public $date_to = '';
    /** @var bool|int */
    public $active = true;

    /** @param int|string|null $id */
    public function __construct($id = null)
    {
    }

    /** @param bool $autoAdd */
    public function add($autoAdd = true, bool $nullValues = false): bool
    {
        return true;
    }
}

class StockAvailable extends ObjectModel
{
    /** @var int */
    public $quantity = 0;
    /** @var int */
    public $id_product = 0;
    /** @var int */
    public $id_product_attribute = 0;
}
