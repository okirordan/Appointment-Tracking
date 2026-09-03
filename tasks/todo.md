# Division and Independent-Office Correspondence Boundaries

- [x] Add organizational schema and four independent ministerial offices.
- [x] Add canonical organizational scope resolution.
- [x] Enforce division/office mail visibility at query and policy level.
- [x] Enforce division/office assignment and dashboard visibility.
- [x] Add viewer-relative incoming/outgoing routing and receipt history.
- [x] Add opt-in historical secretary ownership migration.
- [x] Add the administrator UI control and movement/history presentation.
- [x] Verify focused tests, frontend suite, production build, formatting, and security review. Full PHP coverage is green except host-level `ZipArchive` cases.

## Authoritative Organization Structure redesign

- [x] Expand and safely backfill the organizational entity schema.
- [x] Add audited create/edit/move/activation workflows with cycle and duplicate validation.
- [x] Seed the approved Ministry structure without position/reporting routes.
- [x] Build one searchable expandable Organization Structure administration tree.
- [x] Remove position/reporting controls and redirect legacy management URLs.
- [x] Verify exact entity ownership across secretary, correspondence, and assignment scopes.
- [x] Complete backend, frontend, migration, build, security, and code-quality verification.
- [ ] Complete authenticated live-browser visual inspection after an administrator signs in.

## Organization Structure UI polish

- [x] Add regression coverage for hierarchy depth, connectors, branch affordances, type badges, filtered counts, and action grouping.
- [x] Polish recursive tree depth, connector lines, toggle targets, icons, and shared entity-type badges.
- [x] Standardize inspector values, code copying, action grouping, summary dividers, and filtered result copy.
- [x] Run focused UI, type, lint, build, diff, and available browser verification.

## Exact staff organizational placement

- [x] Add backend abuse/regression tests for exact placement and secretary scope synchronization.
- [x] Enforce canonical entity placement and server-derived legacy department/division projections.
- [x] Replace competing placement fields with one searchable hierarchy-aware staff selector.
- [x] Add an explicit Division filter to staff placement.
- [x] Show exact placement consistently in staff list and profile summary.
- [x] Complete backend, frontend, build, and authorization review checks.
