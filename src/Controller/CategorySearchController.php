<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch\CategorySearchPageLoader;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class CategorySearchController extends StorefrontController
{
    public function __construct(
        private readonly CategorySearchPageLoader $categorySearchPageLoader,
    ) {
    }

    #[Route(
        path: '/category-search',
        name: 'frontend.category.search.page',
        methods: ['GET'],
    )]
    public function search(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->categorySearchPageLoader->load($request, $context);

        return $this->renderStorefront('@TopdataElasticsearchHacksSW6/storefront/page/category-search/index.html.twig', [
            'page' => $page,
        ]);
    }
}
