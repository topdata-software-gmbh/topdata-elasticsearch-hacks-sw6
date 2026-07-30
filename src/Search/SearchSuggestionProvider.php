<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Search;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataElasticsearchHacksSW6\Service\SearchSuggestionService;

#[AutoconfigureTag('topdata_enhanced_search.search_provider')]
class SearchSuggestionProvider implements SearchProviderInterface
{
    public function __construct(
        private SearchSuggestionService $searchSuggestionService,
    ) {}

    public function getType(): string
    {
        return 'suggestions';
    }

    public function search(string $term, SalesChannelContext $context, int $limit): array
    {
        $result = $this->searchSuggestionService->search($term, $limit);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
        ];
    }
}
