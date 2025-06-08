<?php

declare(strict_types=1);

namespace FrostyMedia\WpHealthCheck\HealthCheck;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WP_Error;
use WP_REST_Request;
use function apply_filters;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_pad;
use function class_exists;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function func_get_arg;
use function func_get_args;
use function function_exists;
use function get_num_queries;
use function ini_get;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function ksort;
use function method_exists;
use function microtime;
use function session_write_close;
use function shell_exec;
use function sprintf;
use function substr;
use function wp_cache_get;
use function wp_cache_set;
use const ABSPATH;
use const ARRAY_FILTER_USE_BOTH;
use const FILTER_VALIDATE_BOOLEAN;
use const JSON_ERROR_NONE;
use const PHP_VERSION;

/**
 * Class Utility
 * @package FrostyMedia\WpHealthCheck\HealthCheck
 */
class Utility
{

    public const string HOOK_HEALTH_CHECK_RESPONSE = self::HOOK_PREFIX . 'response';
    public const string HOOK_PREFIX = 'frosty_media/health_check/';
    public const string PARAM_ERRORS = 'errors';
    public const string PARAM_BUILD = 'build';
    public const string PARAM_MYSQL = 'mysql';
    public const string PARAM_OBJECT_CACHE = 'object_cache';
    public const string PARAM_PHP = 'php';
    public const string PARAM_STATUS = 'status';
    public const string PARAM_WP = 'wp';
    public const string STATUS_OK = 'OK';
    public const string STATUS_FAILURE = 'FAILURE';
    public const string STATUS_UNKNOWN = 'UNKNOWN';
    public const string STATUS_WARN = 'WARN';
    private const float MINIMUM_RESPONSE_TIME_WARN = 4.0;
    private const string STATUS_CONNECTED = 'CONNECTED';
    private const string STATUS_NOT_CONNECTED = 'NOT CONNECTED';

    private int $time;
    private float $timer;

    /**
     * Utility constructor.
     * @param Request $request
     */
    public function __construct(private readonly Request $request)
    {
        $this->setTime(time())->setTimer(microtime(true));
    }

    /**
     * Is the current response time too high?
     * @return bool If the timer is greater than the `MIN_RESPONSE_TIME_WARN` return true.
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function isResponseTimeTooHigh(): bool
    {
        return $this->stopTimer() > self::MINIMUM_RESPONSE_TIME_WARN;
    }

    /**
     * Get the time.
     * @return int
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getTime(): int
    {
        return $this->time;
    }

    /**
     * Set the time.
     * @param int $time
     * @return $this
     */
    protected function setTime(int $time): self
    {
        $this->time = $time;
        return $this;
    }

    /**
     * Get the timer.
     * @return float
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getTimer(): float
    {
        return $this->timer;
    }

    /**
     * Set the timer.
     * @param float $time
     * @return $this
     */
    protected function setTimer(float $time): self
    {
        $this->timer = $time;
        return $this;
    }

    /**
     * Stops the timer.
     * @return float Total time spent on the query, in seconds
     */
    public function stopTimer(): float
    {
        $start = $this->getTimer();
        $end = microtime(true);
        $this->timer = $end - $start;
        return $this->timer;
    }

    /**
     * Respond with our JSON data.
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function respond(): never
    {
        // If we are in a WordPress REST request.
        if (func_get_arg(0) instanceof WP_REST_Request) {
            $this->buildJsonResponse(Response::HTTP_OK);
        }

        [$status, $header_status, $message] = array_pad(func_get_args(), 3, null);

        $this->buildJsonResponse($status ?? Response::HTTP_OK, $header_status, $message);
    }

    /**
     * Prepare the incoming array and `json_encode` it to the screen.
     * @param int $status Response status to print to the screen.
     * @param int|null $header_status Response status to return in the header
     * @param string|null $message Optional response message
     * @return never
     */
    protected function buildJsonResponse(
        int $status,
        ?int $header_status = null,
        ?string $message = null
    ): never {
        $json = new JsonResponse($this->buildResponseArray($status, $message), $header_status ?? Response::HTTP_OK);
        $json->prepare($this->request)->send();
        session_write_close();
        exit;
    }

