<?php

declare(strict_types=1);

namespace FrostyMedia\HealthCheck;

use Psr\Container\ContainerInterface;
use RedisCachePro\Diagnostics\Diagnostics;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use TheFrosty\WpUtilities\Plugin\AbstractContainerProvider;
use TheFrosty\WpUtilities\Plugin\HttpFoundationRequestInterface;
use TheFrosty\WpUtilities\Plugin\HttpFoundationRequestTrait;
use Throwable;
use WP;
use WP_Error;
use function add_rewrite_rule;
use function array_key_exists;
use function array_merge;
use function array_shift;
use function class_exists;
use function defined;
use function do_action_ref_array;
use function esc_html;
use function fastcgi_finish_request;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function function_exists;
use function get_class;
use function get_num_queries;
use function http_response_code;
use function in_array;
use function ini_get;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function is_super_admin;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function ksort;
use function method_exists;
use function microtime;
use function nocache_headers;
use function session_write_close;
use function shell_exec;
use function sprintf;
use function substr;
use function wp_cache_get;
use function wp_cache_set;
use const ABSPATH;
use const FILTER_VALIDATE_BOOLEAN;
use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const PHP_VERSION;
use const WPMU_PLUGIN_DIR;

/**
 * Class Utility
 * @package Meta\HealthCheck
 */
class Utility extends AbstractContainerProvider implements HttpFoundationRequestInterface
{

    use HttpFoundationRequestTrait;

    public const string TAG_HEALTH_CHECK_RESPONSE = self::HOOK_PREFIX . 'response';
    public const string HOOK_NAME_QUERY_VAR = self::HOOK_PREFIX . 'query_var';
    private const string HOOK_PREFIX = 'frosty_media/health_check/';
    private const int MINIMUM_RESPONSE_TIME_WARN = 4;
    private const string STATUS_CONNECTED = 'CONNECTED';
    private const string STATUS_FAILURE = 'FAILURE';
    private const string STATUS_NOT_CONNECTED = 'NOT CONNECTED';
    private const string STATUS_OK = 'OK';
    private const string STATUS_UNKNOWN = 'UNKNOWN';
    private const string STATUS_WARN = 'WARN';

    /**
     * Timer.
     * @var float $timer
     */
    private float $timer;

    /**
     * Utility constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->timer = microtime(true);
        parent::__construct($container);
    }

    /**
     * Get the registered "query_var" key.
     * @return string
     * @uses apply_filters()
     */
    public static function getQueryVar(): string
    {
        return apply_filters(self::HOOK_NAME_QUERY_VAR, 'health_check');
    }

    /**
     * Add class hooks.
     */
    public function addHooks(): void
    {
        $this->addAction('init', [$this, 'addRewriteRule']);
        $this->addFilter('query_vars', [$this, 'queryVars']);
        $this->addAction('parse_request', [$this, 'parseRequest'], -1);
    }


    /**
     * Register our rewrite rule.
     */
    protected function addRewriteRule(): void
    {
        add_rewrite_rule('^health$', sprintf('index.php?%s=true', self::getQueryVar()), 'top');
    }

    /**
     * Register new query vars.
     * @param array $vars
     * @return array
     */
    protected function queryVars(array $vars): array
    {
        $vars[] = self::getQueryVar();
        return $vars;
    }

