use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Request as ApiRequest;
use WatermarkModule\Job\ReprocessImages;

public function getServiceConfig()
{
    return [
        'invokables' => [
            ReprocessImages::class => ReprocessImages::class,
        ],
    ];
}

public function handleFileUpload($event)
{
    $file = $event->getParam('file');
    $filepath = $file->getStoragePath();
    $this->applyWatermark($filepath);
}

protected function getWatermarkPath($label)
{
    $api = $this->getServiceLocator()->get('Omeka\ApiManager');
    $response = $api->search('assets', ['search' => $label]);

    if ($response->getTotalResults() > 0) {
        $asset = $response->getContent()[0]; // Get the latest uploaded asset
        return $asset->assetUrl(); // Returns the Omeka asset URL
    }

    return null; // If no asset found
}

protected function applyWatermark($filepath)
{
    $settings = $this->getServiceLocator()->get('Omeka\Settings');

    // Get selected assets
    $watermarkPortraitId = $settings->get('watermark_portrait');
    $watermarkLandscapeId = $settings->get('watermark_landscape');
    $enableWatermarking = $settings->get('enable_watermarking');

    if (!$enableWatermarking || !$watermarkPortraitId || !$watermarkLandscapeId) {
        return;
    }

    $api = $this->getServiceLocator()->get('Omeka\ApiManager');

    // Fetch asset URLs
    $portraitAsset = $api->read('assets', $watermarkPortraitId)->getContent();
    $landscapeAsset = $api->read('assets', $watermarkLandscapeId)->getContent();
    $portraitPath = $portraitAsset->assetUrl();
    $landscapePath = $landscapeAsset->assetUrl();

    // Download assets to temp files
    $tempPortrait = tempnam(sys_get_temp_dir(), 'wm_portrait');
    $tempLandscape = tempnam(sys_get_temp_dir(), 'wm_landscape');

    file_put_contents($tempPortrait, file_get_contents($portraitPath));
    file_put_contents($tempLandscape, file_get_contents($landscapePath));

    // Get image dimensions
    list($width, $height) = getimagesize($filepath);

    // Choose the correct watermark
    $watermark = ($height > $width) ? $tempPortrait : $tempLandscape;

    // Resize watermark to match image width
    $resizedWatermark = tempnam(sys_get_temp_dir(), 'wm_resized');
    shell_exec("convert $watermark -resize ${width}x $resizedWatermark");

    // Apply watermark at the bottom
    shell_exec("convert $filepath $resizedWatermark -gravity south -composite $filepath");

    // Cleanup
    unlink($tempPortrait);
    unlink($tempLandscape);
    unlink($resizedWatermark);
}


public function getConfigForm(\Laminas\Form\Form $form)
{
    $api = $this->getServiceLocator()->get('Omeka\ApiManager');

    // Fetch available assets (watermarks)
    $assets = $api->search('assets')->getContent();
    $assetOptions = [];
    foreach ($assets as $asset) {
        $assetOptions[$asset->id()] = $asset->name();
    }

    // Add dropdown for portrait watermark
    $form->add([
        'name' => 'watermark_portrait',
        'type' => 'select',
        'options' => [
            'label' => 'Select Portrait Watermark',
            'value_options' => $assetOptions,
        ],
    ]);

    // Add dropdown for landscape watermark
    $form->add([
        'name' => 'watermark_landscape',
        'type' => 'select',
        'options' => [
            'label' => 'Select Landscape Watermark',
            'value_options' => $assetOptions,
        ],
    ]);

    // Enable/disable watermarking
    $form->add([
        'name' => 'enable_watermarking',
        'type' => 'checkbox',
        'options' => [
            'label' => 'Enable Watermarking',
        ],
    ]);

    // Reprocess button
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
