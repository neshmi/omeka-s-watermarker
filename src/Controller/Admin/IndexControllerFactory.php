<?php
/**
 * Factory for the IndexController
 */

namespace Watermarker\Controller\Admin;

use Interop\Container\ContainerInterface;

class IndexControllerFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new IndexController(
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\ApiManager')
        );
    }
}