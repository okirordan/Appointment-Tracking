# Officer names without staff accounts

Incoming mail can be addressed to a newly arrived officer before an administrator creates that person's staff account. Under **To → Recipient details by**, select **Officer Name — save a new name**, search existing saved names, or save and select a new one. The name is available for later incoming mail entries.

Saved names live in `mail_named_officers`, with a normalized unique name and the user who added it. Incoming mail links `recipient_named_officer_id` and keeps `recipient_name` as the historical display snapshot. The server takes that snapshot from the selected directory entry, not from submitted free text.

A saved name does not create a login, grant access, receive notifications, or become an assignment target. When the employee needs to use the system or receive routed work, an administrator must create the staff account and the recorder must select that account from **Officer Name — select an existing staff member**.

Deploy migration `2026_09_28_000001_create_mail_named_officers` before serving the new frontend bundle. `MailNamedOfficerTest` covers reusable names, mail capture, conflicting recipient IDs, and permissions; the picker test covers saving and reselecting a name.
