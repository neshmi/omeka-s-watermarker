<?php
namespace Watermarker;

return [
    'controllers' => [
        'factories' => [
            Controller\AdminController::class => Service\Controller\AdminControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin/watermarker' => [
                'type' => 'Literal',
                'options' => [
                    'route'    => '/admin/watermarker',
                    'defaults' => [
                        '__NAMESPACE__' => 'Watermarker\Controller',
                        'controller'    => Controller\AdminController::class,
                        'action'        => 'index',
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/Watermarker/view',
        ],
    ],
];
