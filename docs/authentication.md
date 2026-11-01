# Login and Logout â€” Module 10C

## Approved scope

PHP 8.2, existing PDO/users schema, native sessions and no new dependency. Email/password sign-in supports only active, email-verified client/freelancer/admin accounts. Public Registration still permits only client/freelancer; Admin provisioning remains separate. No remember-me, password reset, password rehash, wallet/credit or automatic email behavior is implemented.

## Request flow

UI navigation refinement (2026-11-01): Login presents the approved generic rejection heading/message below. Its password-recovery position is plain, non-clickable `Forgot password? (Coming soon)` text; no reset endpoint exists. Login contains no verification or resend navigation; those actions remain within the Registration/Email Verification flow. Pending accounts receive the same generic Login rejection as other failed attempts. Registration offers `Already have an account? Sign in` below the form, and all six Verification page states offer `Already verified your email? Sign in`. The verified state uses that shared link rather than a duplicate sign-in action. No eligibility, hashing, token, throttle, session, schema or private configuration behavior changed.

Refinement verification: 265 relevant offline checks passed (59 Authentication, 10 navigation, 6 native session, 17 Authentication UI, 69 Registration, 104 Verification), six changed PHP files passed syntax checks, and nine isolated HTTP GET navigation checks passed. Real browser link navigation and 320px Login layout passed. Database fixture tests were unnecessary for copy/link-only changes and were not run. No mutating application HTTP requests, new privileges, SMTP or development-data writes occurred; development digests/config/schema and original grants remained unchanged. The read-only harness stopped gracefully.

- `GET ?route=/login`: anonymous form; eligible signed-in users redirect to their fixed role home.
- `POST ?route=/login`: login CSRF, bounded input, session throttle, prepared email lookup, `password_verify()`, current eligibility re-read, checked session regeneration and 303 redirect. Email follows Registration's trim/lowercase convention. Password is never normalized, truncated, repopulated or stored in session. Login does not reapply Registration's blocklist/minimum/128-character policy; valid UTF-8, nonempty, NUL-free input and the 1024-byte safety limit bound verification work.
- Unknown users use a public fixed dummy Argon2id hash. All valid-format wrong credentials and ineligible accounts receive the same 422 heading `We couldn't sign you in` and message `Please check your email address and password, then try again.` This reduces enumeration; it does not promise indistinguishable timings. Registration's explicitly approved duplicate-email wording remains unchanged.
- CSRF failure: 403; field/credential rejection: 422; throttling: 429 with Retry-After; service/database/session establishment failure: sanitized 503. No raw SQL/driver details or submitted credentials are logged.
- `POST ?route=/logout`: logout CSRF; clear session data, expire the cookie using its original scope/security parameters, destroy server-side session, 303 to Login. `GET /logout` is 405. Logout requires no database connection.
- Fixed protected homes `/client`, `/freelancer`, `/admin` are placeholders with escaped account name and a POST logout form, not complete dashboards. No request-controlled redirect is accepted.

## Sessions and authorization

SecureSession retains strict cookie-only sessions, HttpOnly, SameSite=Lax, session cookie lifetime and `/GigHub/public/` scope. Secure is enabled for direct HTTPS; local HTTP is explicitly a development limitation. No untrusted forwarded header is used. Public use requires HTTPS.

AuthSession checks regeneration before installing identity, clears prior anonymous data/CSRF tokens and stores only `auth.user_id`, `auth.issued_at`, `auth.last_activity` plus normal session initialization/CSRF state. Roles, hashes, email addresses and passwords are not stored in the authentication identity. Successful login starts a fresh throttle history.

Thirty minutes without authorized activity or eight hours since login expires authentication at the exact boundary. Missing, malformed, future or inconsistent timestamps fail closed. Each protected request reloads current status, verification and role from the database; a missing/pending/suspended account loses authentication. Anonymous users redirect to Login; wrong role returns 403; database failure returns 503 with no protected content and no activity extension. Eligible authorized activity refreshes idle time, never absolute lifetime. An already-authorized in-flight request is not retroactively cancelled by a later suspension; future business transactions must enforce their own authorization at execution time.

