<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1753000000CreateSearchSuggestionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1753000000;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `tdeh_search_suggestion` (
    `id` BINARY(16) NOT NULL,
    `term` VARCHAR(255) NOT NULL,
    `target_type` VARCHAR(50) NOT NULL COMMENT 'category|device|product|custom',
    `target_id` VARCHAR(255) DEFAULT NULL,
    `target_url` VARCHAR(512) DEFAULT NULL,
    `target_params` JSON DEFAULT NULL,
    `priority` INT(11) NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_term` (`term`),
    INDEX `idx_active_priority` (`active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void {}
}
