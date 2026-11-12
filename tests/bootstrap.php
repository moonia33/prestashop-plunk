<?php

/**
 * Unit-test bootstrap: loads the module's PSR-4 classes and provides a
 * minimal Validate stub for the framework-agnostic classes under test.
 */
require_once dirname(__DIR__) . '/autoload.php';

if (!class_exists('Validate')) {
    class Validate
    {
        public static function isEmail($email): bool
        {
            return is_string($email) && false !== filter_var($email, FILTER_VALIDATE_EMAIL);
        }
    }
}
