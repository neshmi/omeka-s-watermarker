<?php
/**
 * Watermarker module configuration
 */

namespace Watermarker;

return [
    'controllers' => [
        'invokables' => [
            'Watermarker\Controller\Admin\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Watermarks',
                'route' => 'admin/watermarker',
                'resource' => 'Watermarker\Controller\Admin\Index',
                'privilege' => 'index',
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'watermarker' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/watermarker[/:action[/:id]]',
                            'defaults' => [
                                '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\WatermarkForm::class => Form\WatermarkForm::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Watermarker\WatermarkService' => Service\WatermarkServiceFactory::class,
        ],
        'aliases' => [
            'Watermarker\TempFileFactory' => 'Omeka\File\TempFileFactory',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'watermarker' => [
        'config' => [
            'watermark_enabled' => true,
            'apply_on_upload' => true,
            'apply_on_import' => true,
        ],
    ],
    // No need to register jobs anymore since we're not using the job system
];