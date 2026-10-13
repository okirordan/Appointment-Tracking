# Correspondence performance investigation — 13 October 2026

## Environment and scope

The read-only query measurements below used the local MySQL 8.4.3 `ats` database with 96,302 mail records and 96,301 correspondences. They are single samples on a warm local server, not route latency or production benchmarks. Automated behavior and query-count tests use SQLite in memory. No production data was modified while profiling.

## Findings and changes

| Finding | Change |
| --- | --- |
| The PS mailbox count scanned about 45,700 incoming records and executed correlated user-role lookups for almost every row. | `MailboxScope::psRecordedOrImported()` now uses materializable, soft-delete-aware user-ID subqueries. The mail count and visibility rules stay the same. |
| Home and mail register cards repeated the same permission-heavy mailbox scan for simple status buckets. | Conditional `COUNT(CASE WHEN...)` aggregates share each mailbox scope scan. Counts involving task/correspondence predicates remain separate. |
| Numbered register pagination reran its exact permission-scoped count on every page change. | The register now uses cursor pagination with Previous/Next controls. The exact count query and page-number navigation are removed. |
| MySQL `DATE(received_date)` and similar predicates prevented use of existing date indexes. | MySQL compares the `DATE` columns directly in search and register date filters. SQLite retains date extraction because its Eloquent test storage includes a time component. Timestamp filters are unchanged. |
| The mail detail presenter queried recipients twice after loading the whole recipient relation. | Both recipient projections now reuse the loaded collection and eager load any missing display relations. |
| The admin dashboard issued one officer count query per displayed department. | The department query now uses `withCount` and keeps numbered pagination. |
| Cached search results survived access-grant revocation; summary and financial-year cache keys omitted source-data changes. | Relevant model events rotate the search version. Search, summary, and filter cache keys now use it. A second rotation after transaction commit protects separate cache backends. The version-key namespace changed to avoid reusing pre-deployment results. |
| A typed mail search built unrelated task, staff, and workstream scopes. | `SearchService` builds those scopes only for requested categories. The mail match, exact total, and permission filter are unchanged. |
| The basic incoming register blocked on a permission-filtered `DISTINCT recipient_name` dropdown query; outgoing did not run it. | Incoming recipient options and labels now load only when the user opens the filter and share a 120-second, user-and-scope-keyed cache. The financial-year prop has no current UI consumer and is optional on authorized register pages. |
| The basic home page built a register list it did not show and automatically fetched overview, recent-mail, and summary data. Basic record details also built an unused surrounding register list. | The home page retains the hero, search, and quick links, while removing the overview and recent-mail blocks and their requests. Basic home and detail responses return an empty register placeholder without querying the list. The full register and full-mode summary behavior remain intact. |

## Measurements

| Read-only measurement | Before | After | Notes |
| --- | ---: | ---: | --- |
| PS incoming mailbox `COUNT(*)` | 2,082–2,800 ms | 1,459–1,643 ms | Same result: 44,151. `EXPLAIN ANALYZE` changed the role lookup from dependent per-row subqueries to one-time materialized subqueries. |
| PS outgoing mailbox `COUNT(*)` | 3,016 ms | 2,148 ms | Same result: 50,577. |
| Seven simple status counts on PS incoming/outgoing scopes | 9,160 ms, 7 queries | 6,305 ms, 2 queries | Same bucket values. This comparison predates the mailbox-scope change and isolates aggregation. |
| Three-column date search, 10 July 2025 | 789 ms | 3.8 ms | Same 238 matches. Plan changed from scanning about 47,544 rows via `mail_financial_year_options_index` to `index_merge` across the existing letter, received, and sent date indexes, about 253 estimated rows. |
| Register mail aggregate queries in SQLite feature test | 14 | 8 | Includes surrounding page work. |
| Exact register pagination count on page 2 in SQLite feature test | 1 | 0 | Cursor pagination does not request an exact list total. |
| Home mail aggregate queries in SQLite feature test | 6 | 4 | Includes surrounding page work. |
| Detail recipient queries in SQLite feature test | 3 | 1 | Partial Inertia request for `selectedMail`. |
| Admin dashboard standalone user count queries for three departments | 5 | 2 | Two global user statistics remain. |
| Basic incoming controller render on local MySQL, same PS user | 758–1,236 ms | 90–91 ms | Controlled warm-process renders, excluding middleware and network. The former recipient DISTINCT query alone took 580–1,060 ms. |
| On-demand recipient options | Included in initial response | 831 ms cold; 15 ms cached | The directory loads when the user selects the recipient filter. It does not start a background request after the page opens. Cache keys include user, authorization scope, and data version. |

