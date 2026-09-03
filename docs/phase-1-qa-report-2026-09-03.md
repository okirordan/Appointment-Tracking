# Appointment Tracking System — Phase 1 QA, Security and Usability Report

**Assessment date:** 3 September 2026  
**Assessment scope:** Current working tree, isolated clean SQLite database, local PHP development server, automated backend/frontend suites, static checks, direct-request authorization probes, public browser experience, and a standard Codex Security scan.  
**Phase gate:** Assessment only. No application source code, configuration, schema, or dependency changes were made.

## Executive summary

The system has a substantial and generally well-tested core: 284 of 288 backend tests passed, all 15 UI unit tests passed, TypeScript compilation passed, and the production frontend bundle built successfully. Public authentication pages are responsive and usable, private file storage is policy-gated, common browser security headers are present, and the principal correspondence/task workflows have broad automated coverage.

The current build is **not ready for production**. A fresh seeded installation does not complete, the default seed creates multiple active accounts with one predictable password and no forced password change, and five reproducible authorization/session-isolation failures cross organizational or lifecycle boundaries. Four document/import tests also fail because the active PHP runtime does not provide `ext-zip`. Historical mail seeding bypasses the model lifecycle and leaves all 532 seeded records without their canonical correspondence and organizational metadata.

No critical vulnerability was validated. One high-severity security finding, seven medium-severity findings, and several low-severity quality/hardening issues require attention. Authenticated browser workflows were deliberately not exercised because the browser-testing safety rules require explicit permission immediately before entering even a local test password; the direct-request probes and automated suites still establish the authorization defects described below.

### Scorecard

| Area | Score / 10 | Release interpretation |
|---|---:|---|
| Functionality | 6.5 | Happy paths are broad, but clean installation and document features fail |
| Security | 5.5 | Good baseline controls; material credential, authorization, and session issues |
| Usability | 7.0 | Public shell is clear and responsive; authenticated workflows not manually timed |
| Organizational access control | 5.5 | Intended scopes are explicit, but five boundary failures were reproduced |
| Correspondence management | 7.0 | Broad workflow coverage; recipient scoping and historical seeding are unsafe |
| Assignment/progress workflow | 7.5 | Strong automated coverage; performance/source-mail projections overexpose data |
| File management | 5.5 | Private disks/policies are sound; active runtime cannot process ZIP-based formats |
| Data integrity | 5.5 | Foreign keys/orphans are clean; seed abort, duplicate units, and null lifecycle data remain |
| Performance | 6.5 | Local public routes are usable; no representative load or concurrency result |
| Beginner friendliness | 6.5 | Public sign-in is understandable; installation defaults and upload limits are confusing |

## Environment, method, and limitations

- Stack identified: Laravel 13.19, PHP 8.3.30, React 19 with Inertia, Vite 6, Pest/PHPUnit 11.5, Spatie Permission, and OpenSpout.
- The application exposes 116 web routes. No first-party API route surface was found.
- Testing used `APP_ENV=testing`, `APP_DEBUG=false`, a dedicated cookie name, array cache, synchronous queue, and an isolated SQLite database at `tmp/qa-phase1-20260903.sqlite`.
- The developer server ran only on `127.0.0.1`; no production data or configured application database was modified.
- Dependency advisories were checked against the lockfiles. Reachability of third-party advisories was not proven and should be established during remediation.
- Browser checks covered the unauthenticated shell at desktop, 390×844 mobile, and 320×640 narrow viewport widths. Authenticated browser entry awaits explicit approval to enter a test password.
- Performance timings came from Laravel's single-process development server and are directional only. Multi-user load, slow storage, queue latency, and production IIS/PHP-FPM behavior were not tested.

## Architecture and ownership map

The core data path is:

1. A `MailRecord` is captured/imported and associated with an organizational location.
2. Correspondence recipients and forwarding records determine departmental, unit, and individual custody.
3. Tasks are created or delegated from correspondence and progress is recorded against assignments.
4. Policies, `MailAccessScope`, `MailboxScope`, `TaskScope`, `SecretaryAuthorityService`, and `OrganizationalScopeService` are expected to enforce visibility.
5. Presenters/services shape the Inertia payload returned to React pages.

The six built-in roles are `sysadmin`, `principal_secretary`, `clerk`, `commissioner`, `secretary`, and `officer`. Other ministry titles are principally represented through positions/organizational units; database-defined custom roles are possible, but several controller/service branches explicitly recognize only the six built-in enum roles. This creates a maintenance risk whenever a custom role is expected to inherit a built-in operational scope.

