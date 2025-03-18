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
        return $renderer->render('watermarker/admin/config-form');
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
