# Shared positions and named officer routing

A position is a reusable designation, not an individual officer. Its current appointments may include several active staff members. Recording or assigning work does not create another position for each officer.

Staff searches resolve names, position titles and shorthand from both the administrator's recipient aliases and the shared officer-title directory. Results identify individual staff members and show their organisational context. Selecting a heading does not assign its occupants. Users explicitly select each officer; removable chips show the selected identities.

Incoming and outgoing recording, forwarding, task creation, delegation, reassignment and outgoing follow-up accept named staff selections. A shared task remains one task with separate workflow responsibilities and notifications. Original mail addressees are canonical recipient records without a forwarding event; subsequent forwards add separate movement records. Office/title-only correspondence metadata remains available for letters that do not name individual officers.

Reassignment requires the current officer to be identified when several officers are handling a task. It closes that officer's current step and creates replacement steps, preserving previous names, titles and organisation snapshots. Other officers' responsibilities remain current. Submission and approval follow each officer's step; a shared assignment cannot be closed through one officer's progress update. Overall progress averages the current branches, with approved contributions at 100 percent.

Workflow titles, roles, departments, divisions and offices are captured when a step is created. Later staff transfers do not rewrite these snapshots. Older entries without a historical title are not backfilled with today's position, which could misrepresent their history.

Run migrations `2026_09_15_000002_preserve_workflow_officer_positions` and `2026_09_15_000003_allow_original_mail_addressees` before serving the updated application. The latter makes the forwarding reference optional for original addressees. Its rollback deliberately refuses to discard or fabricate forwarding history when original addressee records exist.

Regression coverage includes shared-position shorthand lookup, individually selected mail recipients and their access, one-task outgoing follow-up, parallel delegation and review, reassignment history, individual progress, and keyboard-accessible multi-selection. Existing organisational authorisation is applied to every selected officer.
