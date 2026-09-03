# Implementation Plan: Division and Independent-Office Correspondence Boundaries

## Overview

Introduce organizational-unit ownership as the authoritative boundary for division secretaries and independent ministerial offices, retain explicit routed/assigned/copied access, project internal correspondence as outgoing for the sender and incoming for the recipient without cloning the correspondence, and provide a safe opt-in historical ownership migration when a secretary is attached or reassigned.

## Architecture Decisions

- Organizational-unit ownership is authoritative. A division unit grants only division scope; a department root grants department-wide custodianship; a standalone ministerial office grants only that office's scope.
- Department membership alone does not grant a division secretary access to the department register. Department-wide access remains available to the Permanent Secretary, named department heads, explicitly authorized department-root secretaries, and roles with the existing all-assignment capability.
- One correspondence remains the canonical record. Forwards and recipients model internal movements; recipient delivery metadata records automatic receipt without a second registration.
- Mailbox direction is viewer-relative: owned/sent movements are outgoing, while owned external receipts and explicitly routed deliveries are incoming.
- Historical reassignment only changes original ownership for records captured by the selected secretary. It does not rewrite recipients, forwarding history, or assignment targets.
- Existing ambiguous historical data is not silently guessed. Independent offices and schema fields are backfilled only where the relationship is deterministic; administrators use the explicit move option for secretary-owned history.

## Task List

### Phase 1: Organizational foundation

- [x] Add independent ministerial offices and delivery/ownership schema.
- [x] Add a canonical service that resolves a user's current unit and whether it is division-, department-, or independent-office scoped.
- [x] Add focused failing tests for division and independent-office isolation.

### Checkpoint: Foundation

- [x] New migrations run and roll back cleanly on the test database.
- [x] Existing department-root and Permanent Secretary oversight tests remain green.

### Phase 2: Authorization and routing

- [x] Restrict mail access to owned units plus explicit recipients, tasks, participants, and access grants.
- [x] Restrict secretary task/dashboard authority to the supported unit while preserving explicit cross-unit assignments.
- [x] Project internal routes as sender outgoing and recipient incoming, record automatic receipt metadata, and use the actual forwarding unit.
- [x] Apply the same canonical scopes to mail details, search, reports, statistics, notifications, and correspondence views.

### Checkpoint: Authorization and routing

- [x] Cross-division direct URLs return 403 unless the correspondence was explicitly routed.
- [x] Sender and recipient see one correspondence in the correct mailbox projection.
- [x] Department heads retain department-wide oversight; independent offices do not leak into one another.

### Phase 3: Historical placement migration

- [x] Add an opt-in `Move existing records` control to secretary attachment/reassignment.
- [x] Migrate original mail/correspondence/task ownership transactionally without modifying legitimate routing recipients or history.
- [x] Record migration counts and before/after placement in the audit log.

### Checkpoint: Complete

- [x] Focused backend and frontend tests pass.
- [x] Full PHP suite is green for all application changes; four unrelated document tests require the host PHP `ZipArchive` extension.
- [x] Build and formatting checks pass without overwriting unrelated user changes.
- [x] Security and code-quality review finds no unresolved authorization bypass.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Historical records have only department/OPS ownership | High | Do not guess; use deterministic backfills and the explicit administrator migration option. |
| Routing visibility is accidentally removed by ownership changes | High | Keep recipients, task links, participants, and access grants as independent visibility branches and test each. |
| Department-root secretaries lose intended oversight | Medium | Distinguish department-root units from division units and retain department-root behavior. |
| One global lifecycle status misclassifies both sender and recipient | High | Use viewer-relative mailbox queries based on ownership and movement recipients. |
| Existing dirty frontend work is overwritten | Medium | Make additive, minimal edits and verify diffs against the pre-existing working tree. |

## Open Questions

- None blocking. The four named ministerial offices are created as standalone `ministerial_office` organizational units with no department or division parent.

---

# Expansion Plan: Authoritative Organization Structure

## Overview

Replace the department/division/unit/position administration split with one authoritative organizational-entity tree. Preserve legacy foreign keys during an additive migration, backfill every deterministic relationship, seed the approved Ministry structure, and make exact entity ownership the boundary for correspondence, assignments, and secretary access.

## Architecture Decisions

