<?php
/**
 * Watermark service factory
 */

namespace Watermarker\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class WatermarkServiceFactory implements FactoryInterface
{
    /**
     * Create the watermark service
     *
     * @param ContainerInterface $services
     * @param string $requestedName
     * @param array|null $options
     * @return WatermarkService
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new WatermarkService($services);
    }
}