<?php
/**
 * Watermarker module configuration
 */

namespace Watermarker;

return [
    'api_adapters' => [
        'invokables' => [
            'watermark_sets' => Api\Adapter\WatermarkSetAdapter::class,
            'watermark_settings' => Api\Adapter\WatermarkSettingAdapter::class,
            'watermark_assignments' => Api\Adapter\WatermarkAssignmentAdapter::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Watermarker\Controller\Admin\Index' => Controller\Admin\IndexControllerFactory::class,
            'Watermarker\Controller\Admin\Assignment' => Service\Factory\AssignmentControllerFactory::class,
            'Watermarker\Controller\Api' => Service\Factory\ApiControllerFactory::class,
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
                            'route' => '/watermarker',
                            'defaults' => [
                                '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'config' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/config',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'config',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                            'check' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/check',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'check',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
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
                            'editSet' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/editSet/:id',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'editSet',
                                    ],
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
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
                                        'controller' => 'Assignment',
                                        'action' => 'assign',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                            'info' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/info/:resource-type/:resource-id',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Watermarker\Controller\Admin',
                                        'controller' => 'Assignment',
                                        'action' => 'info',
                                    ],
                                    'constraints' => [
                                        'resource-type' => '(item|item-set|media)',
                                        'resource-id' => '\d+',
                                    ],
                                ],
                                'may_terminate' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'watermarker-api' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/watermarker-api/:action',
                    'defaults' => [
                        '__NAMESPACE__' => 'Watermarker\Controller',
                        'controller' => 'Api',
                    ],
                    'constraints' => [
                        'action' => '(getAssignment|setAssignment|getWatermarkSets)',
                    ],
                ],
                'may_terminate' => true,
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
            'Watermarker\Service\WatermarkService' => 'Watermarker\Service\WatermarkServiceFactory',
            'Watermarker\AssignmentService' => Service\Factory\AssignmentServiceFactory::class,
            'Watermarker\Service\WatermarkApplicator' => Service\Factory\WatermarkApplicatorFactory::class,
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
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'cli_commands' => [
        'factories' => [
            Command\WatermarkProcessor::class => Service\Factory\CommandFactory::class,
        ],
    ],
    // No need to register jobs anymore since we're not using the job system
];