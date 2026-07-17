<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ElasticsearchAnalysisCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('elasticsearch.analysis')) {
            return;
        }

        $analysis = $container->getParameter('elasticsearch.analysis');
        if (!\is_array($analysis)) {
            $analysis = [];
        }

        $analysis['filter'] = $analysis['filter'] ?? [];
        $analysis['analyzer'] = $analysis['analyzer'] ?? [];

        $analysis['filter']['topdata_word_delimiter'] = [
            'type' => 'word_delimiter_graph',
            'preserve_original' => true,
            'catenate_all' => true,
            'catenate_words' => true,
            'split_on_case_change' => true,
        ];

        $analysis['analyzer']['topdata_delimiter_analyzer'] = [
            'type' => 'custom',
            'tokenizer' => 'standard',
            'filter' => [
                'topdata_word_delimiter',
                'lowercase',
            ],
        ];

        $container->setParameter('elasticsearch.analysis', $analysis);
    }
}
