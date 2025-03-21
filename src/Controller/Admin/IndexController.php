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

        // Get watermark settings
        $stmt = $connection->query("SELECT * FROM watermark_setting ORDER BY id ASC");
        $watermarkSettings = $stmt->fetchAll();

        // Get variants for each watermark
        $watermarks = [];
        foreach ($watermarkSettings as $setting) {
            $variantsSql = "SELECT * FROM watermark_variant WHERE watermark_id = :watermark_id ORDER BY orientation ASC";
            $variantsStmt = $connection->prepare($variantsSql);
            $variantsStmt->bindValue('watermark_id', $setting['id']);
            $variantsStmt->execute();
            $variants = $variantsStmt->fetchAll();

            // Add variants to watermark data
            $setting['variants'] = $variants;
            $watermarks[] = $setting;
        }

        $view = new ViewModel();
        $view->setVariable('watermarks', $watermarks);
        return $view;
    }

    /**
     * Add a new watermark configuration
     */
    public function addAction()
    {
        // This will force direct HTML output
        $html = new ViewModel();
        $html->setTerminal(true);
        
        // Set default values
        $landscape = ['position' => 'bottom-right', 'opacity' => 0.7];
        $portrait = ['position' => 'bottom-right', 'opacity' => 0.7];
        $square = ['position' => 'bottom-right', 'opacity' => 0.7];
        
        if ($this->getRequest()->isPost()) {
            try {
                $data = $this->params()->fromPost();
                
                $services = $this->getEvent()->getApplication()->getServiceManager();
                $connection = $services->get('Omeka\Connection');
                $api = $services->get('Omeka\ApiManager');
                
                // Manual validation
                $errors = [];
                
                if (empty($data['name'])) {
                    $errors[] = 'Watermark name is required.';
                }
                
                if (empty($data['landscape_image'])) {
                    $errors[] = 'Landscape watermark image is required.';
                }
                
                if (empty($data['portrait_image'])) {
                    $errors[] = 'Portrait watermark image is required.';
                }
                
                // First, create the main watermark record
                if (empty($errors)) {
                    $sql = "INSERT INTO watermark_setting (name, enabled, created)
                            VALUES (:name, :enabled, :created)";
    
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('name', $data['name']);
                    $stmt->bindValue('enabled', isset($data['enabled']) ? 1 : 0);
                    $stmt->bindValue('created', date('Y-m-d H:i:s'));
                    $stmt->execute();
                    
                    // Get the new watermark ID
                    $watermarkId = $connection->lastInsertId();
                    
                    // Add landscape variant
                    if (!empty($data['landscape_image'])) {
                        try {
                            // Validate landscape asset exists
                            $landscapeAsset = $api->read('assets', $data['landscape_image'])->getContent();
                            
                            $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                    VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                            
                            $stmt = $connection->prepare($sql);
                            $stmt->bindValue('watermark_id', $watermarkId);
                            $stmt->bindValue('orientation', 'landscape');
                            $stmt->bindValue('media_id', $data['landscape_image']);
                            $stmt->bindValue('position', $data['landscape_position']);
                            $stmt->bindValue('opacity', $data['landscape_opacity']);
                            $stmt->bindValue('created', date('Y-m-d H:i:s'));
                            $stmt->execute();
                        } catch (\Exception $e) {
                            $this->messenger()->addError(sprintf(
                                'Asset for landscape orientation does not exist: %s',
                                $e->getMessage()
                            ));
                        }
                    }
                    
                    // Add portrait variant
                    if (!empty($data['portrait_image'])) {
                        try {
                            // Validate portrait asset exists
                            $portraitAsset = $api->read('assets', $data['portrait_image'])->getContent();
                            
                            $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                    VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                            
                            $stmt = $connection->prepare($sql);
                            $stmt->bindValue('watermark_id', $watermarkId);
                            $stmt->bindValue('orientation', 'portrait');
                            $stmt->bindValue('media_id', $data['portrait_image']);
                            $stmt->bindValue('position', $data['portrait_position']);
                            $stmt->bindValue('opacity', $data['portrait_opacity']);
                            $stmt->bindValue('created', date('Y-m-d H:i:s'));
                            $stmt->execute();
                        } catch (\Exception $e) {
                            $this->messenger()->addError(sprintf(
                                'Asset for portrait orientation does not exist: %s',
                                $e->getMessage()
                            ));
                        }
                    }
                    
                    // Add square variant (optional)
                    if (!empty($data['square_image'])) {
                        try {
                            // Validate square asset exists
                            $squareAsset = $api->read('assets', $data['square_image'])->getContent();
                            
                            $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                    VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                            
                            $stmt = $connection->prepare($sql);
                            $stmt->bindValue('watermark_id', $watermarkId);
                            $stmt->bindValue('orientation', 'square');
                            $stmt->bindValue('media_id', $data['square_image']);
                            $stmt->bindValue('position', $data['square_position']);
                            $stmt->bindValue('opacity', $data['square_opacity']);
                            $stmt->bindValue('created', date('Y-m-d H:i:s'));
                            $stmt->execute();
                        } catch (\Exception $e) {
                            $this->messenger()->addError(sprintf(
                                'Asset for square orientation does not exist: %s',
                                $e->getMessage()
                            ));
                        }
                    }
    
                    $this->messenger()->addSuccess('Watermark configuration added successfully.');
                    return $this->redirect()->toRoute('admin/watermarker');
                } else {
                    foreach ($errors as $error) {
                        $this->messenger()->addError($error);
                    }
                }
            } catch (\Exception $e) {
                $this->messenger()->addError('Error adding watermark: ' . $e->getMessage());
            }
        }
        
        // No need to manipulate body class with direct HTML output
        
        // Create direct HTML output that matches Omeka S styling
        $outputHtml = '
        <div id="page-actions">
            <a href="' . $this->url()->fromRoute('admin/watermarker') . '" class="button">Cancel</a>
            <button type="submit" form="add-watermark-form" class="button">Save</button>
        </div>
        
        <h1>' . $this->translate('Add Watermark') . '</h1>
        
        <form id="add-watermark-form" method="post">
            <!-- Basic Info Section -->
            <div class="section">
                <h3>' . $this->translate('Basic Settings') . '</h3>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="watermark-name">' . $this->translate('Watermark Name') . '</label>
                        <div class="field-description">' . $this->translate('Name for this watermark configuration.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="name" id="watermark-name" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="watermark-enabled">' . $this->translate('Enabled') . '</label>
                        <div class="field-description">' . $this->translate('Check to enable this watermark configuration.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="checkbox" name="enabled" id="watermark-enabled" value="1" checked>
                    </div>
                </div>
            </div>

            <!-- Landscape Watermark -->
            <div class="section">
                <h3>' . $this->translate('Landscape Watermark') . ' <span class="required">*</span></h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for landscape-oriented images.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your landscape watermark image.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="landscape_image" id="landscape-image" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="landscape_position" id="landscape-position">
                            <option value="top-left">' . $this->translate('Top Left') . '</option>
                            <option value="top-right">' . $this->translate('Top Right') . '</option>
                            <option value="center">' . $this->translate('Center') . '</option>
                            <option value="bottom-left">' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" selected>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full">' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="landscape_opacity" id="landscape-opacity" 
                               min="0.1" max="1.0" step="0.05" value="0.7">
                    </div>
                </div>
            </div>
            
            <!-- Portrait Watermark -->
            <div class="section">
                <h3>' . $this->translate('Portrait Watermark') . ' <span class="required">*</span></h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for portrait-oriented images.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your portrait watermark image.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="portrait_image" id="portrait-image" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="portrait_position" id="portrait-position">
                            <option value="top-left">' . $this->translate('Top Left') . '</option>
                            <option value="top-right">' . $this->translate('Top Right') . '</option>
                            <option value="center">' . $this->translate('Center') . '</option>
                            <option value="bottom-left">' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" selected>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full">' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="portrait_opacity" id="portrait-opacity" 
                               min="0.1" max="1.0" step="0.05" value="0.7">
                    </div>
                </div>
            </div>
            
            <!-- Square Watermark -->
            <div class="section">
                <h3>' . $this->translate('Square Watermark (Optional)') . '</h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for square-shaped images. If not specified, landscape settings will be used.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your square watermark image (optional).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="square_image" id="square-image">
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="square_position" id="square-position">
                            <option value="top-left">' . $this->translate('Top Left') . '</option>
                            <option value="top-right">' . $this->translate('Top Right') . '</option>
                            <option value="center">' . $this->translate('Center') . '</option>
                            <option value="bottom-left">' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" selected>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full">' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="square_opacity" id="square-opacity" 
                               min="0.1" max="1.0" step="0.05" value="0.7">
                    </div>
                </div>
            </div>
            
            <div class="field">
                <div class="field-meta">
                    <div class="field-description">
                        <strong>' . $this->translate('How to find asset IDs:') . '</strong> 
                        ' . $this->translate('The asset ID is the number visible in the URL when viewing an asset in the admin interface (e.g., /admin/asset/12345 where 12345 is the ID).') . '
                    </div>
                </div>
            </div>
            
            <!-- Simple hidden field for CSRF protection -->
            <input type="hidden" name="csrf" value="' . md5(time()) . '">
        </form>
        ';
        
        $html->setVariable('content', $outputHtml);
        return $html;
    }

    /**
     * Edit a watermark configuration
     */
    public function editAction()
    {
        // This will force direct HTML output
        $html = new ViewModel();
        $html->setTerminal(true);
        
        $id = $this->params('id');
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $api = $services->get('Omeka\ApiManager');

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

        // Get variants for this watermark
        $variantsSql = "SELECT * FROM watermark_variant WHERE watermark_id = :watermark_id ORDER BY orientation ASC";
        $variantsStmt = $connection->prepare($variantsSql);
        $variantsStmt->bindValue('watermark_id', $id);
        $variantsStmt->execute();
        $variants = $variantsStmt->fetchAll();
        
        // Organize variants by orientation for easier access
        $variantsByType = [
            'landscape' => null,
            'portrait' => null,
            'square' => null
        ];
        
        foreach ($variants as $variant) {
            if (isset($variantsByType[$variant['orientation']])) {
                $variantsByType[$variant['orientation']] = $variant;
            }
        }

        if ($this->getRequest()->isPost()) {
            try {
                $data = $this->params()->fromPost();
                
                // Manual validation
                $errors = [];
                
                if (empty($data['name'])) {
                    $errors[] = 'Watermark name is required.';
                }
                
                if (empty($data['landscape_image'])) {
                    $errors[] = 'Landscape watermark image is required.';
                }
                
                if (empty($data['portrait_image'])) {
                    $errors[] = 'Portrait watermark image is required.';
                }
                
                // Process the form data if no errors
                if (empty($errors)) {
                    // Start a transaction
                    $connection->beginTransaction();
                    
                    try {
                        // Update the main watermark record
                        $sql = "UPDATE watermark_setting SET
                                name = :name,
                                enabled = :enabled,
                                modified = :modified
                                WHERE id = :id";

                        $stmt = $connection->prepare($sql);
                        $stmt->bindValue('name', $data['name']);
                        $stmt->bindValue('enabled', isset($data['enabled']) ? 1 : 0);
                        $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                        $stmt->bindValue('id', $id);
                        $stmt->execute();

                        // Delete existing variants
                        $deleteSql = "DELETE FROM watermark_variant WHERE watermark_id = :watermark_id";
                        $deleteStmt = $connection->prepare($deleteSql);
                        $deleteStmt->bindValue('watermark_id', $id);
                        $deleteStmt->execute();

                        // Add landscape variant
                        if (!empty($data['landscape_image'])) {
                            try {
                                // Validate landscape asset exists
                                $landscapeAsset = $api->read('assets', $data['landscape_image'])->getContent();
                                
                                $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                        VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                                
                                $stmt = $connection->prepare($sql);
                                $stmt->bindValue('watermark_id', $id);
                                $stmt->bindValue('orientation', 'landscape');
                                $stmt->bindValue('media_id', $data['landscape_image']);
                                $stmt->bindValue('position', $data['landscape_position']);
                                $stmt->bindValue('opacity', $data['landscape_opacity']);
                                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                                $stmt->execute();
                            } catch (\Exception $e) {
                                $this->messenger()->addError(sprintf(
                                    'Asset for landscape orientation does not exist: %s',
                                    $e->getMessage()
                                ));
                            }
                        }
                        
                        // Add portrait variant
                        if (!empty($data['portrait_image'])) {
                            try {
                                // Validate portrait asset exists
                                $portraitAsset = $api->read('assets', $data['portrait_image'])->getContent();
                                
                                $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                        VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                                
                                $stmt = $connection->prepare($sql);
                                $stmt->bindValue('watermark_id', $id);
                                $stmt->bindValue('orientation', 'portrait');
                                $stmt->bindValue('media_id', $data['portrait_image']);
                                $stmt->bindValue('position', $data['portrait_position']);
                                $stmt->bindValue('opacity', $data['portrait_opacity']);
                                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                                $stmt->execute();
                            } catch (\Exception $e) {
                                $this->messenger()->addError(sprintf(
                                    'Asset for portrait orientation does not exist: %s',
                                    $e->getMessage()
                                ));
                            }
                        }
                        
                        // Add square variant (optional)
                        if (!empty($data['square_image'])) {
                            try {
                                // Validate square asset exists
                                $squareAsset = $api->read('assets', $data['square_image'])->getContent();
                                
                                $sql = "INSERT INTO watermark_variant (watermark_id, orientation, media_id, position, opacity, created)
                                        VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                                
                                $stmt = $connection->prepare($sql);
                                $stmt->bindValue('watermark_id', $id);
                                $stmt->bindValue('orientation', 'square');
                                $stmt->bindValue('media_id', $data['square_image']);
                                $stmt->bindValue('position', $data['square_position']);
                                $stmt->bindValue('opacity', $data['square_opacity']);
                                $stmt->bindValue('created', date('Y-m-d H:i:s'));
                                $stmt->execute();
                            } catch (\Exception $e) {
                                $this->messenger()->addError(sprintf(
                                    'Asset for square orientation does not exist: %s',
                                    $e->getMessage()
                                ));
                            }
                        }
                        
                        // Commit the transaction
                        $connection->commit();
                        
                        $this->messenger()->addSuccess('Watermark configuration updated successfully.');
                        return $this->redirect()->toRoute('admin/watermarker');
                        
                    } catch (\Exception $e) {
                        // Rollback the transaction on error
                        $connection->rollBack();
                        $this->messenger()->addError('Error updating watermark: ' . $e->getMessage());
                    }
                } else {
                    foreach ($errors as $error) {
                        $this->messenger()->addError($error);
                    }
                }
            } catch (\Exception $e) {
                $this->messenger()->addError('Error processing form: ' . $e->getMessage());
            }
        }
        
        // Extract data from variants
        $landscape = $variantsByType['landscape'] ?? ['media_id' => '', 'position' => 'bottom-right', 'opacity' => 0.7];
        $portrait = $variantsByType['portrait'] ?? ['media_id' => '', 'position' => 'bottom-right', 'opacity' => 0.7];
        $square = $variantsByType['square'] ?? ['media_id' => '', 'position' => 'bottom-right', 'opacity' => 0.7];
        
        // No need to manipulate body class with direct HTML output
        
        // Create direct HTML output that matches Omeka S styling
        $outputHtml = '
        <div id="page-actions">
            <a href="' . $this->url()->fromRoute('admin/watermarker') . '" class="button">' . $this->translate('Cancel') . '</a>
            <button type="submit" form="edit-watermark-form" class="button">' . $this->translate('Save') . '</button>
        </div>
        
        <h1>' . $this->translate('Edit Watermark') . '</h1>
        
        <form id="edit-watermark-form" method="post">
            <!-- Basic Info Section -->
            <div class="section">
                <h3>' . $this->translate('Basic Settings') . '</h3>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="watermark-name">' . $this->translate('Watermark Name') . '</label>
                        <div class="field-description">' . $this->translate('Name for this watermark configuration.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="name" id="watermark-name" value="' . htmlspecialchars($watermark['name']) . '" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="watermark-enabled">' . $this->translate('Enabled') . '</label>
                        <div class="field-description">' . $this->translate('Check to enable this watermark configuration.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="checkbox" name="enabled" id="watermark-enabled" value="1" ' . ($watermark['enabled'] ? 'checked' : '') . '>
                    </div>
                </div>
                
                <input type="hidden" name="o:id" value="' . htmlspecialchars($watermark['id']) . '">
            </div>

            <!-- Landscape Watermark -->
            <div class="section">
                <h3>' . $this->translate('Landscape Watermark') . ' <span class="required">*</span></h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for landscape-oriented images.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your landscape watermark image.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="landscape_image" id="landscape-image" value="' . htmlspecialchars($landscape['media_id']) . '" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="landscape_position" id="landscape-position">
                            <option value="top-left" ' . ($landscape['position'] == 'top-left' ? 'selected' : '') . '>' . $this->translate('Top Left') . '</option>
                            <option value="top-right" ' . ($landscape['position'] == 'top-right' ? 'selected' : '') . '>' . $this->translate('Top Right') . '</option>
                            <option value="center" ' . ($landscape['position'] == 'center' ? 'selected' : '') . '>' . $this->translate('Center') . '</option>
                            <option value="bottom-left" ' . ($landscape['position'] == 'bottom-left' ? 'selected' : '') . '>' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" ' . ($landscape['position'] == 'bottom-right' ? 'selected' : '') . '>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full" ' . ($landscape['position'] == 'bottom-full' ? 'selected' : '') . '>' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="landscape-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="landscape_opacity" id="landscape-opacity" 
                               min="0.1" max="1.0" step="0.05" value="' . htmlspecialchars($landscape['opacity']) . '">
                    </div>
                </div>
            </div>
            
            <!-- Portrait Watermark -->
            <div class="section">
                <h3>' . $this->translate('Portrait Watermark') . ' <span class="required">*</span></h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for portrait-oriented images.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your portrait watermark image.') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="portrait_image" id="portrait-image" value="' . htmlspecialchars($portrait['media_id']) . '" required>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="portrait_position" id="portrait-position">
                            <option value="top-left" ' . ($portrait['position'] == 'top-left' ? 'selected' : '') . '>' . $this->translate('Top Left') . '</option>
                            <option value="top-right" ' . ($portrait['position'] == 'top-right' ? 'selected' : '') . '>' . $this->translate('Top Right') . '</option>
                            <option value="center" ' . ($portrait['position'] == 'center' ? 'selected' : '') . '>' . $this->translate('Center') . '</option>
                            <option value="bottom-left" ' . ($portrait['position'] == 'bottom-left' ? 'selected' : '') . '>' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" ' . ($portrait['position'] == 'bottom-right' ? 'selected' : '') . '>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full" ' . ($portrait['position'] == 'bottom-full' ? 'selected' : '') . '>' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="portrait-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="portrait_opacity" id="portrait-opacity" 
                               min="0.1" max="1.0" step="0.05" value="' . htmlspecialchars($portrait['opacity']) . '">
                    </div>
                </div>
            </div>
            
            <!-- Square Watermark -->
            <div class="section">
                <h3>' . $this->translate('Square Watermark (Optional)') . '</h3>
                <p class="watermarker-help">' . $this->translate('Configure watermark for square-shaped images. If not specified, landscape settings will be used.') . '</p>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-image">' . $this->translate('Watermark Image ID') . '</label>
                        <div class="field-description">' . $this->translate('Enter the asset ID of your square watermark image (optional).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="text" name="square_image" id="square-image" value="' . htmlspecialchars($square['media_id']) . '">
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-position">' . $this->translate('Position') . '</label>
                        <div class="field-description">' . $this->translate('Select where the watermark should be positioned.') . '</div>
                    </div>
                    <div class="inputs">
                        <select name="square_position" id="square-position">
                            <option value="top-left" ' . ($square['position'] == 'top-left' ? 'selected' : '') . '>' . $this->translate('Top Left') . '</option>
                            <option value="top-right" ' . ($square['position'] == 'top-right' ? 'selected' : '') . '>' . $this->translate('Top Right') . '</option>
                            <option value="center" ' . ($square['position'] == 'center' ? 'selected' : '') . '>' . $this->translate('Center') . '</option>
                            <option value="bottom-left" ' . ($square['position'] == 'bottom-left' ? 'selected' : '') . '>' . $this->translate('Bottom Left') . '</option>
                            <option value="bottom-right" ' . ($square['position'] == 'bottom-right' ? 'selected' : '') . '>' . $this->translate('Bottom Right') . '</option>
                            <option value="bottom-full" ' . ($square['position'] == 'bottom-full' ? 'selected' : '') . '>' . $this->translate('Bottom Full Width') . '</option>
                        </select>
                    </div>
                </div>
                
                <div class="field">
                    <div class="field-meta">
                        <label for="square-opacity">' . $this->translate('Opacity') . '</label>
                        <div class="field-description">' . $this->translate('Set the opacity of the watermark (0.1 - 1.0).') . '</div>
                    </div>
                    <div class="inputs">
                        <input type="number" name="square_opacity" id="square-opacity" 
                               min="0.1" max="1.0" step="0.05" value="' . htmlspecialchars($square['opacity']) . '">
                    </div>
                </div>
            </div>
            
            <div class="field">
                <div class="field-meta">
                    <div class="field-description">
                        <strong>' . $this->translate('How to find asset IDs:') . '</strong> 
                        ' . $this->translate('The asset ID is the number visible in the URL when viewing an asset in the admin interface (e.g., /admin/asset/12345 where 12345 is the ID).') . '
                    </div>
                </div>
            </div>
            
            <!-- Simple hidden field for CSRF protection -->
            <input type="hidden" name="csrf" value="' . md5(time()) . '">
        </form>
        ';
        
        $html->setVariable('content', $outputHtml);
        return $html;
    }
    
    /**
     * Generate HTML options for position select
     *
     * @param string $selected The currently selected position
     * @return string HTML options
     */
    protected function generatePositionOptions($selected)
    {
        $positions = [
            'top-left' => 'Top Left',
            'top-right' => 'Top Right',
            'center' => 'Center',
            'bottom-left' => 'Bottom Left',
            'bottom-right' => 'Bottom Right',
            'bottom-full' => 'Bottom Full Width'
        ];
        
        $html = '';
        foreach ($positions as $value => $label) {
            $selectedAttr = ($value == $selected) ? ' selected' : '';
            $html .= '<option value="' . $value . '"' . $selectedAttr . '>' . $label . '</option>';
        }
        
        return $html;
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

        // Get number of variants for this watermark
        $countSql = "SELECT COUNT(*) FROM watermark_variant WHERE watermark_id = :watermark_id";
        $countStmt = $connection->prepare($countSql);
        $countStmt->bindValue('watermark_id', $id);
        $countStmt->execute();
        $variantCount = $countStmt->fetchColumn();

        $form = $this->getForm(ConfirmForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                // Begin transaction to ensure both tables are updated atomically
                $connection->beginTransaction();
                
                try {
                    // Delete the variants first (due to foreign key constraint)
                    $sql = "DELETE FROM watermark_variant WHERE watermark_id = :id";
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('id', $id);
                    $stmt->execute();
                    
                    // Now delete the main watermark record
                    $sql = "DELETE FROM watermark_setting WHERE id = :id";
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('id', $id);
                    $stmt->execute();
                    
                    // Commit the transaction
                    $connection->commit();

                    $this->messenger()->addSuccess('Watermark configuration deleted with all its variants.');
                    return $this->redirect()->toRoute('admin/watermarker');
                } catch (\Exception $e) {
                    // Rollback on error
                    $connection->rollBack();
                    $this->messenger()->addError('Error deleting watermark: ' . $e->getMessage());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('watermark', $watermark);
        $view->setVariable('variantCount', $variantCount);
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

        if (count($watermarks) == 0) {
            $messenger->addWarning('No enabled watermark configurations found. Please create and enable a watermark first.');
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
        $variantCount = 0;

        foreach ($watermarks as $watermark) {
            // Get variants for this watermark
            $variantsSql = "SELECT * FROM watermark_variant WHERE watermark_id = :watermark_id";
            $variantsStmt = $connection->prepare($variantsSql);
            $variantsStmt->bindValue('watermark_id', $watermark['id']);
            $variantsStmt->execute();
            $variants = $variantsStmt->fetchAll();
            
            $watermarkValid = true;
            $watermarkVariantCount = count($variants);
            $variantCount += $watermarkVariantCount;
            
            if (empty($variants)) {
                $messenger->addWarning(sprintf(
                    'Watermark "%s" has no variants. Please edit and add at least one variant.',
                    $watermark['name']
                ));
                $invalidCount++;
                continue;
            }

            // Check each variant's asset
            foreach ($variants as $variant) {
                try {
                    $asset = $api->read('assets', $variant['media_id'])->getContent();
                } catch (\Exception $e) {
                    $watermarkValid = false;
                    $messenger->addWarning(sprintf(
                        'Watermark "%s" (%s variant) references a missing asset. Please edit and select a valid image.',
                        $watermark['name'],
                        $variant['orientation']
                    ));
                }
            }
            
            if ($watermarkValid) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        if ($validCount > 0) {
            $messenger->addSuccess(sprintf(
                'Successfully verified %d watermark configuration(s) with %d total variants',
                $validCount,
                $variantCount
            ));
        }

        if ($invalidCount == 0 && $validCount > 0) {
            $messenger->addSuccess('All watermark configurations are valid!');
        } elseif (count($watermarks) === 0) {
            $messenger->addWarning('No watermark configurations found. Please add at least one watermark configuration.');
        }

        return $this->redirect()->toRoute('admin/watermarker');
    }
    
    /**
     * Migrate legacy watermarks to the variant system
     */
    public function migrateAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $jobDispatcher = $services->get('Omeka\Job\Dispatcher');
        $messenger = $this->messenger();
        
        // Check if any watermarks need migration
        $hasLegacyFields = false;
        
        try {
            // Check if the media_id column exists in watermark_setting
            $sql = "SHOW COLUMNS FROM watermark_setting LIKE 'media_id'";
            $stmt = $connection->query($sql);
            $hasLegacyFields = (bool)$stmt->fetchColumn();
            
            if (!$hasLegacyFields) {
                // Check if position exists as a fallback
                $sql = "SHOW COLUMNS FROM watermark_setting LIKE 'position'";
                $stmt = $connection->query($sql);
                $hasLegacyFields = (bool)$stmt->fetchColumn();
            }
            
            if (!$hasLegacyFields) {
                // Check if variants table has any entries
                $sql = "SELECT COUNT(*) FROM watermark_variant";
                $stmt = $connection->query($sql);
                $variantCount = (int)$stmt->fetchColumn();
                
                // Check if we have watermarks that need conversion
                $sql = "SELECT COUNT(*) FROM watermark_setting";
                $stmt = $connection->query($sql);
                $watermarkCount = (int)$stmt->fetchColumn();
                
                if ($variantCount == 0 && $watermarkCount > 0) {
                    // We have watermarks but no variants, so assume migration needed
                    $hasLegacyFields = true;
                }
            }
            
            if (!$hasLegacyFields) {
                $messenger->addNotice('No legacy watermarks detected. Migration not needed.');
                return $this->redirect()->toRoute('admin/watermarker');
            }
            
            // Create a background job to handle the migration
            $job = $jobDispatcher->dispatch('Watermarker\Job\MigrateWatermarks');
            
            $messenger->addSuccess(sprintf(
                'Migration job #%d started. Legacy watermarks will be converted to use variants.',
                $job->getId()
            ));
            
        } catch (\Exception $e) {
            $messenger->addError('Failed to start migration: ' . $e->getMessage());
        }
        
        return $this->redirect()->toRoute('admin/watermarker');
    }
    
    /**
     * Reprocess all media with watermarks
     */
    public function reprocessAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $connection = $services->get('Omeka\Connection');
        $jobDispatcher = $services->get('Omeka\Job\Dispatcher');
        $messenger = $this->messenger();
        
        // Check if we have any watermark configs
        try {
            $sql = "SELECT COUNT(*) FROM watermark_setting WHERE enabled = 1";
            $stmt = $connection->query($sql);
            $enabledWatermarks = (int)$stmt->fetchColumn();
            
            if ($enabledWatermarks === 0) {
                $messenger->addWarning('No enabled watermark configurations found. Please create and enable a watermark first.');
                return $this->redirect()->toRoute('admin/watermarker');
            }
            
            // Check if we have any variants
            $sql = "SELECT COUNT(*) FROM watermark_variant";
            $stmt = $connection->query($sql);
            $variantCount = (int)$stmt->fetchColumn();
            
            if ($variantCount === 0) {
                $messenger->addWarning('No watermark variants found. Please migrate or create watermarks with variants first.');
                return $this->redirect()->toRoute('admin/watermarker');
            }
            
            // Request params - allow for future enhancement to reprocess only certain media
            $params = $this->params()->fromQuery();
            $itemId = isset($params['item_id']) ? $params['item_id'] : null;
            $mediaId = isset($params['media_id']) ? $params['media_id'] : null;
            
            // Create job args
            $args = [
                'item_id' => $itemId,
                'media_id' => $mediaId,
                'limit' => 100, // Process in batches of 100
            ];
            
            // Create a background job to handle the reprocessing
            $job = $jobDispatcher->dispatch('Watermarker\Job\ReprocessImages', ['args' => $args]);
            
            $messenger->addSuccess(sprintf(
                'Reprocessing job #%d started. Media will be re-watermarked in batches of 100.',
                $job->getId()
            ));
            
        } catch (\Exception $e) {
            $messenger->addError('Failed to start reprocessing: ' . $e->getMessage());
        }
        
        return $this->redirect()->toRoute('admin/watermarker');
    }
    
    /**
     * Simple test action to diagnose rendering issues
     */
    public function renderTestAction()
    {
        // Log that we're attempting to render the test page
        error_log('Watermarker: Starting render-test page render at ' . date('Y-m-d H:i:s'));
        
        // Create a very simple view model
        $view = new ViewModel();
        
        // Add test data
        $view->setVariable('testData', 'This is a test variable');
        $view->setVariable('timestamp', time());
        
        return $view;
    }
    
    /**
     * Minimal action with a basic form - testing rendering
     */
    public function minimalAction()
    {
        // Log that we're attempting to render the minimal page
        error_log('Watermarker: Starting minimal page render at ' . date('Y-m-d H:i:s'));
        
        // Create a simple view model that renders add-minimal.phtml
        $view = new ViewModel();
        $view->setTemplate('watermarker/admin/index/add-minimal');
        
        return $view;
    }
}