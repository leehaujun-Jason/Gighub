# Client Job Posting — Module 11A Phase 1

## Current completion status — 2026-11-01

Module 11A completed local acceptance: 762 offline checks passed after UI corrections; 26 real MariaDB integration checks passed; 50 HTTP functional checks and 3 startup safety checks passed. Browser acceptance passed for exercised flows at 1280, 768, 375 and 320px. The final read-only development smoke test passed at 1280 and 320px: updated Client Home, empty My Jobs without unnecessary Page 1 text, all 20 Skills and enabled publication controls. Both publication paths use "Your job has been published."; source/offline tests verified this without publishing a development job during the smoke test.

All test fixtures were cleaned up, temporary privileges restored and development database integrity preserved. The approved 20 canonical Skills were successfully seeded into local gighub_dev. A fresh environment still requires a separate controlled Skills seed using docs/skills-catalogue-plan.md; it is not automatic.

Outstanding, not passed: 200% browser zoom, JavaScript-disabled browser behavior, screen-reader speech output and other browser engines. Concurrency stress, durable creation idempotency and public-deployment abuse protection remain outside this acceptance. This is local FYP acceptance, not complete public-deployment readiness.

Preparation results below are historical Phase 1/2A records. Empty-catalogue and unexecuted-acceptance statements apply to those phases, not this completion status. See docs/job-posting-phase2-tests.md for the reconciled verification record.

## Approved behavior

Client-only complete drafts, explicit publication to `open`, editing owned drafts, publication of saved drafts, owner-only list/detail and existing catalogue skill selection. Published jobs are read-only. No deletion, cancellation, bidding, escrow, wallets, SMTP, new dependencies or schema changes.

Title: 5–150 Unicode code points. Plain-text description: 50–10,000 code points and at most 65,535 UTF-8 bytes. Both are trimmed. Budget is an exact decimal string from RM0.01 to RM99,999,999.99, with at most two decimals; no exponent, separators, sign, leading zero padding or floating-point persistence. Drafts require valid title, description, budget and calendar date. A draft may retain an elapsed date; publishing must use a delivery deadline after today's Asia/Kuala_Lumpur calendar date. Database timestamps stay UTC.

Drafts permit 0–10 skills; publication requires 1–10 distinct existing IDs. Submitted arrays, malformed IDs, nonexistent skills and duplicate selections fail validation. No free-text skill creation. Enter defaults to Save Draft through native button order and the server's missing-intent default. Textarea Enter retains normal newlines. JavaScript only focuses the error summary; posting works without JavaScript. This keyboard behavior has offline source/fixture coverage, not a new browser acceptance result.

## Architecture

Routes use existing `?route=/client/jobs` conventions: GET list/create/show/edit, POST create/update/publish. IDs are separate query or form parameters, validated as positive unsigned INT values. All actions call `AuthGuard::check('client')`. A dedicated `job_posting` CSRF purpose protects writes. Successful writes rotate that token and redirect with 303 to a fixed owner detail URL. No user-controlled redirect.

JobRepository owns prepared SQL and owner-scoped reads/updates. SkillRepository supplies the existing catalogue. JobPostingService owns atomic writes: lock an existing owner-scoped job first, reject any non-draft, then lock/recheck the active verified Client/profile before validating and persisting job/skill links. New creation locks/rechecks the Client first because no job exists. Published state, award fields and Client ID are never accepted from submitted input. Existing job locks are consistent with the database design's job-first workflow order; future modules must preserve that discipline. Outer fixture transactions use a named savepoint and are not committed by this service.

Publication from detail reads stored draft content and skills under the job lock and revalidates the date. A repeated publish cannot transition an already open job again. Two concurrent draft edits serialize but are last-writer-wins; optimistic version conflict UI is not implemented. PRG and token rotation reduce accidental repeats but do not provide durable request idempotency. No automatic persistence retries.

No balances change and no mail transport is instantiated by Job Posting. Driver exceptions are sanitized and not logged with SQL or form content. Views escape all output; description uses CSS pre-wrap, not HTML rendering. CSRF recovery keeps only bounded non-sensitive escaped job values. Skills fieldset and field errors are associated, and the error summary links to fields. The list uses bounded 20-row pages ordered by creation/id. Existing AuthGuard/session behavior remains unchanged.

## Catalogue preflight — 2026-11-01

A read-only application-account inspection confirmed the clean instance on port 3309 and its expected data directory. `gighub_dev.skills` contains **0 rows**. Mail and diagnostics were both disabled. No rows were inserted. Draft creation is available without skills; publication cannot succeed until a separately approved catalogue exists. The form explains this and disables its Publish button when the catalogue is empty; the service independently rejects publication without skills.

Proposed seed list for separate review: PHP, HTML, CSS, JavaScript, MySQL, MariaDB, Laravel, WordPress, React, Python, Java, C++, UI/UX Design, Graphic Design, Logo Design, Copywriting, Content Writing, Data Entry, SEO, Video Editing. These are proposed names, not inserted data. The list deliberately uses canonical IDs for Jaccard matching; naming/taxonomy can be refined before seeding.

## Proposed Phase 2 isolation and privileges — NOT executed

