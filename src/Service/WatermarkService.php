<?php
/**
 * Watermark service
 */

namespace Watermarker\Service;

use Omeka\Api\Manager as ApiManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

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
     * Constructor
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        $this->api = $serviceLocator->get('Omeka\ApiManager');
        $this->connection = $serviceLocator->get('Omeka\Connection');
        $this->logger = $serviceLocator->get('Omeka\Logger');
    }

    /**
     * Process a media item for watermarking
     *
     * @param MediaRepresentation $media
     * @return bool True if watermark was applied
     */
    public function processMedia(MediaRepresentation $media)
    {
        $this->logger->info(sprintf(
            'WatermarkService: Processing media ID %s',
            $media->id()
        ));

        // Skip if watermarking is disabled globally
        $settings = $this->serviceLocator->get('Omeka\Settings');
        if (!$settings->get('watermarker_enabled', true)) {
            $this->logger->info('WatermarkService: Watermarking is disabled globally');
            return false;
        }

        // Skip if not an eligible media type
        if (!$this->isWatermarkable($media)) {
            $this->logger->info(sprintf(
                'WatermarkService: Media ID %s is not watermarkable (type: %s)',
                $media->id(),
                $media->mediaType()
            ));
            return false;
        }

        // Get the appropriate watermark
        $watermark = $this->getWatermarkForMedia($media);
        if (!$watermark) {
            $this->logger->info(sprintf(
                'WatermarkService: No suitable watermark found for media ID %s',
                $media->id()
            ));
            return false;
        }

        $this->logger->info(sprintf(
            'WatermarkService: Found watermark ID %s for media ID %s',
            $watermark['id'],
            $media->id()
        ));

        // Apply the watermark
        return $this->applyWatermark($media, $watermark);
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
        $sql = "SELECT * FROM watermark_setting WHERE (orientation = :orientation OR orientation = 'all') AND enabled = 1 LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('orientation', $orientation);
        $stmt->execute();

        $watermark = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$watermark) {
            $this->logger->info(sprintf(
                'No watermark configuration found for orientation: %s',
                $orientation
            ));
            return null;
        }

        $this->logger->info(sprintf(
            'Found watermark configuration ID %s for orientation %s',
            $watermark['id'],
            $orientation
        ));

        // Verify the watermark media still exists
        try {
            $watermarkMedia = $this->api->read('media', $watermark['media_id'])->getContent();
            if (!$watermarkMedia->hasOriginal()) {
                $this->logger->err(sprintf(
                    'Watermark media %s does not have an original file',
                    $watermark['media_id']
                ));
                return null;
            }

            $this->logger->info(sprintf(
                'Using watermark media ID %s, type: %s',
                $watermark['media_id'],
                $watermarkMedia->mediaType()
            ));
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Could not load watermark media %s: %s',
                $watermark['media_id'],
                $e->getMessage()
            ));
            return null;
        }

        return $watermark;
    }

    /**
     * Get media orientation
     *
     * @param MediaRepresentation $media
     * @return string|null 'landscape', 'portrait', or null on error
     */
    protected function getMediaOrientation(MediaRepresentation $media)
    {
        // Download the original file
        $tempFile = $this->downloadFile($media->originalUrl());
        if (!$tempFile) {
            $this->logger->err(sprintf(
                'Failed to download media file for orientation detection: %s',
                $media->originalUrl()
            ));
            return null;
        }

        // Get image dimensions
        $imageSize = @getimagesize($tempFile);
        $this->logger->info(sprintf(
            'Image dimensions for media ID %s: %s',
            $media->id(),
            $imageSize ? "{$imageSize[0]}x{$imageSize[1]}" : "unknown"
        ));

        @unlink($tempFile);

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
     * Apply watermark to media file
     *
     * @param MediaRepresentation $media
     * @param array $watermarkConfig
     * @return bool Success
     */
    protected function applyWatermark(MediaRepresentation $media, array $watermarkConfig)
    {
        $this->logger->info(sprintf(
            'Applying watermark ID %s to media ID %s',
            $watermarkConfig['id'],
            $media->id()
        ));

        // Get watermark image
        try {
            $watermarkMedia = $this->api->read('media', $watermarkConfig['media_id'])->getContent();
            $this->logger->info(sprintf(
                'Retrieved watermark media ID %s',
                $watermarkConfig['media_id']
            ));

            $watermarkFile = $this->downloadFile($watermarkMedia->originalUrl());
            if (!$watermarkFile) {
                return false;
            }

            $this->logger->info('Downloaded watermark image to temp file');
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Failed to load watermark media %s: %s',
                $watermarkConfig['media_id'],
                $e->getMessage()
            ));
            return false;
        }

        // Get target image
        $mediaFile = $this->downloadFile($media->originalUrl());
        if (!$mediaFile) {
            $this->logger->err('Failed to download original media file');
            @unlink($watermarkFile);
            return false;
        }

        $this->logger->info('Downloaded original media to temp file');

        // Create image resources
        $mediaImage = $this->createImageResource($mediaFile, $media->mediaType());
        $watermarkImage = $this->createImageResource($watermarkFile, $watermarkMedia->mediaType());

        // Clean up temp files now that we have loaded the resources
        @unlink($watermarkFile);
        @unlink($mediaFile);

        if (!$mediaImage) {
            $this->logger->err('Failed to create image resource from media file');
            if ($watermarkImage) {
                imagedestroy($watermarkImage);
            }
            return false;
        }

        if (!$watermarkImage) {
            $this->logger->err('Failed to create image resource from watermark file');
            imagedestroy($mediaImage);
            return false;
        }

        $this->logger->info('Created image resources for both media and watermark');

        // Apply watermark with opacity
        $this->overlayWatermark(
            $mediaImage,
            $watermarkImage,
            $watermarkConfig['position'],
            (float)$watermarkConfig['opacity']
        );

        $this->logger->info(sprintf(
            'Applied watermark with position "%s" and opacity %.2f',
            $watermarkConfig['position'],
            (float)$watermarkConfig['opacity']
        ));

        // Save watermarked image to temp file
        $resultFile = tempnam(sys_get_temp_dir(), 'omeka_watermarked_');
        $success = $this->saveImageResource($mediaImage, $resultFile, $media->mediaType());

        // Clean up image resources
        imagedestroy($mediaImage);
        imagedestroy($watermarkImage);

        if (!$success) {
            $this->logger->err('Failed to save watermarked image to temp file');
            @unlink($resultFile);
            return false;
        }

        $this->logger->info('Saved watermarked image to temp file');

        // Now we have the watermarked image in $resultFile
        // We need to update the file in Omeka S storage
        $success = $this->replaceMediaFile($media, $resultFile);

        // Clean up temp file
        @unlink($resultFile);

        return $success;
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
            $scaledHeight = $watermarkHeight * $scaleRatio;

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
                $y = $baseHeight - $scaledHeight;

                // Clean up original watermark
                imagedestroy($watermarkImage);

                // Apply the new watermark
                imagecopymerge(
                    $baseImage, $newWatermark,
                    $x, $y, 0, 0,
                    $baseWidth, $scaledHeight,
                    $opacity * 100
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
                $x = ($baseWidth - $watermarkWidth) / 2;
                $y = $baseHeight - $watermarkHeight - 10;
                break;
            case 'center':
            default:
                $x = ($baseWidth - $watermarkWidth) / 2;
                $y = ($baseHeight - $watermarkHeight) / 2;
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
        imagecopymerge($baseImage, $watermarkImage, $x, $y, 0, 0, $watermarkWidth, $watermarkHeight, $opacity * 100);
    }

    /**
     * Replace the original file of a media with a new one
     *
     * @param MediaRepresentation $media
     * @param string $newFile
     * @return bool Success
     */
    protected function replaceMediaFile(MediaRepresentation $media, $newFile)
    {
        try {
            // Get required services
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaAdapter = $this->serviceLocator->get('Omeka\ApiAdapterManager')->get('media');
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $uploader = $this->serviceLocator->get('Omeka\File\Uploader');

            // Get the media entity from the representation
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());

            // Create a temporary file
            $tempFile = $tempFileFactory->build();
            $tempFile->setSourceName(basename($newFile));
            $tempFile->setTempPath($newFile);

            // Get media type from file
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mediaType = $finfo->file($newFile);
            $tempFile->setMediaType($mediaType);

            // Store file
            $uploader->upload($tempFile);

            // Get the current storage ID
            $currentStorageId = $mediaEntity->getStorageId();

            // Update media entity with new file info
            $mediaEntity->setStorageId($tempFile->getStorageId());
            $mediaEntity->setExtension($tempFile->getExtension());
            $mediaEntity->setMediaType($tempFile->getMediaType());
            $mediaEntity->setSha256($tempFile->getSha256());
            $mediaEntity->setSize($tempFile->getSize());

            // Persist changes
            $entityManager->flush();

            // Generate thumbnails
            $tempFile->storeOriginal();
            $mediaAdapter->storeThumbnails($tempFile);

            // Clean up old file - only delete after successful update
            $store = $this->serviceLocator->get('Omeka\File\Store');
            $store->delete($currentStorageId);

            $this->logger->notice(sprintf(
                'Replaced file for media ID %s with watermarked version',
                $media->id()
            ));

            return true;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Failed to replace file for media ID %s: %s',
                $media->id(),
                $e->getMessage()
            ));
            return false;
        }
    }
}<?php
/**
 * Watermark service
 */

