---
filename: "_ai/backlog/active/260720_1811__IMPLEMENTATION_PLAN__change_tokenizer_to_edge_ngram.md"
title: "Change Search Tokenizer from N-Gram to Edge N-Gram"
createdAt: 2026-07-20 18:11
updatedAt: 2026-07-20 18:11
status: completed
completedAt: 2026-07-21 09:52
priority: high
tags: [elasticsearch, search, performance, swiss-multilingual, tokenization]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
By default, Shopware 6.7 indexes translatable text fields and SKUs using a standard mid-word `ngram` tokenizer on sub-fields mapped to the `sw_ngram_analyzer`. While this allows partial substring matches from any position within a word (e.g., searching `"123"` matches `"91239"` or `"812300"`), it leads to **excessive search noise (false positives)** and **severe indexing overhead**. 

Every keyword produces a high number of analyzed n-gram tokens, ballooning the Elasticsearch index footprint and increasing JVM heap memory utilization. For a Swiss shop operating in multilingual contexts like Swiss German (`gsw-CH`/`de-CH`) and Swiss French (`fr-CH`), it is crucial that:
1. Search noise is minimized (e.g., searching French or German words should not retrieve unrelated products matching random substring syllables).
2. French synonyms (e.g., mapped via `topdata_synonym_filter`) are properly supported on French analyzers (`sw_french_analyzer`) in the Elasticsearch index configuration.
3. Standard synonym mappings and leading-zero SKU matching remain stable and performant.

## 2. Executive Summary
This plan modifies the index creation process of Shopware 6.7 by dynamically swapping the `type` of the default `sw_ngram_tokenizer` from `ngram` to `edge_ngram` in the index configuration [1.2.1]. This is handled dynamically via `ElasticsearchIndexConfigSubscriber`, ensuring it executes on every index rebuild.

To support the Swiss context (`gsw-CH`/`fr-CH`):
- **Multilingual Synonym Support:** The `ElasticsearchIndexConfigSubscriber` is updated to inject the custom `topdata_synonym_filter` into the French analyzer (`sw_french_analyzer`) alongside the existing German, English, and whitespace analyzers [1.2.1].
- **Clean Substring Coverage:** The change preserves custom `min_gram` and `max_gram` settings, allowing the index to remain highly performant while shifting entirely to prefix-only token matching (which matches the natural typing direction of customers in both German and French).

---

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin (`topdata-elasticsearch-hacks-sw6`)
- **Backend root:** `src`
- **PHP Version:** `8.2 / 8.3 / 8.4`

---

## 4. Detailed Implementation Phases

### Phase 1: Core Index Subscriber Refactoring
We will refactor `src/Subscriber/ElasticsearchIndexConfigSubscriber.php` to intercept the Elasticsearch index parameters and alter `sw_ngram_tokenizer`'s configuration.

The `onIndexConfig` method is restructured to ensure the tokenizer swap is executed **before** the early return statement that checks for synonym rules [1.2.1]. Additionally, we will add support for the Swiss French analyzer (`sw_french_analyzer`) in the list of search analyzers that receive custom synonym filter injections [1.2.1].

#### `[MODIFY] src/Subscriber/ElasticsearchIndexConfigSubscriber.php`
```php
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
        $config = $event->getConfig();

        // 1. Swap default 'ngram' tokenizer to 'edge_ngram' for improved performance and lower noise.
        // This targets the default tokenizer used by Shopware's 'sw_ngram_analyzer'.
        if (isset($config['settings']['analysis']['tokenizer']['sw_ngram_tokenizer'])) {
            $config['settings']['analysis']['tokenizer']['sw_ngram_tokenizer']['type'] = 'edge_ngram';
        } else {
            // Defensive backup: define the edge_ngram settings if they are missing or not initialized yet
            $config['settings']['analysis']['tokenizer']['sw_ngram_tokenizer'] = [
                'type' => 'edge_ngram',
                'min_gram' => 4,
                'max_gram' => 5,
                'token_chars' => ['letter', 'digit'],
            ];
        }

        // 2. Load synonym rules and build synonym filter
        $synonymRules = $this->synonymService->exportToArray('product');

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

        // We explicitly target sw_french_analyzer to ensure full synonym coverage for Swiss French (fr-CH)
        $swSearchAnalyzers = [
            'sw_whitespace_analyzer',
            'sw_german_analyzer',
            'sw_english_analyzer',
            'sw_french_analyzer'
        ];

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
```

---

### Phase 2: Index Verification & Swiss Reindexing Process
To apply the modifications, the store administrators need to clear the cache and rebuild the Elasticsearch search indices.

