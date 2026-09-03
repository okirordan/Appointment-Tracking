# Codex Security findings and remediation guide

Date: 2026-08-31  
Scan ID: `7ecc1cdf-e2a8-420a-bb8f-0577710fa731`  
Registered revision: `8f3ecb298c24ba4739a55c3753ddd0942021d1f3`  
Findings: **1 high, 5 medium, 3 low**

## Scope and limitations

This guide expands the completed Standard Codex Security scan into an implementation plan. Findings were validated through static source traces and counterevidence, with selected installed dependency implementations inspected. No application requests, exploit demonstrations, or tests were executed. Live configuration, database state, TLS, permissions, and outbound network access were not verified. This was not a dependency advisory scan.

**Revision warning:** repository HEAD changed while the scan was running. The report was registered against the revision above; HEAD at completion was `1d031d514380d22237e61e147d3cb241671e0cfe`. Before implementing a fix, verify the affected behavior and locations against the intended checkout. Line numbers below are scan anchors, not a guarantee about the current checkout.

No fixes have been implemented by this document. Proposed verification steps are acceptance criteria, not passing test results. Do not include real passwords, tokens, or confidential correspondence in regression fixtures.

The generated canonical report was saved at:

```text
C:/Users/Lenovo/AppData/Local/Temp/codex-security-scans-2cle1C/Appointment-Tracking/8f3ecb298c24ba4739a55c3753ddd0942021d1f3_20260831T090153Z_f8wdpqpk/report.md
```

## Priorities

| ID | Severity | Finding | Main correction |
| --- | --- | --- | --- |
| SEC-01 | High | Predictable initial passwords | Unique, expiring, single-use account activation |
| SEC-02 | Medium | Disabled or locked accounts retain sessions | Enforce account state on every request and revoke sessions |
| SEC-03 | Medium | Portfolios expose unauthorized tasks | Apply the requesting viewer's task scope |
| SEC-04 | Medium | Individual sharing grants department access | Match recipient type before interpreting metadata as a grant |
| SEC-05 | Medium | Ended secretary appointments retain authority | Prevent legacy fallback after appointment revocation |
| SEC-06 | Medium | Task payload exposes restricted live mail | Authorize the entire source-mail payload |
| SEC-07 | Low | Login reveals account existence | Uniform public authentication failures |
| SEC-08 | Low | Push subscriptions allow blind server requests | Restrict push destinations and outbound network access |
| SEC-09 | Low | Default seeding creates known privileged credentials | Separate demo seeding from production initialization |

Address SEC-01 first. Then fix the account and object-access boundaries in SEC-02 through SEC-06. SEC-07 compounds predictable-credential risk and is worth addressing alongside authentication changes. The low ratings for SEC-08 and SEC-09 reflect prerequisites and unverified deployment conditions, not a conclusion that either configuration is safe.

## SEC-01 — Predictable shared initial passwords permit account takeover

**Severity:** High · **CWE:** CWE-1392

### Evidence and impact

`app/Support/DefaultPassword.php:16` derives the initial password from a fixed prefix and the current year. Creation and administrative reset paths consume that value, including `Admin/UserController.php:99,124` and `Admin/PasswordManagementController.php:24` under `app/Http/Controllers/`.

An unauthenticated attacker who knows a newly provisioned account identifier can use the predictable password before its owner and replace it with an attacker-chosen password. New users have no enrolled MFA. Forced password change does not establish identity when the attacker already knows the current password. Existing MFA may protect reset accounts; accounts whose password has already changed are not affected by the initial value.

### Recommended fix

1. Replace shared passwords with cryptographically random, account-specific activation tokens. Store only a token hash, with an expiry and consumed/revoked state.
2. Let activation establish the user's chosen password without granting a normal authenticated session beforehand. Bind the token to one account and purpose, and consume it atomically to prevent replay.
3. Deliver activation through an approved identity-verification channel. Do not expose tokens in logs, analytics, audit payloads, or broadly accessible administrator responses.
4. Use the same secure mechanism for user creation, imports, and administrative resets. Reissuing activation must invalidate the previous token. Preserve or deliberately reset MFA through a separately authorized recovery process.
5. Identify accounts still using the old provisioning flow and require secure reactivation. Do not merely change the shared password formula.

### Verification

