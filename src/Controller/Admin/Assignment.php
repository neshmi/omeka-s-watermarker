<?php
namespace Watermarker\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Watermarker\Form\WatermarkAssignmentForm;

class Assignment extends AbstractActionController
{
    protected $logger;
    protected $connection;
    protected $api;

    public function __construct($logger, $connection, $api)
    {
        $this->logger = $logger;
        $this->connection = $connection;
        $this->api = $api;
    }

    /**
     * Show assignment information for a resource
     */
    public function infoAction()
    {
        $resourceType = $this->params('resource-type');
        $resourceId = $this->params('resource-id');

        if (!$resourceType || !$resourceId) {
            $this->messenger()->addError('Invalid resource information.');
            return $this->redirect()->toRoute('admin');
        }

        // Mapping resource types to API names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
        ];

        $apiResourceType = $resourceTypeMap[$resourceType] ?? $resourceType;

        try {
            // Check if resource exists
            $resource = $this->api()->read($apiResourceType, $resourceId);
        } catch (\Exception $e) {
            $this->messenger()->addError('Resource not found.');
            return $this->redirect()->toRoute('admin');
        }

        // Get watermark assignment info from API
        $response = $this->api()->search('watermark_assignments', [
            'resource_type' => $apiResourceType,
            'resource_id' => $resourceId,
        ]);
        $assignments = $response->getContent();
        $assignment = count($assignments) > 0 ? $assignments[0] : null;

        // Get watermark sets for the form
        $response = $this->api()->search('watermark_sets', ['enabled' => true]);
        $watermarkSets = $response->getContent();

        // Get default watermark set
        $response = $this->api()->search('watermark_sets', [
            'is_default' => true,
            'enabled' => true,
        ]);
        $defaultSets = $response->getContent();
        $defaultSet = count($defaultSets) > 0 ? $defaultSets[0] : null;

        $view = new ViewModel();
        $view->setVariable('resourceType', $resourceType);
        $view->setVariable('resourceId', $resourceId);
        $view->setVariable('resource', $resource->getContent());
        $view->setVariable('assignment', $assignment);
        $view->setVariable('watermarkSets', $watermarkSets);
        $view->setVariable('defaultSet', $defaultSet);
        return $view;
    }

    /**
     * Assign a watermark to a resource
     */
    public function assignAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/watermarker');
        }

        $data = $this->params()->fromPost();
        $resourceType = $data['resource_type'] ?? null;
        $resourceId = $data['resource_id'] ?? null;
        $watermarkSetId = $data['watermark_set_id'] ?? null;
        $explicitlyNoWatermark = (bool) ($data['explicitly_no_watermark'] ?? false);

        if (!$resourceType || !$resourceId) {
            $this->messenger()->addError('Missing resource information.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Mapping resource types to API names
        $resourceTypeMap = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
        ];

        $apiResourceType = $resourceTypeMap[$resourceType] ?? $resourceType;

        // Get assignment service
        $assignmentService = $this->getServiceLocator()->get('Watermarker\AssignmentService');

        // Set assignment
        $result = $assignmentService->setAssignment(
            $resourceType,
            $resourceId,
            $watermarkSetId,
            $explicitlyNoWatermark
        );

        if ($result || $result === null) {
            $this->messenger()->addSuccess('Watermark assignment updated.');
        } else {
            $this->messenger()->addError('Failed to update watermark assignment.');
        }

        // Redirect back to resource
        return $this->redirect()->toUrl($data['redirect_url'] ?? $this->getRequest()->getHeader('Referer')->getUri());
    }
}