# GigHub database decisions and provenance

Status: accepted development database foundation following isolated MariaDB 10.4.32 runtime validation. Prompt 07 authorizes documentation/PHP foundation only, not another database/import, application connection, SMTP, uploads, accounts or credits. Retain gighub_schema_test unchanged.

## Source and authority

- Current instructions: the user's 14 approved revised decisions and exact 20-table list, supplied on 2026-10-30. These override the report and earlier assistant proposals.
- Report inspected: `C:\Users\Acer\Downloads\Project I Portfolio.pdf`, 130 PDF pages.
- Source SHA-256: `3674427c1ba428e6fc4f3d353413cabe5ff76c9294b6e2e08781027bca3636f4`.
- Page references below are printed report pages; add two for the PDF viewer page number (ERD printed 86 = PDF 88).
- Schema: `database/schema.sql`; full dictionary, state machine, ownership rules and transactional invariants: `docs/database-design.md`.
- The report is evidence of intended features, not proof that its ERD, statuses or cardinalities are correct. No report PDF was edited.

## Report-derived requirements retained

| Report evidence | Requirement retained | Tables / treatment |
|---|---|---|
| Chapter 1, section 1.4.1, printed p10 | Clients/freelancers, verification, profiles, portfolio images/descriptions and skills | users, role-specific profiles, email_verification_tokens, uploaded_files, skills, user_skills |
| Section 1.4.1; Post/Browse/Submit/Review use cases; functional requirements section 3.4.1 | Job listings, deadlines, budgets, bids, one proposal per freelancer/job | jobs, job_skills, bids; UNIQUE(job_id,freelancer_id) |
| Award/Fund use cases, Tables 3.5-3.6, pp55-56; sequence Figures 4.2-4.3 | Selected bid price funds escrow before work starts | jobs.awarded_bid_id, escrows, wallets, wallet_entries; ordering clarified by approved change below |
| Tables 3.8-3.9, pp58-59 | Final work review, revisions, full payment on approval | work_submissions; under_review -> completed only with settlement |
| Milestone use cases, Tables 3.13-3.14, pp63-64 | Freelancer submits tasks; client approves/rejects with feedback | milestones, work_submissions, uploaded_files |
| Cancellation Table 3.10, p60; refund functional requirement | Open-job cancellation; mutual agreement required after funding | jobs, cancellation_requests, escrow refund |
| Dispute Table 3.11, p61; Figure 4.5; security section 4.5.4 | Admin evaluates evidence and resolves funded conflicts | disputes, chat_messages, submissions and settlement source |
| Send Message Table 3.12, p62 | Permanent private project discussion linked to an active bid or contract | chat_messages, now bid-specific |
| Section 1.4.1 | Both participants review each other after completion | reviews; compute rating aggregates, no stored average |
| Multiple Chapter 3 use-case steps | Notify parties of bids, funding, work and review events | notifications, simple in-app rows |
| Section 4.4.1-4.4.3, pp96-99 | Jaccard skill-set matching, 20% threshold, empty union score zero | skills/user_skills/job_skills; calculated, not stored |
| Section 4.5, pp100-103 | Password hashing, server-side role/ownership checks, PDO parameters and output encoding | password_hash VARCHAR(255); service responsibilities documented |
| Section 4.7, pp122-124 | Transaction receipts, earnings statements and dispute summaries | Ledger timestamps/source/actor, job and user references; no extra report tables |
| Section 1.4.2, p10 | No live payment APIs, banks, cards, legal contracts or repository execution | No real payment/card/bank data; files are inert private uploads, never executed |

## Approved changes, distinguished from assumptions

All decisions in this table were expressly approved by the user. Details not explicitly approved are recorded in the following assumptions/open-issues sections.

