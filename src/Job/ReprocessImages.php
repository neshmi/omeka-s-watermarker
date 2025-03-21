<?php
/**
 * Job for reprocessing all media with watermarks
 */

namespace Watermarker\Job;

use Omeka\Job\AbstractJob;
use Omeka\Api\Representation\MediaRepresentation;

class ReprocessImages extends AbstractJob
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;
    
    /**
     * @var \Watermarker\Service\WatermarkService
     */
    protected $watermarkService;
    
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;
    
    /**
     * Perform the reprocessing
     */
    public function perform()
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');
        $this->watermarkService = $services->get('Watermarker\WatermarkService');
        
        // Enable debug mode for detailed logging
        if (method_exists($this->watermarkService, 'setDebugMode')) {
            $this->watermarkService->setDebugMode(true);
        }
        
        // Get job arguments
        $args = $this->getArg('args', []);
        $itemId = isset($args['item_id']) ? $args['item_id'] : null;
        $mediaId = isset($args['media_id']) ? $args['media_id'] : null;
        $limit = isset($args['limit']) ? (int)$args['limit'] : 100;
        $offset = isset($args['offset']) ? (int)$args['offset'] : 0;
        
        $this->logger->info('Starting watermark reprocessing job');
        $this->logger->info(sprintf(
            'Parameters: item_id=%s, media_id=%s, limit=%d, offset=%d',
            $itemId, $mediaId, $limit, $offset
        ));
        
        // Set up search criteria
        $criteria = [
            'limit' => $limit,
            'offset' => $offset,
            'sort_by' => 'id',
            'sort_order' => 'asc',
        ];
        
        // If specific item provided, only process media from that item
        if ($itemId) {
            $criteria['item_id'] = $itemId;
        }
        
        // If specific media provided, only process that media
        if ($mediaId) {
            $criteria = ['id' => $mediaId];
        }
        
        try {
            // Search for media to reprocess
            $response = $this->api->search('media', $criteria);
            $totalToProcess = $response->getTotalResults();
            $mediaList = $response->getContent();
            
            $this->logger->info(sprintf('Found %d media items to process', $totalToProcess));
            
            $processed = 0;
            $success = 0;
            $failed = 0;
            
            // Process each media
            foreach ($mediaList as $media) {
                $processed++;
                
                $this->logger->info(sprintf(
                    'Processing media %d/%d: ID %s (%s)',
                    $processed, count($mediaList), $media->id(), $media->mediaType()
                ));
                
                // Skip non-image media
                if (!$this->watermarkService->isWatermarkable($media)) {
                    $this->logger->info(sprintf(
                        'Skipping non-watermarkable media: %s (type: %s)',
                        $media->id(), $media->mediaType()
                    ));
                    continue;
                }
                
                // Apply watermark
                try {
                    $result = $this->watermarkService->processMedia($media);
                    
                    if ($result) {
                        $success++;
                        $this->logger->info(sprintf(
                            'Successfully watermarked media ID: %s',
                            $media->id()
                        ));
                    } else {
                        $failed++;
                        $this->logger->warn(sprintf(
                            'Failed to watermark media ID: %s',
                            $media->id()
                        ));
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->err(sprintf(
                        'Error watermarking media ID %s: %s',
                        $media->id(), $e->getMessage()
                    ));
                }
                
                // Check if job should stop
                if ($this->shouldStop()) {
                    $this->logger->warn('Job stopped before completion');
                    break;
                }
            }
            
            $this->logger->info(sprintf(
                'Reprocessing job completed. Total: %d, Success: %d, Failed: %d',
                $processed, $success, $failed
            ));
            
        } catch (\Exception $e) {
            $this->logger->err(sprintf('Error in reprocessing job: %s', $e->getMessage()));
            $this->logger->err($e->getTraceAsString());
        }
    }
}
