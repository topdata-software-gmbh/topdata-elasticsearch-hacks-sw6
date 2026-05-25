<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

class SearchExportFormatter
{
    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Term', 'Search Count', 'Last Searched At']);

        foreach ($data as $row) {
            fputcsv($handle, [$row['term'], $row['count'], $row['last_searched_at'] ?? '']);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatMarkdown(array $data): string
    {
        $output = "| Term | Search Count | Last Searched At |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($data as $row) {
            $output .= sprintf(
                "| %s | %d | %s |\n",
                $row['term'],
                $row['count'],
                $row['last_searched_at'] ?? '-'
            );
        }

        return $output;
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatLlmPrompt(array $data): string
    {
        $markdownTable = $this->formatMarkdown($data);

        return <<<PROMPT
# Instructions for LLM Optimization:
You are an e-commerce search and SEO specialist. Below is a structured markdown list containing search terms entered by customers that returned ZERO results (no matches) on our Shopware 6 online storefront.

Please analyze these search terms and suggest highly accurate synonyms to improve search results.
Focus on:
1. Translating customer colloquial terms to technical/brand names (e.g., "WC-Papier" -> "Toilettenpapier").
2. Identifying common typos/alternative spellings (e.g., "Akkuborher" -> "Akkubohrer").
3. Suggesting equivalent search words or broader parent terms where suitable.

## Expected Output Format:
Provide synonym mappings in Elasticsearch explicit mapping format. One mapping per line, where the search term points to its target synonyms, separated by `=>`. Use a single markdown code block:
```text
term1 => synonym1, synonym2
term2 => synonym1
```
Do not include any preambles, introductory text, or explanations. Only provide the requested Elasticsearch explicit mapping code block.

## Zero-Result Search Terms Dataset:
{$markdownTable}
PROMPT;
    }
}
