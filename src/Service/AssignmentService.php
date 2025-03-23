<?php
namespace Watermarker\Service;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Omeka\Api\Manager as ApiManager;
use Watermarker\Entity\WatermarkAssignment;
use Watermarker\Entity\WatermarkSet;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Service for managing watermark assignments to resources
 */
class AssignmentService
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var ApiManager
     */
    protected $apiManager;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Constructor
     *
     * @param EntityManager $entityManager
     * @param ApiManager $apiManager
     * @param LoggerInterface $logger
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(EntityManager $entityManager, ApiManager $apiManager, LoggerInterface $logger, ServiceLocatorInterface $serviceLocator)
    {
        $this->entityManager = $entityManager;
        $this->apiManager = $apiManager;
        $this->logger = $logger;
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * Get the API manager
     *
     * @return \Omeka\Api\Manager
     */
    protected function api()
    {
        return $this->serviceLocator->get('Omeka\ApiManager');
    }

    /**
     * Get current watermark assignment for a resource
     *
     * @param string $resourceType
     * @param int $resourceId
     * @return \Watermarker\Api\Representation\WatermarkAssignmentRepresentation|null
     */
    public function getAssignment($resourceType, $resourceId)
    {
        // Convert resource type to API format
        $apiResourceType = $this->normalizeResourceType($resourceType);

        // Search for assignment
        try {
            $response = $this->api()->search('watermark_assignments', [
                'resource_type' => $apiResourceType,
                'resource_id' => $resourceId,
            ]);

            $assignments = $response->getContent();
            if (count($assignments) > 0) {
                return $assignments[0];
            }
        } catch (\Exception $e) {
            error_log('Error fetching watermark assignment: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get default watermark set
     *
     * @return \Watermarker\Api\Representation\WatermarkSetRepresentation|null
     */
    public function getDefaultWatermarkSet()
    {
        try {
            $response = $this->api()->search('watermark_sets', [
                'is_default' => true,
                'enabled' => true,
            ]);

            $sets = $response->getContent();
            if (count($sets) > 0) {
                return $sets[0];
            }
        } catch (\Exception $e) {
            error_log('Error fetching default watermark set: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Normalize resource type to API format
     *
     * @param string $resourceType
     * @return string
     */
    protected function normalizeResourceType($resourceType)
    {
        switch ($resourceType) {
            case 'item':
                return 'items';
            case 'item-set':
                return 'item_sets';
            case 'media':
                return 'media';
            default:
                return $resourceType;
        }
    }

    /**
     * Create or update a watermark assignment
     *
     * @param string $resourceType
     * @param int $resourceId
     * @param int|null $watermarkSetId
     * @param bool $explicitlyNoWatermark
     * @return \Watermarker\Api\Representation\WatermarkAssignmentRepresentation|null
     */
    public function setAssignment($resourceType, $resourceId, $watermarkSetId = null, $explicitlyNoWatermark = false)
    {
        // Convert resource type to API format
        $apiResourceType = $this->normalizeResourceType($resourceType);

        // First check if an assignment exists
        $existingAssignment = $this->getAssignment($resourceType, $resourceId);

        // Prepare assignment data
        $data = [
            'o:resource_type' => $apiResourceType,
            'o:resource_id' => $resourceId,
            'o:explicitly_no_watermark' => $explicitlyNoWatermark,
        ];

        if ($watermarkSetId) {
            $data['o:watermark_set'] = ['o:id' => $watermarkSetId];
        }

        // Update or create the assignment
        try {
            if ($existingAssignment) {
                // For update or remove
                if (!$watermarkSetId && !$explicitlyNoWatermark) {
                    // Remove assignment
                    $this->api()->delete('watermark_assignments', $existingAssignment->id());
                    return null;
                } else {
                    // Update assignment
                    $response = $this->api()->update(
                        'watermark_assignments',
                        $existingAssignment->id(),
                        $data
                    );
                    return $response->getContent();
                }
            } else {
                // No need to create an assignment to use default
                if (!$watermarkSetId && !$explicitlyNoWatermark) {
                    return null;
                }

                // Create new assignment
                $response = $this->api()->create('watermark_assignments', $data);
                return $response->getContent();
            }
        } catch (\Exception $e) {
            error_log('Error setting watermark assignment: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the effective watermark set for a resource, considering inheritance
     *
     * @param string $resourceType item, itemSet, or media
     * @param int $resourceId
     * @return WatermarkSet|null
     */
    public function getEffectiveWatermark($resourceType, $resourceId)
    {
        // Normalize resource type
        $resourceType = $this->normalizeResourceType($resourceType);

        // First check for direct assignment
        $assignment = $this->getAssignment($resourceType, $resourceId);

        if ($assignment) {
            // If explicitly set to no watermark, return null
            if ($assignment->getWatermarkSetId() === null) {
                return null;
            }

            // Found direct assignment with watermark set
            return $assignment->getWatermarkSet();
        }

        // No direct assignment, check parent if applicable
        if ($resourceType === 'item') {
            // Item inherits from its item set
            try {
                $response = $this->apiManager->read('items', $resourceId);
                $item = $response->getContent();

                // Check if item belongs to an item set
                $itemSets = $item->itemSets();
                if (count($itemSets) > 0) {
                    // Use the first item set (could enhance to check multiple sets)
                    $itemSet = $itemSets[0];
                    return $this->getEffectiveWatermark('itemSet', $itemSet->id());
                }
            } catch (\Exception $e) {
                $this->logger->err(sprintf(
                    'Error checking parent for item #%d: %s',
                    $resourceId,
                    $e->getMessage()
                ));
            }
        }
        elseif ($resourceType === 'media') {
            // Media inherits from its item
            try {
                $response = $this->apiManager->read('media', $resourceId);
                $media = $response->getContent();

                // Get the parent item
                $item = $media->item();
                if ($item) {
                    return $this->getEffectiveWatermark('item', $item->id());
                }
            } catch (\Exception $e) {
                $this->logger->err(sprintf(
                    'Error checking parent for media #%d: %s',
                    $resourceId,
                    $e->getMessage()
                ));
            }
        }

        // No assignment or inherited assignment
        return null;
    }
}