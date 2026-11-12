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
    /** @param mixed $email */
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
    /**
     * @param string $key
     * @param mixed  $defaultValue
     *
     * @return mixed
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
    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function get($key, ?int $idLang = null, ?int $idShopGroup = null, ?int $idShop = null, $default = false)
    {
        return $default;
    }

    /** @param mixed $value */
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
}

class Shop
{
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

    public static function getContext(): self
    {
        return new self();
    }
}

class Module
{
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

class OrderState
{
    /** @return array<int, array<string, mixed>> */
    public static function getOrderStates(int $idLang): array
    {
        return [];
    }
}
