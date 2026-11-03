<?php

/**
 * Plugin Name:       AI Provider for deAPI
 * Plugin URI:        https://github.com/deapi-ai/ai-provider-for-deapi
 * Description:       deAPI image generation and editing, text-to-speech and embedding models for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            deAPI
 * Author URI:        https://deapi.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-deapi
 *
 * @package DeApi\AiProvider
 */

declare(strict_types=1);

namespace DeApi\AiProvider;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

// Preserve the path beneath WP_PLUGIN_DIR when WordPress loads the plugin through a symlink.
if (!defined('AI_PROVIDER_FOR_DEAPI_PLUGIN_DIR')) {
    define(
        'AI_PROVIDER_FOR_DEAPI_PLUGIN_DIR',
        WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__))
    );
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the deAPI provider with the WordPress AI Client.
 *
 * Runs before core passes stored Connector keys to the AI Client (init, priority 20).
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    // The AI Client ships with WordPress 7.0. On older sites there is nothing to register with.
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(DeApiProvider::class)) {
        return;
    }

    $registry->registerProvider(DeApiProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
