<?php

declare(strict_types=1);

namespace FrostyMedia\WpHealthCheck\HealthCheck;

use FrostyMedia\WpHealthCheck\ServiceProvider;
use Psr\Container\ContainerInterface;
use TheFrosty\WpUtilities\Plugin\ContainerAwareTrait;
use TheFrosty\WpUtilities\RestApi\Http\RegisterGetRoute;
use WP_REST_Server;
use function apply_filters;
use function is_user_logged_in;

/**
 * Class RestApi
 * @package FrostyMedia\WpHealthCheck\HealthCheck
 */
class RestApi extends RegisterGetRoute
{

    use ContainerAwareTrait;

    public const string HOOK_NAME_REQUIRE_REST_AUTHENTICATION = Utility::HOOK_PREFIX . 'rest_permission_callback';
    protected const string NAMESPACE = 'health/';
    protected const string ROUTE = 'check';

    /**
     * RestApi constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->setContainer($container);
    }

    /**
     * Initialize our REST route.
     * @param WP_REST_Server $server
     */
    public function initializeRoute(WP_REST_Server $server): void
    {
        /** @var Utility $utility */
        $utility = $this->getContainer()->get(ServiceProvider::UTILITY);
        $this->registerRoute(
            self::NAMESPACE,
            self::ROUTE,
            [$utility, 'respond'],
            [
                self::ARG_PERMISSION_CALLBACK => fn(): bool => $this->permissionCallback(),
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
