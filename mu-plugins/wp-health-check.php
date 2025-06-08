<?php

declare(strict_types=1);

/**
 * Plugin Name: WP Health Check
 * Plugin URI: https://github.com/Frosty-Media/wp-health-check
 * Description: A simple WordPress health check endpoint.
 * Version: 1.0.1
 * Author: Austin Passy
 * Author URI: https://austin.passy.co
 */

namespace FrostyMedia\WpHealthCheck;

use FrostyMedia\WpHealthCheck\RestApi\RestApi;
use function defined;

defined('ABSPATH') || exit;

add_action('init', static function (): void {
    add_action('rest_api_init', [new RestApi(), 'initializeRoute']);
}, 10, 0);
