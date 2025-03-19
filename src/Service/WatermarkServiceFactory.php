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
        // Verify all required services are available
        $services = [
            'Omeka\Connection',
            'Omeka\Logger',
            'Omeka\EntityManager',
            'Omeka\ApiManager',
            'Omeka\Settings',
            'Omeka\File\Store',
            'Omeka\File\TempFileFactory',
            'Omeka\File\Uploader',
            'Omeka\ApiAdapterManager',
        ];

        // Log service availability for debugging
        $logger = $container->get('Omeka\Logger');
        $logger->info('WatermarkerFactory: Initializing watermark service');

        foreach ($services as $service) {
            if (!$container->has($service)) {
                $logger->err(sprintf('Required service "%s" not found in container', $service));
            } else {
                $logger->info(sprintf('Required service "%s" is available', $service));
            }
        }

        // Create and return the service
        try {
            return new WatermarkService($container);
        } catch (\Exception $e) {
            $logger->err(sprintf('Error creating WatermarkService: %s', $e->getMessage()));
            throw $e;
        }
    }
}