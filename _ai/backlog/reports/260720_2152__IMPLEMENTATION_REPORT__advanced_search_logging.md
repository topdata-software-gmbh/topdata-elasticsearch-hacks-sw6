---
filename: "_ai/backlog/reports/260720_2152__IMPLEMENTATION_REPORT__advanced_search_logging.md"
title: "Report: Advanced Search Logging and Fragment Consolidation"
createdAt: 2026-07-20 21:52
updatedAt: 2026-07-20 21:52
planFile: "_ai/backlog/active/260720_2152__IMPLEMENTATION_PLAN__advanced_search_logging.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 12
filesModified: 7
filesDeleted: 0
tags: [logging, search, processing, analytics]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The search tracking system has been successfully upgraded from basic zero-result logging to an advanced transactional logging and background consolidation engine. The plugin now records every user input securely with a context session token and uses a background heuristic to filter out typing fragments.

## 2. Files Changed
### Created
- `src/Migration/Migration1752700000CreateSearchLogAndStatsTable.php`
- `src/Service/SearchAnalyticsService.php`
- `src/Command/Command_ConsolidateSearchLogs.php`
- `src/Framework/ScheduledTask/ConsolidateSearchLogsTask.php`
- `src/Framework/ScheduledTask/ConsolidateSearchLogsTaskHandler.php`
- `src/Entity/SearchStats/SearchStatsEntity.php`
- `src/Entity/SearchStats/SearchStatsCollection.php`
- `src/Entity/SearchStats/SearchStatsEntityDefinition.php`
- `src/Controller/SearchStatsController.php`
- `src/Resources/app/administration/src/module/topdata-es-search-stats/index.ts`
- `src/Resources/app/administration/src/module/topdata-es-search-stats/page/search-stats-list/index.ts`
- `src/Resources/app/administration/src/module/topdata-es-search-stats/page/search-stats-list/search-stats-list.html.twig`

### Modified
- `src/Subscriber/ProductSearchSubscriber.php`
- `src/Resources/config/services.xml`
- `src/TopdataElasticsearchHacksSW6.php`
- `src/Resources/app/administration/src/main.ts`
- `src/Resources/app/administration/src/snippet/de-DE.json`
- `src/Resources/app/administration/src/snippet/en-GB.json`
- `README.md`

### Deleted
None. Old ZeroSearch entity files, controller, and admin module are preserved for backward compatibility.

## 3. Key Changes
- **Two-Table Architecture:** Raw search log (`tdeh_search_log`) for fast writes + aggregated stats (`tdeh_search_stats`) for analytics.
- **Live Logging:** ProductSearchSubscriber now listens to both `PRODUCT_SEARCH_RESULT` and `PRODUCT_SUGGEST_RESULT` events, logging all queries (including successful ones) with session context and result counts.
- **Fragment Filtering:** Ignores single-character queries at the subscriber level to reduce noise.
- **Background Consolidation:** Scheduled task (hourly) + CLI command processes raw logs: groups by session, uses Levenshtein distance and prefix checks to detect typing streams, resolves to final intent, upserts stats, deletes processed raw logs.
- **Admin Dashboard:** New "Search Statistics" module under Topdata ES nav showing total searches, zero counts, avg results, CSV export, and reset functionality.
- **Plugin Cleanup:** Uninstall handler now drops `tdeh_search_log` and `tdeh_search_stats` tables alongside existing ones.

## 4. Technical Decisions
- **Two-Table Separation:** Offloading consolidation to an asynchronous scheduled task keeps hot storefront requests fast and reliable.
- **Wiping Log Garbage:** Deletes processed raw logs to prevent database storage issues.
- **Old Zero-Search Table Preserved:** The existing `tdeh_zero_search` table, entity definitions, and admin module remain functional for backward compatibility.
- **Session-Based Stream Detection:** Uses Shopware's `SalesChannelContext::getToken()` to group queries into typing streams.
