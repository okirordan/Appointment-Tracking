# Mail data reconciliation — 24 September 2026

Both HTML printouts contain every SQL record that belongs in their respective report. Incoming HTML contains 16 additional mail records. Outgoing HTML contains 13 additional correspondence entries. No SQL record is missing from the matching HTML, and there are no substantive conflicts in their displayed fields.

| Measure | Incoming | Outgoing |
| --- | --- | --- |
| SQL records | 52,196 | 52,711 |
| HTML records | 52,212 | 52,724 |
| Matched one for one | 52,196 | 52,711 |
| SQL only | 0 | 0 |
| HTML only | 16 | 13 |
| Consolidated CSV rows | 52,212 | 52,724 |

## What each source contains

`Mail print out - Mail Manager.html` contains one Incoming Mail table. `Mail print out - Mail Manager.htm` contains one Outgoing Mail table. Neither file contains both categories. The SQL contains `Mails` and `Correspondances`; every correspondence has a valid `MailId` in `Mails`. The outgoing printout is a routing/correspondence log joined to the original mail, so several outgoing entries can belong to one incoming mail. Its `From` is the original sender, not necessarily the officer dispatching the correspondence. `SentTo` can contain routing outcomes such as “Noted”, not just destinations.

The incoming HTML has From, To, Subject, Received and Ref. The outgoing HTML has From, Received, Subject, Sent to and Date sent. The HTML files omit SQL record IDs and detailed notes. The consolidated files restore incoming `Details` and outgoing `MailDetails` and `CorrespondenceDetails`. `Reference` preserves the source ReferenceNumber/Ref. field, even where it contains an office or designation rather than a reference number. Details absent from HTML-only entries remain blank unless recovered through a unique incoming-record match.

## Method and preservation

Parsed the SQL as text without executing it. Decoded UTF-16 SQL, UTF-8 HTML, HTML entities and doubled SQL apostrophes. Matched incoming rows using all five displayed fields; matched outgoing rows using all five displayed fields after joining SQL correspondence to its mail. Dates were normalized to ISO format. Matching ignores capitalization and runs of whitespace only; it does not use fuzzy subject matching or infer corrected dates. All SQL dates have zero fractional seconds, as verified against the source.

Matched repeated rows one for one, preserving their multiplicity and distinct SQL IDs. The HTML has no IDs, so an individual HTML row cannot distinguish SQL records with identical visible fields. HTMLRow assignments within such groups are deterministic but do not establish which identical printed row belongs to which SQL ID. SQL notes stay attached to their original SQL IDs.

Main text columns use the HTML text with surrounding whitespace trimmed. `SQLTextDifferences` preserves original SQL values wherever raw text differs from HTML; `HTMLWhitespaceValues` preserves HTML values affected by trimming. There are 12 incoming and 4 outgoing matched rows with raw text differences, all equivalent under case/whitespace normalization.

CSV files are UTF-8 with BOM and standard comma quoting. Dates use YYYY-MM-DD, retaining any non-midnight time. CSV has no typed cells; import ID and text columns as text. Source values and anomalous dates are preserved rather than corrected.

## Repeated records and dates needing review

| Measure | Incoming | Outgoing |
| --- | --- | --- |
| Groups with identical visible fields | 229 | 160 |
| Rows in those groups | 475 | 329 |
| Rows beyond one per group | 246 | 169 |

These are possible duplicates, not proven redundant records. No distinct SQL record was deleted. Filter `DuplicateVisibleRowCount > 1` and use `DuplicateVisibleGroup` to review them.

| Flag | Incoming rows | Outgoing rows |
| --- | --- | --- |
| DateSent after analysis date | 0 | 46 |
| DateSent before year 2000 | 0 | 57 |
| Incoming link ambiguous | 0 | 1 |
| No SQL record for this entry | 16 | 13 |
| Received after analysis date | 31 | 30 |
| Received before year 2000 | 205 | 217 |
| Repeated visible fields (preserved) | 475 | 329 |
| Sent before received | 0 | 851 |

Date flags use 24 September 2026 as the analysis date and year 2000 as a review threshold, not a correction rule. Flag counts overlap. ReviewFlags and raw date columns identify every affected row. Some source dates have years such as 0202, 0205 and 2926. Dates that precede receipt may also reflect original entry errors.

## Records present in incoming HTML but absent from SQL

