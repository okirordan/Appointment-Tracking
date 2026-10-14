# Mail performance implementation report

14 October 2026. All measurements below used the local MySQL 8.4.3 `ats` database with 96,302 mail records, an active PS account, one cold in-memory application cache per request, and a rolled-back HTTP-kernel transaction. They include middleware, Inertia serialization, and SQL, but exclude browser and network time. Pulse timings in the task came from a different workload and are not directly comparable. The indexed-search switch is **off by default** because the parity checks below found missed matches.

## Results

| GET route | Before ms / SQL queries | After ms / SQL queries | Target | Result |
| --- | ---: | ---: | ---: | --- |
| `/home?mode=full` shell | 2,160.1 / 21 | 165.0 / 16 | <150 ms | Near, but not met in this sample; stats load separately |
| `/home?mode=basic` | 175.8 / 38 | 257.9 / 35 | <150 ms shell | Not met; still pays for Basic directory and shared props |
| `/incoming-mail?mode=basic` | 275.7 / 51 | 483.6 / 49 | <200 ms | Not met with the new 25-row default; provenance rendering dominates |
| `/outgoing-mail?mode=basic` | 242.2 / 53 | 343.4 / 51 | <200 ms | Not met with the new 25-row default; provenance rendering dominates |
| `/home?mode=full&q=education&type=mail` | 3,427.4 / 157 | 678.8 / 19 (indexed trial) | <100 ms | Improved, but not met; this feature flag stays off |

These are individual samples, not load-test percentiles. Three additional cold application-cache runs ranged 143–163 ms for full home, 241–337 ms for Basic home, 398–629 ms for incoming, and 489–632 ms for outgoing after the `id DESC` change. Repeated indexed search ran at 664–897 ms after one 1,879 ms cold MySQL outlier. The search trial is not a production-default after result. The route profiles are recorded in `*-before.json`, `*-after.json`, and `search-mail-indexed-after.json`; the indexed trial needs `ATS_INDEXED_MAIL_SEARCH=true` and a completed backfill. With the default off, the legacy search retains its behavior and remains slow for broad terms. Full-home search still performs a costly exact mail count in the `all` category. The Basic search still uses the legacy matcher, including leading-wildcard terms and permission-aware correspondence-update text. Replacing it with a single mail document would risk exposing matches from updates that the viewer cannot read.

| Expensive operation | Before | After | Meaning |
| --- | ---: | ---: | --- |
| Full-home incoming/outgoing counts | 954 / 1,046 ms | Deferred, database-cached for 60 s | Removed from the shell request; cache miss still costs SQL |
| `education` old/new scoped ID scan | 3,932.4 / 1,584.7 ms | Indexed trial only | Broad result set still expensive |
| `Kabale` old/new scoped ID scan | 1,909.7 / 289.7 ms | Indexed trial only | Trial speeds up the scan |
| `reconstruction` old/new scoped ID scan | 1,682.9 / 282.4 ms | Indexed trial only | Same visible IDs in parity sample |
| Indexed `education` first page query | — | 452.4 ms | MySQL uses FULLTEXT but filesorts matches by date |

The before/after search ID scans above were taken while the short-reference trial document was fully populated. They are useful for query-shape comparison, not a production benchmark.

## What changed

- The register already used cursor pagination and avoided an exact list count. The default Basic page size is now 25; Next/Previous remains. Outgoing now uses deterministic `id DESC` ordering, which cut its first-page SQL from roughly 460–510 ms to 8–15 ms in repeat local samples. Primary navigation uses short Inertia hover prefetch. Search typing is debounced, uses partial Inertia reloads, and retains the previous result display during requests.
- Search results use a batched presenter, removing the per-row provenance queries. The full home shell defers the three permission-scoped summary aggregates and caches them in the database for 60 seconds, keyed by user, scope, and search-cache version. It skips recent search/task props until requested. Basic mode no longer repeats preference writes on each visit.
- A reversible migration adds nullable `mail_records.search_text` and `ft_mail_search`. A chunked backfill command and queued update hooks populate names, references, offices, assignee fields, and **every historical task note**, as requested. The search flag is disabled by default. Separate `ATS_MAINTAIN_MAIL_SEARCH_DOCUMENTS` controls queued maintenance after migration and before any eventual search cutover.
- The existing permission scope remains shared across home, register, and search. Four missing composite indexes were added for unit and recipient/forward lookups. The four requested reference-prefix indexes and cursor indexes already existed; adding duplicates would raise write cost. The existing wide FULLTEXT index is retained because Basic search still needs it.
- `PERF_LOG=true` enables a 200 ms query logger with path, SQL preview, elapsed ms, and request query count; it is off by default.

## Query plans and parity

