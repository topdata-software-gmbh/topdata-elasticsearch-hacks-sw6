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

/**
 * Boosts Elasticsearch product search results by adding SHOULD clauses that
 * elevate products matching the search term in specific fields.
 *
 * Boost priority (highest to lowest):
 *   1. productNumber wildcard  (2,000,000) — product number contains the term
 *   2. productNumber prefix    (1,800,000) — product number starts with the term
 *   3. name match_phrase       (1,000,000) — exact phrase match in analyzed name
 *   4. name match AND          (  500,000) — all tokens present in analyzed name
 *   5. name delimiter AND      (  200,000) — all tokens present in delimiter-analyzed name
 *   6. name wildcard           (   15,000) — substring match in raw keyword name
 *   7. name prefix             (    1,100) — prefix match in raw keyword name
 *
 * Product-number boosts are intentionally higher than name-field boosts so that
 * a product whose article number contains the search term always outranks one
 * that merely has the term somewhere in its display name. This is critical for
 * B2B / industrial catalogues where users often search by article number.
 */
class ElasticsearchSearchSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchEntitySearcherSearchEvent::class => 'onProductSearchBeforeQuery',
        ];
    }

    /**
     * Injects SHOULD boost queries into the product search to prioritise
     * product-number matches above name matches, and synonym-matched products
     * above loosely related results.
     *
     * All queries are added as SHOULD clauses to the boolean search that
     * Shopware builds in ElasticsearchHelper::addTerm. The base MUST query
     * continues to match loosely relevant documents (prefix/ngram matches on
     * individual tokens), so documents that do NOT match any of our boost
     * clauses can still be returned — they simply miss the additive score.
     *
     * WildcardQuery and PrefixQuery are term-level Lucene queries that
     * inherently produce a constant score (no tf/idf or field-length
     * normalisation), so they are used directly with a boost.
     *
     * For analysed name-field queries (match_phrase, match), a
     * ConstantScoreQuery wrapper is used to neutralise Lucene's length
     * normalisation. Without this, a short product name (e.g. "WC-Papier")
     * would score much higher than a longer one (e.g. "BULKYSOFT WC-Papier
     * Classic") for the same match, letting unrelated products occasionally
     * overtake both. The constant_score guarantees an identical additive
     * score for every document whose analysed tokens match, regardless of
     * field length or term frequency.
     */
    public function onProductSearchBeforeQuery(ElasticsearchEntitySearcherSearchEvent $event): void
    {
        // ---- Guard: only apply to product entity searches
        if ($event->getDefinition()->getEntityName() !== 'product') {
            return;
        }

        $term = $event->getCriteria()->getTerm();

        // ---- Guard: require at least 2 characters to avoid noisy low-signal matches
        if ($term === null || $term === '' || mb_strlen($term) < 2) {
            return;
        }

        $search = $event->getSearch();
        $lowerTerm = mb_strtolower($term);
        $languageIdChain = $event->getContext()->getLanguageIdChain();

        // ─────────────────────────────────────────────────────────────────────
        // Product-number boost queries (language-agnostic, highest priority)
        // ─────────────────────────────────────────────────────────────────────
        //
        // A customer searching for "4000" expects the product whose article
        // number IS "4000WD/F" to appear FIRST — ahead of a product that merely
        // has "4000 mAh" somewhere in its name (e.g. "Scosche magicPACK
        // Powerbank 4000 mAh"). Without these queries, the product-number match
        // only receives Shopware's base ranking weight (~1000) from the
        // `productNumber.search` field, while a name match can collect 1M+500K
        // from match_phrase/match AND boosts and easily outrank it.
        //
        // These boosts are placed OUTSIDE the language-ID loop because
        // `productNumber` is not translated — it is the same value across all
        // languages. Adding them inside the loop would redundantly duplicate
        // the same query N times (once per language).
        //
        // Wildcard and Prefix queries are term-level in Lucene: they produce a
        // constant score inherently (no tf/idf, no field-length normalisation),
        // so wrapping them in ConstantScoreQuery is unnecessary.
        //
        // WildcardQuery *{term}* : catches any product number that contains the
        //   search term as a substring (e.g. "4000" matches "COLOP 4000WD/F"
        //   and also "PWR-4000-XL"). Boost 2,000,000.
        //
        // PrefixQuery  {term}*  : additional boost for product numbers that
        //   START with the search term (e.g. "4000" matches "4000WD/F" but NOT
        //   "PWR-4000"). Boost 1,800,000 — slightly lower than the wildcard so
        //   that a leading-digit match that gets BOTH boosts (2M + 1.8M = 3.8M)
        //   has a modest edge over a contains-only match (2M), which is
        //   desirable but not overwhelming.

        $search->addQuery(
            new WildcardQuery('productNumber', sprintf('*%s*', $lowerTerm), ['boost' => 2_000_000.0]),
            BoolQuery::SHOULD
        );

        $search->addQuery(
            new PrefixQuery('productNumber', $lowerTerm, ['boost' => 1_800_000.0]),
            BoolQuery::SHOULD
        );

        // ─────────────────────────────────────────────────────────────────────
        // Name-field boost queries (language-specific)
        // ─────────────────────────────────────────────────────────────────────
        //
        // These queries are applied per language because the `name` field is
        // stored as a translated object with language-ID sub-keys (e.g.
        // `name.2fbb5fe2e29a4d70aa5854ce7ce3e20b.search`).
        //
        // The boost magnitudes are chosen large enough to dominate any
        // realistic base score from Shopware's ranking configuration (which
        // typically assigns values in the hundreds-to-thousands range).

        foreach ($languageIdChain as $languageId) {
            $analyzedField = sprintf('name.%s.search', $languageId);
            $delimiterField = sprintf('name.%s.delimiter', $languageId);
            $keywordField = sprintf('name.%s', $languageId);

            // ---- ConstantScore MatchPhrase: exact token-sequence match in analyzed name
            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchPhraseQuery($analyzedField, $lowerTerm),
                    ['boost' => 1_000_000.0]
                ),
                BoolQuery::SHOULD
            );

            // ---- ConstantScore Match AND: all tokens present (any order) in analyzed name
            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($analyzedField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 500_000.0]
                ),
                BoolQuery::SHOULD
            );

            // ---- ConstantScore Match AND on delimiter sub-field: hyphenated tokens
            //      (e.g. "WC-Papier" analysed as ["wc", "papier"])
            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($delimiterField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 200_000.0]
                ),
                BoolQuery::SHOULD
            );

            // ---- Wildcard/Prefix on raw keyword name field
            //      These are term-level queries that inherently produce a
            //      constant score; no ConstantScoreQuery wrapper needed.
            //      They serve as a secondary ordering hint for products whose
            //      keyword-typed (non-analysed) name field contains the term
            //      as a whitespace-delimited substring or a prefix.
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
