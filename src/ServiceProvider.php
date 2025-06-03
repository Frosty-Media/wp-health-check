<?php

declare(strict_types=1);

namespace FrostyMedia\HealthCheck;

use Pimple\Container as PimpleContainer;
use Pimple\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ServiceProvider
 * @package FrostyMedia\HealthCheck
 */
class ServiceProvider implements ServiceProviderInterface
{

    public const string REQUEST = 'request';

    /**
     * Register services.
     * @param PimpleContainer $pimple Container instance.
     */
    public function register(PimpleContainer $pimple): void
    {
        $pimple[self::REQUEST] = static fn(): Request => Request::createFromGlobals();
    }
}
