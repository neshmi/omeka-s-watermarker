namespace WatermarkModule\Job;

use Omeka\Job\AbstractJob;

class ReprocessImages extends AbstractJob
{
    public function perform()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $files = $api->search('files')->getContent();

        $watermarkModule = new \WatermarkModule\Module();

        foreach ($files as $file) {
            $filepath = $file->getStoragePath();
            $watermarkModule->applyWatermark($filepath);
        }
    }
}
