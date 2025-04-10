# Omeka S Integration

## Module Registration

### Module Configuration
The `Module.php` file is the entry point for the Watermarker module:

```php
// Key configuration points
- getConfig() - Returns module configuration
- onBootstrap() - Registers event listeners
- install() - Creates database tables and default settings
- uninstall() - Removes module data
- upgrade() - Handles version-specific upgrades
- getConfigForm() - Provides the module configuration form
- handleConfigForm() - Processes form submissions
```

### Event Listeners
Register these critical event listeners for proper integration:

```
- view.layout - For admin interface integration
- view.show.after - For displaying watermark settings on resource pages
- view.browse.after - For indicating watermarked resources in browse views
- api.hydrate.pre - For loading watermark settings with resources
- api.create.pre/post - For handling watermark settings during resource creation
- api.update.pre/post - For handling watermark settings during resource updates
```

## Display Integration

### Media Rendering
Hook into Omeka S media rendering process:

```php
$sharedEventManager->attach(
    'Omeka\Controller\Site\Item',
    'view.show.after.media',
    [$this, 'handleMediaRender']
);
```

### Thumbnails and Derivatives
Intercept derivative creation process:

```php
$sharedEventManager->attach(
    'Omeka\File\TempFile',
    'add_derivative.pre',
    [$this, 'handleDerivativeCreation']
);
```

## Admin Interface

### Navigation
Add to admin navigation:

```php
$sharedEventManager->attach(
    'Omeka\Controller\Admin\Index',
    'view.layout',
    [$this, 'addAdminNavigation']
);
```

### Resource Forms
Extend resource forms to include watermark settings:

```php
$sharedEventManager->attach(
    'Omeka\Controller\Admin\Item',
    'view.add.form.after',
    [$this, 'addWatermarkSettings']
);
$sharedEventManager->attach(
    'Omeka\Controller\Admin\Item',
    'view.edit.form.after',
    [$this, 'addWatermarkSettings']
);
```

## API Integration

### API Extensions
Create adapters and representations:

```
- WatermarkSetAdapter - CRUD operations for watermark sets
- WatermarkSetRepresentation - Public API representation
- WatermarkImageAdapter - CRUD operations for watermark images
- WatermarkImageRepresentation - Public API representation
```

### Module-specific API Endpoints
Register custom API endpoints:

```php
'api_adapters' => [
    'invokables' => [
        'watermarker_sets' => Api\Adapter\WatermarkSetAdapter::class,
        'watermarker_images' => Api\Adapter\WatermarkImageAdapter::class,
    ],
],
'router' => [
    'routes' => [
        'admin' => [
            'child_routes' => [
                'watermarker' => [
                    // Custom module routes
                ],
            ],
        ],
    ],
],
```

## File System Integration

### File Storage
Use Omeka S file storage system:

```php
$fileStore = $serviceLocator->get('Omeka\File\Store');
$storagePath = $fileStore->getStoragePath($storageId);
```

### Temporary Files
Handle uploads with Omeka S temp files:

```php
$tempFile = $serviceLocator->get('Omeka\File\TempFileFactory')->build();
$tempFile->setSourceName($filename);
$tempFile->setTempPath($tempPath);
```

## Permissions

### ACL Integration
Register with Omeka S ACL:

```php
$acl->addResource('Watermarker\Controller\Admin\WatermarkSet');
$acl->allow('editor', 'Watermarker\Controller\Admin\WatermarkSet');
```

### User-specific Permissions
Check user permissions:

```php
if (!$acl->userIsAllowed(WatermarkSet::class, 'create')) {
    throw new PermissionDeniedException('User does not have permission to create watermark sets');
}
```

## Translation and Internationalization

### Translation Setup
Enable translations:

```php
'translator' => [
    'translation_file_patterns' => [
        [
            'type' => 'gettext',
            'base_dir' => dirname(__DIR__) . '/language',
            'pattern' => '%s.mo',
            'text_domain' => null,
        ],
    ],
],
```

### Usage
Throughout the module:

```php
$translator = $serviceLocator->get('MvcTranslator');
$translated = $translator->translate('Watermark sets');
```
