<?php
/**
 * Watermarker admin controller
 */

namespace Watermarker\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Watermarker\Form\WatermarkForm;
use Watermarker\Form\ConfigForm;
use Omeka\Form\ConfirmForm;

class IndexController extends AbstractActionController
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
     * List all watermark sets and watermarks
     */
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get all watermark sets
        $stmt = $connection->query("SELECT * FROM watermark_set ORDER BY id ASC");
        $watermarkSets = $stmt->fetchAll();

        // Get all watermarks with set info
        $stmt = $connection->query("
            SELECT ws.*, s.name as set_name, s.is_default, s.enabled as set_enabled
            FROM watermark_setting ws
            JOIN watermark_set s ON ws.set_id = s.id
            ORDER BY s.is_default DESC, s.id ASC, ws.type ASC
        ");
        $watermarks = $stmt->fetchAll();

        // Group watermarks by set
        $watermarksBySet = [];
        foreach ($watermarks as $watermark) {
            $setId = $watermark['set_id'];
            if (!isset($watermarksBySet[$setId])) {
                $watermarksBySet[$setId] = [];
            }
            $watermarksBySet[$setId][] = $watermark;
        }

        $view = new ViewModel();
        $view->setVariable('watermarkSets', $watermarkSets);
        $view->setVariable('watermarks', $watermarks);
        $view->setVariable('watermarksBySet', $watermarksBySet);
        return $view;
    }

    /**
     * Add a new watermark set
     */
    public function addSetAction()
    {
        $form = $this->getForm(\Watermarker\Form\WatermarkSetForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();
                $services = $this->getEvent()->getApplication()->getServiceManager();
                $connection = $services->get('Omeka\Connection');

                // Check if this is the default set
                $isDefault = isset($formData['is_default']) ? 1 : 0;

                // If this is set as default, clear any other default sets
                if ($isDefault) {
                    $sql = "UPDATE watermark_set SET is_default = 0";
                    $connection->exec($sql);
                }

                $sql = "INSERT INTO watermark_set (name, is_default, enabled, created)
                        VALUES (:name, :is_default, :enabled, :created)";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('name', $formData['name']);
                $stmt->bindValue('is_default', $isDefault);
                $stmt->bindValue('enabled', isset($formData['enabled']) ? 1 : 0);
                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                $stmt->execute();

                $setId = $connection->lastInsertId();

                $this->messenger()->addSuccess('Watermark set added.');
                return $this->redirect()->toRoute('admin/watermarker/editSet', ['id' => $setId]);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setTemplate('watermarker/admin/index/add-set');
        return $view;
    }

    /**
     * Edit a watermark set
     */
    public function editSetAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get set data
        $sql = "SELECT * FROM watermark_set WHERE id = :id LIMIT 1";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();
        $set = $stmt->fetch();

        if (!$set) {
            $this->messenger()->addError('Watermark set not found.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Get watermarks in this set
        $sql = "SELECT * FROM watermark_setting WHERE set_id = :set_id ORDER BY type ASC";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('set_id', $id);
        $stmt->execute();
        $watermarks = $stmt->fetchAll();

        $form = $this->getForm(\Watermarker\Form\WatermarkSetForm::class);
        $form->setData([
            'o:id' => $set['id'],
            'name' => $set['name'],
            'is_default' => $set['is_default'],
            'enabled' => $set['enabled'],
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();

                // Check if this is the default set
                $isDefault = isset($formData['is_default']) ? 1 : 0;

                // If this is set as default, clear any other default sets
                if ($isDefault) {
                    $sql = "UPDATE watermark_set SET is_default = 0";
                    $connection->exec($sql);
                }

                $sql = "UPDATE watermark_set SET
                        name = :name,
                        is_default = :is_default,
                        enabled = :enabled,
                        modified = :modified
                        WHERE id = :id";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('name', $formData['name']);
                $stmt->bindValue('is_default', $isDefault);
                $stmt->bindValue('enabled', isset($formData['enabled']) ? 1 : 0);
                $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                $stmt->bindValue('id', $id);
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark set updated.');
                return $this->redirect()->toRoute('admin/watermarker');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('set', $set);
        $view->setVariable('watermarks', $watermarks);
        $view->setTemplate('watermarker/admin/index/edit-set');
        return $view;
    }

    /**
     * Delete a watermark set
     */
    public function deleteSetAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get set data
        $sql = "SELECT * FROM watermark_set WHERE id = :id LIMIT 1";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();
        $set = $stmt->fetch();

        if (!$set) {
            $this->messenger()->addError('Watermark set not found.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                // Delete all watermarks in this set (cascade will handle this)
                $sql = "DELETE FROM watermark_set WHERE id = :id";
                $stmt = $connection->prepare($sql);
                $stmt->bindValue('id', $id);
                $stmt->execute();

                // Clear any assignments to this set
                $sql = "UPDATE watermark_assignment SET watermark_set_id = NULL
                        WHERE watermark_set_id = :set_id";
                $stmt = $connection->prepare($sql);
                $stmt->bindValue('set_id', $id);
                $stmt->execute();

                // If this was the default set, try to set another set as default
                if ($set['is_default']) {
                    $sql = "UPDATE watermark_set SET is_default = 1
                            WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
                    $connection->exec($sql);
                }

                $this->messenger()->addSuccess('Watermark set deleted.');
                return $this->redirect()->toRoute('admin/watermarker');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('set', $set);
        $view->setTemplate('watermarker/admin/index/delete-set');
        return $view;
    }

    /**
     * Add a new watermark to a set
     */
    public function addAction()
    {
        // Get all possible set_id parameters
        $setId = $this->params('set_id');
        $querySetId = $this->params()->fromQuery('set_id');
        $routeSetId = $this->params()->fromRoute('set_id');

        // Get services for logging
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $logger = $services->get('Omeka\Logger');

        // Log all possible parameters and the request URI
        $logger->info('Watermarker: Add watermark action called with detailed params', [
            'set_id' => $setId,
            'query_set_id' => $querySetId,
            'route_set_id' => $routeSetId,
            'route_params' => $this->params()->fromRoute(),
            'query_params' => $this->params()->fromQuery(),
            'post_params' => $this->getRequest()->isPost() ? 'POST request received' : 'Not a POST request',
            'request_uri' => $this->getRequest()->getUri()->toString(),
        ]);

        // If set_id isn't in the route, try to get it from the query
        if (!$setId && $querySetId) {
            $setId = $querySetId;
            $logger->info('Watermarker: Using set_id from query parameter: ' . $setId);
        }

        // Basic validation
        if (!$setId) {
            $this->messenger()->addError('Set ID parameter is missing. Please check the URL.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Verify set exists
        $connection = $services->get('Omeka\Connection');

        $sql = "SELECT * FROM watermark_set WHERE id = :id LIMIT 1";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('id', $setId);
        $stmt->execute();
        $set = $stmt->fetch();

        if (!$set) {
            $this->messenger()->addError('Watermark set not found with ID: ' . $setId);
            return $this->redirect()->toRoute('admin/watermarker');
        }

        $form = $this->getForm(WatermarkForm::class);
        $form->get('set_id')->setValue($setId);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();

                try {
                    // Check if the asset exists
                    $api = $services->get('Omeka\ApiManager');
                    $asset = $api->read('assets', $formData['media_id'])->getContent();
                } catch (\Exception $e) {
                    $this->messenger()->addError(sprintf(
                        'Asset does not exist: %s',
                        $e->getMessage()
                    ));
                    return $this->redirect()->toRoute('admin/watermarker', ['action' => 'editSet', 'id' => $setId]);
                }

                $sql = "INSERT INTO watermark_setting (set_id, type, media_id, position, opacity, created)
                        VALUES (:set_id, :type, :media_id, :position, :opacity, :created)";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('set_id', $setId);
                $stmt->bindValue('type', $formData['type']);
                $stmt->bindValue('media_id', $formData['media_id']);
                $stmt->bindValue('position', $formData['position']);
                $stmt->bindValue('opacity', $formData['opacity']);
                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark added to set.');
                return $this->redirect()->toRoute('admin/watermarker/editSet', ['id' => $setId]);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('set', $set);
        $view->setVariable('set_id', $setId); // Explicitly pass the set_id
        $view->setTemplate('watermarker/admin/index/add');

        // Also dump the request parameters to the log
        $logger->info('Request params:', [
            'id' => $this->params('id'),
            'set_id' => $this->params('set_id'),
            'action' => $this->params('action'),
            'all' => $this->params()->fromRoute()
        ]);
        return $view;
    }

    /**
     * Edit a watermark
     */
    public function editAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get watermark data
        $sql = "SELECT w.*, s.name as set_name, s.id as set_id
                FROM watermark_setting w
                JOIN watermark_set s ON w.set_id = s.id
                WHERE w.id = :id LIMIT 1";

        $stmt = $connection->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();
        $watermark = $stmt->fetch();

        if (!$watermark) {
            $this->messenger()->addError('Watermark not found.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        $form = $this->getForm(WatermarkForm::class);
        $form->setData([
            'o:id' => $watermark['id'],
            'set_id' => $watermark['set_id'],
            'type' => $watermark['type'],
            'media_id' => $watermark['media_id'],
            'position' => $watermark['position'],
            'opacity' => $watermark['opacity'],
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();

                $sql = "UPDATE watermark_setting SET
                        type = :type,
                        media_id = :media_id,
                        position = :position,
                        opacity = :opacity,
                        modified = :modified
                        WHERE id = :id";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('type', $formData['type']);
                $stmt->bindValue('media_id', $formData['media_id']);
                $stmt->bindValue('position', $formData['position']);
                $stmt->bindValue('opacity', $formData['opacity']);
                $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                $stmt->bindValue('id', $id);
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark updated.');
                return $this->redirect()->toRoute('admin/watermarker/editSet', ['id' => $watermark['set_id']]);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('watermark', $watermark);
        return $view;
    }

    /**
     * Delete a watermark
     */
    public function deleteAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get watermark data
        $sql = "SELECT w.*, s.id as set_id
                FROM watermark_setting w
                JOIN watermark_set s ON w.set_id = s.id
                WHERE w.id = :id LIMIT 1";

        $stmt = $connection->prepare($sql);
        $stmt->bindValue('id', $id);
        $stmt->execute();
        $watermark = $stmt->fetch();

        if (!$watermark) {
            $this->messenger()->addError('Watermark not found.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $sql = "DELETE FROM watermark_setting WHERE id = :id";
                $stmt = $connection->prepare($sql);
                $stmt->bindValue('id', $id);
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark deleted.');
                return $this->redirect()->toRoute('admin/watermarker/editSet', ['id' => $watermark['set_id']]);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('watermark', $watermark);
        return $view;
    }

    /**
     * Global configuration
     */
    public function configAction()
    {
        $form = $this->getForm(ConfigForm::class);
        $settings = $this->settings();

        $form->setData([
            'watermark_enabled' => $settings->get('watermarker_enabled', true),
            'apply_on_upload' => $settings->get('watermarker_apply_on_upload', true),
            'apply_on_import' => $settings->get('watermarker_apply_on_import', true),
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();
                $settings->set('watermarker_enabled', isset($formData['watermark_enabled']));
                $settings->set('watermarker_apply_on_upload', isset($formData['apply_on_upload']));
                $settings->set('watermarker_apply_on_import', isset($formData['apply_on_import']));

                $this->messenger()->addSuccess('Watermark settings updated.');
                return $this->redirect()->toRoute('admin/watermarker');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        return $view;
    }

    /**
     * Assign a watermark set to an item or item set
     */
    public function assignAction()
    {
        $this->logger->info('Watermarker: Starting assignAction');

        // Get parameters from route and query
        $resourceType = $this->params()->fromRoute('resource_type');
        $resourceId = $this->params()->fromRoute('resource_id');

        // Get watermark set ID from POST data
        $watermarkSetId = $this->params()->fromPost('watermark_set_id');

        $this->logger->info(sprintf(
            'Watermarker: Request parameters - type: %s, id: %s, set_id: %s',
            $resourceType,
            $resourceId,
            $watermarkSetId
        ));

        // Validate resource type
        if (!in_array($resourceType, ['item-set', 'item'])) {
            $this->logger->err(sprintf('Watermarker: Invalid resource type: %s', $resourceType));
            if ($this->getRequest()->isXmlHttpRequest()) {
                return $this->jsonResponse(['error' => 'Invalid resource type']);
            }
            $this->flashMessenger()->addErrorMessage('Invalid resource type');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Get the resource
        try {
            // Convert resource type to API endpoint name
            $apiEndpoint = $resourceType === 'item-set' ? 'item_sets' : 'items';
            $resource = $this->api->read($apiEndpoint, $resourceId)->getContent();
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Watermarker: Resource not found - type: %s, id: %s, error: %s',
                $resourceType,
                $resourceId,
                $e->getMessage()
            ));
            if ($this->getRequest()->isXmlHttpRequest()) {
                return $this->jsonResponse(['error' => 'Resource not found']);
            }
            $this->flashMessenger()->addErrorMessage('Resource not found');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Handle watermark assignment
        try {
            // Handle different watermark set options
            $explicitlyNoWatermark = false;
            if (empty($watermarkSetId)) {
                // "None" selected - set explicitly_no_watermark flag
                $watermarkSetId = null;
                $explicitlyNoWatermark = true;
            } elseif ($watermarkSetId === 'default') {
                // "Default" selected - use null and no flag
                $watermarkSetId = null;
                $explicitlyNoWatermark = false;
            } else {
                // Specific watermark set selected - verify it exists
                $stmt = $this->connection->prepare('SELECT id FROM watermark_set WHERE id = ?');
                $stmt->execute([$watermarkSetId]);
                if (!$stmt->fetch()) {
                    throw new \Exception(sprintf('Watermark set with ID %s not found', $watermarkSetId));
                }
                $explicitlyNoWatermark = false;
            }

            // Check if assignment exists
            $stmt = $this->connection->prepare('
                SELECT id FROM watermark_assignment
                WHERE resource_type = ? AND resource_id = ?
            ');
            $stmt->execute([$resourceType, $resourceId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update existing assignment
                $stmt = $this->connection->prepare('
                    UPDATE watermark_assignment
                    SET watermark_set_id = ?,
                        explicitly_no_watermark = ?,
                        modified = NOW()
                    WHERE resource_type = ? AND resource_id = ?
                ');
                $stmt->execute([
                    $watermarkSetId,
                    $explicitlyNoWatermark ? 1 : 0, // Convert boolean to integer
                    $resourceType,
                    $resourceId
                ]);
            } else {
                // Insert new assignment
                $stmt = $this->connection->prepare('
                    INSERT INTO watermark_assignment
                    (resource_type, resource_id, watermark_set_id, explicitly_no_watermark, created, modified)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ');
                $stmt->execute([
                    $resourceType,
                    $resourceId,
                    $watermarkSetId,
                    $explicitlyNoWatermark ? 1 : 0 // Convert boolean to integer
                ]);
            }

            $this->logger->info(sprintf(
                'Watermarker: Successfully assigned watermark set %s to %s %s (explicitly_no_watermark: %s)',
                $watermarkSetId === null ? ($explicitlyNoWatermark ? 'none' : 'default') : $watermarkSetId,
                $resourceType,
                $resourceId,
                $explicitlyNoWatermark ? 'true' : 'false'
            ));

            if ($this->getRequest()->isXmlHttpRequest()) {
                return $this->jsonResponse(['success' => true]);
            }
            $this->flashMessenger()->addSuccessMessage('Watermark settings saved successfully');
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Watermarker: Error saving watermark assignment - %s, SQL State: %s',
                $e->getMessage(),
                $e->getCode()
            ));
            if ($this->getRequest()->isXmlHttpRequest()) {
                return $this->jsonResponse(['error' => 'Failed to save watermark settings: ' . $e->getMessage()]);
            }
            $this->flashMessenger()->addErrorMessage('Failed to save watermark settings: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('admin/watermarker');
    }

    /**
     * Test watermark application on a specific media
     */
    public function testAction()
    {
        $mediaId = $this->params('media-id');
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $connection = $services->get('Omeka\Connection');

        // First, check watermark sets
        $sql = "SELECT * FROM watermark_set WHERE enabled = 1";
        $stmt = $connection->query($sql);
        $sets = $stmt->fetchAll();

        if (count($sets) == 0) {
            $messenger->addWarning('No enabled watermark sets found. Please create and enable a watermark set first.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        // Then check if there are any watermarks
        $sql = "SELECT COUNT(*) FROM watermark_setting";
        $watermarkCount = (int)$connection->fetchColumn($sql);

        if ($watermarkCount == 0) {
            $messenger->addWarning('No watermarks found. Please add watermarks to a set first.');
            return $this->redirect()->toRoute('admin/watermarker');
        }

        if (!$mediaId) {
            $media = null;

            // If no media-id provided, get the first eligible media
            $mediaList = $api->search('media', [
                'limit' => 1,
                'sort_by' => 'id',
                'sort_order' => 'desc'
            ])->getContent();

            if (count($mediaList) > 0) {
                $media = $mediaList[0];
                $mediaId = $media->id();
            }

            if (!$media) {
                $messenger->addError('No media found to test watermarking.');
                return $this->redirect()->toRoute('admin/watermarker');
            }
        } else {
            try {
                $media = $api->read('media', $mediaId)->getContent();
            } catch (\Exception $e) {
                $messenger->addError('Media not found: ' . $mediaId);
                return $this->redirect()->toRoute('admin/watermarker');
            }
        }

        // Process this media with the watermark service
        $watermarkService = $services->get('Watermarker\WatermarkService');

        // Enable debug mode for detailed logging
        if (method_exists($watermarkService, 'setDebugMode')) {
            $watermarkService->setDebugMode(true);
        }

        $result = $watermarkService->processMedia($media);

        if ($result) {
            $messenger->addSuccess('Successfully applied watermark to derivative images.');
        } else {
            $messenger->addError('Failed to apply watermark. Check the logs for details.');
        }

        // Redirect to the media show page
        return $this->redirect()->toUrl($media->url());
    }

    /**
     * Check watermark configurations
     */
    public function checkAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $api = $services->get('Omeka\ApiManager');
        $messenger = $this->messenger();

        // Get all watermark configurations
        $sql = "SELECT * FROM watermark_setting";
        $stmt = $connection->query($sql);
        $watermarks = $stmt->fetchAll();

        $validCount = 0;
        $invalidCount = 0;

        foreach ($watermarks as $watermark) {
            try {
                // Check if the asset exists
                $assetExists = true;
                try {
                    $asset = $api->read('assets', $watermark['media_id'])->getContent();
                    $validCount++;
                } catch (\Exception $e) {
                    $assetExists = false;
                    $invalidCount++;
                    $messenger->addWarning(sprintf(
                        'Watermark "%s" references a missing asset. Please edit and select a valid image.',
                        $watermark['name']
                    ));
                }
            } catch (\Exception $e) {
                $invalidCount++;
            }
        }

        if ($validCount > 0) {
            $messenger->addSuccess(sprintf(
                'Successfully verified %d watermark configuration(s)',
                $validCount
            ));
        }

        if ($invalidCount == 0 && $validCount > 0) {
            $messenger->addSuccess('All watermark configurations are valid!');
        }

        return $this->redirect()->toRoute('admin/watermarker');
    }

    /**
     * Get watermark info for a resource
     * This action returns JSON data about a resource's watermark assignment
     */
    public function infoAction()
    {
        $resourceType = $this->params()->fromRoute('resource-type');
        $resourceId = $this->params()->fromRoute('resource-id');

        // Validate parameters
        if (!in_array($resourceType, ['item', 'item-set']) || !$resourceId) {
            return $this->jsonError('Invalid resource type or ID');
        }

        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Debug: Get all watermark assignments for this resource type
        $debugAssignments = $connection->query("
            SELECT * FROM watermark_assignment
            WHERE resource_type = '$resourceType'
        ")->fetchAll();

        // Debug: Get all watermark sets
        $debugSets = $connection->query("
            SELECT * FROM watermark_set
        ")->fetchAll();

        // Check if this resource has a watermark assignment
        $sql = "SELECT wa.*, ws.name as set_name
                FROM watermark_assignment wa
                LEFT JOIN watermark_set ws ON wa.watermark_set_id = ws.id
                WHERE wa.resource_id = :resource_id AND wa.resource_type = :resource_type";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('resource_id', $resourceId);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->execute();
        $assignment = $stmt->fetch();

        // Debug: Get the raw query result
        $debugQuery = $connection->query("
            SELECT wa.*, ws.name as set_name
            FROM watermark_assignment wa
            LEFT JOIN watermark_set ws ON wa.watermark_set_id = ws.id
            WHERE wa.resource_id = '$resourceId' AND wa.resource_type = '$resourceType'
        ")->fetchAll();

        // Get watermark set info if assigned
        $watermarkInfo = null;
        $watermarkSetId = null;
        $explicitlyNoWatermark = false;
        $selectedValue = 'default'; // Default value for the dropdown

        if ($assignment) {
            $watermarkSetId = $assignment['watermark_set_id'];
            $explicitlyNoWatermark = (bool)$assignment['explicitly_no_watermark'];

            if ($explicitlyNoWatermark) {
                $watermarkInfo = 'No watermark (explicitly disabled)';
                $selectedValue = ''; // Empty string for "None"
            } elseif ($watermarkSetId === null) {
                $watermarkInfo = 'Using default watermark settings';
                $selectedValue = 'default';
            } else {
                $watermarkInfo = sprintf('Using watermark set: "%s"', $assignment['set_name']);
                $selectedValue = (string)$watermarkSetId; // Convert to string for the dropdown
            }
        } else {
            $watermarkInfo = 'Using default watermark settings';
            $selectedValue = 'default';
        }

        // Prepare JSON response with extensive debug information
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode([
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
            'watermark_set_id' => $watermarkSetId,
            'explicitly_no_watermark' => $explicitlyNoWatermark,
            'status' => $watermarkInfo,
            'selected_value' => $selectedValue,
            'debug' => [
                'raw_assignment' => $assignment,
                'watermark_set_id' => $watermarkSetId,
                'explicitly_no_watermark' => $explicitlyNoWatermark,
                'selected_value' => $selectedValue,
                'sql' => $sql,
                'params' => [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType
                ],
                'all_assignments' => $debugAssignments,
                'all_sets' => $debugSets,
                'query_result' => $debugQuery,
                'processed_values' => [
                    'watermark_set_id' => $watermarkSetId,
                    'explicitly_no_watermark' => $explicitlyNoWatermark,
                    'selected_value' => $selectedValue,
                    'watermark_info' => $watermarkInfo
                ]
            ],
            'success' => true
        ]));

        return $response;
    }

    /**
     * Helper to return a JSON error response
     */
    private function jsonError($message, $statusCode = 400)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode([
            'error' => $message,
            'success' => false
        ]));

        return $response;
    }

    /**
     * Helper method to create JSON responses
     */
    private function jsonResponse($data)
    {
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        return $response;
    }
}