Private mail and evidence files use non-public storage disks (`serve=false`) and are delivered through controllers after authorization. This is a sound boundary, subject to the recipient-target authorization defect below.

## Role and capability matrix

| Role | Administration | Mail visibility/management | Task visibility | Create/delegate | Review/approve | Reports/export |
|---|---|---|---|---|---|---|
| System administrator | Full user/role/structure/settings administration | No seeded mail permission | Personal/participant relationships | Administrative permissions; not broad task custody by default | Not broad operational oversight by default | Administration-oriented |
| Principal Secretary | No general system administration | All correspondence capabilities | Ministry-wide | Yes | Yes | View/export |
| Clerk | No | Captured/permitted correspondence; manage and assign | Scoped | Create/delegate within authority | Limited by permissions | Limited |
| Commissioner | No | Route-specific mail lists/assignment despite no seeded mail permission | Department and subordinate scope | Yes | Review/approve/return/reject/reassign | View |
| Secretary | No | Seeded `mail.view`, plus active appointment authority | Office/department placement scope plus direct relationships | Limited | Progress update | View |
| Officer | No | No general mail capability | Direct assignments, participation, and permitted subordinate relationships | No general delegation | Progress update | Limited |

## Organizational access matrix

| Actor/placement | Intended visible scope | Verified boundary status |
|---|---|---|
| Principal Secretary | Ministry-wide tasks and correspondence | Automated coverage passed |
| System administrator | Administrative data; operational records only through explicit relationship/permission | Automated baseline passed; session revocation fails |
| Clerk | Captured mail, explicit recipients/forwarding, and delegated task relationships | Core tests passed |
| Commissioner | Own department and authorized subordinate structures | Core tests passed; recipient metadata can widen scope |
| Department secretary | Active attached department/root scope | Core tests passed; ended placement can retain scope |
| Division/office secretary | Active exact-unit/authorized placement scope | Core tests passed; ended placement can retain scope |
| Officer | Direct assignment, participant, and authorized subordinate relationships | Core tests passed |
| User without active placement | Direct personal relationships only; no inherited organizational custody | Violated by stale secretary fallback in applicable profiles |

## Detailed findings

### ATS-QA-001 — Predictable shared password across seeded active accounts

- **Severity:** High
- **Category:** Security / Authentication
- **Affected roles:** All seeded roles
- **Organizational scope:** Ministry-wide
- **Feature:** Initial provisioning and login
- **Preconditions:** Run the standard database seed and expose the application to a reachable network.
- **Steps to reproduce:** (1) Run `php artisan migrate:fresh --seed --force`. (2) Inspect the credentials created by `DatabaseSeeder`/`UserSeeder`. (3) Attempt login using the documented/shared seed password for any seeded username.
- **Expected result:** Production-capable seeds either create no usable shared credentials, generate per-user secrets out of band, or require a password change before any application access.
- **Actual result:** Fourteen active seeded users span all built-in roles, share one predictable password, and have `force_password_change = 0`.
- **Impact:** Disclosure of one seed convention can grant immediate access across operational and privileged personas.
- **Root cause:** `DatabaseSeeder.php:9-25` and `UserSeeder.php:19-46` provision a common known secret without a first-login gate.
- **Affected route:** `GET/POST /login`; `GET/POST /password/change`
- **Controller/service:** `Auth\LoginRequest`, `Auth\ChangePasswordController`
- **Model/policy/middleware:** `User`; authentication middleware
- **Tables:** `users`, role/permission pivot tables
- **Recommended fix:** Restrict demo credentials to an explicit local/demo seeder, generate unique high-entropy temporary secrets, set `force_password_change = 1`, and block all other authenticated routes until rotation. Add a production-environment seeder refusal.
- **Regression test required:** Yes — seed behavior by environment, unique credentials, and enforced first-login rotation.

### ATS-QA-002 — Clean installation aborts during CIM staff seeding

