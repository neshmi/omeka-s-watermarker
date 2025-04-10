<?php declare(strict_types=1);
/**
 * Watermarker
 *
 * A module for Omeka S that adds watermarking capabilities to uploaded and imported media.
 *
 * @copyright Copyright 2025, Your Name
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GPLv3 or later
 */

namespace Watermarker;

use Common\Form\Element;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\Resource;
use Omeka\Module\AbstractModule;
use Watermarker\Form\Admin\BatchEditFieldset;
use Watermarker\Entity\WatermarkAssignment;
use Watermarker\Entity\WatermarkSet;

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

        // Create watermark set table
        $connection->exec("
            CREATE TABLE watermark_set (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Create watermark table
        $connection->exec("
            CREATE TABLE watermark_setting (
                id INT AUTO_INCREMENT NOT NULL,
                set_id INT NOT NULL,
                type VARCHAR(50) NOT NULL, /* landscape, portrait, square, all */
                media_id INT NOT NULL,
                position VARCHAR(50) NOT NULL,
                opacity DECIMAL(3,2) NOT NULL,
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_watermark_set FOREIGN KEY (set_id) REFERENCES watermark_set(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Create table for item/item set watermark assignments
        $connection->exec("
            CREATE TABLE watermark_assignment (
                id INT AUTO_INCREMENT NOT NULL,
                resource_id INT NOT NULL,
                resource_type VARCHAR(50) NOT NULL, /* items, item_sets, media */
                watermark_set_id INT NULL, /* NULL means default watermark set */
                explicitly_no_watermark TINYINT(1) NOT NULL DEFAULT 0, /* If true, no watermark should be applied regardless of inheritance */
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY (resource_id, resource_type),
                CONSTRAINT fk_watermark_assignment FOREIGN KEY (watermark_set_id) REFERENCES watermark_set(id) ON DELETE SET NULL
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

        // Drop in reverse order due to foreign key constraints
        $connection->exec('DROP TABLE IF EXISTS watermark_assignment');
        $connection->exec('DROP TABLE IF EXISTS watermark_setting');
        $connection->exec('DROP TABLE IF EXISTS watermark_set');
        $connection->exec('DROP TABLE IF EXISTS watermark_setting_old');
    }

    /**
     * Upgrade this module.
     *
     * @param string $oldVersion
     * @param string $newVersion
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $logger = $serviceLocator->get('Omeka\Logger');

        if (version_compare($oldVersion, '1.1.0', '<')) {
            // Perform migration directly in the upgrade method
            try {
                $logger->info('Watermarker upgrade: Starting migration to multiple watermarks');

                // Start a transaction
                try {
                    $connection->beginTransaction();
                } catch (\Exception $transactionException) {
                    $logger->err(sprintf('Watermarker upgrade: Error starting transaction: %s', $transactionException->getMessage()));
                    // Continue without transaction if it fails
                }

                // Check if the old watermark_setting table exists and has the old structure
                $oldTableExists = false;
                try {
                    // Check if the watermark_setting table exists
                    $sql = "SHOW TABLES LIKE 'watermark_setting'";
                    $stmt = $connection->query($sql);
                    $tableExists = (bool) $stmt->fetchColumn();

                    if ($tableExists) {
                        // Check if it has the old structure (has 'orientation' column)
                        $sql = "SHOW COLUMNS FROM watermark_setting LIKE 'orientation'";
                        $stmt = $connection->query($sql);
                        $oldTableExists = (bool) $stmt->fetchColumn();
                    }
                } catch (\Exception $e) {
                    $oldTableExists = false;
                }

                if (!$oldTableExists) {
                    // Only roll back if there's an active transaction
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                    $logger->info('Watermarker upgrade: No migration needed.');
                    return;
                }

                // Create new tables
                $logger->info('Watermarker upgrade: Creating new watermark tables');

                // Rename the old table
                $connection->exec("RENAME TABLE watermark_setting TO watermark_setting_old");

                // Create watermark set table
                $connection->exec("
                    CREATE TABLE watermark_set (
                        id INT AUTO_INCREMENT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        is_default TINYINT(1) NOT NULL DEFAULT 0,
                        enabled TINYINT(1) NOT NULL DEFAULT 1,
                        created DATETIME NOT NULL,
                        modified DATETIME DEFAULT NULL,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // Create watermark table
                $connection->exec("
                    CREATE TABLE watermark_setting (
                        id INT AUTO_INCREMENT NOT NULL,
                        set_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL,
                        media_id INT NOT NULL,
                        position VARCHAR(50) NOT NULL,
                        opacity DECIMAL(3,2) NOT NULL,
                        created DATETIME NOT NULL,
                        modified DATETIME DEFAULT NULL,
                        PRIMARY KEY (id),
                        CONSTRAINT fk_watermark_set FOREIGN KEY (set_id) REFERENCES watermark_set(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // Create assignment table
                $connection->exec("
                    CREATE TABLE watermark_assignment (
                        id INT AUTO_INCREMENT NOT NULL,
                        resource_id INT NOT NULL,
                        resource_type VARCHAR(50) NOT NULL,
                        watermark_set_id INT NULL,
                        created DATETIME NOT NULL,
                        modified DATETIME DEFAULT NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY (resource_id, resource_type),
                        CONSTRAINT fk_watermark_assignment FOREIGN KEY (watermark_set_id) REFERENCES watermark_set(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // Migrate existing data
                // Get old watermarks
                $sql = "SELECT * FROM watermark_setting_old ORDER BY id ASC";
                $stmt = $connection->query($sql);
                $oldWatermarks = $stmt->fetchAll();

                $count = 0;
                if (!empty($oldWatermarks)) {
                    // Create a default watermark set
                    $now = date('Y-m-d H:i:s');
                    $sql = "INSERT INTO watermark_set (name, is_default, enabled, created)
                            VALUES ('Default Watermark Set', 1, 1, :created)";
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('created', $now);
                    $stmt->execute();

                    $setId = $connection->lastInsertId();

                    // Migrate watermarks
                    foreach ($oldWatermarks as $oldWatermark) {
                        // Only migrate enabled watermarks
                        if (!isset($oldWatermark['enabled']) || !$oldWatermark['enabled']) {
                            continue;
                        }

                        // Map old orientation to new type
                        $type = isset($oldWatermark['orientation']) && $oldWatermark['orientation'] === 'all'
                              ? 'all'
                              : (isset($oldWatermark['orientation']) ? $oldWatermark['orientation'] : 'all');

                        // Insert into new table
                        $sql = "INSERT INTO watermark_setting (set_id, type, media_id, position, opacity, created, modified)
                                VALUES (:set_id, :type, :media_id, :position, :opacity, :created, :modified)";
                        $stmt = $connection->prepare($sql);
                        $stmt->bindValue('set_id', $setId);
                        $stmt->bindValue('type', $type);
                        $stmt->bindValue('media_id', $oldWatermark['media_id']);
                        $stmt->bindValue('position', $oldWatermark['position']);
                        $stmt->bindValue('opacity', $oldWatermark['opacity']);
                        $stmt->bindValue('created', isset($oldWatermark['created']) ? $oldWatermark['created'] : $now);
                        $stmt->bindValue('modified', isset($oldWatermark['modified']) ? $oldWatermark['modified'] : null);
                        $stmt->execute();

                        $count++;
                    }
                }

                // Commit the transaction
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->commit();
                    } catch (\Exception $commitException) {
                        $logger->err(sprintf('Watermarker upgrade: Error committing transaction: %s', $commitException->getMessage()));
                    }
                }
                $logger->info(sprintf('Watermarker upgrade: Migrated %d existing watermark(s).', $count));
                $logger->info('Watermarker upgrade: Migration completed successfully.');

            } catch (\Exception $e) {
                // Rollback on error
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (\Exception $rollbackException) {
                        $logger->err(sprintf('Watermarker upgrade: Rollback error: %s', $rollbackException->getMessage()));
                    }
                }

                $logger->err(sprintf('Watermarker upgrade: Migration failed: %s', $e->getMessage()));
                $logger->err($e->getTraceAsString());
            }
        }

        if (version_compare($oldVersion, '1.2.0', '<')) {
            // Add explicitly_no_watermark column
            $logger->info('Watermarker upgrade: Adding explicitly_no_watermark column to watermark_assignment table');

            try {
                // Check if the column already exists
                $columnExists = false;
                try {
                    $sql = "SHOW COLUMNS FROM watermark_assignment LIKE 'explicitly_no_watermark'";
                    $stmt = $connection->query($sql);
                    $columnExists = (bool) $stmt->fetchColumn();
                } catch (\Exception $e) {
                    $logger->err('Watermarker upgrade: Error checking for column: ' . $e->getMessage());
                }

                if (!$columnExists) {
                    $sql = "ALTER TABLE watermark_assignment
                            ADD COLUMN explicitly_no_watermark TINYINT(1) NOT NULL DEFAULT 0";
                    $connection->exec($sql);
                    $logger->info('Watermarker upgrade: Column added successfully');
                } else {
                    $logger->info('Watermarker upgrade: Column already exists, skipping');
                }
            } catch (\Exception $e) {
                $logger->err('Watermarker upgrade: Error adding column: ' . $e->getMessage());
                $logger->err($e->getTraceAsString());
            }
        }
    }

    /**
     * Attach shared event listeners for this module
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        // Also listen to after.save.media for existing items
        $sharedEventManager->attach(
            'Omeka\Entity\Media',
            'entity.persist.post',
            [$this, 'handleMediaPersisted']
        );

        // Attach to resource adapters for API operations
        $adapters = [
            \Omeka\Api\Adapter\ItemAdapter::class,
            \Omeka\Api\Adapter\MediaAdapter::class,
            \Omeka\Api\Adapter\ItemSetAdapter::class,
        ];

        foreach ($adapters as $adapter) {
            // Handle resource updates through the API
            $sharedEventManager->attach(
                $adapter,
                'api.hydrate.pre',
                [$this, 'handleResourceUpdate']
            );

            // Handle resource creation and updates
            $sharedEventManager->attach(
                $adapter,
                'api.create.pre',
                [$this, 'handleCreateUpdateResource']
            );

            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleCreateUpdateResource']
            );
        }

        // Attach to controllers for form handling
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\Media',
            'Omeka\Controller\Admin\ItemSet',
        ];

        foreach ($controllers as $controller) {
            // Add watermark form to resource forms
            $sharedEventManager->attach(
                $controller,
                'view.add.form.advanced',
                [$this, 'addResourceFormElements']
            );

            $sharedEventManager->attach(
                $controller,
                'view.edit.form.advanced',
                [$this, 'addResourceFormElements']
            );

            // Add watermark info to resource view
            $sharedEventManager->attach(
                $controller,
                'view.show.sidebar',
                [$this, 'handleViewShowAfter']
            );
        }

        // Add watermark module to admin navigation
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.layout',
            [$this, 'addAdminNavigation']
        );

        // Handle media processing
        $sharedEventManager->attach(
            'Omeka\File\TempFile',
            'create_derivative.post',
            [$this, 'handleDerivativesCreated']
        );

        $sharedEventManager->attach(
            'Omeka\File\TempFile',
            'add_derivative.post',
            [$this, 'handleDerivativesCreated']
        );
    }

    /**
     * Handle the view show after event
     *
     * @param Event $event
     */
    public function handleViewShowAfter(Event $event)
    {
        $view = $event->getTarget();
        $resource = $view->resource;

        if (!$resource) {
            return;
        }

        // Get assignment service directly
        $assignmentService = $this->getServiceLocator()->get('Watermarker\Service\AssignmentService');

        // Get current assignment
        $currentAssignment = $assignmentService->getAssignment($resource->resourceName(), $resource->id());

        // Get database connection for watermark sets
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $connection->prepare('SELECT id, name FROM watermark_set WHERE enabled = 1 ORDER BY name');
        $stmt->execute();
        $watermarkSets = $stmt->fetchAll();

        // Determine which watermark set is being used
        $watermarkSetName = 'Default';
        $watermarkSetId = null;

        if ($currentAssignment) {
            if ($currentAssignment['explicitly_no_watermark']) {
                $watermarkSetName = 'None (explicitly disabled)';
            } else if ($currentAssignment['watermark_set_id']) {
                $watermarkSetId = $currentAssignment['watermark_set_id'];
                // Find the name of the assigned watermark set
                foreach ($watermarkSets as $set) {
                    if ($set['id'] == $watermarkSetId) {
                        $watermarkSetName = $set['name'];
                        break;
                    }
                }
            }
        }

        // Display the watermark information in the sidebar
        echo '<div class="meta-group">';
        echo '<h4>' . $view->translate('Watermark') . '</h4>';
        echo '<div class="value">';
        echo $view->translate('Current watermark:') . ' <strong>' . $view->escapeHtml($watermarkSetName) . '</strong>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Add watermark module link to admin navigation
     *
     * @param Event $event
     */
    public function addAdminNavigation(Event $event)
    {
        $view = $event->getTarget();
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: addAdminNavigation called');

        // Add CSS for watermark admin page
        $view->headLink()->appendStylesheet($view->assetUrl('css/watermarker.css', 'Watermarker'));

        // Add watermark link to main navigation
        $navigation = $view->navigation();
        $navigation->addPage([
            'label' => 'Watermarks',
            'uri' => '/admin/watermarker',
            'resource' => 'Watermarker\Controller\Admin\Index',
            'privilege' => 'browse',
            'class' => 'o-icon-fa-image'
        ]);

        $view->headScript()->appendScript($script, 'text/javascript', ['defer' => true]);

        $logger->info('Watermarker: Admin navigation setup complete');
    }

    /**
     * Add watermark tab to item edit page
     *
     * @param Event $event
     */
    public function addItemWatermarkTabEdit(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: addItemWatermarkTabEdit triggered on ' . $event->getName());

        $view = $event->getTarget();
        $item = $view->item;

        if (!$item) {
            $logger->err('Watermarker: No item found in view');
            // Even when no item is found, we'll still inject an empty data div for debugging
            echo "<!-- Watermarker: No item found! -->\n";
            echo '<div id="watermarker-data" data-watermarker=\'{"error":"No item found"}\' style="display:none;"></div>';
            return;
        }

        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        // Check if this item has a watermark assignment
        $sql = "SELECT * FROM watermark_assignment
                WHERE resource_id = :resource_id AND resource_type = 'item'";
        $stmt = $connection->prepare($sql);
        $stmt->bindValue('resource_id', $item->id());
        $stmt->execute();
        $assignment = $stmt->fetch();

        // Create direct URL to bypass router issues
        $assignUrl = '/admin/watermarker/assign/item/' . $item->id();

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
            $watermarkInfo = 'Watermarking disabled for this item';
        } else {
            $watermarkInfo = 'Using default watermark settings';
        }

        // Store assignment information in a data attribute for JavaScript to use
        $watermarker_data = json_encode([
            'resourceId' => $item->id(),
            'resourceType' => 'item',
            'watermarkInfo' => $watermarkInfo,
            'assignUrl' => $assignUrl
        ]);

        // For template purposes, create the HTML structure that will be used by JS
        $itemHtml = '<div class="field">
                    <div class="field-meta">
                        <label>Watermark Settings</label>
                    </div>
                    <div class="inputs">
                        <div class="value">
                            <p class="watermark-status"></p>
                            <a href="" class="button watermark-edit-link" target="_blank">Edit Watermark Settings</a>
                        </div>
                    </div>
                </div>';

        // Inject the data div with debugging information and template
        echo "<!-- Watermarker: Injecting data for item ID " . $item->id() . " -->\n";
        echo '<div id="watermarker-data" data-watermarker=\'' . $watermarker_data . '\' style="display:none;">Watermarker data present</div>';
        // Also inject the template data for JavaScript to use
        echo '<div id="watermarker-template" style="display:none;">' . $itemHtml . '</div>';

        echo "<!-- Watermarker: Data injection complete -->\n";
    }

    /**
     * Add watermark tab to item set edit page
     *
     * @param Event $event
     */
    public function addItemSetWatermarkTabEdit(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: addItemSetWatermarkTabEdit triggered on ' . $event->getName());

        $view = $event->getTarget();
        $itemSet = $view->itemSet;

        // Add required JavaScript only
        $view->headScript()->appendFile($view->assetUrl('js/watermarker.js', 'Watermarker'));

        if (!$itemSet) {
            return;
        }

        // Get database connection
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        // Get assignment service
        $assignmentService = $this->getServiceLocator()->get('Watermarker\Service\AssignmentService');

        // Get watermark sets
        $stmt = $connection->prepare('SELECT id, name, is_default FROM watermark_set WHERE enabled = 1 ORDER BY name');
        $stmt->execute();
        $watermarkSets = $stmt->fetchAll();

        // Get current assignment
        $currentAssignment = $assignmentService->getAssignment('item_sets', $itemSet->id());

        // Create direct URL to bypass router issues
        $assignUrl = sprintf('/admin/watermarker/assign/%s/%s',
            $itemSet->resourceName() === 'item_sets' ? 'item-set' : 'item',
            $itemSet->id()
        );

        // Log the generated URL
        $logger->info(sprintf('Watermarker: Generated assign URL: %s', $assignUrl));

        // Prepare data for JavaScript
        $watermarkData = [
            'resourceType' => 'item-set',
            'resourceId' => $itemSet->id(),
            'watermarkSets' => $watermarkSets,
            'currentAssignment' => $currentAssignment ? $currentAssignment['watermark_set_id'] : null,
            'assignUrl' => $assignUrl
        ];

        // Add data div for JavaScript
        echo '<div id="watermarker-data" data-watermarker=\'' . json_encode($watermarkData) . '\'></div>';

        // Add template div for JavaScript
        $template = '<div class="field">' .
            '<div class="field-meta">' .
            '<label>Watermark Set</label>' .
            '</div>' .
            '<div class="inputs">' .
            '<div class="value">' .
            '<select id="watermark-set-select" class="watermark-set-select">' .
            '<option value="">None</option>' .
            '<option value="default">Default</option>';

        // Add watermark sets to the template
        foreach ($watermarkSets as $set) {
            $template .= '<option value="' . $set['id'] . '">' . $view->escapeHtml($set['name']) .
                        ($set['is_default'] ? ' (Default)' : '') . '</option>';
        }

        $template .= '</select>' .
            '<button type="button" class="button watermark-save-button">Save</button>' .
            '</div>' .
            '</div>' .
            '</div>';

        echo '<div id="watermarker-template" style="display: none;">' . $template . '</div>';
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
        $logger->info('Watermarker: Media persisted event triggered');

        // Get the entity from the event
        $entity = $event->getTarget();

        if (!$entity) {
            $logger->err('Watermarker: No entity in persisted event');
            return;
        }

        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        // Check if watermarking is enabled
        if (!$settings->get('watermarker_enabled', true)) {
            $logger->info('Watermarker: Watermarking disabled in settings');
            return;
        }

        if (!$settings->get('watermarker_apply_on_upload', true)) {
            $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
            return;
        }

        // This is our fallback approach - wait for derivatives to be generated
        // and then try to watermark them directly
        $logger->info(sprintf('Watermarker: Scheduling watermark for media ID: %s', $entity->getId()));

        // Use register_shutdown_function to ensure this runs after the response
        register_shutdown_function(function() use ($entity, $logger) {
            try {
                // Wait for derivatives to be generated - use a longer wait time
                $logger->info('Watermarker: Waiting 25 seconds for derivatives to be generated...');
                sleep(25);

                // Get needed services
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                $connection = $this->getServiceLocator()->get('Omeka\Connection');
                $watermarkService = $this->watermarkService();

                // Get the media representation
                $media = $api->read('media', $entity->getId())->getContent();

                // Check if this is an image type we support
                $mediaType = $media->mediaType();
                if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'])) {
                    $logger->info(sprintf('Watermarker: Skipping unsupported media type: %s', $mediaType));
                    return;
                }

                $logger->info(sprintf('Watermarker: Processing media ID: %s (type: %s)', $entity->getId(), $mediaType));

                // Get derivatives
                $derivatives = $media->thumbnailUrls();
                if (empty($derivatives) || !isset($derivatives['large'])) {
                    $logger->err('Watermarker: No large derivative found for media');
                    return;
                }

                $logger->info(sprintf('Watermarker: Found derivatives: %s', implode(', ', array_keys($derivatives))));

                // Check for resource-specific watermark assignment
                $parentItem = $media->item();
                $resourceType = 'item';
                $resourceId = $parentItem ? $parentItem->id() : null;

                if (!$resourceId) {
                    $logger->warn('Watermarker: Media has no parent item, using default watermark');
                } else {
                    $logger->info(sprintf('Watermarker: Checking watermark assignment for item ID: %s', $resourceId));
                }

                // Get the watermark assignment for this resource
                $assignment = $watermarkService->getWatermarkAssignment($resourceId, $resourceType);

                // If explicitly set to no watermark, skip
                if ($assignment && isset($assignment['explicitly_no_watermark']) && $assignment['explicitly_no_watermark']) {
                    $logger->info('Watermarker: Resource is explicitly set to have no watermark');
                    return;
                }

                // Get the appropriate watermark for this media
                $resourceSetId = null;
                if ($assignment && isset($assignment['watermark_set_id'])) {
                    $resourceSetId = $assignment['watermark_set_id'];
                    $logger->info(sprintf('Watermarker: Found specific watermark set ID: %s', $resourceSetId));
                }

                // Get the watermark configuration
                $watermarkConfig = $watermarkService->getWatermarkForMedia($media, $resourceSetId);

                // If no watermark config found, we can't proceed
                if (!$watermarkConfig) {
                    $logger->info('Watermarker: No applicable watermark configuration found');
                    return;
                }

                $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s',
                    $watermarkConfig['id']));

                // Get media entity to access storage ID
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $entity->getId());

                if (!$mediaEntity) {
                    $logger->err('Watermarker: Media entity not found');
                    return;
                }

                $storageId = $mediaEntity->getStorageId();

                // Try to find the large derivative
                $largePath = null;
                $possiblePaths = [
                    OMEKA_PATH . '/files/large/' . $storageId,
                    OMEKA_PATH . '/files/large/' . $storageId . '.jpg',
                    OMEKA_PATH . '/files/large/' . $storageId . '.jpeg',
                    OMEKA_PATH . '/files/large/' . $storageId . '.png',
                    OMEKA_PATH . '/files/large/' . $storageId . '.webp',
                    '/var/www/html/files/large/' . $storageId,
                    '/var/www/html/files/large/' . $storageId . '.jpg',
                    '/var/www/html/files/large/' . $storageId . '.jpeg',
                    '/var/www/html/files/large/' . $storageId . '.png',
                    '/var/www/html/files/large/' . $storageId . '.webp',
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $largePath = $path;
                        $logger->info(sprintf('Watermarker: Found large derivative at: %s', $path));
                        break;
                    }
                }

                // If still not found, try glob with more aggressive pattern matching
                if (!$largePath) {
                    // First try exact storage ID with any extension
                    $globPattern = OMEKA_PATH . '/files/large/' . $storageId . '*';
                    $matches = glob($globPattern);

                    if (!empty($matches)) {
                        $largePath = $matches[0];
                        $logger->info(sprintf('Watermarker: Found via glob: %s', $largePath));
                    } else {
                        // Try alternate location
                        $globPattern = '/var/www/html/files/large/' . $storageId . '*';
                        $matches = glob($globPattern);

                        if (!empty($matches)) {
                            $largePath = $matches[0];
                            $logger->info(sprintf('Watermarker: Found via alternate glob: %s', $largePath));
                        } else {
                            // Try searching for partial storage ID match (first 16 chars)
                            $partialId = substr($storageId, 0, 16);
                            $logger->info(sprintf('Watermarker: Trying partial storage ID: %s', $partialId));

                            $globPattern = OMEKA_PATH . '/files/large/' . $partialId . '*';
                            $matches = glob($globPattern);

                            if (!empty($matches)) {
                                $largePath = $matches[0];
                                $logger->info(sprintf('Watermarker: Found via partial ID glob: %s', $largePath));
                            } else {
                                // Try alternate location with partial ID
                                $globPattern = '/var/www/html/files/large/' . $partialId . '*';
                                $matches = glob($globPattern);

                                if (!empty($matches)) {
                                    $largePath = $matches[0];
                                    $logger->info(sprintf('Watermarker: Found via alternate partial ID glob: %s', $largePath));
                                }
                            }
                        }
                    }
                }

                if (!$largePath) {
                    $logger->err('Watermarker: Could not find large derivative file');
                    return;
                }

                // Get the watermark asset
                $assetId = $watermarkConfig['media_id'];
                $watermarkAsset = $api->read('assets', $assetId)->getContent();

                if (!$watermarkAsset) {
                    $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                    return;
                }

                // Get watermark asset path
                $assetFilename = basename($watermarkAsset->assetUrl());
                $assetPath = null;
                $possibleAssetPaths = [
                    OMEKA_PATH . '/files/asset/' . $assetFilename,
                    '/var/www/html/files/asset/' . $assetFilename,
                ];

                foreach ($possibleAssetPaths as $path) {
                    if (file_exists($path)) {
                        $assetPath = $path;
                        break;
                    }
                }

                if (!$assetPath) {
                    $logger->err('Watermarker: Could not find watermark asset file on disk');
                    return;
                }

                // Get the file type of the derivative
                $fileInfo = @getimagesize($largePath);
                if (!$fileInfo) {
                    $logger->err('Watermarker: Failed to get image info for large derivative');
                    return;
                }

                $derivativeType = image_type_to_mime_type($fileInfo[2]);
                $logger->info(sprintf('Watermarker: Large derivative is type: %s, dimensions: %dx%d',
                    $derivativeType, $fileInfo[0], $fileInfo[1]
                ));

                // Create image resources
                $mediaImage = $watermarkService->createImageResource($largePath, $derivativeType);
                if (!$mediaImage) {
                    $logger->err('Watermarker: Failed to create image resource from large derivative');
                    return;
                }

                $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
                if (!$watermarkImage) {
                    $logger->err('Watermarker: Failed to create image resource from watermark');
                    imagedestroy($mediaImage);
                    return;
                }

                // Apply watermark
                $logger->info(sprintf(
                    'Watermarker: Applying watermark (position: %s, opacity: %.2f)',
                    $watermarkConfig['position'], (float)$watermarkConfig['opacity']
                ));

                $watermarkService->overlayWatermark(
                    $mediaImage,
                    $watermarkImage,
                    $watermarkConfig['position'],
                    (float)$watermarkConfig['opacity']
                );

                // Create a temp file for the result with proper extension
                $tempDir = sys_get_temp_dir();
                $tempResult = tempnam($tempDir, 'watermarked_');

                // Get file extension from derivative type
                $fileExt = $watermarkService->getExtensionForMimeType($derivativeType);
                if (!empty($fileExt)) {
                    $tempResultWithExt = $tempResult . '.' . $fileExt;
                    rename($tempResult, $tempResultWithExt);
                    $tempResult = $tempResultWithExt;
                    $logger->info(sprintf('Watermarker: Added extension to temp file: %s', $fileExt));
                }

                // Save the watermarked image
                $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

                // Clean up resources
                imagedestroy($mediaImage);
                imagedestroy($watermarkImage);

                if (!$saveSuccess) {
                    $logger->err('Watermarker: Failed to save watermarked image');
                    @unlink($tempResult);
                    return;
                }

                // Verify temp file has content
                if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                        $tempResult,
                        file_exists($tempResult) ? filesize($tempResult) : 0
                    ));

                    // Check for any PHP error messages
                    $lastError = error_get_last();
                    if ($lastError) {
                        $logger->err(sprintf(
                            'Watermarker: Last PHP error: %s in %s on line %d',
                            $lastError['message'],
                            $lastError['file'],
                            $lastError['line']
                        ));
                    }

                    @unlink($tempResult);
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Temp result file created successfully (size: %d bytes)',
                    filesize($tempResult)
                ));

                // Replace the original derivative with the watermarked version
                $copySuccess = false;

                // Check file permissions before copy
                $targetDir = dirname($largePath);
                if (!is_writable($targetDir)) {
                    $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                    // Try to adjust permissions if possible
                    $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                    @chmod($targetDir, 0777);

                    if (!is_writable($targetDir)) {
                        $logger->err('Watermarker: Unable to make target directory writable');
                    }
                }

                // Try file_put_contents first (more reliable in some environments)
                $fileContents = file_get_contents($tempResult);
                if ($fileContents !== false) {
                    $bytesWritten = file_put_contents($largePath, $fileContents);
                    if ($bytesWritten !== false && $bytesWritten > 0) {
                        $logger->info(sprintf(
                            'Watermarker: Successfully wrote %d bytes to large derivative using file_put_contents',
                            $bytesWritten
                        ));
                        $copySuccess = true;
                    } else {
                        $logger->err('Watermarker: Failed to write to large derivative using file_put_contents');

                        // Try copy as fallback
                        if (@copy($tempResult, $largePath)) {
                            $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                            $copySuccess = true;
                        } else {
                            $logger->err(sprintf(
                                'Watermarker: All file write methods failed for large derivative: %s (error: %s)',
                                $largePath,
                                error_get_last()['message'] ?? 'Unknown error'
                            ));
                        }
                    }
                } else {
                    // Try copy as fallback
                    if (@copy($tempResult, $largePath)) {
                        $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: Copy failed for large derivative: %s (error: %s)',
                            $largePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }

                // Clean up temp file
                @unlink($tempResult);

                // Verify final file
                if ($copySuccess && file_exists($largePath)) {
                    $finalSize = filesize($largePath);
                    if ($finalSize < 100) {
                        $logger->err(sprintf(
                            'Watermarker: Final large derivative is too small (%d bytes), likely corrupted',
                            $finalSize
                        ));
                        return;
                    }

                    $logger->info(sprintf(
                        'Watermarker: Final large derivative verified successfully (size: %d bytes)',
                        $finalSize
                    ));
                    $logger->info('Watermarker: Successfully applied watermark to large derivative');
                } else {
                    $logger->err('Watermarker: Failed to verify final watermarked large derivative');
                }

            } catch (\Exception $e) {
                $logger->err(sprintf(
                    'Watermarker: Error in watermarking: %s',
                    $e->getMessage()
                ));
                $logger->err(sprintf(
                    'Watermarker: Error trace: %s',
                    $e->getTraceAsString()
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
     * Handle derivatives created event - this is the ideal place to watermark
     * as it happens right after Omeka creates the derivatives
     *
     * @param Event $event
     */
    public function handleDerivativesCreated(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $tempFile = $event->getTarget();
        $logger->info('Watermarker: Derivatives created event triggered');

        // Dump all event info to debug
        $logger->info(sprintf(
            'Watermarker: Event target class: %s',
            get_class($tempFile)
        ));

        // Check if the event has the expected target structure
        if (!method_exists($tempFile, 'getMediaType')) {
            $logger->err('Watermarker: Event target does not have getMediaType method');

            // Try to extract useful information from the event object
            $params = $event->getParams();
            $name = $event->getName();
            $target = $event->getTarget();

            $logger->info(sprintf(
                'Watermarker: Event details - Name: %s, Target class: %s, Params: %s',
                $name,
                is_object($target) ? get_class($target) : gettype($target),
                print_r($params, true)
            ));
            return;
        }

        try {
            // Get needed services
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $watermarkService = $this->watermarkService();
            $settings = $this->getServiceLocator()->get('Omeka\Settings');

            // Check if watermarking is enabled
            if (!$settings->get('watermarker_enabled', true)) {
                $logger->info('Watermarker: Watermarking disabled in settings');
                return;
            }

            if (!$settings->get('watermarker_apply_on_upload', true)) {
                $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
                return;
            }

            // Check if this is an image
            $mediaType = $tempFile->getMediaType();
            $logger->info(sprintf('Watermarker: Media type from tempFile: %s', $mediaType));

            if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/webp'])) {
                $logger->info(sprintf('Watermarker: Not a supported image type: %s', $mediaType));
                return;
            }

            // Check if getStoragePaths method exists
            if (!method_exists($tempFile, 'getStoragePaths')) {
                $logger->err('Watermarker: TempFile does not have getStoragePaths method');

                // Log available methods on tempFile
                $methods = get_class_methods($tempFile);
                $logger->info(sprintf(
                    'Watermarker: Available methods on tempFile: %s',
                    implode(', ', $methods)
                ));
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Processing new derivatives for file: %s (type: %s)',
                $tempFile->getSourceName(), $mediaType
            ));

            // Get the first available watermark configuration
            $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
            $watermarkConfig = $connection->fetchAssoc($sql);

            if (!$watermarkConfig) {
                $logger->info('Watermarker: No enabled watermark configurations found');
                return;
            }

            $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s (%s)',
                $watermarkConfig['id'], $watermarkConfig['name']
            ));

            // Get the watermark asset
            $assetId = $watermarkConfig['media_id'];
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            if (!$watermarkAsset) {
                $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                return;
            }

            // Get watermark asset path
            $assetFilename = basename($watermarkAsset->assetUrl());
            $assetPath = null;
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
            ];

            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    break;
                }
            }

            if (!$assetPath) {
                $logger->err('Watermarker: Could not find watermark asset file on disk');
                return;
            }

            // Get the large derivative path directly
            $derivativePaths = $tempFile->getStoragePaths();
            $logger->info(sprintf(
                'Watermarker: Available derivative paths: %s',
                print_r($derivativePaths, true)
            ));

            if (!isset($derivativePaths['large'])) {
                $logger->err('Watermarker: No large derivative path available');
                return;
            }

            $largePath = $derivativePaths['large'];
            $logger->info(sprintf('Watermarker: Found large derivative at: %s', $largePath));

            // Make sure the large derivative exists
            if (!file_exists($largePath)) {
                $logger->err('Watermarker: Large derivative file does not exist');

                // Try to find if the directory exists
                $dir = dirname($largePath);
                if (!is_dir($dir)) {
                    $logger->err(sprintf('Watermarker: Directory does not exist: %s', $dir));
                } else {
                    $logger->info(sprintf('Watermarker: Directory exists: %s', $dir));
                    // List files in the directory
                    $files = scandir($dir);
                    $logger->info(sprintf(
                        'Watermarker: Files in directory: %s',
                        implode(', ', $files)
                    ));
                }
                return;
            }

            // Get the file type of the derivative
            $fileInfo = @getimagesize($largePath);
            if (!$fileInfo) {
                $logger->err('Watermarker: Failed to get image info for large derivative');
                return;
            }

            $derivativeType = image_type_to_mime_type($fileInfo[2]);
            $logger->info(sprintf('Watermarker: Large derivative is type: %s, dimensions: %dx%d',
                $derivativeType, $fileInfo[0], $fileInfo[1]
            ));

            // Create image resources
            $mediaImage = $watermarkService->createImageResource($largePath, $derivativeType);
            if (!$mediaImage) {
                $logger->err('Watermarker: Failed to create image resource from large derivative');
                return;
            }

            $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
            if (!$watermarkImage) {
                $logger->err('Watermarker: Failed to create image resource from watermark');
                imagedestroy($mediaImage);
                return;
            }

            // Apply watermark
            $logger->info(sprintf(
                'Watermarker: Applying watermark to large derivative (position: %s, opacity: %.2f)',
                $watermarkConfig['position'], (float)$watermarkConfig['opacity']
            ));

            $watermarkService->overlayWatermark(
                $mediaImage,
                $watermarkImage,
                $watermarkConfig['position'],
                (float)$watermarkConfig['opacity']
            );

            // Create a temp file for the result with proper extension
            $tempDir = sys_get_temp_dir();
            $tempResult = tempnam($tempDir, 'watermarked_');

            // Get file extension from derivative type
            $fileExt = $watermarkService->getExtensionForMimeType($derivativeType);
            if (!empty($fileExt)) {
                $tempResultWithExt = $tempResult . '.' . $fileExt;
                rename($tempResult, $tempResultWithExt);
                $tempResult = $tempResultWithExt;
                $logger->info(sprintf('Watermarker: Added extension to temp file: %s', $fileExt));
            }

            // Save the watermarked image
            $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

            // Clean up resources
            imagedestroy($mediaImage);
            imagedestroy($watermarkImage);

            if (!$saveSuccess) {
                $logger->err('Watermarker: Failed to save watermarked image');
                @unlink($tempResult);
                return;
            }

            // Verify temp file has content
            if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                $logger->err(sprintf(
                    'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                    $tempResult,
                    file_exists($tempResult) ? filesize($tempResult) : 0
                ));
                @unlink($tempResult);
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Temp result file created successfully (size: %d bytes)',
                filesize($tempResult)
            ));

            // Replace the original derivative with the watermarked version
            $copySuccess = false;

            // Check file permissions before copy
            $targetDir = dirname($largePath);
            if (!is_writable($targetDir)) {
                $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                // Try to adjust permissions if possible
                $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                @chmod($targetDir, 0777);

                if (!is_writable($targetDir)) {
                    $logger->err('Watermarker: Unable to make target directory writable');
                }
            }

            // Try file_put_contents first (more reliable in some environments)
            $fileContents = file_get_contents($tempResult);
            if ($fileContents !== false) {
                $bytesWritten = file_put_contents($largePath, $fileContents);
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $logger->info(sprintf(
                        'Watermarker: Successfully wrote %d bytes to large derivative using file_put_contents',
                        $bytesWritten
                    ));
                    $copySuccess = true;
                } else {
                    $logger->err('Watermarker: Failed to write to large derivative using file_put_contents');

                    // Try copy as fallback
                    if (@copy($tempResult, $largePath)) {
                        $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: All file write methods failed for large derivative: %s (error: %s)',
                            $largePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }
            } else {
                // Try copy as fallback
                if (@copy($tempResult, $largePath)) {
                    $logger->info('Watermarker: Successfully copied to large derivative using copy()');
                    $copySuccess = true;
                } else {
                    $logger->err(sprintf(
                        'Watermarker: Copy failed for large derivative: %s (error: %s)',
                        $largePath,
                        error_get_last()['message'] ?? 'Unknown error'
                    ));
                }
            }

            // Clean up temp file
            @unlink($tempResult);

            // Verify final file
            if ($copySuccess && file_exists($largePath)) {
                $finalSize = filesize($largePath);
                if ($finalSize < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Final large derivative is too small (%d bytes), likely corrupted',
                        $finalSize
                    ));
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Final large derivative verified successfully (size: %d bytes)',
                    $finalSize
                ));
                $logger->info('Watermarker: Successfully applied watermark to large derivative');
            } else {
                $logger->err('Watermarker: Failed to verify final watermarked large derivative');
            }

        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error in watermarking derivatives: %s',
                $e->getMessage()
            ));
            $logger->err(sprintf(
                'Watermarker: Error trace: %s',
                $e->getTraceAsString()
            ));
        }
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
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: File stored event triggered');

        // Log details about the event
        $params = $event->getParams();
        $name = $event->getName();
        $target = $event->getTarget();

        $logger->info(sprintf(
            'Watermarker: File stored event details - Name: %s, Target class: %s, Params: %s',
            $name,
            is_object($target) ? get_class($target) : gettype($target),
            print_r($params, true)
        ));

        try {
            // Get the stored file info
            $storedInfo = $event->getParam('stored');

            // Check if we have the necessary information
            if (!$storedInfo || !isset($storedInfo['filename']) || !isset($storedInfo['type'])) {
                $logger->err('Watermarker: Missing stored file information');
                return;
            }

            $filename = $storedInfo['filename'];
            $type = $storedInfo['type'];

            // Only proceed if this is a derivative
            if ($type !== 'large') {
                $logger->info(sprintf('Watermarker: Ignoring non-large derivative: %s', $type));
                return;
            }

            $logger->info(sprintf('Watermarker: File stored - type: %s, filename: %s', $type, $filename));

            // Get needed services
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $watermarkService = $this->watermarkService();
            $settings = $this->getServiceLocator()->get('Omeka\Settings');

            // Check if watermarking is enabled
            if (!$settings->get('watermarker_enabled', true)) {
                $logger->info('Watermarker: Watermarking disabled in settings');
                return;
            }

            if (!$settings->get('watermarker_apply_on_upload', true)) {
                $logger->info('Watermarker: Auto-watermarking on upload disabled in settings');
                return;
            }

            // Get the file path - need to construct based on Omeka's storage structure
            $filePath = OMEKA_PATH . '/files/' . $type . '/' . $filename;
            if (!file_exists($filePath)) {
                // Try alternate path
                $filePath = '/var/www/html/files/' . $type . '/' . $filename;
                if (!file_exists($filePath)) {
                    $logger->err(sprintf('Watermarker: File does not exist: %s', $filePath));
                    return;
                }
            }

            $logger->info(sprintf('Watermarker: Found file at: %s', $filePath));

            // Get the first available watermark configuration
            $sql = "SELECT * FROM watermark_setting WHERE enabled = 1 ORDER BY id ASC LIMIT 1";
            $watermarkConfig = $connection->fetchAssoc($sql);

            if (!$watermarkConfig) {
                $logger->info('Watermarker: No enabled watermark configurations found');
                return;
            }

            $logger->info(sprintf('Watermarker: Using watermark configuration ID: %s (%s)',
                $watermarkConfig['id'], $watermarkConfig['name']
            ));

            // Get the watermark asset
            $assetId = $watermarkConfig['media_id'];
            $watermarkAsset = $api->read('assets', $assetId)->getContent();

            if (!$watermarkAsset) {
                $logger->err(sprintf('Watermarker: Watermark asset not found: %s', $assetId));
                return;
            }

            // Get watermark asset path
            $assetFilename = basename($watermarkAsset->assetUrl());
            $assetPath = null;
            $possibleAssetPaths = [
                OMEKA_PATH . '/files/asset/' . $assetFilename,
                '/var/www/html/files/asset/' . $assetFilename,
            ];

            foreach ($possibleAssetPaths as $path) {
                if (file_exists($path)) {
                    $assetPath = $path;
                    break;
                }
            }

            if (!$assetPath) {
                $logger->err('Watermarker: Could not find watermark asset file on disk');
                return;
            }

            // Get the file info
            $fileInfo = @getimagesize($filePath);
            if (!$fileInfo) {
                $logger->err(sprintf('Watermarker: Failed to get image info for file: %s', $filePath));
                return;
            }

            $derivativeType = image_type_to_mime_type($fileInfo[2]);
            $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $logger->info(sprintf('Watermarker: File is type: %s (extension: %s), dimensions: %dx%d',
                $derivativeType, $fileExt, $fileInfo[0], $fileInfo[1]
            ));

            // Check if file extension matches media type
            $extMimeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif'
            ];

            $expectedMimeType = isset($extMimeMap[$fileExt]) ? $extMimeMap[$fileExt] : null;

            if ($expectedMimeType && $expectedMimeType != $derivativeType) {
                $logger->info(sprintf(
                    'Watermarker: File has %s extension but detected type is %s, using detected type',
                    $fileExt, $derivativeType
                ));
            }

            // Create image resources
            $mediaImage = $watermarkService->createImageResource($filePath, $derivativeType);
            if (!$mediaImage) {
                $logger->err(sprintf('Watermarker: Failed to create image resource from file: %s', $filePath));
                return;
            }

            $watermarkImage = $watermarkService->createImageResource($assetPath, 'image/png');
            if (!$watermarkImage) {
                $logger->err('Watermarker: Failed to create image resource from watermark');
                imagedestroy($mediaImage);
                return;
            }

            // Apply watermark
            $logger->info(sprintf(
                'Watermarker: Applying watermark (position: %s, opacity: %.2f)',
                $watermarkConfig['position'], (float)$watermarkConfig['opacity']
            ));

            $watermarkService->overlayWatermark(
                $mediaImage,
                $watermarkImage,
                $watermarkConfig['position'],
                (float)$watermarkConfig['opacity']
            );

            // Create a temp file for the result
            $tempDir = sys_get_temp_dir();
            $tempResult = tempnam($tempDir, 'watermarked_');

            // Save the watermarked image
            $saveSuccess = $watermarkService->saveImageResource($mediaImage, $tempResult, $derivativeType);

            // Clean up resources
            imagedestroy($mediaImage);
            imagedestroy($watermarkImage);

            if (!$saveSuccess) {
                $logger->err('Watermarker: Failed to save watermarked image');
                @unlink($tempResult);
                return;
            }

            // Verify temp file has content
            if (!file_exists($tempResult) || filesize($tempResult) < 100) {
                $logger->err(sprintf(
                    'Watermarker: Temp result file (%s) is empty or too small (size: %d bytes)',
                    $tempResult,
                    file_exists($tempResult) ? filesize($tempResult) : 0
                ));
                @unlink($tempResult);
                return;
            }

            $logger->info(sprintf(
                'Watermarker: Temp result file created successfully (size: %d bytes)',
                filesize($tempResult)
            ));

            // Replace the original file with the watermarked version
            $copySuccess = false;

            // Check file permissions before copy
            $targetDir = dirname($filePath);
            if (!is_writable($targetDir)) {
                $logger->err(sprintf('Watermarker: Target directory is not writable: %s', $targetDir));

                // Try to adjust permissions if possible
                $logger->info(sprintf('Watermarker: Attempting to change permissions for target directory: %s', $targetDir));
                @chmod($targetDir, 0777);

                if (!is_writable($targetDir)) {
                    $logger->err('Watermarker: Unable to make target directory writable');
                }
            }

            // Try file_put_contents first (more reliable in some environments)
            $fileContents = file_get_contents($tempResult);
            if ($fileContents !== false) {
                $bytesWritten = file_put_contents($filePath, $fileContents);
                if ($bytesWritten !== false && $bytesWritten > 0) {
                    $logger->info(sprintf(
                        'Watermarker: Successfully wrote %d bytes to file using file_put_contents',
                        $bytesWritten
                    ));
                    $copySuccess = true;
                } else {
                    $logger->err('Watermarker: Failed to write to file using file_put_contents');

                    // Try copy as fallback
                    if (@copy($tempResult, $filePath)) {
                        $logger->info('Watermarker: Successfully copied to file using copy()');
                        $copySuccess = true;
                    } else {
                        $logger->err(sprintf(
                            'Watermarker: All file write methods failed for file: %s (error: %s)',
                            $filePath,
                            error_get_last()['message'] ?? 'Unknown error'
                        ));
                    }
                }
            } else {
                // Try copy as fallback
                if (@copy($tempResult, $filePath)) {
                    $logger->info('Watermarker: Successfully copied to file using copy()');
                    $copySuccess = true;
                } else {
                    $logger->err(sprintf(
                        'Watermarker: Copy failed for file: %s (error: %s)',
                        $filePath,
                        error_get_last()['message'] ?? 'Unknown error'
                    ));
                }
            }

            // Clean up temp file
            @unlink($tempResult);

            // Verify final file
            if ($copySuccess && file_exists($filePath)) {
                $finalSize = filesize($filePath);
                if ($finalSize < 100) {
                    $logger->err(sprintf(
                        'Watermarker: Final file is too small (%d bytes), likely corrupted',
                        $finalSize
                    ));
                    return;
                }

                $logger->info(sprintf(
                    'Watermarker: Final file verified successfully (size: %d bytes)',
                    $finalSize
                ));
                $logger->info('Watermarker: Successfully applied watermark to file');
            } else {
                $logger->err('Watermarker: Failed to verify final watermarked file');
            }

        } catch (\Exception $e) {
            $logger->err(sprintf(
                'Watermarker: Error in watermarking: %s',
                $e->getMessage()
            ));
            $logger->err(sprintf(
                'Watermarker: Error trace: %s',
                $e->getTraceAsString()
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
        return $this->getServiceLocator()->get('Watermarker\Service\WatermarkService');
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

    /**
     * Listen for API file uploads
     *
     * @param Event $event
     */
    public function handleIngest(Event $event)
    {
        // Get the request and entity
        $request = $event->getParam('request');
        $entity = $event->getParam('entity');

        if (!$request || !$entity) {
            return;
        }

        // Only process media resources that are files
        if (!$entity instanceof \Omeka\Entity\Media || $entity->getIngester() !== 'upload') {
            return;
        }

        // Check if auto-watermarking is enabled
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if (!$settings->get('watermarker_enabled', true) || !$settings->get('watermarker_apply_on_upload', true)) {
            return;
        }

        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info(sprintf('Watermarker: Processing new upload for media ID %d', $entity->getId()));

        // Schedule watermarking to happen after response
        $mediaId = $entity->getId();

        // Use register_shutdown_function to ensure this runs after the response
        register_shutdown_function(function() use ($mediaId, $logger) {
            try {
                // Wait a moment for the file to be fully processed
                sleep(2);

                $logger->info(sprintf('Watermarker: Applying watermark to media ID %d', $mediaId));

                // Get the watermark applicator service
                $applicator = $this->getServiceLocator()->get('Watermarker\Service\WatermarkApplicator');

                // Apply the watermark
                $result = $applicator->applyWatermark($mediaId);

                if ($result) {
                    $logger->info(sprintf('Watermarker: Successfully applied watermark to media ID %d', $mediaId));
                } else {
                    $logger->warn(sprintf('Watermarker: Failed to apply watermark to media ID %d', $mediaId));
                }
            } catch (\Exception $e) {
                $logger->err('Watermarker: Error in watermarking new upload: ' . $e->getMessage());
                $logger->err($e->getTraceAsString());
            }
        });
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Form\ResourceBatchUpdateForm $form
         * @var \Access\Form\Admin\BatchEditFieldset $fieldset
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $form = $event->getTarget();
        $formElementManager = $services->get('FormElementManager');

        $fieldset = $formElementManager->get(BatchEditFieldset::class, [
            'resource_type' => $event->getTarget()->getOption('resource_type'),
        ]);

        $form->add($fieldset);
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        $view = $event->getTarget();
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-admin.css', 'Access'));
        // $view->headScript()
        //     ->appendFile($assetUrl('js/access-admin.js', 'Access'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addResourceFormElements(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         */
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: addResourceFormElements triggered');

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $view = $event->getTarget();
        $plugins = $services->get('ControllerPluginManager');

        // Get current resource if any
        $resource = $view->vars()->offsetGet('resource');
        $logger->info(sprintf('Watermarker: Resource type: %s, ID: %s',
            $resource ? $resource->resourceName() : 'none',
            $resource ? $resource->id() : 'none'));

        // Get current watermark assignment if any using direct database query
        $currentAssignment = null;
        if ($resource) {
            try {
                $connection = $services->get('Omeka\Connection');
                $stmt = $connection->prepare('
                    SELECT * FROM watermark_assignment
                    WHERE resource_type = :resource_type AND resource_id = :resource_id
                ');
                $stmt->bindValue('resource_type', $resource->resourceName());
                $stmt->bindValue('resource_id', $resource->id());
                $stmt->execute();
                $currentAssignment = $stmt->fetch();

                if ($currentAssignment) {
                    $logger->info(sprintf('Watermarker: Found existing assignment ID %s', $currentAssignment['id']));
                } else {
                    $logger->info('Watermarker: No existing assignment found');
                }
            } catch (\Exception $e) {
                $logger->err('Watermarker: Error checking for existing assignment: ' . $e->getMessage());
            }
        }

        // Prepare value options for watermark sets
        $valueOptions = [
            WatermarkSet::NONE => 'None', // @translate'
            WatermarkSet::DEFAULT => 'Default', // @translate
        ];

        // Get existing watermark sets from the database
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $connection->prepare('SELECT id, name FROM watermark_set WHERE enabled = 1 ORDER BY name');
        $stmt->execute();
        $watermarkSets = $stmt->fetchAll();
        $logger->info(sprintf('Watermarker: Found %d watermark sets', count($watermarkSets)));

        // Add watermark sets to value options
        foreach ($watermarkSets as $set) {
            $valueOptions[$set['id']] = $set['name'];
        }

        // Determine the current value
        $currentValue = WatermarkSet::DEFAULT;
        if ($currentAssignment) {
            if ($currentAssignment['explicitly_no_watermark']) {
                $currentValue = WatermarkSet::NONE;
            } else if ($currentAssignment['watermark_set_id']) {
                $currentValue = $currentAssignment['watermark_set_id'];
            }
        }
        $logger->info(sprintf('Watermarker: Current watermark value: %s', $currentValue));

        // Create the select element for watermark sets
        $watermarkSetElement = new \Laminas\Form\Element\Select('o-watermarker:set');
        $watermarkSetElement
            ->setLabel('Watermark Set') // @translate
            ->setValueOptions($valueOptions)
            ->setAttributes([
                'id' => 'o-watermark-set',
                'value' => $currentValue,
            ]);

        $logger->info('Watermarker: Created form element with name o-watermarker:set');

        // Add hidden inputs for resource type and ID
        $resourceTypeInput = new \Laminas\Form\Element\Hidden('resource_type');
        $resourceTypeInput->setValue($resource ? $resource->resourceName() : '');

        $resourceIdInput = new \Laminas\Form\Element\Hidden('resource_id');
        $resourceIdInput->setValue($resource ? $resource->id() : '');

        $logger->info('Watermarker: Added hidden inputs for resource type and ID');

        // Output the form elements
        echo $view->formRow($watermarkSetElement);
        echo $view->formHidden($resourceTypeInput);
        echo $view->formHidden($resourceIdInput);

        $logger->info('Watermarker: Form elements output complete');
    }


    /**
     * Add watermark form to the item edit page.
     *
     * @param Event $event
     */
    public function addWatermarkFormToItem(Event $event): void
    {
        $view = $event->getTarget();
        $item = $view->resource;
        $resourceId = $item ? $item->id() : null;

        echo $this->getWatermarkFormHtml($view, 'items', $resourceId);
    }

    /**
     * Add watermark form to the item set edit page.
     *
     * @param Event $event
     */
    public function addWatermarkFormToItemSet(Event $event): void
    {
        $view = $event->getTarget();
        $itemSet = $view->resource;
        $resourceId = $itemSet ? $itemSet->id() : null;

        echo $this->getWatermarkFormHtml($view, 'item_sets', $resourceId);
    }

    /**
     * Add watermark form to the media edit page.
     *
     * @param Event $event
     */
    public function addWatermarkFormToMedia(Event $event): void
    {
        $view = $event->getTarget();
        $media = $view->resource;
        $resourceId = $media ? $media->id() : null;

        echo $this->getWatermarkFormHtml($view, 'media', $resourceId);
    }

    /**
     * Get HTML for the watermark form.
     *
     * @param PhpRenderer $view
     * @param string $resourceType
     * @param int $resourceId
     * @return string
     */
    protected function getWatermarkFormHtml($view, $resourceType, $resourceId)
    {
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $router = $this->getServiceLocator()->get('Router');
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        // Get watermark sets from database
        $watermarkSets = [];
        try {
            $stmt = $connection->prepare('SELECT * FROM watermark_set WHERE enabled = 1 ORDER BY name');
            $stmt->execute();
            $watermarkSets = $stmt->fetchAll();
            $logger->info('Watermarker: Found ' . count($watermarkSets) . ' watermark sets');
        } catch (\Exception $e) {
            // Log error but continue
            $logger->err('Watermarker: Error fetching watermark sets: ' . $e->getMessage());
        }

        // Get current assignment
        $currentAssignment = null;
        $currentSetId = null;
        $isExplicitlyNoWatermark = false;
        $isDefault = true;

        try {
            // Get assignment using direct database query
            $stmt = $connection->prepare('
                SELECT * FROM watermark_assignment
                WHERE resource_type = :resource_type AND resource_id = :resource_id
            ');
            $stmt->bindValue('resource_type', $resourceType);
            $stmt->bindValue('resource_id', $resourceId);
            $stmt->execute();
            $assignment = $stmt->fetch();

            if ($assignment) {
                $currentAssignment = $assignment;
                $isDefault = false;

                if ($assignment['explicitly_no_watermark']) {
                    $isExplicitlyNoWatermark = true;
                    $currentSetId = 'none';
                } else if ($assignment['watermark_set_id']) {
                    $currentSetId = $assignment['watermark_set_id'];
                }
            }
        } catch (\Exception $e) {
            // Log error but continue
            $logger->err('Watermarker: Error fetching watermark assignment: ' . $e->getMessage());
            return '';
        }

        // Include required JavaScript
        $view->headScript()->appendFile($view->assetUrl('js/watermarker.js', 'Watermarker'));

        // Generate HTML
        $html = '<div class="field">';
        $html .= '<div class="field-meta">';
        $html .= '<label>' . $view->translate('Watermark Set') . '</label>';
        $html .= '<div class="field-description">' . $view->translate('Apply a watermark to media files associated with this resource.') . '</div>';
        $html .= '</div>';
        $html .= '<div class="inputs">';
        $html .= '<div class="watermark-form" data-resource-type="' . $resourceType . '" data-resource-id="' . $resourceId . '" data-api-url="' . $router->assemble(['action' => 'setAssignment'], ['name' => 'watermarker-api']) . '">';
        $html .= '<select name="watermark_set" id="o-watermark-set" class="watermark-select">';
        $html .= '<option value="none"' . ($isExplicitlyNoWatermark ? ' selected' : '') . '>' . $view->translate('None (no watermark)') . '</option>';
        $html .= '<option value="default"' . ($isDefault ? ' selected' : '') . '>' . $view->translate('Default (inherit from parent)') . '</option>';

        // Add watermark sets to dropdown
        foreach ($watermarkSets as $set) {
            $selected = ($currentSetId == $set['id']) ? ' selected' : '';
            $html .= '<option value="' . $set['id'] . '"' . $selected . '>' . $view->escapeHtml($set['name']) . ($set['is_default'] ? ' (' . $view->translate('Default') . ')' : '') . '</option>';
        }

        $html .= '</select>';

        // Current status
        $html .= '<div class="watermark-status">';
        if ($isExplicitlyNoWatermark) {
            $html .= $view->translate('Watermarking explicitly disabled for this resource.');
        } else if (!$isDefault && $currentSetId) {
            $html .= $view->translate('Using custom watermark set.');
        } else {
            $html .= $view->translate('Using default watermark settings.');
        }
        $html .= '</div>';

        // Save button
        $html .= '<button type="button" class="watermark-save-button button">' . $view->translate('Save Watermark Setting') . '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Handle form submission.
     *
     * @param Event $event
     */
    public function handleFormSubmission(Event $event): void
    {
        $request = $this->getServiceLocator()->get('Request');
        if (!$request->isPost()) {
            return;
        }

        // Process form submission if needed
        // This is a placeholder for now - we'll handle assignments via AJAX
    }

    /**
     * Add watermark section to advanced tab
     *
     * @param Event $event
     */
    public function addWatermarkSection(Event $event): void
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: addWatermarkSection event triggered: ' . $event->getName());

        $view = $event->getTarget();
        $resource = $view->resource;

        if (!$resource) {
            $logger->error('Watermarker: No resource in view');
            return;
        }

        $logger->info('Watermarker: Processing resource ID ' . $resource->id() . ' of type ' . $resource->resourceName());

        // Add watermark section to sections array
        $sectionName = 'watermark';
        $sections = $event->getParam('sections');

        // Add CSS and JavaScript
        $view->headLink()->appendStylesheet($view->assetUrl('css/watermarker.css', 'Watermarker'));
        $view->headScript()->appendFile($view->assetUrl('js/watermarker.js', 'Watermarker'));

        // Get resource type
        $resourceType = $resource->resourceName();

        // Retrieve any existing assignment
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $stmt = $connection->prepare('SELECT * FROM watermark_assignment WHERE resource_type = :type AND resource_id = :id');
        $stmt->bindValue('type', $resourceType);
        $stmt->bindValue('id', $resource->id());
        $stmt->execute();
        $assignment = $stmt->fetch();

        // Get watermark sets
        $stmt = $connection->prepare('SELECT * FROM watermark_set WHERE enabled = 1');
        $stmt->execute();
        $watermarkSets = $stmt->fetchAll();

        // Get router for API URL
        $router = $this->getServiceLocator()->get('Router');
        $apiUrl = $router->assemble(['action' => 'setAssignment'], ['name' => 'watermarker-api']);

        // Build HTML directly
        ob_start();
        ?>
        <div class="section active section-watermark">
            <h4><?php echo $view->translate('Watermark Settings'); ?></h4>
            <div class="watermark-form"
                 data-resource-type="<?php echo $resourceType; ?>"
                 data-resource-id="<?php echo $resource->id(); ?>"
                 data-api-url="<?php echo $apiUrl; ?>">
                <div class="field watermark-setting">
                    <div class="field-meta">
                        <label><?php echo $view->translate('Watermark Setting'); ?></label>
                        <div class="field-description"><?php echo $view->translate('Choose a watermark setting for this resource.'); ?></div>
                    </div>
                    <div class="inputs">
                        <div class="watermark-dropdown">
                            <button class="watermark-dropdown-button" type="button">
                                <?php
                                $selectedText = $view->translate('Default Watermark');
                                $selectedValue = 'default';
                                if ($assignment) {
                                    if ($assignment['explicitly_no_watermark']) {
                                        $selectedText = $view->translate('No Watermark');
                                        $selectedValue = 'none';
                                    } elseif ($assignment['watermark_set_id']) {
                                        foreach ($watermarkSets as $set) {
                                            if ($set['id'] == $assignment['watermark_set_id']) {
                                                $selectedText = $set['name'];
                                                $selectedValue = $set['id'];
                                                break;
                                            }
                                        }
                                    }
                                }
                                echo $selectedText;
                                ?>
                                <span class="dropdown-arrow">▼</span>
                            </button>
                            <div class="watermark-dropdown-content">
                                <div class="watermark-option" data-value="none"><?php echo $view->translate('No Watermark'); ?></div>
                                <div class="watermark-option" data-value="default"><?php echo $view->translate('Default Watermark'); ?></div>
                                <?php foreach ($watermarkSets as $set): ?>
                                    <div class="watermark-option" data-value="<?php echo $set['id']; ?>"><?php echo $set['name']; ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                // Status message
                $statusClass = 'default';
                $statusText = $view->translate('Using default watermark settings.');

                if ($assignment) {
                    if ($assignment['explicitly_no_watermark']) {
                        $statusClass = 'error';
                        $statusText = $view->translate('Watermarking explicitly disabled for this resource.');
                    } elseif ($assignment['watermark_set_id']) {
                        $statusClass = 'success';
                        $setName = '';
                        foreach ($watermarkSets as $set) {
                            if ($set['id'] == $assignment['watermark_set_id']) {
                                $setName = $set['name'];
                                break;
                            }
                        }
                        $statusText = $view->translate('Using custom watermark set: ') . $setName;
                    }
                }
                ?>
                <div class="watermark-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></div>
                <button type="button" class="watermark-save-button button"><?php echo $view->translate('Save Watermark Setting'); ?></button>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        // Add to sections array
        $sections[$sectionName] = $html;
        $event->setParam('sections', $sections);

        $logger->info('Watermarker: Added watermark section to sections array');
    }

    /**
     * Handle resource update to save watermark assignments
     *
     * @param Event $event
     */
    public function handleResourceUpdate(Event $event): void
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Watermarker: handleResourceUpdate triggered');

        $request = $event->getParam('request');
        if (!$request) {
            $logger->info('Watermarker: No request found');
            return;
        }

        $logger->info('Watermarker: Request content: ' . json_encode($request->getContent()));

        // Get the resource ID from the request
        $resourceId = $request->getContent()['o:id'] ?? null;
        if (!$resourceId) {
            $logger->info('Watermarker: Missing resource ID');
            return;
        }

        // Determine resource type from the event target
        $target = $event->getTarget();
        if (!$target) {
            $logger->info('Watermarker: No target in event');
            return;
        }

        // Determine resource type from adapter class
        $adapterClass = get_class($target);
        $resourceType = null;

        if (strpos($adapterClass, '\\ItemAdapter') !== false) {
            $resourceType = 'item';
        } elseif (strpos($adapterClass, '\\ItemSetAdapter') !== false) {
            $resourceType = 'item-set';
        } elseif (strpos($adapterClass, '\\MediaAdapter') !== false) {
            $resourceType = 'media';
        }

        if (!$resourceType) {
            $logger->info('Watermarker: Unknown resource type from adapter: ' . $adapterClass);
            return;
        }

        $logger->info(sprintf('Watermarker: Processing resource type: %s, ID: %s', $resourceType, $resourceId));

        // Get the watermark set value from the request
        $watermarkSetValue = $request->getContent()['o-watermarker:set'] ?? null;
        $logger->info(sprintf('Watermarker: Watermark set value: %s', $watermarkSetValue));

        if ($watermarkSetValue === null) {
            $logger->info('Watermarker: No watermark set value provided');
            return;
        }

        try {
            // Get the assignment service
            $assignmentService = $this->getServiceLocator()->get('Watermarker\Service\AssignmentService');

            // Set the assignment
            $result = $assignmentService->setAssignment(
                $resourceType,
                $resourceId,
                $watermarkSetValue
            );

            if ($result === true) {
                $logger->info('Watermarker: Assignment set successfully');
            } elseif ($result === false) {
                $logger->err('Watermarker: Failed to set assignment');
            } else {
                $logger->info('Watermarker: No changes needed for assignment');
            }
        } catch (\Exception $e) {
            $logger->err('Watermarker: Error setting assignment: ' . $e->getMessage());
        }
    }

    /**
     * Handle resource creation and update to save watermark assignments
     *
     * @param Event $event
     */
    public function handleCreateUpdateResource(Event $event)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $request = $event->getParam('request');
        $content = $request->getContent();

        // Log the request content for debugging
        $logger->info('Watermarker: Request content: ' . json_encode($content));

        // Get watermark_set value from request content
        $watermarkSetValue = isset($content['o-watermarker:set']) ? $content['o-watermarker:set'] : null;
        $logger->info('Watermarker: Watermark set value: ' . $watermarkSetValue);

        // If no watermark set value, nothing to do
        if ($watermarkSetValue === null) {
            $logger->info('Watermarker: No watermark set value in request');
            return;
        }

        // Get resource ID from request content
        $resourceId = isset($content['o:id']) ? $content['o:id'] : (isset($content['resource_id']) ? $content['resource_id'] : null);
        if (!$resourceId) {
            $logger->err('Watermarker: No resource ID in request content');
            return;
        }

        // Get the target adapter from the event
        $target = $event->getTarget();
        if (!$target) {
            $logger->err('Watermarker: No target in event');
            return;
        }

        // Determine resource type from adapter class
        $adapterClass = get_class($target);
        $resourceType = null;

        if (strpos($adapterClass, '\\ItemAdapter') !== false) {
            $resourceType = 'item';
        } elseif (strpos($adapterClass, '\\ItemSetAdapter') !== false) {
            $resourceType = 'item-set';
        } elseif (strpos($adapterClass, '\\MediaAdapter') !== false) {
            $resourceType = 'media';
        }

        if (!$resourceType) {
            $logger->err('Watermarker: Unknown resource type from adapter: ' . $adapterClass);
            return;
        }

        $logger->info(sprintf(
            'Watermarker: Processing resource type: %s, ID: %s, watermark set: %s',
            $resourceType,
            $resourceId,
            $watermarkSetValue
        ));

        try {
            // Get the assignment service
            $assignmentService = $this->getServiceLocator()->get('Watermarker\Service\AssignmentService');

            // Set the assignment
            $result = $assignmentService->setAssignment(
                $resourceType,
                $resourceId,
                $watermarkSetValue
            );

            if ($result === true) {
                $logger->info('Watermarker: Assignment set successfully');
            } elseif ($result === false) {
                $logger->err('Watermarker: Failed to set assignment');
            } else {
                $logger->info('Watermarker: No changes needed for assignment');
            }
        } catch (\Exception $e) {
            $logger->err('Watermarker: Error setting assignment: ' . $e->getMessage());
        }
    }
}