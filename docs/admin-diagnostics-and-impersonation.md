# Administration diagnostics and support access

The header has one appearance button. Its icon indicates the next mode in the
Light → Dark → System → Light cycle. The existing `ats-theme` browser preference
persists across navigation and sign-ins on the same browser and site.

## Logs

Administration → Logs separates User Activity, Activity Log, System Logs,
Laravel Errors and Failed User Actions. Filters cover user, date range, module,
action, severity and text search, with 25 entries per page. View details shows
the recorded context, including before/after values where the action supplies
them. Existing ordinary-administrator mail isolation remains in effect.

New events include selected full-page views, rejected requests and validation,
Laravel logging events, queue lifecycle events and scheduled task events.
Request bodies, queue payloads and exception argument values are not collected.
Credentials are redacted when recording and displaying diagnostics. New
exceptions retain their type, message, file, line and safe trace locations.
Diagnostics failures do not interrupt the original application operation.

For existing installations, run `php artisan migrate --force`. Optionally run
`php artisan ats:import-laravel-diagnostics` to import recent historical headers.
This scans the newest three Laravel log files, at most 5 MB and 1,000 entries
per file, and deduplicates imported entries. Historical contexts and stack
arguments are deliberately omitted; their safety cannot be established from
unstructured log files. New events are collected automatically.

## Super Admin support access

`super_admin` is a reserved supplementary role for an active System
Administrator. Provision it from the server console using
`php artisan ats:super-admin <username>`; use `--revoke` to remove it.
Browser forms cannot grant this role. Ordinary administrators cannot modify
reserved accounts, including locked, inactive or deleted accounts.

In User Management, find an eligible user and choose **Login As**. A confirmation
explains that the target's permissions and visibility will apply. ATS rotates
the server session, clears previous work mode and password confirmation, and
uses the target identity through the existing authentication and policy system.
No target password is requested or changed. Administrator accounts and accounts
with incomplete password setup are ineligible.

Session locking uses the shared database cache store by default, independently
of the application's read/write cache failover. Redis lock acquisition can fail
after the failover cache has already returned a lock object. `SESSION_BLOCK_STORE`
can explicitly select another reliable shared lock store when required.

A persistent banner identifies the target and provides **Return to Super Admin**.
Administration and account-security changes, including push-subscription changes,
are blocked while impersonating. The original account's privileges are not
available to the target session. Returning verifies the original account's
current role, account state and credential version. If these were revoked, the
session ends at sign-in instead. Disabling the target does not trap the operator.

Support sessions last at most one hour. Expiry is enforced before subsequent
requests. Keep Laravel's normal scheduler running every minute to close audit
sessions even when a browser is abandoned. Signing out closes support without
restoring the original account. Start/end records include actor, target, time
and request IP where available; actions during support carry both identities
and the support record ID. Existing audit records remain append-only.

The migration's rollback preserves support history and the reserved role.
To disable support access, revoke role assignments rather than deleting history.
