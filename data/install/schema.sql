CREATE TABLE watermark_set (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_default TINYINT(1) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE watermark_setting (
    id INT AUTO_INCREMENT NOT NULL,
    set_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    media_id INT NOT NULL,
    position VARCHAR(50) NOT NULL,
    opacity DOUBLE PRECISION NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX IDX_WATERMARK_SETTING_SET (set_id),
    CONSTRAINT FK_WATERMARK_SETTING_SET FOREIGN KEY (set_id) REFERENCES watermark_set (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE watermark_assignment (
    id INT AUTO_INCREMENT NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id INT NOT NULL,
    watermark_set_id INT DEFAULT NULL,
    explicitly_no_watermark TINYINT(1) NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    PRIMARY KEY(id),
    UNIQUE INDEX resource (resource_type, resource_id),
    INDEX IDX_WATERMARK_ASSIGNMENT_SET (watermark_set_id),
    CONSTRAINT FK_WATERMARK_ASSIGNMENT_SET FOREIGN KEY (watermark_set_id) REFERENCES watermark_set (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;