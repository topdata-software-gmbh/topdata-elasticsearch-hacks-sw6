<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                   add(SearchLogEntity $entity)
 * @method void                   set(string $key, SearchLogEntity $entity)
 * @method SearchLogEntity[]      getIterator()
 * @method SearchLogEntity[]      getElements()
 * @method SearchLogEntity|null   get(string $key)
 * @method SearchLogEntity|null   first()
 * @method SearchLogEntity|null   last()
 */
class SearchLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SearchLogEntity::class;
    }
}
