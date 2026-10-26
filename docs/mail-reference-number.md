# Mail reference numbers

New mail and mail editing forms include an optional **Reference No.** field in both Basic and Full modes. It accepts up to 255 characters and uses the existing `mail_records.correspondence_reference` column. It is separate from the system register number.

Incoming lists and mail details display the reference, or **Not provided** when blank. Reference numbers are always available; the former Correspondence Reference display setting no longer controls capture, editing, or viewing. Other optional mail settings are unchanged. No database migration is needed.

`MailBasicModeTest` covers capture, viewing in both modes, and both update routes. `CorrespondenceFeatureSettingsTest` covers capture with optional features disabled. `mail-basic-mode.test.tsx` covers the optional form field and its value.
