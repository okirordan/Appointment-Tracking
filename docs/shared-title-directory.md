# Shared officer and department title directory

Administrator recipient shorthands and the titles used to record or forward mail previously lived in separate directories. `annotation_titles` now supplies the shared designation and primary abbreviation; each `recipient_aliases` routing entry links to it through `annotation_title_id`.

## Behaviour

- Administrators manage shared titles and recipient links on the recipient shorthands page. Titles added by secretaries appear there immediately.
- Recording and forwarding title pickers search the shared designation, primary abbreviation and active alternative recipient abbreviations.
- Adding an existing abbreviation or designation reuses its title. Alternative abbreviations for the same designation link to the existing title instead of creating duplicates.
- Secretaries can add missing officer or department titles using **Abbreviation** and **Full designation**. The new title is selected without submitting the enclosing correspondence form.
- Actual recipient searches recognise linked shared titles while retaining existing recipient permissions and office scope.
- Selecting a title alone remains a documentary record. It does not create an officer account or automatically assign work or send notifications. An administrator must link a real recipient for routing.
- Disabling a shared title removes it from title searches and disables its linked shorthand matches in recipient searches. It does not disable the underlying officer account. Editing or toggling a recipient alias cannot override an explicit administrator title disable.
- Turning off the last active recipient alias hides its linked title. Administrators can explicitly reactivate the title for documentary use without enabling its routing aliases.

## Data and maintenance

`SharedTitleDirectory` resolves existing titles and links recipient aliases. The administrator alias controller and recipient alias seeder use this service. New code that writes recipient aliases should also call it in the same transaction.

Migration `2026_09_11_000001_link_recipient_aliases_to_shared_titles` adds the link and the explicit administrator-disable flag, then links existing aliases. Existing title IDs, labels and correspondence references are retained. Aliases whose target no longer exists cannot infer a designation and are skipped. Migration rollback removes the linking columns but retains created titles because correspondence may subsequently reference them.

The picker also prevents Enter from accidentally submitting the mail form, allows normal focus in the designation input, and ignores stale search responses.

## Verification

`SharedTitleDirectoryTest` covers administrator/secretary visibility, alternative abbreviations, shared multi-target codes, deactivation, recipient search and migration preservation. `annotation-title-picker.test.tsx` covers creation, focus, Enter handling and stale searches. Existing correspondence tests protect assignment and forwarding behaviour.
