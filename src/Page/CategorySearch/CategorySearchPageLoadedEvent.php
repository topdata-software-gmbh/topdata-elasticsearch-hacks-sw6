<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

class CategorySearchPageLoadedEvent extends PageLoadedEvent
{
    public function __construct(
        protected CategorySearchPage $page,
        SalesChannelContext $salesChannelContext,
        Request $request,
    ) {
        parent::__construct($salesChannelContext, $request);
    }

    public function getPage(): CategorySearchPage
    {
        return $this->page;
    }
}
