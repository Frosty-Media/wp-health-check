<?php

declare(strict_types=1);

namespace FrostyMedia\WpHealthCheck\RestApi;

use FrostyMedia\WpHealthCheck\HealthCheck\Utility;
use Symfony\Component\HttpFoundation\Request;
use WP_REST_Server;
use function apply_filters;
use function is_user_logged_in;
use function microtime;
use function register_rest_route;
use function time;

/**
 * Class RestApi
 * @package FrostyMedia\WpHealthCheck\HealthCheck
 */
class RestApi
{

    public const string HOOK_NAME_REQUIRE_REST_AUTHENTICATION = Utility::HOOK_PREFIX . 'rest_permission_callback';
    protected const string NAMESPACE = 'health/';
    protected const string ROUTE = 'check';

    /**
     * Initialize our REST route.
     */
    public function initializeRoute(): void
    {
        $utility = new Utility();
        $utility->setRequest(Request::createFromGlobals());
        $utility->setTime(time())->setTimer(microtime(true));
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'callback' => [$utility, 'respond'],
                'methods'  => WP_REST_Server::READABLE,
                'permission_callback' => fn(): bool => $this->permissionCallback(),
            ]
        );
    }

    /**
     * Allow REST access with or without a password.
     * @return bool
     * @uses apply_filters()
     */
    protected function permissionCallback(): bool
    {
        $require_auth = apply_filters(self::HOOK_NAME_REQUIRE_REST_AUTHENTICATION, true);
        return !($require_auth === true) || is_user_logged_in();
    }
}
