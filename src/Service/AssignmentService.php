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
     * @return array|null Assignment data array or null if not found
     */
    public function getAssignment($resourceType, $resourceId)
    {
        // Convert resource type to API format
        $apiResourceType = $this->normalizeResourceType($resourceType);

        // Search for assignment using direct database query
        try {
            $connection = $this->entityManager->getConnection();
            $stmt = $connection->prepare('
                SELECT a.*, s.name as watermark_set_name, s.enabled as watermark_set_enabled
                FROM watermark_assignment a
                LEFT JOIN watermark_set s ON a.watermark_set_id = s.id
                WHERE a.resource_type = :resource_type AND a.resource_id = :resource_id
                LIMIT 1
            ');
            $stmt->bindValue('resource_type', $apiResourceType);
            $stmt->bindValue('resource_id', $resourceId);
            $stmt->execute();
            $assignment = $stmt->fetch();

            if ($assignment) {
                return $assignment;
            }
        } catch (\Exception $e) {
            $this->logger->err('Error fetching watermark assignment: ' . $e->getMessage());
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
     * @param string|int|null $watermarkSetId Watermark set ID, 'none', 'default', or null
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

        // Get database connection
        $connection = $this->entityManager->getConnection();

        // Handle special watermark set values
        if ($watermarkSetId === 'none' || $watermarkSetId === 'None') {
            $watermarkSetId = null;
            $explicitlyNoWatermark = true;
        } elseif ($watermarkSetId === 'default' || $watermarkSetId === 'Default') {
            $watermarkSetId = null;
            $explicitlyNoWatermark = false;
        } elseif ($watermarkSetId !== null) {
            // Only try to validate if we have a non-null watermark set ID
            // Convert string ID to integer
            $watermarkSetId = (int) $watermarkSetId;
            $explicitlyNoWatermark = false;

            // Check watermark set exists and is enabled using direct database query
            $stmt = $connection->prepare('SELECT id, enabled FROM watermark_set WHERE id = :id');
            $stmt->bindValue('id', $watermarkSetId);
            $stmt->execute();
            $set = $stmt->fetch();

            if (!$set) {
                $this->logger->err("Watermark set not found: {$watermarkSetId}");
                throw new \InvalidArgumentException("Watermark set not found");
            }

            if (!$set['enabled']) {
                $this->logger->err("Watermark set is disabled: {$watermarkSetId}");
                throw new \InvalidArgumentException("Watermark set is disabled");
            }
        }

        // Find existing assignment using direct database query
        $stmt = $connection->prepare('SELECT id, watermark_set_id, explicitly_no_watermark FROM watermark_assignment WHERE resource_type = :resource_type AND resource_id = :resource_id');
        $stmt->bindValue('resource_type', $apiResourceType);
        $stmt->bindValue('resource_id', $resourceId);
        $stmt->execute();
        $assignment = $stmt->fetch();

        // If removing to default and no assignment exists
        if (!$watermarkSetId && !$explicitlyNoWatermark && !$assignment) {
            return null; // No changes needed
        }

        // If no assignment, no watermark set, and not explicitly no watermark
        if (!$assignment && !$watermarkSetId && !$explicitlyNoWatermark) {
            return null; // No changes needed
        }

        // If removing to default and assignment exists
        if (!$watermarkSetId && !$explicitlyNoWatermark && $assignment) {
            try {
                $stmt = $connection->prepare('DELETE FROM watermark_assignment WHERE id = :id');
                $stmt->bindValue('id', $assignment['id']);
                $stmt->execute();
                return true;
            } catch (\Exception $e) {
                $this->logger->err("Failed to delete assignment: " . $e->getMessage());
                return false;
            }
        }

        // Create or update assignment
        try {
            $now = new \DateTime('now');
            $timestamp = $now->format('Y-m-d H:i:s');

            if ($assignment) {
                // Update existing assignment
                $stmt = $connection->prepare('
                    UPDATE watermark_assignment
                    SET watermark_set_id = :watermark_set_id,
                        explicitly_no_watermark = :explicitly_no_watermark,
                        modified = :modified
                    WHERE id = :id
                ');
                $stmt->bindValue('id', $assignment['id']);
                $stmt->bindValue('watermark_set_id', $watermarkSetId);
                $stmt->bindValue('explicitly_no_watermark', $explicitlyNoWatermark ? 1 : 0);
                $stmt->bindValue('modified', $timestamp);
                $stmt->execute();
            } else {
                // Create new assignment
                $stmt = $connection->prepare('
                    INSERT INTO watermark_assignment
                    (resource_type, resource_id, watermark_set_id, explicitly_no_watermark, created)
                    VALUES (:resource_type, :resource_id, :watermark_set_id, :explicitly_no_watermark, :created)
                ');
                $stmt->bindValue('resource_type', $apiResourceType);
                $stmt->bindValue('resource_id', $resourceId);
                $stmt->bindValue('watermark_set_id', $watermarkSetId);
                $stmt->bindValue('explicitly_no_watermark', $explicitlyNoWatermark ? 1 : 0);
                $stmt->bindValue('created', $timestamp);
                $stmt->execute();
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
     * @return array|null Watermark set data or null
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
        $connection = $this->entityManager->getConnection();

        try {
            // Check for direct assignment to this resource
            $stmt = $connection->prepare('
                SELECT a.*, s.*
                FROM watermark_assignment a
                LEFT JOIN watermark_set s ON a.watermark_set_id = s.id
                WHERE a.resource_type = :resource_type AND a.resource_id = :resource_id
                LIMIT 1
            ');
            $stmt->bindValue('resource_type', $apiResourceType);
            $stmt->bindValue('resource_id', $resourceId);
            $stmt->execute();
            $assignment = $stmt->fetch();

            if ($assignment) {
                // If explicitly no watermark, return null
                if ($assignment['explicitly_no_watermark']) {
                    return null;
                }

                // If has watermark set, return the watermark set data
                if ($assignment['watermark_set_id']) {
                    $stmt = $connection->prepare('SELECT * FROM watermark_set WHERE id = :id');
                    $stmt->bindValue('id', $assignment['watermark_set_id']);
                    $stmt->execute();
                    return $stmt->fetch();
                }
            }

            // For media, check parent item
            if ($apiResourceType === 'media') {
                $stmt = $connection->prepare('SELECT item_id FROM media WHERE id = :id');
                $stmt->bindValue('id', $resourceId);
                $stmt->execute();
                $media = $stmt->fetch();

                if ($media && isset($media['item_id'])) {
                    return $this->getWatermarkSet('items', $media['item_id']);
                }
            }

            // For item, check parent item set
            if ($apiResourceType === 'items') {
                $stmt = $connection->prepare('
                    SELECT item_set_id
                    FROM item_item_set
                    WHERE item_id = :item_id
                ');
                $stmt->bindValue('item_id', $resourceId);
                $stmt->execute();
                $itemSets = $stmt->fetchAll();

                // Check each item set, use first that has a watermark
                foreach ($itemSets as $itemSet) {
                    $watermarkSet = $this->getWatermarkSet('item_sets', $itemSet['item_set_id']);
                    if ($watermarkSet) {
                        return $watermarkSet;
                    }
                }
            }

            // If no assignment found, use default
            $stmt = $connection->prepare('
                SELECT * FROM watermark_set
                WHERE is_default = 1 AND enabled = 1
                LIMIT 1
            ');
            $stmt->execute();
            return $stmt->fetch();
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
     * @return array|null Watermark set data or null
     */
    public function getEffectiveWatermark($resourceType, $resourceId)
    {
        // Normalize resource type
        $resourceType = $this->normalizeResourceType($resourceType);

        // First check for direct assignment
        $assignment = $this->getAssignment($resourceType, $resourceId);

        if ($assignment) {
            // If explicitly set to no watermark, return null
            if ($assignment['explicitly_no_watermark']) {
                return null;
            }

            // If has watermark set, return the set info
            if ($assignment['watermark_set_id']) {
                // Get the watermark set details
                $connection = $this->entityManager->getConnection();
                $stmt = $connection->prepare('SELECT * FROM watermark_set WHERE id = :id');
                $stmt->bindValue('id', $assignment['watermark_set_id']);
                $stmt->execute();
                return $stmt->fetch();
            }
        }

        // No direct assignment, check parent if applicable
        if ($resourceType === 'items') {
            // Item inherits from its item set
            try {
                $connection = $this->entityManager->getConnection();
                $stmt = $connection->prepare('
                    SELECT item_set_id FROM item_item_set WHERE item_id = :item_id LIMIT 1
                ');
                $stmt->bindValue('item_id', $resourceId);
                $stmt->execute();
                $result = $stmt->fetch();

                if ($result && isset($result['item_set_id'])) {
                    return $this->getEffectiveWatermark('item_sets', $result['item_set_id']);
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
                $connection = $this->entityManager->getConnection();
                $stmt = $connection->prepare('SELECT item_id FROM media WHERE id = :id');
                $stmt->bindValue('id', $resourceId);
                $stmt->execute();
                $result = $stmt->fetch();

                if ($result && isset($result['item_id'])) {
                    return $this->getEffectiveWatermark('items', $result['item_id']);
                }
            } catch (\Exception $e) {
                $this->logger->err(sprintf(
                    'Error checking parent for media #%d: %s',
                    $resourceId,
                    $e->getMessage()
                ));
            }
        }

        // No assignment or inherited assignment, try to get default watermark set
        try {
            $connection = $this->entityManager->getConnection();
            $stmt = $connection->prepare('
                SELECT * FROM watermark_set
                WHERE is_default = 1 AND enabled = 1
                LIMIT 1
            ');
            $stmt->execute();
            return $stmt->fetch();
        } catch (\Exception $e) {
            $this->logger->err('Error getting default watermark set: ' . $e->getMessage());
        }

        // No default watermark set found
        return null;
    }
}