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
     * List all watermark configurations
     */
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $stmt = $connection->query("SELECT * FROM watermark_setting ORDER BY id ASC");
        $watermarks = $stmt->fetchAll();

        $view = new ViewModel();
        $view->setVariable('watermarks', $watermarks);
        return $view;
    }

    /**
     * Add a new watermark
     */
    public function addAction()
    {
        $form = $this->getForm(WatermarkForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            $this->messenger()->addSuccess(sprintf(
                'Received form data with media_id: %s',
                isset($data['media_id']) ? $data['media_id'] : 'not set'
            ));

            if ($form->isValid()) {
                $formData = $form->getData();
                $services = $this->getEvent()->getApplication()->getServiceManager();
                $connection = $services->get('Omeka\Connection');

                $this->messenger()->addSuccess(sprintf(
                    'Validated form data with media_id: %s',
                    isset($formData['media_id']) ? $formData['media_id'] : 'not set'
                ));

                try {
                    // Check if the asset exists
                    $api = $services->get('Omeka\ApiManager');
                    $asset = $api->read('assets', $formData['media_id'])->getContent();
                    $this->messenger()->addSuccess(sprintf(
                        'Asset exists and has URL: %s',
                        $asset->assetUrl()
                    ));
                } catch (\Exception $e) {
                    $this->messenger()->addError(sprintf(
                        'Asset does not exist: %s',
                        $e->getMessage()
                    ));
                }

                $sql = "INSERT INTO watermark_setting (name, media_id, orientation, position, opacity, enabled, created)
                        VALUES (:name, :media_id, :orientation, :position, :opacity, :enabled, :created)";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('name', $formData['name']);
                $stmt->bindValue('media_id', $formData['media_id']);
                $stmt->bindValue('orientation', $formData['orientation']);
                $stmt->bindValue('position', $formData['position']);
                $stmt->bindValue('opacity', $formData['opacity']);
                $stmt->bindValue('enabled', isset($formData['enabled']) ? 1 : 0);
                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark configuration added.');
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
     * Edit a watermark configuration
     */
    public function editAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get watermark data
        $sql = "SELECT * FROM watermark_setting WHERE id = :id LIMIT 1";
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
            'name' => $watermark['name'],
            'media_id' => $watermark['media_id'],
            'orientation' => $watermark['orientation'],
            'position' => $watermark['position'],
            'opacity' => $watermark['opacity'],
            'enabled' => $watermark['enabled'],
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $formData = $form->getData();

                $sql = "UPDATE watermark_setting SET
                        name = :name,
                        media_id = :media_id,
                        orientation = :orientation,
                        position = :position,
                        opacity = :opacity,
                        enabled = :enabled,
                        modified = :modified
                        WHERE id = :id";

                $stmt = $connection->prepare($sql);
                $stmt->bindValue('name', $formData['name']);
                $stmt->bindValue('media_id', $formData['media_id']);
                $stmt->bindValue('orientation', $formData['orientation']);
                $stmt->bindValue('position', $formData['position']);
                $stmt->bindValue('opacity', $formData['opacity']);
                $stmt->bindValue('enabled', isset($formData['enabled']) ? 1 : 0);
                $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                $stmt->bindValue('id', $id);
                $stmt->execute();

                $this->messenger()->addSuccess('Watermark configuration updated.');
                return $this->redirect()->toRoute('admin/watermarker');
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
     * Delete a watermark configuration
     */
    public function deleteAction()
    {
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');

        // Get watermark data
        $sql = "SELECT * FROM watermark_setting WHERE id = :id LIMIT 1";
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

                $this->messenger()->addSuccess('Watermark configuration deleted.');
                return $this->redirect()->toRoute('admin/watermarker');
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
     * Test watermark application on a specific media
     */
    public function testAction()
    {
        $mediaId = $this->params('media-id');
        $messenger = $this->messenger();
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');

        // First, check watermark configurations
        $connection = $services->get('Omeka\Connection');
        $sql = "SELECT * FROM watermark_setting WHERE enabled = 1";
        $stmt = $connection->query($sql);
        $watermarks = $stmt->fetchAll();

        $messenger->addSuccess(sprintf(
            'Found %d enabled watermark configurations in database',
            count($watermarks)
        ));

        foreach ($watermarks as $watermark) {
            $messenger->addSuccess(sprintf(
                'Watermark "%s" uses asset ID: %s',
                $watermark['name'],
                $watermark['media_id']
            ));

            try {
                $asset = $api->read('assets', $watermark['media_id'])->getContent();
                $messenger->addSuccess(sprintf(
                    'Asset exists and has URL: %s',
                    $asset->assetUrl()
                ));
            } catch (\Exception $e) {
                $messenger->addError(sprintf(
                    'Asset does not exist: %s',
                    $e->getMessage()
                ));
            }
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

        $messenger->addSuccess(sprintf(
            'Using media ID %s for test',
            $mediaId
        ));

        // Process this media with the watermark service
        $watermarkService = $services->get('Watermarker\WatermarkService');

        // Enable debug mode for detailed logging
        if (method_exists($watermarkService, 'setDebugMode')) {
            $watermarkService->setDebugMode(true);
        }

        $logger->info('Starting test watermarking process');
        $result = $watermarkService->processMedia($media);
        $logger->info('Completed test watermarking process');

        if ($result) {
            $messenger->addSuccess(
                'Successfully applied watermark to derivatives. The original file is preserved. ' .
                'The watermark will be visible in the large and medium views, but not in thumbnails or original downloads.'
            );
        } else {
            $messenger->addError('Failed to apply watermark. Check the logs for details.');
        }

        // Redirect to the media show page - now it's safe since we don't delete the media
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
                // Log the asset ID for debugging
                $messenger->addSuccess(sprintf(
                    'Watermark "%s" has asset ID: %s',
                    $watermark['name'],
                    $watermark['media_id']
                ));

                // Check if the asset exists
                $assetExists = true;
                try {
                    $asset = $api->read('assets', $watermark['media_id'])->getContent();
                    $messenger->addSuccess(sprintf(
                        'Successfully loaded asset %s',
                        $watermark['media_id']
                    ));
                } catch (\Exception $e) {
                    $assetExists = false;
                    $messenger->addError(sprintf(
                        'Error loading asset: %s',
                        $e->getMessage()
                    ));
                }

                if (!$assetExists) {
                    $invalidCount++;
                    $messenger->addWarning(sprintf(
                        'Watermark "%s" (ID: %s) references a non-existent asset (ID: %s)',
                        $watermark['name'],
                        $watermark['id'],
                        $watermark['media_id']
                    ));
                } else {
                    $validCount++;

                    // Try to get the asset URL
                    $messenger->addSuccess(sprintf(
                        'Asset URL: %s',
                        $asset->assetUrl()
                    ));
                }
            } catch (\Exception $e) {
                $messenger->addError(sprintf(
                    'Error checking watermark "%s" (ID: %s): %s',
                    $watermark['name'],
                    $watermark['id'],
                    $e->getMessage()
                ));
            }
        }

        if ($validCount > 0) {
            $messenger->addSuccess(sprintf(
                '%d watermark configuration(s) are valid',
                $validCount
            ));
        }

        if ($invalidCount > 0) {
            $messenger->addWarning(sprintf(
                '%d watermark configuration(s) need to be updated. Please edit them to select valid assets.',
                $invalidCount
            ));
        }

        return $this->redirect()->toRoute('admin/watermarker');
    }
}