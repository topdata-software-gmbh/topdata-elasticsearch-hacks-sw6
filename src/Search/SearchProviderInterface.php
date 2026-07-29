<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Search;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface SearchProviderInterface
{
    public function getType(): string;

    public function search(string $term, SalesChannelContext $context, int $limit): array;
}
