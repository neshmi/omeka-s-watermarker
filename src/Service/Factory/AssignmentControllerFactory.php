<?php
namespace Watermarker\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Watermarker\Controller\Admin\AssignmentController;

class AssignmentControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AssignmentController(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiManager'),
            $services->get('Watermarker\AssignmentService')
        );
    }
}