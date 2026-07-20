<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class SearchStatsCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SearchStatsEntity::class;
    }
}
