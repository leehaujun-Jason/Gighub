# Isolated Authentication HTTP acceptance harness

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
