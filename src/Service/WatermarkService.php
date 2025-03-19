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
        $this->logger->info(sprintf(
            'Processing media ID %s (isNewUpload: %s)',
            $media->id(),
            $isNewUpload ? 'true' : 'false'
        ));

        // Skip if watermarking is disabled globally
        $settings = $this->serviceLocator->get('Omeka\Settings');
        if (!$settings->get('watermarker_enabled', true)) {
            $this->logger->info('Watermarking is disabled globally');
            return false;
        }

        // Skip if not an eligible media type
        if (!$this->isWatermarkable($media)) {
            $this->logger->info(sprintf(
                'Media ID %s is not watermarkable (type: %s)',
                $media->id(),
                $media->mediaType()
            ));
            return false;
        }

        // Get the appropriate watermark
        $watermark = $this->getWatermarkForMedia($media);
        if (!$watermark) {
            $this->logger->info(sprintf(
                'No suitable watermark found for media ID %s',
                $media->id()
            ));
            return false;
        }

        $this->logger->info(sprintf(
            'Found watermark ID %s for media ID %s',
            $watermark['id'],
            $media->id()
        ));

        // If this is a new upload, we may need to wait for derivatives to be generated
        if ($isNewUpload) {
            $this->logger->info('New upload detected, waiting 2 seconds for derivatives to be generated...');
            // Sleep briefly to allow derivatives to be generated
            sleep(2);
        }

        // Apply watermark directly to derivatives
        return $this->applyWatermarkDirectly($media, $watermark);
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
     * @return array|null Watermark configuration or null if none applies
     */
    public function getWatermarkForMedia(MediaRepresentation $media)
    {
        // Get image dimensions
        $orientation = $this->getMediaOrientation($media);
        if (!$orientation) {
            $this->logger->info('Could not determine media orientation, skipping watermark');
            return null;
        }

        // Get settings for this orientation from the database
        $sql = "SELECT * FROM watermark_setting WHERE (orientation = :orientation OR orientation = 'all') AND enabled = 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('orientation', $orientation);
        $stmt->execute();

        $watermarks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($watermarks)) {
            $this->logger->info(sprintf(
                'No watermark configuration found for orientation: %s',
                $orientation
            ));
            return null;
        }

        // Try each watermark until we find one with valid media
        foreach ($watermarks as $watermark) {
            $this->logger->info(sprintf(
                'Checking watermark configuration ID %s for orientation %s',
                $watermark['id'],
                $orientation
            ));

            // Get the asset instead of media
            try {
                $assetId = $watermark['media_id']; // We kept the column name as media_id
                $api = $this->serviceLocator->get('Omeka\ApiManager');
                $watermarkAsset = $api->read('assets', $assetId)->getContent();

                $this->logger->info(sprintf(
                    'Found asset ID %s for watermark',
                    $assetId
                ));

                // If we got here, the asset exists
                return $watermark;

            } catch (\Exception $e) {
                $this->logger->err(sprintf(
                    'Could not load watermark asset %s: %s',
                    $watermark['media_id'],
                    $e->getMessage()
                ));

                // Log the error and continue to the next watermark
                continue;
            }
        }

        $this->logger->info('No valid watermark configurations found');
        return null;
    }

    /**
     * Get media orientation
     *
     * @param MediaRepresentation $media
     * @return string|null 'landscape', 'portrait', or null on error
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

        $orientation = ($width >= $height) ? 'landscape' : 'portrait';
        $this->logger->info(sprintf(
            'Media ID %s orientation: %s (%dx%d)',
            $media->id(),
            $orientation,
            $width,
            $height
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
     * @param MediaRepresentation $media
     * @param array $watermarkConfig
     * @return bool Success
     */
    protected function applyWatermarkDirectly(MediaRepresentation $media, array $watermarkConfig)
    {
        $this->logger->info(sprintf(
            'Applying watermark ID %s to derivatives of media ID %s',
            $watermarkConfig['id'],
            $media->id()
        ));

        try {
            // Get watermark asset
            $assetId = $watermarkConfig['media_id'];
            $api = $this->serviceLocator->get('Omeka\ApiManager');
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            $this->logger->info(sprintf(
                'Retrieved watermark asset ID %s',
                $assetId
            ));

            // Get the temp directory
            $tempDir = $this->serviceLocator->get('Config')['file_manager']['temp_dir'] ?? sys_get_temp_dir();

            // Find the local path to the asset
            $assetUrl = $watermarkAsset->assetUrl();
            $assetFilename = basename($assetUrl);

            $this->logger->info(sprintf('Asset URL: %s', $assetUrl));

            // Try various possible locations for the asset file
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
                // Add more possible paths if needed
            ];

            $assetPath = null;
            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    $this->logger->info(sprintf('Found asset at: %s', $assetPath));
                    break;
                }
            }

            if (!$assetPath) {
                $this->logger->err('Could not find asset file locally');
                return false;
            }

            // Get media entity to access storage ID
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());
            
            if (!$mediaEntity) {
                $this->logger->err(sprintf('Media entity with ID %s not found', $media->id()));
                return false;
            }
            
            $storageId = $mediaEntity->getStorageId();
            $extension = $mediaEntity->getExtension();
            $mediaType = $mediaEntity->getMediaType();
            
            $this->logger->info(sprintf(
                'Media info - Storage ID: %s, Extension: %s, Type: %s',
                $storageId,
                $extension,
                $mediaType
            ));
            
            // Find paths to the derivative files
            $derivativeTypes = ['large', 'medium'];
            $success = false;
            
            foreach ($derivativeTypes as $type) {
                $this->logger->info(sprintf('Processing %s derivative', $type));
                
                // Find the derivative file
                $derivativePath = null;
                $possibleDerivativePaths = [
                    OMEKA_PATH . '/files/' . $type . '/' . $storageId . '.' . $extension,
                    OMEKA_PATH . '/files/' . $type . '/' . $storageId,
                    '/var/www/html/files/' . $type . '/' . $storageId . '.' . $extension,
                    '/var/www/html/files/' . $type . '/' . $storageId,
                ];
                
                foreach ($possibleDerivativePaths as $path) {
                    if (file_exists($path)) {
                        $derivativePath = $path;
                        $this->logger->info(sprintf('Found %s derivative at: %s', $type, $derivativePath));
                        break;
                    }
                }
                
                if (!$derivativePath) {
                    $this->logger->err(sprintf('Could not find %s derivative, skipping', $type));
                    continue;
                }
                
                // Create temp files for processing
                $tempDerivative = tempnam($tempDir, 'watermark_');
                
                // Copy the derivative for processing
                if (!copy($derivativePath, $tempDerivative)) {
                    $this->logger->err(sprintf('Failed to copy %s derivative for processing', $type));
                    @unlink($tempDerivative);
                    continue;
                }
                
                // Create image resources
                $mediaImage = $this->createImageResource($tempDerivative, $mediaType);
                $watermarkImage = $this->createImageResource($assetPath, 'image/png');
                
                if (!$mediaImage) {
                    $this->logger->err(sprintf('Failed to create image resource from %s derivative', $type));
                    if ($watermarkImage) {
                        imagedestroy($watermarkImage);
                    }
                    @unlink($tempDerivative);
                    continue;
                }
                
                if (!$watermarkImage) {
                    $this->logger->err('Failed to create image resource from watermark file');
                    imagedestroy($mediaImage);
                    @unlink($tempDerivative);
                    continue;
                }
                
                // Log the dimensions
                $width = imagesx($mediaImage);
                $height = imagesy($mediaImage);
                $this->logger->info(sprintf(
                    'Processing %s derivative: %dx%d pixels',
                    $type,
                    $width,
                    $height
                ));
                
                // Apply the watermark
                $this->overlayWatermark(
                    $mediaImage,
                    $watermarkImage,
                    $watermarkConfig['position'],
                    (float)$watermarkConfig['opacity']
                );
                
                // Save the watermarked image
                $tempResult = tempnam($tempDir, 'result_');
                $saveSuccess = $this->saveImageResource($mediaImage, $tempResult, $mediaType);
                
                // Clean up resources
                imagedestroy($mediaImage);
                imagedestroy($watermarkImage);
                @unlink($tempDerivative);
                
                if (!$saveSuccess) {
                    $this->logger->err(sprintf('Failed to save watermarked %s derivative', $type));
                    @unlink($tempResult);
                    continue;
                }
                
                // Replace the original derivative with the watermarked version
                if (!copy($tempResult, $derivativePath)) {
                    $this->logger->err(sprintf('Failed to replace %s derivative with watermarked version', $type));
                    @unlink($tempResult);
                    continue;
                }
                
                @unlink($tempResult);
                
                $this->logger->info(sprintf(
                    'Successfully replaced %s derivative with watermarked version',
                    $type
                ));
                
                $success = true;
            }
            
            if ($success) {
                $this->logger->info(sprintf(
                    'Successfully applied watermark to derivatives of media ID %s',
                    $media->id()
                ));
            } else {
                $this->logger->err(sprintf(
                    'Failed to apply watermark to any derivatives of media ID %s',
                    $media->id()
                ));
            }
            
            return $success;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Failed to apply watermark to derivatives: %s',
                $e->getMessage()
            ));
            return false;
        }
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
    protected function createImageResource($file, $mediaType)
    {
        switch ($mediaType) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($file);
            case 'image/png':
                return @imagecreatefrompng($file);
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($file);
                }
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
    protected function saveImageResource($image, $file, $mediaType)
    {
        switch ($mediaType) {
            case 'image/jpeg':
                return @imagejpeg($image, $file, 95);
            case 'image/png':
                return @imagepng($image, $file, 9);
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    return @imagewebp($image, $file, 95);
                }
                break;
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

        // Handle full width watermark
        if ($position === 'bottom-full') {
            // For full width, we need to resize the watermark
            $newWatermarkHeight = $watermarkHeight;
            $newWatermarkWidth = $baseWidth;

            // Create a new image with the right dimensions
            $newWatermark = imagecreatetruecolor($newWatermarkWidth, $newWatermarkHeight);

            // Preserve transparency for the new image
            imagealphablending($newWatermark, false);
            imagesavealpha($newWatermark, true);
            $transparent = imagecolorallocatealpha($newWatermark, 0, 0, 0, 127);
            imagefilledrectangle($newWatermark, 0, 0, $newWatermarkWidth, $newWatermarkHeight, $transparent);
            imagealphablending($newWatermark, true);

            // Resize the watermark to full width while maintaining aspect ratio
            $scaleRatio = $baseWidth / $watermarkWidth;
            $scaledHeight = (int)($watermarkHeight * $scaleRatio);

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

        // Apply transparency if needed
        imagealphablending($baseImage, true);
        imagesavealpha($baseImage, true);

        // For PNG watermarks with transparency
        if (imageistruecolor($watermarkImage)) {
            imagealphablending($watermarkImage, true);
            imagesavealpha($watermarkImage, true);
        }

        // Copy with alpha blending for opacity
        imagecopymerge($baseImage, $watermarkImage, $x, $y, 0, 0, $watermarkWidth, $watermarkHeight, (int)($opacity * 100));
    }
}