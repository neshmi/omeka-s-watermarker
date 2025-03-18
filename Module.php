<?php
namespace Watermarker;

use Omeka\Module\AbstractModule;
use Omeka\Job\AbstractJob;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Settings\Settings;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Request as ApiRequest;
use Omeka\Job\Dispatcher;
use Omeka\Module\Manager;
use Watermarker\Job\ReprocessImages;

class Module extends AbstractModule
{
    public function getConfigForm(\Laminas\Form\Form $form)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        // Fetch available assets (watermarks)
        $assets = $api->search('assets')->getContent();
        $assetOptions = [];
        foreach ($assets as $asset) {
            $assetOptions[$asset->id()] = $asset->name();
        }

        $form->add([
            'name' => 'watermark_portrait',
            'type' => 'select',
            'options' => [
                'label' => 'Select Portrait Watermark',
                'value_options' => $assetOptions,
            ],
        ]);

        $form->add([
            'name' => 'watermark_landscape',
            'type' => 'select',
            'options' => [
                'label' => 'Select Landscape Watermark',
                'value_options' => $assetOptions,
            ],
        ]);

        $form->add([
            'name' => 'enable_watermarking',
            'type' => 'checkbox',
            'options' => [
                'label' => 'Enable Watermarking',
            ],
        ]);

        $form->add([
            'name' => 'reprocess_images',
            'type' => 'submit',
            'attributes' => [
                'value' => 'Reprocess Images',
            ],
        ]);
    }

    public function handleConfigForm(\Laminas\Http\PhpEnvironment\Request $request, \Laminas\Form\Form $form)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $settings->set('watermark_portrait', $request->getPost('watermark_portrait'));
        $settings->set('watermark_landscape', $request->getPost('watermark_landscape'));
        $settings->set('enable_watermarking', (bool) $request->getPost('enable_watermarking'));

        if ($request->getPost('reprocess_images')) {
            $jobDispatcher = $this->getServiceLocator()->get('Omeka\Job\Dispatcher');
            $jobDispatcher->dispatch(ReprocessImages::class, []);
        }
    }

    public function getServiceConfig()
    {
        return [
            'invokables' => [
                ReprocessImages::class => ReprocessImages::class,
            ],
        ];
    }
}
