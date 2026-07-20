<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1754000000RenameTablesToTdehPrefix extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1754000000;
    }

    public function update(Connection $connection): void
    {
        $renames = [];
        if ($this->tableExists($connection, 'topdata_es_synonym') && !$this->tableExists($connection, 'tdeh_synonym')) {
            $renames[] = '`topdata_es_synonym` TO `tdeh_synonym`';
        }
        if ($this->tableExists($connection, 'topdata_es_zero_search') && !$this->tableExists($connection, 'tdeh_zero_search')) {
            $renames[] = '`topdata_es_zero_search` TO `tdeh_zero_search`';
        }

        if ($renames !== []) {
            $connection->executeStatement('RENAME TABLE ' . implode(', ', $renames));
        }
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $result = $connection->fetchOne('SHOW TABLES LIKE :table', ['table' => $table]);

        return $result !== false;
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
