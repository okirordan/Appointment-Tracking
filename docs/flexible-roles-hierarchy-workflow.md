# Flexible roles, hierarchy, and assignment workflow

## Existing-system assessment

The original ATS already had useful foundations: Spatie role tables, task history, audit logs, in-app notifications, scoped task queries, soft-delete columns, evidence attachments, mail-to-task linking, dashboards, and reporting. Its limiting assumptions were a six-value role enum, route middleware tied to those names, one current assignee column, and progress reporting directly to the original assigner.

The enhancement preserves all existing URLs and compatibility columns. The legacy `users.role`, `tasks.assigned_by_user_id`, `tasks.assigned_to_user_id`, and task-history records remain available to existing reports and integrations. New behavior is layered over those fields.

## Architecture and database changes

- `roles` now stores display name, description, hierarchy level, active state, and system-role protection.
- Permissions are grouped and described for the role-management interface.
- `organizational_units` supports institution, directorate, department, division, section, and unit records in a parent-child tree.
- `positions` connects a configurable role to a unit, hierarchy level, supervisor position, and workflow capabilities.
- `user_positions` records substantive and acting appointments, reporting users, and effective dates.
- `user_delegations` records temporary delegation during leave or absence.
- `user_profile_changes` preserves field-level before/after values, administrator, time, and reason.
- `user_lifecycle_events` preserves deletion, restoration, and related reasons.
- Tasks now distinguish creator, owner, current assignee, responsible officer, current reviewer, and final approver, with separate execution, review, and approval statuses.
- `assignment_workflow_steps` stores the actual delegation route. Each step records sender, recipient, position, parent, sequence, instructions, due date, state, direct-route flag, and review result.
- `assignment_submissions` and `assignment_reviews` implement reverse-route reporting, approvals, returns, rejections, information requests, and revised deadlines.
- `assignment_participants` preserves creator, owner, assignee, copied, and observer relationships without duplicating tasks.

## Workflow behavior

Task creation creates the first workflow step. Delegation closes the current step and appends a child step to the same task. Direct assignment uses the same mechanism and records the direct-route flag. Submission goes to the sender of the actual current step. Approval forwards the submission to the parent step; final approval at the root completes the task. Return or information-request actions reactivate the same execution step. Reassignment changes the active recipient while preserving the former participant, task history, lifecycle reason, audit record, and notifications.

Existing tasks are backfilled into a one-step workflow during migration. No duplicate tasks are created.

## Authorization and organizational scope

New functionality uses named permissions rather than role-name routing. A compatibility middleware retains access for existing built-in accounts while permission assignments take over for configurable roles. Task scope includes actual workflow participants and current reviewers. Configured users are limited by their active position, organizational unit, reporting line, and assigned permissions; legacy accounts retain the previous department rules.

Deactivated roles block login. Soft-deleted or inactive recipients cannot receive new workflow steps. Roles assigned to active users or positions cannot be deactivated or deleted.

## User interface

- **Roles & Permissions:** create and edit roles, hierarchy levels, descriptions, and permission groups; activate/deactivate safely.
- **Organization Hierarchy:** manage units, positions, supervisor positions, appointments, acting appointments, and temporary delegations.
- **User Profile:** edit approved fields, inspect placement and reporting line, view field-level history, soft-delete with reassignment, and restore with a reason.
- **Assignment Workflow tab:** view ownership, separate statuses, actual delegation route, direct/skipped route indicators, current stage, review history, and actions for delegation, submission, review, and reassignment.

All destructive or consequential actions use in-app modals and validation. No browser alerts, prompts, or confirmation boxes were introduced.

## Notifications, audit, and reporting

Notifications cover assignment creation, delegation, submission, review decisions, reassignment, approaching deadlines, and overdue work. Relevant current holders, reviewers, owners, and assigners are deduplicated before deadline notices are created.

Audit records capture role and permission changes, hierarchy changes, appointments, delegations, user profile changes, deletion/restoration, workflow route changes, submission, review, and reassignment. Report data and CSV exports include execution/review/approval statuses and the actual delegation route. Workflow summaries include created, assigned, awaiting-review, returned, direct-route, and average-route-level metrics.

## Fresh-install and backward-compatibility strategy

`DatabaseSeeder` remains restricted to role configuration, required departments, and users. No task, mail, workstream, attachment, import, notification, or sample operational data is seeded. The migration is additive and backfills existing assignments transactionally. Existing route names, mail links, task references, evidence records, dashboards, and report entry points are retained.

## Verification

- Production frontend build succeeds.
- All 108 automated tests pass with 984 assertions.
- New coverage includes role/permission editing, user profile history, soft deletion/restoration, hierarchy appointments, cascading delegation, reverse-route approval, direct assignment, and return for correction.
- MySQL migration and configuration seeding complete successfully.

## Assumptions and follow-up recommendations

- Lower hierarchy numbers represent more senior positions; equal levels are supported.
- A user has one primary active position and may have additional historical or acting appointments.
- Attachments continue to use the existing evidence subsystem, so a user attaches evidence before submitting or while adding progress.
- The current implementation supports one active route branch per assignment. If parallel execution branches become an operational requirement, add a route-branch identifier and branch-completion policy rather than duplicating the task.
- For very large institutions, add cached organizational-unit descendant paths and queued bulk reminders after real production volume is known.