- **Severity:** High
- **Category:** Functional / Deployment / Data integrity
- **Affected roles:** Installer and all users of a new environment
- **Organizational scope:** Department of Education Inspection and Compliance
- **Feature:** Fresh database installation
- **Preconditions:** Empty database and normal seed sequence.
- **Steps to reproduce:** Run `php artisan migrate:fresh --seed --force` against the isolated database.
- **Expected result:** All migrations and seeders complete and produce a usable baseline installation.
- **Actual result:** All 53 migrations apply, then seeding aborts at `CimStaffSeeder.php:149` because approved position `Commissioner – Inspection and Compliance` is not found in `Department of Inspection and Compliance`.
- **Impact:** A new deployment cannot be reproduced reliably; later seeders and required reference data are omitted.
- **Root cause:** The approved structure names the unit `Department of Education Inspection and Compliance`, while `CimStaffSeeder` queries an exact different name.
- **Affected route:** Not applicable; installation command
- **Controller/service:** Seeder orchestration
- **Model/policy/middleware:** Organizational unit and approved-position models
- **Tables:** `organizational_units`, `approved_positions`, `users`
- **Recommended fix:** Use immutable unit codes/IDs rather than display names, align the dictionary with the approved structure, and fail validation before partial staff insertion.
- **Regression test required:** Yes — a clean migration/seed smoke test in CI.

### ATS-QA-003 — Individual correspondence recipient metadata grants department-wide access

- **Severity:** Medium
- **Category:** Security / Authorization
- **Affected roles:** Department-scoped users, secretaries, commissioners
- **Organizational scope:** Any department recorded on an individual recipient row
- **Feature:** Correspondence list, view, and attachment access
- **Preconditions:** A correspondence is addressed to an individual and its recipient row also stores that individual's department.
- **Steps to reproduce:** (1) Create mail for one individual. (2) Store `target_type=individual` with the individual's `department_id`. (3) Authenticate as a different user whose scope contains that department. (4) request the mail or its attachment.
- **Expected result:** Only the individual, explicitly authorized delegates, and independent custodians may access an individually addressed item.
- **Actual result:** `MailAccessScope`/`MailboxScope` OR-match `department_id` without requiring a department target, so an unrelated department-scoped user receives access. The isolated deny probe failed.
- **Impact:** Confidential individually addressed correspondence can be disclosed to a wider organizational audience.
- **Root cause:** `CorrespondenceForwardingService.php:364-369` and `MailRecordService.php:259-267` store placement metadata; `MailAccessScope.php:99-108` and `MailboxScope.php:119-129` treat it as an authorization target.
- **Affected route:** Mailbox/mail detail routes; `GET /mail-attachments/{attachment}/download|preview`
- **Controller/service:** `MailAttachmentController`
- **Model/policy/middleware:** `MailRecordPolicy`, `MailAccessScope`, `MailboxScope`
- **Tables:** correspondence recipients/forwardings, `mail_records`, `mail_attachments`
- **Recommended fix:** Bind every scope predicate to recipient `target_type`, separate informational placement metadata from custody grants, and centralize this invariant in one recipient-authority query.
- **Regression test required:** Yes — individual/department/unit targets with same-department non-recipient users.

### ATS-QA-004 — Ended secretary appointment can retain organizational access

- **Severity:** Medium
- **Category:** Security / Authorization lifecycle
- **Affected roles:** Secretary and any user with secretary profile placement
- **Organizational scope:** Former department or unit
- **Feature:** Mail and task scope after appointment end
- **Preconditions:** A secretary placement exists, profile department/unit fields are populated, then the appointment is ended.
- **Steps to reproduce:** (1) Attach a secretary. (2) end the appointment. (3) Keep the same authenticated session. (4) request records belonging only to the former placement.
- **Expected result:** Ending the appointment removes inherited placement authority immediately while retaining audit history.
- **Actual result:** The appointment end path leaves profile fields populated and legacy fallbacks continue deriving authority from them. The isolated deny probe failed.
- **Impact:** Former secretaries may continue accessing records after reassignment or separation.
- **Root cause:** `SecretaryAttachmentService.php:104-113,195-212` ends the appointment without clearing/invalidating fallbacks used by `OrganizationalScopeService.php:15-27`, `SecretaryAuthorityService.php:45-60`, and `MailAccessScope.php:41-108`.
- **Affected route:** Authenticated mail/task routes using organizational scope
- **Controller/service:** Secretary appointment administration and scope services
- **Model/policy/middleware:** Secretary appointment/profile models, mail/task policies
- **Tables:** secretary appointments, `users`, `mail_records`, task assignments
- **Recommended fix:** Make active appointment rows the sole authority source; treat user profile placement as display/cache data and atomically invalidate it on appointment end.
- **Regression test required:** Yes — same-session before/after appointment end and new-session verification.

### ATS-QA-005 — Officer performance portfolio bypasses task visibility scope

