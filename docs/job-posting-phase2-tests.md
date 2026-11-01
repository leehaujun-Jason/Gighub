# Job Posting Phase 2A — preparation only

## Reconciled acceptance status — 2026-11-01

The Phase 2A preparation record below is preserved as history. Later, separately authorized phases completed:

- 762 offline checks passed after the minor UI corrections.
- 26 real MariaDB integration checks passed on gighub_schema_test using rollback fixtures.
- 50 isolated HTTP functional checks and 3 startup safety checks passed.
- Browser acceptance passed for exercised flows at 1280, 768, 375 and 320px.
- Final read-only development smoke testing passed at 1280 and 320px after the controlled Skills seed.

All test fixtures were cleaned up and temporary privileges restored. Development database integrity was preserved; the only separately approved development data change was insertion of the 20 canonical Skills into gighub_dev.skills. The final smoke test compared development/test counts and content/schema fingerprints, privileges and private configuration against its baseline without submitting mutating forms. No real SMTP messages were sent by these Job Posting tests.

Outstanding and not claimed as passed: 200% browser zoom, JavaScript-disabled browser behavior, screen-reader speech output and other browser engines. Concurrent-writer stress and public-deployment throttling remain deferred. Prepared/unexecuted suite references below describe Phase 2A only. Future fixture runs still require fresh isolation, cleanup and privilege approval; completed acceptance is not blanket authorization to rerun them.

## Application review

Reviewed JobPostingService, JobRepository, SkillRepository, validator, controller, CSRF, routes and views. No confirmed Critical/Major source defect was established. Queries scope jobs by both id/client_id; edit/publication lock the draft before rechecking current Client eligibility; submitted ownership/status/award fields are excluded; publication reads stored skills/date; all write paths use a transaction or outer-fixture savepoint. CSRF precedes service writes, role checks precede controller work, output is escaped and redirects are fixed.

Known limitations: real SQL/locks/rollback still need execution; standalone commit behavior is covered only by offline doubles because the new CLI suite deliberately uses one outer rollback transaction. Duplicate create requests are not durably idempotent; concurrent draft edits are last-writer-wins. These are existing documented limits, not fixed in this phase. JobRepository::replaceSkills issues DELETE even on creation: job_skills DELETE is a runtime operation privilege, not merely cleanup. Development account permissions were not inspected or changed here. Published/non-draft error copy says published for any non-draft status; this is harmless in the current draft/open-only phase.

No application source code is changed in Phase 2A. Test doubles do not prove database correctness, and prepared tests are not passing integration acceptance.

## Read-only isolated inspection — 2026-11-01

Existing IntegrationDatabase guard authenticated the exact test account at 127.0.0.1:3309 / gighub_schema_test, expected MariaDB 10.4.32 and clean datadir. Twenty InnoDB tables contained zero rows. No triggers were present on users/client_profiles/freelancer_profiles/email_verification_tokens/skills/jobs/job_skills. No explicit TABLE_PRIVILEGES entries were visible for jobs, job_skills or skills. This is not a complete effective-grant audit: Phase 2B must verify all required privileges including schema/global scope. No fixture, grant or development-data query was executed in this phase.

## CLI database suite

`tests/job-posting-database.php --run-isolated` is PREPARED ONLY. Do not run until separately authorized.

One connection only, through unchanged tests/integration-bootstrap.php. Main, snapshots, setup, service, failure injection, constraint checks and rollback all use the same PDO; no subprocess or secondary database connection exists. No Config::load fallback or developer/admin credentials. Negative development SELECT must be denied before fixtures.

