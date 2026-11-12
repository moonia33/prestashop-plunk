<?php

/**
 * PSR-4 autoloader for PlunkConnect\ classes.
 *
 * Prefers Composer's autoloader (vendor/autoload.php) when available
 * (`composer install` inside the module directory). Falls back to a small
 * built-in PSR-4 loader so the module works when installed from a zip
 * without Composer - required for PrestaShop 8 and 9 zip installs.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!class_exists(PlunkConnect\Config::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'PlunkConnect\\';
        if (0 !== strpos($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}
