<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\FullText\MatchPhraseQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use OpenSearchDSL\Query\TermLevel\WildcardQuery;
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
            $analyzedField = sprintf('name.%s.search', $languageId);
            $delimiterField = sprintf('name.%s.delimiter', $languageId);
            $keywordField = sprintf('name.%s', $languageId);

            $search->addQuery(
                new MatchPhraseQuery($analyzedField, $lowerTerm, ['boost' => 15.0]),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new MatchQuery($analyzedField, $lowerTerm, ['boost' => 5.0, 'operator' => 'and']),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new MatchQuery($delimiterField, $lowerTerm, ['boost' => 1.5, 'operator' => 'and']),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('* %s *', $lowerTerm), ['boost' => 10.0]),
                BoolQuery::SHOULD
            );
            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('%s *', $lowerTerm), ['boost' => 10.0]),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new PrefixQuery($keywordField, $lowerTerm, ['boost' => 1.1]),
                BoolQuery::SHOULD
            );
        }
    }
}
