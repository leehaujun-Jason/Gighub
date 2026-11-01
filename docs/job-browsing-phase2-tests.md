# Module 11B Phase 2A — prepared, not executed

## Reconciled completion status — 2026-11-01

Later separately authorized Phase 2B-2 and Phase 2C acceptance completed successfully:

- 69 real SQL checks passed, with isolated transaction fixtures rolled back.
- 66 isolated HTTP checks passed.
- 28 browser scenarios and 8 catalogue/detail responsive layout checks passed at 1280, 768, 375 and 320px.
- Live startup-failure cleanup before readiness and normal fixture cleanup passed.
- Temporary privileges were fully revoked and effective grants matched their original baseline.
- All 20 isolated test tables returned to empty. Development counts/content/schema fingerprints,
  active private configuration and credentials remained unchanged. No SMTP was used.

The missing permissions temporarily added for execution were DELETE on users/client_profiles/
freelancer_profiles, and INSERT/DELETE on skills/jobs/job_skills, all on gighub_schema_test only.
These nine additions were restored after each lifecycle; no UPDATE, DDL, global or development
permissions were added. This historical result does not authorize future grants or fixture runs.

Outstanding and explicitly unverified: 200% browser zoom, JavaScript-disabled browser behavior,
screen-reader speech and other browser engines. Browser checks verified the exercised labels,
focus, keyboard and error behavior, not comprehensive accessibility conformance.
The conservative cleanup limits documented below still apply to uncertain/abrupt termination.

The Phase 2A and Phase 2B-1 sections below preserve their original preparation/offline results.
Statements that live SQL/HTTP/browser acceptance or cleanup had not yet run apply to those phases;
they are superseded by this completion summary. Future execution still requires fresh approval,
identity/integrity/grant preflight, a new restricted runtime and exact-ID restoration procedures.

Only test infrastructure/documentation is changed here. No database connection, fixture write,
grant/revoke, HTTP host startup or acceptance execution is performed in this preparation phase.
The 11B application implementation and existing 11A test mode remain separate.

## Mode and request boundaries

Use a NEW restricted `accept-<16 hex>` directory under the existing private HTTP acceptance root,
never a completed run. Reuse the existing sessions directory, unique cookie, stop.key and port 8765
contract from tests/http-acceptance/README.md. No credentials belong in run.json.
Browsing run.json additionally requires:

```json
{
  "run": "accept-<16 hex>",
  "port": 8765,
  "job_browsing_enabled": true,
  "job_posting_enabled": false,
  "mutations_enabled": false,
  "browse_fixture_writes_enabled": false,
  "auth_requests_enabled": false,
  "clock": 1893456000
}
```

This is a settings fragment, not a complete runtime file: add the unique cookie setting and
prepare sessions/stop.key using the existing private-runtime procedure. The fixed clock is
2030-01-01 UTC and matches the deterministic fixture dates.

BrowsePolicy permits only GET /jobs, /jobs/show and /login. An explicit auth_requests_enabled=true
permits POST /login and /logout only. Login is further restricted to this run's exact synthetic
Client/Freelancer email labels. All other methods/routes, including Client Job Posting,
Registration, Verification and Admin actions, are blocked before session/database initialization.
No global mutation opt-in is enabled. Browse fixture writes must be disabled while the HTTP host runs.
The host's authenticated /_shutdown control remains outside application routing and unchanged.

The host selects browse-control.php preflight. Both modes true, malformed browsing flags or failed
preflight prevent startup. Existing Authentication/11A selection remains unchanged when browsing
is absent/false. Their older control scripts explicitly reject browsing runs. Real public classes
are injected with the existing hard-pinned TestDatabase PDO, never bootstrap/app.php or config/local.php.
Mail remains DisabledMailTransport, with no Composer/SMTP initialization or diagnostic transport.

## Fixtures and database connection paths

browse-fixtures.php has a pure plan() and an explicit populate(). The plan contains four users:
active verified Client, suspended verified Client, pending unverified Client, active verified Freelancer;
their role profiles; three run-marked skills; 24 eligible open jobs and four hidden jobs
(draft/cancelled/suspended-owner/pending-owner). Budgets include RM0.01/RM99,999,999.99 and ties;
creation dates include ties, deadlines include overdue/future values, and skill sets overlap.
Literal %_!, Unicode and HTML-like text exercise search escaping and output encoding.
Cancelled fixtures include closed_at; there are no bids, awards, wallets, tokens or financial records.

Future CLI rollback tests may call populate() inside a single guarded outer transaction with an
in-memory tracking callback, then roll back and compare all 20 tables. No subprocess/secondary PDO
is created by populate(). It verifies TestDatabase identity and empty test tables before insertion.
All fixture users/skills/jobs are reported to the callback immediately after obtaining their IDs.

