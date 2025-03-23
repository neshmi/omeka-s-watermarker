<?php
namespace Watermarker\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

class WatermarkApplicator
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Watermarker\Service\WatermarkService
     */
    protected $watermarkService;

    /**
     * Constructor
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        $this->logger = $serviceLocator->get('Omeka\Logger');
        $this->api = $serviceLocator->get('Omeka\ApiManager');
        $this->watermarkService = $serviceLocator->get('Watermarker\Service\WatermarkService');
    }

    /**
     * Apply a watermark to a media
     *
     * @param int|MediaRepresentation $media The media to watermark
     * @return bool True if the watermark was applied, false otherwise
     */
    public function applyWatermark($media)
    {
        // If an ID was provided, get the media representation
        if (is_numeric($media)) {
            try {
                $media = $this->api->read('media', $media)->getContent();
            } catch (\Exception $e) {
                $this->logger->err('Watermarker: Failed to load media: ' . $e->getMessage());
                return false;
            }
        }

        // Verify this is a media representation
        if (!$media instanceof MediaRepresentation) {
            $this->logger->err('Watermarker: Invalid media provided to watermark applicator');
            return false;
        }

        // Check if media has image media type
        $mediaType = $media->mediaType();
        if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'])) {
            $this->logger->info(sprintf('Watermarker: Skipping non-image media type: %s', $mediaType));
            return false;
        }

        $this->logger->info(sprintf('Watermarker: Applying watermark to media ID: %d', $media->id()));

        // Get the watermark set for this media
        $watermarkSet = $this->getWatermarkSetForMedia($media);
        if (!$watermarkSet) {
            $this->logger->info('Watermarker: No watermark set found for this media');
            return false;
        }

        // Get the appropriate watermark setting based on media orientation
        $watermarkSetting = $this->getWatermarkSettingForMedia($watermarkSet, $media);
        if (!$watermarkSetting) {
            $this->logger->info('Watermarker: No matching watermark setting found for media orientation');
            return false;
        }

        // Get derivatives paths
        $paths = $this->getDerivativePaths($media);
        if (empty($paths)) {
            $this->logger->err('Watermarker: Could not find derivative paths for media');
            return false;
        }

        // Get the watermark image
        $watermarkMediaId = $watermarkSetting->mediaId();
        if (!$watermarkMediaId) {
            $this->logger->err('Watermarker: No watermark media found');
            return false;
        }

        try {
            $watermarkMedia = $this->api->read('media', $watermarkMediaId)->getContent();
        } catch (\Exception $e) {
            $this->logger->err('Watermarker: Failed to load watermark media: ' . $e->getMessage());
            return false;
        }

        $watermarkImagePath = $this->getLocalPathForMedia($watermarkMedia);
        if (!$watermarkImagePath) {
            $this->logger->err('Watermarker: Could not get local path for watermark image');
            return false;
        }

        // Process each derivative
        $success = false;
        foreach ($paths as $type => $path) {
            if ($type !== 'large') {
                // Only watermark the large derivative
                continue;
            }

            if ($this->applyWatermarkToFile(
                $path,
                $watermarkImagePath,
                $watermarkSetting->position(),
                $watermarkSetting->opacity()
            )) {
                $this->logger->info(sprintf('Watermarker: Successfully applied watermark to %s derivative', $type));
                $success = true;
            } else {
                $this->logger->err(sprintf('Watermarker: Failed to apply watermark to %s derivative', $type));
            }
        }

        return $success;
    }

    /**
     * Apply a watermark to a file
     *
     * @param string $targetPath Path to the file to watermark
     * @param string $watermarkPath Path to the watermark image
     * @param string $position Position of the watermark
     * @param float $opacity Opacity of the watermark
     * @return bool True if the watermark was applied, false otherwise
     */
    protected function applyWatermarkToFile($targetPath, $watermarkPath, $position, $opacity)
    {
        if (!file_exists($targetPath)) {
            $this->logger->err(sprintf('Watermarker: Target file does not exist: %s', $targetPath));
            return false;
        }

        if (!file_exists($watermarkPath)) {
            $this->logger->err(sprintf('Watermarker: Watermark file does not exist: %s', $watermarkPath));
            return false;
        }

        // Get file info
        $fileInfo = @getimagesize($targetPath);
        if (!$fileInfo) {
            $this->logger->err(sprintf('Watermarker: Failed to get image size for: %s', $targetPath));
            return false;
        }

        $mimeType = $fileInfo['mime'];

        // Create image resources
        $targetImage = $this->watermarkService->createImageResource($targetPath, $mimeType);
        if (!$targetImage) {
            $this->logger->err(sprintf('Watermarker: Failed to create image resource from: %s', $targetPath));
            return false;
        }

        $watermarkImage = $this->watermarkService->createImageResource($watermarkPath, 'image/png');
        if (!$watermarkImage) {
            $this->logger->err(sprintf('Watermarker: Failed to create watermark image resource from: %s', $watermarkPath));
            imagedestroy($targetImage);
            return false;
        }

        // Apply watermark
        $this->watermarkService->overlayWatermark(
            $targetImage,
            $watermarkImage,
            $position,
            $opacity
        );

        // Save the image
        $result = $this->watermarkService->saveImageResource($targetImage, $targetPath, $mimeType);

        // Clean up
        imagedestroy($targetImage);
        imagedestroy($watermarkImage);

        return $result;
    }

    /**
     * Get the watermark set for a media
     *
     * @param MediaRepresentation $media
     * @return \Watermarker\Api\Representation\WatermarkSetRepresentation|null
     */
    protected function getWatermarkSetForMedia(MediaRepresentation $media)
    {
        // First check for a direct assignment to the media
        try {
            $assignments = $this->api->search('watermark_assignments', [
                'resource_type' => 'media',
                'resource_id' => $media->id(),
            ])->getContent();

            if (count($assignments) > 0) {
                $assignment = $assignments[0];

                // If explicitly no watermark, return null
                if ($assignment->explicitlyNoWatermark()) {
                    return null;
                }

                // If has a watermark set, return it
                if ($assignment->watermarkSet()) {
                    return $assignment->watermarkSet();
                }
            }
        } catch (\Exception $e) {
            $this->logger->err('Watermarker: Error checking media assignment: ' . $e->getMessage());
        }

        // Next check for an assignment to the parent item
        $item = $media->item();
        if ($item) {
            try {
                $assignments = $this->api->search('watermark_assignments', [
                    'resource_type' => 'items',
                    'resource_id' => $item->id(),
                ])->getContent();

                if (count($assignments) > 0) {
                    $assignment = $assignments[0];

                    // If explicitly no watermark, return null
                    if ($assignment->explicitlyNoWatermark()) {
                        return null;
                    }

                    // If has a watermark set, return it
                    if ($assignment->watermarkSet()) {
                        return $assignment->watermarkSet();
                    }
                }
            } catch (\Exception $e) {
                $this->logger->err('Watermarker: Error checking item assignment: ' . $e->getMessage());
            }

            // Next check for assignments to item sets
            $itemSets = $item->itemSets();
            foreach ($itemSets as $itemSet) {
                try {
                    $assignments = $this->api->search('watermark_assignments', [
                        'resource_type' => 'item_sets',
                        'resource_id' => $itemSet->id(),
                    ])->getContent();

                    if (count($assignments) > 0) {
                        $assignment = $assignments[0];

                        // If explicitly no watermark, return null
                        if ($assignment->explicitlyNoWatermark()) {
                            return null;
                        }

                        // If has a watermark set, return it
                        if ($assignment->watermarkSet()) {
                            return $assignment->watermarkSet();
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->err('Watermarker: Error checking item set assignment: ' . $e->getMessage());
                }
            }
        }

        // Finally, get the default watermark set
        try {
            $watermarkSets = $this->api->search('watermark_sets', [
                'is_default' => true,
                'enabled' => true,
            ])->getContent();

            if (count($watermarkSets) > 0) {
                return $watermarkSets[0];
            }
        } catch (\Exception $e) {
            $this->logger->err('Watermarker: Error getting default watermark set: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get the appropriate watermark setting for a media based on its orientation
     *
     * @param \Watermarker\Api\Representation\WatermarkSetRepresentation $watermarkSet
     * @param MediaRepresentation $media
     * @return \Watermarker\Api\Representation\WatermarkSettingRepresentation|null
     */
    protected function getWatermarkSettingForMedia($watermarkSet, MediaRepresentation $media)
    {
        // Get all settings for this watermark set
        $settings = $watermarkSet->settings();
        if (empty($settings)) {
            return null;
        }

        // Try to determine media's orientation
        $orientation = $this->getMediaOrientation($media);

        // First look for a setting that matches the orientation
        foreach ($settings as $setting) {
            if ($setting->type() === $orientation) {
                return $setting;
            }
        }

        // Then look for a setting that works for all orientations
        foreach ($settings as $setting) {
            if ($setting->type() === 'all') {
                return $setting;
            }
        }

        // Return the first setting as a fallback
        return $settings[0];
    }

    /**
     * Get the orientation of a media
     *
     * @param MediaRepresentation $media
     * @return string 'landscape', 'portrait', 'square', or 'all'
     */
    protected function getMediaOrientation(MediaRepresentation $media)
    {
        // Try to get width and height from media data
        $width = null;
        $height = null;

        // Check for width and height in media data
        $mediaData = $media->mediaData();
        if (isset($mediaData['width']) && isset($mediaData['height'])) {
            $width = (int) $mediaData['width'];
            $height = (int) $mediaData['height'];
        }

        // If that failed, try to get the derivative and check its dimensions
        if (!$width || !$height) {
            $paths = $this->getDerivativePaths($media);
            if (isset($paths['large']) && file_exists($paths['large'])) {
                $imageSize = @getimagesize($paths['large']);
                if ($imageSize) {
                    $width = $imageSize[0];
                    $height = $imageSize[1];
                }
            }
        }

        // If still no dimensions, return 'all'
        if (!$width || !$height) {
            return 'all';
        }

        // Determine orientation
        if ($width > $height) {
            return 'landscape';
        } elseif ($height > $width) {
            return 'portrait';
        } else {
            return 'square';
        }
    }

    /**
     * Get local derivative paths for a media
     *
     * @param MediaRepresentation $media
     * @return array
     */
    protected function getDerivativePaths(MediaRepresentation $media)
    {
        $paths = [];

        // Try to get the filename from the media
        $storageId = $media->storageId();
        if (!$storageId) {
            return $paths;
        }

        // Check Omeka paths
        $basePath = OMEKA_PATH . '/files/';
        if (!is_dir($basePath)) {
            $basePath = '/var/www/html/files/';
            if (!is_dir($basePath)) {
                return $paths;
            }
        }

        // Define derivative types
        $types = ['original', 'large', 'medium', 'square'];

        // Try to find the files
        foreach ($types as $type) {
            $typePath = $basePath . $type . '/';

            // First try direct filename match
            $directPath = $typePath . $storageId;
            if (file_exists($directPath)) {
                $paths[$type] = $directPath;
                continue;
            }

            // Then try with extensions
            $extensions = ['jpg', 'jpeg', 'png', 'webp'];
            foreach ($extensions as $ext) {
                $extPath = $typePath . $storageId . '.' . $ext;
                if (file_exists($extPath)) {
                    $paths[$type] = $extPath;
                    break;
                }
            }

            // If still not found, try glob pattern
            if (!isset($paths[$type])) {
                $matches = glob($typePath . $storageId . '*');
                if (!empty($matches)) {
                    $paths[$type] = $matches[0];
                }
            }
        }

        return $paths;
    }

    /**
     * Get local file path for a media
     *
     * @param MediaRepresentation $media
     * @return string|null
     */
    protected function getLocalPathForMedia(MediaRepresentation $media)
    {
        $paths = $this->getDerivativePaths($media);

        // Prefer the original
        if (isset($paths['original'])) {
            return $paths['original'];
        }

        // Fall back to large
        if (isset($paths['large'])) {
            return $paths['large'];
        }

        // Use the first available
        return reset($paths) ?: null;
    }
}