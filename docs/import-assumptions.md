# ATS recurring import contract

The importer accepts recurring exports from Microsoft Access, Microsoft Excel, and MySQL. It does not connect directly to those systems: an administrator exports one entity per CSV or XLSX file and stages that file in ATS.

## Approved assumptions

- Files may approach 2 GB and contain more than 1,000 rows, so reading and persistence are chunked.
- Stable staff IDs may be available, but user account provisioning is excluded. Staff/account creation remains a separate audited workflow.
- Source records may update previously imported records. `source_system + external_id` is the primary idempotency key.
- Task descriptions are confidential. They may be stored in private staging for validation but are never written to logs or shown in validation summaries.
- The timezone for due dates is `Africa/Kampala`.
- CSV and XLSX are supported. Access and MySQL should export canonical CSV/XLSX rather than providing database credentials.

## Duplicate rules

- Department: source external ID, then department code; name-only matches require review.
- Division: source external ID, then department and division code.
- Workstream: source external ID, then type and exact normalized name.
- Task: source external ID, then unique task reference.
- People must never be matched by display name. A stable staff ID is required when task assignment is imported.

Only a fully valid, explicitly confirmed batch may update operational tables. Confirmation runs transactionally and is audited without raw confidential row data.
