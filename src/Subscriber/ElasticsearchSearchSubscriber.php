<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\Compound\ConstantScoreQuery;
use OpenSearchDSL\Query\FullText\MatchPhraseQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use OpenSearchDSL\Query\TermLevel\TermQuery;
use OpenSearchDSL\Query\TermLevel\WildcardQuery;
use Shopware\Elasticsearch\Framework\DataAbstractionLayer\Event\ElasticsearchEntitySearcherSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Boosts Elasticsearch product search results by adding SHOULD clauses that
 * elevate products matching the search term in specific fields.
 *
 * Boost priority (highest to lowest):
 *   1. productNumber exact              (2,000,000) — product number equals the term exactly
 *   1b.productNumber stripped exact     (1,500,000) — exact match on leading-zero-stripped digits (e.g. "4000" for "004000")
 *   2. name match_phrase                 (1,000,000) — exact phrase match in analyzed name
 *   3. name match AND                    (  500,000) — all tokens present in analyzed name
 *   4. name delimiter AND                (  200,000) — all tokens present in delimiter-analyzed name
 *   5. name wildcard                     (   15,000) — substring match in raw keyword name
 *   6. name prefix                       (    1,100) — prefix match in raw keyword name
 *
 * Product-number boosts are intentionally higher than name-field boosts so that
 * a product whose article number exactly matches the search term always
 * outranks one that merely has the term somewhere in its display name. This is
 * critical for B2B / industrial catalogues where users often search by article
 * number. A TermQuery is used (not WildcardQuery/PrefixQuery) to avoid false
 * positives: searching for "4000" must not match "40001" or "4000WD/F".
 * For purely numeric searches, leading zeros are stripped in PHP and a suffix
 * WildcardQuery is added so that "4000" also finds products with article
 * number "004000".
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
     * TermQuery, WildcardQuery and PrefixQuery are term-level Lucene queries
     * that inherently produce a constant score (no tf/idf or field-length
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
        // Product-number boost query (language-agnostic, highest priority)
        // ─────────────────────────────────────────────────────────────────────
        //
        // A customer searching for "4000" expects the product whose article
        // number is EXACTLY "4000" to appear FIRST — ahead of a product that
        // merely has "4000 mAh" somewhere in its name (e.g. "Scosche magicPACK
        // Powerbank 4000 mAh") or whose article number only starts with or
        // contains "4000" (e.g. "40001", "4000WD/F"). Without this query, the
        // exact product-number match only receives Shopware's base ranking weight
        // (~1000) from the `productNumber.search` field, while a name match can
        // collect 1M+500K from match_phrase/match AND boosts and easily outrank
        // it.
        //
        // A TermQuery on the `productNumber` keyword field is used (not
        // WildcardQuery or PrefixQuery) to enforce an EXACT match. The keyword
        // field uses `sw_lowercase_normalizer`, so the comparison is
        // case-insensitive but the entire value must be identical. This avoids
        // false positives: searching "4000" must NOT match "40001" (different
        // article number) or "4000WD/F" (different article number).
        //
        // This boost is placed OUTSIDE the language-ID loop because
        // `productNumber` is not translated — it is the same value across all
        // languages. Adding it inside the loop would redundantly duplicate the
        // query N times (once per language).
        //
        // TermQuery is a term-level Lucene query: it produces a constant score
        // inherently (no tf/idf, no field-length normalisation), so wrapping
        // it in ConstantScoreQuery is unnecessary.
        //
        // Leading-zero matching (e.g. "004000" → SKU "4000") is handled in
        // two layers:
        //   1. Matching: `ProductElasticsearchDefinitionDecorator::buildTermQuery`
        //      wraps the original term query AND a `TermQuery` on
        //      `productNumber` for the stripped value in a `bool.should` with
        //      `minimum_should_match: 1`. This makes the document MATCH the
        //      MUST clause if EITHER the original search OR the exact stripped
        //      SKU matches — without this wrapper, a `SHOULD` clause below can
        //      only raise the score of documents the base query already
        //      returned, so the stripped-SKU product would never appear.
        //   2. Ranking: here in `ElasticsearchSearchSubscriber`, we add a
        //      second `TermQuery` for the stripped value with a high boost
        //      (1.5M) as a SHOULD clause to push the matched product to the
        //      top of the results.

        $search->addQuery(
            new TermQuery('productNumber', $lowerTerm, ['boost' => 2_000_000.0]),
            BoolQuery::SHOULD
        );

        // ---- Leading-zero TermQuery: for purely numeric terms, strip leading
        //      zeros and add a second TermQuery for the stripped value. This
        //      makes the search bidirectional:
        //        "4000"   → also matches SKU "004000"
        //        "004000" → also matches SKU "4000"
        //      Without this, only the exact TermQuery above fires, so searching
        //      "004000" would miss SKU "4000" (they are different keyword values).
        //      A WildcardQuery is NOT used here because a suffix wildcard `*4000`
        //      would match "40001" (false positive), and as a SHOULD clause it
        //      cannot help a document that fails the base query's MUST clause.
        //      The TermQuery is the most precise tool: it only matches documents
        //      whose productNumber exactly equals the stripped value.
        //
        //      Only fires when ctype_digit is true (pure numeric strings) and
        //      the stripped value differs from the original (avoid redundant
        //      duplicate of the TermQuery above).
        if (ctype_digit($lowerTerm)) {
            $stripped = ltrim($lowerTerm, '0');
            if ($stripped !== '' && $stripped !== $lowerTerm) {
                $search->addQuery(
                    new TermQuery('productNumber', $stripped, ['boost' => 1_500_000.0]),
                    BoolQuery::SHOULD
                );
            }
        }

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
