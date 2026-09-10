# Spec: PS Office Cross-Department Recording

## Objective

Allow explicitly permitted Office of the Permanent Secretary users to reconstruct real correspondence movements between the PS Office and one internal organizational unit on the existing master correspondence. Preserve the represented business office separately from the authenticated recorder, keep a reliable current holder, expose only participating-unit history to departmental viewers, warn on likely duplicates, notify destination offices, and retain both occurrence and system recording timestamps.

## Tech Stack

- Laravel 13 / PHP 8.2+, Eloquent, Inertia
- React 19 / TypeScript / Vite
- Spatie Laravel Permission
- PHPUnit feature tests and Vitest UI tests

## Commands

- Focused backend: `php artisan test tests/Feature/PsOfficeCrossDepartmentRecordingTest.php`
- Full backend: `php artisan test`
- Frontend: `npm run test:ui`
- Type check: `npx tsc --noEmit`
- Format check: `npm run format:check`
- Build: `npm run build`

## Project Structure

- `database/migrations/` — additive audit/current-holder schema and permission migration
- `app/Http/Requests/Mail/` — boundary validation and authorization
- `app/Services/Mail/` — recording, duplicate detection, notifications, and audit orchestration
- `app/Http/Controllers/Mail/` — thin web endpoint
- `app/Models/` — relationships and casts
- `resources/js/pages/mail/` — quick recording form and filtered timeline
- `tests/Feature/` — permission, movement, visibility, audit, notification, and duplicate coverage

## Code Style

```php
public function canRecord(User $user): bool
{
    return $this->enabled()
        && $user->can(self::PERMISSION)
        && $this->isPsOfficeUser($user);
}
```

Use typed service boundaries, Form Request validation, transactions for state changes, additive schema changes, existing design-system components, and explicit audit metadata.

## Testing Strategy

- Feature tests drive authorization abuse cases and each PS ↔ department transition.
- Database assertions distinguish `occurred_at` from immutable `recorded_at` and the represented office from the authenticated recorder.
- Inertia assertions verify current-holder and viewer-filtered history contracts.
- Existing correspondence, organization-boundary, notification, reporting, and frontend suites guard regressions.

## Boundaries

- Always: require the feature flag, dedicated permission, PS Office membership, normal mail visibility, validated organizational units, transactions, and real authenticated recorder IDs.
- Ask first: new external integrations, global visibility bypasses, destructive migrations, or automatic impersonation.
- Never: create duplicate master mail records, accept a client-supplied recorder, rewrite `recorded_at`, expose another department's represented interactions, or silently discard likely duplicates.

## Success Criteria

- Disabled by default and manageable in administrator correspondence settings.
- Only a PS Office member with `ps_office_cross_department_recording` can use the endpoint and UI.
- Repeated PS ↔ department cycles remain on one correspondence and update its current holder.
- Department participants keep historical access while only seeing reconstructed interactions involving their scope.
- Each entry records from/to/represented units, actual recorder, entry method, occurrence time, recording time, status transition, optional responsible officer/assignment, attachments, and audit metadata.
- Likely duplicates require an explicit confirmation after presenting the matching entry.
- Destination office users receive wording that identifies the PS Office as the recorder.
- Closed/filed records preserve the complete authorized timeline.

## Open Questions

- None blocking. The duplicate window is 30 minutes and text similarity is conservative; users can explicitly confirm legitimate repeats.