LoginThrottle records five failures per anonymous session within a rolling 900 seconds. A sixth attempt is blocked before querying/hashing. Malformed CSRF-valid attempts count; CSRF-invalid attempts do not. Successful credentials are also blocked while the session is limited. No account lock or database write occurs. Cookie reset/logout/new sessions bypass this local safeguard; shared IP/account protection is required before public deployment.

## Architecture and isolation

AuthenticationController returns the existing response arrays and uses a lazy AuthenticationService factory. AuthenticationService and AuthGuard use the existing injected UserRepository/PDO conventions. Authentication reads only; it never constructs SMTP or changes user/token/wallet records. CSRF has distinct registration, verification, login and logout purposes. Existing verification token/transaction/resend rules are unchanged.

`tests/authentication-database.php --run-isolated` uses exactly one PDO from the shared CLI integration bootstrap for main queries, fixtures, service/guard and rollback cleanup. No secondary/subprocess connections exist. Existing Verification's separate contention PDO also uses the shared bootstrap. No suite falls back to active application configuration. All test fixtures are synthetic and rolled back in `finally`; all 20 table row counts/content digests are compared after cleanup. Auto-increment gaps may remain in the isolated database only.

## Verification coverage and historical implementation acceptance

- `php tests/authentication.php`: offline repository/session doubles, eligibility, generic failure, password preservation, redirects, CSRF, expiry, throttling, RBAC and sanitized failures.
- `php tests/authentication-session.php`: real native CLI session regeneration, old-session deletion, cookie parameter configuration, destruction and ID replay rejection. Temporary synthetic session files are removed. This does not inspect actual browser Set-Cookie headers.
- `node tests/authentication-ui.cjs`: DOM fixtures for visibility labels/value preservation/focus and markup associations; not a browser accessibility audit.
- `php tests/authentication-database.php --run-isolated`: actual isolated MariaDB authentication, roles/statuses, prepared queries, fresh suspension checks and rollback invariants.

Do not run mutating HTTP/browser tests against the active development app. A dedicated loopback test harness with isolated credentials will be designed separately without weakening the CLI guard. Outstanding acceptance includes actual browser cookie/redirect behavior, session fixation/replay across requests, POST logout, JavaScript-disabled Enter submission, password-manager autofill, screen readers, mobile width 320px, 200â€“400% zoom and cross-browser checks. Existing HTTP suites are not run during this phase because they exercise active Apache configuration. Mail and diagnostics remain disabled.

No schema, active private configuration, SMTP setting, dependency or database permission change is part of 10C. No staging, commit or push is authorized. 10B and 10C now share bootstrap and verified-result view/test changes, so a future separate 10B commit requires explicit hunk selection; do not stage those mixed files wholesale as 10B-only.

### Recorded implementation verification â€” 2026-11-01

512 offline checks passed: 32 isolated-bootstrap, 23 Foundation, 69 Registration, 104 Verification, 119 combined SMTP/diagnostics, 58 Authentication, 6 native CLI session, 52 Registration UI, 32 Verification UI and 17 Authentication UI checks. SMTP's 76 base checks are included in 119 and are not counted twice. The final Authentication and session suites were rerun after logout-cleanup hardening.

181 isolated database checks passed: 52 Registration, 81 Verification and 48 Authentication. All fixtures rolled back. Separate read-only isolated PDO validation confirmed MariaDB 10.4.32, strict session SQL mode, UTC, utf8mb4, native prepared statements and the expected 20 tables. Main/setup/fixture/cleanup/secondary connection paths were inspected before execution; test processes received a cleared minimal environment, and the account was independently denied SELECT on development both before and after fixtures.

All 20 development tables remained readable; canonical row-content digests and row counts matched the pre-test baseline. Private active configuration and schema file hashes matched; mail and diagnostics remained false; test tables were empty afterward. No service or application account permissions changed. Evidence is stored outside Git under `C:/MariaDB_Recovery/GigHub_Clean_Test/verification/10c-*`.

