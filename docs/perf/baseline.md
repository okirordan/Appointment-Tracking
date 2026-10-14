# Mail performance baseline — 14 October 2026

The local MySQL 8.4.3 `ats` database contained 96,302 mail records. These are single, cold-cache, controlled HTTP-kernel requests for an active Permanent Secretary account. The profiler uses an in-memory cache and rolls back the database transaction after each GET; it includes middleware, Inertia serialization, and SQL, but no browser or network time. Route-level Pulse samples supplied with the task are production evidence from another workload and are not directly comparable: `/home` slowest query 23,640 ms, `/incoming-mail` 2,915 ms, and `/login` 1,479 ms.

| Route | HTTP ms | Queries | Cumulative SQL ms | Main cost |
| --- | ---: | ---: | ---: | --- |
| `/home?mode=basic` | 175.8 | 38 | 28.7 | Session, shared props, settings; no mail list query |
| `/home?mode=full` | 2,160.1 | 21 | 2,029.6 | Incoming/outgoing mailbox counts, 954 and 1,046 ms |
| `/incoming-mail?mode=basic` | 275.7 | 51 | 57.9 | Rendering and shared props; first-page indexed list query under 3 ms |
| `/outgoing-mail?mode=basic` | 242.2 | 53 | 41.3 | Rendering and shared props |
| `/home?mode=full&q=education&type=mail` | 3,427.4 | 157 | 3,138.1 | Outgoing count 1,132 ms; mail list 832 ms; incoming count 549 ms; mail exact count 524 ms |

Raw route profiles are in `*-before.json`; `mysql-before.json` contains `SHOW INDEX` for the four principal tables, `innodb_ft_min_token_size=3`, and EXPLAIN for mail search and both mailbox lists. The search EXPLAIN chose the `mail_financial_year_options_index` B-tree on `mail_records`, then scanned task and history tables; it did not choose `mail_records_search_fulltext`. Both unfiltered mailbox first-page plans chose their existing cursor indexes (`mail_cursor_received_index` and `mail_cursor_updated_index`) with backward index scans. Those plans do not prove filtered or non-PS visibility scopes are cheap.

## Why work repeats

`SearchService::performSearch()` fetched mail rows and, when the page was full, cloned the entire keyword/permission query for an exact `COUNT(*)`. Its general `all` category repeats this pattern for each populated result type. A full-page navigation with search also resolved `HomeController::$mailStats`, running three further permission-heavy mailbox aggregates even though the search page does not render those stats. The mail search called `MailRecordPresenter::row()` separately for every hit; that called provenance and access methods with lazy loads, producing 40 identical-shaped mail-existence checks and one correspondence-update query per hit. The 157-query route profile records this N+1 pattern. The list presenter already has `rows()`, which batches those loads and permission checks.

The register itself already uses cursor pagination and defers/omits stats in Basic mode. Therefore its first page does not repeat an exact filtered list count. Existing indexes already cover prefix lookups on `register_number` (unique), `dispatch_reference`, `correspondence_reference`, and `registry_file_number`; another copy of those indexes would increase write cost without helping these queries.

The optional local query logger is gated by `PERF_LOG=true` in `config/ats.php`. It records SQL over 200 ms and the total query count per request. It is off by default. The profiler is repeatable with `php scripts/perf_profile.php '/home?mode=full'` and `php scripts/perf_explain.php education`.
