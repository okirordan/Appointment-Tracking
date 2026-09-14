# Mail origin and movement

The original mail and its movements are separate records. `MailProvenance` resolves older outgoing copies through `source_mail_record_id`, then uses the canonical originating mail. Forwarding does not update the original sender, source organisation or original addressee. New copies made by the older assignment service also retain the original source fields.

## Display contract

Mail rows, mail details and authorised assignment details expose `provenance`:

- `original_source`, `original_source_organisation`, `original_addressee`: values on the original mail.
- `received_by`: the office or department which first registered the mail.
- `received_through`: the original receiving office and distinct forwarding offices. This is an office summary, not a representation of branches as a single serial route.
- `current_locations`, `current_handlers`: active primary routing destinations and assignment holders. CC recipients are excluded. A later movement out of a destination supersedes its old location even when access is retained. Current assignment workflow steps and review ownership are considered. Closed, filed and withdrawn records have no active handling destination.
- `latest_forward`: forwarding office, receiving destinations, actual actor and forwarding timestamp. Multiple primary destinations remain a list.

`movement_timeline` contains chronological registration, forwarding, system receipt, assignment/delegation, notes, status changes and removal events. Each movement identifies its source, destination, actor and instructions where recorded. The receiving date is displayed as a date rather than inventing a receipt time. Automatic delivery/receipt is distinguished from an officer's acknowledgement.

The incoming register, correspondence list, mail drawer, linked assignment and print view expose this information. Existing notes and attachment history remains available.

## Historical integrity and access

Migration `2026_09_11_000002_preserve_forwarding_identity` adds nullable snapshots of forwarding actor/office, receiving office, and assignment-step sender/recipient identity and office. These are populated when new movements are created, including documentary cross-department movements. Later directory edits do not relabel those movements. Older entries fall back to existing records; historical names that were never captured cannot be reconstructed reliably.

Secretary forwarding identifies the secretary as the actual actor and uses their current office/supervisor for representation, rather than borrowing the original mail's PS supervisor. A departmental actor with legacy department placement uses that department as the forwarding office.

Existing access rules still apply. A viewer with access only through a reconstructed departmental interaction sees permitted interactions, not unrelated normal forwards or legacy-copy instructions. Assignment events are restricted to assignments the viewer may view. No new recipient access is granted by the presentation layer.

## Verification

`MailProvenanceTest` exercises PS → LEIT → another office, preservation of source fields, old outgoing copies, assignment details, CC exclusion, directory renames, historical-access filtering, delegation, same-time moves, and closed/withdrawn records. `mail-provenance.test.tsx` verifies distinct labels, register context and safe rendering of movement instructions.
