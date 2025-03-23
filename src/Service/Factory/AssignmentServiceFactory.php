<?php
namespace Watermarker\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Watermarker\Service\AssignmentService;

class AssignmentServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AssignmentService($services);
    }
}