<?php
/**
 * Watermarker job to apply watermarks to media derivatives
 */

namespace Watermarker\Job;

use Omeka\Job\AbstractJob;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;

class ApplyWatermark extends AbstractJob
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * Apply watermark to derivatives
     */
    public function perform()
    {
        // Get services
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $store = $services->get('Omeka\File\Store');

        // Get job parameters - using proper AbstractJob method
        $mediaId = $this->getArg('media_id');
        $watermarkId = $this->getArg('watermark_id', null);
        $isNewUpload = $this->getArg('is_new_upload', false);
        $assetPath = $this->getArg('asset_path', null);

        $this->logger->info(sprintf(
            'Starting watermark job for media ID %s, is new upload: %s',
            $mediaId,
            $isNewUpload ? 'yes' : 'no'
        ));

        try {
            // Validate that we have a required media ID
            if (!$mediaId) {
                $this->logger->err('No media ID provided, cannot proceed');
                return;
            }
            
            // Get media entity
            try {
                $media = $api->read('media', $mediaId)->getContent();
                if (!$media) {
                    $this->logger->err(sprintf('Media ID %s not found', $mediaId));
                    return;
                }
            } catch (\Exception $e) {
                $this->logger->err(sprintf('Error fetching media ID %s: %s', $mediaId, $e->getMessage()));
                return;
            }

            // For new uploads, we need to wait a bit to ensure derivatives have been generated
            if ($isNewUpload) {
                $this->logger->info('New upload detected, waiting 5 seconds for derivatives to be generated...');
                sleep(5);
                
                // If no watermark ID is provided, find an appropriate one
                if (!$watermarkId) {
                    $watermarkId = $this->findAppropriateWatermark($media);
                    if (!$watermarkId) {
                        $this->logger->err('Could not find an appropriate watermark for this media');
                        return;
                    }
                }
            }

            // Get watermark configuration
            $connection = $services->get('Omeka\Connection');
            $sql = "SELECT * FROM watermark_setting WHERE id = :id LIMIT 1";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue('id', $watermarkId);
            $stmt->execute();
            $watermarkConfig = $stmt->fetch();

            if (!$watermarkConfig) {
                $this->logger->err(sprintf('Watermark configuration ID %s not found', $watermarkId));
                return;
            }

            // Get watermark asset
            $watermarkAsset = $api->read('assets', $watermarkConfig['media_id'])->getContent();
            if (!$watermarkAsset) {
                $this->logger->err(sprintf('Watermark asset ID %s not found', $watermarkConfig['media_id']));
                return;
            }

            // Get temp directory
            $tempDir = $services->get('Config')['file_manager']['temp_dir'] ?? sys_get_temp_dir();

            // We already get the asset path from job parameters above
            if (!$assetPath || !file_exists($assetPath)) {
                $this->logger->info('No asset path provided or file not found, trying to locate');

                $assetUrl = $watermarkAsset->assetUrl();
                $assetFilename = basename($assetUrl);

                // Try various possible locations for the asset file
                $possibleAssetPaths = [
                    OMEKA_PATH . '/files/asset/' . $assetFilename,
                    '/var/www/html/files/asset/' . $assetFilename,
                    // Add more possible paths if needed
                ];

                foreach ($possibleAssetPaths as $path) {
                    if (file_exists($path)) {
                        $assetPath = $path;
                        $this->logger->info(sprintf('Found asset at: %s', $assetPath));
                        break;
                    }
                }

                if (!$assetPath || !file_exists($assetPath)) {
                    $this->logger->err('Could not find asset file locally, cannot proceed');
                    return;
                }
            }

            $this->logger->info(sprintf('Using watermark file: %s', $assetPath));

            // Get media entity to access storage ID
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);
            if (!$mediaEntity) {
                $this->logger->err(sprintf('Media entity not found: %s', $mediaId));
                return;
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

            // Get the original media file path
            $originalFile = tempnam($tempDir, 'original_');
            $originalUrl = $media->originalUrl();

            // Try to find the original file directly
            $originalPath = null;
            $possibleOriginalPaths = [
                OMEKA_PATH . '/files/original/' . $storageId . '.' . $extension,
                OMEKA_PATH . '/files/original/' . $storageId,
                '/var/www/html/files/original/' . $storageId . '.' . $extension,
                '/var/www/html/files/original/' . $storageId,
                // Add more possibilities if needed
            ];

            foreach ($possibleOriginalPaths as $path) {
                if (file_exists($path)) {
                    $originalPath = $path;
                    $this->logger->info(sprintf('Found original file at: %s', $originalPath));
                    break;
                }
            }

            if (!$originalPath) {
                $this->logger->err('Could not find original file locally, cannot proceed');
                return;
            }

            // Copy the original file to our temp location for processing
            if (!copy($originalPath, $originalFile)) {
                $this->logger->err('Failed to copy original file to temp location');
                @unlink($originalFile);
                return;
            }

            $this->logger->info(sprintf('Copied original file to: %s', $originalFile));

            // Now manually create the derivatives we want to watermark
            $derivativeTypes = ['large', 'medium'];
            $success = false;

            foreach ($derivativeTypes as $type) {
                $this->logger->info(sprintf('Processing %s derivative', $type));

                // First find the existing derivative to get its dimensions
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

                // Temporary file for watermarked version
                $watermarkedFile = tempnam($tempDir, 'watermarked_');

                // Copy the derivative to temp file for processing
                if (!copy($derivativePath, $watermarkedFile)) {
                    $this->logger->err(sprintf('Failed to copy %s derivative for processing', $type));
                    @unlink($watermarkedFile);
                    continue;
                }

                // Now load and process the images
                $mediaImage = $this->createImageResource($watermarkedFile, $mediaType);
                $watermarkImage = $this->createImageResource($assetPath, 'image/png');

                if (!$mediaImage) {
                    $this->logger->err(sprintf('Failed to create image resource from %s derivative', $type));
                    if ($watermarkImage) {
                        imagedestroy($watermarkImage);
                    }
                    @unlink($watermarkedFile);
                    continue;
                }

                if (!$watermarkImage) {
                    $this->logger->err('Failed to create image resource from watermark file');
                    imagedestroy($mediaImage);
                    @unlink($watermarkedFile);
                    continue;
                }

                // Get dimensions for logging
                $width = imagesx($mediaImage);
                $height = imagesy($mediaImage);
                $this->logger->info(sprintf(
                    'Processing %s derivative: %dx%d pixels',
                    $type,
                    $width,
                    $height
                ));

                // Apply watermark
                $this->overlayWatermark(
                    $mediaImage,
                    $watermarkImage,
                    $watermarkConfig['position'],
                    (float)$watermarkConfig['opacity']
                );

                // Save watermarked derivative
                $tempResult = tempnam($tempDir, 'result_');
                $saveSuccess = $this->saveImageResource($mediaImage, $tempResult, $mediaType);

                // Clean up image resources
                imagedestroy($mediaImage);
                imagedestroy($watermarkImage);
                @unlink($watermarkedFile);

                if (!$saveSuccess) {
                    $this->logger->err(sprintf('Failed to save watermarked %s derivative', $type));
                    @unlink($tempResult);
                    continue;
                }

                // Replace the original derivative with our watermarked version
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

            // Clean up temp files
            @unlink($originalFile);

            if ($success) {
                $this->logger->info(sprintf(
                    'Successfully applied watermark to derivatives of media ID %s',
                    $mediaId
                ));
            } else {
                $this->logger->err(sprintf(
                    'Failed to apply watermark to any derivatives of media ID %s',
                    $mediaId
                ));
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error in watermark job: %s',
                $e->getMessage()
            ));
        }
    }
    
    /**
     * Find an appropriate watermark for the given media
     * 
     * @param MediaRepresentation $media
     * @return int|null Watermark ID or null if none found
     */
    protected function findAppropriateWatermark($media)
    {
        $this->logger->info('Searching for appropriate watermark');
        
        try {
            // Get the media's orientation
            $orientation = $this->getMediaOrientation($media);
            if (!$orientation) {
                $this->logger->info('Could not determine media orientation');
                return null;
            }
            
            $this->logger->info('Media orientation: ' . $orientation);
            
            // Query for watermarks matching this orientation
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $sql = "SELECT id FROM watermark_setting WHERE (orientation = :orientation OR orientation = 'all') AND enabled = 1 ORDER BY id ASC LIMIT 1";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue('orientation', $orientation);
            $stmt->execute();
            
            $watermarkId = $stmt->fetchColumn();
            
            if ($watermarkId) {
                $this->logger->info(sprintf('Found matching watermark ID: %s', $watermarkId));
                return $watermarkId;
            }
            
            // If no specific orientation match, try for generic watermarks
            $sql = "SELECT id FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
            $stmt = $connection->query($sql);
            $watermarkId = $stmt->fetchColumn();
            
            if ($watermarkId) {
                $this->logger->info(sprintf('Found generic watermark ID: %s', $watermarkId));
                return $watermarkId;
            }
            
            $this->logger->info('No suitable watermark found');
            return null;
            
        } catch (\Exception $e) {
            $this->logger->err(sprintf('Error finding appropriate watermark: %s', $e->getMessage()));
            return null;
        }
    }
    
    /**
     * Get media orientation
     * 
     * @param MediaRepresentation $media
     * @return string|null 'landscape', 'portrait', or null on error
     */
    protected function getMediaOrientation($media)
    {
        try {
            // Get the media's original file
            $storageId = null;
            $extension = null;
            
            // Get entity manager and find the media entity
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $media->id());
            
            if (!$mediaEntity) {
                $this->logger->err('Media entity not found');
                return null;
            }
            
            $storageId = $mediaEntity->getStorageId();
            $extension = $mediaEntity->getExtension();
            
            // Try to find the original file
            $possiblePaths = [
                OMEKA_PATH . '/files/original/' . $storageId . '.' . $extension,
                OMEKA_PATH . '/files/original/' . $storageId,
                '/var/www/html/files/original/' . $storageId . '.' . $extension,
                '/var/www/html/files/original/' . $storageId,
            ];
            
            $originalPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $originalPath = $path;
                    break;
                }
            }
            
            if (!$originalPath) {
                $this->logger->err('Could not find original file');
                return null;
            }
            
            // Get image dimensions
            $imageSize = @getimagesize($originalPath);
            if (!$imageSize) {
                $this->logger->err('Failed to get image dimensions');
                return null;
            }
            
            $width = $imageSize[0];
            $height = $imageSize[1];
            
            // Determine orientation
            return ($width >= $height) ? 'landscape' : 'portrait';
            
        } catch (\Exception $e) {
            $this->logger->err(sprintf('Error getting media orientation: %s', $e->getMessage()));
            return null;
        }
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