The common-term global mail search count was 1,629 ms before these changes; global search still uses numbered pagination and substring matching. A `COUNT(*) OVER()` trial took 7,295 ms versus 2,439 ms for the list query alone, so it was not adopted. No route response-time, memory, or production p95 improvement is claimed. The requested sub-two-second route targets need representative end-to-end measurements after deployment.

## Index decision

The initial query changes needed no migration. Cursor pagination of incoming and filed mail needs a non-null date sort key. A plain `COALESCE` expression made the scoped list query much slower, so migration `2026_10_13_000001_add_mail_cursor_sort_index.php` adds an indexed generated date column on MySQL. In the local 96,302-record database, the scoped first-page query took 37 ms using the index versus 1,293 ms with the expression in one warm comparison. `EXPLAIN` selected `mail_cursor_received_index` for the ordered query with a backward index scan. SQLite tests use the equivalent expression without a migration column.

Outgoing mail includes forwarded incoming records, so an index beginning with direction cannot cover its mixed `updated_at` order. Migration `2026_10_13_000002_add_mail_updated_cursor_index.php` adds `(deleted_at, updated_at, id)`. The scoped outgoing first-page query fell from 1,350–1,440 ms to 3.6–6.8 ms in local warm runs, and `EXPLAIN` now selects `mail_cursor_updated_index` with a backward index scan instead of filesort.

## Correctness and security

Mailbox and correspondence authorization scopes remain in every list, summary, search, and detail query. The PS role subqueries retain the user's soft-delete scope. Cursor navigation retains the stable date and ID order, including undated mail, and tests cover forward/backward navigation. Global search still uses numbered pages. Tests cover PS mailbox visibility, department boundaries, grant revocation, cache refresh after a correspondence status change, date filtering, detail rendering, dashboard counts, and login behavior through the full suite.

## Deployment and follow-up

Run both cursor-sort migrations before serving the new controller code. The first targeted migration completed in 53 seconds and the second in under one second on the local 96,302-record MySQL table; schedule the schema changes according to the deployment database's lock and capacity limits. Restart PHP workers or reload OPcache so every worker uses the new code; restart long-running queue workers if this deployment uses them. The deferred register summary loads in a follow-up Inertia request, while the list appears in the initial response. Mail feature display settings use a 30-second node-local Laravel file cache with a version stored in the default cache and invalidated by `MailFeatureSettings::set()`. Use a shared default cache store (Redis/database failover as in `.env.example`) in multi-node deployments so the version and permission-scoped caches are consistent across nodes.

Validation after the home and navigation change: 452 PHP tests (6,494 assertions), 90 frontend tests, TypeScript type checking, the production Vite build, Pint on the changed PHP files, and `git diff --check` passed. Whole-repository Pint still reports unrelated existing formatting issues. The local MySQL migration status shows both new migrations applied. These checks do not establish the production route latency targets; the timings above are local SQL and controller-render measurements.

After deployment, use Laravel Pulse with representative roles and data volume to record route response time, query count, cumulative SQL time, slowest SQL, and memory for `/home`, `/mail/{mail}`, both registers, login, settings, and admin dashboard. Re-run `EXPLAIN ANALYZE` for the remaining complex search count and mailbox count on the production database before considering further indexes or search-query decomposition. The expensive full-text-plus-substring global search and its permission-filtered exact count remain the main unresolved database costs; login and settings did not show a code-level bottleneck that could be safely changed without route-level profiling.