| # | Approved decision | Schema consequence / deviation from report |
|---|---|---|
| 1 | One fixed Client/Freelancer role; separately created Admin | One role per users row; one matching profile. No configurable Roles table. Role/profile consistency and admin-only provisioning are application rules. |
| 2 | Real SMTP verification, expiring single-use hashed tokens | email_verification_tokens holds only SHA-256 hashes, expiration and used/revoked times. No raw token or SMTP password column. This strengthens Chapter 1's basic verification. |
| 3 | One winning freelancer per job | jobs.awarded_bid_id is canonical; same-job composite FK prevents selecting another job's bid. |
| 4 | Selection and funding are separate | Job becomes awarded on selection, remains awarded after funding failure, enters in_progress only after successful funding. This overrides UC005's rollback-to-open wording and the earlier assistant's combined-operation recommendation. |
| 5 | Entire agreed amount locked; one final payout after all work approval | Exactly one escrow per job, created locked only after successful funding. No partial payments or monetary milestone amounts. |
| 6 | Simulated wallets, immutable history, no real payments | wallets + append-only wallet_entries, UPDATE/DELETE rejection triggers, unique settlement marker; no banking/card model. |
| 7 | Milestone and final submission histories with approvals/revisions | work_submissions retains each attempt, review feedback/actor/time; milestone states include submitted, revisions_requested and approved. |
| 8 | Real uploads, private storage and metadata | uploaded_files targets exactly a freelancer portfolio or work submission; storage key, detected MIME, bytes and digest, not BLOBs/public paths. Overrides the earlier links-only recommendation. |
| 9 | Bid-specific private messaging | Chat references bid_id, which determines the client/bidder pair. Replaces ambiguous job-only chat. |
| 10 | Full refund or full payout disputes, no partial settlement | decision enum refund_client/pay_freelancer; one terminal settlement. Removes UC011's percentage-split alternative. |
| 11 | Funded mutual cancellation needs counterpart consent | Requester/responder/status/times recorded; service checks the responder is the opposite job participant. |
| 12 | Two-way reviews after normal completion | One review per job/reviewer, rating 1-5; participant and completion checks in service. Disputed/cancelled closure does not qualify. |
| 13 | Simple in-app notifications | notifications table; no notification-delivery infrastructure or extra email use cases implied. |
| 14 | Preserve Jaccard skill matching | Keep canonical skills and distinct junction sets; retain report formula and threshold. No persisted score or ML subsystem. |

## Additional implementation assumptions (proposed, not user approvals)

1. Public registration selects role before creating the account and matching profile in one transaction. Prompt 10A explicitly defers wallet/credit and verification-token creation; no authenticated session or SMTP is added. A normal account becomes active only after verified email. Admin setup must also satisfy the verified-email check before activation. No identity-confirmation field or identity-verification workflow is included. Email verification confirms mailbox control only; it does not verify real identity.
2. Use one advertised budget amount, matching the existing dictionary, rather than Chapter 1's budget range. Bids may have any positive amount; bids above or below advertised budget are now expressly approved; budget is not a bid cap. Agreed escrow amount is the selected bid amount, not the advertised budget.
3. Add draft jobs and draft work submissions. Job publication is explicit. Submission drafts allow files to be uploaded before a submitted attempt is frozen.
4. At least one milestone must exist before funding; all milestones must be approved before final submission. The report's warning-only premature request is strengthened. Define/freeze milestones and contractual scope at selection; no funded scope changes.
5. Unfunded awards can be withdrawn/reselected by reopening the job or cancelled by the client. No expiry/automatic funding timeout is implemented. No freelancer acceptance step is added; selection is the client's decision as in the report.
6. Bid status remains pending during selection, accepted only after funding, and rejected for unsuccessful bids after funding/cancellation. The status is a synchronized UI mirror; jobs.awarded_bid_id is always authoritative. SQL does not enforce all possible consistency combinations of the mirror label and job state. The design document now specifies unfunded reopen/cancel behaviour, funded historical labels and mirror reconciliation; replacing the mirror with derived status remains unresolved, not silently approved.
7. One bid per freelancer/job for the lifetime of the job; no withdrawal, deletion or resubmission. At most one submitted attempt per milestone/final context is enforced under a job lock by the service, not a conditional unique SQL index.
8. One dispute and one mutual cancellation request per funded job. Either participant can request cancellation or raise a dispute. Cancellation rejection permits normal work or escalation. A pending cancellation freezes reviews/payment; dispute escalation supersedes a pending request. No repeated requests, appeals or dispute reopening.
9. Simulated credit uses one demo_credit entry per wallet, enforced by uniqueness. Amount is now approved as RM10,000 once per Client; controlled issuance/audit mechanics remain an implementation choice. No seed data or credit feature is created. No fees, withdrawals, negative balances, transfers between arbitrary users or manual adjustments.
10. uploads have pending/active/quarantined/deleted metadata states. Portfolio files are individual profile items (title/description optional), avoiding a 21st portfolio-project table. Submission files belong to their author. Private storage/download authorization, validation and filesystem compensation are service responsibilities. submitted evidence must be retained, not silently replaced/deleted.
11. Portfolio visibility is to authenticated marketplace users through a controlled endpoint; clients see only submitted deliverables for their awarded jobs, not draft work. Admin evidence access is restricted to authorized moderation/dispute review. Exact retention and visibility policy remains open.
12. Original messages, submitted content and settled cases are not editable by ordinary users. Losing-bid conversations become read-only after funding; terminal job conversations are read-only. During dispute, winner chat remains available.
13. UTC DATETIME storage with local presentation; all app connections use utf8mb4 and strict SQL mode. The schema itself does not change server/global configuration. Case-insensitive email/skill uniqueness follows utf8mb4_unicode_ci; normalize/trim consistently in PHP.
14. Use INT UNSIGNED IDs, DECIMAL(10,2) money, external HTTP(S) links when supplied, and no dependency on JSON, partial indexes, descending-index syntax or newer MySQL features. Two PERSISTENT generated ledger markers provide conditional uniqueness supported by MariaDB 10.4; they are not FK targets.
15. SQL checks handle local row shape and ranges. Authenticated ownership, transition legality, exact fund recipient/amount, complete approvals, role/profile consistency, ledger/balance equality and file availability require transactions and service checks. A successful schema import would not prove those features work.
16. Financial history is immutable under ordinary DML using two simple triggers. A privileged operator can still perform DDL, so runtime least privilege is mandatory. There are no hidden triggers that credit/debit wallets; the app must write balance and ledger together.

