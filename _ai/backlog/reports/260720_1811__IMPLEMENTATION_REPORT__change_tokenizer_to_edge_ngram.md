---
filename: "_ai/backlog/reports/260720_1811__IMPLEMENTATION_REPORT__change_tokenizer_to_edge_ngram.md"
title: "Report: Change Search Tokenizer from N-Gram to Edge N-Gram"
createdAt: 2026-07-20 18:11
updatedAt: 2026-07-21 00:00
planFile: "_ai/backlog/active/260720_1811__IMPLEMENTATION_PLAN__change_tokenizer_to_edge_ngram.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [elasticsearch, search, performance, swiss-multilingual, tokenization]
documentType: IMPLEMENTATION_REPORT
---

### 1. Summary

Successfully refactored the index settings configuration to change Shopware's core `sw_ngram_tokenizer` from an `ngram` type to an `edge_ngram` type. Extended custom synonym registration to cover Swiss French search queries natively using the `sw_french_analyzer`.

### 2. Files Changed

- **`src/Subscriber/ElasticsearchIndexConfigSubscriber.php`** [MODIFY]: Swapped standard n-gram tokenization to prefix-only `edge_ngram` tokenization (executed before the synonym early-return), and registered `topdata_synonym_filter` inside the French analyzer list (`sw_french_analyzer`).
- **`README.md`** [MODIFY]: Updated features list to document edge n-gram tokenization and multilingual French synonym support.

### 3. Key Changes

- **Flipped Tokenization to Prefix-Only:** Replaced standard `ngram` with `edge_ngram` on the default tokenizer `sw_ngram_tokenizer`. This prevents random mid-word syllable matches and drastically minimizes false positives.
- **Swiss French Synonyms Integration:** Handled synonym expansion for French locales by ensuring the synonym token filter is injected into the `sw_french_analyzer` right after the lowercase step.
- **Decoupled Early Return:** Ensured that the tokenizer type conversion logic is executed on every index configuration build, independent of whether synonym rules are empty.

### 4. Deviations from Plan

None. The code and documentation files have been modified exactly in accordance with the plan.

### 5. Technical Decisions

- **Zero Mapping Modifications:** Instead of overriding individual product properties mapping, dynamically replacing the tokenizer `type` inside index settings modifies the behavior globally on all mapped `sw_ngram_analyzer` sub-fields (e.g. `name.ngram` or `productNumber.ngram`). This is cleaner, safer, and avoids decorator bloat.

### 6. Testing Notes

1. Run `php bin/console cache:clear` and reindex: `php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias`.
2. Check settings on the active index: `curl -X GET "http://localhost:9200/sw_product*/_settings"`. Verify that `sw_ngram_tokenizer` uses `"type": "edge_ngram"`.
3. Perform a search for `"WC Papier"` in the storefront using German and French, verifying that synonym and correct prefix matches rank at the top.
