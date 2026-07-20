<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SearchStatsEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected int $count;
    protected int $zeroCount;
    protected int $avgResultCount;
    protected ?\DateTimeInterface $lastSearchedAt = null;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    public function getZeroCount(): int
    {
        return $this->zeroCount;
    }

    public function setZeroCount(int $zeroCount): void
    {
        $this->zeroCount = $zeroCount;
    }

    public function getAvgResultCount(): int
    {
        return $this->avgResultCount;
    }

    public function setAvgResultCount(int $avgResultCount): void
    {
        $this->avgResultCount = $avgResultCount;
    }

    public function getLastSearchedAt(): ?\DateTimeInterface
    {
        return $this->lastSearchedAt;
    }

    public function setLastSearchedAt(?\DateTimeInterface $lastSearchedAt): void
    {
        $this->lastSearchedAt = $lastSearchedAt;
    }
}
