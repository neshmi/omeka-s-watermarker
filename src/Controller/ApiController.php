<?php
namespace Watermarker\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\ErrorStore;

class ApiController extends AbstractActionController
{
    /**
     * Get watermark assignment for a resource
     */
    public function getAssignmentAction()
    {
        $resourceType = $this->params()->fromQuery('resource_type');
        $resourceId = $this->params()->fromQuery('resource_id');

        if (!$resourceType || !$resourceId) {
            return $this->returnError('Missing resource type or ID');
        }

        // Map resource types to API endpoint names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            // Also allow direct API names
            'items' => 'items',
            'item_sets' => 'item_sets',
        ];

        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->returnError('Invalid resource type');
        }

        $apiResourceType = $resourceTypeMap[$resourceType];

        $api = $this->api();

        // First check if the resource exists
        try {
            $resource = $api->read($apiResourceType, $resourceId)->getContent();
        } catch (\Exception $e) {
            return $this->returnError('Resource not found');
        }

        // Find watermark assignment
        $assignments = $api->search('watermark_assignments', [
            'resource_type' => $apiResourceType,
            'resource_id' => $resourceId,
        ])->getContent();

        if (count($assignments) > 0) {
            $assignment = $assignments[0];
            return new JsonModel([
                'success' => true,
                'assignment' => $assignment->getJsonLd(),
            ]);
        }

        // If no direct assignment, get the default
        $watermarkSets = $api->search('watermark_sets', [
            'is_default' => true,
            'enabled' => true,
        ])->getContent();

        if (count($watermarkSets) > 0) {
            $defaultSet = $watermarkSets[0];
            return new JsonModel([
                'success' => true,
                'default' => true,
                'assignment' => [
                    'o:resource_type' => $apiResourceType,
                    'o:resource_id' => $resourceId,
                    'o:watermark_set' => $defaultSet->getReference(),
                    'o:explicitly_no_watermark' => false,
                ],
            ]);
        }

        // No assignment and no default
        return new JsonModel([
            'success' => true,
            'no_watermark' => true,
            'assignment' => [
                'o:resource_type' => $apiResourceType,
                'o:resource_id' => $resourceId,
                'o:watermark_set' => null,
                'o:explicitly_no_watermark' => false,
            ],
        ]);
    }

    /**
     * Set watermark assignment for a resource
     */
    public function setAssignmentAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->returnError('Method not allowed', 405);
        }

        $data = $this->params()->fromPost();
        $resourceType = $data['resource_type'] ?? null;
        $resourceId = $data['resource_id'] ?? null;
        $watermarkSetId = $data['watermark_set_id'] ?? null;
        $explicitlyNoWatermark = (bool) ($data['explicitly_no_watermark'] ?? false);

        if (!$resourceType || !$resourceId) {
            return $this->returnError('Missing resource type or ID');
        }

        // Map resource types to API endpoint names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            // Also allow direct API names
            'items' => 'items',
            'item_sets' => 'item_sets',
        ];

        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->returnError('Invalid resource type');
        }

        $apiResourceType = $resourceTypeMap[$resourceType];

        $api = $this->api();

        // First check if the resource exists
        try {
            $resource = $api->read($apiResourceType, $resourceId)->getContent();
        } catch (\Exception $e) {
            return $this->returnError('Resource not found');
        }

        // Check if watermark set exists if provided
        $watermarkSet = null;
        if ($watermarkSetId && !$explicitlyNoWatermark) {
            try {
                $watermarkSet = $api->read('watermark_sets', $watermarkSetId)->getContent();
                if (!$watermarkSet->enabled()) {
                    return $this->returnError('Watermark set is disabled');
                }
            } catch (\Exception $e) {
                return $this->returnError('Watermark set not found');
            }
        }

        // Find existing assignment
        $assignments = $api->search('watermark_assignments', [
            'resource_type' => $apiResourceType,
            'resource_id' => $resourceId,
        ])->getContent();

        $assignmentData = [
            'o:resource_type' => $apiResourceType,
            'o:resource_id' => $resourceId,
        ];

        if ($watermarkSetId && !$explicitlyNoWatermark) {
            $assignmentData['o:watermark_set'] = ['o:id' => $watermarkSetId];
            $assignmentData['o:explicitly_no_watermark'] = false;
        } elseif ($explicitlyNoWatermark) {
            $assignmentData['o:watermark_set'] = null;
            $assignmentData['o:explicitly_no_watermark'] = true;
        } else {
            // Remove assignment if no watermark set and not explicitly no watermark
            if (count($assignments) > 0) {
                $assignment = $assignments[0];
                $api->delete('watermark_assignments', $assignment->id());

                return new JsonModel([
                    'success' => true,
                    'message' => 'Watermark assignment removed',
                ]);
            }

            return new JsonModel([
                'success' => true,
                'message' => 'No changes made',
            ]);
        }

        // Update or create assignment
        if (count($assignments) > 0) {
            $assignment = $assignments[0];
            $response = $api->update('watermark_assignments', $assignment->id(), $assignmentData);
        } else {
            $response = $api->create('watermark_assignments', $assignmentData);
        }

        if ($response) {
            return new JsonModel([
                'success' => true,
                'assignment' => $response->getContent()->getJsonLd(),
            ]);
        } else {
            return $this->returnError('Failed to save assignment');
        }
    }

    /**
     * Get all watermark sets
     */
    public function getWatermarkSetsAction()
    {
        $api = $this->api();
        $enabledOnly = (bool) $this->params()->fromQuery('enabled_only', true);

        $query = [];
        if ($enabledOnly) {
            $query['enabled'] = true;
        }

        $watermarkSets = $api->search('watermark_sets', $query)->getContent();

        $sets = [];
        foreach ($watermarkSets as $set) {
            $sets[] = $set->getJsonLd();
        }

        return new JsonModel([
            'success' => true,
            'sets' => $sets,
        ]);
    }

    /**
     * Return an error response
     */
    protected function returnError($message, $statusCode = 400)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);

        return new JsonModel([
            'success' => false,
            'error' => $message,
        ]);
    }
}