<?php

declare(strict_types=1);

namespace FrostyMedia\WpHealthCheck\RestApi;

use FrostyMedia\WpHealthCheck\HealthCheck\Utility;
use Symfony\Component\HttpFoundation\Request;
use WP_REST_Server;
use function apply_filters;
use function is_user_logged_in;
use function register_rest_route;

/**
 * Class RestApi
 * @package FrostyMedia\WpHealthCheck\HealthCheck
 */
class RestApi
{

    public const string HOOK_NAME_REQUIRE_REST_AUTHENTICATION = Utility::HOOK_PREFIX . 'rest_permission_callback';
    protected const string NAMESPACE = 'health';
    protected const string ROUTE = 'check';

    /**
     * Initialize our REST route.
     */
    public function initializeRoute(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [new Utility(Request::createFromGlobals()), 'respond'],
                'permission_callback' => fn(): bool => $this->permissionCallback(),
                'schema' => $this->getParameterArgs(),
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

    /**
     * @return array[]
     */
    private function getParameterArgs(): array
    {
        return [
            Utility::PARAM_ERRORS => [
                'type' => ['string', 'null'],
                'description' => 'Health check error message(s).',
            ],
            Utility::PARAM_BUILD => [
                'type' => ['array', 'null'],
                'description' => 'Build details found in an .info file.',
                'items' => [
                    'enum' => ['commit', 'version'],
                    'type' => 'string',
                ],
            ],
            Utility::PARAM_MYSQL => [
                'type' => ['array', 'null'],
                'description' => 'MySQL information.',
                'items' => [
                    'enum' => ['errors', 'extension', 'instance', 'num_queries', 'status'],
                    'type' => ['string', 'null'],
                ],
            ],
            Utility::PARAM_OBJECT_CACHE => [
                'type' => ['array', 'null'],
                'description' => 'Object cache information.',
                'items' => [
                    'enum' => ['cache', 'client', 'flush', 'errors', 'hits', 'misses', 'status'],
                    'type' => ['string', 'null'],
                ],
            ],
            Utility::PARAM_PHP => [
                'type' => ['array', 'null'],
                'description' => 'PHP information.',
                'items' => [
                    'enum' => ['memory_limit', 'version'],
                    'type' => 'string',
                ],
            ],
            Utility::PARAM_STATUS => [
                'type' => ['string'],
                'enum' => [Utility::STATUS_OK, Utility::STATUS_FAILURE, Utility::STATUS_UNKNOWN, Utility::STATUS_WARN],
                'description' => 'The WordPress application status.',
            ],
            Utility::PARAM_WP => [
                'type' => ['array', 'null'],
                'description' => 'WordPress information.',
                'items' => [
                    'type' => ['integer', 'string', 'null'],
                    'enum' => ['wp_db_version', 'db_version', 'version', 'cli'],
                ],
            ],
        ];
    }
}
