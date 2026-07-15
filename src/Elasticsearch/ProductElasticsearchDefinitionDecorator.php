<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Elasticsearch;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;

class ProductElasticsearchDefinitionDecorator extends AbstractElasticsearchDefinition
{
    private AbstractElasticsearchDefinition $decorated;

    public function __construct(AbstractElasticsearchDefinition $decorated)
    {
        $this->decorated = $decorated;
    }

    public function getEntityDefinition(): EntityDefinition
    {
        return $this->decorated->getEntityDefinition();
    }

    public function getMapping(Context $context): array
    {
        $mapping = $this->decorated->getMapping($context);

        if (isset($mapping['properties']['name']['properties'])) {
            foreach ($mapping['properties']['name']['properties'] as $langId => $config) {
                $mapping['properties']['name']['properties'][$langId]['fields']['delimiter'] = [
                    'type' => 'text',
                    'analyzer' => 'topdata_delimiter_analyzer',
                ];
            }
        }

        return $mapping;
    }

    public function fetch(array $ids, Context $context): array
    {
        return $this->decorated->fetch($ids, $context);
    }

    public function buildTermQuery(Context $context, Criteria $criteria): \OpenSearchDSL\BuilderInterface
    {
        return $this->decorated->buildTermQuery($context, $criteria);
    }
}
