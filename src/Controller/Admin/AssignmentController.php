<?php
namespace Watermarker\Controller\Admin;

use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Manager as ApiManager;
use Omeka\Stdlib\Message;
use Watermarker\Service\AssignmentService;

class AssignmentController extends AbstractActionController
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
     * @var AssignmentService
     */
    protected $assignmentService;

    /**
     * Constructor
     *
     * @param EntityManager $entityManager
     * @param ApiManager $api
     * @param AssignmentService $assignmentService
     */
    public function __construct(
        EntityManager $entityManager,
        ApiManager $api,
        AssignmentService $assignmentService
    ) {
        $this->entityManager = $entityManager;
        $this->api = $api;
        $this->assignmentService = $assignmentService;
    }

    /**
     * Action to assign a watermark set to a resource
     */
    public function assignAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $data = $this->params()->fromPost();

        // Check required parameters
        if (empty($data['resource_id']) || empty($data['resource_type'])) {
            return new JsonModel([
                'success' => false,
                'error' => 'Missing required parameters',
            ]);
        }

        $resourceId = $data['resource_id'];
        $resourceType = $data['resource_type'];
        $watermarkSetId = isset($data['watermark_set_id']) ? $data['watermark_set_id'] : null;

        // Validate resource exists
        try {
            if ($resourceType === 'item-set') {
                $this->api->read('item_sets', $resourceId);
            } elseif ($resourceType === 'item') {
                $this->api->read('items', $resourceId);
            } elseif ($resourceType === 'media') {
                $this->api->read('media', $resourceId);
            } else {
                return new JsonModel([
                    'success' => false,
                    'error' => 'Invalid resource type',
                ]);
            }
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'error' => 'Resource not found',
            ]);
        }

        // Handle the assignment
        try {
            if (empty($watermarkSetId) || $watermarkSetId === '') {
                // Explicitly set no watermark
                $this->assignmentService->assignNoWatermark($resourceType, $resourceId);
                $message = 'No watermark will be applied to this resource';
            } elseif ($watermarkSetId === 'default') {
                // Remove any explicit assignment (inherit from parent)
                $this->assignmentService->removeAssignment($resourceType, $resourceId);
                $message = 'This resource will inherit watermark settings from its parent';
            } else {
                // Assign the specified watermark set
                $this->assignmentService->assignWatermarkSet($resourceType, $resourceId, $watermarkSetId);

                // Get the watermark set name for the message
                try {
                    $watermarkSet = $this->api->read('watermark_sets', $watermarkSetId)->getContent();
                    $setName = $watermarkSet->getName();
                    $message = sprintf('Assigned watermark set "%s" to this resource', $setName);
                } catch (\Exception $e) {
                    $message = 'Assigned watermark set to this resource';
                }
            }

            return new JsonModel([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Action to get watermark information for a resource
     */
    public function infoAction()
    {
        $resourceType = $this->params()->fromRoute('resource-type');
        $resourceId = $this->params()->fromRoute('resource-id');

        if (!$resourceType || !$resourceId) {
            return new JsonModel([
                'success' => false,
                'error' => 'Missing resource type or ID',
            ]);
        }

        // Normalize resource type
        if ($resourceType === 'item-set') {
            $resourceType = 'itemSet';
        }

        try {
            // Get current watermark assignment
            $assignment = $this->assignmentService->getAssignment($resourceType, $resourceId);

            // Prepare response data
            $data = [
                'success' => true,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'status' => null,
            ];

            if ($assignment && $assignment->getWatermarkSetId() === null) {
                // Explicitly set to no watermark
                $data['explicitly_no_watermark'] = true;
                $data['watermark_set_id'] = null;
                $data['status'] = 'No watermark will be applied to this resource';
            } elseif ($assignment) {
                // Has a watermark set assigned
                $data['explicitly_no_watermark'] = false;
                $data['watermark_set_id'] = $assignment->getWatermarkSetId();

                // Get watermark set name
                try {
                    $watermarkSet = $this->api->read('watermark_sets', $assignment->getWatermarkSetId())->getContent();
                    $data['status'] = sprintf('Using watermark set "%s"', $watermarkSet->getName());
                } catch (\Exception $e) {
                    $data['status'] = 'Using assigned watermark set';
                }
            } else {
                // No assignment - inherits from parent
                $data['explicitly_no_watermark'] = false;
                $data['watermark_set_id'] = null;
                $data['status'] = 'Inheriting watermark settings from parent';
            }

            // Add debug information if debug mode is enabled
            $settings = $this->settings();
            if ($settings->get('watermarker_debug_mode', false)) {
                $effectiveWatermark = $this->assignmentService->getEffectiveWatermark($resourceType, $resourceId);
                $data['debug'] = [
                    'raw_assignment' => $assignment ? [
                        'id' => $assignment->getId(),
                        'resource_type' => $assignment->getResourceType(),
                        'resource_id' => $assignment->getResourceId(),
                        'watermark_set_id' => $assignment->getWatermarkSetId(),
                    ] : null,
                    'explicitly_no_watermark' => $assignment && $assignment->getWatermarkSetId() === null,
                    'watermark_set_id' => $assignment ? $assignment->getWatermarkSetId() : null,
                    'effective_watermark' => $effectiveWatermark ? [
                        'id' => $effectiveWatermark->getId(),
                        'name' => $effectiveWatermark->getName(),
                    ] : null,
                    'selected_value' => $assignment
                        ? ($assignment->getWatermarkSetId() === null ? '' : $assignment->getWatermarkSetId())
                        : 'default',
                ];
            }

            return new JsonModel($data);

        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}