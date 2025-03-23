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
                        'may_terminate' => true,
                        'child_routes' => [
                            'set' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/set/:action[/:id]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                    ],
                                    'constraints' => [
                                        'action' => '(index|add|edit|delete)',
                                        'id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                            'set-add' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/set-add',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'addSet',
                                    ],
                                ],
                            ],
                            'watermark' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/watermark/:action[/:id]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'index',
                                    ],
                                    'constraints' => [
                                        'action' => '(index|edit|delete)',
                                        'id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                            'watermark-add' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/add-watermark[/:set_id]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'add',
                                    ],
                                    'constraints' => [
                                        'set_id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                            'assign' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/assign',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'assign',
                                    ],
                                ],
                                'may_terminate' => false,
                                'child_routes' => [
                                    'resource' => [
                                        'type' => 'Segment',
                                        'options' => [
                                            'route' => '/:resource-type/:resource-id',
                                            'defaults' => [
                                                '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                                'controller' => 'Index',
                                                'action' => 'assign',
                                            ],
                                            'constraints' => [
                                                'resource-type' => '(item|item-set)',
                                                'resource-id' => '\d+',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'info' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/info/:resource-type/:resource-id',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'info',
                                    ],
                                    'constraints' => [
                                        'resource-type' => '(item|item-set)',
                                        'resource-id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
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
            Form\WatermarkSetForm::class => Form\WatermarkSetForm::class,
            Form\WatermarkAssignmentForm::class => Form\WatermarkAssignmentForm::class,
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