Keep `tests/integration-bootstrap.php` and fixed dedicated test credentials. Exact target remains 127.0.0.1:3309 / gighub_schema_test, expected account gighub_test, expected clean data directory and MariaDB version. Never use Config::load(), administrator credentials or development credentials for fixtures/requests. Verify denial to gighub_dev before and after tests. A reviewed minimal child environment must apply to all CGI/subprocess paths.

Combined Authentication + Job Posting fixtures use exactly these seven tables: users, client_profiles, freelancer_profiles, email_verification_tokens, skills, jobs, job_skills. A job-only subset needs users/client_profiles/skills/jobs/job_skills. Do not touch bids, escrow, wallets or other business tables.

Creation order: synthetic users; matching profiles; run-marked skills; owned draft/open jobs; job_skills. Tokens only for the existing Authentication suite. All fixture identifiers must be traced to a unique run. Skills can use a short run-specific prefix within VARCHAR(50), and only IDs actually created by that run are cleanup candidates. Never delete an existing catalogue skill.

Required additional test-table operations beyond existing SELECT/Authentication grants:
- jobs: INSERT and UPDATE; temporary DELETE for committed HTTP fixture cleanup.
- job_skills: INSERT and DELETE (draft skill replacement and cleanup).
- skills: INSERT for synthetic catalogue rows; temporary DELETE for cleanup. No skill UPDATE is needed.
- Existing Authentication cleanup DELETE grants on users, client_profiles, freelancer_profiles and email_verification_tokens require fresh explicit approval if that combined suite is run. Do not assume earlier temporary grants remain authorized.

No global privilege, GRANT OPTION, DDL, trigger management or development-schema access. Record effective original privileges and restore exactly in a finally path; revoke temporary grants even after test failure. Transaction-only CLI fixtures still advance test auto-increment counters and require job_skills DELETE because the production draft-edit operation replaces skills.

Before fixtures: verify server/account/schema identity, current FK/triggers, and baseline test contents. The current Authentication cleanup refuses rows in the other 16 tables, so it must be narrowly extended for these three tables before any new mutating HTTP run. If there are unrelated pre-existing records, stop and design preservation first. Verify no fixture job has bids/milestones/escrows or other dependent records; no authorized job fixture should create any.

Cleanup order: job_skills for tracked job IDs; tracked jobs with matching Client IDs and no award/dependencies; tracked email_verification_tokens; tracked freelancer_profiles/client_profiles; tracked users; tracked synthetic skills after verifying neither user_skills nor other job_skills references them. All deletes are prepared, ID-scoped and run-bound, never unrestricted DELETE/TRUNCATE. Do not disable FKs. Verify both run marker and tracked IDs, no remaining run rows, original privileges restored and development access denied.

Before/after approved mutation tests, compare all 20 development row counts and canonical content digests using an independently verified read-only mechanism, without printing records. Record active config/schema hashes and disabled mail flags. The application account and active configuration remain unchanged. Request PDO, setup, cleanup, secondary and subprocess paths must all be independently reviewed before execution.

## Phase 1 verification and limits

Offline tests cover validator boundaries, service ownership/status/date transitions and transaction/savepoint calls through PDO/repository doubles, controller/view CSRF/role/error/PRG behavior, and UI source/DOM fixtures. These doubles do not prove MariaDB SQL execution, permissions, locking or rollback correctness on a real server. No database integration fixtures or mutating HTTP requests were run in Phase 1. No actual browser/mobile/zoom/screen-reader/no-JavaScript acceptance is claimed.

Phase 2 requires separately approved test-only grants, fixture/cleanup changes and execution. Also verify runtime application permissions for jobs and job_skills, particularly DELETE on job_skills for draft replacement, without granting additional development access automatically. Empty catalogue is a publication blocker. Existing authentication session-only abuse limitations remain; this feature adds no public-deployment spam/rate-limit guarantee.

## General UX references

Original GigHub code; no copied modules/assets. Source patterns reviewed in Module 11A research: paulonteri/freelance-marketplace (Apache-2.0), TrickyVikram/freelancehub-laravel (MIT), zyna-b/HirePrint---B2B-Marketplace (MIT). Relevant source: their Client create-job/manual-project forms and job/project controllers. Adopt labels, inline errors and explicit draft/publication; reject hardcoded ownership, unsafe output and internal exception disclosure. No library or asset was imported.

Recorded Phase 1 checks (2026-11-01): 109 new offline checks (52 validator, 21 service, 18 controller/view, 18 UI source/DOM fixtures) and 604 existing offline regression checks passed. SMTP diagnostics includes its 76 base SMTP checks in the 119 total; do not count those twice. PHP syntax checks covered 75 public/source/test/example files; private local configuration was excluded. New JavaScript syntax and Git whitespace checks passed. No SQL fixtures or browser mutations were executed.

## Phase 2A preparation follow-up

The dedicated integration suite and guarded HTTP Job Posting mode are now prepared, not executed. See `docs/job-posting-phase2-tests.md` for the exact fixture lifecycle/privileges and `docs/skills-catalogue-plan.md` for the proposed revised 20-name catalogue and idempotent future seed. These plans supersede the initial catalogue suggestion/fixture outline above without changing Phase 1 history. No development seed or mutating acceptance run is authorized by their presence.