- `organizational_units` remains the canonical entity table because correspondence, tasks, users, delegations, positions, and secretary attachments already reference it.
- Legacy `departments`, `divisions`, `positions`, and `user_positions` remain as compatibility data during the expand/migrate phase, but are removed from the Organization Structure UI and are no longer used to derive reporting routes.
- Entity placement is defined only by `parent_id`; legacy department/division columns are compatibility projections and must never constrain the tree.
- Entity types are a typed contract: office, functional area, department, division, section, unit, regional office, affiliated/external body, plus the non-selectable Ministry root.
- Moving an entity is a validated update. Self-parenting, descendant-parenting, duplicate sibling names, and accidental parentless entities are rejected before the transaction commits.
- The current entity head and secretary are explicit entity relationships. Assigning a secretary also updates their primary organizational entity so existing unit-scoped access services remain exact.

## Task List

### Phase 1: Entity contract and safe migration

#### Task 1: Expand the organizational entity schema

**Acceptance criteria:**
- [x] Entities store description, head, secretary, top-level intent, and supported typed values.
- [x] Existing rows and legacy ownership records are preserved and deterministically backfilled.
- [x] Migration has a tested rollback path.

**Verification:** focused migration/model feature tests and `php artisan migrate:fresh --seed` on the test database.

#### Task 2: Add validated entity create, edit, move, and activation workflows

**Acceptance criteria:**
- [x] One endpoint contract manages every selectable entity type.
- [x] Cycles, self-parenting, duplicate siblings, and unintentional orphans are rejected.
- [x] Parent, type, active-state, head, and secretary changes are audited with before/after values.

**Verification:** focused controller feature tests covering success and abuse cases.

### Checkpoint: Entity foundation

- [x] Focused entity tests pass and migration rolls both directions.
- [x] Existing correspondence and assignments retain their organizational owner.

### Phase 2: Approved Ministry structure and administration UI

#### Task 3: Seed the approved Ministry entity tree

**Acceptance criteria:**
- [x] The Ministry root, five independent offices, two functional areas, approved departments/lower entities, standalone entities, UNATCOM desk, and affiliated bodies exist with correct parents and types.
- [x] Unnamed regional offices are not invented.
- [x] Seeder is idempotent and does not create positions/reporting routes.

**Verification:** focused seeder tests assert representative paths, types, counts, and idempotency.

#### Task 4: Build the single Organization Structure administration page

**Acceptance criteria:**
- [x] The page presents one searchable expandable tree with type badges, breadcrumb context, and record totals.
- [x] A single minimal form supports add/edit/move with searchable parent selection and optional head/secretary assignment.
- [x] Keyboard, empty, validation, and responsive states meet WCAG-oriented UI tests.

**Verification:** Vitest component tests, TypeScript, ESLint, production build, and browser inspection at 320/768/1024/1440 widths.

#### Task 5: Remove competing hierarchy interfaces

**Acceptance criteria:**
- [x] Position/reporting-route controls are absent from Organization Structure.
- [x] Legacy department/division URLs redirect to the canonical page.
- [x] Navigation exposes only Organization Structure for this domain.

**Verification:** route/navigation feature tests and repository reference search.

### Checkpoint: Administration experience

- [x] Admin can add, edit, move, activate, and deactivate any supported entity from one page.
- [x] No second management UI remains reachable.

### Phase 3: Ownership and access integration

#### Task 6: Enforce exact entity ownership across secretary, mail, and assignments

**Acceptance criteria:**
- [x] A secretary's default scope is their assigned entity only.
- [x] Internal movements project sender outgoing and recipient incoming by entity without position-based routing.
- [x] Task ownership and assignment targets retain organizational entity references with legacy department compatibility only where needed.

**Verification:** focused cross-entity isolation and transfer tests, followed by the full PHP suite.

### Checkpoint: Complete

- [x] All redesign backend/frontend tests pass; the only full-suite failures are host-level `ZipArchive` cases unrelated to this change.
- [x] Production build, type checking, linting, migration rollback, security review, and code review pass.
- [ ] Authenticated live-browser visual inspection awaits an administrator sign-in.
- [x] No existing correspondence, assignments, or audit history is deleted.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Legacy records use department/division IDs without a unit | High | Additive nullable entity fields and deterministic batched backfill; retain legacy keys during the compatibility phase. |
| Moving a populated subtree creates inconsistent user snapshots | High | Validate and update descendants/users in one transaction while keeping canonical ownership IDs unchanged. |
| Direct secretary assignment broadens access | High | Scope by the exact assigned entity, never by all descendants or the legacy department unless explicit authority exists. |
| Approved names differ from legacy seed names | Medium | Reuse stable codes where available, update in place, and audit/verify representative parent paths. |
| Dirty working tree contains prior related work | Medium | Make focused additive edits, never reset user changes, and do not create commits without explicit authorization. |

