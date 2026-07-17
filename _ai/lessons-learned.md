# Lessons Learned

## [2026-07-17] - Fix Synonym List "Loads Forever" + Missing `updated_at` Column

### Context
Debugging the synonym list admin page in the Shopware 6.7 plugin `topdata-elasticsearch-hacks-sw6`. The page rendered the table skeleton but never populated data ("loads forever"). After fixing that, a SQL error appeared for a missing `updated_at` column.

### Challenge 1: Admin Component "Loads Forever"
The synonym list component used the `listing` mixin but never implemented `getList()`.

- **Root cause:** `isLoading` initialized to `true` and never set to `false`. The mixin's stub `getList()` is a no-op, so the skeleton overlay stayed forever.
- **Fix:** Added `items`, `sortBy`, `sortDirection`, `limit` to `data()`, implemented `getList()`, `onPageChange()`, `onSortColumn()`, and a `mounted()` hook. Template was missing `v-if="items"`, `:dataSource="items"`, and pagination/sort event handlers.
- **Reference pattern:** The working zero-search-list in the same plugin (`topdata-es-zero-search`) had the correct implementation — always copy from a working sibling module.

### Challenge 2: Admin Build Not Picking Up Plugin
Running `bin/build-administration.sh` didn't rebuild the plugin's JS.

- **Root cause:** The plugin wasn't listed in `var/plugins.json`. The `bin/console bundle:dump` command (run at the start of the build script) failed due to database unavailability and only dumped core bundles, omitting custom plugins.
- **Fix:** Manually added the plugin entry to `var/plugins.json`, then ran only the plugin build step (`VITE_MODE=production npx ts-node -T build/plugins.vite.ts`). The `basePath` must point to the directory containing `Resources/`, e.g., `custom/plugins/topdata-elasticsearch-hacks-sw6/src/` (not the plugin root).

### Challenge 3: Missing `updated_at` Column in Synonym Table
After the admin page loaded, it crashed with: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'topdata_es_synonym.updated_at'`.

- **Root cause:** Shopware 6.7's `EntityDefinition` base class (`EntityDefinition.php:447`) auto-adds `UpdatedAtField` with `ApiAware` flag to **all** entity definitions. The DAL generates SELECT queries including `updated_at` for every read. The migration `Migration1752590000` that adds this column hadn't been executed.
- **Fix:** Run `php bin/console database:migrate --all TopdataElasticsearchHacksSW6` — but from the Docker container, not the host CLI (host can't resolve `focus-mariadb` hostname).
- **Key insight:** Shopware 6.7 adds `UpdatedAtField` implicitly even if you don't define it in your entity definition.

### Key Takeaways

- **Entity definitions in SW 6.7:** The `EntityDefinition` base class auto-adds `UpdatedAtField` and `CreatedAtField` to all definitions. You must ensure your DB table has `updated_at DATETIME(3) NULL` column or the DAL queries will fail.
- **Admin listing components:** When using the `listing` mixin, you **must** implement `getList()` yourself — the mixin only provides a no-op stub. Copy the pattern from a working sibling component.
- **Admin builds:** If `bin/build-administration.sh` doesn't pick up your plugin, check `var/plugins.json`. Add the plugin entry manually if `bundle:dump` fails due to DB issues.
- **Docker commands:** For DB-dependent Shopware CLI commands (migrations, cache:clear, etc.), use `docker exec <container> php /www/bin/console ...` — the host CLI can't resolve container hostnames.
