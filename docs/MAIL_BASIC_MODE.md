# Mail Basic Mode

Implemented in the Appointment Tracking checkout serving `http://127.0.0.1:8080`.
The approved visual reference is `C:\Users\Lenovo\Desktop\WORK\MaiManager Redesign\mail.html`.
Its CSS is scoped to `.mail-basic`; React renders live Inertia data, never the prototype's sample records or localStorage database.

## Mode selection

The application header provides Basic / Full for every Secretary and Permanent Secretary, including their dashboards. In the mail workspace it switches the current register or record. Elsewhere it opens mail home. Secretary accounts initially use Basic; other accounts initially use Full. The URL and a session preference keyed by user ID preserve selection without changing records or permissions. Full remains the existing interface.

## Screen and action mapping

| Basic screen | Existing ATS source/action |
| --- | --- |
| Home | MailboxScope + MailAccessScope counts and latest four incoming records; shared global search searches authorized incoming/outgoing mail, correspondence text, tasks, staff, departments, divisions and workstreams; TaskScope restricts open linked assignments |
| Incoming / outgoing / filed registers | MailRecordController queries, scoped recipient options, global search plus register date/status filters, five records per page |
| New incoming / outgoing | Existing StoreMailRequest and MailRecordService capture routes; duplicate suggestions and explicit override; optional reference; expandable priority, confidentiality, receipt, attachments and configured extra fields |
| Read-first details / Edit | Same MailRecordPresenter payload in both modes; existing mail.update service and audit logging; received date for incoming, sent date for outgoing, ATS register number shown separately |
| Action points | All viewable linked ATS tasks, including multiple forwards. The Add action point form has no Assignee field; the server selects an eligible officer from active mail routing, a linked task, the named staff recipient, or the office supervisor. It uses `mail.assign` or `mail.assign-outgoing`, creates a real task, and sends existing task notifications. If no eligible officer is linked, saving reports a clear error without creating a task. Status starts Assigned and follows the task workflow; each action links to its task. |
| Correspondences | The template's Receiving office, Date recorded and Correspondence details fields post to `mail.updates.store` as an immutable update. Receiving office searches active titles, departments, officers and previously entered custom recipients by full name or shorthand. Selection stores a stable key and displays shorthand; editing the text clears the previous key. A new recipient is remembered automatically in the save transaction. The destination office is stored as a snapshot and linked identity; the selected date is `occurred_at` and server `recorded_at` retains the audit time. Basic Mode displays the supplied text → From Office → To Office → Date, using office shorthand with full-name fallback. Historical participants can see their own saved entries without gaining access to other restricted thread notes. Full Mode retains the complete activity history. |
| Status and dispatch | Shared form in Basic and Full using mail.transition; direction-specific options, PS-only approval/rejection and sensitive-mail archival restrictions retained server-side |
| File / reopen | Existing shared dialogs, policies and services |
| Attachments / Print | Existing protected preview/download routes; print current Basic view or existing authorized complete-record print view |

## Intentional mappings required by the supplied specification

The prototype's standalone action-point records are represented by actual ATS tasks. The Status control shows Assigned at creation because that is the real ATS state; In progress and Completed are reached through the assigned officer's task workflow, so those options cannot be selected during task creation. No parallel action-point store was introduced. Linked selections retain title, user or department IDs as well as historical text snapshots. Custom recipients retain a linked alias and text snapshot. In Basic Mode, adding correspondence to a record currently in the actor’s Incoming mailbox calls the existing Forward service with no task assignment: a single forwarding update carries the Basic entry and changes mailbox placement in the same transaction. Existing forwarding authority and recipient scope checks still apply. Further notes on an already sent record do not create duplicate movements. Full Mode’s normal note workflow is unchanged.