## Open Questions

- None blocking. Affiliated/external bodies are modeled as a separate entity type and do not inherit internal entity permissions.

---

# Expansion Plan: Organization Structure UI Polish

## Overview

Refine only the presentation layer of the existing Organization Structure page so deep hierarchy, entity types, summary metrics, detail values, and actions scan consistently without changing routes, controllers, or data contracts.

## Architecture Decisions

- Preserve the current components and design tokens; introduce one page-local entity-type badge shared by the tree and inspector.
- Keep row selection and metadata full-width while applying 24px depth increments only to the leading tree content.
- Render connector lines on recursive child groups so hierarchy remains visible through at least three levels.
- Derive the tree result count from the same search/type predicate used by the tree.

## Task List

### Phase 1: Tree hierarchy and type language

- [x] Add failing UI assertions for nested connectors, consistent branch toggles/spacers, and shared type badges.
- [x] Add 24px visual depth, connector lines, 40px toggle targets, and consistently colored icons/badges.

### Checkpoint: Tree readability

- [x] Focused Organization Structure component tests pass.

### Phase 2: Detail and summary polish

- [x] Standardize detail values, add code copy affordance, and group actions as one primary plus two secondary actions.
- [x] Clarify stat dividers and change the tree count to “Showing filtered of total.”

### Checkpoint: Complete

- [x] UI tests, TypeScript, scoped lint, production build, and diff checks pass.
- [ ] Browser inspection is completed when authenticated page access is available.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Deep indentation crowds small screens | Medium | Preserve 24px desktop depth and use the existing responsive tree overflow behavior. |
| Filter count diverges from displayed rows | Medium | Share one exported filter predicate between the count and tree rendering. |
| Copy API is unavailable | Low | Hide no data and keep the code selectable; the copy action reports success only after the browser API resolves. |

## Open Questions

- None blocking. This pass is intentionally frontend-only.

---

# Expansion Plan: Exact Staff Organizational Placement

## Overview

Make the canonical organizational entity the single placement choice when creating or editing staff. Legacy department and division identifiers remain compatibility projections derived by the server, while secretary access follows the exact selected entity immediately.

## Architecture Decisions

- `users.organizational_unit_id` is authoritative for staff placement; client-supplied department/division IDs never override it.
- Active internal entities are selectable, excluding the Ministry root and affiliated/external bodies.
- Non-system-administrator staff require an exact entity; the system administrator may remain centrally unassigned.
- Updating a secretary's staff placement also updates any active secretary attachment so dashboard and correspondence scope cannot remain on the previous entity.
- A single searchable path-based selector replaces the three competing department, division, and unit controls.

## Task List

### Phase 1: Placement contract and access boundary

- [x] Add failing feature tests for exact division transfer, legacy projection derivation, external-body rejection, and secretary scope synchronization.
- [x] Make staff placement authoritative and synchronize active secretary attachments transactionally.

### Checkpoint: Access safety

- [x] A transferred secretary resolves only to the newly selected division entity.
- [x] Mismatched client department/division values cannot broaden access.

### Phase 2: Staff administration UI

- [x] Return hierarchy-aware organizational entity options with type and full path.
- [x] Replace department/division/unit controls in create and edit flows with one searchable accessible selector.
- [x] Add an explicit entity-level filter so administrators can narrow assignments to Divisions.
- [x] Show exact organizational placement in the staff list and profile summary.

### Checkpoint: Complete

- [x] Focused backend and frontend tests, type checking, formatting, build, and diff checks pass.
- [x] Authorization-focused code review has no unresolved scope-expansion finding.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Existing secretary attachment points to an old office | High | Update active attachments in the same transaction as the staff placement. |
| Client submits a unit plus unrelated legacy IDs | High | Derive both legacy IDs exclusively from the selected canonical entity. |
| Old staff are not yet mapped | Medium | Require an entity on the next non-admin create/edit while preserving existing records until deliberately reviewed. |
| Duplicate entity names confuse administrators | Medium | Display searchable full hierarchy paths and entity-type labels. |

## Open Questions

- None blocking. System administrators remain the only role permitted without an organizational entity.