| Consolidated ID | Received | From | To | Reference | Subject |
| --- | --- | --- | --- | --- | --- |
| HTML-INC-050793 | 2026-08-31 | MAJ | ccPS/ES | C/SE | Brief on the declining humanitarian financing for ugandas refugee response and intention to use USEEP funds |
| HTML-INC-052167 | 2026-09-23 | Alice Nyiramahoro | PS/ES | Chief Commissioner Uganda Girl Guides Association | Notification and request for Clarence of the Uganda Scouts delegation to Participate in the Eastern Africa Zonal Scouts Competitions, Conference and Zonal Youth Forum in Kibaha, Coastal region, Tanzania from 15th to 19th December 2026 |
| HTML-INC-052168 | 2026-09-23 | Alice Nyiramahoro | cc PS/ES | Chief Commissioner Uganda Girl Guides Association | Follow up on Scouts File Uganda Vs Barugahare Mujuni & Others File No. CID E 200-25 |
| HTML-INC-052169 | 2026-09-23 | Bill Nkeeto | PS/ES | Academic Registrar Victoria University | Request for Permission to Conduct Student Field Trips to Albertine Region |
| HTML-INC-052170 | 2026-09-23 | Charles Ouma | PS/ES | Deputy Solicitor General | Draft Contract for Design, Develop, Train Implement and Commission an Online Scholarship Information Management System [SIMS] for the Ministry of Education and Sports [MoES] and ASSA Department: PR MoES/CONS/2025-2026/00008 |
| HTML-INC-052171 | 2026-09-23 | A.D Kibenge | PS/ES | Permanent Secretary, Ministry of Gender, Labour and Social Development | Submission of the Uganda Jobs for Youth Labour Force [UJOY] Concept Paper and Minutes |
| HTML-INC-052172 | 2026-09-23 | Edith N. Mwanje | PS/ES | Permanent Secretary, Ministry of East African Community Affairs | Call for Participation in 4th Edition of EAKC Mobility Program for Kiswahili Stakeholders FY, 2026/27 |
| HTML-INC-052173 | 2026-09-23 | Katusiimeh Mesharch | PS/ES | Kabale University | Invitation from Kabale university |
| HTML-INC-052174 | 2026-09-23 | EN | PS/ES | PHRO1 | Submission of september 2026 monthly salary payroll |
| HTML-INC-052175 | 2026-09-23 | Mpuuga Constantine Sajjabbi | PS/ES | Headteacher- Namilyango | Request for permission to undertake an exchange visit to the united kingdom |
| HTML-INC-052176 | 2026-09-23 | EN | PS/ES | PHRO1 | Submission of September 2026 monthly pension payroll |
| HTML-INC-052177 | 2026-09-23 | RP | PS/ES | HRO4 | Report after attending a first meeting of the national steering committee on decent work country programme III that took place on 17th September 2026 at Admas hotel Entebbe |
| HTML-INC-052178 | 2026-09-23 | Dr Mugisha Annet K | PS/ES | CTETD(ai) | AI enabled watsapps teacher training model |
| HTML-INC-052179 | 2026-09-23 | MAJ | PS/ES | CSE | Request for guidance on requests regarding charges of PTA school feeding and related matters on fees |
| HTML-INC-052180 | 2026-09-23 | MAJ | PS/ES | C/SE | Request for guidance on requests regarding charges of PTA school feeding and related matters on fees |
| HTML-INC-052181 | 2026-09-23 | Amb. John L. Mugerwa | cc PS/ES | For Permanent Secretary, MoFA | Recall From Tour of Duty Mr. Baker Balunywa |

Two new MAJ entries for guidance on PTA/school feeding fees have the same sender, date, subject and recipient but different reference values (`CSE` and `C/SE`). Both are retained for review.

## Entries present in outgoing HTML but absent from SQL correspondence

