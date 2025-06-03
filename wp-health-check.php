<?php

/**
 * Plugin Name: WP Health Check
 * Plugin URI: https://github.com/Frosty-Media/wp-health-check
 * Description: A simple WordPress health check endpoint.
 * Version: 1.0.0
 * Author: Austin Passy
 * Author URI: https://austin.passy.co
 * License: MIT
 * Requires at least: 6.8
 * Tested up to: 6.8.1
 * Requires PHP: 8.3
 * Plugin URI: https://github.com/Frosty-Media/wp-health-check
 * GitHub Plugin URI: https://github.com/Frosty-Media/wp-health-check
 * Primary Branch: main
 * Release Asset: true
 */

namespace FrostyMedia\WpHealthCheck;

defined('ABSPATH') || exit;

use FrostyMedia\WpHealthCheck\HealthCheck\Utility;
use ReflectionMethod;
use TheFrosty\WpUtilities\Plugin\PluginFactory;
use TheFrosty\WpUtilities\WpAdmin\DisablePluginUpdateCheck;
use function defined;
use function flush_rewrite_rules;

$plugin = PluginFactory::create('wp-health-check');
$container = $plugin->getContainer();
$container->register(new ServiceProvider());

$plugin
    ->add(new DisablePluginUpdateCheck())
    ->add(new Utility($container))
    ->initialize();

register_activation_hook(__FILE__, static function () use ($container): void {
    $addRewriteRule = new ReflectionMethod(Utility::class, 'addRewriteRule');
    $addRewriteRule->invoke(new Utility($container));
    flush_rewrite_rules();
});