- Two newly provisioned accounts receive different activation secrets; calendar-derived credentials cannot authenticate.
- Expired, revoked, consumed, wrong-account, and wrong-purpose tokens fail.
- Two concurrent redemption attempts cannot both succeed.
- Reissuing activation invalidates the old link, and activation cannot bypass required MFA.
- Creation, import, and reset paths all use the new flow.

## SEC-02 — Disabled or locked users retain authenticated access

**Severity:** Medium · **CWE:** CWE-613

### Evidence and impact

`app/Http/Controllers/Admin/UserController.php:178` changes the active flag, while `Admin/PasswordManagementController.php:56` changes lock state. State is checked during fresh login in `app/Http/Requests/Auth/LoginRequest.php:59`, but existing authenticated sessions are not consistently revoked or rejected on subsequent requests.

A previously authenticated user can retain their existing permissions after an administrator disables or locks the account. This does not grant new permissions, but defeats an important access-revocation control.

### Recommended fix

1. Add a centralized account-state check to every authenticated route, before business actions execute. Reject inactive or locked users, log out the guard, invalidate the session, and regenerate the CSRF token.
2. On disable/lock, revoke server-side sessions and remember-me credentials using mechanisms appropriate to every deployed session driver. Session deletion alone is insufficient if some deployments use a different driver.
3. Consider an account/session version if revocation must work across multiple session stores or services. Ensure state changes are visible promptly rather than cached indefinitely.
4. Verify any other authentication mechanisms present in the target checkout, and revoke their credentials where the account-state contract requires it. Re-enabling an account should require fresh authentication.

### Verification

- Log in through two sessions, disable or lock the account, and confirm both fail their next protected read and write requests.
- Remember-me authentication cannot restore access while disabled or locked.
- Re-enabling does not resurrect invalidated sessions.
- Active users continue to work, including forced-change and MFA flows.

## SEC-03 — Performance portfolios expose tasks outside the viewer's scope

**Severity:** Medium · **CWE:** CWE-862

### Evidence and impact

`app/Services/PerformanceService.php:131` queries tasks by the subject officer without applying the requesting viewer's `TaskScope`. Officer lookup and performance controllers authorize access to an officer, then serialize task rows through `TaskPresenter`.

A clerk with lookup access or an unrelated system administrator can receive task titles, references, status, and dates for records that direct task access would deny. Aggregate performance access is intentional; the finding concerns identifiable task rows, not a demonstrated disclosure of full descriptions or attachment bytes.

### Recommended fix

1. Pass the requesting viewer into the portfolio service or provide a query already constrained by the central task authorization scope.
2. Intersect the subject officer filter with that scope before pagination, counts associated with the row listing, eager loading, and serialization.
3. Keep any intentionally broader aggregate reporting contract separate from identifiable records. Do not infer universal record access from a reporting role.
4. Review other portfolio/export consumers for the same missing viewer context.

### Verification

- Create visible and hidden tasks for one officer; the viewer receives only the visible rows.
- A system administrator without personal task access cannot retrieve unrelated task rows.
- Authorized participants retain access, and paging/list totals do not inadvertently reveal hidden rows.
- Intentionally permitted aggregate metrics retain their documented behavior.

## SEC-04 — Individual correspondence sharing grants unintended department access

**Severity:** Medium · **CWE:** CWE-863

### Evidence and impact

`app/Services/Mail/MailAccessScope.php:110` interprets a recipient's department field as a grant without requiring a department recipient type. Individual recipients also carry descriptive department metadata; producers include `CorrespondenceForwardingService.php:352` and `MailRecordService.php:259` in the same directory.

A department custodian who was not the individual recipient can gain access to correspondence, its thread, and private attachments. Secretary mutation policies can also use the same incorrect scope. This is limited to qualifying custodians, not every officer in a department. Attachment guards exist but inherit the faulty authorization decision.

### Recommended fix

1. Require the appropriate recipient target type in every branch: individual access from the individual user, department access from an explicit department target, and office access from an explicit office target.
2. Group query conditions carefully so alternative branches cannot bypass type, active-state, or other existing constraints.
3. Preserve descriptive organization metadata without treating it as authority. Centralize recipient interpretation so list, detail, mutation, and download paths agree.
4. Review historical recipient records before changing data. Do not erase legitimate department grants or indiscriminately remove department metadata from individual records.

### Verification

- An individual recipient with department metadata can read the mail; an unrelated custodian of that department cannot read, modify, forward, or download it without a separate valid grant.
- Explicit department and office recipients continue to work.
- Exercise real forwarding and outgoing-mail producers rather than fixtures that omit department metadata.
- Inactive recipients do not regain access through an alternative query branch.

