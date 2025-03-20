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
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @var object Track uploaded temp file for later processing
     */
    protected $tempFileUploaded = null;

    /**
     * @var bool Track whether derivatives have been created
     */
    protected $derivativesCreated = false;

    /**
     * @var array Information about the last stored file
     */
    protected $lastStoredFile = null;

    /**
     * @var int ID of the last hydrated media entity
     */
    protected $lastHydratedMediaId = null;

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
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

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

        // Also listen to after.save.media for existing items
        $sharedEventManager->attach(
            'Omeka\Entity\Media',
            'entity.persist.post',
            [$this, 'handleMediaPersisted']
        );

        // Listen to hydrate post event
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'handleMediaHydrated']
        );

        // Listen specifically for file uploads
        $sharedEventManager->attach(
            'Omeka\File\Ingester\Upload',
            'upload.post',
            [$this, 'handleMediaUploaded']
        );

        // Also listen to derivative creation events
        $sharedEventManager->attach(
            'Omeka\File\TempFileFactory',
            'create_derivatives.post',
            [$this, 'handleDerivativesCreated']
        );

        // Listen to the stored event on the File/Store adapter
        $sharedEventManager->attach(
            'Omeka\File\Store\Filesystem',
            'store.post',
            [$this, 'handleFileStored']
        );

        // Add link to admin navigation
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.layout',
            [$this, 'addAdminNavigation']
        );

        // Enable this for debugging only - it generates too much noise in logs
        /*
        $sharedEventManager->attach(
            '*',
            '*',
            function (Event $event) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');

                // Only log API events that might involve media
                if (strpos($event->getName(), 'media') !== false ||
                    strpos($event->getName(), 'item') !== false ||
                    strpos($event->getName(), 'api') !== false) {

                    $logger->info(sprintf(
                        'Watermarker: Event "%s" on "%s" triggered',
                        $event->getName(),
                        $event->getTarget() ? get_class($event->getTarget()) : 'unknown'
                    ));
                }
            }
        );
        */
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
     * Handle media creation event - apply watermarks to eligible new media
     *
     * @param Event $event
     */
    public function handleMediaCreated(Event $event)
    {
        // This event is no longer the primary handler for watermarking
        // We rely on the handleMediaPersisted event instead to avoid timing issues
    }

    /**
     * Handle media update event - reapply watermarks if needed
     *
     * @param Event $event
     */
    public function handleMediaUpdated(Event $event)
    {
        // Media updates may need re-watermarking but we use the same
        // approach as for new uploads - the persisted event will handle it
    }

    /**
     * Handle media hydration event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaHydrated(Event $event)
    {
        // This event is no longer needed as we use the persisted event
    }

    /**
     * Handle media persisted event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaPersisted(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: Media persisted event triggered');

        // Get the entity from the event
        $entity = $event->getTarget();

        if (!$entity) {
            $logger->err('Watermarker: No entity in persisted event');
            return;
        }

        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Check if watermarking is enabled
        if (!$settings->get('watermarker_enabled', true)) {
            $logger->info('Watermarker: Watermarking disabled in settings');
            return;
        }

        if (!$settings->get('watermarker_apply_on_upload', true)) {
            $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
            return;
        }

        // This is our fallback approach - wait for derivatives to be generated
        // and then try to watermark them directly
        $logger->info(sprintf('Watermarker: Scheduling watermark for media ID: %s', $entity->getId()));

        // Use register_shutdown_function to ensure this runs after the response
        register_shutdown_function(function() use ($entity, $logger) {
            try {
                // Wait for derivatives to be generated - use a longer wait time
                $logger->info('Watermarker: Waiting 25 seconds for derivatives to be generated...');
                sleep(25);

                // Get needed services
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $watermarkService = $this->watermarkService();

                // Get the media representation
                $media = $api->read('media', $entity->getId())->getContent();

                // Check if this is an image type we support
                $mediaType = $media->mediaType();
                if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'])) {
                    $logger->info(sprintf('Watermarker: Skipping unsupported media type: %s', $mediaType));
                    return;
                }

                $logger->info(sprintf('Watermarker: Processing media ID: %s (type: %s)', $entity->getId(), $mediaType));

                // Get derivatives
                $derivatives = $media->thumbnailUrls();
                if (empty($derivatives) || !isset($derivatives['large'])) {
                    $logger->err('Watermarker: No large derivative found for media');
                    return;
                }

                $logger->info(sprintf('Watermarker: Found derivatives: %s', implode(', ', array_keys($derivatives))));

                // Get the first available watermark
                $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
                $watermarkConfig = $connection->fetchAssoc($sql);

                if (!$watermarkConfig) {
                    $logger->info('Watermarker: No enabled watermark configurations found');
                    return;
                }

                $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s (%s)',
                    $watermarkConfig['id'], $watermarkConfig['name']));

                // Get media entity to access storage ID
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $entity->getId());

                if (!$mediaEntity) {
                    $logger->err('Watermarker: Media entity not found');
                    return;
                }

                $storageId = $mediaEntity->getStorageId();

                // Try to find the large derivative
                $largePath = null;
                $possiblePaths = [
                    OMEKA_PATH . '/files/large/' . $storageId,
                    OMEKA_PATH . '/files/large/' . $storageId . '.jpg',
                    OMEKA_PATH . '/files/large/' . $storageId . '.jpeg',
                    OMEKA_PATH . '/files/large/' . $storageId . '.png',
                    OMEKA_PATH . '/files/large/' . $storageId . '.webp',
                    '/var/www/html/files/large/' . $storageId,
                    '/var/www/html/files/large/' . $storageId . '.jpg',
                    '/var/www/html/files/large/' . $storageId . '.jpeg',
                    '/var/www/html/files/large/' . $storageId . '.png',
                    '/var/www/html/files/large/' . $storageId . '.webp',
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $largePath = $path;
                        $logger->info(sprintf('Watermarker: Found large derivative at: %s', $path));
                        break;
                    }
                }

                // If still not found, try glob with more aggressive pattern matching
                if (!$largePath) {
                    // First try exact storage ID with any extension
                    $globPattern = OMEKA_PATH . '/files/large/' . $storageId . '*';
                    $matches = glob($globPattern);

                    if (!empty($matches)) {
                        $largePath = $matches[0];
                        $logger->info(sprintf('Watermarker: Found via glob: %s', $largePath));
                    } else {
                        // Try alternate location
                        $globPattern = '/var/www/html/files/large/' . $storageId . '*';
                        $matches = glob($globPattern);

                        if (!empty($matches)) {
                            $largePath = $matches[0];
                            $logger->info(sprintf('Watermarker: Found via alternate glob: %s', $largePath));
                        } else {
                            // Try searching for partial storage ID match (first 16 chars)
                            $partialId = substr($storageId, 0, 16);
                            $logger->info(sprintf('Watermarker: Trying partial storage ID: %s', $partialId));

                            $globPattern = OMEKA_PATH . '/files/large/' . $partialId . '*';
                            $matches = glob($globPattern);

                            if (!empty($matches)) {
                                $largePath = $matches[0];
                                $logger->info(sprintf('Watermarker: Found via partial ID glob: %s', $largePath));
                            } else {
                                // Try alternate location with partial ID
                                $globPattern = '/var/www/html/files/large/' . $partialId . '*';
                                $matches = glob($globPattern);

                                if (!empty($matches)) {
                                    $largePath = $matches[0];
                                    $logger->info(sprintf('Watermarker: Found via alternate partial ID glob: %s', $largePath));
                                }
                            }
                        }
                    }
                }

                if (!$largePath) {
                    $logger->err('Watermarker: Could not find large derivative file');
                    return;
                }

                // Get the watermark asset
                $assetId = $watermarkConfig['media_id'];
                $watermarkAsset = $api->read('assets', $assetId)->getContent();

                if (!$watermarkAsset) {
                    $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                    return;
                }

                // Get watermark asset path
                $assetFilename = basename($watermarkAsset->assetUrl());
                $assetPath = null;
                $possibleAssetPaths = [
                    OMEKA_PATH . '/files/asset/' . $assetFilename,
                    '/var/www/html/files/asset/' . $assetFilename,
                ];

                foreach ($possibleAssetPaths as $path) {
                    if (file_exists($path)) {
                        $assetPath = $path;
                        break;
                    }
                }

                if (!$assetPath) {
                    $logger->err('Watermarker: Could not find watermark asset file on disk');
                    return;
                }

                // Get the file type of the derivative
                $fileInfo = @getimagesize($largePath);
                if (!$fileInfo) {
                    $logger->err('Watermarker: Failed to get image info for large derivative');
                    return;
                }

                $derivativeType = image_type_to_mime_type($fileInfo[2]);
                $logger->info(sprintf('Watermarker: Large derivative is type: %s, dimensions: %dx%d',
                    $derivativeType, $fileInfo[0], $fileInfo[1]
                ));

                // Create image resources
                $mediaImage = $watermarkService->createImageResource($largePath, $derivativeType);
                if (!$mediaImage) {
                    $logger->err('Watermarker: Failed to create image resource from large derivative');
                    return;
                }

                $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
                if (!$watermarkImage) {
                    $logger->err('Watermarker: Failed to create image resource from watermark');
                    imagedestroy($mediaImage);
                    return;
                }

                // Apply watermark
                $logger->info(sprintf(
                    'Watermarker: Applying watermark (position: %s, opacity: %.2f)',
                    $watermarkConfig['position'], (float)$watermarkConfig['opacity']
                ));

                $watermarkService->overlayWatermark(
                    $mediaImage,
                    $watermarkImage,
                    $watermarkConfig['position'],
                    (float)$watermarkConfig['opacity']
                );

                // Create a temp file for the result with proper extension
                $tempDir = sys_get_temp_dir();
                $tempResult = tempnam($tempDir, 'watermarked_');
                
                // Get file extension from derivative type
                $fileExt = $watermarkService->getExtensionForMimeType($derivativeType);
                if (!empty($fileExt)) {
                    $tempResultWithExt = $tempResult . '.' . $fileExt;
                    rename($tempResult, $tempResultWithExt);
                    $tempResult = $tempResultWithExt;
                    $logger->info(sprintf('Watermarker: Added extension to temp file: %s', $fileExt));
                }

                // Save the watermarked image
                $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

                // Clean up resources
                imagedestroy($mediaImage);
                imagedestroy($watermarkImage);

                if (!$saveSuccess) {
                    $logger->err('Watermarker: Failed to save watermarked image');
                    @unlink($tempResult);
                    return;
                }

                // Verify temp file has content
                if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                        $tempResult,
                        file_exists($tempResult) ? filesize($tempResult) : 0
                    ));
                    
                    // Check for any PHP error messages
                    $lastError = error_get_last();
                    if ($lastError) {
                        $logger->err(sprintf(
                            'Watermarker: Last PHP error: %s in %s on line %d',
                            $lastError['message'],
                            $lastError['file'],
                            $lastError['line']
                        ));
                    }
                    
                    @unlink($tempResult);
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Temp result file created successfully (size: %d bytes)',
                    filesize($tempResult)
                ));

                // Replace the original derivative with the watermarked version
                $copySuccess = false;

                // Check file permissions before copy
                $targetDir = dirname($largePath);
                if (!is_writable($targetDir)) {
                    $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                    // Try to adjust permissions if possible
                    $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                    @chmod($targetDir, 0777);

                    if (!is_writable($targetDir)) {
                        $logger->err('Watermarker: Unable to make target directory writable');
                    }
                }

                // Try file_put_contents first (more reliable in some environments)
                $fileContents = file_get_contents($tempResult);
                if ($fileContents !== false) {
                    $bytesWritten = file_put_contents($largePath, $fileContents);
                    if ($bytesWritten !== false && $bytesWritten > 0) {
                        $logger->info(sprintf(
                            'Watermarker: Successfully wrote %d bytes to large derivative using file_put_contents',
                            $bytesWritten
                        ));
                        $copySuccess = true;
                    } else {
                        $logger->err('Watermarker: Failed to write to large derivative using file_put_contents');

                        // Try copy as fallback
                        if (@copy($tempResult, $largePath)) {
                            $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                            $copySuccess = true;
                        } else {
                            $logger->err(sprintf(
                                'Watermarker: All file write methods failed for large derivative: %s (error: %s)',
                                $largePath,
                                error_get_last()['message'] ?? 'Unknown error'
                            ));
                        }
                    }
                } else {
                    // Try copy as fallback
                    if (@copy($tempResult, $largePath)) {
                        $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: Copy failed for large derivative: %s (error: %s)',
                            $largePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }

                // Clean up temp file
                @unlink($tempResult);

                // Verify final file
                if ($copySuccess && file_exists($largePath)) {
                    $finalSize = filesize($largePath);
                    if ($finalSize < 100) {
                        $logger->err(sprintf(
                            'Watermarker: Final large derivative is too small (%d bytes), likely corrupted',
                            $finalSize
                        ));
                        return;
                    }

                    $logger->info(sprintf(
                        'Watermarker: Final large derivative verified successfully (size: %d bytes)',
                        $finalSize
                    ));
                    $logger->info('Watermarker: Successfully applied watermark to large derivative');
                } else {
                    $logger->err('Watermarker: Failed to verify final watermarked large derivative');
                }

            } catch (\Exception $e) {
                $logger->err(sprintf(
                    'Watermarker: Error in watermarking: %s',
                    $e->getMessage()
                ));
                $logger->err(sprintf(
                    'Watermarker: Error trace: %s',
                    $e->getTraceAsString()
                ));
            }
        });
    }

    /**
     * Handle media upload event
     *
     * @param Event $event
     */
    public function handleMediaUploaded(Event $event)
    {
        // No longer needed as we use the persisted event
    }

    /**
     * Handle derivatives created event - this is the ideal place to watermark
     * as it happens right after Omeka creates the derivatives
     *
     * @param Event $event
     */
    public function handleDerivativesCreated(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $tempFile = $event->getTarget();
        $logger->info('Watermarker: Derivatives created event triggered');

        // Dump all event info to debug
        $logger->info(sprintf(
            'Watermarker: Event target class: %s',
            get_class($tempFile)
        ));

        // Check if the event has the expected target structure
        if (!method_exists($tempFile, 'getMediaType')) {
            $logger->err('Watermarker: Event target does not have getMediaType method');

            // Try to extract useful information from the event object
            $params = $event->getParams();
            $name = $event->getName();
            $target = $event->getTarget();

            $logger->info(sprintf(
                'Watermarker: Event details - Name: %s, Target class: %s, Params: %s',
                $name,
                is_object($target) ? get_class($target) : gettype($target),
                print_r($params, true)
            ));
            return;
        }

        try {
            // Get needed services
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $watermarkService = $this->watermarkService();
            $settings = $this->getServiceLocator()->get('Omeka\Settings');

            // Check if watermarking is enabled
            if (!$settings->get('watermarker_enabled', true)) {
                $logger->info('Watermarker: Watermarking disabled in settings');
                return;
            }

            if (!$settings->get('watermarker_apply_on_upload', true)) {
                $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
                return;
            }

            // Check if this is an image
            $mediaType = $tempFile->getMediaType();
            $logger->info(sprintf('Watermarker: Media type from tempFile: %s', $mediaType));

            if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'])) {
                $logger->info(sprintf('Watermarker: Not a supported image type: %s', $mediaType));
                return;
            }

            // Check if getStoragePaths method exists
            if (!method_exists($tempFile, 'getStoragePaths')) {
                $logger->err('Watermarker: TempFile does not have getStoragePaths method');

                // Log available methods on tempFile
                $methods = get_class_methods($tempFile);
                $logger->info(sprintf(
                    'Watermarker: Available methods on tempFile: %s',
                    implode(', ', $methods)
                ));
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Processing new derivatives for file: %s (type: %s)',
                $tempFile->getSourceName(), $mediaType
            ));

            // Get the first available watermark configuration
            $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
            $watermarkConfig = $connection->fetchAssoc($sql);

            if (!$watermarkConfig) {
                $logger->info('Watermarker: No enabled watermark configurations found');
                return;
            }

            $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s (%s)',
                $watermarkConfig['id'], $watermarkConfig['name']
            ));

            // Get the watermark asset
            $assetId = $watermarkConfig['media_id'];
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            if (!$watermarkAsset) {
                $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                return;
            }

            // Get watermark asset path
            $assetFilename = basename($watermarkAsset->assetUrl());
            $assetPath = null;
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
            ];

            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    break;
                }
            }

            if (!$assetPath) {
                $logger->err('Watermarker: Could not find watermark asset file on disk');
                return;
            }

            // Get the large derivative path directly
            $derivativePaths = $tempFile->getStoragePaths();
            $logger->info(sprintf(
                'Watermarker: Available derivative paths: %s',
                print_r($derivativePaths, true)
            ));

            if (!isset($derivativePaths['large'])) {
                $logger->err('Watermarker: No large derivative path available');
                return;
            }

            $largePath = $derivativePaths['large'];
            $logger->info(sprintf('Watermarker: Found large derivative at: %s', $largePath));

            // Make sure the large derivative exists
            if (!file_exists($largePath)) {
                $logger->err('Watermarker: Large derivative file does not exist');

                // Try to find if the directory exists
                $dir = dirname($largePath);
                if (!is_dir($dir)) {
                    $logger->err(sprintf('Watermarker: Directory does not exist: %s', $dir));
                } else {
                    $logger->info(sprintf('Watermarker: Directory exists: %s', $dir));
                    // List files in the directory
                    $files = scandir($dir);
                    $logger->info(sprintf(
                        'Watermarker: Files in directory: %s',
                        implode(', ', $files)
                    ));
                }
                return;
            }

            // Get the file type of the derivative
            $fileInfo = @getimagesize($largePath);
            if (!$fileInfo) {
                $logger->err('Watermarker: Failed to get image info for large derivative');
                return;
            }

            $derivativeType = image_type_to_mime_type($fileInfo[2]);
            $logger->info(sprintf('Watermarker: Large derivative is type: %s, dimensions: %dx%d',
                $derivativeType, $fileInfo[0], $fileInfo[1]
            ));

            // Create image resources
            $mediaImage = $watermarkService->createImageResource($largePath, $derivativeType);
            if (!$mediaImage) {
                $logger->err('Watermarker: Failed to create image resource from large derivative');
                return;
            }

            $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
            if (!$watermarkImage) {
                $logger->err('Watermarker: Failed to create image resource from watermark');
                imagedestroy($mediaImage);
                return;
            }

            // Apply watermark
            $logger->info(sprintf(
                'Watermarker: Applying watermark to large derivative (position: %s, opacity: %.2f)',
                $watermarkConfig['position'], (float)$watermarkConfig['opacity']
            ));

            $watermarkService->overlayWatermark(
                $mediaImage,
                $watermarkImage,
                $watermarkConfig['position'],
                (float)$watermarkConfig['opacity']
            );

            // Create a temp file for the result with proper extension
            $tempDir = sys_get_temp_dir();
            $tempResult = tempnam($tempDir, 'watermarked_');
            
            // Get file extension from derivative type
            $fileExt = $watermarkService->getExtensionForMimeType($derivativeType);
            if (!empty($fileExt)) {
                $tempResultWithExt = $tempResult . '.' . $fileExt;
                rename($tempResult, $tempResultWithExt);
                $tempResult = $tempResultWithExt;
                $logger->info(sprintf('Watermarker: Added extension to temp file: %s', $fileExt));
            }

            // Save the watermarked image
            $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

            // Clean up resources
            imagedestroy($mediaImage);
            imagedestroy($watermarkImage);

            if (!$saveSuccess) {
                $logger->err('Watermarker: Failed to save watermarked image');
                @unlink($tempResult);
                return;
            }

            // Verify temp file has content
            if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                $logger->err(sprintf(
                    'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                    $tempResult,
                    file_exists($tempResult) ? filesize($tempResult) : 0
                ));
                @unlink($tempResult);
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Temp result file created successfully (size: %d bytes)',
                filesize($tempResult)
            ));

            // Replace the original derivative with the watermarked version
            $copySuccess = false;

            // Check file permissions before copy
            $targetDir = dirname($largePath);
            if (!is_writable($targetDir)) {
                $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                // Try to adjust permissions if possible
                $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                @chmod($targetDir, 0777);

                if (!is_writable($targetDir)) {
                    $logger->err('Watermarker: Unable to make target directory writable');
                }
            }

            // Try file_put_contents first (more reliable in some environments)
            $fileContents = file_get_contents($tempResult);
            if ($fileContents !== false) {
                $bytesWritten = file_put_contents($largePath, $fileContents);
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $logger->info(sprintf(
                        'Watermarker: Successfully wrote %d bytes to large derivative using file_put_contents',
                        $bytesWritten
                    ));
                    $copySuccess = true;
                } else {
                    $logger->err('Watermarker: Failed to write to large derivative using file_put_contents');

                    // Try copy as fallback
                    if (@copy($tempResult, $largePath)) {
                        $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: All file write methods failed for large derivative: %s (error: %s)',
                            $largePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }
            } else {
                // Try copy as fallback
                if (@copy($tempResult, $largePath)) {
                    $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                    $copySuccess = true;
                } else {
                    $logger->err(sprintf(
                        'Watermarker: Copy failed for large derivative: %s (error: %s)',
                        $largePath,
                        error_get_last()['message'] ?? 'Unknown error'
                    ));
                }
            }

            // Clean up temp file
            @unlink($tempResult);

            // Verify final file
            if ($copySuccess && file_exists($largePath)) {
                $finalSize = filesize($largePath);
                if ($finalSize < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Final large derivative is too small (%d bytes), likely corrupted',
                        $finalSize
                    ));
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Final large derivative verified successfully (size: %d bytes)',
                    $finalSize
                ));
                $logger->info('Watermarker: Successfully applied watermark to large derivative');
            } else {
                $logger->err('Watermarker: Failed to verify final watermarked large derivative');
            }

        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error in watermarking derivatives: %s',
                $e->getMessage()
            ));
            $logger->err(sprintf(
                'Watermarker: Error trace: %s',
                $e->getTraceAsString()
            ));
        }
    }

    /**
     * Handle file stored event
     *
     * This is triggered after a file is stored in the filesystem
     *
     * @param Event $event
     */
    public function handleFileStored(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: File stored event triggered');

        // Log details about the event
        $params = $event->getParams();
        $name = $event->getName();
        $target = $event->getTarget();

        $logger->info(sprintf(
            'Watermarker: File stored event details - Name: %s, Target class: %s, Params: %s',
            $name,
            is_object($target) ? get_class($target) : gettype($target),
            print_r($params, true)
        ));

        try {
            // Get the stored file info
            $storedInfo = $event->getParam('stored');

            // Check if we have the necessary information
            if (!$storedInfo || !isset($storedInfo['filename']) || !isset($storedInfo['type'])) {
                $logger->err('Watermarker: Missing stored file information');
                return;
            }

            $filename = $storedInfo['filename'];
            $type = $storedInfo['type'];

            // Only proceed if this is a derivative
            if ($type !== 'large') {
                $logger->info(sprintf('Watermarker: Ignoring non-large derivative: %s', $type));
                return;
            }

            $logger->info(sprintf('Watermarker: File stored - type: %s, filename: %s', $type, $filename));

            // Get needed services
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $watermarkService = $this->watermarkService();
            $settings = $this->getServiceLocator()->get('Omeka\Settings');

            // Check if watermarking is enabled
            if (!$settings->get('watermarker_enabled', true)) {
                $logger->info('Watermarker: Watermarking disabled in settings');
                return;
            }

            if (!$settings->get('watermarker_apply_on_upload', true)) {
                $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
                return;
            }

            // Get the file path - need to construct based on Omeka's storage structure
            $filePath = OMEKA_PATH . '/files/' . $type . '/' . $filename;
            if (!file_exists($filePath)) {
                // Try alternate path
                $filePath = '/var/www/html/files/' . $type . '/' . $filename;
                if (!file_exists($filePath)) {
                    $logger->err(sprintf('Watermarker: File does not exist: %s', $filePath));
                    return;
                }
            }

            $logger->info(sprintf('Watermarker: Found file at: %s', $filePath));

            // Get the first available watermark configuration
            $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
            $watermarkConfig = $connection->fetchAssoc($sql);

            if (!$watermarkConfig) {
                $logger->info('Watermarker: No enabled watermark configurations found');
                return;
            }

            $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s (%s)',
                $watermarkConfig['id'], $watermarkConfig['name']
            ));

            // Get the watermark asset
            $assetId = $watermarkConfig['media_id'];
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            if (!$watermarkAsset) {
                $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                return;
            }

            // Get watermark asset path
            $assetFilename = basename($watermarkAsset->assetUrl());
            $assetPath = null;
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
            ];

            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    break;
                }
            }

            if (!$assetPath) {
                $logger->err('Watermarker: Could not find watermark asset file on disk');
                return;
            }

            // Get the file info
            $fileInfo = @getimagesize($filePath);
            if (!$fileInfo) {
                $logger->err(sprintf('Watermarker: Failed to get image info for file: %s', $filePath));
                return;
            }

            $derivativeType = image_type_to_mime_type($fileInfo[2]);
            $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $logger->info(sprintf('Watermarker: File is type: %s (extension: %s), dimensions: %dx%d',
                $derivativeType, $fileExt, $fileInfo[0], $fileInfo[1]
            ));

            // Check if file extension matches media type
            $extMimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif'
            ];

            $expectedMimeType = isset($extMimeMap[$fileExt]) ? $extMimeMap[$fileExt] : null;

            if ($expectedMimeType && $expectedMimeType != $derivativeType) {
                $logger->info(sprintf(
                    'Watermarker: File has %s extension but detected type is %s, using detected type',
                    $fileExt, $derivativeType
                ));
            }

            // Create image resources
            $mediaImage = $watermarkService->createImageResource($filePath, $derivativeType);
            if (!$mediaImage) {
                $logger->err(sprintf('Watermarker: Failed to create image resource from file: %s', $filePath));
                return;
            }

            $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
            if (!$watermarkImage) {
                $logger->err('Watermarker: Failed to create image resource from watermark');
                imagedestroy($mediaImage);
                return;
            }

            // Apply watermark
            $logger->info(sprintf(
                'Watermarker: Applying watermark (position: %s, opacity: %.2f)',
                $watermarkConfig['position'], (float)$watermarkConfig['opacity']
            ));

            $watermarkService->overlayWatermark(
                $mediaImage,
                $watermarkImage,
                $watermarkConfig['position'],
                (float)$watermarkConfig['opacity']
            );

            // Create a temp file for the result
            $tempDir = sys_get_temp_dir();
            $tempResult = tempnam($tempDir, 'watermarked_');

            // Save the watermarked image
            $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

            // Clean up resources
            imagedestroy($mediaImage);
            imagedestroy($watermarkImage);

            if (!$saveSuccess) {
                $logger->err('Watermarker: Failed to save watermarked image');
                @unlink($tempResult);
                return;
            }

            // Verify temp file has content
            if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                $logger->err(sprintf(
                    'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                    $tempResult,
                    file_exists($tempResult) ? filesize($tempResult) : 0
                ));
                @unlink($tempResult);
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Temp result file created successfully (size: %d bytes)',
                filesize($tempResult)
            ));

            // Replace the original file with the watermarked version
            $copySuccess = false;

            // Check file permissions before copy
            $targetDir = dirname($filePath);
            if (!is_writable($targetDir)) {
                $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                // Try to adjust permissions if possible
                $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                @chmod($targetDir, 0777);

                if (!is_writable($targetDir)) {
                    $logger->err('Watermarker: Unable to make target directory writable');
                }
            }

            // Try file_put_contents first (more reliable in some environments)
            $fileContents = file_get_contents($tempResult);
            if ($fileContents !== false) {
                $bytesWritten = file_put_contents($filePath, $fileContents);
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $logger->info(sprintf(
                        'Watermarker: Successfully wrote %d bytes to file using file_put_contents',
                        $bytesWritten
                    ));
                    $copySuccess = true;
                } else {
                    $logger->err('Watermarker: Failed to write to file using file_put_contents');

                    // Try copy as fallback
                    if (@copy($tempResult, $filePath)) {
                        $logger->info('Watermarker: Successfully copied to file using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: All file write methods failed for file: %s (error: %s)',
                            $filePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }
            } else {
                // Try copy as fallback
                if (@copy($tempResult, $filePath)) {
                    $logger->info('Watermarker: Successfully copied to file using copy()');
                    $copySuccess = true;
                } else {
                    $logger->err(sprintf(
                        'Watermarker: Copy failed for file: %s (error: %s)',
                        $filePath,
                        error_get_last()['message'] ?? 'Unknown error'
                    ));
                }
            }

            // Clean up temp file
            @unlink($tempResult);

            // Verify final file
            if ($copySuccess && file_exists($filePath)) {
                $finalSize = filesize($filePath);
                if ($finalSize < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Final file is too small (%d bytes), likely corrupted',
                        $finalSize
                    ));
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Final file verified successfully (size: %d bytes)',
                    $finalSize
                ));
                $logger->info('Watermarker: Successfully applied watermark to file');
            } else {
                $logger->err('Watermarker: Failed to verify final watermarked file');
            }

        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error in watermarking: %s',
                $e->getMessage()
            ));
            $logger->err(sprintf(
                'Watermarker: Error trace: %s',
                $e->getTraceAsString()
            ));
        }
    }

    /**
     * Get the watermark service
     *
     * @return \Watermarker\Service\WatermarkService
     */
    protected function watermarkService()
    {
        return $this->getServiceLocator()->get('Watermarker\WatermarkService');
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
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('watermarker_enabled', isset($formData['watermark_enabled']));
        $settings->set('watermarker_apply_on_upload', isset($formData['apply_on_upload']));
        $settings->set('watermarker_apply_on_import', isset($formData['apply_on_import']));

        return true;
    }
}