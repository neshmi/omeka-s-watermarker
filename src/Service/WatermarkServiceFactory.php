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
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return WatermarkService
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // Make sure we have all required services
        $serviceLocator = $container;
        $connection = $serviceLocator->get('Omeka\Connection');
        $logger = $serviceLocator->get('Omeka\Logger');
        $entityManager = $serviceLocator->get('Omeka\EntityManager');
        $apiManager = $serviceLocator->get('Omeka\ApiManager');
        $settings = $serviceLocator->get('Omeka\Settings');

        return new WatermarkService($serviceLocator);
    }
}<?php
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
     * @param ContainerInterface $container
     * @param string $requestedName
     * @param array|null $options
     * @return WatermarkService
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new WatermarkService($container);
    }
}