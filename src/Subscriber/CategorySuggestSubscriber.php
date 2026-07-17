<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CategorySuggestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SuggestPageLoadedEvent::class => 'onSuggestPageLoaded',
        ];
    }

    public function onSuggestPageLoaded(SuggestPageLoadedEvent $event): void
    {
        $term = $event->getRequest()->query->get('search', '');

        if ($term === '' || mb_strlen($term) < 2) {
            return;
        }

        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $displayLimit = (int) $this->systemConfigService->get(
            'TopdataElasticsearchHacksSW6.config.categorySuggestLimit',
            $salesChannelId
        ) ?: 8;

        $criteria = new Criteria();
        $criteria->setLimit(50);
        $criteria->addFilter(new ContainsFilter('name', $term));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addFilter(new EqualsFilter('type', CategoryDefinition::TYPE_PAGE));
        $criteria->addAssociations(['media', 'seoUrls']);

        $excludedCategories = $this->systemConfigService->get(
            'TopdataElasticsearchHacksSW6.config.excludedCategories',
            $salesChannelId
        );

        if (!empty($excludedCategories) && \is_array($excludedCategories)) {
            $criteria->addFilter(
                new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [new EqualsAnyFilter('id', $excludedCategories)]
                )
            );
        }

        $rootIds = array_filter([
            $salesChannelContext->getSalesChannel()->getNavigationCategoryId(),
            $salesChannelContext->getSalesChannel()->getFooterCategoryId(),
            $salesChannelContext->getSalesChannel()->getServiceCategoryId(),
        ]);

        if ($rootIds !== []) {
            $rootFilter = new OrFilter();
            foreach ($rootIds as $rootId) {
                $rootFilter->addQuery(new EqualsFilter('id', $rootId));
                $rootFilter->addQuery(new ContainsFilter('path', '|' . $rootId . '|'));
            }
            $criteria->addFilter($rootFilter);
        }

        $categories = $this->categoryRepository->search($criteria, $salesChannelContext);

        if ($categories->count() === 0) {
            return;
        }

        $entities = $categories->getEntities();
        $entities->sort(fn ($a, $b) => $this->sortByRelevance($a, $b, $term));

        $trimmed = new CategoryCollection();
        $i = 0;
        foreach ($entities as $entity) {
            if ($i >= $displayLimit) {
                break;
            }
            $trimmed->add($entity);
            $i++;
        }

        $event->getPage()->addExtension('topdata_category_suggest', new ArrayEntity([
            'categories' => $trimmed,
            'total' => $categories->getTotal(),
        ]));
    }

    private function sortByRelevance($a, $b, string $term): int
    {
        $aName = mb_strtolower($a->getTranslation('name') ?? $a->getName() ?? '');
        $bName = mb_strtolower($b->getTranslation('name') ?? $b->getName() ?? '');
        $termLower = mb_strtolower($term);

        $aExact = $aName === $termLower;
        $bExact = $bName === $termLower;

        if ($aExact !== $bExact) {
            return $aExact ? -1 : 1;
        }

        $aStartsWith = str_starts_with($aName, $termLower);
        $bStartsWith = str_starts_with($bName, $termLower);

        if ($aStartsWith !== $bStartsWith) {
            return $aStartsWith ? -1 : 1;
        }

        return ($a->getLevel() ?? 0) <=> ($b->getLevel() ?? 0);
    }
}
