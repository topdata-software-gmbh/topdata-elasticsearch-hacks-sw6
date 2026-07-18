<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SynonymEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdeh_synonym';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SynonymEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SynonymCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new LongTextField('synonyms', 'synonyms'))->addFlags(new Required()),
            (new StringField('scope', 'scope'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
        ]);
    }
}
