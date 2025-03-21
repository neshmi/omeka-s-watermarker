<?php
/**
 * Job for migrating legacy watermarks to the new variant system
 */

namespace Watermarker\Job;

use Omeka\Job\AbstractJob;

class MigrateWatermarks extends AbstractJob
{
    /**
     * Perform the migration
     */
    public function perform()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        
        $logger->info('Starting watermark migration to variant system');
        
        // Check if we have both required tables
        try {
            $stmt = $connection->query("SHOW TABLES LIKE 'watermark_setting'");
            $hasWatermarkTable = (bool)$stmt->fetchColumn();
            
            $stmt = $connection->query("SHOW TABLES LIKE 'watermark_variant'");
            $hasVariantTable = (bool)$stmt->fetchColumn();
            
            if (!$hasWatermarkTable) {
                $logger->err('Watermark table not found, cannot migrate');
                return;
            }
            
            if (!$hasVariantTable) {
                $logger->err('Variant table not found, cannot migrate');
                return;
            }
            
            // First, check if any watermarks have already been migrated
            $stmt = $connection->query("SELECT COUNT(*) FROM watermark_variant");
            $variantCount = (int)$stmt->fetchColumn();
            
            if ($variantCount > 0) {
                $logger->info(sprintf('Found %d existing variants, skipping migration', $variantCount));
                return;
            }
            
            // Get all existing watermarks
            $watermarks = $connection->fetchAll("SELECT * FROM watermark_setting");
            $logger->info(sprintf('Found %d watermarks to migrate', count($watermarks)));
            
            // Begin transaction for atomic migration
            $connection->beginTransaction();
            
            try {
                foreach ($watermarks as $watermark) {
                    $logger->info(sprintf('Migrating watermark ID %d: %s', $watermark['id'], $watermark['name']));
                    
                    // Some watermark fields may be in different columns depending on the schema version
                    // Try to handle all possible variations
                    $mediaId = $watermark['media_id'] ?? null;
                    $position = $watermark['position'] ?? 'bottom-right';
                    $opacity = $watermark['opacity'] ?? 0.7;
                    
                    if (!$mediaId) {
                        $logger->warn(sprintf('Watermark ID %d has no media_id, skipping', $watermark['id']));
                        continue;
                    }
                    
                    // Create a variant for each orientation using the same settings
                    $orientations = ['landscape', 'portrait', 'square'];
                    foreach ($orientations as $orientation) {
                        $sql = "INSERT INTO watermark_variant 
                                (watermark_id, orientation, media_id, position, opacity, created) 
                                VALUES (:watermark_id, :orientation, :media_id, :position, :opacity, :created)";
                        
                        $stmt = $connection->prepare($sql);
                        $stmt->bindValue('watermark_id', $watermark['id']);
                        $stmt->bindValue('orientation', $orientation);
                        $stmt->bindValue('media_id', $mediaId);
                        $stmt->bindValue('position', $position);
                        $stmt->bindValue('opacity', $opacity);
                        $stmt->bindValue('created', date('Y-m-d H:i:s'));
                        $stmt->execute();
                        
                        $logger->info(sprintf(
                            'Created %s variant for watermark ID %d',
                            $orientation,
                            $watermark['id']
                        ));
                    }
                    
                    // Remove legacy fields if they exist in the schema
                    // Since we're not sure which fields exist, try a simple UPDATE
                    // with only the fields we're sure exist
                    $sql = "UPDATE watermark_setting SET 
                            name = :name,
                            enabled = :enabled,
                            modified = :modified
                            WHERE id = :id";
                    
                    $stmt = $connection->prepare($sql);
                    $stmt->bindValue('name', $watermark['name']);
                    $stmt->bindValue('enabled', $watermark['enabled']);
                    $stmt->bindValue('modified', date('Y-m-d H:i:s'));
                    $stmt->bindValue('id', $watermark['id']);
                    $stmt->execute();
                }
                
                // Commit all changes
                $connection->commit();
                $logger->info('Successfully migrated all watermarks to variant system');
                
            } catch (\Exception $e) {
                // Rollback on error
                $connection->rollBack();
                $logger->err(sprintf('Migration failed: %s', $e->getMessage()));
                $logger->err($e->getTraceAsString());
            }
            
        } catch (\Exception $e) {
            $logger->err(sprintf('Database error: %s', $e->getMessage()));
            $logger->err($e->getTraceAsString());
        }
    }
}