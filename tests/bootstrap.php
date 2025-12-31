<?php
/**
 * PHPUnit Bootstrap for TraceKit APM Tests
 */

// Set APP_ENV early to prevent "Undefined array key" warnings
// Set to "dev" as default, can be overridden in individual tests
if (!isset($_ENV['APP_ENV'])) {
    $_ENV['APP_ENV'] = 'dev';
}
if (!isset($_SERVER['APP_ENV'])) {
    $_SERVER['APP_ENV'] = 'dev';
}

// Set flag to indicate we're running tests (for suppressing verbose logging)
if (!defined('PHPUNIT_TEST')) {
    define('PHPUNIT_TEST', true);
}
$_ENV['PHPUNIT_TEST'] = '1';
$_SERVER['PHPUNIT_TEST'] = '1';

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

