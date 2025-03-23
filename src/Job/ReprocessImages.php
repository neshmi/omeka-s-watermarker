<?php
namespace Watermarker\Job;

use Omeka\Job\AbstractJob;
use Omeka\Api\Manager as ApiManager;

class ReprocessImages extends AbstractJob
{
    public function perform()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $files = $api->search('files')->getContent();

        $watermarkModule = new \Watermarker\Module();

        foreach ($files as $file) {
            $filepath = $file->getStoragePath();
            $watermarkModule->applyWatermark($filepath);
        }
    }
}
