# Imported outgoing mail repair

The consolidated outgoing Excel register stored `MailDetails`,
`CorrespondenceDetails`, and source metadata as JSON in `mail_records.details`.
It also stored the original outside sender in the outgoing `From` field, even
though the outgoing movement starts at the PS office. Existing correspondence
notes were hidden from Basic Mode because their import method was excluded.

`mail:repair-imported-outgoing` repairs only imported outgoing rows whose
`details` is a JSON object containing both `MailDetails` and
`CorrespondenceDetails`. It copies the **stored subject** into Details, sets
From to `PS/ES`, resolves known destination title shorthand for To, and routes
the original `CorrespondenceDetails` text into the existing correspondence
note (or creates one when missing). Empty source correspondence text remains
empty. Existing notes keep their import provenance and are not duplicated.
The Basic Mode correspondence list includes these imported notes.

Before changing each mail, the command stores its original JSON, sender and
recipient in `mail_import_payloads`. This retains source metadata such as
`SQLTextDifferences` and the original sender for audit and recovery. The
command is idempotent: normalized rows no longer match. Future staged imports
with this JSON format use the same normalization when confirmed. Plain-text
Details and incoming mail are left as supplied.

After deploying the migration, run `php artisan mail:repair-imported-outgoing`
once for existing data. The command reports the number of corrected rows and
correspondence notes.
