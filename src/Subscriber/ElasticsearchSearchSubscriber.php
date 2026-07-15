<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\FullText\MatchPhraseQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use Shopware\Elasticsearch\Framework\DataAbstractionLayer\Event\ElasticsearchEntitySearcherSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ElasticsearchSearchSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchEntitySearcherSearchEvent::class => 'onProductSearchBeforeQuery',
        ];
    }

    public function onProductSearchBeforeQuery(ElasticsearchEntitySearcherSearchEvent $event): void
    {
        if ($event->getDefinition()->getEntityName() !== 'product') {
            return;
        }

        $term = $event->getCriteria()->getTerm();

        if ($term === null || $term === '' || mb_strlen($term) < 2) {
            return;
        }

        $search = $event->getSearch();
        $lowerTerm = mb_strtolower($term);
        $languageIdChain = $event->getContext()->getLanguageIdChain();

        foreach ($languageIdChain as $languageId) {
            $field = sprintf('name.%s', $languageId);

            $search->addQuery(
                new MatchPhraseQuery($field, $lowerTerm, ['boost' => 12]),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new MatchQuery($field, $lowerTerm, ['boost' => 5, 'operator' => 'and']),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new PrefixQuery($field, $lowerTerm, ['boost' => 1.5]),
                BoolQuery::SHOULD
            );
        }
    }
}
