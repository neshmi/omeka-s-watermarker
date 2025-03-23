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
                return $this->redirect()->toRoute('admin/watermarker/set', ['action' => 'edit', 'id' => $setId]);
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
        // Get set_id from query parameters
        $setId = $this->params()->fromQuery('set_id');
        
        // Basic validation
        if (!$setId) {
            $this->messenger()->addError('Set ID parameter is missing. Please check the URL.');
            return $this->redirect()->toRoute('admin/watermarker');
        }
        
        // Verify set exists
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $logger = $services->get('Omeka\Logger');
        
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
                return $this->redirect()->toUrl('/admin/watermarker/editSet/' . $setId);
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
                return $this->redirect()->toUrl('/admin/watermarker/editSet/' . $watermark['set_id']);
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
                return $this->redirect()->toUrl('/admin/watermarker/editSet/' . $watermark['set_id']);
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
        // Try to get parameters from various sources
        // First try from route
        $resourceId = $this->params()->fromRoute('resource-id');
        $resourceType = $this->params()->fromRoute('resource-type');
        
        // Next try from query
        if (!$resourceId) {
            $resourceId = $this->params()->fromQuery('resource-id');
        }
        if (!$resourceType) {
            $resourceType = $this->params()->fromQuery('resource-type');
        }
        
        // Finally, try to parse them from the URL path
        if (!$resourceId || !$resourceType) {
            $request = $this->getRequest();
            $requestUri = $request->getRequestUri();
            
            // Log the current request URI
            $this->getServiceLocator()->get('Omeka\Logger')->info(sprintf(
                'Watermarker: Request URI: %s',
                $requestUri
            ));
            
            // Simple regex to extract resource type and ID from the URL
            if (preg_match('#/assign/([^/]+)/(\d+)#', $requestUri, $matches)) {
                $extractedType = $matches[1];
                $extractedId = $matches[2];
                
                // Only use if we still don't have these values
                if (!$resourceType) {
                    $resourceType = $extractedType;
                }
                if (!$resourceId) {
                    $resourceId = $extractedId;
                }
                
                $this->getServiceLocator()->get('Omeka\Logger')->info(sprintf(
                    'Watermarker: Extracted from URI - type: %s, id: %s',
                    $extractedType, 
                    $extractedId
                ));
            }
        }
        
        // Validate resource type
        if (!in_array($resourceType, ['item', 'item-set'])) {
            $this->messenger()->addError('Invalid resource type.');
            return $this->redirect()->toRoute('admin');
        }
        
        // Validate resource exists
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $api = $services->get('Omeka\ApiManager');
        
        try {
            $resource = $api->read($resourceType, $resourceId)->getContent();
        } catch (\Exception $e) {
            $this->messenger()->addError(sprintf('%s not found.', ucfirst(str_replace('-', ' ', $resourceType))));
            return $this->redirect()->toRoute('admin');
        }
        
        // Get available watermark sets
        $connection = $services->get('Omeka\Connection');
        $sql = "SELECT * FROM watermark_set WHERE enabled = 1 ORDER BY is_default DESC, name ASC";
        $stmt = $connection->query($sql);
        $watermarkSets = $stmt->fetchAll();
        
        if (count($watermarkSets) == 0) {
            $this->messenger()->addWarning('No enabled watermark sets found. Please create and enable a watermark set first.');
            return $this->redirect()->toRoute('admin/watermarker');
        }
        
        // Get current assignment
        $sql = "SELECT * FROM watermark_assignment 
                WHERE resource_id = :resource_id AND resource_type = :resource_type";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('resource_id', $resourceId);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->execute();
        $assignment = $stmt->fetch();
        
        // Create form
        $form = $this->getForm(\Watermarker\Form\WatermarkAssignmentForm::class);
        $form->setWatermarkSets($watermarkSets);
        
        // Set form data
        $formData = [
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
        ];
        
        if ($assignment) {
            $formData['watermark_set_id'] = $assignment['watermark_set_id'] === null ? 'none' : $assignment['watermark_set_id'];
        }
        
        $form->setData($formData);
        
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            
            if ($form->isValid()) {
                $formData = $form->getData();
                
                // Handle the special 'none' case
                $watermarkSetId = $formData['watermark_set_id'];
                if ($watermarkSetId === 'none') {
                    $watermarkSetId = null;
                } else if (empty($watermarkSetId)) {
                    // Empty means use default - delete any existing assignment
                    $sql = "DELETE FROM watermark_assignment 
                            WHERE resource_id = :resource_id AND resource_type = :resource_type";
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('resource_id', $resourceId);
                    $stmt->bindValue('resource_type', $resourceType);
                    $stmt->execute();
                    
                    $this->messenger()->addSuccess(sprintf(
                        'Watermark assignment cleared. Default watermark settings will be used for this %s.',
                        str_replace('-', ' ', $resourceType)
                    ));
                    
                    // Redirect back to the resource
                    $redirectUrl = $resource->url();
                    return $this->redirect()->toUrl($redirectUrl);
                }
                
                // Insert or update the assignment
                if ($assignment) {
                    $sql = "UPDATE watermark_assignment 
                            SET watermark_set_id = :watermark_set_id, modified = :modified 
                            WHERE resource_id = :resource_id AND resource_type = :resource_type";
                } else {
                    $sql = "INSERT INTO watermark_assignment 
                            (resource_id, resource_type, watermark_set_id, created) 
                            VALUES (:resource_id, :resource_type, :watermark_set_id, :modified)";
                }
                
                $stmt = $connection->prepare($sql);
                $stmt->bindValue('resource_id', $resourceId);
                $stmt->bindValue('resource_type', $resourceType);
                $stmt->bindValue('watermark_set_id', $watermarkSetId);
                $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                $stmt->execute();
                
                if ($watermarkSetId === null) {
                    $message = sprintf('Watermarking disabled for this %s.', str_replace('-', ' ', $resourceType));
                } else {
                    // Get the set name
                    $setName = 'Unknown';
                    foreach ($watermarkSets as $set) {
                        if ($set['id'] == $watermarkSetId) {
                            $setName = $set['name'];
                            break;
                        }
                    }
                    
                    $message = sprintf(
                        'Watermark set "%s" assigned to this %s.',
                        $setName,
                        str_replace('-', ' ', $resourceType)
                    );
                }
                
                $this->messenger()->addSuccess($message);
                
                // Redirect back to the resource
                $redirectUrl = $resource->url();
                return $this->redirect()->toUrl($redirectUrl);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        
        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('resource', $resource);
        $view->setVariable('resourceType', $resourceType);
        $view->setVariable('assignment', $assignment);
        return $view;
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
        
        // Check if this resource has a watermark assignment
        $sql = "SELECT * FROM watermark_assignment 
                WHERE resource_id = :resource_id AND resource_type = :resource_type";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('resource_id', $resourceId);
        $stmt->bindValue('resource_type', $resourceType);
        $stmt->execute();
        $assignment = $stmt->fetch();
        
        // Get watermark set info if assigned
        $watermarkInfo = null;
        if ($assignment && $assignment['watermark_set_id']) {
            $sql = "SELECT name FROM watermark_set WHERE id = :id";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue('id', $assignment['watermark_set_id']);
            $stmt->execute();
            $setInfo = $stmt->fetch();
            
            if ($setInfo) {
                $watermarkInfo = sprintf('Using watermark set: "%s"', $setInfo['name']);
            }
        } else if ($assignment && $assignment['watermark_set_id'] === null) {
            $watermarkInfo = $resourceType === 'item' 
                ? 'Watermarking disabled for this item' 
                : 'Watermarking disabled for this item set and its items';
        } else {
            $watermarkInfo = 'Using default watermark settings';
        }
        
        // Prepare JSON response
        $response = $this->getResponse();
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode([
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
            'watermark_set_id' => $assignment ? $assignment['watermark_set_id'] : null,
            'status' => $watermarkInfo,
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
}