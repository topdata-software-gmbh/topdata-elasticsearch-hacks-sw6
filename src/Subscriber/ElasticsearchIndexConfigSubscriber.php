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

        $config = $event->getConfig();

        if (empty($synonymRules)) {
            $event->setConfig($config);
            return;
        }

        // ignore_case makes rule matching case-insensitive so user-entered
        // (uppercase) query tokens match the lowercased rules stored in DB.
        $config['settings']['analysis']['filter']['topdata_synonym_filter'] = [
            'type' => 'synonym',
            'synonyms' => $synonymRules,
            'ignore_case' => true,
        ];

        // Order matters: lowercase must run BEFORE the synonym filter, otherwise
        // multi-token rules like "wc papier => wc-papier" never fire, because the
        // tokenizer produces uppercase ["WC", "Papier"] and the synonym filter is
        // case-sensitive (we set ignore_case as a belt-and-braces safety net, but
        // the explicit lowercase stage is what reliably lets multi-word rules match).
        // word_delimiter runs LAST so the synonym output token (e.g. "wc-papier")
        // is then split into [wc, papier, wc-papier, wcpapier] for substring matches.
        if (isset($config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer'])) {
            $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] = [
                'lowercase',
                'topdata_synonym_filter',
                'topdata_word_delimiter',
            ];
        }

        // Shopware's name.{lang}.search sub-field is analyzed by these analyzers
        // (sw_whitespace_analyzer by default, sw_german_analyzer/sw_english_analyzer
        // via language_analyzer_mapping). The high-boost match_phrase (30x) and
        // match-AND (20x) queries in ElasticsearchSearchSubscriber target that
        // .search field, so without synonyms here a multi-word query like "WC Papier"
        // can never produce the single hyphenated token "wc-papier" that was indexed
        // for "WC-Papier" products, and the product never gets the top-boost.
        // Inject synonyms right after lowercase and before any stop filter so rule
        // inputs are lowercased and not dropped as stop words.
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
