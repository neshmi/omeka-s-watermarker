<?php
namespace Watermarker\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Watermarker\Controller\ApiController;

class ApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ApiController();
    }
}