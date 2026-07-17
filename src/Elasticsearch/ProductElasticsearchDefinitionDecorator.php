<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\TermLevel\TermQuery;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;

/**
 * Decorates the product ES definition to inject a `.delimiter` sub-field on
 * the name mapping and to broaden term matching so that searching for a number
 * with leading zeros (e.g. "004000") also matches the product whose article
 * number is the stripped form (e.g. "4000").
 */
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

    /**
     * Builds the term query, then — when the search term is purely numeric
     * with leading zeros — wraps it in a `bool` query with
     * `minimum_should_match: 1` containing the original term query and a
     * `TermQuery` on `productNumber` for the leading-zero-stripped value.
     *
     * Why this is necessary: Shopware's base term query for "004000" tokenises
     * and analyses the term and only matches documents whose analysed fields
     * contain the tokens ["004000"]. A product with SKU "4000" has the token
     * ["4000"] in `productNumber.search` and rarely appears in the result
     * set. Boosting it from a SHOULD clause (as `ElasticsearchSearchSubscriber`
     * does) is therefore useless — a SHOULD clause can only raise the score of
     * documents that the MUST clause already returned.
     *
     * By wrapping the original term query AND a `TermQuery` on `productNumber`
     * for the stripped value in a `bool.should` with `minimum_should_match: 1`,
     * the document matches the MUST clause as soon as EITHER the original
     * search OR the exact stripped-SKU `TermQuery` matches. The subsequent
     * SHOULD boost in `ElasticsearchSearchSubscriber` then pushes it to the
     * top of the ranking.
     *
     * Example: searching "004000" wraps
     *   { original query for "004000" } + { term: productNumber = "4000" }
     * so the product with SKU "4000" matches the MUST clause via the
     * TermQuery even though its `productNumber.search` token is "4000"
     * (not "004000").
     *
     * The opposite direction ("4000" → "004000") is not handled here because
     * we cannot know how many leading zeros the stored SKU contains; it
     * relies on ngram overlap in Shopware's base query.
     */
    public function buildTermQuery(Context $context, Criteria $criteria): BuilderInterface
    {
        $query = $this->decorated->buildTermQuery($context, $criteria);

        $term = $criteria->getTerm();
        if ($term === null || $term === '') {
            return $query;
        }

        $lowerTerm = mb_strtolower($term);

        // Only applies to purely numeric terms (e.g. "004000"). Alphanumeric
        // SKUs (e.g. "4000WD/F") are skipped because stripping leading zeros
        // is meaningless for them.
        if (!ctype_digit($lowerTerm)) {
            return $query;
        }

        $stripped = ltrim($lowerTerm, '0');

        // Skip when stripping produced nothing useful:
        //  - empty (e.g. "0", "00")   → no meaningful stripped value
        //  - unchanged (e.g. "4000")  → no leading zeros, no wrapper needed
        if ($stripped === '' || $stripped === $lowerTerm) {
            return $query;
        }

        // Build a `bool.should` with `minimum_should_match: 1` so a document
        // matches if EITHER the original Shopware term query OR a TermQuery on
        // the `productNumber` keyword field for the stripped value matches.
        //
        // The TermQuery targets the keyword field (`productNumber`) which uses
        // `sw_lowercase_normalizer`, so the comparison is case-insensitive but
        // the entire product number must equal the stripped value exactly.
        $wrapper = new BoolQuery();
        $wrapper->add($query, BoolQuery::SHOULD);
        $wrapper->add(new TermQuery('productNumber', $stripped), BoolQuery::SHOULD);
        $wrapper->addParameter('minimum_should_match', 1);

        return $wrapper;
    }
}