A random dbj marker binds synthetic users, skills and job titles; exact inserted IDs are tracked. Setup inserts two verified Clients/profiles and two synthetic skills in an outer transaction. All 20 test-table row counts/canonical content digests are captured before and compared after rollback. Auto-increment gaps may remain only in test. Existing rows are never deleted/reset. No schema changes. The suite tests owner privacy, forged Client/status fields, complete draft, skill replacement, publication/new publication, expired deadline, immutable open jobs, repeated publish rejection, and atomic rollback after real link writes fail. CHECK, duplicate junction and FK violations must return expected numeric MariaDB errors. Savepoints isolate negative constraint attempts. Finally always rolls back an open fixture transaction; failure stages are sanitized.

## HTTP job mode

Use a NEW restricted `accept-<16 hex>` runtime under the existing private HTTP acceptance root; never reuse the completed Authentication evidence directory. run.json must explicitly contain job_posting_enabled=true, mutations_enabled=false initially, port=8765, matching run, unique cookie and a valid test clock. Runtime files remain outside Git/web root. No runtime was created in Phase 2A.

The existing loopback server chooses job-control preflight only in explicit job mode. It still uses the hard-pinned test CGI adapter and minimal child environment. The front controller injects actual Job Posting services/controllers with test PDO/clock. It never loads config/local.php or administrator/application credentials. Mail remains DisabledMailTransport and diagnostics/SMTP classes are absent. Job mode rejects mutation routes outside login/logout/Job Posting; original Authentication mode is unchanged.

JobFixtures validates run/state/manifest types, bounds, unique exact IDs, marked names and all dependencies. TrackedJobRepository enforces marked owners/titles and records job IDs before service commit; failed transactions may leave harmless phantom IDs in the inventory. Missing/unreadable inventory fails closed. Runtime is single-request-at-a-time; parallel writers are not supported. Do not tamper with inventory or reuse a failed run automatically.

`job-control.php` supports preflight/seed/inspect/cleanup. Seed refuses any pre-existing fixture inventory and requires the entire 20-table test schema empty. It creates four synthetic users (Client, second Client, Freelancer, Admin), role profiles and three marked skills, recording exact IDs as it proceeds in a transaction. It does not create jobs; those come through normal controller requests. On failures preserve the inventory, even if some IDs rolled back. It never escalates privileges.

`job_http_checks.py --run-isolated-http <run>` is PREPARED ONLY. It requires both explicit job mode and mutation opt-in. It does not seed, grant, cleanup, revoke or restart servers automatically. Prepared coverage: anonymous redirect, Freelancer/Admin denial, verified Client, invalid CSRF creates no job, field errors, draft creation, forged ownership/status rejection, owner detail/list, other-Client read/edit/update denial, editing, saved-draft publication, open-state immutability/repeated publication, direct publication and fixed redirect. Bodies/cookies/keys/raw errors are not printed. Failed runs stop without retry. Capture stdout/status into separate sanitized evidence; a success JSON is emitted only after all assertions pass.

## Exact fixture tables and cleanup

Allowed seven: users, client_profiles, freelancer_profiles, email_verification_tokens, skills, jobs, job_skills. Job-mode seed creates no verification tokens; the cleanup allowlist retains that existing Authentication child relationship, but will accept only tokens belonging to tracked user IDs. All other 13 tables must remain empty. No pre-existing records are eligible for deletion; abort on any untracked user, skill, job, child or dependency.

Creation: tracked users -> profiles -> tracked skills -> request-created tracked jobs -> job_skills.
Cleanup: stop test activity; disable mutations; gracefully stop HTTP host and confirm no CGI children; require its fresh successful stopped-status evidence. Then validate the entire fixture inventory. Delete job_skills by tracked job ID -> jobs by tracked ID/marked title -> tokens by tracked user -> Freelancer/Client profiles -> users by tracked ID/marked email -> skills by tracked ID/marked name. All deletes occur in one cleanup transaction, with live FK constraints. Restrictive FKs reject unexpected residual skill/user dependencies. Verify all 20 test tables empty before committing cleanup. Never use TRUNCATE, unrestricted DELETE, disabled FKs or delete-by-marker alone.