    /**
     * Build an Array for the health check response.
     * @param int $status Required response HTTP status code.
     * @param string|null $message Optional message.
     * @return array[]|string[]
     */
    protected function buildResponseArray(int $status = Response::HTTP_BAD_REQUEST, ?string $message = null): array
    {
        $response = [
            self::PARAM_ERRORS => $message,
            self::PARAM_BUILD => null,
            self::PARAM_MYSQL => $this->getMysqlStatus(),
            self::PARAM_PHP => $this->getPhpStatus(),
            self::PARAM_OBJECT_CACHE => $this->getObjectCacheStatus(),
            self::PARAM_STATUS => $this->getSummaryStatus($status),
            self::PARAM_WP => $this->getWpStatus(),
        ];

        $commit = $this->getBuildInfo('commit');
        if ($commit !== null) {
            $response[self::PARAM_BUILD] = [
                'commit' => str_contains($commit, 'Error:') ? $commit : substr($commit, 0, 7),
                'version' => $this->getBuildInfo('version'),
            ];
        }

        /**
         * Fires once the complete response array has been created.
         * Useful to add data to the response, like if ($this->request->query->has('rest')) {}
         * @param string[] $response The response array.
         * @param Utility $this The Utility instance.
         */
        $response = apply_filters(self::HOOK_HEALTH_CHECK_RESPONSE, $response, $this);
        ksort($response);

        return array_filter(
            $response,
            static fn($value, $key) => !empty($value) || $key === self::PARAM_ERRORS,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Get the build info.
     * @param string $key
     * @return string|null
     */
    protected function getBuildInfo(string $key): ?string
    {
        if ($this->hasParam(self::PARAM_BUILD)) {
            return null;
        }
        if (file_exists(ABSPATH . '.info')) {
            $file = file_get_contents(ABSPATH . '.info');
            if (is_string($file)) {
                $data = json_decode($file, false, 512, JSON_THROW_ON_ERROR);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return !empty($data->$key) ? $data->$key : '(empty)';
                }
            }

            return sprintf('Error: can\'t parse `%s.info`; %s', ABSPATH, json_last_error_msg());
        }

        return sprintf('Error: `%s.info` doesn\'t exist', ABSPATH);
    }

    /**
     * Get the status (healthcheck) of mysql.
     * @return array|null
     */
    protected function getMysqlStatus(): ?array
    {
        if ($this->hasParam(self::PARAM_MYSQL)) {
            return null;
        }
        global $wpdb;

        if (!empty($wpdb->last_error)) {
            $this->getWpError('mysql')->add('mysql-has-error', $wpdb->last_error);
        }

        $process_list = $wpdb->get_results('show full processlist');
        if (!$process_list) {
            $this->getWpError('mysql')->add(
                'mysql-processlist-failed',
                'Unable to get process list. ' . $wpdb->last_error
            );
        }

        $status = [
            'errors' => null,
            'extension' => is_object($wpdb->dbh) ? $wpdb->dbh::class : self::STATUS_UNKNOWN,
            'instance' => $wpdb::class,
            'num_queries' => get_num_queries(),
            'status' => self::STATUS_OK,
        ];
        if ($this->getWpError('mysql')->has_errors()) {
            $status = array_merge($status, ['errors' => $this->getWpError('mysql')->errors]);
        }
        ksort($status);

        return $status;
    }

    /**
     * Get the status (stats) of PHP.
     * @return array|null
     */
    protected function getPhpStatus(): ?array
    {
        if ($this->hasParam(self::PARAM_PHP)) {
            return null;
        }
        $status = [
            'memory_limit' => ini_get('memory_limit'),
            'version' => PHP_VERSION,
        ];
        ksort($status);

        return $status;
    }

    /**
     * Get the status (healthcheck) of the object cache.
     * @return array|null
     */
    protected function getObjectCacheStatus(): ?array
    {
        if ($this->hasParam(self::PARAM_OBJECT_CACHE)) {
            return null;
        }
        global $wp_object_cache, $wpdb;

        $set = wp_cache_set('test', 1);
        if (!$set) {
            $this->getWpError('cache')->add('object-cache-unable-to-set', 'Unable to set object cache value.');
        }

        $value = wp_cache_get('test');
        if ($value !== 1) {
            $this->getWpError('cache')->add('object-cache-unable-to-get', 'Unable to get object cache value.');
        }

        // Check alloptions are not out of sync.
        $alloptions = [];
        $results = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE autoload = 'yes'");
        foreach ($results as $option) {
            $alloptions[$option->option_name] = $option->option_value;
        }

        $alloptions_cache = wp_cache_get('alloptions', 'options');
        if (!is_array($alloptions_cache)) {
            $this->getWpError('cache')->add(
                'object-cache-alloptions',
                '`wp_cache_get(alloptions) returned false.'
            );
        } else {
            foreach ($alloptions as $option => $value) {
                if (!array_key_exists($option, $alloptions_cache)) {
                    $this->getWpError('cache')->add(
                        "object-cache-alloptions-option-$option",
                        sprintf('%s option not found in cache', $option)
                    );
                    continue;
                }
                /**
                 * Values that are stored in the cache can be any scalar type, but scalar values retrieved from the
                 * database will always be string. When a cache value is populated via update / add option, it will
                 * be stored in the cache as a scalar type, but then a string in the database. We convert all
                 * non-string scalars to strings to be able to do the appropriate comparison.
                 */
                $cache_value = $alloptions_cache[$option];
                if (is_scalar($cache_value) && !is_string($cache_value)) {
                    $cache_value = (string)$cache_value;
                }
                if ($cache_value !== $value) {
                    $this->getWpError('cache')->add(
                        "object-cache-alloptions-cache_value-$cache_value",
                        sprintf('%s value not the same in cache and DB.', $option)
                    );
                }
            }
        }

        /**
         * @psalm-suppress UndefinedConstant
         */
        $status = [
            'cache' => null,
            'client' => $wp_object_cache->redis_client ?? null, // Removed WP_REDIS_CLIENT (not in use, but defined).
            'flush' => null,
            'errors' => null,
            'hits' => $wp_object_cache->cache_hits,
            'misses' => $wp_object_cache->cache_misses,
            'status' => null,
        ];
        // Redis Cache (plugin) 1 & 2.
        if (method_exists($wp_object_cache, 'redis_status')) {
            $status['status'] = $wp_object_cache->redis_status() ? self::STATUS_CONNECTED : self::STATUS_NOT_CONNECTED;
            // phpcs:ignore.
        } elseif (!empty($GLOBALS['RedisCachePro']) && class_exists('\RedisCachePro\Diagnostics\Diagnostics')) {
            /**
             * @psalm-suppress UndefinedClass
             */
            $diagnostics = (new \RedisCachePro\Diagnostics\Diagnostics($wp_object_cache))->toArray();
            $status['connector'] = $diagnostics['config']['connector']->text ?? self::STATUS_UNKNOWN;
            $status['cache'] = $diagnostics['config']['cache']->text ?? self::STATUS_UNKNOWN;
            $status['status'] = $diagnostics['general']['status']->text ?? self::STATUS_UNKNOWN;
            $status['compressions'] = $diagnostics['general']['compressions']->text ?? self::STATUS_UNKNOWN;
            $status['phpredis'] = $diagnostics['versions']['phpredis']->text ?? self::STATUS_UNKNOWN;
            $status['igbinary'] = $diagnostics['versions']['igbinary']->text ?? self::STATUS_UNKNOWN;
        }
        if ($this->getWpError('cache')->has_errors()) {
            $status = array_merge($status, ['errors' => $this->getWpError('cache')->errors]);
        }
        ksort($status);

        return $status;
    }

    /**
     * Get a summary status text for HTTP Response codes.
     * @param int $status_code
     * @return string
     */
    private function getSummaryStatus(int $status_code): string
    {
        if ($this->getWpError('mysql')->has_errors() && $this->getWpError('cache')->has_errors()) {
            return self::STATUS_WARN;
        }

        if ($status_code >= Response::HTTP_OK && $status_code < Response::HTTP_MULTIPLE_CHOICES) {
            return self::STATUS_OK;
        }

        if ($status_code >= Response::HTTP_BAD_REQUEST && $status_code < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return self::STATUS_WARN;
        }

        if ($status_code >= Response::HTTP_INTERNAL_SERVER_ERROR && $status_code < 600) {
            return self::STATUS_FAILURE;
        }

        return self::STATUS_UNKNOWN;
    }

    /**
     * Get the status of WordPress.
     * @return array|null
     */
    protected function getWpStatus(): ?array
    {
        if ($this->hasParam(self::PARAM_WP)) {
            return null;
        }
        global $wp_db_version, $wp_version, $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'db_version')
        );
        /**
         * Allow the use of `shell_exec`.
         * @psalm-suppress ForbiddenCode
         */
        $status = [
            'wp_db_version' => $wp_db_version ?? self::STATUS_UNKNOWN,
            'db_version' => is_object($row) && is_numeric($row->option_value) ?
                (int)$row->option_value : self::STATUS_UNKNOWN,
            'version' => $wp_version ?? self::STATUS_UNKNOWN,
            'cli' => function_exists('shell_exec') ? shell_exec('wp cli version') : self::STATUS_UNKNOWN,
        ];
        ksort($status);

        return $status;
    }

    /**
     * Get a new WP_Error instance.
     * @param string $key
     * @return WP_Error
     */
    private function getWpError(string $key): WP_Error
    {
        static $wp_error;

        if (!isset($wp_error[$key])) {
            $wp_error[$key] = new WP_Error();
        }

        return $wp_error[$key];
    }

    /**
     * Validate a passed parameter.
     * @param string $param
     * @return bool
     */
    private function hasParam(string $param): bool
    {
        return $this->request->query->has($param) &&
            filter_var($this->request->query->get($param), FILTER_VALIDATE_BOOLEAN) === true;
    }
}
