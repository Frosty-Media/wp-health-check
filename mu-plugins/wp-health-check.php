<?php

declare(strict_types=1);

/**
 * Plugin Name: WP Health Check (MU)
 * Plugin URI: https://github.com/Frosty-Media/wp-health-check
 * Description: A simple WordPress health check endpoint.
 * Version: 1.0.0
 * Author: Austin Passy
 * Author URI: https://austin.passy.co
 */

namespace FrostyMedia\WpHealthCheck;

use TheFrosty\WpUtilities\Plugin\PluginFactory;
use TheFrosty\WpUtilities\WpAdmin\DisablePluginUpdateCheck;
use function define;
use function defined;
use function file_exists;
use function is_plugin_active;
use const WP_PLUGIN_DIR;
use const WPMU_PLUGIN_DIR;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (is_plugin_active('wp-health-check/wp-health-check.php')) {
    include_once WP_PLUGIN_DIR . '/wp-health-check/wp-health-check.php';
} elseif (file_exists(WPMU_PLUGIN_DIR . '/wp-health-check/wp-health-check.php')) {
    include_once WPMU_PLUGIN_DIR . '/wp-health-check/wp-health-check.php';
} else {
    return;
}

$plugin = PluginFactory::create('wp-health-check');
$container = $plugin->getContainer();
$container->register(new ServiceProvider());
$plugin->add(new DisablePluginUpdateCheck())->initialize();

/** @var \Symfony\Component\HttpFoundation\Request $request */
$request = $container->get(ServiceProvider::REQUEST);
/** @var HealthCheck\Utility $utility */
$utility = $container->get(ServiceProvider::UTILITY);

if (
    $request->server->has('REQUEST_URI') &&
    stripos($request->server->get('REQUEST_URI'), '/health-check') !== false
) {
    define('DISABLE_WP_CRON', true);
    $utility->respond();
}
