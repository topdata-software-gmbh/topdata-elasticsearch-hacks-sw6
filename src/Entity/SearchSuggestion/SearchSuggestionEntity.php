<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchSuggestion;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SearchSuggestionEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected string $targetType;
    protected ?string $targetId = null;
    protected ?string $targetUrl = null;
    protected ?array $targetParams = null;
    protected int $priority = 0;
    protected bool $active = true;

    public function getTerm(): string { return $this->term; }
    public function setTerm(string $term): void { $this->term = $term; }
    public function getTargetType(): string { return $this->targetType; }
    public function setTargetType(string $targetType): void { $this->targetType = $targetType; }
    public function getTargetId(): ?string { return $this->targetId; }
    public function setTargetId(?string $targetId): void { $this->targetId = $targetId; }
    public function getTargetUrl(): ?string { return $this->targetUrl; }
    public function setTargetUrl(?string $targetUrl): void { $this->targetUrl = $targetUrl; }
    public function getTargetParams(): ?array { return $this->targetParams; }
    public function setTargetParams(?array $targetParams): void { $this->targetParams = $targetParams; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): void { $this->priority = $priority; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
}