## SEC-05 — Ended secretary appointments retain department authority

**Severity:** Medium · **CWE:** CWE-863

### Evidence and impact

`app/Services/SecretaryAuthorityService.php:95` falls back to the user's profile department when there is no current appointment. Appointment creation stamps that profile, while ending an appointment in `SecretaryAttachmentService.php:192` does not remove the stale authority. A related fallback exists at `SecretaryAuthorityService.php:53`.

An active account whose appointment has ended or expired can retain authority in its former department, including access to newly created records and some schedule/task actions. This is more than retention of previously seen information. Legacy accounts with no appointment history are a distinct compatibility case.

### Recommended fix

1. Distinguish “never had an appointment” from “had an appointment that is no longer effective.” Permit any retained legacy fallback only for the former case.
2. Derive appointed authority from effective, non-revoked appointments, including time boundaries. Use one consistent rule across secretary authority, office scope, dashboards, and task policies.
3. On end/expiry, ensure stale profile fields cannot restore authority. Clear or maintain those fields according to the profile's separate business meaning; do not rely on clearing alone as authorization.
4. Preserve legitimate concurrent appointments. Existing `DepartmentAccessService` history-aware behavior is a useful consistency reference.

### Verification

- End an appointment with a populated profile department; access to new records and protected mutations in that department must fail.
- Repeat for natural expiry, future appointments, and boundary times.
- Another active appointment continues to grant only its intended authority.
- Never-appointed legacy users follow the explicitly accepted compatibility policy.

## SEC-06 — Task details disclose restricted live correspondence to supervisors

**Severity:** Medium · **CWE:** CWE-863

### Evidence and impact

`app/Services/Tasks/TaskPresenter.php:192` emits `mail_origin` to non-system-administrator task viewers. Its current details are serialized around line 198, while only the mail URL is policy-gated around line 209.

A noncustodian supervisor can be authorized through the task reporting hierarchy but denied access by mail policy. If an authorized manager later edits the source mail, the supervisor receives the new live content through the task response. The original description intentionally copied into the task is not the finding. Attachment bytes remain independently guarded; live body and attachment metadata are implicated.

### Recommended fix

1. Apply the mail view policy to the entire `mail_origin` object before serializing any of its fields or relationships.
2. Keep the task's authorized copied description separate from the live source mail. Do not remove intentionally shared task content as a substitute for enforcing source-mail access.
3. Review related presenters and exports for nested objects guarded only by hiding their links. Browser UI hiding cannot protect an already serialized payload.

### Verification

- Create an individual recipient and a noncustodian supervisor who can view the task but cannot view its source mail.
- Edit only the source mail. The supervisor's task response must omit the new body and protected attachment metadata.
- A viewer independently authorized for the source mail receives the intended payload.
- The authorized task description remains available, and direct attachment downloads retain their policy checks.

## SEC-07 — Login errors reveal which staff identifiers exist

**Severity:** Low · **CWE:** CWE-204

### Evidence and impact

`app/Http/Requests/Auth/LoginRequest.php:55` returns an explicit missing-account message, whereas an existing identifier with a wrong password produces a different error. This reveals valid usernames, staff identifiers, or email addresses. Throttling slows enumeration but does not remove the response distinction. Existing authentication tests intentionally expect the specific message, so remediation changes tested UX.

### Recommended fix

1. Return the same public status and failure message for unknown identifiers, wrong passwords, inactive accounts, and locked accounts.
2. Keep detailed reasons in access-controlled operational logs without recording passwords or tokens.
3. Retain throttling and review material timing differences, such as skipping password verification entirely for nonexistent accounts. Do not weaken legitimate abuse controls to normalize responses.
4. Update tests and user-facing help so legitimate users still have an approved support/recovery route.

### Verification

- Compare response status, body, and exposed error fields across all failure cases.
- Test username, employee-number, and email login variants.
- Confirm rate limiting still works and diagnostic logs contain no secrets.

## SEC-08 — Browser push subscriptions permit arbitrary server-side requests

**Severity:** Low · **CWE:** CWE-918

### Evidence and impact

`app/Http/Controllers/NotificationController.php:122` accepts a syntactically valid URL as a subscription endpoint. `BrowserPushService.php:92,110` passes the persisted endpoint to WebPush. The inspected dependency constructs and sends a POST without an application destination restriction.

