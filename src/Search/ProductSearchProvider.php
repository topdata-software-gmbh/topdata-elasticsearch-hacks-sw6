<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Search;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AutoconfigureTag('topdata_enhanced_search.search_provider')]
class ProductSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private SalesChannelRepository $productRepository,
        private UrlGeneratorInterface $router,
        private CurrencyFormatter $currencyFormatter,
    ) {}

    public function getType(): string
    {
        return 'products';
    }

    public function search(string $term, SalesChannelContext $context, int $limit): array
    {
        $criteria = new Criteria();
        $criteria->addState(Criteria::STATE_ELASTICSEARCH_AWARE);
        $criteria->setTerm($term);
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('name', $term), 1000));
        $criteria->addQuery(new ScoreQuery(new EqualsFilter('productNumber', $term), 2000));
        $criteria->setLimit($limit);
        $criteria->addAssociation('cover.media');
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $result = $this->productRepository->search($criteria, $context);

        $items = [];
        foreach ($result->getEntities() as $product) {
            $price = $product->getCalculatedPrice();
            if ($product->getCalculatedPrices()->count() > 0) {
                $price = $product->getCalculatedPrices()->last();
            }

            $imageUrl = null;
            if ($product->getCover()?->getMedia()?->getUrl()) {
                $imageUrl = $product->getCover()->getMedia()->getUrl();
            }

            $customFields = $product->getCustomFields();
            $subtitle = is_array($customFields) ? ($customFields['tdg_props_mig_zusatztext'] ?? null) : null;

            $items[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'subtitle' => $subtitle,
                'url' => $this->router->generate('frontend.detail.page', ['productId' => $product->getId()]),
                'imageUrl' => $imageUrl,
                'price' => $price ? number_format($price->getUnitPrice(), 2, '.', '') : null,
                'priceFormatted' => $price
                    ? $this->currencyFormatter->formatCurrencyByLanguage(
                        $price->getUnitPrice(),
                        $context->getCurrency()->getIsoCode(),
                        $context->getLanguageId(),
                        $context->getContext()
                    )
                    : null,
            ];
        }

        return $items;
    }
}