- **Severity:** Medium
- **Category:** Security / Authorization
- **Affected roles:** Users permitted to view an officer performance page
- **Organizational scope:** Tasks outside the viewer's authorized task scope
- **Feature:** Officer performance portfolio
- **Preconditions:** Viewer may see an officer, but one of that officer's tasks is outside the viewer's normal `TaskScope`.
- **Steps to reproduce:** (1) Create a hidden task for a visible officer. (2) authenticate as the constrained viewer. (3) request `GET /officer-performance/{user}`.
- **Expected result:** Aggregate metrics and portfolio entries include only tasks the viewer may access, or return deliberately declassified aggregates.
- **Actual result:** The controller checks whether the officer is visible, then `PerformanceService::portfolio()` queries the officer's work without applying the viewer's `TaskScope`. Hidden task metadata is present; the deny probe failed.
- **Impact:** Subject, status, dates, and workflow metadata can reveal restricted matters.
- **Root cause:** Viewer context is not supplied to `PerformanceService.php:101-120,128-155`; `TaskScope.php:27-40` is bypassed.
- **Affected route:** `GET /officer-performance/{user}` (`performance.show`)
- **Controller/service:** `OfficerPerformanceController.php:65-82`, `PerformanceService`
- **Model/policy/middleware:** `Task`, `TaskScope`
- **Tables:** tasks, assignments, progress records
- **Recommended fix:** Pass the viewer into metrics/portfolio queries and intersect with the canonical task visibility scope; explicitly classify any safe aggregate fields.
- **Regression test required:** Yes — visible officer with a mixture of visible and hidden tasks.

### ATS-QA-006 — Task presentation exposes source-mail content before mail authorization

- **Severity:** Medium
- **Category:** Security / Data exposure
- **Affected roles:** Any user able to view a task but not its source correspondence
- **Organizational scope:** Cross-scope tasks derived from restricted correspondence
- **Feature:** Task detail payload
- **Preconditions:** User can view a task referencing a mail record they cannot independently view.
- **Steps to reproduce:** Request `GET /tasks/{task}` as the task-authorized/mail-unauthorized user and inspect the Inertia JSON payload.
- **Expected result:** Source-mail fields are absent/redacted unless `MailRecordPolicy::view` passes.
- **Actual result:** `TaskPresenter` includes the source mail object before a mail policy decision. The deny probe found the restricted source object.
- **Impact:** Correspondence metadata/content crosses the separate mail authorization boundary.
- **Root cause:** `TaskController.php:37-42` delegates presentation without viewer-specific mail authorization; `TaskPresenter.php:88-96,192-215` eagerly serializes source mail despite `MailRecordPolicy.php:39-42`.
- **Affected route:** `GET /tasks/{task}` (`tasks.show`)
- **Controller/service:** `TaskController`, `TaskPresenter`
- **Model/policy/middleware:** `TaskPolicy`, `MailRecordPolicy`
- **Tables:** tasks, `mail_records`, correspondence
- **Recommended fix:** Require the current viewer in the presenter and emit source-mail fields only after an explicit `view` authorization check; avoid serializing the relationship by default.
- **Regression test required:** Yes — task access with and without source-mail access.

### ATS-QA-007 — Existing sessions survive account or role deactivation

- **Severity:** Medium
- **Category:** Security / Session management
- **Affected roles:** All authenticated users
- **Organizational scope:** Whatever the existing session can access
- **Feature:** User deactivation, lock, role disable, and password administration
- **Preconditions:** User already has an authenticated session; an administrator deactivates/locks the account or disables its role.
- **Steps to reproduce:** (1) Sign in. (2) deactivate the user from a second administrative context. (3) reuse the original session on an authenticated route.
- **Expected result:** The next request terminates the session and denies access.
- **Actual result:** Login checks status only during authentication; the original session continues receiving HTTP 200. The deny probe failed.
- **Impact:** Offboarded or compromised users retain access until session expiry/logout.
- **Root cause:** `LoginRequest.php:45-64` validates state only at login; authenticated middleware in `bootstrap/app.php:25-38`/`routes/web.php:56-57` does not revalidate user/role state, and admin mutation paths do not revoke sessions.
- **Affected route:** All authenticated routes; especially `POST /users/{user}/toggle-active` and `POST /roles/{role}/toggle`
- **Controller/service:** `Admin\UserController`, `Admin\RoleController`, password administration
- **Model/policy/middleware:** `User`, roles, authentication middleware
- **Tables:** `users`, `roles`, sessions
- **Recommended fix:** Add a per-request active/lock/role-state guard and revoke server-side sessions/tokens when identity authority changes. Rotate session identifiers after password/security changes.
- **Regression test required:** Yes — same-cookie requests after each revocation event.

