<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchSuggestion;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SearchSuggestionEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdeh_search_suggestion';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SearchSuggestionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SearchSuggestionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new StringField('target_type', 'targetType'))->addFlags(new Required()),
            new StringField('target_id', 'targetId'),
            new StringField('target_url', 'targetUrl'),
            new JsonField('target_params', 'targetParams'),
            new IntField('priority', 'priority'),
            (new BoolField('active', 'active'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