57 PHP files passed syntax checks; new JavaScript passed syntax checks. Tracked Git whitespace and untracked trailing-whitespace checks passed. Candidate source review/high-confidence secret-pattern scanning found no exposed credential material; the public dummy Argon2id hash and example.invalid fixture strings are deliberate synthetic values. Ignored local.php, vendor and logs are not tracked or staged. This scan is not a guarantee against every possible secret format.

Registration/Verification HTTP suites and real browser authentication tests were skipped under the approved separate-harness scope. No real SMTP, development fixture writes, HTTP login/logout requests, staging, commit or push occurred. At that implementation stage, core automated checks passed and isolated HTTP/browser acceptance was still pending. The subsequent acceptance record below supersedes that pending status.

### Exact implementation scope

Created: `app/Controllers/AuthenticationController.php`, `app/Controllers/AccountHomeController.php`, `app/Core/AuthSession.php`, `app/Core/AuthGuard.php`, `app/Core/LoginThrottle.php`, `app/Services/AuthenticationService.php`, `app/Services/LoginValidator.php`, `app/Views/login.php`, `app/Views/account-home.php`, `public/assets/login.css`, `public/assets/login.js`, `tests/authentication.php`, `tests/authentication-session.php`, `tests/authentication-database.php`, `tests/authentication-ui.cjs`, `docs/authentication.md`.

Modified this phase: `app/Core/Csrf.php`, `app/Repositories/UserRepository.php`, `app/Views/home.php`, `app/Views/verification.php`, `bootstrap/app.php`, `tests/verification-http.php`, `docs/email-verification.md`. Other visible 10B/isolation changes predate this phase and were preserved.

## Reconciled local acceptance — 2026-11-01

This record distinguishes completed phases from current reruns; it does not replace historical counts above.

- Offline implementation regression: 512 checks passed in the recorded implementation phase. Later UI refinement recorded 265 relevant offline checks, including 59 Authentication checks. These overlapping totals must not be added together.
- Isolated database integration: 181 checks passed (52 Registration, 81 Verification, 48 Authentication), using only `gighub_schema_test`. Fixtures rolled back; development counts/content digests were unchanged.
- Subsequent isolated HTTP acceptance: **93 checks passed** using the loopback CGI harness, dedicated test credentials and synthetic run-marked fixtures. Temporary DELETE privileges were revoked after fixture cleanup; all 20 test tables were empty. Development counts/content digests and active private configuration matched the recorded baseline. Evidence is outside Git under `C:/MariaDB_Recovery/GigHub_Clean_Test/http-acceptance/accept-856697495fef4ff3/`.
- Completed actual browser checks: Login Enter submission, password visibility control by click/keyboard, visible keyboard focus, successful role navigation, logout/back-button behavior, Registration Enter submission and role switching, explicit verification confirmation with no GET activation, link replay rejection, and responsive Login/Registration layouts at 320px. These checks used synthetic isolated fixtures; the later navigation-only refinement also checked browser links and Login at 320px.
- Final navigation correction: no Verification/resend link on Login. Registration and all six Verification states retain Sign in links. Forgot password remains non-clickable Coming soon text; Login links to Registration. Current rerun results are reported separately at completion rather than relabeling historical acceptance as a new run.
- Outstanding manual coverage: actual browser zoom (200–400%), JavaScript-disabled browser behavior, real password-manager autofill, screen readers and other browser engines. HTTP fallback checks do not prove JavaScript-disabled browser behavior. The harness does not establish Apache/TLS deployment behavior.
- Deferred: complete verify/resend/suspend concurrency stress and shared IP/account throttling. Session-only throttling is local-development protection and can be bypassed by new sessions. This is local functional acceptance, not complete public-deployment readiness.

Final correction verification (2026-11-01): 11 offline navigation, 59 Authentication, 6 native CLI session and 17 JavaScript UI fixture checks passed. Eleven isolated navigation-only HTTP checks passed using GET requests with mutations disabled; the host stopped gracefully. No fixtures, grants, SMTP or development writes were performed. Actual browser interaction was not rerun for this final link-only correction; the browser evidence above belongs to the prior acceptance phase.
