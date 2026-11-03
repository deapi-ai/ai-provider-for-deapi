<?php

/**
 * PHPUnit bootstrap file for the AI Provider for deAPI package.
 *
 * @package DeApi\AiProvider
 */

declare(strict_types=1);

// The plugin files require ABSPATH, as WordPress defines it.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/autoload.php';
