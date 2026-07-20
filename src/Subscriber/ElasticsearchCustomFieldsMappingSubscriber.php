<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Elasticsearch\Event\ElasticsearchCustomFieldsMappingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ElasticsearchCustomFieldsMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchCustomFieldsMappingEvent::class => 'onCustomFieldsMapping',
        ];
    }

    public function onCustomFieldsMapping(ElasticsearchCustomFieldsMappingEvent $event): void
    {
        if ($event->getEntity() !== 'product') {
            return;
        }

        $event->setMapping('topdata_is_topseller', CustomFieldTypes::BOOL);
    }
}
