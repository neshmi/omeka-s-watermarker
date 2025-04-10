# Implementation Notes

## Watermarking Process

### Image Processing Strategy
The module uses a layered approach to watermarking:

1. **Load Base Image**: Load the target image from Omeka S storage
2. **Load Watermark**: Load the watermark image
3. **Apply Transformations**: 
   - Scale watermark according to settings
   - Set opacity
   - Position according to configuration
4. **Merge Images**: Apply watermark to base image
5. **Save Result**: Save the watermarked derivative

### Code Example: Applying Watermark

```php
/**
 * Apply watermark to an image
 * 
 * @param string $targetImagePath Path to target image
 * @param WatermarkImageRepresentation $watermark Watermark to apply
 * @param array $options Additional options
 * @return bool Success indicator
 */
public function applyWatermark($targetImagePath, $watermark, array $options = [])
{
    // Determine image type and create appropriate resource
    $imageInfo = getimagesize($targetImagePath);
    $targetImage = $this->createImageResource($targetImagePath, $imageInfo[2]);
    
    // Load watermark image
    $watermarkPath = $this->getWatermarkPath($watermark);
    $watermarkInfo = getimagesize($watermarkPath);
    $watermarkImage = $this->createImageResource($watermarkPath, $watermarkInfo[2]);
    
    // Calculate dimensions and position
    $position = $this->calculatePosition(
        $imageInfo[0], $imageInfo[1],
        $watermarkInfo[0], $watermarkInfo[1],
        $watermark->position(),
        $watermark->marginX(),
        $watermark->marginY()
    );
    
    // Apply watermark with transparency
    $this->imageCopyMergeAlpha(
        $targetImage, $watermarkImage,
        $position['x'], $position['y'],
        0, 0,
        $watermarkInfo[0], $watermarkInfo[1],
        $watermark->opacity() * 100
    );
    
    // Save result
    return $this->saveImage($targetImage, $targetImagePath, $imageInfo[2]);
}
```

## Performance Optimization

### Derivative Caching
To avoid re-processing images:

```php
/**
 * Check if watermarked derivative exists
 * 
 * @param Media $media The media to check
 * @param string $type Derivative type
 * @return bool Whether watermarked derivative exists
 */
public function hasWatermarkedDerivative(Media $media, $type)
{
    $filename = sprintf(
        '%s.%s.%s',
        $media->getStorageId(),
        $type,
        $this->getWatermarkHash($media, $type)
    );
    
    return file_exists($this->basePath . '/' . $filename);
}
```

### Watermark Hash Generation
For cache invalidation:

```php
/**
 * Generate hash for watermark configuration
 * 
 * @param Media $media The media
 * @param string $type Derivative type
 * @return string Hash representing the watermark config
 */
private function getWatermarkHash(Media $media, $type)
{
    $settings = $this->getWatermarkSettings($media);
    $watermarkSet = $settings->getWatermarkSet();
    
    return md5(json_encode([
        'set_id' => $watermarkSet->id(),
        'modified' => $watermarkSet->modified(),
        'type' => $type,
        'settings_hash' => $settings->getConfigHash()
    ]));
}
```

## SVG Watermark Support

SVG watermarks require special handling:

```php
/**
 * Render SVG watermark to raster for application
 * 
 * @param string $svgPath Path to SVG file
 * @param int $width Target width
 * @param int $height Target height
 * @return resource GD image resource
 */
private function renderSvg($svgPath, $width, $height)
{
    // Create image of target size
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    
    // Fill with transparency
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);
    
    // Load SVG content
    $svg = file_get_contents($svgPath);
    
    // Use ImageMagick if available
    if (extension_loaded('imagick')) {
        $imagick = new \Imagick();
        $imagick->readImageBlob($svg);
        $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
        $imagick->setImageFormat('png');
        
        $tempPng = tempnam(sys_get_temp_dir(), 'svg_render');
        $imagick->writeImage($tempPng);
        
        $rasterized = imagecreatefrompng($tempPng);
        unlink($tempPng);
        
        return $rasterized;
    }
    
    // Fallback to external command if available
    // ...
}
```

## Batch Processing

For applying watermarks to existing collections:

```php
/**
 * Process all derivatives for a given item
 * 
 * @param Item $item Item to process
 * @param array $options Processing options
 * @return array Results summary
 */
public function processItem(Item $item, array $options = [])
{
    $results = [
        'processed' => 0,
        'skipped' => 0,
        'errors' => 0
    ];
    
    foreach ($item->media() as $media) {
        if (!$this->isWatermarkable($media)) {
            $results['skipped']++;
            continue;
        }
        
        $types = $options['types'] ?? ['thumbnail', 'square_thumbnail', 'medium', 'large'];
        
        foreach ($types as $type) {
            try {
                $this->processMediaDerivative($media, $type);
                $results['processed']++;
            } catch (\Exception $e) {
                $this->logger->err(sprintf(
                    'Error processing %s for media %s: %s',
                    $type,
                    $media->id(),
                    $e->getMessage()
                ));
                $results['errors']++;
            }
        }
    }
    
    return $results;
}
```

## Advanced Configuration Options

### Dynamic Watermark Selection

```php
/**
 * Select appropriate watermark based on resource context
 * 
 * @param AbstractResourceRepresentation $resource Resource
 * @param string $type Derivative type
 * @return WatermarkImageRepresentation|null Watermark to apply
 */
public function selectWatermark(AbstractResourceRepresentation $resource, $type)
{
    // Get settings for this resource
    $settings = $this->getResourceSettings($resource);
    if (!$settings || !$settings->isEnabled()) {
        return null;
    }
    
    // Check if this derivative type should be watermarked
    if (!$settings->shouldWatermark($type)) {
        return null;
    }
    
    // Get watermark set
    $watermarkSetId = $settings->getWatermarkSetId();
    $watermarkSet = $this->api->read('watermarker_sets', $watermarkSetId)->getContent();
    
    // Select specific watermark from set
    $watermarks = $watermarkSet->watermarkImages();
    
    // Use resource-specific logic to select the most appropriate watermark
    if ($resource->resourceName() === 'items' && $this->isPublicDomain($resource)) {
        // Use specific watermark for public domain items
        return $this->findWatermarkByTag($watermarks, 'public-domain');
    }
    
    // Default to first active watermark in the set
    foreach ($watermarks as $watermark) {
        if ($watermark->isActive()) {
            return $watermark;
        }
    }
    
    return null;
}
```

### Watermark Position Calculation

```php
/**
 * Calculate watermark position
 * 
 * @param int $imgWidth Target image width
 * @param int $imgHeight Target image height
 * @param int $wmWidth Watermark width
 * @param int $wmHeight Watermark height
 * @param string $position Position identifier
 * @param int $marginX