Future HTTP setup uses `browse-control.php seed <run>` before starting the host. It requires
browse_fixture_writes_enabled=true, auth_requests_enabled=false, mutations_enabled=false, no
server-ready marker, no prior inventory and all 20 tables empty. It creates job-fixtures.json
exclusively, inserts through one CLI IntegrationDatabase PDO in a transaction, records exact IDs
using the shared JobFixtures inventory, validates inventory and commits. It never escalates privileges.
Any failure rolls back; preserve the inventory even if it contains rolled-back IDs. No automatic retry.

Before startup reset browse_fixture_writes_enabled=false. Enable auth_requests_enabled only for
approved role-session checks. Request connections use only the CGI TestDatabase adapter; CLI
preflight/setup/inspection/cleanup use unchanged IntegrationDatabase with --run-isolated.
Both pin 127.0.0.1:3309, gighub_schema_test, gighub_test and the clean version/datadir. CLI control
also verifies that SELECT on gighub_dev.users is denied. No development/admin credential fallback.

## Exact future privilege requirements — not granted here

Inspect effective grants first and grant ONLY missing operations on gighub_schema_test.

| Scope | Required operations |
|---|---|
| All 20 existing test tables | SELECT for empty/inventory/integrity checks |
| users | INSERT; temporary DELETE for persistent HTTP fixture cleanup |
| client_profiles | INSERT; temporary DELETE for HTTP cleanup |
| freelancer_profiles | INSERT; temporary DELETE for HTTP cleanup |
| skills | INSERT; temporary DELETE for HTTP cleanup |
| jobs | INSERT; temporary DELETE for HTTP cleanup |
| job_skills | INSERT; temporary DELETE for HTTP cleanup |

Rollback-only use of this fixture plan needs the six table-level INSERT operations plus SELECT;
no DELETE is needed if everything remains inside the outer rollback transaction. No UPDATE is
required by this browsing plan or Login/Logout flow. No token DELETE is required: browsing inventory
rejects every verification-token row and the shared cleanup skips that table when empty.
No schema-wide writes, global privileges, DDL, GRANT OPTION, trigger management or gighub_dev access.
Preserve all pre-existing grants, record each temporary addition and restore the exact baseline,
including after failure. Grant/revoke tooling is not added or executed by this preparation.

## Cleanup and execution gates

Before future execution independently verify server/process/endpoint identity, all 20 expected tables,
empty test contents, constraints/triggers, current effective grants and denied development access.
Record development counts/canonical content digests/schema fingerprints and private config hashes
through a separately verified read-only mechanism. Confirm no other test process is writing.

After HTTP checks disable authentication requests and fixture writes, stop the HTTP host using its
existing graceful control and verify fresh server-stopped.json with graceful=true and no unfinished
children. Successful-startup cleanup requires both ready/stopped records and their timestamp order.
The separately guarded failed-before-ready path is described below; missing readiness alone never
authorizes cleanup.
Then BrowseFixtures revalidates exact run/IDs/marked names/owners/links, rejects any tokens, and reuses
JobFixtures cleanup: job_skills -> jobs -> role profiles -> users -> skills, in one transaction with FKs
enabled. Only exact tracked IDs with matching run markers are eligible; other tables must stay empty.
Any unrelated record/dependency fails closed; there is no TRUNCATE, unrestricted deletion or FK bypass.
Verify all 20 tables empty, restore grants and compare development integrity/config baselines.
If cleanup fails, stop further tests, preserve exact inventory and still restore temporary grants
through a separately authorized administrative connection. Do not broaden cleanup or retry automatically.

## Offline coverage and limits

tests/job-browsing-harness.php exercises request policy, malformed/hostile inputs, configuration/identity
rejection, fixture-plan/ownership boundaries and source wiring. browse-harness-checks.py exercises
host mode selection and minimal child environment without main(), networking or bytecode caches.
Existing 11A isolation and HTTP startup guard suites must remain passing.
These checks do not prove live SQL execution, live host rejection, FK cleanup, permission restoration
or development integrity. All database/HTTP acceptance and browser behavior remain unexecuted in 2A.
Separate execution approval is required after independent review; prepare actual SQL/HTTP assertions
for visibility, wildcard semantics, inclusive ranges, ANY skills, stable pagination and public privacy.

Preparation verification: 141 browsing harness checks, 14 offline host checks, 19 existing 11A fixture
guards, 12 HTTP startup guards and 102 public browsing regression checks passed (288 total).
Seven affected PHP files passed syntax checks; Python host definitions/checks parsed and executed
offline without main() or cache generation. Tracked/new-file whitespace checks passed.

