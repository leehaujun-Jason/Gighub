# Isolated Authentication HTTP acceptance harness

Module 11B browsing: see `docs/job-browsing-phase2-tests.md`. The separate
`job_browsing_enabled=true` mode uses browse-control.php and a narrow browsing/login/logout
allowlist; it cannot enable Job Posting mutations. Completed local acceptance passed 69 SQL checks,
66 HTTP checks, 28 browser scenarios and 8 responsive layout checks (1280, 768, 375 and 320px).
Live startup-failure and normal fixture cleanup passed; temporary privileges were fully revoked,
grants restored, all 20 test tables returned to empty and development integrity remained unchanged.
200% browser zoom, JavaScript-disabled browser behavior, screen-reader speech and other browser
engines remain explicitly unverified. Historical preparation results are preserved in the module
documents. Future fixture execution still requires separate authorization and a fresh safety
preflight. Existing Authentication and 11A modes retain their own lifecycle.

This is test-only infrastructure. It never loads bootstrap/app.php, config/local.php,
production database credentials or Composer/SMTP. Production files and the CLI guard
are unchanged. Do not serve this directory with Apache.

## Architecture

server.py binds only 127.0.0.1:8765 and passes requests to the installed php-cgi.exe
with an explicit minimal environment. PHP CGI provides real native sessions and
Set-Cookie headers. front.php composes the existing application controllers/services
with injected test PDO and DisabledMailTransport. The host serves only that front
controller and allowlisted local CSS/JS. Arbitrary files and proxy requests are not served.
Request/response bodies, URLs, cookies, passwords and verification keys are not logged.

database.php validates exact private test settings and rechecks database, account,
port, version and datadir on every request. Its separate CGI PDO adapter does not
weaken Database::forSchemaValidation() or tests/integration-bootstrap.php. The
HTTP host executes control.php preflight before binding. guards.php tests rejection
of unexpected configuration/identity values offline. These are negative validator
tests, not a claim that every identity failure was injected into a live server.

The dedicated test account and fixed gighub_schema_test DSN are used for requests,
fixtures, inspection and cleanup. control.php uses the existing CLI guard; no
database subprocess is spawned by the PHP application. The HTTP host launches
only the CGI front controller with the same fixed test database. Each process
has a bounded request budget; no automatic force termination/retry is implemented.

Session files, cookie name, fixture keys and test-only clocks are kept under a
new restricted run directory outside Git and htdocs:
C:/MariaDB_Recovery/GigHub_Clean_Test/http-acceptance/accept-<16 hex digits>.
Only SYSTEM, Administrators and the current Windows account have access.
Known synthetic fixture passwords are test source values, never real credentials.
Do not print private configuration, keys, cookies, raw CGI errors or full responses.

## Privileges and cleanup

privileges.php is CLI-only administrative lifecycle tooling, never imported by
the server/request path. It loads the existing secured admin file for identity,
read-only baselines and explicitly authorized grant/revoke operations only.
Before any grant it requires the test schema to be empty, records sanitized
privilege metadata (never SHOW GRANTS authentication hashes), and inspects foreign
keys/triggers. Only users/client_profiles/freelancer_profiles/email_verification_tokens
can receive temporary table-level DELETE. No password/global/application privilege changes.

Do not run privileges.php grant without explicit authorization. Always arrange
cleanup and revoke in a finally block, even when a test or browser step fails.
Revoke opens a separate identity-verified administrative connection and restores
the recorded original privileges. Never use administrator credentials for requests.

Every created account uses http<run-id>-<label>@example.invalid. The request front
controller rejects valid-format registrations outside this marker. Profiles/tokens
are traced through those user IDs. Cleanup disables mutations, requires the other
16 tables to remain empty, and transactionally deletes only marked IDs in order:
tokens, freelancer profiles, client profiles, users. No unrestricted DELETE/TRUNCATE.
If cleanup fails, stop testing, retain evidence and still revoke temporary grants.

## Running a reviewed run

1. Create a new restricted runtime with run.json (run, port=8765, unique cookie,
   integer clock, mutations_enabled=false), sessions/, and random private stop.key.
2. Record Git/file hashes; run privileges.php before <run> and guards.php.
3. Start python server.py --run <run> in a managed foreground session. Preflight
   must pass before listening; prove GET and mutation-disabled responses.
4. After independent connection-path review and explicit privilege approval, run
   privileges.php grant <run>, verify denial to development, enable only this
   run's mutations, and run control.php seed <run>.
5. Run python http_checks.py <run>; perform separately authorized browser checks.
   Controls receive JSON on stdin, not credentials/keys on command lines.
6. Stop browser activity and set mutations_enabled=false. POST /_shutdown with
   X-Test-Control from private stop.key; this exits the host loop gracefully.
7. Run control.php cleanup <run>, always run privileges.php revoke <run> in finally,
   then privileges.php after <run>. Verify test-account denial and empty tables.
8. Remove only this run's temporary key/session files; preserve sanitized evidence.

## Scope and limits

This tests actual controllers and native CGI HTTP/session behavior, not Apache's
rewrites, TLS, reverse proxies or deployment headers. Header emission mirrors the
front-controller contract. No OS-level outbound firewall sandbox is installed;
application mail is hard-disabled and SMTP classes are not loaded. Fixture clocks
and CLI-edited private test sessions simulate expiry boundaries without changing
Windows/MariaDB time. Complete concurrency stress is not claimed.

Actual browser zoom, disabled-JavaScript browser behavior, password-manager autofill,
screen-reader operation and other browser engines must be reported separately if
the available browser backend cannot exercise them. HTTP fallback checks do not
replace a JavaScript-disabled browser test.

## Job Posting mode — completed local acceptance

Later authorized Module 11A acceptance passed 26 real MariaDB integration checks, 50 HTTP functional checks and 3 startup safety checks. All test fixtures were cleaned up, temporary privileges restored and development integrity preserved. The offline total after UI corrections was 762 passing checks. Browser acceptance passed for exercised flows at 1280, 768, 375 and 320px; the final read-only development smoke test passed at 1280 and 320px after the approved 20-Skill local seed. Fresh environments still require a separate controlled Skills seed.

Outstanding, not passed: 200% browser zoom, JavaScript-disabled browser behavior, screen-reader speech output and other browser engines. See docs/job-posting-phase2-tests.md for reconciled outcomes and remaining limits. Future mutating runs require separate approval and fresh safety preflight.

### Historical Phase 2A preparation (not executed during that phase)

Use only a NEW restricted run directory with explicit job_posting_enabled=true. The server selects job-control.php preflight; front.php adds actual Job Posting controllers/services with the same guarded test PDO and a tracked test repository. No active configuration is loaded. Job mode rejects Registration/Verification mutations. Normal Authentication mode is unchanged.

Do not execute job-posting-database.php or job_http_checks.py without separate approval. Job-specific seed/inspection/cleanup lives in job-control.php, with exact-ID inventory in job-fixtures.php. Old control.php refuses its own seed/cleanup/issue in job mode. Existing privileges.php remains limited to the original Authentication grants; it MUST NOT be used to provision job permissions.

The full preparation, required privileges, fresh-runtime requirement, cleanup ordering and Phase 2B prerequisites are in docs/job-posting-phase2-tests.md. No fixture/grant/HTTP execution occurred while preparing this mode. Synthetic catalogue rows have no relationship to a future development catalogue seed.
