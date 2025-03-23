CREATE TABLE IF NOT EXISTS `watermark_set` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `is_default` tinyint(1) NOT NULL DEFAULT '0',
    `enabled` tinyint(1) NOT NULL DEFAULT '1',
    `created` datetime NOT NULL,
    `modified` datetime DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watermark_setting` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `set_id` int(11) NOT NULL,
    `type` varchar(50) NOT NULL,
    `media_id` int(11) NOT NULL,
    `position` varchar(50) NOT NULL DEFAULT 'bottom-right',
    `opacity` float NOT NULL DEFAULT '1',
    `created` datetime NOT NULL,
    `modified` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `set_id` (`set_id`),
    CONSTRAINT `fk_watermark_setting` FOREIGN KEY (`set_id`) REFERENCES `watermark_set` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `watermark_assignment` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `resource_type` varchar(50) NOT NULL,
    `resource_id` int(11) NOT NULL,
    `watermark_set_id` int(11) DEFAULT NULL,
    `explicitly_no_watermark` tinyint(1) NOT NULL DEFAULT '0',
    `created` datetime NOT NULL,
    `modified` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `resource` (`resource_type`, `resource_id`),
    KEY `watermark_set_id` (`watermark_set_id`),
    CONSTRAINT `fk_watermark_assignment` FOREIGN KEY (`watermark_set_id`) REFERENCES `watermark_set` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;