<?php
/**
 * Watermarker
 *
 * A module for Omeka S that adds watermarking capabilities to uploaded and imported media.
 *
 * @copyright Copyright 2025, Your Name
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GPLv3 or later
 */

namespace Watermarker;

use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

class Module extends AbstractModule
{
    /** Module body */

    /**
     * Get module configuration.
     *
     * @return array Module configuration
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Install this module.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');

        // Create watermark table
        $connection->exec("
            CREATE TABLE watermark_setting (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                media_id INT NOT NULL,
                orientation VARCHAR(50) NOT NULL,
                position VARCHAR(50) NOT NULL,
                opacity DECIMAL(3,2) NOT NULL,
                enabled TINYINT(1) NOT NULL,
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * Uninstall this module.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec('DROP TABLE IF EXISTS watermark_setting');
    }

    /**
     * Attach to Omeka events.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Listen for media creation and update events to apply watermarks
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.post',
            [$this, 'handleMediaCreated']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.update.post',
            [$this, 'handleMediaUpdated']
        );

        // Add link to admin navigation
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.layout',
            [$this, 'addAdminNavigation']
        );
    }

    /**
     * Handle media creation event - apply watermarks to eligible new media
     *
     * @param Event $event
     */
    public function handleMediaCreated(Event $event)
    {
        $media = $event->getParam('response')->getContent();
        $this->processMediaWatermark($media);
    }

    /**
     * Handle media update event - reapply watermarks if needed
     *
     * @param Event $event
     */
    public function handleMediaUpdated(Event $event)
    {
        $media = $event->getParam('response')->getContent();
        $this->processMediaWatermark($media);
    }

    /**
     * Add watermark module link to admin navigation
     *
     * @param Event $event
     */
    public function addAdminNavigation(Event $event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/watermark.css', 'Watermarker'));
        $view->headScript()->appendFile($view->assetUrl('js/watermark.js', 'Watermarker'));
    }

    /**
     * Process a media item and apply watermark if appropriate
     *
     * @param MediaRepresentation $media
     */
    protected function processMediaWatermark($media)
    {
        // Skip if not an image
        if (!$this->isWatermarkableMedia($media)) {
            return;
        }

        // Get the appropriate watermark based on orientation
        $watermark = $this->getWatermarkForMedia($media);
        if (!$watermark) {
            return;
        }

        // Apply the watermark
        $this->applyWatermark($media, $watermark);
    }

    /**
     * Check if media is eligible for watermarking (image file)
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    protected function isWatermarkableMedia($media)
    {
        $mediaType = $media->mediaType();
        return (
            $media->hasOriginal() &&
            strpos($mediaType, 'image/') === 0 &&
            $mediaType != 'image/gif' // Skip animated GIFs for now
        );
    }

    /**
     * Get appropriate watermark based on media orientation
     *
     * @param MediaRepresentation $media
     * @return array|null Watermark configuration or null if none applies
     */
    protected function getWatermarkForMedia($media)
    {
        $tempFile = $this->downloadMediaFile($media);
        if (!$tempFile) {
            return null;
        }

        // Get image dimensions to determine orientation
        $imageSize = getimagesize($tempFile);
        unlink($tempFile); // Clean up temp file

        if (!$imageSize) {
            return null;
        }

        $width = $imageSize[0];
        $height = $imageSize[1];
        $orientation = ($width >= $height) ? 'landscape' : 'portrait';

        // Get settings for this orientation
        $settings = $this->getWatermarkSettings();

        foreach ($settings as $setting) {
            if ($setting['orientation'] == $orientation && $setting['enabled']) {
                return $setting;
            }
        }

        return null;
    }

    /**
     * Get all watermark settings from database
     *
     * @return array
     */
    protected function getWatermarkSettings()
    {
        $serviceLocator = $this->getServiceLocator();
        $connection = $serviceLocator->get('Omeka\Connection');

        $sql = "SELECT * FROM watermark_setting WHERE enabled = 1";
        $stmt = $connection->query($sql);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Download original media file to temp location
     *
     * @param MediaRepresentation $media
     * @return string|null Path to temp file or null on failure
     */
    protected function downloadMediaFile($media)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'omeka_watermark_');
        $originalFile = $media->originalUrl();

        // Handle local files
        if (strpos($originalFile, '/') === 0) {
            copy($originalFile, $tempFile);
            return $tempFile;
        }

        // Handle remote files
        $fileData = file_get_contents($originalFile);
        if ($fileData === false) {
            return null;
        }

