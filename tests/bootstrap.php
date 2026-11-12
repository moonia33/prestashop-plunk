<?php

/**
 * PlunkConnect – test bootstrap.
 *
 * Unit tests cover the dependency-free classes only. PrestaShop-facing code
 * is exercised via a minimal stub surface declared here.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PlunkConnect\\';
    if (0 === strncmp($class, $prefix, strlen($prefix))) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Minimal PrestaShop stubs needed by otherwise pure classes.
if (!class_exists('Validate')) {
    class Validate
    {
        public static function isEmail($email): bool
        {
            return is_string($email) && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        }
    }
}