An authenticated subscriber with valid cryptographic material can cause a notification-triggered server request to a chosen destination, including internal addresses if egress permits. VAPID configuration and a notification trigger are required. The request body is constrained by WebPush encryption; arbitrary response disclosure or cloud-credential theft was not demonstrated.

### Recommended fix

1. Define the HTTPS push-service origins actually supported by the application. Validate parsed scheme, exact host and allowed port against that policy; reject credentials in URLs and avoid naive suffix matching.
2. Enforce the destination policy during delivery as well as registration, including existing subscriptions. Reject private, loopback, link-local, and other prohibited destinations, with IPv4 and IPv6 handling.
3. Protect against DNS changes between validation and connection. Prefer network egress controls plus a transport that connects only to validated destinations; a one-time DNS lookup at registration is insufficient.
4. Disable redirects or revalidate every redirect destination. Bound delivery timeouts and subscription volume.
5. Verify legitimate browser/provider support before rollout and retire subscriptions that fail the new policy. Do not send test traffic to real internal services.

### Verification

- Mock transport/DNS to reject unsupported hosts, deceptive suffixes, non-HTTPS endpoints, private addresses, and redirects outside the approved destination set.
- Demonstrate that re-resolution cannot switch a previously accepted hostname to a prohibited address.
- Confirm a valid notification reaches an approved push endpoint through mocked transport.
- Confirm legacy subscriptions cannot bypass delivery-time enforcement.

## SEC-09 — Default seeding creates privileged users with a fixed password

**Severity:** Low · **CWE:** CWE-1392

### Evidence and impact

`database/seeders/DatabaseSeeder.php:15` calls `UserSeeder`, which creates active demonstration administrator and official accounts with a fixed shared password (`UserSeeder.php:38,44`) and no forced-change flag.

An exposed deployment retaining those credentials would permit account takeover. The seeder explicitly describes these as development accounts and instructs production removal; no live deployment with retained defaults was verified. Existing accounts use `firstOrCreate`, so seeding does not overwrite passwords that have already changed.

### Recommended fix

1. Remove demonstration users from the default production initialization path. Keep demo provisioning in a separately invoked, explicitly environment-guarded development/test seeder.
2. Bootstrap the first administrator through a controlled one-time process using unique, expiring activation, aligned with SEC-01.
3. Review deployed accounts created by demo seeders through an authorized administrative process. Disable or secure confirmed demo accounts and revoke their sessions; do not delete accounts blindly if operational records reference them.
4. Add a release check that detects forbidden demo provisioning/default credentials without printing sensitive values. Guardrails must fail safely rather than rely solely on comments.

### Verification

- Default production initialization does not create active demo users or shared privileged credentials.
- Explicit demo seeding is rejected outside approved development/test environments.
- Bootstrap activation is unique, expiring, and single-use.
- Remediation preserves legitimate account/record relationships and does not reset existing secure passwords unexpectedly.

## Implementation and release checklist

- [ ] Reconcile each finding against the intended checkout and confirm the current security policy.
- [ ] Implement targeted regression tests that demonstrate the unauthorized behavior before its fix and denial afterward.
- [ ] Preserve legitimate role, office, recipient, reporting, and aggregate-metric behavior with positive tests.
- [ ] Review migrations and existing-data handling separately from request-path changes.
- [ ] Revoke affected sessions and activation credentials where required; avoid logging secrets during migration.
- [ ] Validate authentication and authorization changes in a controlled environment before deployment.
- [ ] Verify deployment-specific push egress, session drivers, demo accounts, and activation delivery.
- [ ] Review the patch and run fix verification against the original findings, then scan the final target revision.

## Unresolved policy questions — not reported vulnerabilities

These questions were retained by the scan but did not establish another security finding:

1. Explicit unassignment with a replacement leaves a correspondence recipient active (`AssignmentWorkflowService.php:438–490`). Clarify whether this resolution should preserve access; ordinary reassignment intentionally retains some participants.
2. Reviewing a pending submission can restore a withdrawn holder (`AssignmentWorkflowService.php:156–190`). The reviewer already has relevant authority; clarify the intended interaction with withdrawal.
3. Sidebar logout does not revoke browser push subscriptions while topbar logout does. Define whether logout must stop future device notifications, then implement that contract consistently.

Retention of already-delivered notification text after revocation was rejected as a separate finding: the reviewed evidence did not show new protected information or newly gained authority.
