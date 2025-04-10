<?php
namespace Watermarker\Service\Controller;

use Watermarker\Controller\Admin\WatermarkController;
use Interop\Container\ContainerInterface;

class WatermarkControllerFactory
{
    /**
     * Create the WatermarkController
     *
     * @param ContainerInterface $serviceLocator
     * @return WatermarkController
     */
    public function __invoke(ContainerInterface $serviceLocator)
    {
        $api = $serviceLocator->get('Omeka\ApiManager');
        $entityManager = $serviceLocator->get('Omeka\EntityManager');
        $logger = $serviceLocator->get('Omeka\Logger');
        
        return new WatermarkController($api, $entityManager, $logger);
    }
}