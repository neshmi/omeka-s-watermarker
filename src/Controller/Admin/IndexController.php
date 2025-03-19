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
        $connection = $this->connection();
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

            if ($form->isValid()) {
                $formData = $form->getData();
                $connection = $this->connection();

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
        $connection = $this->connection();

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
        $connection = $this->connection();

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
     * Get database connection
     *
     * @return \Doctrine\DBAL\Connection
     */
    protected function connection()
    {
        // Get the connection directly using the plugin() method to access the services
        return $this->plugin('Omeka\EntityManager')->getConnection();
    }
}