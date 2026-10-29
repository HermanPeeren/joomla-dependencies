CREATE TABLE IF NOT EXISTS `#__dependencies_edges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dependent` varchar(255) NOT NULL COMMENT 'ExtensionKey id of the extension that has the requirement',
  `kind` varchar(20) NOT NULL COMMENT 'extension, joomla, php or php-extension',
  `target` varchar(255) NOT NULL DEFAULT '' COMMENT 'ExtensionKey id, or the PHP extension name',
  `version_constraint` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_dependent` (`dependent`(191)),
  KEY `idx_target` (`target`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__dependencies_locks` (
  `extension` varchar(255) NOT NULL COMMENT 'ExtensionKey id of an extension this plugin set locked = 1 on',
  PRIMARY KEY (`extension`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
