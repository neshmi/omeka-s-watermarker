<?php
namespace Watermarker\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\ErrorStore;

class ApiController extends AbstractActionController
{
    protected $entityManager;
    protected $api;
    protected $assignmentService;

    public function __construct($entityManager, $api, $assignmentService)
    {
        $this->entityManager = $entityManager;
        $this->api = $api;
        $this->assignmentService = $assignmentService;
    }

    /**
     * Get assignment data for a resource
     */
    public function getAssignmentAction()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker API: getAssignmentAction called');

        $resourceType = $this->params()->fromQuery('resource_type');
        $resourceId = $this->params()->fromQuery('resource_id');

        if (!$resourceType || !$resourceId) {
            $logger->warn('Watermarker API: Missing resource information in getAssignmentAction');
            return new JsonModel([
                'status' => 'error',
                'message' => 'Missing resource information'
            ]);
        }

        $logger->info(sprintf('Watermarker API: Getting assignment for %s ID %s', $resourceType, $resourceId));

        // Convert resource type
        $apiResourceType = $resourceType;
        if ($resourceType === 'item') {
            $apiResourceType = 'items';
        } elseif ($resourceType === 'item-set' || $resourceType === 'item_sets') {
            $apiResourceType = 'item_sets';
        }

        try {
            // Verify resource exists
            $this->api->read($apiResourceType, $resourceId);

            // Get assignment from database directly
            $conn = $this->entityManager->getConnection();
            $stmt = $conn->prepare(
                'SELECT * FROM watermark_assignment
                 WHERE resource_type = :type AND resource_id = :id'
            );
            $stmt->bindValue('type', $apiResourceType);
            $stmt->bindValue('id', $resourceId);
            $stmt->execute();
            $assignmentData = $stmt->fetch();

            // Format assignment
            $assignment = null;
            if ($assignmentData) {
                $assignment = [
                    'id' => $assignmentData['id'],
                    'resource_type' => $assignmentData['resource_type'],
                    'resource_id' => $assignmentData['resource_id'],
                    'watermark_set_id' => $assignmentData['watermark_set_id'],
                    'explicitly_no_watermark' => (bool)$assignmentData['explicitly_no_watermark']
                ];
            }

            // Get available watermark sets
            $stmt = $conn->prepare('SELECT * FROM watermark_set WHERE enabled = 1');
            $stmt->execute();
            $sets = $stmt->fetchAll();

            // Format watermark sets
            $formattedSets = [];
            foreach ($sets as $set) {
                $formattedSets[] = [
                    'id' => $set['id'],
                    'name' => $set['name'],
                    'is_default' => (bool)$set['is_default']
                ];
            }

            $logger->info(sprintf('Watermarker API: Found %d watermark sets', count($formattedSets)));

            return new JsonModel([
                'status' => 'success',
                'assignment' => $assignment,
                'sets' => $formattedSets
            ]);
        } catch (\Exception $e) {
            $logger->error('Watermarker API: Error in getAssignmentAction: ' . $e->getMessage());
            return new JsonModel([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * List available watermark sets
     */
    public function listSetsAction()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker API: listSetsAction called');

        try {
            // Get available watermark sets directly from database
            $conn = $this->entityManager->getConnection();
            $stmt = $conn->prepare('SELECT * FROM watermark_set WHERE enabled = 1');
            $stmt->execute();
            $sets = $stmt->fetchAll();

            // Format watermark sets
            $formattedSets = [];
            foreach ($sets as $set) {
                $formattedSets[] = [
                    'id' => $set['id'],
                    'name' => $set['name'],
                    'is_default' => (bool)$set['is_default']
                ];
            }

            $logger->info(sprintf('Watermarker API: Found %d watermark sets', count($formattedSets)));

            return new JsonModel([
                'status' => 'success',
                'sets' => $formattedSets
            ]);
        } catch (\Exception $e) {
            $logger->error('Watermarker API: Error in listSetsAction: ' . $e->getMessage());
            return new JsonModel([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Set assignment for a resource
     */
    public function setAssignmentAction()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker API: setAssignmentAction called');

        if (!$this->getRequest()->isPost()) {
            $logger->warn('Watermarker API: setAssignmentAction called with non-POST request');
            return new JsonModel([
                'status' => 'error',
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // Log request details
        $logger->info('Watermarker API: Request headers: ' . json_encode($this->getRequest()->getHeaders()->toArray()));
        $logger->info('Watermarker API: Request content type: ' . $this->getRequest()->getHeader('Content-Type'));
        $logger->info('Watermarker API: Raw request content: ' . $this->getRequest()->getContent());

        // Try to get JSON data first
        $jsonData = $this->getRequest()->getContent();
        if ($jsonData) {
            try {
                $data = json_decode($jsonData, true);
                $logger->info('Watermarker API: Parsed JSON data: ' . json_encode($data));
            } catch (\Exception $e) {
                $logger->warn('Watermarker API: Failed to parse JSON data: ' . $e->getMessage());
                $data = null;
            }
        }

        // Fall back to POST data if JSON parsing failed
        if (empty($data)) {
            $data = $this->params()->fromPost();
            $logger->info('Watermarker API: POST data: ' . json_encode($data));
        }

        $resourceType = $data['resource_type'] ?? null;
        $resourceId = $data['resource_id'] ?? null;
        $watermarkSetId = $data['watermark_set_id'] ?? $data['o-watermarker:set'] ?? null;
        $explicitlyNoWatermark = (bool) ($data['explicitly_no_watermark'] ?? false);

        $logger->info(sprintf(
            'Watermarker API: Setting assignment for %s ID %s, set ID: %s, explicitly no watermark: %s',
            $resourceType,
            $resourceId,
            $watermarkSetId === null ? 'null' : $watermarkSetId,
            $explicitlyNoWatermark ? 'true' : 'false'
        ));

        if (!$resourceType || !$resourceId) {
            $logger->warn('Watermarker API: Missing resource information in setAssignmentAction');
            return new JsonModel([
                'status' => 'error',
                'message' => 'Missing resource information'
            ]);
        }

        try {
            // Direct database update to avoid API complexity
            $conn = $this->entityManager->getConnection();

            // Handle special watermark set values
            if ($watermarkSetId === 'None') {
                $watermarkSetId = null;
                $explicitlyNoWatermark = true;
            } else if ($watermarkSetId === 'Default') {
                $watermarkSetId = null;
                $explicitlyNoWatermark = false;
            }

            // Check if assignment already exists
            $stmt = $conn->prepare(
                'SELECT id FROM watermark_assignment
                 WHERE resource_type = :type AND resource_id = :id'
            );
            $stmt->bindValue('type', $resourceType);
            $stmt->bindValue('id', $resourceId);
            $stmt->execute();
            $existing = $stmt->fetch();

            $now = date('Y-m-d H:i:s');

            if ($existing) {
                // Update existing assignment
                $stmt = $conn->prepare(
                    'UPDATE watermark_assignment
                     SET watermark_set_id = :set_id,
                         explicitly_no_watermark = :no_watermark,
                         modified = :modified
                     WHERE id = :id'
                );
                $stmt->bindValue('id', $existing['id']);
                $stmt->bindValue('set_id', $watermarkSetId);
                $stmt->bindValue('no_watermark', $explicitlyNoWatermark ? 1 : 0);
                $stmt->bindValue('modified', $now);
                $stmt->execute();

                $logger->info('Watermarker API: Updated existing assignment ID ' . $existing['id']);
            } else {
                // Create new assignment
                $stmt = $conn->prepare(
                    'INSERT INTO watermark_assignment
                     (resource_type, resource_id, watermark_set_id, explicitly_no_watermark, created, modified)
                     VALUES (:type, :id, :set_id, :no_watermark, :created, :modified)'
                );
                $stmt->bindValue('type', $resourceType);
                $stmt->bindValue('id', $resourceId);
                $stmt->bindValue('set_id', $watermarkSetId);
                $stmt->bindValue('no_watermark', $explicitlyNoWatermark ? 1 : 0);
                $stmt->bindValue('created', $now);
                $stmt->bindValue('modified', $now);
                $stmt->execute();

                $logger->info('Watermarker API: Created new assignment');
            }

            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'watermark_set_id' => $watermarkSetId,
                    'explicitly_no_watermark' => $explicitlyNoWatermark,
                ]
            ]);
        } catch (\Exception $e) {
            $logger->error('Watermarker API: Error in setAssignmentAction: ' . $e->getMessage());
            return new JsonModel([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Test action to verify API accessibility
     */
    public function testAction()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker API: testAction called');

        return new JsonModel([
            'status' => 'success',
            'message' => 'API is accessible',
            'request' => [
                'method' => $this->getRequest()->getMethod(),
                'headers' => $this->getRequest()->getHeaders()->toArray(),
                'content_type' => $this->getRequest()->getHeader('Content-Type'),
            ]
        ]);
    }
}