`mysql-before.json` and `mysql-after.json` contain the full `SHOW INDEX` and `EXPLAIN` output. Before, the old `education` mail search chose `mail_financial_year_options_index`, then scanned related task/history paths; it did **not** use the wide FULLTEXT index. After, the trial query's mail step reports `type=fulltext`, `key=ft_mail_search`, and `Extra=Using where; Ft_hints: no_ranking; Using filesort`. The incoming first-page plan uses its date cursor index; outgoing uses a backward scan of `PRIMARY` after the order change. The new four composite indexes are present in `mysql-after.json`.

`search-parity-after.json` records a scoped PS comparison. With every row indexed, `education` returned 9,440 of 9,447 legacy results and `Kabale` 556 of 558; `reconstruction` matched 55 of 55. The missing records contain interior substring matches that MySQL's word-based FULLTEXT does not produce. A compact-token experiment for `PS/ES` was worse (38,837 vs 48,264 old matches and a 78 s full ID scan), so the trial matcher now sends references composed only of short tokens back to the legacy matcher. The compact token was removed from the final indexer; an interrupted local cleanup pass left a mix of old and new document formats, but all rows remain populated and the feature flag is off. A fresh deployment backfill uses only the final format. No user-visible permission change was made: the outer `MailAccessScope` still applies to both searches. These mismatches and the Basic correspondence-update semantics are why `ATS_INDEXED_MAIL_SEARCH` must remain false until a parity-preserving search design is tested for every role.

## Deployment sequence

`main` auto-deploys to production. These local changes should be reviewed and merged only through the normal deployment process. Back up the production database using the existing operational procedure before DDL. Building FULLTEXT and composite indexes may lock the affected MySQL tables and requires a maintenance window appropriate for the production workload. The commands below are for the **production application directory** after code is deployed; they have not been run on production.

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# Set ATS_MAINTAIN_MAIL_SEARCH_DOCUMENTS=true, keep ATS_INDEXED_MAIL_SEARCH=false.
php artisan config:cache
# Ensure the existing database queue worker is running with the refreshed config.
php artisan mail:reindex-search --chunk=500
php artisan route:cache
php artisan view:cache
php artisan event:cache
npm ci
npm run build
```

Use the project's established deployment order for `npm ci`/Vite build and restart the PHP/queue processes so OPcache and worker config refresh. Keep `ATS_INDEXED_MAIL_SEARCH=false` for this release. Confirm `search_text` has no null rows before any future trial, then run representative role and term comparisons and measure p50/p95 SQL and HTTP latency. The file cache is not selected for summary stats; `ATS_STATS_CACHE_STORE=database` is the default. A shared cache is needed if the application runs across multiple nodes. The local `.env` uses `BCRYPT_ROUNDS=12`, `QUEUE_CONNECTION=database`, and `CACHE_STORE=database`; the production values were not inspected. Enable PHP OPcache in production and set timestamp validation to fit the deployment method.

## Rollback

1. Keep or set `ATS_INDEXED_MAIL_SEARCH=false` and `ATS_MAINTAIN_MAIL_SEARCH_DOCUMENTS=false`; rebuild the config cache and restart the queue workers. The legacy search and the existing wide FULLTEXT index remain available.
2. Revert the application code through the normal deployment process. If removing the added schema, roll back the two `2026_10_14_*` migrations in reverse order during a maintenance window. Dropping the FULLTEXT index and `search_text` discards the backfill and may lock `mail_records`. Do not roll back unrelated migrations.
3. Restore the prior frontend build through the normal artifact process if the code is reverted.

## Remaining work

- A parity-preserving index for substring and permission-aware update search is needed before enabling indexed results. Consider a dedicated ngram/token table or search engine with per-update access filtering, then compare old/new visible IDs for PS, secretaries, commissioners, clerks, and officers. Retire the old wide FULLTEXT index only after that cutover.
- Basic register latency is now above baseline because 25 rows invoke full provenance and correspondence timelines. A list-specific projection that preserves current `basic_route_display` needs characterization tests and a separate query/profile pass. A visibility scope rewrite was intentionally deferred because the outgoing `id DESC` and incoming date cursor first-page list SQL are under 25 ms locally and the permissions are sensitive. If a non-PS scoped EXPLAIN remains above 100 ms after index deployment, evaluate a precomputed visibility table with explicit approval.
- Login was not profiled end to end in the local HTTP profiler; the Pulse 1,479 ms sample cannot be compared to the GET measurements. Measure POST `/login` plus redirect with a test account before changing audit or password work. Keep bcrypt rounds at least 10. Consider Redis cache/session/queue, 50–70% of spare RAM for MySQL buffer pool, `innodb_ft_min_token_size=2` only with an index rebuild, and Octane only after query semantics and latency are stable. These are recommendations, not applied settings.

Verification: full PHP feature/unit suite (457 passed, 6,593 assertions), a focused 28-test mail run after the outgoing order change, Pint, TypeScript, ESLint, Prettier, Vite production build, and 90 frontend tests. No real-browser timing or production login measurement was performed.
