<?php
namespace Watermarker;

use Omeka\Module\AbstractModule;
use Omeka\Settings\Settings;
use Omeka\Api\Manager as ApiManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Job\Dispatcher;
use Laminas\Mvc\Controller\AbstractController;
use Watermarker\Job\ReprocessImages;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        // Fetch all assets
        $assets = $api->search('assets')->getContent();
        $assetOptions = [];

        foreach ($assets as $asset) {
            // Only add images (PNG, JPG, etc.)
            if (strpos($asset->mediaType(), 'image') !== false) {
                $assetOptions[$asset->id()] = $asset->name();
            }
        }

        return $renderer->render('watermarker/admin/config-form', [
            'assetOptions' => $assetOptions,
            'watermarkPortrait' => $this->getServiceLocator()->get('Omeka\Settings')->get('watermark_portrait'),
            'watermarkLandscape' => $this->getServiceLocator()->get('Omeka\Settings')->get('watermark_landscape'),
            'enableWatermarking' => $this->getServiceLocator()->get('Omeka\Settings')->get('enable_watermarking'),
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $request = $controller->getRequest();

        if ($request->isPost()) {
            $postData = $request->getPost();
            $settings->set('watermark_portrait', $postData['watermark_portrait'] ?? null);
            $settings->set('watermark_landscape', $postData['watermark_landscape'] ?? null);
            $settings->set('enable_watermarking', isset($postData['enable_watermarking']));

            if (isset($postData['reprocess_images'])) {
                $jobDispatcher = $this->getServiceLocator()->get('Omeka\Job\Dispatcher');
                $jobDispatcher->dispatch(ReprocessImages::class, []);
            }
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
