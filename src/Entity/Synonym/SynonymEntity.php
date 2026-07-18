<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SynonymEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected string $synonyms;
    protected string $scope;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getSynonyms(): string
    {
        return $this->synonyms;
    }

    public function setSynonyms(string $synonyms): void
    {
        $this->synonyms = $synonyms;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }
}
