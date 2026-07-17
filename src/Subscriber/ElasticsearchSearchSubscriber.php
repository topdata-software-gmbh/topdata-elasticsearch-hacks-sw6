<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\Compound\ConstantScoreQuery;
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

            // The boost queries below are added as SHOULD clauses to the boolean
            // search that Shopware builds in ElasticsearchHelper::addTerm. That
            // base (MUST) query keeps matching loosely relevant documents (e.g.
            // prefix/ngram matches on individual query tokens), so docs that do
            // NOT match our boost clauses can still be returned - they just don't
            // get the additive score. Previously the boost magnitudes were small
            // (30, 20, ...) and Lucene length-normalised the inner relevance,
            // so a short-name "WC-Papier" product would beat a longer-name one
            // for the same boost, while both could be overtaken by unrelated
            // products that scored well through the base query (Papierhandtuecher
            // matched "papier" via prefix -> large ranking-weighted base score).
            //
            // Mitigation: wrap the boost clauses in a constant_score query, which
            // yields a CONSTANT score for every matching document (independent of
            // field length and term frequency). This guarantees that all products
            // whose analyzed tokens match the synonym-expanded query (e.g. both
            // "BULKYSOFT WC-Papier" and "EDELWEISS WC-Papier Classic") receive an
            // IDENTICAL large additive score, so they crowd to the top regardless
            // of their name length, and unrelated base-only matches rank below.
            // Magnitudes are chosen large enough to dominate realistic base scores
            // (Shopware weights each search field by its `ranking` config value,
            // which is typically in the hundreds-to-thousands range).
            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchPhraseQuery($analyzedField, $lowerTerm),
                    ['boost' => 1_000_000.0]
                ),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($analyzedField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 500_000.0]
                ),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($delimiterField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 200_000.0]
                ),
                BoolQuery::SHOULD
            );

            // Wildcard and prefix queries already produce a constant score
            // (Lucene does not apply tf/idf or length normalisation to them),
            // so wrapping them in constant_score is unnecessary. They are kept
            // as a secondary ordering hint for products whose keyword-typed
            // name field contains the search term as a substring / prefix.
            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('* %s *', $lowerTerm), ['boost' => 15_000.0]),
                BoolQuery::SHOULD
            );
            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('%s *', $lowerTerm), ['boost' => 15_000.0]),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new PrefixQuery($keywordField, $lowerTerm, ['boost' => 1_100.0]),
                BoolQuery::SHOULD
            );
        }
    }
}