| Consolidated ID | Received | Sent | From | Sent to | Subject | Incoming link |
| --- | --- | --- | --- | --- | --- | --- |
| HTML-OUT-000050 | 2026-09-23 | 2026-09-23 | Mpuuga Constantine Sajjabbi | AC/GSE-Moses | Request for permission to undertake an exchange visit to the united kingdom | Unique From + Subject + Received match |
| HTML-OUT-000051 | 2026-09-16 | 2026-09-23 | Ben Kumumanya | Mbabazi Peninah | Submission of Head Teacher, Bumadu Seed Secondary School-Ms. Peninah Mbabazi  in Bundibugyo District for Disciplinary Action | Unique From + Subject + Received match |
| HTML-OUT-000155 | 2026-09-08 | 2026-09-21 | Irene Kauma | Hon ronald aled akugizibwe | Coding and grant aiding of schools in masindi district | Unique From + Subject + Received match |
| HTML-OUT-000156 | 2026-04-28 | 2026-09-21 | The Rt Rev. Gaddie Akanjuna | CAO- Kabale | Bugongi Primary School | Unique From + Subject + Received match |
| HTML-OUT-000157 | 2026-04-29 | 2026-09-21 | Higenyi Samson | CAO- Budaka | Request for Funding for Infrastructure Development | Unique From + Subject + Received match |
| HTML-OUT-000158 | 2026-08-27 | 2026-09-21 | Irene Kauma | CAO- Nakaseke | Priority areas requiring support from the hon minister of education and sports | Ambiguous |
| HTML-OUT-000159 | 2026-08-31 | 2026-09-21 | Kallra Al-Khamis | CAO- Mayuge | Reminder/Follow up on Fencing Proposal | Unique From + Subject + Received match |
| HTML-OUT-000160 | 2026-08-31 | 2026-09-21 | Lunialo Abdul Maliki | CAO- Sironko | Request for Infrastructure Development and Support Premised on the Auditor General's Report for the Year Ended 31st December 2024 Bumasifwa Seed Secondary School | Unique From + Subject + Received match |
| HTML-OUT-000161 | 2026-09-01 | 2026-09-21 | Musinguzi Venansio | CAO- Mbarara | Reminder of the Multipurpose Hall Pledge by the First Lady | Unique From + Subject + Received match |
| HTML-OUT-000162 | 2026-07-08 | 2026-09-21 | Kamba Amir | Deputy RCC | Request for Infrastructural Development at Gulu Army Secondary School Under Use Program | Unique From + Subject + Received match |
| HTML-OUT-000163 | 2026-07-16 | 2026-09-21 | Seera Margaret Kolya | CAO- Namisindwa | Request for Funds to Construct a Multipurpose Hall at Namisindwa Secondary School | Unique From + Subject + Received match |
| HTML-OUT-000164 | 2026-09-03 | 2026-09-21 | Obwona Haxvier Morris | Town clerk-Lira | Request for Coding and Grant aiding of Alwak Primary School EMIS No. 200102 | Unique From + Subject + Received match |
| HTML-OUT-020563 | 2026-08-06 | 2025-09-22 | C/HRM [ai] | Ivan Odeke | Request regarding Assignment of Procurement Officer at AFCON | Unique From + Subject + Received match |

Of the 13 additional outgoing entries, 11 link uniquely to existing SQL incoming records, one links uniquely to an HTML-only incoming record, and one has an ambiguous link to two SQL incoming records. A link inferred from From + Subject + Received is labelled as such. It does not imply an outgoing SQL record exists.

The ambiguous entry is HTML-OUT-000158, “Priority areas requiring support from the hon minister of education and sports”. Candidate incoming IDs are `d0f2d188-97e5-4cc2-01fc-08df072c9b21` and `85b9a30f-6633-4b46-01fe-08df072c9b21`. Its MailId and IncomingRecordId are left blank. CandidateIncomingIds retains both possibilities.

## Using the CSVs

Filter `SourcePresence = HTML only` to see the 16 incoming and 13 outgoing additions. `SQL and HTML` means the entry exists in both its SQL table and relevant printout. SQLRow is the one-based INSERT ordinal across the SQL file, not a physical line number. HTMLRow is the one-based data row within the named HTML table, excluding the header. ConsolidatedId equals the SQL ID when present; IDs beginning HTML- are local consolidation identifiers, not original database IDs. Outgoing IncomingRecordId links to incoming ConsolidatedId. Blank fields indicate unavailable data, not fabricated values.

## Validation

All 104,907 SQL INSERT records parsed with the expected column counts and validated value syntax. All 104,936 HTML data rows parsed as five-cell rows. SQL IDs are unique within each table. There are no orphan SQL correspondence links. Every SQL record and its detail notes is represented in the consolidated outputs. Every HTML row is represented once. Both saved CSVs were reopened and compared field for field to the prepared data; row counts, unique ConsolidatedIds and populated cross-file links passed.

## Source fingerprints

| File | Bytes | SHA-256 |
| --- | --- | --- |
| script.sql | 71985850 | a200e40d1ce7b7f27fb25149b21f9bfed415de739b511d5f49d12a33cb24f078 |
| Mail print out - Mail Manager.html | 18292927 | 5e5c57c4ebd9db6ba4d7fb7a57f7d9cc5ede557ad0ae9ad2c9abf3df7cb6cbfb |
| Mail print out - Mail Manager.htm | 22044523 | 0d19db2d9ccf1ec45cf380fb244e67bfacb993e1ce48ba1e132e4498592e2e7f |