namespace Watermarker\Service;

use Omeka\Api\Manager as ApiManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

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
     * Constructor
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        $this->api = $serviceLocator->get('Omeka\ApiManager');
        $this->connection = $serviceLocator->get('Omeka\Connection');
        $this->logger = $serviceLocator->get('Omeka\Logger');
    }

    /**
     * Process a media item for watermarking
     *
     * @param MediaRepresentation $media
     * @return bool True if watermark was applied
     */
    public function processMedia(MediaRepresentation $media)
    {
        $this->logger->info(sprintf(
            'WatermarkService: Processing media ID %s',
            $media->id()
        ));

        // Skip if watermarking is disabled globally
        $settings = $this->serviceLocator->get('Omeka\Settings');
        if (!$settings->get('watermarker_enabled', true)) {
            $this->logger->info('WatermarkService: Watermarking is disabled globally');
            return false;
        }

        // Skip if not an eligible media type
        if (!$this->isWatermarkable($media)) {
            $this->logger->info(sprintf(
                'WatermarkService: Media ID %s is not watermarkable (type: %s)',
                $media->id(),
                $media->mediaType()
            ));
            return false;
        }

        // Get the appropriate watermark
        $watermark = $this->getWatermarkForMedia($media);
        if (!$watermark) {
            $this->logger->info(sprintf(
                'WatermarkService: No suitable watermark found for media ID %s',
                $media->id()
            ));
            return false;
        }

        $this->logger->info(sprintf(
            'WatermarkService: Found watermark ID %s for media ID %s',
            $watermark['id'],
            $media->id()
        ));

        // Apply the watermark
        return $this->applyWatermark($media, $watermark);
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
            return null;
        }

        // Get settings for this orientation from the database
        $sql = "SELECT * FROM watermark_setting WHERE (orientation = :orientation OR orientation = 'all') AND enabled = 1 LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('orientation', $orientation);
        $stmt->execute();

        $watermark = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$watermark) {
            return null;
        }

        // Verify the watermark media still exists
        try {
            $watermarkMedia = $this->api->read('media', $watermark['media_id'])->getContent();
            if (!$watermarkMedia->hasOriginal()) {
                $this->logger->err(sprintf(
                    'Watermark media %s does not have an original file',
                    $watermark['media_id']
                ));
                return null;
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Could not load watermark media %s: %s',
                $watermark['media_id'],
                $e->getMessage()
            ));
            return null;
        }

        return $watermark;
    }

    /**
     * Get media orientation
     *
     * @param MediaRepresentation $media
     * @return string|null 'landscape', 'portrait', or null on error
     */
    protected function getMediaOrientation(MediaRepresentation $media)
    {
        // Download the original file
        $tempFile = $this->downloadFile($media->originalUrl());
        if (!$tempFile) {
            return null;
        }

        // Get image dimensions
        $imageSize = @getimagesize($tempFile);
        unlink($tempFile);

        if (!$imageSize) {
            return null;
        }

        $width = $imageSize[0];
        $height = $imageSize[1];

        return ($width >= $height) ? 'landscape' : 'portrait';
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

        // Handle local files
        if (strpos($url, '/') === 0) {
            if (!@copy($url, $tempFile)) {
                $this->logger->err(sprintf('Failed to copy local file %s', $url));
                @unlink($tempFile);
                return false;
            }
            return $tempFile;
        }

        // Handle remote files
        $fileContents = @file_get_contents($url);
        if ($fileContents === false) {
            $this->logger->err(sprintf('Failed to download remote file %s', $url));
            @unlink($tempFile);
            return false;
        }

        if (!@file_put_contents($tempFile, $fileContents)) {
            $this->logger->err(sprintf('Failed to save downloaded file %s', $url));
            @unlink($tempFile);
            return false;
        }

        return $tempFile;
    }

    /**
     * Apply watermark to media file
     *
     * @param MediaRepresentation $media
     * @param array $watermarkConfig
     * @return bool Success
     */
    protected function applyWatermark(MediaRepresentation $media, array $watermarkConfig)
    {
        $this->logger->info(sprintf(
            'Applying watermark ID %s to media ID %s',
            $watermarkConfig['id'],
            $media->id()
        ));

        // Get watermark image
        try {
            $watermarkMedia = $this->api->read('media', $watermarkConfig['media_id'])->getContent();
            $this->logger->info(sprintf(
                'Retrieved watermark media ID %s',
                $watermarkConfig['media_id']
            ));

            $watermarkFile = $this->downloadFile($watermarkMedia->originalUrl());
            if (!$watermarkFile) {
                return false;
            }

            $this->logger->info('Downloaded watermark image to temp file');
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Failed to load watermark media %s: %s',
                $watermarkConfig['media_id'],
                $e->getMessage()
            ));
            return false;
        }

        // Get target image
        $mediaFile = $this->downloadFile($media->originalUrl());
        if (!$mediaFile) {
            $this->logger->err('Failed to download original media file');
            @unlink($watermarkFile);
            return false;
        }

        $this->logger->info('Downloaded original media to temp file');

        // Create image resources
        $mediaImage = $this->createImageResource($mediaFile, $media->mediaType());
        $watermarkImage = $this->createImageResource($watermarkFile, $watermarkMedia->mediaType());

        // Clean up temp files now that we have loaded the resources
        @unlink($watermarkFile);
        @unlink($mediaFile);

        if (!$mediaImage) {
            $this->logger->err('Failed to create image resource from media file');
            if ($watermarkImage) {
                imagedestroy($watermarkImage);
            }
            return false;
        }

        if (!$watermarkImage) {
            $this->logger->err('Failed to create image resource from watermark file');
            imagedestroy($mediaImage);
            return false;
        }

        $this->logger->info('Created image resources for both media and watermark');

        // Apply watermark with opacity
        $this->overlayWatermark(
            $mediaImage,
            $watermarkImage,
            $watermarkConfig['position'],
            (float)$watermarkConfig['opacity']
        );

        $this->logger->info(sprintf(
            'Applied watermark with position "%s" and opacity %.2f',
            $watermarkConfig['position'],
            (float)$watermarkConfig['opacity']
        ));

        // Save watermarked image to temp file
        $resultFile = tempnam(sys_get_temp_dir(), 'omeka_watermarked_');
        $success = $this->saveImageResource($mediaImage, $resultFile, $media->mediaType());

        // Clean up image resources
        imagedestroy($mediaImage);
        imagedestroy($watermarkImage);

        if (!$success) {
            $this->logger->err('Failed to save watermarked image to temp file');
            @unlink($resultFile);
            return false;
        }

        $this->logger->info('Saved watermarked image to temp file');

        // Now we have the watermarked image in $resultFile
        // We need to update the file in Omeka S storage
        $success = $this->replaceMediaFile($media, $resultFile);

        // Clean up temp file
        @unlink($resultFile);

        return $success;
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
            $scaledHeight = $watermarkHeight * $scaleRatio;

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
                $y = $baseHeight - $scaledHeight;

                // Clean up original watermark
                imagedestroy($watermarkImage);

                // Apply the new watermark
                imagecopymerge(
                    $baseImage, $newWatermark,
                    $x, $y, 0, 0,
                    $baseWidth, $scaledHeight,
                    $opacity * 100
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
                $x = ($baseWidth - $watermarkWidth) / 2;
                $y = $baseHeight - $watermarkHeight - 10;
                break;
            case 'center':
            default:
                $x = ($baseWidth - $watermarkWidth) / 2;
                $y = ($baseHeight - $watermarkHeight) / 2;
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
        imagecopymerge($baseImage, $watermarkImage, $x, $y, 0, 0, $watermarkWidth, $watermarkHeight, $opacity * 100);
    }

    /**
     * Replace the original file of a media with a new one
     *
     * @param MediaRepresentation $media
     * @param string $newFile
     * @return bool Success
     */
    protected function replaceMediaFile(MediaRepresentation $media, $newFile)
    {
        try {
            // Get required services
            $entityManager = $this->serviceLocator->get('Omeka\EntityManager');
            $mediaAdapter = $this->serviceLocator->get('Omeka\ApiAdapterManager')->get('media');
            $tempFileFactory = $this->serviceLocator->get('Omeka\File\TempFileFactory');
            $uploader = $this->serviceLocator->get('Omeka\File\Uploader');

            // Get the media entity from the representation
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());

            // Create a temporary file
            $tempFile = $tempFileFactory->build();
            $tempFile->setSourceName(basename($newFile));
            $tempFile->setTempPath($newFile);

            // Get media type from file
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mediaType = $finfo->file($newFile);
            $tempFile->setMediaType($mediaType);

            // Store file
            $uploader->upload($tempFile);

            // Get the current storage ID
            $currentStorageId = $mediaEntity->getStorageId();

            // Update media entity with new file info
            $mediaEntity->setStorageId($tempFile->getStorageId());
            $mediaEntity->setExtension($tempFile->getExtension());
            $mediaEntity->setMediaType($tempFile->getMediaType());
            $mediaEntity->setSha256($tempFile->getSha256());
            $mediaEntity->setSize($tempFile->getSize());

            // Persist changes
            $entityManager->flush();

            // Generate thumbnails
            $tempFile->storeOriginal();
            $mediaAdapter->storeThumbnails($tempFile);

            // Clean up old file - only delete after successful update
            $store = $this->serviceLocator->get('Omeka\File\Store');
            $store->delete($currentStorageId);

            $this->logger->notice(sprintf(
                'Replaced file for media ID %s with watermarked version',
                $media->id()
            ));

            return true;
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Failed to replace file for media ID %s: %s',
                $media->id(),
                $e->getMessage()
            ));
            return false;
        }
    }
}