### ATS-QA-008 — ZIP-based document and spreadsheet features fail in the active PHP runtime

- **Severity:** Medium
- **Category:** Functional / Environment / File management
- **Affected roles:** Mail importers, administrators, officers using document previews
- **Organizational scope:** All
- **Feature:** XLSX import/template and DOCX preview
- **Preconditions:** Run under the currently active PHP 8.3.30 installation.
- **Steps to reproduce:** Run the full backend suite or `composer check-platform-reqs`.
- **Expected result:** Required extensions are installed and document features/tests execute.
- **Actual result:** `ext-zip` is missing. Four tests fail: two `MailImportTest` XLSX cases, the admin XLSX template test, and the DOCX preview case in `ProgressWorkflowTest`.
- **Impact:** Supported office-file workflows fail at runtime despite the rest of the suite passing.
- **Root cause:** Deployment/runtime prerequisites do not ensure the PHP ZIP extension used by XLSX/DOCX tooling.
- **Affected route:** Mail import/template and progress evidence preview routes
- **Controller/service:** Import/template controllers and document preview service
- **Model/policy/middleware:** Upload request validation and file policies
- **Tables:** Imported mail/progress metadata tables
- **Recommended fix:** Install/enable `ext-zip`, declare/verify it in Composer platform requirements and deployment health checks, and run the office-file smoke tests on the deployment runtime.
- **Regression test required:** Yes — CI/platform requirement gate plus end-to-end XLSX/DOCX fixtures.

### ATS-QA-009 — Historical mail seeder bypasses lifecycle initialization

- **Severity:** Medium
- **Category:** Data integrity / Correspondence workflow
- **Affected roles:** All correspondence users in seeded/migrated environments
- **Organizational scope:** Ministry-wide historical dataset
- **Feature:** Incoming mail seed/import
- **Preconditions:** Run `MoesIncomingMailSeeder` after the minimum reference seeders.
- **Steps to reproduce:** Run the seeder and query lifecycle/ownership fields for all inserted mail records.
- **Expected result:** Seeded mail has canonical correspondence linkage, financial year, last processor, and organizational ownership consistent with normal capture.
- **Actual result:** All 532 seeded mail records have null `correspondence_id`, `financial_year`, `last_processed_by_user_id`, `organizational_unit_id`, and `department_id`.
- **Impact:** Search, routing, reporting, access decisions, and workflow continuity can behave differently for seeded historical mail than for normally captured mail.
- **Root cause:** `MoesIncomingMailSeeder.php:77` uses query-level `MailRecord::upsert`, bypassing the model `created` lifecycle that initializes canonical correspondence and metadata.
- **Affected route:** Mailbox, search, reports, routing/assignment
- **Controller/service:** Seeder/import lifecycle versus `MailRecordService`
- **Model/policy/middleware:** `MailRecord` events, mail scopes/policies
- **Tables:** `mail_records`, correspondence, recipients/forwardings
- **Recommended fix:** Route historical rows through an idempotent domain import service or explicitly perform the same lifecycle initialization in one transaction, then validate every imported row.
- **Regression test required:** Yes — seeded/imported rows must satisfy the same invariants as captured mail.

### ATS-QA-010 — Duplicate active organizational units under the same parent

- **Severity:** Medium
- **Category:** Data integrity / Usability
- **Affected roles:** Administrators, clerks, commissioners, secretaries
- **Organizational scope:** Eight affected unit names
- **Feature:** Organizational structure and recipient/assignee selection
- **Preconditions:** Run the structure seeders that complete before the clean-seed abort.
- **Steps to reproduce:** Group active organizational units by exact parent and normalized name.
- **Expected result:** One active unit identity exists for each parent/name unless an explicit versioning model differentiates it.
- **Actual result:** Eight duplicate active names exist under the same parent: Communications Section, Database Management Unit, General Administration Section, Inventory Management Unit, Loans Assessment and Award, Loans Recovery, Records Management Section, and Resource Centre. The records use different codes from two structure seeders.
- **Impact:** Users can choose visually indistinguishable destinations; access and reporting may split across duplicate IDs.
- **Root cause:** `OrganizationStructureSeeder` and `ApprovedMinistryStructureSeeder` independently create overlapping structures without a shared natural key or reconciliation step.
- **Affected route:** Administration structure, recipient lookup, assignment, search/report filters
- **Controller/service:** Organizational structure administration/lookups
- **Model/policy/middleware:** `OrganizationalUnit` and organizational scope services
- **Tables:** `organizational_units`, positions and placement relations
- **Recommended fix:** Establish immutable canonical unit codes, reconcile seed sources, add a uniqueness constraint for active units under a parent, and migrate references before retiring duplicates.
- **Regression test required:** Yes — seed uniqueness and ambiguous-recipient lookup tests.

