<?php
/**
 * Watermarker
 *
 * A module for Omeka S that adds watermarking capabilities to uploaded and imported media.
 *
 * @copyright Copyright 2025, Your Name
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GPLv3 or later
 */

namespace Watermarker;

use Omeka\Module\AbstractModule;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\MediaRepresentation;

class Module extends AbstractModule
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Get module configuration.
     *
     * @return array Module configuration
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Install this module.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');

        // Create watermark table
        $connection->exec("
            CREATE TABLE watermark_setting (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                media_id INT NOT NULL,
                orientation VARCHAR(50) NOT NULL,
                position VARCHAR(50) NOT NULL,
                opacity DECIMAL(3,2) NOT NULL,
                enabled TINYINT(1) NOT NULL,
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * Uninstall this module.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec('DROP TABLE IF EXISTS watermark_setting');
    }

    /**
     * Attach to Omeka events.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: Attaching event listeners');

        // Listen for media creation and update events to apply watermarks
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.post',
            [$this, 'handleMediaCreated']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.update.post',
            [$this, 'handleMediaUpdated']
        );

        // Also listen to after.save.media for existing items
        $sharedEventManager->attach(
            'Omeka\Entity\Media',
            'entity.persist.post',
            [$this, 'handleMediaPersisted']
        );

        // Listen to hydrate post event
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'handleMediaHydrated']
        );

        // Add link to admin navigation
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.layout',
            [$this, 'addAdminNavigation']
        );

        // Debug event to log when module events are triggered
        $sharedEventManager->attach(
            '*',
            '*',
            function (Event $event) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');

                // Only log API events that might involve media
                if (strpos($event->getName(), 'media') !== false ||
                    strpos($event->getName(), 'item') !== false ||
                    strpos($event->getName(), 'api') !== false) {

                    $logger->info(sprintf(
                        'Watermarker: Event "%s" on "%s" triggered',
                        $event->getName(),
                        $event->getTarget() ? get_class($event->getTarget()) : 'unknown'
                    ));
                }
            }
        );
    }

    /**
     * Add watermark module link to admin navigation
     *
     * @param Event $event
     */
    public function addAdminNavigation(Event $event)
    {
        $view = $event->getTarget();
        $view->headLink()->appendStylesheet($view->assetUrl('css/watermark.css', 'Watermarker'));
        $view->headScript()->appendFile($view->assetUrl('js/watermark.js', 'Watermarker'));
    }

    /**
     * Handle media creation event - apply watermarks to eligible new media
     *
     * @param Event $event
     */
    public function handleMediaCreated(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: handleMediaCreated called');

        $media = $event->getParam('response')->getContent();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Check if watermarking is enabled and should be applied on upload
        if (!$settings->get('watermarker_enabled', true)) {
            $logger->info('Watermarker: Watermarking is disabled globally');
            return;
        }

        if (!$settings->get('watermarker_apply_on_upload', true)) {
            $logger->info('Watermarker: Watermarking on upload is disabled');
            return;
        }

        $logger->info(sprintf(
            'Watermarker: Processing media ID %s for watermarking',
            $media->id()
        ));

        // Check if we have any watermarks configured
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $connection->query("SELECT COUNT(*) FROM watermark_setting WHERE enabled = 1");
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            $logger->info('Watermarker: No active watermark configurations found');
            return;
        }

        $logger->info(sprintf(
            'Watermarker: Found %d active watermark configurations',
            $count
        ));
        
        // Directly process the media for watermarking (no job system)
        // Using a small delay via the isNewUpload flag to allow derivatives to be generated
        $result = $this->watermarkService()->processMedia($media, true);
        
        $logger->info(sprintf(
            'Watermarker: Media processing %s',
            $result ? 'successful' : 'failed'
        ));
    }

    /**
     * Handle media update event - reapply watermarks if needed
     *
     * @param Event $event
     */
    public function handleMediaUpdated(Event $event)
    {
        $media = $event->getParam('response')->getContent();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Check if watermarking is enabled
        if (!$settings->get('watermarker_enabled', true)) {
            return;
        }

        $this->watermarkService()->processMedia($media);
    }

    /**
     * Handle media hydration event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaHydrated(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: handleMediaHydrated called');

        // Get the request and entity from the event
        $entity = $event->getParam('entity');
        $request = $event->getParam('request');

        if (!$entity) {
            $logger->info('Watermarker: No entity in hydration event');
            return;
        }

        // Only process on create operations
        if ($request && $request->getOperation() !== 'create') {
            $logger->info('Watermarker: Skipping non-create operation: ' . $request->getOperation());
            return;
        }

        // Get the media representation
        try {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $media = $api->read('media', $entity->getId())->getContent();

            $logger->info(sprintf(
                'Watermarker: Processing hydrated media ID %s, type %s',
                $media->id(),
                $media->mediaType()
            ));

            $settings = $this->getServiceLocator()->get('Omeka\Settings');

            // Check if watermarking is enabled
            if (!$settings->get('watermarker_enabled', true)) {
                $logger->info('Watermarker: Watermarking is disabled globally');
                return;
            }

            if (!$settings->get('watermarker_apply_on_upload', true)) {
                $logger->info('Watermarker: Watermarking on upload is disabled');
                return;
            }

            // Check if we have any watermarks configured
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $stmt = $connection->query("SELECT COUNT(*) FROM watermark_setting WHERE enabled = 1");
            $count = (int)$stmt->fetchColumn();

            if ($count === 0) {
                $logger->info('Watermarker: No active watermark configurations found');
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Found %d active watermark configurations',
                $count
            ));

            // Directly process the media for watermarking (no job system)
            // Using a small delay via the isNewUpload flag to allow derivatives to be generated
            $result = $this->watermarkService()->processMedia($media, true);
            
            $logger->info(sprintf(
                'Watermarker: Media processing in handleMediaHydrated %s',
                $result ? 'successful' : 'failed'
            ));
        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error processing hydrated media: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Handle media persisted event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaPersisted(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: handleMediaPersisted called');

        // Get the entity from the event
        $entity = $event->getTarget();

        if (!$entity) {
            $logger->info('Watermarker: No entity in persistence event');
            return;
        }

        $logger->info(sprintf(
            'Watermarker: Processing persisted media ID %s',
            $entity->getId()
        ));

        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Check if watermarking is enabled
        if (!$settings->get('watermarker_enabled', true)) {
            $logger->info('Watermarker: Watermarking is disabled globally');
            return;
        }

        if (!$settings->get('watermarker_apply_on_upload', true)) {
            $logger->info('Watermarker: Watermarking on upload is disabled');
            return;
        }

        // Check if we have any watermarks configured
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $connection->query("SELECT COUNT(*) FROM watermark_setting WHERE enabled = 1");
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            $logger->info('Watermarker: No active watermark configurations found');
            return;
        }

        $logger->info(sprintf(
            'Watermarker: Found %d active watermark configurations',
            $count
        ));

        // Get media representation for the entity
        try {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $media = $api->read('media', $entity->getId())->getContent();

            // Directly process the media for watermarking (no job system)
            // Using a small delay via the isNewUpload flag to allow derivatives to be generated
            $result = $this->watermarkService()->processMedia($media, true);
            
            $logger->info(sprintf(
                'Watermarker: Media processing in handleMediaPersisted %s',
                $result ? 'successful' : 'failed'
            ));
        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error processing persisted media: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Get the watermark service
     *
     * @return \Watermarker\Service\WatermarkService
     */
    protected function watermarkService()
    {
        return $this->getServiceLocator()->get('Watermarker\WatermarkService');
    }

    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(Form\ConfigForm::class);

        return $renderer->formCollection($form, false);
    }

    /**
     * Handle configuration form submission.
     *
     * @param AbstractController $controller
     * @return bool
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $formElementManager = $this->getServiceLocator()->get('FormElementManager');
        $form = $formElementManager->get(Form\ConfigForm::class);
        $form->setData($controller->params()->fromPost());

        if (!$form->isValid()) {
            return false;
        }

        $formData = $form->getData();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('watermarker_enabled', isset($formData['watermark_enabled']));
        $settings->set('watermarker_apply_on_upload', isset($formData['apply_on_upload']));
        $settings->set('watermarker_apply_on_import', isset($formData['apply_on_import']));

        return true;
    }
}