The old control.php explicitly rejects seed/cleanup/issue in job mode so its Authentication-only cleanup cannot be used accidentally. Existing privileges.php has NOT been expanded and cannot manage job permissions. Fresh separately reviewed administrative grant/revoke steps are required. If cleanup fails, stop all further tests, preserve exact inventory/evidence and still revoke temporary privileges via separately authorized administrator connection. Do not broaden grants or delete untracked records to force cleanup.

## Required privilege proposal — NOT granted

All scope is gighub_schema_test ONLY for gighub_test@127.0.0.1. No global privileges, GRANT OPTION, development access or schema/trigger administration.

| Table | Operations required |
|---|---|
| All existing test tables | SELECT (already expected schema-level SELECT; verify) |
| users | INSERT for fixtures; existing UPDATE only if other Authentication regressions need it; temporary DELETE for committed HTTP cleanup |
| client_profiles | INSERT; temporary DELETE for committed HTTP cleanup |
| freelancer_profiles | INSERT for HTTP wrong-role fixture; temporary DELETE for its cleanup |
| email_verification_tokens | No new INSERT/UPDATE for job-only tests; DELETE only if the separately approved combined workflow actually creates tokens |
| skills | INSERT; temporary DELETE for HTTP cleanup |
| jobs | INSERT, UPDATE; temporary DELETE for HTTP cleanup |
| job_skills | INSERT, DELETE (both service replacement and HTTP cleanup) |

CLI job-only rollback suite needs users/client_profiles INSERT, skills INSERT, jobs INSERT/UPDATE, job_skills INSERT/DELETE plus SELECT. It does NOT need DELETE on users/profiles/jobs/skills because its outer transaction rolls back. HTTP job-only adds freelancer_profiles INSERT and cleanup DELETE on users/client_profiles/freelancer_profiles/jobs/skills. Current generic cleanup supports the token table, but do not grant token DELETE for a pure job run that has no tokens; cleanup must skip token DELETE when the tracked-token count is zero (see implementation). Any combined Authentication run needs separate approval for its existing token privileges.

Record exact original effective grants. Approve only missing operations; verify schema/global scope unchanged and dedicated account denied access to development. Revoke every newly temporary grant afterward and compare to the recorded original state even after test failure. No script in this change automatically grants or revokes privileges.

## Phase 2B prerequisites

1. Approve test execution and exact temporary table privileges after an effective-grant audit; preserve clean development baseline using a separately authorized read-only integrity mechanism.
2. Review new code paths independently. Verify no development credential fallback, exact instance identity, empty test schema and untouched original Authentication evidence.
3. Create a fresh restricted runtime and prepare grant/revoke/cleanup orchestration with a finally path. Do not use old privileges.php as a job grant mechanism.
4. Run rollback CLI suite first; report failures without retrying automatically.
5. After successful cleanup and fresh guards, seed HTTP fixtures, enable only that isolated run, execute HTTP checks, stop activity/host, disable mutations, clean exact fixtures and revoke all temporary grants.
6. Confirm denied development access, unchanged development baseline/config, no remaining test records/privileges, no mail and no unexpected child processes.

Development skill seeding is a SEPARATE approval and is not needed for synthetic integration fixtures. Proposed canonical names/idempotent plan are in docs/skills-catalogue-plan.md. No database/browser acceptance result is claimed by preparation.

## Non-mutating verification — 2026-11-01

732 offline checks passed: the previous 713 checks plus 19 new fixture/isolation guard checks. PHP syntax: 79 files passed; JavaScript syntax: 8 files passed; Python AST parsing: 3 harness scripts passed without generating caches. The database fixture suite and mutating HTTP suite were not executed. Existing controller/HTTP-named PHP offline suites use doubles and perform no fixture writes; they are not live HTTP acceptance. No service was started/stopped, no privilege was granted/revoked and no development seed ran. Read-only test-schema inspection is recorded above; development data integrity was not newly measured in this phase because inspection was restricted to the isolated instance/schema.