### ATS-QA-011 — Locked dependencies contain unresolved security advisories

- **Severity:** Medium
- **Category:** Security / Supply chain
- **Affected roles:** Deployment and all application users
- **Organizational scope:** Application-wide
- **Feature:** Build/runtime dependency set
- **Preconditions:** Install the committed lockfiles.
- **Steps to reproduce:** Run `composer audit --locked` and `npm audit --package-lock-only`; repeat npm audit with `--omit=dev`.
- **Expected result:** The release dependency set has no known high/critical advisories, or each exception has documented non-reachability and an expiry.
- **Actual result:** Composer reports 10 advisories for locked `league/commonmark` 2.8.2. npm reports 23 advisories total and 15 with `--omit=dev` (including 2 critical and 8 high), partly because build tooling is listed as production dependencies.
- **Impact:** Known vulnerable third-party code enters the release process; actual exploitability remains unestablished for this application.
- **Root cause:** Stale lockfiles and production/development dependency classification. No direct first-party CommonMark/Markdown use was found, and Node build tools were not observed in the shipped browser bundle.
- **Affected route:** Potentially build pipeline and any reachable affected library path
- **Controller/service:** Dependency/build configuration
- **Model/policy/middleware:** Not applicable
- **Tables:** None
- **Recommended fix:** Upgrade to patched compatible versions, move build-only packages to `devDependencies`, regenerate/review lockfiles, and document any temporary advisory exception with reachability evidence.
- **Regression test required:** Yes — lockfile audit gate with reviewed exceptions.

### ATS-QA-012 — Push subscription endpoint validation permits conditional blind SSRF

- **Severity:** Low
- **Category:** Security / External integration
- **Affected roles:** Authenticated users with notification settings access
- **Organizational scope:** Server egress network
- **Feature:** Browser push notifications
- **Preconditions:** Web Push/VAPID is configured, outbound network access is available, and a notification is delivered to a user-controlled subscription.
- **Steps to reproduce:** Register a syntactically valid attacker-chosen endpoint through `POST /notification-settings/push-subscriptions`, then trigger a push delivery and observe the server request.
- **Expected result:** Endpoints are restricted to approved HTTPS push-service origins/IP ranges and are revalidated after DNS resolution/redirects.
- **Actual result:** `NotificationController.php:119-143` accepts a general URL and `BrowserPushService.php:73-97,110-116` sends server-side requests to it. Response contents are not exposed, so impact is blind and conditional.
- **Impact:** An authenticated user may induce requests from the application network to unintended destinations.
- **Root cause:** URL syntax validation is used as an egress authorization policy.
- **Affected route:** `POST /notification-settings/push-subscriptions`
- **Controller/service:** `NotificationController`, `BrowserPushService`
- **Model/policy/middleware:** Auth middleware, push subscription model
- **Tables:** push subscriptions
- **Recommended fix:** Allowlist supported push-provider origins, require HTTPS, block private/link-local/reserved resolved addresses, re-resolve at send time, reject redirects, and apply egress firewall rules.
- **Regression test required:** Yes — private/reserved IPv4/IPv6, DNS rebinding, redirect, and allowed-provider cases.

### ATS-QA-013 — Upload limits conflict across runtime, validation, examples, and deployment documentation

- **Severity:** Low
- **Category:** Functional / Usability / Configuration
- **Affected roles:** Users uploading correspondence, evidence, imports, and documents
- **Organizational scope:** All
- **Feature:** File uploads/imports
- **Preconditions:** Compare default configuration, request validation, `.env.example`, and IIS deployment guidance.
- **Steps to reproduce:** Attempt files between the documented/request/configuration thresholds on each upload route.
- **Expected result:** One documented limit is enforced consistently at proxy/web server, PHP, request validation, and UI layers.
- **Actual result:** Mail/evidence configuration defaults to 100 MB, `.env.example` describes 10 MB evidence, many requests enforce 20 MB, import configuration suggests 2 GiB, the controller enforces 20 MiB, and IIS guidance references 32 MiB.
- **Impact:** Users see avoidable failures and support teams cannot predict accepted sizes.
- **Root cause:** Limit ownership is distributed and values drifted independently.
- **Affected route:** All upload, import, and evidence routes
- **Controller/service:** Form requests/import/evidence services
- **Model/policy/middleware:** Upload validators
- **Tables:** Attachment/evidence metadata tables
- **Recommended fix:** Define per-feature limits once, expose them to validation and UI, and validate deployment/PHP ceilings are at least as large. Document units consistently (MB versus MiB).
- **Regression test required:** Yes — boundary-size tests and deployment health reporting.

