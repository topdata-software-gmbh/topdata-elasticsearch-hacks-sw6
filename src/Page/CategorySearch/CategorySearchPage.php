<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Storefront\Page\Page;

class CategorySearchPage extends Page
{
    protected string $searchTerm = '';
    protected CategoryCollection $categories;
    protected int $total = 0;

    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    public function setSearchTerm(string $searchTerm): void
    {
        $this->searchTerm = $searchTerm;
    }

    public function getCategories(): CategoryCollection
    {
        return $this->categories;
    }

    public function setCategories(CategoryCollection $categories): void
    {
        $this->categories = $categories;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }
}
