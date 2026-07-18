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
        $synonymRules = $this->synonymService->exportToArray('product');

        $config = $event->getConfig();

        if (empty($synonymRules)) {
            $event->setConfig($config);
            return;
        }

        $config['settings']['analysis']['filter']['topdata_synonym_filter'] = [
            'type' => 'synonym',
            'synonyms' => $synonymRules,
            'ignore_case' => true,
        ];

        if (isset($config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer'])) {
            $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] = [
                'lowercase',
                'topdata_synonym_filter',
                'topdata_word_delimiter',
            ];
        }

        $swSearchAnalyzers = ['sw_whitespace_analyzer', 'sw_german_analyzer', 'sw_english_analyzer'];
        foreach ($swSearchAnalyzers as $analyzerName) {
            if (!isset($config['settings']['analysis']['analyzer'][$analyzerName])) {
                continue;
            }

            $filters = $config['settings']['analysis']['analyzer'][$analyzerName]['filter'] ?? [];

            if (in_array('topdata_synonym_filter', $filters, true)) {
                continue;
            }

            $insertAt = 0;
            $lowercasePos = array_search('lowercase', $filters, true);
            if ($lowercasePos !== false) {
                $insertAt = $lowercasePos + 1;
            }

            array_splice($filters, $insertAt, 0, ['topdata_synonym_filter']);
            $config['settings']['analysis']['analyzer'][$analyzerName]['filter'] = $filters;
        }

        $event->setConfig($config);
    }
}