## Unresolved issues to approve before affected implementation

| Issue | Proposed direction / impact |
|---|---|
| SMTP provider and secret provisioning | Choose provider, sender, host/port, TLS mode and credentials through private local configuration/environment; never SQL/Git. No SMTP setup or secret is included. |
| Verification implementation | Approved: 30-minute expiry, 60-second resend cooldown, real SMTP and hashed single-use tokens. Still decide invalid-attempt limits, delivery retries and email-change reauthentication. SQL does not implement the timing/consumption service. |
| Demo-credit implementation | Approved: RM10,000 exactly once per Client. Choose controlled issuance/audit when implementing the feature; SQL uniqueness does not enforce role or amount. No credit is issued now. |
| Future identity verification (out of current scope) | The unsupported identity_confirmed_at placeholder has been removed. Any real identity checks require separate workflow/privacy approval and a future schema change; neither self-declaration nor verified email is claimed as verified identity. |
| Private storage location | Approve the exact non-web-served directory before creating anything outside GigHub; do not place uploaded files under htdocs or solve privacy by public obscure URLs. |
| Upload implementation | Approved: PDF/JPG/PNG/WEBP only, portfolio maximum 5 MB, deliverable maximum 10 MB, ZIP prohibited, private storage outside htdocs. Still decide exact bytes, quotas, storage path, scanning, retention and visibility. No scanner/directory/uploader is created. |
| Budget semantics and deadlines | Approve single budget versus range, bids above/below budget are approved; still decide delivery-days versus deadline rules, and accepted overdue-work behaviour. No automatic release/refund. |
| Scope freezing and final review | Approve milestone requirement/freeze point and handling of final rejection after milestone approvals. Current draft requires a new final attempt without undoing earlier approvals. |
| Cancellation/dispute implementation | Approved: at most one cancellation request and one formal dispute per funded job. Determine inactivity/escalation timeouts separately; multiple attempts/appeals are not implied. |
| Bid mirror status | Accept application-maintained pending/accepted/rejected labels or replace with a derived read model before implementation. Canonical single winner is already enforced by job selection. |
| Operational limits | Agree wallet caps below DECIMAL maximum, job/bid/text limits, chat polling target and notification retention. Schema sizes are storage ceilings, not all product/security policy decisions. |

These questions do not prevent creating a reviewable initial schema. They must not be silently treated as approved business rules or implemented without review.

## Report sections requiring updates

