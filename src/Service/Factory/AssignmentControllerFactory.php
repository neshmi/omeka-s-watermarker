<?php
namespace Watermarker\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Watermarker\Controller\Admin\Assignment;

class AssignmentControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Assignment(
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\ApiManager')
        );
    }
}