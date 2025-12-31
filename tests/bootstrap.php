<?php
/**
 * PHPUnit Bootstrap for TraceKit APM Tests
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Set test environment
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';

