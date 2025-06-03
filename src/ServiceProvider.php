<?php

declare(strict_types=1);

namespace FrostyMedia\WpHealthCheck;

use FrostyMedia\WpHealthCheck\HealthCheck\Utility;
use Pimple\Container as PimpleContainer;
use Pimple\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ServiceProvider
 * @package FrostyMedia\WpHealthCheck
 */
class ServiceProvider implements ServiceProviderInterface
{

    public const string REQUEST = 'request';
    public const string UTILITY = 'utility';

    /**
     * Register services.
     * @param PimpleContainer $pimple Container instance.
     */
    public function register(PimpleContainer $pimple): void
    {
        $pimple[self::REQUEST] = static fn(): Request => Request::createFromGlobals();
        $pimple[self::UTILITY] = static function () use ($pimple): Utility {
            $utility = new Utility();
            $utility->setRequest($pimple[self::REQUEST]);
            $utility->timerStart();
            return $utility;
        };
    }
}
