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
     * @var object Track uploaded temp file for later processing
     */
    protected $tempFileUploaded = null;
    
    /**
     * @var bool Track whether derivatives have been created
     */
    protected $derivativesCreated = false;
    
    /**
     * @var array Information about the last stored file
     */
    protected $lastStoredFile = null;
    
    /**
     * @var int ID of the last hydrated media entity
     */
    protected $lastHydratedMediaId = null;

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
        
        // Listen specifically for file uploads
        $sharedEventManager->attach(
            'Omeka\File\Ingester\Upload',
            'upload.post',
            [$this, 'handleMediaUploaded']
        );
        
        // Also listen to derivative creation events
        $sharedEventManager->attach(
            'Omeka\File\TempFileFactory',
            'create_derivatives.post',
            [$this, 'handleDerivativesCreated']
        );
        
        // Listen to the stored event on the File/Store adapter
        $sharedEventManager->attach(
            'Omeka\File\Store\Filesystem',
            'store.post',
            [$this, 'handleFileStored']
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
        // This event is no longer the primary handler for watermarking
        // We rely on the handleMediaPersisted event instead to avoid timing issues
    }

    /**
     * Handle media update event - reapply watermarks if needed
     *
     * @param Event $event
     */
    public function handleMediaUpdated(Event $event)
    {
        // Media updates may need re-watermarking but we use the same
        // approach as for new uploads - the persisted event will handle it
    }

    /**
     * Handle media hydration event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaHydrated(Event $event)
    {
        // This event is no longer needed as we use the persisted event
    }

    /**
     * Handle media persisted event - another opportunity to apply watermarks
     *
     * @param Event $event
     */
    public function handleMediaPersisted(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        
        // Get the entity from the event
        $entity = $event->getTarget();
        
        if (!$entity) {
            return;
        }
        
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        
        // Check if watermarking is enabled
        if (!$settings->get('watermarker_enabled', true)) {
            return;
        }
        
        if (!$settings->get('watermarker_apply_on_upload', true)) {
            return;
        }
        
        // This is the MOST RELIABLE point to apply watermarks!
        // Schedule watermarking to run after the response is sent
        register_shutdown_function(function() use ($entity, $logger) {
            // Wait for derivatives to be generated
            sleep(10);
            
            try {
                // Get the API manager and watermark service
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $watermarkService = $this->watermarkService();
                
                // Get the media representation
                $media = $api->read('media', $entity->getId())->getContent();
                
                // Get the first available watermark
                $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
                $watermarkConfig = $connection->fetchAssoc($sql);
                
                if (!$watermarkConfig) {
                    return;
                }
                
                // Apply the watermark directly
                $watermarkService->applyWatermarkDirectly($media, $watermarkConfig);
            } catch (\Exception $e) {
                $logger->err(sprintf(
                    'Watermarker: Error in watermarking: %s',
                    $e->getMessage()
                ));
            }
        });
    }

    /**
     * Handle media upload event
     *
     * @param Event $event
     */
    public function handleMediaUploaded(Event $event)
    {
        // No longer needed as we use the persisted event
    }
    
    /**
     * Handle derivatives created event
     *
     * @param Event $event
     */
    public function handleDerivativesCreated(Event $event)
    {
        // No longer needed as we use the persisted event
    }
    
    /**
     * Handle file stored event
     * 
     * This is triggered after a file is stored in the filesystem
     *
     * @param Event $event
     */
    public function handleFileStored(Event $event)
    {
        // No longer needed as we use the persisted event
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