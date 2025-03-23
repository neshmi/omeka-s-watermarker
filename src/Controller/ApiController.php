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
        $resourceType = $this->params()->fromQuery('resource_type');
        $resourceId = $this->params()->fromQuery('resource_id');

        if (!$resourceType || !$resourceId) {
            return new JsonModel([
                'status' => 'error',
                'message' => 'Missing resource information'
            ]);
        }

        // Convert resource type
        $apiResourceType = $resourceType;
        if ($resourceType === 'item') {
            $apiResourceType = 'items';
        } elseif ($resourceType === 'item-set') {
            $apiResourceType = 'item_sets';
        }

        try {
            // Verify resource exists
            $this->api->read($apiResourceType, $resourceId);

            // Get assignment
            $response = $this->api->search('watermark_assignments', [
                'resource_type' => $apiResourceType,
                'resource_id' => $resourceId,
            ]);
            $assignments = $response->getContent();
            $assignment = count($assignments) > 0 ? $assignments[0] : null;

            // Get available watermark sets
            $response = $this->api->search('watermark_sets', ['enabled' => true]);
            $watermarkSets = $response->getContent();
            $formattedSets = [];
            foreach ($watermarkSets as $set) {
                $formattedSets[] = [
                    'id' => $set->id(),
                    'name' => $set->name(),
                    'is_default' => $set->isDefault()
                ];
            }

            // Get default watermark set
            $response = $this->api->search('watermark_sets', [
                'is_default' => true,
                'enabled' => true,
            ]);
            $defaultSets = $response->getContent();
            $defaultSet = count($defaultSets) > 0 ? $defaultSets[0]->getJsonLd() : null;

            // Format data
            $data = [
                'assignment' => $assignment ? $assignment->getJsonLd() : null,
                'watermark_sets' => $formattedSets,
                'default_set' => $defaultSet,
            ];

            return new JsonModel([
                'status' => 'success',
                'data' => $data
            ]);
        } catch (\Exception $e) {
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
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'status' => 'error',
                'message' => 'Only POST requests are allowed'
            ]);
        }

        $data = $this->params()->fromPost();
        $resourceType = $data['resource_type'] ?? null;
        $resourceId = $data['resource_id'] ?? null;
        $watermarkSetId = $data['watermark_set_id'] ?? null;
        $explicitlyNoWatermark = (bool) ($data['explicitly_no_watermark'] ?? false);

        if (!$resourceType || !$resourceId) {
            return new JsonModel([
                'status' => 'error',
                'message' => 'Missing resource information'
            ]);
        }

        try {
            $result = $this->assignmentService->setAssignment(
                $resourceType,
                $resourceId,
                $watermarkSetId,
                $explicitlyNoWatermark
            );

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
            return new JsonModel([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get list of watermark sets
     */
    public function getWatermarkSetsAction()
    {
        try {
            // Get watermark sets
            $response = $this->api->search('watermark_sets', ['enabled' => true]);
            $watermarkSets = $response->getContent();
            $formattedSets = [];

            foreach ($watermarkSets as $set) {
                $formattedSets[] = [
                    'id' => $set->id(),
                    'name' => $set->name(),
                    'is_default' => $set->isDefault()
                ];
            }

            return new JsonModel([
                'status' => 'success',
                'data' => $formattedSets
            ]);
        } catch (\Exception $e) {
            return new JsonModel([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
}