        file_put_contents($tempFile, $fileData);
        return $tempFile;
    }

    /**
     * Apply watermark to media file
     *
     * @param MediaRepresentation $media
     * @param array $watermark
     */
    protected function applyWatermark($media, $watermark)
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');

        // Get watermark image
        $watermarkMedia = $api->read('media', $watermark['media_id'])->getContent();
        $watermarkFile = $this->downloadMediaFile($watermarkMedia);

        if (!$watermarkFile) {
            return;
        }

        // Get target image
        $mediaFile = $this->downloadMediaFile($media);
        if (!$mediaFile) {
            unlink($watermarkFile);
            return;
        }

        // Create image resources
        $mediaImage = $this->createImageResource($mediaFile, $media->mediaType());
        $watermarkImage = $this->createImageResource($watermarkFile, $watermarkMedia->mediaType());

        if (!$mediaImage || !$watermarkImage) {
            if ($mediaImage) {
                imagedestroy($mediaImage);
            }
            if ($watermarkImage) {
                imagedestroy($watermarkImage);
            }
            unlink($watermarkFile);
            unlink($mediaFile);
            return;
        }

        // Apply watermark
        $this->overlayWatermark(
            $mediaImage,
            $watermarkImage,
            $watermark['position'],
            $watermark['opacity']
        );

        // Save watermarked image
        $resultFile = tempnam(sys_get_temp_dir(), 'omeka_watermarked_');
        $this->saveImageResource($mediaImage, $resultFile, $media->mediaType());

        // Update the file in Omeka S
        // Note: This is a placeholder. The actual implementation would need to use
        // Omeka S's file management system to replace the original file.

        // Clean up
        imagedestroy($mediaImage);
        imagedestroy($watermarkImage);
        unlink($watermarkFile);
        unlink($mediaFile);
        unlink($resultFile);
    }

    /**
     * Create GD image resource from file
     *
     * @param string $file
     * @param string $mediaType
     * @return resource|false
     */
    protected function createImageResource($file, $mediaType)
    {
        switch ($mediaType) {
            case 'image/jpeg':
                return imagecreatefromjpeg($file);
            case 'image/png':
                return imagecreatefrompng($file);
            case 'image/webp':
                return imagecreatefromwebp($file);
            default:
                return false;
        }
    }

    /**
     * Save image resource to file
     *
     * @param resource $image
     * @param string $file
     * @param string $mediaType
     * @return bool
     */
    protected function saveImageResource($image, $file, $mediaType)
    {
        switch ($mediaType) {
            case 'image/jpeg':
                return imagejpeg($image, $file, 95);
            case 'image/png':
                return imagepng($image, $file, 9);
            case 'image/webp':
                return imagewebp($image, $file, 95);
            default:
                return false;
        }
    }

    /**
     * Overlay watermark on image
     *
     * @param resource $baseImage
     * @param resource $watermarkImage
     * @param string $position
     * @param float $opacity
     */
    protected function overlayWatermark($baseImage, $watermarkImage, $position, $opacity)
    {
        // Get dimensions
        $baseWidth = imagesx($baseImage);
        $baseHeight = imagesy($baseImage);
        $watermarkWidth = imagesx($watermarkImage);
        $watermarkHeight = imagesy($watermarkImage);

        // Calculate position
        $x = 0;
        $y = 0;

        switch ($position) {
            case 'top-left':
                $x = 10;
                $y = 10;
                break;
            case 'top-right':
                $x = $baseWidth - $watermarkWidth - 10;
                $y = 10;
                break;
            case 'bottom-left':
                $x = 10;
                $y = $baseHeight - $watermarkHeight - 10;
                break;
            case 'bottom-right':
                $x = $baseWidth - $watermarkWidth - 10;
                $y = $baseHeight - $watermarkHeight - 10;
                break;
            case 'center':
                $x = ($baseWidth - $watermarkWidth) / 2;
                $y = ($baseHeight - $watermarkHeight) / 2;
                break;
        }

        // Apply transparency if supported and requested
        imagealphablending($baseImage, true);

        // Copy watermark onto base image
        imagecopymerge($baseImage, $watermarkImage, $x, $y, 0, 0, $watermarkWidth, $watermarkHeight, $opacity * 100);
    }

    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(Form\ConfigForm::class);

        return $renderer->formCollection($form, false);
    }

    /**
     * Handle configuration form submission.
     *
     * @param AbstractController $controller
     * @return bool
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData($controller->params()->fromPost());

        if (!$form->isValid()) {
            return false;
        }

        $formData = $form->getData();
        $this->setConfig($formData);

        return true;
    }
}