### ATS-QA-014 — Static quality gates are not clean

- **Severity:** Low
- **Category:** Maintainability / Release quality
- **Affected roles:** Developers and release maintainers
- **Organizational scope:** Repository-wide tooling
- **Feature:** Linting and formatting
- **Preconditions:** Run the committed quality scripts.
- **Steps to reproduce:** Run `npx eslint .` and `npm run format:check`.
- **Expected result:** Both commands pass for the intended source set.
- **Actual result:** ESLint reports 14 `no-undef` errors: two in `scripts/generate-icons.mjs` and twelve in unrelated `tmp/ats_presentation_redesign` scripts. Prettier reports `resources/js/ssr.jsx` as unformatted.
- **Impact:** CI quality gates are noisy and can hide newly introduced defects.
- **Root cause:** Node globals are not configured for the icon script, temporary artifacts are inside the lint scope, and one app file has formatting drift.
- **Affected route:** Not applicable
- **Controller/service:** Build tooling
- **Model/policy/middleware:** Not applicable
- **Tables:** None
- **Recommended fix:** Scope lint inputs deliberately, ignore disposable artifacts, configure Node globals for Node scripts, and format the SSR entry point.
- **Regression test required:** Yes — clean lint/format commands in CI.

## Test execution record

### Passed

- Focused organizational/mail/task/security suite: **42 tests, 628 assertions passed**.
- Full backend suite excluding the four environment failures: **284 tests passed**.
- Frontend UI suite: **15 tests in 3 files passed**.
- `npx tsc --noEmit`: passed.
- `npm run build`: passed; 362 modules transformed.
- Database foreign-key check: zero violations.
- Orphan checks for user/mail department/unit/capturer relations: zero.
- Duplicate username and register-number checks: zero.
- Duplicate same-unit approved-position title check: zero.
- Active overlapping secretary appointments check: zero.
- Public browser checks: responsive desktop/mobile sign-in, readable labels, inline required-field errors, working Light/Dark/System theme controls, no horizontal overflow at 390 px or 320 px, anonymous `/tasks` redirect to `/login`, and branded unknown-route handling.
- Security headers on `/login`: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, same-origin referrer policy, restrictive permissions policy, nonce-based CSP, and private/no-cache response controls.

### Failed or intentionally negative

| Test/check | Result | Interpretation |
|---|---|---|
| Full backend suite | 4 failures / 288 | All four require missing `ext-zip` |
| Fresh migration + full seed | Failed | CIM department-name mismatch aborts installation |
| Authorization probe: individual recipient | Failed expected-deny assertion | Department metadata widens access |
| Authorization probe: ended secretary | Failed expected-deny assertion | Stale placement authority remains |
| Authorization probe: performance portfolio | Failed expected-deny assertion | Hidden task appears in portfolio |
| Authorization probe: task source mail | Failed expected-deny assertion | Restricted mail object appears in task payload |
| Authorization probe: deactivated session | Failed expected-deny assertion | Existing session still receives HTTP 200 |
| ESLint | 14 errors | Two app-tooling and twelve temporary-artifact scope errors |
| Prettier check | 1 file | `resources/js/ssr.jsx` drift |
| Composer audit | 10 advisories | Locked CommonMark advisories; app reachability not established |
| npm audit, production classification | 15 advisories | Build packages classified as production; reachability not established |

The negative probe lives in `tmp/PhaseOneSecurityProbeTest.php`. Its five failures are the evidence: every case asserts the security-preserving denial/redaction that the current build does not provide.

## Usability and accessibility observations

The public sign-in experience has a clear heading, explicit labels, accessible button names, meaningful crest alternative text, visible validation messages, and usable theme controls. At 390×844 the form is comfortably sized and the nonessential hero is removed; at 320×640 there is still no horizontal scroll, although fields sit close to the viewport edges. Keyboard-only authenticated workflows, focus order in dialogs, complex tables, file-upload feedback, screen-reader announcements, and time-to-complete measures remain unverified until authenticated browser testing is authorized.