    /**
     * Listen for the health request and process response.
     */
    protected function parseRequest(WP $wp): void
    {
        if (
            !isset($wp->query_vars[self::getQueryVar()]) ||
            !filter_var($wp->query_vars[self::getQueryVar()], FILTER_VALIDATE_BOOLEAN)
        ) {
            return;
        }

        nocache_headers();

        try {
            global $wpdb;

            if (empty($wpdb)) {
                throw new RuntimeException('WordPress couldn\'t load `$wpdb`.', Response::HTTP_SERVICE_UNAVAILABLE);
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
                 * @psalm-suppress UndefinedClass
                 * @psalm-suppress UndefinedConstant
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

            if ($this->isResponseTimeTooHigh()) {
                $status = Response::HTTP_OK; // A slow response should still generate an 'HTTP_OK' response.
                throw new RuntimeException(
                    sprintf(
                        'WordPress loaded, but the response time is slow. Current response is %s.',
                        $this->timerStop()
                    ),
                    Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
                );
            }
        } catch (Throwable $exception) {
            $this->respond($exception->getCode(), $status ?? $exception->getCode(), $exception->getMessage());
        }

        // All good
        $this->respond(Response::HTTP_OK);
    }

    /**
     * Is the current response time too high?
     * @return bool If the timer is greater than the `MIN_RESPONSE_TIME_WARN` return true.
     */
    public function isResponseTimeTooHigh(): bool
    {
        return $this->timerStop() > (float)self::MINIMUM_RESPONSE_TIME_WARN;
    }

    /**
     * Stops the debugging timer.
     * @return float Total time spent on the query, in seconds
     */
    public function timerStop(): float
    {
        return microtime(true) - $this->timer;
    }

    /**
     * Prepare the incoming array and `json_encode` it to the screen.
     * @param int $status Response status to print to the screen.
     * @param int $header_status Response status to return in the header
     * @param string|null $message Optional response message
     */
    public function respond(int $status, int $header_status = Response::HTTP_OK, ?string $message = null): void
    {
        if ($this->isApplicationJson()) {
            $this->buildJsonResponse($status, $header_status, $message);
        }
        http_response_code($header_status);
        $checks = $this->buildResponseArray($status, $message);
        $list = static function (array $checks): string {
            $html = '<ul>';
            foreach ($checks as $check => $status) {
                if (is_array($status)) {
                    $html .= sprintf('<li><strong>%s</strong>:<ul>', esc_html($check));
                    foreach ($status as $code => $error) {
                        $html .= sprintf(
                            '<li><strong>%s</strong>: %s</li>',
                            esc_html($code),
                            esc_html(array_shift($error))
                        );
                    }
                    $html .= '</ul></li>';
                    continue;
                }
                $html .= sprintf('<li><strong>%s</strong>: %s</li>', esc_html($check), $status);
            }
            $html .= '</ul>';

            return $html;
        }
        ?>
        <html lang="en_US">
        <head>
            <title>Status: <?php
                echo esc_html($this->getSummaryStatus($status)); ?></title>
        </head>
        <ul>
            <?php
            foreach ($checks as $check => $_status) : ?>
                <li>
                    <strong><?php
                        echo esc_html($check); ?></strong>:<?php
                    echo is_array($_status) ? $list($_status) : esc_html($_status); ?>
                </li>
            <?php
            endforeach ?>
        </ul>
        </html>
        <?php
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Prepare the incoming array and `json_encode` it to the screen.
     * @param int $status Response status to print to the screen.
     * @param int $header_status Response status to return in the header
     * @param string|null $message Optional response message
     */
    protected function buildJsonResponse(
        int $status,
        int $header_status = Response::HTTP_OK,
        ?string $message = null
    ): never {
        $json = new JsonResponse($this->buildResponseArray($status, $message), $header_status);
        $json->prepare($this->getRequest());
        $json->setEncodingOptions(JSON_PRETTY_PRINT);
        $json->send();
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
        $commit = $this->getBuildInfo('commit');
        $response = [
            'buildInfo' => [
                'commit' => str_contains($commit, 'Error:') ? $commit : substr($commit, 0, 7),
                'version' => $this->getBuildInfo('version'),
            ],
            'mysql' => $this->getMysqlStatus($message),
            'php' => $this->getPhpStatus(),
            'redis' => $this->getObjectCacheStatus(),
            'status' => $this->getSummaryStatus($status),
            'wp' => $this->getWpStatus(),
        ];

        /**
         * Fires once the complete response array has been created.
         * Useful to add data to the response, like if ($this->getRequest()->query->has('rest')) {}
         * @param Utility $this The Utility instance.
         * @param string[] $response The response array (passed by reference).
         */
        do_action_ref_array(self::TAG_HEALTH_CHECK_RESPONSE, [$this, &$response]);

        return $response;
    }

    /**
     * Get the build info.
     * @param string $key
     * @return string
     */
    protected function getBuildInfo(string $key): string
    {
        if (!defined('ABSPATH')) {
            return '';
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
     * @param string|null $message
     * @return array
     */
    protected function getMysqlStatus(?string $message): array
    {
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

        if ($message) {
            $this->getWpError('mysql')->add('mysql-has-message', $message);
        }

        $status = [
            'errors' => null,
            'extension' => is_object($wpdb->dbh) ? get_class($wpdb->dbh) : self::STATUS_UNKNOWN,
            'instance' => get_class($wpdb),
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
     * @return array
     */
    protected function getPhpStatus(): array
    {
        $status = [
            'memory_limit' => ini_get('memory_limit'),
            'version' => PHP_VERSION,
        ];
        ksort($status);

        return $status;
    }

    /**
     * Get the status (healthcheck) of the object cache (redis).
     * @return array
     */
    protected function getObjectCacheStatus(): array
    {
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
            'client' => $wp_object_cache->redis_client ?? null, // Removed WP_REDIS_CLIENT (not in use, but defined)
            'flush' => null,
            'errors' => null,
            'hits' => $wp_object_cache->cache_hits,
            'misses' => $wp_object_cache->cache_misses,
            'status' => null,
        ];
        // Redis Cache (plugin) 1 & 2
        if (method_exists($wp_object_cache, 'redis_status')) {
            $status['status'] = $wp_object_cache->redis_status() ? self::STATUS_CONNECTED : self::STATUS_NOT_CONNECTED;
        } elseif (!empty($GLOBALS['RedisCachePro']) && class_exists('\RedisCachePro\Diagnostics\Diagnostics')) {
            /**
             * @psalm-suppress UndefinedClass
             */
            $diagnostics = (new Diagnostics($wp_object_cache))->toArray();
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
        if (
            $this->getRequest()->query->has('cli') &&
            in_array($this->getRequest()->query->get('cli'), ['flush', 'flushdb'], true) &&
            is_super_admin()
        ) {
            try {
                $ObjectCachePro = $GLOBALS['ObjectCachePro'];
                if (method_exists($ObjectCachePro, 'logFlush')) {
                    $ObjectCachePro->logFlush();
                }

                $result = $wp_object_cache->connection()->flushdb();
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
                $result = false;
            }

            $status['flush'] = !$result ?
                sprintf('Object cache could not be flushed. %s', $message ?? '') :
                'Object cache flushed.';
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
        } elseif ($status_code >= Response::HTTP_OK && $status_code < Response::HTTP_MULTIPLE_CHOICES) {
            return self::STATUS_OK;
        } elseif ($status_code >= Response::HTTP_BAD_REQUEST && $status_code < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return self::STATUS_WARN;
        } elseif ($status_code >= Response::HTTP_INTERNAL_SERVER_ERROR && $status_code < 600) {
            return self::STATUS_FAILURE;
        }

        return self::STATUS_UNKNOWN;
    }

    /**
     * Get the status of WordPress.
     * @return array
     */
    protected function getWpStatus(): array
    {
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
            'cli' => shell_exec('wp cli version'),
            'super_admin' => is_super_admin(),
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
     * Is the content type JSON or does the query contain JSON?
     * @return bool
     */
    private function isApplicationJson(): bool
    {
        return $this->getRequest()->query->has('json') ||
            ($this->getRequest()->headers->has('Content-Type') &&
                $this->getRequest()->headers->get('Content-Type') === 'application/json');
    }
}
