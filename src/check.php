<?php

declare(strict_types=1);

use FrostyMedia\WpHealthCheck\HealthCheck\Utility;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

putenv('WORDPRESS_RUN_TYPE=health-check'); // phpcs:ignore

// Bootstrap WordPress.
$wpConfig = null;
foreach (
    [
        dirname(__DIR__, 5),
        dirname(__DIR__, 4),
        dirname(__DIR__, 3),
    ] as $path
) {
    if (!is_readable("$path/wp-config.php")) {
        continue;
    }
    /**
     * @psalm-suppress UnresolvableInclude
     */
    $wpConfig = "$path/wp-config.php";
    require $wpConfig;
}

if (!$wpConfig) {
    echo json_encode([
        'errors' => 'Couldn\'t locate wp-config.php',
        'status' => 'FAILURE',
    ]);
    exit;
}

$utility = new Utility(Request::createFromGlobals());

nocache_headers();

try {
    /**
     * @psalm-suppress InvalidGlobal
     */
    global $wpdb;

    if (empty($wpdb)) {
        throw new RuntimeException(
            sprintf('WordPress couldn\'t load `$wpdb` from `%s`.', $wpConfig),
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    $wpdb->suppress_errors();
    $db_connect = $wpdb->db_connect(false);

    if (!empty($wpdb->last_error)) {
        throw new RuntimeException(
            sprintf('MySQL has an error "%s".', $wpdb->last_error),
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    // If we didn't connect, we need to double-check `LudicrousDB` and manually bootstrap it.
    if (!$db_connect) {
        /**
         * @psalm-suppress MissingFile
         * @psalm-suppress UndefinedClass
         */
        if (!$wpdb instanceof LudicrousDB) {
            if (file_exists(WPMU_PLUGIN_DIR . '/ludicrousdb/ludicrousdb.php')) {
                unset($wpdb);
                require_once WPMU_PLUGIN_DIR . '/ludicrousdb/ludicrousdb.php';
                /**
                 * @psalm-suppress InvalidGlobal
                 */
                global $wpdb;

                if (empty($wpdb)) {
                    throw new RuntimeException(
                        'WordPress couldn\'t load LudicrousDB `$wpdb`.',
                        Response::HTTP_SERVICE_UNAVAILABLE
                    );
                }

                $wpdb->suppress_errors();
                $ludicrous_db_connect = $wpdb->db_connect(false);
            }
            if (!isset($ludicrous_db_connect) || !$ludicrous_db_connect) {
                throw new RuntimeException(
                    'WordPress loaded, but could not connect to the db.',
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
        }
    }

    if ($utility->isResponseTimeTooHigh()) {
        $status = Response::HTTP_OK; // A slow response should still generate a 'HTTP_OK' response.
        throw new RuntimeException(
            sprintf(
                'WordPress loaded, but the response time is slow. Current response is %s.',
                human_time_diff($utility->getTime())
            ),
            Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
        );
    }
} catch (Throwable $exception) {
    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    $utility->respond($exception->getCode(), $status ?? $exception->getCode(), $exception->getMessage());
}

// All good.
$utility->respond(Response::HTTP_OK);
