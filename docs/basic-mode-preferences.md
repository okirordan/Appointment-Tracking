# Basic Mode onboarding and preferences

This layer reuses the existing Basic and Full views. It is available to users
who can already switch mail modes, including secretaries and Permanent
Secretaries. It does not grant mail access or change routing permissions.

## Persistence and login behavior

Apply `2026_10_10_000001_add_mail_mode_preferences_to_users.php` before serving
the updated app. Seven fields on `users` store the default, introduction and
Basic usage timestamps, tip preference and dismissal timestamp, login count,
and last tip login. No additional preference table is created.

Successful Laravel Login events initialize a user-specific session choice.
Two-factor challenges do not count until verification succeeds. Existing
authenticated sessions initialize on their first visit; a cache lock prevents
simultaneous first requests from counting twice. Page navigation and refreshes
do not count as logins. Impersonation does not change the target's preferences.

The welcome dialog takes precedence. After it is completed or dismissed,
the saved Basic/Full default applies at login. Missing or invalid defaults use
Full. Ask Me Each Time is captured at login and prompts once for that session.
Changing that setting mid-session takes effect on the next login.

Mode links affect only the current session. Saving a default requires the
welcome checkbox, Make this my default, or the Mode Preferences section of
the existing Settings page, accessible from either profile menu.

## Reminders and forms

An atomic conditional update claims a tip at most once across tabs. Eligibility
starts at login 2 and requires five additional logins after the last claim.
The browser waits one second after loading, does not show tips over a dialog,
and allows dismissal without navigation. Trying Basic permanently suppresses
the chip and tips. Disabling tips persists until explicitly re-enabled.

Mode changes ask before discarding modified form fields. Validation failures
retain the dirty marker; successful form submissions clear it. Reminder
claims and preference updates do not clear unrelated unsaved forms.

Tests: `MailModePreferencesTest`, `MailBasicModeTest`, and
`tests/frontend/mode-onboarding.test.tsx` cover the login sequence, defaults,
temporary choices, ownership, two-factor login, tip claims, accessibility,
settings, and unsaved-work protection.
