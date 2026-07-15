<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Elasticsearch\Framework\Indexing\Event\ElasticsearchIndexConfigEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

class ElasticsearchIndexConfigSubscriber implements EventSubscriberInterface
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        $this->synonymService = $synonymService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchIndexConfigEvent::class => 'onIndexConfig',
        ];
    }

    public function onIndexConfig(ElasticsearchIndexConfigEvent $event): void
    {
        $synonymRules = $this->synonymService->exportToArray();

        if (empty($synonymRules)) {
            return;
        }

        $config = $event->getConfig();

        $config['settings']['analysis']['filter']['topdata_synonym_filter'] = [
            'type' => 'synonym',
            'synonyms' => $synonymRules,
        ];

        if (isset($config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer'])) {
            $filters = $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] ?? [];
            array_unshift($filters, 'topdata_synonym_filter');
            $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] = $filters;
        }

        $event->setConfig($config);
    }
}