## Phase 2B-1 — cleanup after an attempted startup fails

This correction is test-only and verified offline. No SQL/HTTP acceptance or live process/service
operation was executed while implementing it. The earlier Phase 2B attempt stopped before grants
or fixture writes after identifying that normal cleanup could not handle a missing ready marker.

Browsing server startup now records browse-startup.json before spawning its PHP preflight child.
The immutable record binds a random attempt ID, exact run, PID, server script, endpoint/database,
start time and SHA-256 of the exact fixture manifest. The preflight PHP process and every CGI child
are retained in the host's process list. A finally block writes browse-terminal.json with readiness
state, end time and each child PID/observed exit code. A 30-second preflight timeout does not kill
anything: a live/uncertain child is recorded as such and prevents automated cleanup.

Both records are authenticated with HMAC-SHA-256 using the existing private stop.key. New browsing
runs require a 64-lowercase-hex control key. The key is never printed, transmitted to application
requests or included in evidence. These records live in the restricted private runtime directory,
not Git or htdocs. This protects against accidental editing/cross-run replay; it is not forensic
proof against an attacker controlling the same Windows account and private key.

After a future explicitly authorized failed startup:

1. Do not retry the host or edit/delete evidence. Wait for its process and children to exit without
   force termination. Disable auth_requests_enabled, browse_fixture_writes_enabled and mutations_enabled.
2. Run the same separately authorized `browse-control.php cleanup <run>` command. Before connecting
   to a database it authenticates matching startup/terminal records and the unchanged manifest.
   A no-readiness cleanup requires phase=failed_before_ready, ready_reached=false and absence of
   both normal ready/stopped markers. Normal cleanup retains its ready/stopped requirements.
3. The cleanup gate independently inspects Windows process/listener state using a read-only
   PowerShell helper. The runtime PID and all recorded child PIDs must be absent; no run-tagged
   Python process, unknown PHP/CGI worker or listener on port 8765 may remain. Unreadable process
   identities, failed OS queries, PID reuse and ambiguous observations refuse cleanup. Raw process
   command lines are examined only in memory and never returned or logged.
4. The unchanged CLI integration guard then verifies the exact test database/account/server identity
   and development denial. Existing fixture ownership/dependency checks run again. Actual user,
   skill and job IDs must exactly equal the tracked inventory, including no missing or duplicate IDs.
5. An exclusive browse-cleanup-claimed.json consumes cleanup once. OS quiescence is checked again,
   then the existing FK-safe, exact-ID transactional cleanup runs and verifies all 20 tables empty.
   Restore temporary grants and compare development integrity using the reviewed external lifecycle.

The host refuses prior startup/terminal/cleanup evidence, preventing automatic restart during or
after cleanup. Authentication/11A startup behavior and shared JobFixtures cleanup SQL are unchanged.
Only browsing mode uses the new lifecycle recorder and cleanup gate.

Limits: abrupt process termination, missing/partial/invalid signatures, incomplete terminal records,
live children, an OS inspection timeout or a consumed cleanup claim require manual read-only
investigation and separately approved recovery. Never forge markers, delete a claim or broaden
DELETE to bypass a refusal. An unrelated PHP CLI process may conservatively block cleanup; wait
for it normally rather than killing it. A run-tagged Python orchestrator can also block the gate;
invoke cleanup only after those test writers have finished. No automatic retry is implemented.
The OS observation is point-in-time and assumes the restricted local runtime directory remains
trusted; it is not a shared/public deployment mechanism.

Offline tests: tests/browsing-cleanup-safety.php simulates normal/failed startup, live or uncertain
processes/listeners, authentication and run mismatches, wrong database, bad fixture IDs and repeat
cleanup. browse-lifecycle-checks.py validates the recorder's payload/signature construction without
starting a host. browse-process-checks.ps1 runs the actual observer against mocked OS query cmdlets,
including live/reused PIDs, active IPv4/IPv6 listeners and query failure. Live OS/SQL cleanup remains
to be exercised only during a newly authorized Phase 2B preflight/acceptance run.

Phase 2B-1 verification: 46 PHP cleanup-safety checks, 22 Python lifecycle checks (including the
actual recorder with in-memory files), 17 mocked Windows observer checks and 186 existing
browsing/11A/HTTP guard checks passed (271 total). PHP, Python and PowerShell syntax and Git/new-file
whitespace checks passed. No database connection, live OS observation, HTTP runtime or acceptance
fixture operation was performed. The corrected path is ready for a fresh authorized execution
preflight; absence of live verification is not a claim that production cleanup has already passed.
