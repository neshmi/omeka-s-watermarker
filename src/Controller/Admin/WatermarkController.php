<?php
namespace Watermarker\Controller\Admin;

use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Stdlib\Message;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Exception\NotFoundException;
use Watermarker\Entity\WatermarkAssignment;
use Watermarker\Entity\WatermarkSet;

class WatermarkController extends AbstractActionController
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(Api $api, $entityManager, $logger)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Set watermark assignment for a resource
     */
    public function setAssignmentAction()
    {
        $this->logger->info('Watermarker API: setAssignmentAction called');

        if (!$this->getRequest()->isPost()) {
            $this->logger->warn('Watermarker API: setAssignmentAction called with non-POST request');
            return new JsonModel([
                'status' => 'error',
                'message' => 'Only POST requests are allowed'
            ]);
        }

        // Log request headers
        $headers = $this->getRequest()->getHeaders();
        $this->logger->info('Watermarker API: Request headers: ' . json_encode($headers->toArray()));

        // Try to get JSON data first
        $jsonData = $this->getRequest()->getContent();
        $this->logger->info('Watermarker API: Raw request content: ' . $jsonData);

        if ($jsonData) {
            try {
                $data = json_decode($jsonData, true);
                $this->logger->info('Watermarker API: Decoded JSON data: ' . json_encode($data));
            } catch (\Exception $e) {
                $this->logger->err('Watermarker API: JSON decode error: ' . $e->getMessage());
                $data = null;
            }
        }

        // Fall back to POST data if JSON parsing failed
        if (empty($data)) {
            $data = $this->params()->fromPost();
            $this->logger->info('Watermarker API: Using POST data: ' . json_encode($data));
        }

        try {
            $resourceType = $data['resource_type'];
            $resourceId = $data['resource_id'];
            $watermarkSetValue = $data['watermark_set'] ?? null;

            $this->logger->info(sprintf(
                'Watermarker: Processing assignment for resource type: %s, ID: %s, watermark set: %s',
                $resourceType,
                $resourceId,
                $watermarkSetValue
            ));

            // Map resource type to API resource type
            $apiResourceType = $resourceType;
            if ($resourceType === 'item-set') {
                $apiResourceType = 'item_sets';
            } else if ($resourceType === 'item') {
                $apiResourceType = 'items';
            } else if ($resourceType === 'media') {
                $apiResourceType = 'media';
            }

            $this->logger->info('Watermarker: Mapped API resource type: ' . $apiResourceType);

            // Check if resource exists
            try {
                $resource = $this->api->read($apiResourceType, $resourceId)->getContent();
                $this->logger->info('Watermarker: Found resource: ' . json_encode($resource));
            } catch (\Exception $e) {
                $this->logger->err(sprintf('Watermarker: Resource not found - %s', $e->getMessage()));
                return new JsonModel([
                    'status' => 'error',
                    'message' => 'Resource not found'
                ]);
            }

            // Prepare the assignment data
            $assignmentData = [
                'o:resource_type' => $apiResourceType,
                'o:resource_id' => $resourceId,
            ];

            if ($watermarkSetValue === 'none') {
                $assignmentData['o:explicitly_no_watermark'] = true;
                $assignmentData['o:watermark_set'] = null;
            } elseif ($watermarkSetValue === 'default') {
                $assignmentData['o:explicitly_no_watermark'] = false;
                $assignmentData['o:watermark_set'] = null;
            } else {
                // Check if the watermark set exists
                try {
                    $watermarkSet = $this->api->read('watermark_sets', $watermarkSetValue)->getContent();
                    $this->logger->info('Watermarker: Found watermark set: ' . json_encode($watermarkSet));
                    $assignmentData['o:explicitly_no_watermark'] = false;
                    $assignmentData['o:watermark_set'] = ['o:id' => $watermarkSetValue];
                } catch (\Exception $e) {
                    $this->logger->err(sprintf('Watermarker: Watermark set not found - %s', $e->getMessage()));
                    return new JsonModel([
                        'status' => 'error',
                        'message' => 'Watermark set not found'
                    ]);
                }
            }

            $this->logger->info('Watermarker: Assignment data: ' . json_encode($assignmentData));

            // Check for existing assignment
            $existingAssignments = $this->api->search('watermark_assignments', [
                'resource_type' => $apiResourceType,
                'resource_id' => $resourceId,
            ])->getContent();

            $this->logger->info('Watermarker: Found existing assignments: ' . json_encode($existingAssignments));

            if (count($existingAssignments) > 0) {
                $assignment = $existingAssignments[0];
                $this->logger->info(sprintf('Watermarker: Updating existing assignment ID %s', $assignment->id()));
                $result = $this->api->update('watermark_assignments', $assignment->id(), $assignmentData);
                $this->logger->info('Watermarker: Update result: ' . json_encode($result));
            } else {
                $this->logger->info('Watermarker: Creating new assignment');
                $result = $this->api->create('watermark_assignments', $assignmentData);
                $this->logger->info('Watermarker: Create result: ' . json_encode($result));
            }

            $this->logger->info('Watermarker: Assignment saved successfully');
            return new JsonModel([
                'status' => 'success',
                'message' => 'Watermark assignment saved successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->err('Watermarker: Error saving assignment: ' . $e->getMessage());
            $this->logger->err('Watermarker: Stack trace: ' . $e->getTraceAsString());
            return new JsonModel([
                'status' => 'error',
                'message' => 'Error saving watermark assignment: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Return a JSON error response
     */
    protected function jsonError($message, $status = 400)
    {
        $response = $this->getResponse();
        $response->setStatusCode($status);

        return new JsonModel([
            'success' => false,
            'message' => $message
        ]);
    }
}