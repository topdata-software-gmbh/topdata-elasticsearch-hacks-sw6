<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Search;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\ManufacturerSearchService;

#[AutoconfigureTag('topdata_enhanced_search.search_provider')]
class ManufacturerSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ManufacturerSearchService $manufacturerSearchService,
        private UrlGeneratorInterface $router,
    ) {}

    public function getType(): string
    {
        return 'manufacturers';
    }

    public function search(string $term, SalesChannelContext $context, int $limit): array
    {
        $result = $this->manufacturerSearchService->search($term, $limit);

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'url' => $this->router->generate('frontend.manufacturer.listing', ['id' => $row['id']]),
            ];
        }

        return [
            'items' => $items,
            'total' => $result['total'],
        ];
    }
}
