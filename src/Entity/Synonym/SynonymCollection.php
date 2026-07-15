<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                 add(SynonymEntity $entity)
 * @method void                 set(string $key, SynonymEntity $entity)
 * @method SynonymEntity[]      getIterator()
 * @method SynonymEntity[]      getElements()
 * @method SynonymEntity|null   get(string $key)
 * @method SynonymEntity|null   first()
 * @method SynonymEntity|null   last()
 */
class SynonymCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SynonymEntity::class;
    }
}
