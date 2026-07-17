<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService;

class CategorySearchPageLoader
{
    public function __construct(
        private readonly GenericPageLoaderInterface $genericLoader,
        private readonly CategorySearchService $categorySearchService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function load(Request $request, SalesChannelContext $salesChannelContext): CategorySearchPage
    {
        $page = $this->genericLoader->load($request, $salesChannelContext);
        $page = CategorySearchPage::createFrom($page);

        $term = (string) $request->query->get('search', '');
        $page->setSearchTerm($term);

        if ($term !== '' && mb_strlen($term) >= 2) {
            $result = $this->categorySearchService->search(
                $term,
                $salesChannelContext,
                50,
            );

            $page->setCategories($result['categories']);
            $page->setTotal($result['total']);
        }

        $this->eventDispatcher->dispatch(
            new CategorySearchPageLoadedEvent($page, $salesChannelContext, $request),
        );

        return $page;
    }
}
