<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Search;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService;

#[AutoconfigureTag('topdata_enhanced_search.search_provider')]
class CategorySearchProvider implements SearchProviderInterface
{
    public function __construct(
        private CategorySearchService $categorySearchService,
        private UrlGeneratorInterface $router,
    ) {}

    public function getType(): string
    {
        return 'categories';
    }

    public function search(string $term, SalesChannelContext $context, int $limit): array
    {
        $result = $this->categorySearchService->search($term, $context, $limit);

        $items = [];
        foreach ($result['categories'] as $category) {
            $items[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'url' => $this->router->generate('frontend.navigation.page', ['navigationId' => $category->getId()]),
            ];
        }

        return $items;
    }
}
