<?php
/**
 * Migration to update to multiple watermarks
 */

namespace Watermarker\Migration;

use Doctrine\DBAL\Connection;
use Omeka\Stdlib\Message;

class UpdateToMultipleWatermarks
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @param Connection $connection
     * @param \Laminas\Log\Logger $logger
     */
    public function __construct(Connection $connection, $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Migrate to the new schema
     *
     * @return array Array of messages
     */
    public function migrate()
    {
        $messages = [];
        
        try {
            // Start a transaction
            $this->connection->beginTransaction();
            
            // Check if the old watermark_setting table exists and has the old schema
            $oldTableExists = $this->checkOldTableStructure();
            
            if (!$oldTableExists) {
                $this->connection->rollBack();
                $messages[] = new Message('No migration needed.');
                return $messages;
            }
            
            // Create new tables
            $this->createNewTables();
            $messages[] = new Message('Created new watermark tables.');
            
            // Migrate existing data
            $count = $this->migrateData();
            $messages[] = new Message(sprintf('Migrated %d existing watermark(s).', $count));
            
            // Commit the transaction
            $this->connection->commit();
            $messages[] = new Message('Migration completed successfully.');
            
        } catch (\Exception $e) {
            // Rollback on error
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            
            $messages[] = new Message(sprintf('Migration failed: %s', $e->getMessage()));
            $this->logger->err(sprintf('Migration error: %s', $e->getMessage()));
            $this->logger->err($e->getTraceAsString());
        }
        
        return $messages;
    }
    
    /**
     * Check if the old watermark_setting table has the old structure
     *
     * @return bool
     */
    protected function checkOldTableStructure()
    {
        try {
            // Check if the watermark_setting table exists
            $sql = "SHOW TABLES LIKE 'watermark_setting'";
            $stmt = $this->connection->query($sql);
            $tableExists = (bool) $stmt->fetchColumn();
            
            if (!$tableExists) {
                return false;
            }
            
            // Check if it has the old structure (has 'name' and 'orientation' columns)
            $sql = "SHOW COLUMNS FROM watermark_setting LIKE 'orientation'";
            $stmt = $this->connection->query($sql);
            $hasOldStructure = (bool) $stmt->fetchColumn();
            
            return $hasOldStructure;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Create the new tables for watermark sets
     */
    protected function createNewTables()
    {
        // Rename the old table
        $this->connection->exec("RENAME TABLE watermark_setting TO watermark_setting_old");
        
        // Create watermark set table
        $this->connection->exec("
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
        $this->connection->exec("
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
        $this->connection->exec("
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
    }
    
    /**
     * Migrate data from old table to new structure
     *
     * @return int Number of migrated watermarks
     */
    protected function migrateData()
    {
        // Get old watermarks
        $sql = "SELECT * FROM watermark_setting_old ORDER BY id ASC";
        $stmt = $this->connection->query($sql);
        $oldWatermarks = $stmt->fetchAll();
        
        if (empty($oldWatermarks)) {
            return 0;
        }
        
        // Create a default watermark set
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO watermark_set (name, is_default, enabled, created)
                VALUES ('Default Watermark Set', 1, 1, :created)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('created', $now);
        $stmt->execute();
        
        $setId = $this->connection->lastInsertId();
        
        // Migrate watermarks
        $count = 0;
        foreach ($oldWatermarks as $oldWatermark) {
            // Only migrate enabled watermarks
            if (!$oldWatermark['enabled']) {
                continue;
            }
            
            // Map old orientation to new type
            $type = $oldWatermark['orientation'] === 'all' ? 'all' : $oldWatermark['orientation'];
            
            // Insert into new table
            $sql = "INSERT INTO watermark_setting (set_id, type, media_id, position, opacity, created, modified)
                    VALUES (:set_id, :type, :media_id, :position, :opacity, :created, :modified)";
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('set_id', $setId);
            $stmt->bindValue('type', $type);
            $stmt->bindValue('media_id', $oldWatermark['media_id']);
            $stmt->bindValue('position', $oldWatermark['position']);
            $stmt->bindValue('opacity', $oldWatermark['opacity']);
            $stmt->bindValue('created', $oldWatermark['created']);
            $stmt->bindValue('modified', $oldWatermark['modified']);
            $stmt->execute();
            
            $count++;
        }
        
        return $count;
    }
}