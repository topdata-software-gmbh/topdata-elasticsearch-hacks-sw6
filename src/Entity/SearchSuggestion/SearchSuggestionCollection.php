<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchSuggestion;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                          add(SearchSuggestionEntity $entity)
 * @method void                          set(string $key, SearchSuggestionEntity $entity)
 * @method SearchSuggestionEntity[]      getIterator()
 * @method SearchSuggestionEntity[]      getElements()
 * @method SearchSuggestionEntity|null   get(string $key)
 * @method SearchSuggestionEntity|null   first()
 * @method SearchSuggestionEntity|null   last()
 */
class SearchSuggestionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SearchSuggestionEntity::class;
    }
}