- Chapter 1 section 1.4: explain fixed roles, real SMTP verification, actual private uploads, simulated balances and no real payment data; reconcile single-budget wording and desktop/mobile expectations.
- Chapter 3 use-case descriptions and functional requirements: correct use-case IDs after cancellation/refund insertion; explicitly model separate award/funding and failure remaining awarded, real verification, uploads, notifications, reviews, milestone/final submission history and mutual-consent cancellation.
- Figures 3.17-3.18: correct include/extend meaning and milestone actor reversal (Freelancer submits; Client reviews). Preserve bid-specific chat privacy.
- Chapter 4 Figures 4.2-4.6: distinguish selection from funding, ordinary revisions from disputes, and verified onboarding; remove split outcomes and make transaction boundaries explicit.
- Section 4.3 ERD and complete dictionary: replace with the 20-table schema. Correct Jobs.UserID -> id/job ID, Password -> password_hash, BidsAmount naming, JobsID naming and DisputesID naming. Show actual FKs and unique escrow/winner relationships.
- Section 4.4: retain Jaccard formula, 0.20 threshold and empty-union handling; add deterministic ties, batched retrieval and limitations of extra-skill penalties.
- Section 4.5: add token hashing/expiry/single-use, session/CSRF controls, private upload/download authorization, immutable ledger, idempotency, locks, rollback and least-privilege responsibilities.
- Sections 4.6-4.7: align UI states, award-awaiting-funding feedback, upload states, normal/disputed completion, timestamps and ledger-based receipts/earnings reports. No raw card or SMTP credential form/storage.

## Validation record and import approval gate

Initial offline checks were followed by approved MariaDB 10.4.32 runtime validation: all 23 statements imported, with 20 InnoDB tables, 37 FKs, 19 UNIQUE constraints, 43 CHECKs, 68 indexes, two triggers and two generated columns verified. Results: 65 PASS, one non-strict ENUM issue rejected under strict session mode. Synthetic records were rolled back; all test tables were empty. Global settings were unchanged. Application workflows/concurrency remain untested; no database access is performed by Prompt 07.

The script intentionally contains no CREATE DATABASE, USE, DROP, grants, seed data or IF NOT EXISTS collision masking. Import is not automatic or rollback-safe because DDL commits implicitly. Do not use a live/existing database for first validation. User must approve the destination and import separately; no commit is made by this task.

## Review findings and disposition (2026-10-30)

- Medium, fixed schema/document issue: removed the unsupported identity_confirmed_at column and its dictionary entry; removed self-confirmation placeholder wording. No real identity workflow is specified. Email verification is not identity verification.
- High, clarified implementation hazard: the prior verification transaction said both set verified/active and never unsuspend. Now activation is explicitly conditional on pending_verification under the user lock; email changes preserve suspended status. This is a documentation correction, not implemented PHP protection. Subsequent approval denies normal marketplace actions to suspended accounts and allows Admin resolution of their existing financial disputes; no participant privileges or reactivation are implied.
- High, clarified enforcement boundary: database uniqueness prevents a second settlement entry but does not itself ensure correct recipient, full amount, matching escrow state or wallet balance. Added exact recipient/amount derivation, per-escrow reconciliation and exact-replay handling. These cross-row guards require future service transactions/tests; no accounting trigger or new payment model was introduced.
- High, clarified upload race: recheck authorized draft ownership when inserting/activating metadata after external file I/O, under the same locks as submission freezing. A structurally valid owner FK is not a live authorization decision. No uploader/download endpoint or filesystem change was created.
- Medium, clarified workflow synchronization: added explicit allowed source job/request states, cancellation/dispute outcome matrix and bid-label reconciliation; unfunded selection remains distinct from funded acceptance. One cancellation request and one formal dispute per funded job are subsequently approved limits. Bid mirror representation and detailed timeouts remain implementation choices.
- Compatibility review: found no definite defect in immediate composite FKs, jobs/bids/escrows creation order, PERSISTENT nullable UNIQUE markers, or separate single-statement SIGNAL triggers. Reviewed against official MariaDB documentation. At that historical offline review no SQL was run. Subsequent approved runtime testing confirmed MariaDB 10.4.32 import and tested constraints; application workflows remain unverified.

Approved suspension policy: suspended accounts cannot perform normal marketplace actions; an active verified Admin may resolve existing financial disputes involving them. Email verification never clears suspension. Exceptional participant access is not implicitly granted.


## Confirmed runtime validation and approved policies