Existing administrator feature settings still govern optional fields. The Basic action-point form always includes the template's required due date and persists it even when the Full Mode forwarding-date field is hidden. View-only, sensitive-mail, office, historical-participant and assignment permissions remain enforced by existing policies. The additive `2026_10_08_000001_add_basic_correspondence_destination` migration stores the office snapshot, and `2026_10_08_000002_create_correspondence_office_aliases` stores custom receiving-office suggestions separately from the official title directory; no database copy is used.

## Verification

Targeted backend tests cover both roles, default mode, per-user preference, capture/edit parity, blank references, outgoing dates, recipient filtering, pagination, linked tasks and notifications, correspondence destination and date, shared older notes, and denied out-of-scope mutations. UI tests cover the switch, capture route and template fields. The user's working database is not seeded or reset.

Commands: `php artisan test --compact`, `npm run test:ui -- --maxWorkers=2 --reporter=dot`, `npx tsc --noEmit`, targeted ESLint, Laravel Pint, and `npm run build`.

The focused Basic Mode and connected-correspondence PHP tests, frontend tests, and TypeScript check pass after this update. The new additive migration has been applied to the local `ats` database.

## Comprehensive Basic improvements (9 October 2026)

The `2026_10_09_000001_link_basic_mail_parties` migration adds nullable department references on mail and title/user/department references on correspondence updates. Existing title/staff capture relationships are reused. Historical records and reference numbers are retained; the migration does not recreate or seed data. Capture and edit forms preserve linked identities, including existing named-officer addressees.

Both Basic search boxes open the shared global search with categorized results and category pagination. Basic correspondence matching observes independent thread access or ownership of the note; hidden notes cannot reveal records through search. Title and correspondence changes invalidate the shared search cache.

The prototype layout remains, but Basic inherits the Full Mode theme tokens, font and appearance preference. The shared Light/Dark/System control is available in its header, alongside the avatar menu for password, security and logout. Correspondence entries show the supplied text, From Office, To Office, and Date.

Automated verification: full backend regression run passed 400 tests (5,528 assertions); after final refinements, 37 focused workflow tests passed (730 assertions). Frontend suite passed 63 tests; TypeScript, targeted ESLint and production build passed. The forwarding tests include PS and department-secretary flows, recipient access, shorthand rename persistence, global search, and transaction rollback. Browser inventory exposes no authenticated app session, so authenticated visual verification remains a manual check.

Local rollout completed: the new migration is applied to the existing MySQL database, all five linked-reference columns are present, and the production frontend assets have been rebuilt. The local app responds at its login page. Final historical-note privacy tests and Basic integration checks passed (17 tests, 224 assertions).

## Reusable external sources (9 October 2026)

In Basic Mode, Incoming Mail source search offers **Add New Source** for an unlisted individual, organization, or office. Add Correspondence has no source field. Its Receiving office search offers **Add New Recipient** for an unlisted name, which can be classified as an office, organization, or individual. The other correspondence fields remain the template's date and details. Entries display the supplied details without a label, followed by From Office, To Office, and Date.

The additive `2026_10_09_000002_create_external_mail_sources` migration stores external incoming-mail sources with a unique case- and whitespace-normalized name and a nullable mail reference. The source is created or reused inside the mail transaction, never during search or validation, so failed saves roll it back. Full Mode's plain external sender workflow is unchanged. The `2026_10_09_000003_link_basic_correspondence_recipients` migration adds a kind to custom recipient aliases and a foreign key from correspondence updates. New recipients are created only inside the correspondence transaction. Basic correspondence uses the recorded actor or forwarding office as From Office and the stored destination as To Office.

When a received item is forwarded into Outgoing, the Basic register and details use the latest visible forwarding event for the current From and To offices and forwarding date. The original source remains below the current From in muted text. Incoming continues to show the original source and addressee, while directly logged Outgoing mail keeps its recorded route. The latest recipient's existing directory label provides the Basic shorthand without altering the full provenance or stored mail record.

Verified with the full backend suite (408 tests), frontend suite (70 tests), TypeScript, lint, and production build. Migration batch 55 was applied to the local MySQL database.