The application returns `/tasks` visitors to sign-in, which is appropriate. `/dashboard` is not a defined application route and returns the branded 404 page; navigation and documentation should consistently use the actual landing route to avoid beginner confusion.

## Performance observations

Five local requests to `/login` took approximately **0.84–1.14 seconds**, and `/up` took approximately **0.61 seconds**, on the single-process development server. No database-query explosion was established in the automated run, but these timings are not a production performance result. Before release, test representative mail volume, large organizational trees, officer portfolio queries, search pagination, attachment streaming, and concurrent routing/progress updates under the intended IIS/PHP/database topology.

## Priority order

### P0 — must resolve before any production deployment

1. Remove/shared-seed credential exposure and enforce first-login rotation.
2. Make a clean migration and complete seed deterministic.
3. Correct the individual-recipient authorization predicate.
4. Remove former-secretary authority immediately on appointment end.
5. Apply task visibility to performance portfolios and mail policy to task source-mail presentation.
6. Revoke/revalidate sessions after user/role authority changes.

### P1 — required for a dependable release candidate

1. Install and continuously verify `ext-zip`.
2. Restore historical mail lifecycle invariants and reconcile existing affected data.
3. Reconcile duplicate organizational units before users create more references.
4. Upgrade or formally disposition dependency advisories.

### P2 — complete before broad user acceptance testing

1. Unify upload limits across layers.
2. Restrict Web Push endpoints and server egress.
3. Restore clean lint/format gates.
4. Perform authenticated, role-by-role browser tests with a dedicated QA credential.

### P3 — production hardening and confidence work

1. Run accessibility tooling plus keyboard/screen-reader sessions on authenticated pages.
2. Execute production-like load, concurrency, recovery, and large-file tests.
3. Remove the `X-Powered-By` PHP version disclosure at the web-server layer.
4. Add operational monitoring for authorization denials, import failures, notification egress, and stale sessions.

## Required regression suite

The remediation phase should add a cross-product authorization suite, not isolated controller examples. At minimum cover:

- every built-in role × direct recipient/department/unit/individual target × attachment access;
- active, future, ended, and overlapping secretary placements in the same and existing sessions;
- task-visible/mail-hidden and mail-visible/task-hidden projections;
- visible officers with mixed visible/hidden tasks and safe aggregate definitions;
- account deactivation, soft deletion, lockout, password reset, role disable, and role removal against an existing cookie;
- clean database creation and complete seed on the deployment database engine;
- captured, imported, and seeded mail satisfying identical correspondence/ownership invariants;
- canonical organizational-unit uniqueness and recipient lookup disambiguation;
- upload files immediately below, equal to, and above every configured limit;
- safe push-provider endpoints plus private, link-local, IPv6, redirect, and DNS-change rejection;
- XLSX/DOCX end-to-end checks on the packaged PHP runtime;
- dependency, type, build, lint, format, and security-audit release gates.

## Release recommendation

**Do not deploy this build to production.** Resolve all P0 items, create the regression tests above, rerun the complete backend/frontend/static/dependency suite on the deployment runtime, then perform authenticated role-by-role browser verification. After those steps, repeat the security scan against the remediation diff and explicitly validate that each original attack path is closed.

## Evidence and artifacts

- [Desktop sign-in screenshot](C:/Users/Lenovo/.codex/visualizations/2026/09/03/01a065b3-125c-7211-a637-112788fd2910/qa-login-desktop.png)
- [Mobile sign-in screenshot](C:/Users/Lenovo/.codex/visualizations/2026/09/03/01a065b3-125c-7211-a637-112788fd2910/qa-login-mobile.png)
- [Canonical Codex Security report](C:/Users/Lenovo/AppData/Local/Temp/codex-security-scans-5k0j5o/Appointment-Tracking/1d031d514380d22237e61e147d3cb241671e0cfe_20260903T051918Z_dlrrplt6/report.md)
- Isolated QA database: `tmp/qa-phase1-20260903.sqlite`
- Authorization probe: `tmp/PhaseOneSecurityProbeTest.php`

The security scan completed and sealed with seven validated findings and partial coverage. Its baseline dependency-reachability pass was limited by an agent usage exhaustion event; the lockfile audits and first-party reachability search in this report compensate partially but do not constitute exhaustive third-party exploitability analysis.