The unchanged schema was imported once into a newly created, verified-empty gighub_schema_test on MariaDB 10.4.32. Existing database names were checked first. All 23 statements succeeded: 20 tables, one deferred FK ALTER and two triggers. information_schema, SHOW CREATE TABLE and SHOW CREATE TRIGGER verified 20 InnoDB/utf8mb4_unicode_ci tables, 20 primary keys, 37 foreign keys, 19 UNIQUE constraints, 43 CHECK constraints, 68 indexes, two ledger triggers and two generated columns.

The corrected runner recorded 65 PASS and one default-mode ENUM failure; passes included 46 expected rejections. Tested protections included duplicate accounts/skills/bids/escrows/reviews/disputes/cancellation, FK ownership and same-job references, money/rating ranges, milestone/submission checks, upload metadata ownership, one-time credit and settlement uniqueness, and immutable ledger triggers. Synthetic wallet/ledger totals reconciled. These fixtures prove row constraints, not complete legal business workflows.

Installed global sql_mode was NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION. Invalid users.role was accepted as an empty ENUM with warning 1265: Data truncated for column 'role' at row 1. STRICT_TRANS_TABLES on the test session rejected the same invalid value. Global settings were unchanged. Every future application connection MUST initialize strict SQL mode, UTC and utf8mb4. The factory prepares these settings but no successful application PDO connection is configured or tested.

All synthetic rows were rolled back and all 20 tables verified empty. Auto-increment counters may have advanced. The isolated schema is retained: do not reset/re-import it or select it as the application database. No real accounts, permanent credits, real payment details, emails or upload files were created. Unchanged source SQL SHA-256: 29515ad151c5171642ac9a69eae44e27b635f27f55753bde511dde4d0d346286.

Approved product policies:
- Real SMTP verification, 30-minute token expiry, 60-second resend cooldown, hashed single-use tokens. Actual token use/timing/delivery requires PHP implementation.
- Each Client receives RM10,000 simulated credit exactly once. No real payment, bank transfer, card processing or withdrawal. SQL uniqueness does not itself enforce Client role or exact credit amount.
- Bids may be above or below advertised budget; full escrow amount comes from the selected bid.
- Portfolio uploads: PDF/JPG/PNG/WEBP, maximum 5 MB. Deliverables: same types, maximum 10 MB. ZIP prohibited. Store bytes outside public htdocs; never serve direct storage URLs. Exact MB-to-bytes interpretation, detection, quota, retention and storage path remain implementation choices.
- Each funded job allows at most one mutual cancellation request and one formal dispute; funded cancellation requires counterpart consent.
- Suspended accounts cannot perform normal marketplace actions. An active verified Admin may resolve existing financial disputes involving them without reactivating either party.
- Retain fixed normal roles/separate Admin, separate award/funding, full final payout, submission history, private bid chat, normal-completion reviews, in-app notifications and Jaccard.

NOT TESTED by SQL validation: SMTP, token consumption/expiry/cooldown, filesystem/type/size checks, request authorization, suspended-account guards, complete state transitions, exact-recipient service implementation, crashes or concurrent requests. These require feature-level PHP tests. This is an accepted data foundation, not a working marketplace.

Prompt 07 prepares only documentation and PHP structure: no database access, re-import, SMTP, upload-directory creation, accounts or wallet credits. See php-foundation.md for lazy PDO, private-file protection, optional ignored local configuration and the local-only non-database health endpoint.

## Prompt 10A registration implementation

Registration now creates only a pending user and one role-specific profile using the accepted schema. Passwords use available PHP Argon2id hashing (VARCHAR(255) is unchanged); the earlier report bcrypt description is a documentation difference, not a schema change. No activation, verified timestamp, public Admin, login session, wallet, demo credit, escrow, email-verification token or SMTP is implemented. Prompt 10A-R restricts optional registration data to bio for both roles and company_name for Clients; portfolio text/link management is deferred to verified profile editing and posted portfolio values are ignored. Password length is now 12–128 characters with the existing UTF-8 byte guard. Full Name maps to display_name without a schema change. See registration.md for validation policies, the approved duplicate-email enumeration trade-off, limited session throttling, transaction/savepoint testing and explicit feature boundaries.

Initial verification: 31 no-database registration checks, 23 foundation checks and 49 rollback-only live gighub_dev checks passed. Prompt 10A-R verification: 49 registration checks, 23 foundation checks and 52 rollback-only database checks passed; actual desktop/mobile rendering was inspected. All synthetic rows were rolled back; schema, permissions, private configuration, reserved schema-test database and PDF were not modified. No commit or push.
