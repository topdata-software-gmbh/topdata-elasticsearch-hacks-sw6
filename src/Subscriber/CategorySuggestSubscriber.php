<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService;

class CategorySuggestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CategorySearchService $categorySearchService,
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

        $result = $this->categorySearchService->search(
            $term,
            $event->getSalesChannelContext(),
        );

        if ($result['categories']->count() === 0) {
            return;
        }

        $event->getPage()->addExtension('topdata_category_suggest', new ArrayEntity([
            'categories' => $result['categories'],
            'total' => $result['total'],
        ]));
    }
}
