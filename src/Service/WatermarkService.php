<?php
/**
 * Watermark service
 */

namespace Watermarker\Service;

use Omeka\Api\Manager as ApiManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

// Define OMEKA_PATH if not already defined
if (!defined('OMEKA_PATH')) {
    define('OMEKA_PATH', dirname(dirname(dirname(dirname(__DIR__)))));
}

class WatermarkService
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $debugMode = false;

    /**
     * Constructor
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;

        // Initialize required services
        try {
            $this->api = $serviceLocator->get('Omeka\ApiManager');
            $this->connection = $serviceLocator->get('Omeka\Connection');
            $this->logger = $serviceLocator->get('Omeka\Logger');

            // Log successful initialization
            $this->logger->info('WatermarkService: Successfully initialized');
        } catch (\Exception $e) {
            // Service initialization failed - log error if logger is available
            if (isset($this->logger)) {
                $this->logger->err(sprintf(
                    'WatermarkService initialization error: %s',
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Set debug mode
     *
     * @param bool $debugMode
     */
    public function setDebugMode($debugMode = true)
    {
        $this->debugMode = $debugMode;
        $this->logger->info(sprintf(
            'Watermark debug mode set to: %s',
            $debugMode ? 'enabled' : 'disabled'
        ));
    }

    /**
     * Process a media item for watermarking
     *
     * @param MediaRepresentation $media
     * @param bool $isNewUpload Whether this is a newly uploaded media
     * @return bool True if watermark was applied
     */
    public function processMedia(MediaRepresentation $media, $isNewUpload = false)
    {
        // Skip if watermarking is disabled globally
        $settings = $this->serviceLocator->get('Omeka\Settings');
        if (!$settings->get('watermarker_enabled', true)) {
            return false;
        }

        // Skip if not an eligible media type
        if (!$this->isWatermarkable($media)) {
            return false;
        }

        // Check if this media is part of an item with a specific watermark assignment
        $resourceSetId = $this->getWatermarkSetForResource($media);

        // Get the appropriate watermark
        $watermark = $this->getWatermarkForMedia($media, $resourceSetId);
        if (!$watermark) {
            return false;
        }

        // If this is a new upload, we need to wait for derivatives to be generated
        if ($isNewUpload) {
            // Sleep to ensure derivatives are fully generated
            sleep(5);

            // Get media entity to access storage ID (needed to check for derivatives)
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());

            if (!$mediaEntity) {
                return false;
            }

            $storageId = $mediaEntity->getStorageId();
            $extension = $mediaEntity->getExtension();

            // Check if derivatives exist
            $derivativeFound = false;
            $derivativeTypes = ['large', 'medium'];

            foreach ($derivativeTypes as $type) {
                $possiblePaths = [
                    OMEKA_PATH . '/files/' . $type . '/' . $storageId . '.' . $extension,
                    OMEKA_PATH . '/files/' . $type . '/' . $storageId,
                    '/var/www/html/files/' . $type . '/' . $storageId . '.' . $extension,
                    '/var/www/html/files/' . $type . '/' . $storageId,
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $derivativeFound = true;
                        break;
                    }
                }

                if ($derivativeFound) {
                    break;
                }
            }

            if (!$derivativeFound) {
                // Sleep even longer if no derivatives were found
                sleep(5);

                // Try a broader search as a last resort
                foreach ($derivativeTypes as $type) {
                    $found = $this->findDerivativeFiles($type, $storageId);
                    if (!empty($found)) {
                        $derivativeFound = true;
                        break;
                    }
                }
            }
        }

        // Apply watermark directly to derivatives
        return $this->applyWatermarkDirectly($media, $watermark);
    }

    /**
     * Get the watermark set assigned to a specific media or its parent
     *
     * @param MediaRepresentation $media
     * @return int|null ID of the watermark set to use, or null for default
     */
    protected function getWatermarkSetForResource(MediaRepresentation $media)
    {
        $this->logger->info(sprintf(
            'Watermarker: Getting watermark set for %s %s',
            'item',
            $media->id()
        ));

        try {
            // Check for direct assignment
            $stmt = $this->connection->prepare('
                SELECT watermark_set_id, explicitly_no_watermark
                FROM watermark_assignment
                WHERE resource_type = ? AND resource_id = ?
            ');
            $stmt->execute(['item', $media->item()->id()]);
            $result = $stmt->fetch();

            if ($result) {
                if ($result['explicitly_no_watermark']) {
                    $this->logger->info(sprintf(
                        'Watermarker: Found explicit no-watermark setting for item %s',
                        $media->id()
                    ));
                    return false; // Explicitly no watermark
                }
                if ($result['watermark_set_id'] === null) {
                    $this->logger->info(sprintf(
                        'Watermarker: Found default watermark setting for item %s',
                        $media->id()
                    ));
                    return null; // Use default watermark
                }
                $this->logger->info(sprintf(
                    'Watermarker: Found specific watermark set %s for item %s',
                    $result['watermark_set_id'],
                    $media->id()
                ));
                return $result['watermark_set_id'];
            }

            // If no direct assignment, check item sets for items
            if ($media->item()) {
                try {
                    $item = $this->api->read('items', $media->item()->id())->getContent();
                    if (!$item) {
                        $this->logger->warn(sprintf(
                            'Watermarker: Media item %s has no parent item',
                            $media->id()
                        ));
                        return null;
                    }

                    // Check if item belongs to any item sets
                    $itemSets = $item->itemSets();
                    if (empty($itemSets)) {
                        $this->logger->info(sprintf(
                            'Watermarker: Item %s belongs to no item sets, using default watermark',
                            $media->id()
                        ));
                        return null;
                    }

                    // Check each item set for watermark assignments
                    foreach ($itemSets as $itemSet) {
                        $stmt = $this->connection->prepare('
                            SELECT watermark_set_id, explicitly_no_watermark
                            FROM watermark_assignment
                            WHERE resource_type = ? AND resource_id = ?
                        ');
                        $stmt->execute(['item-set', $itemSet->id()]);
                        $result = $stmt->fetch();

                        if ($result) {
                            if ($result['explicitly_no_watermark']) {
                                $this->logger->info(sprintf(
                                    'Watermarker: Found explicit no-watermark setting in item set %s for item %s',
                                    $itemSet->id(),
                                    $media->id()
                                ));
                                return false; // Explicitly no watermark
                            }
                            if ($result['watermark_set_id'] === null) {
                                $this->logger->info(sprintf(
                                    'Watermarker: Found default watermark setting in item set %s for item %s',
                                    $itemSet->id(),
                                    $media->id()
                                ));
                                return null; // Use default watermark
                            }
                            $this->logger->info(sprintf(
                                'Watermarker: Found specific watermark set %s in item set %s for item %s',
                                $result['watermark_set_id'],
                                $itemSet->id(),
                                $media->id()
                            ));
                            return $result['watermark_set_id'];
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->err(sprintf(
                        'Watermarker: Error checking item set watermark assignments for item %s: %s',
                        $media->id(),
                        $e->getMessage()
                    ));
                }
            }

            // No specific assignment found, use default
            $this->logger->info(sprintf(
                'Watermarker: No specific watermark assignment found for item %s, using default',
                $media->id()
            ));
            return null;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Watermarker: Error getting watermark set for item %s: %s',
                $media->id(),
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Check if media is eligible for watermarking
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    public function isWatermarkable(MediaRepresentation $media)
    {
        // Check if the media has an original file
        if (!$media->hasOriginal()) {
            $this->logger->info(sprintf(
                'Media ID %s has no original file',
                $media->id()
            ));
            return false;
        }

        // Check if it's a supported image type
        $mediaType = $media->mediaType();
        $supportedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        $isSupported = in_array($mediaType, $supportedTypes);

        if (!$isSupported) {
            $this->logger->info(sprintf(
                'Media ID %s has unsupported media type: %s',
                $media->id(),
                $mediaType
            ));
        }

        return $isSupported;
    }

    /**
     * Get appropriate watermark for media
     *
     * @param MediaRepresentation $media
     * @param int|null $resourceSetId Optional watermark set ID assigned to this resource or its parent
     * @return array|null Watermark configuration or null if none applies
     */
    public function getWatermarkForMedia(MediaRepresentation $media, $resourceSetId = null)
    {
        // Get image dimensions
        $orientation = $this->getMediaOrientation($media);
        if (!$orientation) {
            $this->logger->info('Could not determine media orientation, skipping watermark');
            return null;
        }

        // If this is a square image but no 'square' type exists, default to the orientation
        // being either landscape (width >= height) or portrait (width < height)
        $imageType = $orientation;
        if ($orientation === 'square') {
            $imageInfo = $this->getImageInfo($media);
            if ($imageInfo) {
                $squareTypeExists = $this->checkIfWatermarkTypeExists('square');
                if (!$squareTypeExists) {
                    $imageType = ($imageInfo['width'] >= $imageInfo['height']) ? 'landscape' : 'portrait';
                    $this->logger->info(sprintf(
                        'Square image but no square watermark type exists, treating as %s',
                        $imageType
                    ));
                }
            }
        }

        // Check if there's a specific watermark set assigned to this item or its parent item set
        if ($resourceSetId) {
            $this->logger->info(sprintf('Checking for resource-specific watermark set ID: %d', $resourceSetId));
            $watermark = $this->getWatermarkFromSet($resourceSetId, $imageType);
            if ($watermark) {
                return $watermark;
            }
            // If no matching watermark in resource-specific set, fall back to default set
        }

        // If no resource-specific watermark, use the default set
        $this->logger->info('Falling back to default watermark set');
        return $this->getDefaultWatermark($imageType);
    }

    /**
     * Get image info including dimensions for a media item
     *
     * @param MediaRepresentation $media
     * @return array|null Array with width, height, or null if dimensions can't be determined
     */
    protected function getImageInfo(MediaRepresentation $media)
    {
        // Get the file path
        $filePath = $this->getLocalFilePath($media);
        if (!$filePath) {
            return null;
        }

        // Get image dimensions
        $imageSize = @getimagesize($filePath);
        if (!$imageSize) {
            return null;
        }

        return [
            'width' => $imageSize[0],
            'height' => $imageSize[1],
            'type' => image_type_to_mime_type($imageSize[2])
        ];
    }

    /**
     * Check if a specific watermark type exists in the database
     *
     * @param string $type The watermark type to check for (landscape, portrait, square, all)
     * @return bool True if this type exists in any set, false otherwise
     */
    protected function checkIfWatermarkTypeExists($type)
    {
        $sql = "SELECT COUNT(*) FROM watermark_setting WHERE type = :type";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('type', $type);
        $stmt->execute();

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get watermark from a specific set for a given image type
     *
     * @param int $setId The watermark set ID
     * @param string $imageType The image type (landscape, portrait, square, all)
     * @return array|null Watermark configuration or null if none applies
     */
    protected function getWatermarkFromSet($setId, $imageType)
    {
        // Check if set exists and is enabled
        $sql = "SELECT * FROM watermark_set WHERE id = :id AND enabled = 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('id', $setId);
        $stmt->execute();

        $set = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$set) {
            $this->logger->info(sprintf('Watermark set ID %d does not exist or is disabled', $setId));
            return null;
        }

        // Try to get the specific watermark for this image type
        $sql = "SELECT w.* FROM watermark_setting w
                WHERE w.set_id = :set_id AND (w.type = :type OR w.type = 'all')
                ORDER BY CASE WHEN w.type = :type THEN 0 ELSE 1 END
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('set_id', $setId);
        $stmt->bindValue('type', $imageType);
        $stmt->execute();

        $watermark = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$watermark) {
            $this->logger->info(sprintf(
                'No watermark found in set %d for image type: %s',
                $setId,
                $imageType
            ));
            return null;
        }

        // Verify the asset exists
        try {
            $assetId = $watermark['media_id'];
            $api = $this->serviceLocator->get('Omeka\ApiManager');
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            $this->logger->info(sprintf(
                'Found watermark configuration with asset ID %s for image type %s in set %d',
                $assetId,
                $imageType,
                $setId
            ));

            return $watermark;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Could not load watermark asset %s: %s',
                $watermark['media_id'],
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get the default watermark for a given image type
     *
     * @param string $imageType The image type (landscape, portrait, square, all)
     * @return array|null Watermark configuration or null if none applies
     */
    protected function getDefaultWatermark($imageType)
    {
        // Find the default watermark set
        $sql = "SELECT id FROM watermark_set WHERE is_default = 1 AND enabled = 1 LIMIT 1";
        $defaultSetId = $this->connection->fetchColumn($sql);

        if (!$defaultSetId) {
            $this->logger->info('No default watermark set found, looking for any enabled set');
            // If no default set, try any enabled set
            $sql = "SELECT id FROM watermark_set WHERE enabled = 1 LIMIT 1";
            $defaultSetId = $this->connection->fetchColumn($sql);

            if (!$defaultSetId) {
                $this->logger->info('No enabled watermark sets found');
                return null;
            }
        }

        $this->logger->info(sprintf('Using default watermark set ID: %d', $defaultSetId));
        return $this->getWatermarkFromSet($defaultSetId, $imageType);
    }

    /**
     * Get media orientation
     *
     * @param MediaRepresentation $media
     * @return string|null 'landscape', 'portrait', 'square', or null on error
     */
    protected function getMediaOrientation(MediaRepresentation $media)
    {
        $this->logger->info(sprintf(
            'Getting orientation for media ID %s',
            $media->id()
        ));

        // Get file path directly using the File\Store service
        $store = $this->serviceLocator->get('Omeka\File\Store');
        $storagePath = $this->getLocalFilePath($media);

        if (!$storagePath) {
            $this->logger->err(sprintf(
                'Could not get local file path for media ID %s',
                $media->id()
            ));
            return null;
        }

        $this->logger->info(sprintf(
            'Using local file path: %s',
            $storagePath
        ));

        // Get image dimensions
        $imageSize = @getimagesize($storagePath);
        $this->logger->info(sprintf(
            'Image dimensions for media ID %s: %s',
            $media->id(),
            $imageSize ? "{$imageSize[0]}x{$imageSize[1]}" : "unknown"
        ));

        if (!$imageSize) {
            $this->logger->err(sprintf(
                'Failed to get image dimensions for media ID %s',
                $media->id()
            ));
            return null;
        }

        $width = $imageSize[0];
        $height = $imageSize[1];

        // Consider an image 'square' if width and height are within 5% of each other
        $tolerance = 0.05; // 5% tolerance
        $aspectRatio = $width / $height;

        if (abs($aspectRatio - 1) <= $tolerance) {
            $orientation = 'square';
        } else {
            $orientation = ($width >= $height) ? 'landscape' : 'portrait';
        }

        $this->logger->info(sprintf(
            'Media ID %s orientation: %s (%dx%d, ratio: %.2f)',
            $media->id(),
            $orientation,
            $width,
            $height,
            $aspectRatio
        ));

        return $orientation;
    }

    /**
     * Get local file path for a media
     *
     * @param MediaRepresentation $media
     * @return string|null Local file path or null if not found
     */
    protected function getLocalFilePath(MediaRepresentation $media)
    {
        try {
            // Get the media entity to access storage ID
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());

            if (!$mediaEntity) {
                $this->logger->err(sprintf('Media entity with ID %s not found', $media->id()));
                return null;
            }

            $storageId = $mediaEntity->getStorageId();
            $extension = $mediaEntity->getExtension();
            $this->logger->info(sprintf('Media storage ID: %s, extension: %s', $storageId, $extension));

            // Get base files directory from Omeka settings
            $basePath = OMEKA_PATH . '/files';

            // Construct path to original file (with extension)
            $filePath = $basePath . '/original/' . $storageId;

            // Try with the extension if available
            if ($extension) {
                $filePathWithExt = $filePath . '.' . $extension;
                if (file_exists($filePathWithExt)) {
                    $this->logger->info(sprintf('Found file at path: %s', $filePathWithExt));
                    return $filePathWithExt;
                }
            }

            // Try without extension as fallback
            if (file_exists($filePath)) {
                $this->logger->info(sprintf('Found file at path (no extension): %s', $filePath));
                return $filePath;
            }

            // If we still don't have the file, try searching by pattern
            $pattern = $filePath . '.*';
            $matches = glob($pattern);
            if (!empty($matches)) {
                $foundPath = $matches[0];
                $this->logger->info(sprintf('Found file using pattern search: %s', $foundPath));
                return $foundPath;
            }

            $this->logger->err(sprintf('File does not exist at any of the tried paths (with or without extension)', $filePath));
            return null;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error getting local file path: %s',
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Apply watermark directly to derivatives without using the job system
     *
     * This is the core method that actually applies the watermark to derivative images.
     * It logs extensively to help diagnose issues with watermark application.
     *
     * @param MediaRepresentation $media The media object to watermark
     * @param array $watermarkConfig Configuration for the watermark to apply
     * @return bool True if watermark was successfully applied, false otherwise
     */
    public function applyWatermarkDirectly(MediaRepresentation $media, array $watermarkConfig)
    {
        try {
            $this->logger->info(sprintf(
                'Watermarker: Starting watermark application for media ID: %s (type: %s)',
                $media->id(),
                $media->mediaType()
            ));

            // Get watermark asset
            $assetId = $watermarkConfig['media_id'];
            $api = $this->serviceLocator->get('Omeka\ApiManager');
            $watermarkAsset = $api->read('assets', $assetId)->getContent();
            $this->logger->info('Watermarker: Successfully loaded watermark asset');

            // Get the temp directory
            $tempDir = $this->serviceLocator->get('Config')['file_manager']['temp_dir'] ?? sys_get_temp_dir();

            // Find the local path to the asset using Omeka services
            $store = $this->serviceLocator->get('Omeka\File\Store');
            $assetUrl = $watermarkAsset->assetUrl();
            $assetFilename = basename($assetUrl);

            // Get the watermark from the asset store
            $assetPath = null;
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
            ];

            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    $this->logger->info(sprintf('Watermarker: Found watermark asset at path: %s', $path));
                    break;
                }
            }

            if (!$assetPath) {
                $this->logger->err('Watermarker: Could not find watermark asset file on disk');
                return false;
            }

            // Get media entity information
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());

            if (!$mediaEntity) {
                $this->logger->err('Watermarker: Could not load media entity');
                return false;
            }

            $storageId = $mediaEntity->getStorageId();
            $mediaType = $mediaEntity->getMediaType();

            // Log media info
            $this->logger->info(sprintf(
                'Watermarker: Media details - ID: %s, Type: %s, Storage ID: %s',
                $media->id(), $mediaType, $storageId
            ));

            // Get the derivative URLs from media representation
            $thumbnailUrls = $media->thumbnailUrls();
            // Only watermark the large derivative
            $derivativeTypes = ['large'];
            $success = false;

            // Process each derivative type
            foreach ($derivativeTypes as $type) {
                $this->logger->info(sprintf('Watermarker: Processing %s derivative', $type));

                // Make sure we have a thumbnail of this type
                if (!isset($thumbnailUrls[$type])) {
                    $this->logger->err(sprintf('Watermarker: No %s derivative URL available', $type));
                    continue;
                }

                $derivativeUrl = $thumbnailUrls[$type];
                $this->logger->info(sprintf('Watermarker: Derivative URL: %s', $derivativeUrl));

                // Extract filename from URL
                $derivativeFilename = basename(parse_url($derivativeUrl, PHP_URL_PATH));
                $this->logger->info(sprintf('Watermarker: Derivative filename: %s', $derivativeFilename));

                // Find derivative file on disk
                $derivativePath = null;
                $possiblePaths = [
                    OMEKA_PATH . '/files/' . $type . '/' . $storageId,
                    OMEKA_PATH . '/files/' . $type . '/' . $derivativeFilename,
                    '/var/www/html/files/' . $type . '/' . $storageId,
                    '/var/www/html/files/' . $type . '/' . $derivativeFilename,
                ];

                // Add extensions for good measure
                foreach (['.jpg', '.jpeg', '.png', '.webp', ''] as $ext) {
                    if (!in_array(OMEKA_PATH . '/files/' . $type . '/' . $storageId . $ext, $possiblePaths)) {
                        $possiblePaths[] = OMEKA_PATH . '/files/' . $type . '/' . $storageId . $ext;
                        $possiblePaths[] = '/var/www/html/files/' . $type . '/' . $storageId . $ext;
                    }
                }

                // Look for the derivative file
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $derivativePath = $path;
                        $this->logger->info(sprintf('Watermarker: Found %s derivative at: %s', $type, $path));
                        break;
                    }
                }

                // If still not found, try glob
                if (!$derivativePath) {
                    $globPattern = OMEKA_PATH . '/files/' . $type . '/' . $storageId . '*';
                    $this->logger->info(sprintf('Watermarker: Trying glob pattern: %s', $globPattern));
                    $matches = glob($globPattern);

                    if (!empty($matches)) {
                        $derivativePath = $matches[0];
                        $this->logger->info(sprintf('Watermarker: Found via glob: %s', $derivativePath));
                    } else {
                        // Try alternate location
                        $globPattern = '/var/www/html/files/' . $type . '/' . $storageId . '*';
                        $this->logger->info(sprintf('Watermarker: Trying alternate glob pattern: %s', $globPattern));
                        $matches = glob($globPattern);

                        if (!empty($matches)) {
                            $derivativePath = $matches[0];
                            $this->logger->info(sprintf('Watermarker: Found via alternate glob: %s', $derivativePath));
                        }
                    }
                }

                // If still not found, check similar files
                if (!$derivativePath) {
                    $this->logger->err(sprintf('Watermarker: Could not find %s derivative file', $type));

                    // List directory contents to help debug
                    $dirToCheck = '/var/www/html/files/' . $type . '/';
                    if (is_dir($dirToCheck)) {
                        $files = scandir($dirToCheck);
                        $this->logger->info(sprintf('Watermarker: Directory contains %d files', count($files) - 2));

                        $prefix = substr($storageId, 0, 8);
                        $relevantFiles = array_filter($files, function($file) use ($prefix) {
                            return strpos($file, $prefix) === 0;
                        });

                        if (!empty($relevantFiles)) {
                            $this->logger->info(sprintf('Watermarker: Found similar files: %s', implode(', ', $relevantFiles)));
                            // Use the first matching file
                            $derivativePath = $dirToCheck . reset($relevantFiles);
                            $this->logger->info(sprintf('Watermarker: Using similar file: %s', $derivativePath));
                        }
                    }
                }

                // Skip if we still can't find the derivative
                if (!$derivativePath || !file_exists($derivativePath)) {
                    $this->logger->err(sprintf('Watermarker: Unable to locate %s derivative, skipping', $type));
                    continue;
                }

                // Get the file type based on actual file
                $fileInfo = @getimagesize($derivativePath);
                if (!$fileInfo) {
                    $this->logger->err(sprintf('Watermarker: Failed to get image info for %s', $derivativePath));
                    continue;
                }

                $derivativeType = image_type_to_mime_type($fileInfo[2]);
                $derivativeWidth = $fileInfo[0];
                $derivativeHeight = $fileInfo[1];

                $this->logger->info(sprintf(
                    'Watermarker: Derivative is type: %s, dimensions: %dx%d (original type was: %s)',
                    $derivativeType, $derivativeWidth, $derivativeHeight, $mediaType
                ));

                // Note format conversion if it happened
                if ($mediaType !== $derivativeType) {
                    $this->logger->info(sprintf(
                        'Watermarker: Format conversion detected: original %s → derivative %s',
                        $mediaType, $derivativeType
                    ));
                }

                // Create a temp copy of the derivative for processing
                $tempDerivative = tempnam($tempDir, 'watermark_');
                // Add appropriate extension to help GD
                $derivativeExt = $this->getExtensionForMimeType($derivativeType);
                $tempDerivativeWithExt = $tempDerivative . '.' . $derivativeExt;
                rename($tempDerivative, $tempDerivativeWithExt);
                $tempDerivative = $tempDerivativeWithExt;

                if (!copy($derivativePath, $tempDerivative)) {
                    $this->logger->err(sprintf('Watermarker: Failed to copy derivative to temp file'));
                    @unlink($tempDerivative);
                    continue;
                }

                // Create image resources - use the actual derivative type, not the original media type
                $mediaImage = $this->createImageResource($tempDerivative, $derivativeType);
                if (!$mediaImage) {
                    $this->logger->err(sprintf(
                        'Watermarker: Failed to create image resource from derivative (type: %s, path: %s)',
                        $derivativeType, $tempDerivative
                    ));
                    @unlink($tempDerivative);
                    continue;
                }

                // Load the watermark image
                $watermarkImage = $this->createImageResource($assetPath, 'image/png');
                if (!$watermarkImage) {
                    $this->logger->err('Watermarker: Failed to create image resource from watermark');
                    imagedestroy($mediaImage);
                    @unlink($tempDerivative);
                    continue;
                }

                // Apply the watermark
                $this->logger->info(sprintf(
                    'Watermarker: Applying watermark to %s derivative (position: %s, opacity: %.2f)',
                    $type, $watermarkConfig['position'], (float)$watermarkConfig['opacity']
                ));

                $this->overlayWatermark(
                    $mediaImage,
                    $watermarkImage,
                    $watermarkConfig['position'],
                    (float)$watermarkConfig['opacity']
                );

                // Save the watermarked image in the derivative's actual format
                $tempResult = tempnam($tempDir, 'result_');

                // Make sure we match the original file's extension if it has one
                $originalExt = strtolower(pathinfo($derivativePath, PATHINFO_EXTENSION));
                if (!empty($originalExt)) {
                    $this->logger->info(sprintf('Watermarker: Using original file extension for temp file: %s', $originalExt));
                    $derivativeExt = $originalExt;
                }

                // Add appropriate extension
                $tempResultWithExt = $tempResult . '.' . $derivativeExt;
                rename($tempResult, $tempResultWithExt);
                $tempResult = $tempResultWithExt;

                // Save using the derivative's actual type
                $saveSuccess = $this->saveImageResource($mediaImage, $tempResult, $derivativeType);

                // Clean up resources
                imagedestroy($mediaImage);
                imagedestroy($watermarkImage);
                @unlink($tempDerivative);

                if (!$saveSuccess) {
                    $this->logger->err('Watermarker: Failed to save watermarked image');
                    @unlink($tempResult);
                    continue;
                }

                // Verify temp file has content
                if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                    $this->logger->err(sprintf(
                        'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                        $tempResult,
                        file_exists($tempResult) ? filesize($tempResult) : 0
                    ));
                    @unlink($tempResult);
                    continue;
                }

                $this->logger->info(sprintf(
                    'Watermarker: Temp result file (%s) created successfully (size: %d bytes)',
                    $tempResult,
                    filesize($tempResult)
                ));

                // Ensure original file and temp file have compatible formats
                $originalFormat = strtolower(pathinfo($derivativePath, PATHINFO_EXTENSION));
                $tempFormat = strtolower(pathinfo($tempResult, PATHINFO_EXTENSION));

                $this->logger->info(sprintf(
                    'Watermarker: Format check - original: %s, temp: %s, path: %s',
                    $originalFormat ? $originalFormat : 'none',
                    $tempFormat ? $tempFormat : 'none',
                    $derivativePath
                ));

                // If original has no extension but should have one based on detected type
                if (empty($originalFormat)) {
                    // Check if we can determine the format from the file
                    $fileInfo = @getimagesize($derivativePath);
                    if ($fileInfo && isset($fileInfo[2])) {
                        $detectedType = image_type_to_mime_type($fileInfo[2]);
                        $detectedExt = $this->getExtensionForMimeType($detectedType);
                        $this->logger->info(sprintf(
                            'Watermarker: Original file has no extension but detected as: %s (.%s)',
                            $detectedType, $detectedExt
                        ));

                        // Use the correct extension for the output file
                        if ($tempFormat != $detectedExt) {
                            $newTempFile = $tempResult . '.' . $detectedExt;
                            rename($tempResult, $newTempFile);
                            $tempResult = $newTempFile;
                            $tempFormat = $detectedExt;
                            $this->logger->info(sprintf(
                                'Watermarker: Renamed temp file to match correct format: %s',
                                $tempResult
                            ));
                        }
                    }
                } else if ($tempFormat && $originalFormat != $tempFormat) {
                    // If formats don't match, rename temp file to match original
                    $newTempFile = preg_replace('/\.' . preg_quote($tempFormat) . '$/', '.' . $originalFormat, $tempResult);
                    if ($newTempFile != $tempResult) {
                        rename($tempResult, $newTempFile);
                        $tempResult = $newTempFile;
                        $this->logger->info(sprintf(
                            'Watermarker: Renamed temp file to match original format: %s -> %s',
                            $tempFormat, $originalFormat
                        ));
                    }
                }

                // Replace the original derivative with the watermarked version
                $copySuccess = false;

                // Check file permissions before copy
                $targetDir = dirname($derivativePath);
                if (!is_writable($targetDir)) {
                    $this->logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                    // Try to adjust permissions if possible
                    $this->logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                    @chmod($targetDir, 0777);

                    if (!is_writable($targetDir)) {
                        $this->logger->err('Watermarker: Unable to make target directory writable');
                    }
                }

                // Get original file permissions
                $originalPerms = null;
                if (file_exists($derivativePath)) {
                    $originalPerms = fileperms($derivativePath);
                    // Check if file is writable
                    if (!is_writable($derivativePath)) {
                        $this->logger->info(sprintf(
                            'Watermarker: Original file is not writable, attempting chmod: %s',
                            $derivativePath
                        ));
                        @chmod($derivativePath, 0666);
                    }
                }

                // Try file_put_contents first (more reliable in some environments)
                $fileContents = file_get_contents($tempResult);
                if ($fileContents !== false) {
                    $bytesWritten = file_put_contents($derivativePath, $fileContents);
                    if ($bytesWritten !== false && $bytesWritten > 0) {
                        $this->logger->info(sprintf(
                            'Watermarker: Successfully wrote %d bytes to derivative using file_put_contents',
                            $bytesWritten
                        ));
                        $copySuccess = true;
                    } else {
                        $this->logger->err('Watermarker: Failed to write to derivative using file_put_contents');

                        // Try copy as fallback
                        if (@copy($tempResult, $derivativePath)) {
                            $this->logger->info('Watermarker: Successfully copied to derivative using copy()');
                            $copySuccess = true;
                        } else {
                            $this->logger->err(sprintf(
                                'Watermarker: All file write methods failed for derivative: %s (error: %s)',
                                $derivativePath,
                                error_get_last()['message'] ?? 'Unknown error'
                            ));
                        }
                    }
                } else {
                    $this->logger->err('Watermarker: Failed to read temp file contents');

                    // Try copy as fallback
                    if (@copy($tempResult, $derivativePath)) {
                        $this->logger->info('Watermarker: Successfully copied to derivative using copy()');
                        $copySuccess = true;
                    } else {
                        $this->logger->err(sprintf(
                            'Watermarker: Copy failed for derivative: %s (error: %s)',
                            $derivativePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }

                // Restore original permissions if we had them
                if ($copySuccess && $originalPerms !== null) {
                    @chmod($derivativePath, $originalPerms);
                }

                // Clean up temp file
                @unlink($tempResult);

                // Verify final file
                if ($copySuccess && file_exists($derivativePath)) {
                    $finalSize = filesize($derivativePath);
                    if ($finalSize < 100) {
                        $this->logger->err(sprintf(
                            'Watermarker: Final derivative is too small (%d bytes), likely corrupted',
                            $finalSize
                        ));
                        continue;
                    }

                    $this->logger->info(sprintf(
                        'Watermarker: Final derivative verified successfully (size: %d bytes)',
                        $finalSize
                    ));
                    $this->logger->info(sprintf('Watermarker: Successfully watermarked %s derivative', $type));
                    $success = true;
                } else {
                    $this->logger->err('Watermarker: Failed to verify final watermarked derivative');
                    continue;
                }
                $success = true;
            }

            if ($success) {
                $this->logger->info(sprintf('Watermarker: Successfully watermarked media ID: %s', $media->id()));
            } else {
                $this->logger->err(sprintf('Watermarker: Failed to watermark any derivatives for media ID: %s', $media->id()));
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Watermarker: Exception while applying watermark: %s',
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Helper method to get file extension from MIME type
     *
     * @param string $mimeType The MIME type
     * @return string The file extension without leading dot
     */
    public function getExtensionForMimeType($mimeType)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
        ];

        return isset($map[$mimeType]) ? $map[$mimeType] : 'jpg';
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated Use applyWatermarkDirectly instead
     */
    protected function applyWatermarkToDerivatives(MediaRepresentation $media, array $watermarkConfig)
    {
        return $this->applyWatermarkDirectly($media, $watermarkConfig);
    }

    /**
     * Find derivative files using a broader search
     *
     * @param string $type The derivative type (large, medium, etc.)
     * @param string $storageId The storage ID of the media
     * @return array Array of found file paths
     */
    protected function findDerivativeFiles($type, $storageId)
    {
        $found = [];

        // Search in common locations
        $searchLocations = [
            OMEKA_PATH . '/files/' . $type,
            '/var/www/html/files/' . $type
        ];

        foreach ($searchLocations as $dir) {
            if (!is_dir($dir)) {
                $this->logger->info(sprintf('Directory %s does not exist', $dir));
                continue;
            }

            // Use glob to find files that match the storage ID pattern
            $pattern = $dir . '/' . $storageId . '*';
            $matches = glob($pattern);

            if (!empty($matches)) {
                $this->logger->info(sprintf('Found %d matches in %s with pattern %s', count($matches), $dir, $pattern));
                $found = array_merge($found, $matches);
            } else {
                $this->logger->info(sprintf('No matches in %s with pattern %s', $dir, $pattern));
            }
        }

        return $found;
    }

    /**
     * Download a file to a temporary location
     *
     * @param string $url
     * @return string|false Path to temp file or false on failure
     */
    protected function downloadFile($url)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'omeka_watermarker_');
        $this->logger->info(sprintf('Downloading file from: %s to %s', $url, $tempFile));

        // Handle local files
        if (strpos($url, '/') === 0) {
            $this->logger->info('Handling as local file');
            if (!@copy($url, $tempFile)) {
                $this->logger->err(sprintf('Failed to copy local file %s', $url));
                @unlink($tempFile);
                return false;
            }
            $this->logger->info('Local file copied successfully');
            return $tempFile;
        }

        // Handle remote files
        $this->logger->info('Handling as remote file');
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Omeka S Watermarker Module'
            ]
        ]);

        $fileContents = @file_get_contents($url, false, $context);
        if ($fileContents === false) {
            $this->logger->err(sprintf('Failed to download remote file %s', $url));
            @unlink($tempFile);
            return false;
        }

        $bytesWritten = @file_put_contents($tempFile, $fileContents);
        if ($bytesWritten === false) {
            $this->logger->err(sprintf('Failed to save downloaded file %s', $url));
            @unlink($tempFile);
            return false;
        }

        $this->logger->info(sprintf('Successfully downloaded %d bytes', $bytesWritten));
        return $tempFile;
    }

    /**
     * Create GD image resource from file
     *
     * @param string $file
     * @param string $mediaType
     * @return resource|false
     */
    public function createImageResource($file, $mediaType)
    {
        $this->logger->info(sprintf('Watermarker: Creating image resource from file type: %s (path: %s)', $mediaType, $file));

        // Check if file is readable
        if (!is_readable($file)) {
            $this->logger->err(sprintf('Watermarker: File is not readable: %s', $file));
            return false;
        }

        // Get information about the image
        $imageInfo = @getimagesize($file);
        if ($imageInfo) {
            $this->logger->info(sprintf('Watermarker: Image size: %dx%d, detected type: %s',
                $imageInfo[0], $imageInfo[1], image_type_to_mime_type($imageInfo[2])));

            // If detected type is different from provided mediaType, use detected type
            if ($imageInfo[2] !== false && image_type_to_mime_type($imageInfo[2]) !== $mediaType) {
                $this->logger->info(sprintf('Watermarker: Detected media type (%s) differs from provided type (%s), using detected type',
                    image_type_to_mime_type($imageInfo[2]), $mediaType));
                $mediaType = image_type_to_mime_type($imageInfo[2]);
            }
        } else {
            $this->logger->err(sprintf('Watermarker: Failed to get image information for: %s', $file));
        }

        // Check file extension if mediaType seems wrong
        $fileExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($fileExt == 'jpg' && $mediaType == 'image/png') {
            $this->logger->info('Watermarker: File extension is JPG but media type is PNG, treating as JPEG');
            $mediaType = 'image/jpeg';
        } else if ($fileExt == 'png' && $mediaType == 'image/jpeg') {
            $this->logger->info('Watermarker: File extension is PNG but media type is JPEG, treating as PNG');
            $mediaType = 'image/png';
        }

        switch ($mediaType) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($file);
                if (!$img) {
                    $this->logger->err('Watermarker: Failed to create JPEG image resource');

                    // Try alternate method as fallback
                    $this->logger->info('Watermarker: Trying alternate method to load JPEG');
                    $img = @imagecreatefromstring(file_get_contents($file));
                    if ($img) {
                        $this->logger->info('Watermarker: Successfully created image from string data');
                    }
                } else {
                    $this->logger->info('Watermarker: Successfully created JPEG image resource');
                }
                return $img;

            case 'image/png':
                $img = @imagecreatefrompng($file);
                if (!$img) {
                    $this->logger->err('Watermarker: Failed to create PNG image resource');

                    // Try alternate method as fallback
                    $this->logger->info('Watermarker: Trying alternate method to load PNG');
                    $img = @imagecreatefromstring(file_get_contents($file));
                    if ($img) {
                        $this->logger->info('Watermarker: Successfully created image from string data');
                    }
                }

                if ($img) {
                    // Preserve transparency for PNG images
                    imagealphablending($img, true);
                    imagesavealpha($img, true);
                    $this->logger->info('Watermarker: Successfully created PNG image resource with alpha channel preserved');
                }
                return $img;

            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $img = @imagecreatefromwebp($file);
                    if (!$img) {
                        $this->logger->err('Watermarker: Failed to create WebP image resource');

                        // Try alternate method as fallback
                        $this->logger->info('Watermarker: Trying alternate method to load WebP');
                        $img = @imagecreatefromstring(file_get_contents($file));
                        if ($img) {
                            $this->logger->info('Watermarker: Successfully created image from string data');
                        }
                    } else {
                        $this->logger->info('Watermarker: Successfully created WebP image resource');
                    }
                    return $img;
                } else {
                    $this->logger->err('Watermarker: WebP support not available in PHP');
                }
                break;

            default:
                // Try generic approach for unsupported types
                $this->logger->info(sprintf('Watermarker: Trying generic approach for unsupported media type: %s', $mediaType));
                $img = @imagecreatefromstring(file_get_contents($file));
                if ($img) {
                    $this->logger->info('Watermarker: Successfully created image from string data');
                    return $img;
                }

                $this->logger->err(sprintf('Watermarker: Unsupported media type: %s', $mediaType));
                break;
        }

        return false;
    }

    /**
     * Save image resource to file
     *
     * @param resource $image
     * @param string $file
     * @param string $mediaType
     * @return bool Success
     */
    public function saveImageResource($image, $file, $mediaType)
    {
        $this->logger->info(sprintf('Watermarker: Saving image resource as type: %s to %s', $mediaType, $file));

        // Check if file extension matches media type
        $fileExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $detectedMimeType = $mediaType;

        // Try to detect the actual type of the image resource
        $actualType = null;
        if (function_exists('imageistruecolor') && imageistruecolor($image)) {
            // For PNG with transparency, we need to preserve that
            $hasAlpha = false;
            for ($x = 0; $x < imagesx($image) && !$hasAlpha; $x++) {
                for ($y = 0; $y < imagesy($image) && !$hasAlpha; $y++) {
                    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    if ($color['alpha'] > 0) {
                        $hasAlpha = true;
                    }
                }
            }

            if ($hasAlpha) {
                $this->logger->info('Watermarker: Image resource has alpha channel, treating as PNG');
                $actualType = 'image/png';
            }
        }

        if (empty($fileExt)) {
            // If no extension, add one based on media type (preferring the detected type)
            $typeToUse = $actualType ?: $mediaType;
            switch ($typeToUse) {
                case 'image/jpeg':
                    $file .= '.jpg';
                    $detectedMimeType = 'image/jpeg';
                    break;
                case 'image/png':
                    $file .= '.png';
                    $detectedMimeType = 'image/png';
                    break;
                case 'image/webp':
                    $file .= '.webp';
                    $detectedMimeType = 'image/webp';
                    break;
            }
            $this->logger->info(sprintf('Watermarker: Added extension to file path: %s', $file));
        } else {
            // If extension doesn't match media type, decide what format to use
            $extMimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif'
            ];

            $expectedMimeType = isset($extMimeMap[$fileExt]) ? $extMimeMap[$fileExt] : null;

            if ($expectedMimeType && $expectedMimeType != $mediaType) {
                if ($actualType && $actualType == $expectedMimeType) {
                    // If detected type matches file extension, use that
                    $this->logger->info(sprintf(
                        'Watermarker: Using detected type %s to match file extension %s instead of provided type %s',
                        $actualType, $fileExt, $mediaType
                    ));
                    $detectedMimeType = $actualType;
                } else {
                    // Otherwise, prefer the extension's implied type
                    $this->logger->info(sprintf(
                        'Watermarker: File has %s extension but media type is %s, saving as %s',
                        $fileExt, $mediaType, $expectedMimeType
                    ));
                    $detectedMimeType = $expectedMimeType;
                }
            }
        }

        // Check image resource validity
        if (!is_resource($image) && !($image instanceof \GdImage)) {
            $this->logger->err('Watermarker: Invalid image resource provided');
            return false;
        }

        // Ensure directory exists
        $dir = dirname($file);
        if (!is_dir($dir)) {
            $this->logger->err(sprintf('Watermarker: Directory %s does not exist', $dir));
            return false;
        }

        if (!is_writable($dir)) {
            $this->logger->err(sprintf('Watermarker: Directory %s is not writable', $dir));
            // Try to create a temp file in the system temp directory first, with proper extension
            $tempFile = tempnam(sys_get_temp_dir(), 'wm_');

            // Add the file extension based on the detected MIME type
            $fileExt = $this->getExtensionForMimeType($detectedMimeType);
            if (!empty($fileExt)) {
                $tempFileWithExt = $tempFile . '.' . $fileExt;
                rename($tempFile, $tempFileWithExt);
                $tempFile = $tempFileWithExt;
                $this->logger->info(sprintf('Watermarker: Added extension to temp file: %s', $fileExt));
            }

            $this->logger->info(sprintf('Watermarker: Using temporary file %s instead', $tempFile));
            $file = $tempFile;
        }

        // Use a two-step process for more reliable file writing
        // 1. First save to a temporary file
        $tempDir = sys_get_temp_dir();
        $tempOutput = tempnam($tempDir, 'wm_output_');

        // Add the file extension based on the detected MIME type
        $fileExt = $this->getExtensionForMimeType($detectedMimeType);
        if (!empty($fileExt)) {
            $tempOutputWithExt = $tempOutput . '.' . $fileExt;
            rename($tempOutput, $tempOutputWithExt);
            $tempOutput = $tempOutputWithExt;
            $this->logger->info(sprintf('Watermarker: Added extension to temp output file: %s (%s)', $fileExt, $tempOutput));
        }

        // Save based on the detected media type
        $this->logger->info(sprintf('Watermarker: Using detected MIME type: %s', $detectedMimeType));
        $savedToTemp = false;

        switch ($detectedMimeType) {
            case 'image/jpeg':
                // Check if this is a PNG with transparency being saved as JPG
                $hasAlpha = false;
                if (imageistruecolor($image)) {
                    // Check if the image has alpha channel
                    for ($x = 0; $x < imagesx($image); $x++) {
                        for ($y = 0; $y < imagesy($image); $y++) {
                            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                            if ($color['alpha'] > 0) {
                                $hasAlpha = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($hasAlpha) {
                    $this->logger->info('Watermarker: Converting from transparent PNG to JPEG');
                    // Create a white background for the JPEG
                    $jpgImage = imagecreatetruecolor(imagesx($image), imagesy($image));
                    $white = imagecolorallocate($jpgImage, 255, 255, 255);
                    imagefill($jpgImage, 0, 0, $white);

                    // Copy the PNG onto the white background
                    imagecopy($jpgImage, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

                    // Save as JPEG
                    $result = @imagejpeg($jpgImage, $tempOutput, 95);
                    imagedestroy($jpgImage);
                } else {
                    $result = @imagejpeg($image, $tempOutput, 95);
                }

                if (!$result) {
                    $this->logger->err('Watermarker: Failed to save JPEG image to temp file');
                } else {
                    $this->logger->info('Watermarker: Successfully saved JPEG image to temp file');
                    $savedToTemp = true;
                }
                break;

            case 'image/png':
                // Make sure alpha blending and saving is properly set up
                if (imageistruecolor($image)) {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                }

                $result = @imagepng($image, $tempOutput, 9);
                if (!$result) {
                    $this->logger->err('Watermarker: Failed to save PNG image to temp file');

                    // Try creating a new PNG image as fallback
                    $this->logger->info('Watermarker: Trying fallback method to save PNG');
                    $newImg = imagecreatetruecolor(imagesx($image), imagesy($image));
                    imagealphablending($newImg, false);
                    imagesavealpha($newImg, true);
                    $transparent = imagecolorallocatealpha($newImg, 0, 0, 0, 127);
                    imagefilledrectangle($newImg, 0, 0, imagesx($image), imagesy($image), $transparent);
                    imagecopy($newImg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

                    $result = @imagepng($newImg, $tempOutput, 9);
                    imagedestroy($newImg);

                    if ($result) {
                        $this->logger->info('Watermarker: Successfully saved PNG image to temp file using fallback method');
                        $savedToTemp = true;
                    } else {
                        $this->logger->err('Watermarker: Fallback method also failed to save PNG image to temp file');
                    }
                } else {
                    $this->logger->info('Watermarker: Successfully saved PNG image to temp file with transparency preserved');
                    $savedToTemp = true;
                }
                break;

            case 'image/webp':
                if (function_exists('imagewebp')) {
                    // Set up for transparency if needed
                    if (imageistruecolor($image)) {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                    }

                    $result = @imagewebp($image, $tempOutput, 95);
                    if (!$result) {
                        $this->logger->err('Watermarker: Failed to save WebP image to temp file');

                        // Try fallback to PNG if WebP fails
                        $this->logger->info('Watermarker: Trying to save as PNG instead of WebP');
                        $result = @imagepng($image, $tempOutput, 9);

                        if ($result) {
                            $this->logger->info('Watermarker: Successfully saved as PNG to temp file instead of WebP');
                            $savedToTemp = true;
                        }
                    } else {
                        $this->logger->info('Watermarker: Successfully saved WebP image to temp file');
                        $savedToTemp = true;
                    }
                } else {
                    $this->logger->err('Watermarker: WebP support not available in PHP');

                    // Fallback to PNG if WebP is not supported
                    $this->logger->info('Watermarker: Falling back to PNG since WebP is not supported');
                    if (imageistruecolor($image)) {
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                    }

                    $result = @imagepng($image, $tempOutput, 9);

                    if ($result) {
                        $this->logger->info('Watermarker: Successfully saved as PNG to temp file');
                        $savedToTemp = true;
                    }
                }
                break;

            default:
                // Try saving as PNG for unsupported types
                $this->logger->info(sprintf('Watermarker: Trying to save unsupported type %s as PNG', $mediaType));
                if (imageistruecolor($image)) {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                }

                $result = @imagepng($image, $tempOutput, 9);
                if ($result) {
                    $this->logger->info('Watermarker: Successfully saved unsupported type as PNG to temp file');
                    $savedToTemp = true;
                } else {
                    $this->logger->err(sprintf('Watermarker: Cannot save unsupported media type to temp file: %s', $mediaType));
                }
                break;
        }

        // If temp file saved successfully, check that it has content
        if ($savedToTemp) {
            if (!file_exists($tempOutput) || filesize($tempOutput) < 100) {
                $this->logger->err(sprintf(
                    'Watermarker: Temp file is empty or too small (size: %d bytes)',
                    file_exists($tempOutput) ? filesize($tempOutput) : 0
                ));
                @unlink($tempOutput);
                return false;
            }

            // Verify the temp file is readable
            if (!is_readable($tempOutput)) {
                $this->logger->err('Watermarker: Temp file is not readable');
                @unlink($tempOutput);
                return false;
            }

            $this->logger->info(sprintf(
                'Watermarker: Temp file created successfully (size: %d bytes)',
                filesize($tempOutput)
            ));

            // 2. Copy from temp file to final destination
            $success = false;

            // Try direct file_put_contents to avoid permission issues
            $fileContents = file_get_contents($tempOutput);
            if ($fileContents !== false) {
                $bytesWritten = file_put_contents($file, $fileContents);
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $this->logger->info(sprintf(
                        'Watermarker: Successfully wrote %d bytes to final file using file_put_contents',
                        $bytesWritten
                    ));
                    $success = true;
                } else {
                    $this->logger->err('Watermarker: Failed to write to final file using file_put_contents');

                    // Try copy as fallback
                    if (@copy($tempOutput, $file)) {
                        $this->logger->info('Watermarker: Successfully copied to final file using copy()');
                        $success = true;
                    } else {
                        $this->logger->err(sprintf(
                            'Watermarker: All file write methods failed for file: %s (error: %s)',
                            $file,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }
            } else {
                $this->logger->err('Watermarker: Failed to read temp file contents');

                // Try copy as fallback
                if (@copy($tempOutput, $file)) {
                    $this->logger->info('Watermarker: Successfully copied to final file using copy()');
                    $success = true;
                } else {
                    $this->logger->err(sprintf(
                        'Watermarker: Copy failed for file: %s (error: %s)',
                        $file,
                        error_get_last()['message'] ?? 'Unknown error'
                    ));
                }
            }

            // Clean up temp file
            @unlink($tempOutput);

            // Verify final file
            if ($success && file_exists($file)) {
                $finalSize = filesize($file);
                $this->logger->info(sprintf('Watermarker: Final file created successfully (size: %d bytes)', $finalSize));

                if ($finalSize < 100) {
                    $this->logger->err('Watermarker: Final file is too small, likely corrupted');
                    return false;
                }

                return true;
            }
        }

        // Clean up
        if (file_exists($tempOutput)) {
            @unlink($tempOutput);
        }

        return false;
    }

    /**
     * Overlay watermark on image
     *
     * @param resource $baseImage
     * @param resource $watermarkImage
     * @param string $position
     * @param float $opacity
     */
    public function overlayWatermark($baseImage, $watermarkImage, $position, $opacity)
    {
        // Get dimensions
        $baseWidth = imagesx($baseImage);
        $baseHeight = imagesy($baseImage);
        $watermarkWidth = imagesx($watermarkImage);
        $watermarkHeight = imagesy($watermarkImage);

        $this->logger->info(sprintf('Watermarker: Overlaying watermark (position: %s, opacity: %.2f)',
            $position, $opacity));

        // Calculate position
        $x = 0;
        $y = 0;

        // Handle full width watermark
        if ($position === 'bottom-full') {
            $this->logger->info('Watermarker: Using full-width watermark positioning');

            // For full width, we need to resize the watermark
            $newWatermarkHeight = $watermarkHeight;
            $newWatermarkWidth = $baseWidth;

            // Create a new image with the right dimensions
            $newWatermark = imagecreatetruecolor($newWatermarkWidth, $newWatermarkHeight);
            if (!$newWatermark) {
                $this->logger->err('Watermarker: Failed to create new watermark image for full-width');
                return;
            }

            // Preserve transparency for the new image
            imagealphablending($newWatermark, false);
            imagesavealpha($newWatermark, true);
            $transparent = imagecolorallocatealpha($newWatermark, 0, 0, 0, 127);
            imagefilledrectangle($newWatermark, 0, 0, $newWatermarkWidth, $newWatermarkHeight, $transparent);
            imagealphablending($newWatermark, true);

            // Resize the watermark to full width while maintaining aspect ratio
            $scaleRatio = $baseWidth / $watermarkWidth;
            $scaledHeight = (int)($watermarkHeight * $scaleRatio);

            $this->logger->info(sprintf('Watermarker: Resizing watermark from %dx%d to %dx%d',
                $watermarkWidth, $watermarkHeight, $baseWidth, $scaledHeight));

            // Only resize if the watermark is smaller than the base image width
            if ($watermarkWidth < $baseWidth) {
                // Stretch watermark to full width
                imagecopyresampled(
                    $newWatermark, $watermarkImage,
                    0, 0, 0, 0,
                    $baseWidth, $scaledHeight,
                    $watermarkWidth, $watermarkHeight
                );

                // Place at the bottom
                $x = 0;
                $y = (int)($baseHeight - $scaledHeight);

                $this->logger->info(sprintf('Watermarker: Placing full-width watermark at position [%d,%d]', $x, $y));

                // Clean up original watermark
                imagedestroy($watermarkImage);

                // Apply the new watermark
                imagecopymerge(
                    $baseImage, $newWatermark,
                    $x, $y, 0, 0,
                    $baseWidth, $scaledHeight,
                    (int)($opacity * 100)
                );

                // Clean up the new watermark
                imagedestroy($newWatermark);

                return;
            } else {
                // If watermark is already wider, just place it at the bottom
                $this->logger->info('Watermarker: Watermark is already wider than image, using bottom-center placement');
                $position = 'bottom-center';
                imagedestroy($newWatermark);
            }
        }

        // Standard positioning for non-full-width watermarks
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
            case 'bottom-center':
                $x = (int)(($baseWidth - $watermarkWidth) / 2);
                $y = $baseHeight - $watermarkHeight - 10;
                break;
            case 'center':
            default:
                $x = (int)(($baseWidth - $watermarkWidth) / 2);
                $y = (int)(($baseHeight - $watermarkHeight) / 2);
                break;
        }

        // Ensure coordinates are integers
        $x = (int)$x;
        $y = (int)$y;

        $this->logger->info(sprintf('Watermarker: Placing watermark at position [%d,%d]', $x, $y));

        // Apply transparency if needed
        imagealphablending($baseImage, true);
        imagesavealpha($baseImage, true);

        // For PNG watermarks with transparency
        if (imageistruecolor($watermarkImage)) {
            $this->logger->info('Watermarker: Configuring transparency for truecolor watermark');
            imagealphablending($watermarkImage, true);
            imagesavealpha($watermarkImage, true);
        }

        // Try to use better alpha-aware copy function for PNGs if available
        if (function_exists('imagecopy') && $opacity >= 1.0) {
            $this->logger->info('Watermarker: Using imagecopy for full opacity watermark');
            // For full opacity, use imagecopy which preserves alpha better
            imagecopy(
                $baseImage, $watermarkImage,
                $x, $y, 0, 0,
                $watermarkWidth, $watermarkHeight
            );
        } else {
            $this->logger->info(sprintf('Watermarker: Using imagecopymerge with opacity %.0f%%', $opacity * 100));
            // Copy with alpha blending for opacity
            imagecopymerge(
                $baseImage, $watermarkImage,
                $x, $y, 0, 0,
                $watermarkWidth, $watermarkHeight,
                (int)($opacity * 100)
            );
        }
    }

    /**
     * Get all available watermark sets
     *
     * @return array Array of watermark sets with their IDs and names
     */
    public function getAllWatermarkSets()
    {
        $sql = "SELECT id, name, is_default FROM watermark_set WHERE enabled = 1 ORDER BY is_default DESC, name ASC";
        $stmt = $this->connection->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get watermark assignment for a resource
     *
     * @param int $resourceId The resource ID
     * @param string $resourceType The resource type (item or item-set)
     * @return array|null Assignment data or null if not found
     */
    public function getWatermarkAssignment($resourceId, $resourceType)
    {
        $sql = "SELECT * FROM watermark_assignment
                WHERE resource_id = :resource_id AND resource_type = :resource_type";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('resource_id', $resourceId);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}