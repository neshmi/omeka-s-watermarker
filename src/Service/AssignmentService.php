<?php
namespace Watermarker\Service;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Omeka\Api\Manager as ApiManager;
use Watermarker\Entity\WatermarkAssignment;
use Watermarker\Entity\WatermarkSet;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Interop\Container\ContainerInterface;

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
    protected $api;

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
     * @param ApiManager $api
     * @param LoggerInterface $logger
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(
        EntityManager $entityManager,
        ApiManager $api,
        LoggerInterface $logger,
        ContainerInterface $serviceLocator
    ) {
        $this->entityManager = $entityManager;
        $this->api = $api;
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
     * Set a watermark assignment for a resource
     *
     * @param string $resourceType Item, item-set, or media
     * @param int $resourceId The ID of the resource
     * @param int|null $watermarkSetId Watermark set ID or null to use default
     * @param bool $explicitlyNoWatermark If true, no watermark will be applied
     * @return bool|null True on success, false on failure, null if no changes
     */
    public function setAssignment($resourceType, $resourceId, $watermarkSetId = null, $explicitlyNoWatermark = false)
    {
        // Map resource types to API names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            // Allow direct API names too
            'items' => 'items',
            'item_sets' => 'item_sets',
        ];

        if (!isset($resourceTypeMap[$resourceType])) {
            $this->logger->err("Invalid resource type: {$resourceType}");
            throw new \InvalidArgumentException("Invalid resource type");
        }

        $apiResourceType = $resourceTypeMap[$resourceType];

        // Check if resource exists
        try {
            $this->api->read($apiResourceType, $resourceId);
        } catch (\Exception $e) {
            $this->logger->err("Resource not found: {$apiResourceType} {$resourceId}");
            throw new \InvalidArgumentException("Resource not found");
        }

        // Handle explicitly no watermark with set ID
        if ($explicitlyNoWatermark && $watermarkSetId) {
            $watermarkSetId = null;
        }

        // Check watermark set exists if set
        if ($watermarkSetId) {
            try {
                $watermarkSet = $this->api->read('watermark_sets', $watermarkSetId)->getContent();
                if (!$watermarkSet->enabled()) {
                    $this->logger->err("Watermark set is disabled: {$watermarkSetId}");
                    throw new \InvalidArgumentException("Watermark set is disabled");
                }
            } catch (\Exception $e) {
                $this->logger->err("Watermark set not found: {$watermarkSetId}");
                throw new \InvalidArgumentException("Watermark set not found");
            }
        }

        // Find existing assignment
        $assignments = $this->api->search('watermark_assignments', [
            'resource_type' => $apiResourceType,
            'resource_id' => $resourceId,
        ])->getContent();

        // If removing to default and no assignment exists
        if (!$watermarkSetId && !$explicitlyNoWatermark && count($assignments) === 0) {
            return null; // No changes needed
        }

        // If no assignment, no watermark set, and not explicitly no watermark
        if (count($assignments) === 0 && !$watermarkSetId && !$explicitlyNoWatermark) {
            return null; // No changes needed
        }

        // If removing to default and assignment exists
        if (!$watermarkSetId && !$explicitlyNoWatermark && count($assignments) > 0) {
            $assignment = $assignments[0];
            try {
                $this->api->delete('watermark_assignments', $assignment->id());
                return true;
            } catch (\Exception $e) {
                $this->logger->err("Failed to delete assignment: " . $e->getMessage());
                return false;
            }
        }

        // Create or update assignment
        $assignmentData = [
            'o:resource_type' => $apiResourceType,
            'o:resource_id' => $resourceId,
            'o:explicitly_no_watermark' => (bool) $explicitlyNoWatermark,
        ];

        if ($watermarkSetId) {
            $assignmentData['o:watermark_set'] = $watermarkSetId;
        }

        try {
            if (count($assignments) > 0) {
                $assignment = $assignments[0];
                $this->api->update('watermark_assignments', $assignment->id(), $assignmentData);
            } else {
                $this->api->create('watermark_assignments', $assignmentData);
            }
            return true;
        } catch (\Exception $e) {
            $this->logger->err("Failed to save assignment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the applicable watermark set for a resource
     *
     * @param string $resourceType
     * @param int $resourceId
     * @return \Watermarker\Api\Representation\WatermarkSetRepresentation|null
     */
    public function getWatermarkSet($resourceType, $resourceId)
    {
        // Map resource types to API names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            // Allow direct API names too
            'items' => 'items',
            'item_sets' => 'item_sets',
        ];

        if (!isset($resourceTypeMap[$resourceType])) {
            $this->logger->err("Invalid resource type: {$resourceType}");
            return null;
        }

        $apiResourceType = $resourceTypeMap[$resourceType];

        try {
            // Check for direct assignment to this resource
            $assignments = $this->api->search('watermark_assignments', [
                'resource_type' => $apiResourceType,
                'resource_id' => $resourceId,
            ])->getContent();

            if (count($assignments) > 0) {
                $assignment = $assignments[0];

                // If explicitly no watermark, return null
                if ($assignment->explicitlyNoWatermark()) {
                    return null;
                }

                // If has watermark set, return it
                if ($assignment->watermarkSet()) {
                    return $assignment->watermarkSet();
                }
            }

            // For media, check parent item
            if ($apiResourceType === 'media') {
                $media = $this->api->read('media', $resourceId)->getContent();
                $itemId = $media->item()->id();
                return $this->getWatermarkSet('item', $itemId);
            }

            // For item, check parent item set
            if ($apiResourceType === 'items') {
                $item = $this->api->read('items', $resourceId)->getContent();
                $itemSets = $item->itemSets();

                // Check each item set, use first that has a watermark
                foreach ($itemSets as $itemSet) {
                    $watermarkSet = $this->getWatermarkSet('item_sets', $itemSet->id());
                    if ($watermarkSet) {
                        return $watermarkSet;
                    }
                }
            }

            // If no assignment found, use default
            $defaultSets = $this->api->search('watermark_sets', [
                'is_default' => true,
                'enabled' => true,
            ])->getContent();

            if (count($defaultSets) > 0) {
                return $defaultSets[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->err("Error getting watermark set: " . $e->getMessage());
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