#### Step 1: Run Reindexing
```bash
# Clear container cache
php bin/console cache:clear

# Reset and rebuild search index
php bin/console es:reset
php bin/console es:index --no-queue
php bin/console es:create:alias
```

#### Step 2: Query Index Analysis Settings (Verification)
Verify that the `edge_ngram` configuration is active and has been properly written to the product indices by querying the Elasticsearch or OpenSearch API:
```bash
curl -X GET "http://localhost:9200/sw_product*/_settings?pretty"
```
Under the `tokenizer.sw_ngram_tokenizer` settings block, verify that `"type"` now equals `"edge_ngram"`.

---

### Phase 3: Project Housekeeping & Documentation Updates
No new file types are introduced by this plan, so no changes are needed for `.gitignore`. However, we will modify `README.md` to document the change of tokenization strategy and provide instructions on how to regenerate indices [1.2.1].

#### `[MODIFY] README.md`
```markdown
...
## Features
* Globally registers a `word_delimiter_graph` token filter in Elasticsearch settings.
* Overrides default language analyzers (`sw_german_analyzer`, `sw_english_analyzer`, `sw_default_analyzer`) to split terms dynamically without breaking default stemmers.
* **Optimized Tokenization Strategy (Edge N-Gram):** Automatically converts Shopware's default `sw_ngram_tokenizer` from standard `ngram` (mid-word matching) to `edge_ngram` (prefix-only matching) [1.2.1]. This reduces false-positive noise and optimizes Elasticsearch server RAM and index disk utilization.
* **French Language Support:** Custom synonym mappings are fully registered in the `sw_french_analyzer` [1.2.1], providing native synonym search matching for Swiss French (`fr-CH`) product queries.
* **Multi-Field Mapping Architecture (Option B):** Isolates word-delimiter splitting to a dedicated `.delimiter` sub-field to protect clean search field precision.
...
```

---

## 5. Implementation Report

```yaml
---
filename: "_ai/backlog/reports/260720_1811__IMPLEMENTATION_REPORT__change_tokenizer_to_edge_ngram.md"
title: "Report: Change Search Tokenizer from N-Gram to Edge N-Gram"
createdAt: 2026-07-20 18:11
updatedAt: 2026-07-20 18:11
planFile: "_ai/backlog/active/260720_1811__IMPLEMENTATION_PLAN__change_tokenizer_to_edge_ngram.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
completedAt: 2026-07-21 09:52
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [elasticsearch, search, performance, swiss-multilingual, tokenization]
documentType: IMPLEMENTATION_REPORT
---
```

### 1. Summary
We successfully refactored the index settings configuration to change Shopware's core `sw_ngram_tokenizer` from an `ngram` type to an `edge_ngram` type. We also extended custom synonym registration to cover Swiss French search queries natively using the `sw_french_analyzer`.

### 2. Files Changed
- **`src/Subscriber/ElasticsearchIndexConfigSubscriber.php`** [MODIFY]: Swapped standard n-gram tokenization to prefix-only `edge_ngram` tokenization, and registered `topdata_synonym_filter` inside the French analyzer list (`sw_french_analyzer`).
- **`README.md`** [MODIFY]: Updated features list to document prefix tokenization and multi-lingual French synonym support.

### 3. Key Changes
*   **Flipped Tokenization to Prefix-Only:** Replaced standard `ngram` with `edge_ngram` on the default tokenizer `sw_ngram_tokenizer`. This prevents random mid-word syllable matches and drastically minimizes false positives.
*   **Swiss French Synonyms Integration:** Handled synonym expansion for French locales by ensuring the synonym token filter is injected into the `sw_french_analyzer` right after the lowercase step.
*   **Decoupled Early Return:** Ensured that the tokenizer type conversion logic is executed on every index configuration build, independent of whether synonym rules are empty.

### 4. Deviations from Plan
*   None. The code and documentation files have been modified exactly in accordance with the established Swiss multilingual requirements.

### 5. Technical Decisions
*   **Zero Mapping Modifications:** Instead of overriding individual product properties mapping, dynamically replacing the tokenizer `type` inside index settings modifies the behavior globally on all mapped `sw_ngram_analyzer` sub-fields (e.g. `name.ngram` or `productNumber.ngram`). This is cleaner, safer, and avoids decorator bloat.

### 6. Testing Notes
1. Run `php bin/console cache:clear` and reindex the search fields: `php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias`.
2. Check settings on the active index: `curl -X GET "http://localhost:9200/sw_product*/_settings"`. Verify that `sw_ngram_tokenizer` uses `"type": "edge_ngram"`.
3. Perform a search for `"WC Papier"` in the storefront using German and French, verifying that synonym and correct prefix matches rank at the top.
