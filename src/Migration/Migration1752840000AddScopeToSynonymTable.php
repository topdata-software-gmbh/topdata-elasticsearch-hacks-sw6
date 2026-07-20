<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752840000AddScopeToSynonymTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752840000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'topdata_es_synonym', 'scope')) {
            $connection->executeStatement('
                ALTER TABLE `topdata_es_synonym`
                ADD COLUMN `scope` VARCHAR(50) NOT NULL DEFAULT "global" AFTER `synonyms`
            ');
        }
    }

    private function columnExists(Connection $connection, string $table, string $column): bool
    {
        $result = $connection->fetchOne(
            'SHOW COLUMNS FROM `' . $table . '` LIKE :column',
            ['column' => $column]
        );

        return $result !== false;
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
