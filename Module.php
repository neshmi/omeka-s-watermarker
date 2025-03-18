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
use Laminas\View\Renderer\PhpRenderer;
use Watermarker\Job\ReprocessImages;

class Module extends AbstractModule
{
    public function getConfigForm(PhpRenderer $renderer)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        // Fetch available assets (watermarks)
        $assets = $api->search('assets')->getContent();
        $assetOptions = [];
        foreach ($assets as $asset) {
            $assetOptions[$asset->id()] = $asset->name();
        }

        return $renderer->render('admin/settings-form', [
            'assetOptions' => $assetOptions,
            'watermarkPortrait' => $this->getServiceLocator()->get('Omeka\Settings')->get('watermark_portrait'),
            'watermarkLandscape' => $this->getServiceLocator()->get('Omeka\Settings')->get('watermark_landscape'),
            'enableWatermarking' => $this->getServiceLocator()->get('Omeka\Settings')->get('enable_watermarking'),
        ]);
    }

    public function handleConfigForm(\Laminas\Http\PhpEnvironment\Request $request)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Save watermark selections
        $settings->set('watermark_portrait', $request->getPost('watermark_portrait'));
        $settings->set('watermark_landscape', $request->getPost('watermark_landscape'));
        $settings->set('enable_watermarking', (bool) $request->getPost('enable_watermarking'));

        // Trigger background job if reprocessing is requested
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
