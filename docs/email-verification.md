# Email verification — Phases 10B-A and 10B-B1

## Current recovery and isolated-test acceptance — 2026-11-01

This section supersedes the operational status in the historical acceptance records below. Those test counts and observations are retained as history, not presented as newly rerun evidence.

- Active development uses the recovered clean MariaDB instance at `127.0.0.1:3309 / gighub_dev`. The original damaged installation and recovery copies are not application/test targets.
- Effective private `mail.enabled` and `mail.diagnostics` were verified as boolean false during the current preflight. Private configuration and SMTP credentials were not modified. The prior enabled-flag discrepancy is resolved.
- Integration tests no longer use development credentials. `tests/integration-bootstrap.php` requires explicit CLI `--run-isolated`, loads only `C:/MariaDB_Recovery/GigHub_Clean_Test/private/gighub-test.local.php`, and validates the exact host, integer port 3309, database `gighub_schema_test`, account `gighub_test`, server version and clean data directory. Missing/invalid settings fail closed. No `Config::load()` fallback exists; database/mail environment overrides are removed.
- Main, fixture, cleanup and Verification contention connections use this bootstrap. Repositories/services receive the isolated PDO; fixture suites create no subprocess database connections. The dedicated account is denied access to `gighub_dev` and has only test-schema SELECT and the table-level INSERT/UPDATE needed by existing fixtures. Credentials remain outside Git and the web root.
- Phase 3K-2 recorded 431 offline checks, 52 Registration and 81 Verification isolated database checks, plus read-only PDO validation passing. Fixture rows were rolled back; all 20 development row counts/content digests remained unchanged. Test-only auto-increment counters may advance. These are prior-phase results, not today's regression totals.
- Login 10C adds a sign-in link to the verified result page; verification still never authenticates a user, creates a wallet or issues credit. The corresponding rendering assertion now expects that link while still asserting no authentication state.

Current isolated commands: `php tests/registration-database.php --run-isolated`, `php tests/verification-database.php --run-isolated`, and `php tests/pdo-connectivity.php --run-isolated`. Never use development configuration for fixture tests. Historical commands/targets below do not authorize current execution against development.

Known limitations remain: session-only anonymous throttling is bypassable; complete concurrent verify/verify, resend/resend and verify/suspend schedules are unproven; SMTP acceptance is not inbox delivery; localhost links are local-computer-only. Invalid enabled SMTP configuration currently also prevents confirmation because the shared factory constructs the transport. Correcting that availability coupling is deferred. Generic result-page Delivery notice/resend copy remains a non-blocking UX follow-up. The completed isolated HTTP/browser harness never loads active development configuration; its subsequent acceptance is recorded in the Reconciled local acceptance section of `docs/authentication.md`.

The earlier combined 10B/10C implementation regression recorded 512 offline checks and 181 isolated database checks passing, plus read-only isolated PDO verification. All 20 development row counts/content digests remained unchanged; active private configuration and schema hashes matched. HTTP/browser suites were deliberately not run in that implementation phase; the Reconciled local acceptance section of `docs/authentication.md` records the subsequent 93-check HTTP acceptance and supersedes that historical skip. Detailed breakdown and limitations: `docs/authentication.md`. This is not a new real-email delivery test.

Future commit readiness: reviewed 10B SMTP classes, Composer manifest/lockfile, disabled configuration defaults/example, pending/result copy, SMTP tests and shared isolated-test support contain no identified Critical/Major blocker for a local-development baseline. No credentials, private configuration, vendor, logs, exports or unrelated recovery files belong in that commit. `bootstrap/app.php`, `app/Views/verification.php`, `tests/verification-http.php` and this document now also contain 10C integration changes: use explicit hunk review for separate commits, or separately approve a combined scope. Nothing is staged or committed.

## Historical final acceptance review — 2026-10-31

The user reports that a controlled Gmail verification message arrived in Inbox, explicit confirmation succeeded, the page stated active/not logged in, and link reuse was rejected. Read-only database acceptance independently confirmed the specified Client is active with a populated verification timestamp, one consumed token, no usable outstanding tokens, and no wallet or wallet-entry rows. No address, key or hash is recorded here. Inbox arrival and browser session behavior are user-observed evidence, not independently replayed during this review.

Fresh regression: 479 checks passed (119 combined SMTP/diagnostics, 104 verification unit, 69 registration unit, 23 foundation, 61 verification controller/HTTP, 19 registration HTTP, 32 verification JavaScript, 52 registration JavaScript). Database integration suites were skipped: both explicitly target gighub_dev and insert rollback fixtures; they are not a separate isolated database setup and can advance auto-increment counters. Current instructions prohibit modifying gighub_dev. The reserved schema-test connectivity script was also not run. Full database regression therefore remains a commit-readiness gap pending an approved test strategy. Composer strict validation/audit and whitespace checks passed.

Configuration discrepancy: the effective private mail.enabled and mail.diagnostics flags were both still enabled during this review, contrary to the reported shutdown. No private setting was changed. Restore both to false locally and recheck before sign-off.

Non-blocking UX follow-ups: the generic Delivery notice is unnecessarily shown on successful and invalid-link pages; the invalid-link page offers resend even when the account is already active. Anonymous invalid-link handling cannot safely infer or disclose account state. A future copy pass should explain that resend applies only to eligible pending accounts while preserving generic responses. No UI redesign was made.

Existing limits remain: session-based anonymous throttling is bypassable by cookie reset; full concurrent resend/confirmation/suspension schedules remain unproven. No SMTP connection, send, account mutation, commit or push occurred during this acceptance review.

## 10B-B2: safe failure diagnostics

Two controlled attempts reportedly produced no inbox/spam message. Their historical root causes remain unknown: the original adapter discarded every exception and mapped all failures to uncertain. Password grouping spaces were corrected before the second attempt; that does not establish an authentication diagnosis.

The adapter now installs `DiagnosticSmtp`, a thin PHPMailer SMTP subclass recording only stage, DATA-attempt/acceptance flags and a strictly validated three-digit 200–599 response code. It never parses or exports exception messages, SMTP error/detail strings, addresses, bodies, credentials or tokens. STARTTLS, authentication and recipient failures are attributed only at their corresponding API boundaries. DNS/TCP and implicit SMTPS certificate failures share connection classification; a generic connection failure cannot prove its underlying cause. Unexpected exceptions use unknown before DATA and delivery_uncertain after DATA begins. Untracked/injected SMTP implementations conservatively remain unknown/uncertain.

Definite failures before DATA throw the existing generic transport exception, so EmailVerificationService returns failed. An explicit 4xx/5xx DATA rejection also throws (category unknown, numeric code retained because no delivery_rejected category is approved). DATA timeouts/disconnects without a proven negative reply, and failures after DATA acceptance, remain delivery_uncertain. Successful send still returns accepted, never proof of inbox delivery. No automatic retry or token revocation is introduced. Configuration/factory failures record configuration and throw the same generic exception. User-facing controllers, token rules, transactions and resend limits are unchanged.

`SmtpDiagnostics::last()` exposes only an ephemeral in-process `{category, code}` record, reset per factory/send operation. Nothing is added to sessions or public responses. For a future **separately approved** live test, manually add `'diagnostics' => true` inside the private mail section while `app.environment` is exactly local. This explicitly enables one fixed-format PHP error-log line per failure: `[GigHub SMTP] category=<allowlisted-label> code=<three-digit-code-or-none>`. Leave SMTPDebug at zero. Inspect only these fixed lines locally in the configured Apache PHP error log; do not share general log dumps. Remove/disable the flag afterward. Production ignores the flag. No configuration was changed or diagnostics enabled during implementation. No Gmail connection or live email was attempted. Previous sections describing all exceptions as uncertain are historical and superseded by this section.

Offline `tests/smtp-diagnostics.php` extends the SMTP suite with configuration, connection, TLS, authentication, recipient, explicit rejection, timeout, unexpected exception, numeric-code sanitization and production/local logging-gate checks. Doubles replace socket operations; temporary diagnostic test output is removed on close. No database fixtures or live HTTP sends are needed.

## Current phase: 10B-B1 SMTP integration (2026-10-31)

PHPMailer **7.1.1** is installed using Composer **2.11.1**, on PHP **8.2.12**. Composer extracted its distribution archive using the available extraction tools; the missing PHP ZIP extension did not block installation. No php.ini, XAMPP setting, database permission or schema was changed. `composer.json` declares PHP ^8.2, OpenSSL, mbstring and PHPMailer ~7.1.1; `composer.lock` pins the installed version. `vendor/` remains ignored. Install the locked dependencies on another checkout with `composer install --no-interaction --no-scripts --no-plugins`. Do not copy PHPMailer manually. Its LGPL-2.1-only license is retained in the installed package; the application metadata does not relicense that dependency.

Official references reviewed: [7.1.1 release](https://github.com/PHPMailer/PHPMailer/releases/tag/v7.1.1), [dependency requirements](https://github.com/PHPMailer/PHPMailer/blob/v7.1.1/composer.json), and [SMTP example](https://github.com/PHPMailer/PHPMailer/blob/v7.1.1/examples/smtp.phps). The example's verbose diagnostics and browser ErrorInfo output are deliberately not used.

### Implementation and configuration contract

- `bootstrap/app.php` loads Composer's autoloader when present, keeping the existing explicit application requires and lazy database/service construction. `MailTransportFactory` replaces the hardwired capture. The verification service, token repository, account rules and transaction boundaries are unchanged.
- `mail.enabled` is a strict boolean, **false by default**. `DisabledMailTransport` cannot send. Existing tokens can still be confirmed while mail is disabled. A disabled-send attempt is handled as an existing recoverable transport failure: issuance may already have committed and consumed cooldown/hourly allowance; there is no automatic retry or token revocation.
- When enabled, `mail.transport=smtp` selects `PhpMailerTransport`. Missing PHPMailer, OpenSSL, invalid or incomplete settings produce only `Mail transport unavailable.` without chained internal details. There is no PHP mail()/sendmail or capture fallback. Because confirmation and issuance share a service factory, invalid enabled SMTP settings also make confirmation temporarily unavailable; they do not consume a confirmation key. Disable mail or correct configuration to restore that service.
- Explicit `mail.transport=memory` is allowed only with `app.environment=local` or `test`, and still uses request-lifetime `InMemoryMailTransport`. It is rejected in production. Automated core tests continue to inject their fake directly.
- `mail.host`: one DNS hostname or IPv4 address, no scheme, embedded port, whitespace or semicolon-separated fallback list. IPv6 literals are not supported by this minimal configuration validator.
- `mail.port`: integer 1–65535; provider's port must match its encryption mode. `mail.encryption`: exactly `starttls` (normally port 587) or `smtps` (normally 465). Authentication and explicit encryption are always required; no opportunistic plaintext fallback. Certificate/hostname verification stays enabled and self-signed certificates are rejected.
- `mail.username`, `mail.password`: nonempty private strings, maximum 4096 bytes; NUL/CR/LF and angle-bracket placeholders are rejected. No real credentials are in source, tests or the configuration example.
- `mail.from_email`: fixed, valid configured sender, maximum 254 bytes. `mail.from_name`: configured UTF-8 name, maximum 400 bytes without control characters. Neither identity is accepted from form input. Each message has exactly the recipient supplied by the existing verification service.
- `mail.timeout`: integer 1–30 seconds, default 10. Both connection/socket timeout and SMTP command time limit are set. This is **not a total wall-clock deadline**: multiple commands/DNS resolution and PHPMailer's extended DATA handling can take longer.
- `verification.base_url` retains the existing validation: absolute HTTPS, or HTTP for the exact supported loopback hosts, directory path, no userinfo/query/fragment/control characters. It is never derived from Host. The localhost default only works on this computer; approve a suitable trusted URL before external testing.

`config/local.example.php` supplies placeholders with mail disabled. Put approved real settings only into ignored `config/local.php` later, without replacing its existing database configuration. The private file was not modified in this phase. Effective configuration was checked without printing credentials: mail remains disabled.

### Sending and recovery

The adapter builds a fresh PHPMailer instance for each attempt, uses UTF-8 HTML plus explicit plain text, a fixed configured sender, authenticated SMTP, no keepalive, SMTP debug level zero and a no-op diagnostic sink. No attachments, external image fetching, credentials or raw keys are logged. The existing fragment URL and manual key remain unchanged.

The existing service commits token issuance **before** invoking the transport. An accepted result means only SMTP acceptance, not inbox delivery. A false send result or exception becomes `uncertain`, with no automatic retry, exception disclosure or token revocation. Configuration failures are generic and caught by existing recovery handling. Registration remains committed and pending. Resend outcomes remain generic across unknown, ineligible, limited and failed-delivery accounts. The pending page now handles `accepted` explicitly; it no longer falsely asserts that no mail was sent after uncertain/accepted results. General verification pages make no delivery claim.

### Fresh verification evidence

All ten suites passed: **569 checks**.

| Suite | Checks |
| --- | ---: |
| SMTP (`php tests/smtp.php`) | 76 |
| Verification unit | 104 |
| Verification JavaScript | 32 |
| Verification controller/HTTP | 61 |
| Verification rollback database | 81 |
| Registration unit | 69 |
| Registration JavaScript | 52 |
| Registration HTTP | 19 |
| Registration rollback database | 52 |
| Foundation | 23 |

The SMTP suite tests invalid configuration, disabled/fake/real selection, production fake rejection, missing dependency in a separate process, exact recipient/fixed sender, real multipart MIME construction, debug suppression, exception sanitization and single-attempt failure handling. Test doubles replace PHPMailer's SMTP/socket boundary; its real SMTP orchestration is also exercised for STARTTLS-before-authentication and stopping after connection/TLS/authentication/DATA failure. **No test opens an SMTP socket or sends external email.** This does not prove actual TLS negotiation, authentication, provider acceptance or inbox delivery.

The two database suites ran sequentially against guarded `gighub_dev` with synthetic outer-transaction fixtures and rollback. All 20 table counts/content hashes matched after verification cleanup. Existing records were preserved; auto-increment counters may advance. No test ran against `gighub_schema_test`; the historical CLI-only `tests/pdo-connectivity.php` targets that reserved database and was deliberately not run. Registration's injected failure case emits its existing generic persistence-failure diagnostic, without credentials. All 40 application/test/public-configuration PHP files passed syntax checks. Composer strict validation, platform checks and audit passed with no reported advisories. Git whitespace checks passed (only Windows LF-to-CRLF notices). The schema hash remains `29515ad151c5171642ac9a69eae44e27b635f27f55753bde511dde4d0d346286`.

### Remaining gate: 10B-B2 controlled real-email test

1. Obtain explicit approval for the SMTP provider, one controlled recipient, fixed verified sender and trusted base URL. Decide whether the recipient opens the link on this same XAMPP computer or needs a separately approved reachable HTTPS environment; do not expose XAMPP just for this task.
2. Privately configure the provider's approved host, port, TLS mode, credentials and sender in local.php. Do not post credentials into chat, screenshots, source control or debug logs. Confirm certificate trust works; report trust failures instead of disabling validation or changing php.ini automatically.
3. Explicitly enable SMTP for the approved test window. Perform one controlled registration or eligible resend, respecting the persisted cooldown/hourly cap. Check provider acceptance, inbox/spam delivery, sender identity, both HTML/plain text and fragment/manual-key behavior.
4. Explicitly confirm using CSRF-protected POST, then check replay/expiry/replacement and no automatic login. Do not automatically retry an uncertain send. Restore disabled mail after the test unless continued use is separately approved.

Real delivery, provider timeouts, mail-client link rewriting and successful inbox-to-activation behavior remain unverified. The earlier browser/manual and concurrency gates below still apply, as do session-only anonymous abuse-protection limitations. No commit or push was performed in 10B-B1.

## Historical 10B-A baseline

The sections below record the completed 10B-A implementation and its earlier approval gate. Statements about hardwired capture, no Composer or no SMTP describe that baseline; the current 10B-B1 section above supersedes them. They do not claim that real SMTP was tested.

## Final security review and 10B-B gate

The final 10B-A review covers the complete diff and every untracked candidate against Registration commit 2926a6a. It found no blocking security, data-integrity or unexpected-scope issue; an independent read-only reviewer reached the same conclusion. Source review confirms secure random generation, context-bound hashed storage, eligibility, ordered user-before-token current reads, atomic consumption/activation, persisted rate limits, explicit CSRF-protected POST, no token in generated HTTP query strings/session/error logs, and no mail attempt before an owned commit. No Login/wallet/credit or unrelated database operation is added. This is a scoped review and automated evidence, not a claim of complete security or real inbox verification.

Fresh final checks: all nine suites passed, **493 checks total** (Registration 69 PHP/52 JavaScript/19 HTTP/52 database; Foundation 23; Verification 104 PHP/32 JavaScript/61 controller-HTTP/81 database). All 36 checked PHP application/test/public-configuration files and both new JavaScript files passed syntax checks. Database fixtures were rolled back, all 20 table counts/content hashes matched their starting values, and synthetic prefixes were absent. Auto-increment counters may advance. Credential-pattern scanning found no hits in the 26 candidates; private local.php and generated screenshots are ignored, with no ignored file tracked. SQL schema and PDO database allowlist are unchanged. Only synthetic example.invalid data is used in fixtures.

Exact concurrency scenarios still unverified end-to-end with committed, independently visible fixtures and separate connections:

1. Two simultaneous confirmations of the same usable key, including a waiter resuming after the first commit.
2. Two simultaneous resend/issuance requests from different sessions at the 60-second and hourly-cap boundaries.
3. Resend versus confirmation of the older key, exercising both possible lock/commit orderings.
4. Suspension versus confirmation, including suspension committing first and verification committing first. The future suspension service must obey the shared user-row lock contract.
5. Email change versus confirmation/issuance. The future email-change service must lock the user and revoke outstanding tokens; this module does not implement that service.
6. A pre-existing REPEATABLE READ snapshot followed by a competing committed issuance and a waiting resend. Current locking reads are designed to see the committed history, but this full multi-service schedule has not been exercised.

The current bounded second-connection lock timeout demonstrates user-row contention only; unit event ordering and rollback tests do not replace those scenarios. Future tests need separately isolated committed disposable fixtures and an approved cleanup strategy, rather than committing/deleting records in the normal development database during this review.

Before enabling a 10B-B real transport: separately approve the PHPMailer/Composer dependency approach, provider/sender/TLS settings, privately provisioned credentials, a trusted reachable base URL and controlled recipient(s). Replace the hardwired in-memory transport deliberately; update pending/result messaging so the current unconditional "no external email" notices are shown only in capture mode. A transport acceptance must still not claim inbox delivery. Keep SMTP debug output disabled, certificate verification enabled, and request bodies/captures free of token logging. Test rejection, timeout after possible acceptance, no automatic uncertain-send retry, resend replacement, delayed/out-of-order delivery, HTML/plain text and email-client fragment/manual-key behaviour. No real delivery is configured, sent or proven by 10B-A.

The user now authorizes one reviewed local commit named `feat: implement secure email verification core` and no push. Earlier no-commit statements below describe the implementation phase before this final approval.

Approved scope: existing schema, hashed single-use email-bound random tokens, 30-minute expiry, persisted 60-second cooldown and five issuances/hour, explicit CSRF-protected POST, fragment links/manual-key fallback, in-memory mail transport and safe tests. No SMTP/dependency installation, schema/configuration outside the project, login, wallet, credit, commit or push.

Implementation plan:
1. Add clock-controlled regression tests against the approved core contracts.
2. Add the token repository, transactional service and replaceable in-memory mail transport.
3. Integrate after the existing registration transaction commits, with recoverable pending accounts on any verification failure.
4. Add lazy confirmation/resend/result routes and progressively enhanced accessible pages.
5. Run all registration/foundation/verification suites; use outer rollback fixtures only for database work, inspect browser behaviour, and request independent review.

Ruling: use the already intended local base URL `http://localhost/GigHub/public/` as a public default; never inspect Host to build verification links and never change private local.php. A future non-local base must be explicitly configured HTTPS.

Ruling: normal requests use a fresh in-memory capture transport and explicitly report that no external email was sent. Captured keys are available only to the injected test transport in memory, not an HTTP preview, session, file or log. Real end-to-end inbox delivery remains unavailable until a later approved adapter/configuration phase.

Ruling: token hashes include a domain tag, user ID, current canonical email and random secret in an unambiguous JSON encoding. The public row-ID selector locates the record, while the random 32-byte secret proves possession. Future email changes must still revoke outstanding tokens under the user lock.

## Implemented flow and transaction invariants

RegistrationService::register now returns true only when it committed its own existing user/profile transaction; a savepoint release returns false. RegistrationController invokes verification only after a true result. A failed verification factory, issuance or transport leaves the committed account/profile intact and pending, shows a truthful recovery notice, and never retries registration or mail automatically. Only delivery-status strings enter the registration flash. The change does not alter registration validation, Argon2id hashing or account creation rules.

EmailVerificationService::issue normalizes the email using the registration convention and starts a short transaction (or an isolated savepoint for tests). It locks the user before evaluating eligibility. Only pending Client/Freelancer accounts with NULL email_verified_at qualify. After acquiring the user lock it reads the clock in UTC and locks token history using current reads (FOR UPDATE), not a possibly stale REPEATABLE READ snapshot. A latest issuance less than 60 seconds ago or five records with created_at in (now-3600, now] prevents issuance. Revoked/used/failed-delivery records still count. Exactly 60 seconds and exactly 3600 seconds release their respective boundaries. A future-dated latest record also prevents issuance until the cooldown is satisfied.

Issuance revokes old rows only when used_at and revoked_at are NULL, then inserts one new hash with explicit created_at and expires_at=created_at+1800 seconds. Both operations commit together or both roll back. This preserves the mutually exclusive used/revoked CHECK. The hash is SHA-256 of JSON containing a domain tag, string user ID, current stored email and a fresh bin2hex(random_bytes(32)) secret. The email key is public selector ID + dot + secret. The raw key exists only in request/test memory, the email content and the recipient's browser form; MariaDB stores only its hash. No selector is accepted above the UNSIGNED INT limit.

Consumption validates bounded key syntax before opening a transaction. It performs a non-locking selector lookup, then locks the user and token in that order and checks current eligibility, user/token ownership, context-bound hash using hash_equals, unused/unrevoked state and now < expires_at. Exactly at expiry the key fails. It marks the token used, conditionally changes only a still-pending eligible user to active with email_verified_at, and revokes any other outstanding unused tokens in one atomic scope. Replays, expired/replaced keys, changed email, active users, suspended users and Admins cannot activate an account. An activation/write failure rolls back token consumption. Future suspension/email-change services must acquire the same user lock and email changes must revoke outstanding tokens; this phase does not implement either module.

EmailVerificationService::request refuses to run inside an existing caller transaction. It issues/commits first, checks no transaction remains, then calls MailTransport once. A transport failure or uncertain result does not revoke the committed token: it may already have been accepted elsewhere. There is no automatic retry, queue, outbox or exactly-once-delivery promise. Older or delayed messages may arrive after replacement in a later real-SMTP phase; only the latest unexpired key can work. Retention/cleanup is deferred; no token history is deleted, so the persisted rate limit remains auditable.

## Transport and configuration

MailTransport is a small replaceable interface. InMemoryMailTransport records messages/attempts only in its object and supports captured, failure, uncertain and simulated accepted outcomes for tests. Its messages are sensitive and must never be serialized/logged or placed in sessions. Normal bootstrap hardwires a fresh captured-mode transport; it has no network code or configurable SMTP adapter. The capture disappears at request end. There is deliberately no HTTP debug inbox/key endpoint, log/file outbox or session key preview. This means normal users cannot receive a usable email in 10B-A; the development UI says so. Automated tests inspect their injected capture in memory. A simulated accepted outcome is not a claim of real delivery.

The only added configuration is public verification.base_url in defaults.php/local.example.php. The existing ignored local.php and all database credentials are unchanged. The URL must be an absolute HTTPS URL or HTTP with exactly localhost, 127.0.0.1 or [::1]; userinfo, query strings, fragments, control characters and non-directory paths are rejected. No Host/Forwarded header is consulted. The local default is useful only on the XAMPP computer. Future external delivery requires an explicitly approved reachable URL, adapter/dependency, SMTP host/port/TLS/sender/credentials and controlled recipient test; do not disable TLS certificate validation. No PHPMailer, Composer or credentials were installed/configured here.

## Routes, UI and privacy

- GET /verify-email renders a lazy, read-only confirmation form, never issues or consumes a token. The approved email URL is base + index.php?route=/verify-email#verification=<selector.secret>.
- verification.js clears every fragment with replaceState before interpreting it. Valid fragment data fills the form locally; it never fetches, submits automatically or sends a key in a query string. Invalid fragments are cleared and show recovery guidance. If clearing fails, no fragment key is accepted. No localStorage/sessionStorage is used. The browser can still expose links to extensions/history sync before clearing; fragments are not protection against a compromised client.
- The primary action is Confirm Verification. A secondary details section supports copy/paste without JavaScript or when a mail client strips the fragment. Keys are never reflected into server-rendered error pages or persistent sessions. The manual field is empty after any server response. Native form POST includes the key in the body and a purpose-separated CSRF token.
- POST /verify-email returns 303 to /verify-email/result after valid CSRF processing. Success uses only a one-time verified flash, invalid/replaced/expired/replayed keys use an invalid flash, and transient verification failures use unavailable so a still-valid key can be retried. The result route does not activate anyone. CSRF failures return 403; a missing/consumed result flash redirects to the confirmation form.
- GET/POST /verify-email/resend provides a labelled email form, CSRF and POST/redirect/GET. Every valid public email request receives the same conditional result irrespective of unknown/active/suspended/limited/transport outcomes. Invalid email syntax receives 422 with bounded escaped recovery. The request-wide session limit gives 429 and Retry-After equally for all account states.
- VerificationThrottle allows ten CSRF-valid confirmation/resend requests per anonymous session per rolling ten minutes, including malformed/unknown attempts. Cookie resets bypass this session limit, but persisted per-account issuance cooldown/hour cap survive new sessions. The hourly cap includes initial registration issuance, not five extra resends.
- Session data contains only CSRF tokens, request timestamps and small public outcome strings; no verification keys, raw tokens, user identity, password or authentication state is stored. Public resend bodies are generic, but timing can still differ. The existing explicit registration duplicate-email message remains approved and enumerable. Shared abuse prevention remains necessary before public deployment.

The pages reuse registration colours, card/forms and focus rules, with minimal responsive verification-specific CSS. They provide distinct pending/confirm/success/invalid/unavailable/resend/result states, accessible error/status content, explicit no-login text and no link to an unimplemented Login module. Pending and resend pages explicitly state no external mail is sent. Application errors suppress driver/transport details, and no debug mail/token output is produced. Existing no-store, no-referrer and CSP headers remain in effect. Raw tokens never enter generated request query strings; protect POST bodies, mail captures and browser tooling from logging in any future deployment.

## Verification evidence

- Verification PHP unit/integration-boundary doubles: **104 PASS**. Includes commit-before-send, no send after failed insert/commit/rollback, captured/failure/uncertain modes, trusted URL/Host isolation, eligibility, locking/clock order, CSRF purpose isolation and registration callback ordering/failure/savepoint suppression. Doubles do not prove live database isolation.
- Verification database suite: **81 PASS**, explicitly opted in to gighub_dev with the dedicated account, all 20 expected InnoDB tables and an outer rollback. Includes 1799/1800-second expiry, 59/60-second cooldown, five-per-hour/3600-second boundary, context hash, replacement/replay/email binding, freelancer success, suspension/Admin/active rejection, savepoint rollback restoring revoked/used state, and no financial/login side effects. A second connection with a one-second SESSION-only lock timeout was blocked by the synthetic user lock; both connections were rolled back. All 20 table counts AND row-content hashes matched before/after, and the synthetic prefix was absent. No DELETE, DDL, reset, permission change or schema-test operation. Auto-increment counters may advance.
- Verification controller/actual Apache HTTP suite: **61 PASS**. Includes separate CSRF purpose, lazy scanner GET, no key reflection/session identity, generic resend equality across stub outcomes, explicit transient recovery, PRG, rate limit, malformed request handling and HTTP 403 for private mail/service sources. HTTP uses malformed synthetic keys/inputs rather than committing accounts.
- Verification JavaScript fixture suite: **32 PASS**, including valid/malformed/oversized selector fragments, clearing before parsing, clear failure, explicit POST only, no automatic fetch/activation, manual fallback and summary focus. This is a small DOM fixture, not a browser engine.
- Existing Registration suites: **69 PHP**, **52 JavaScript**, **19 HTTP**, **52 rollback-only database** checks passed; Foundation **23 PASS**. These preserve the 10A policy and boundaries while the new integration tests exercise the optional verification callback.
- Actual Chrome: a synthetic unissued fragment key was removed from the address bar and produced the ready-to-confirm state; no automatic activation occurred. Explicit keyboard confirmation reached safe invalid-key recovery. The secondary manual-key control opened and Enter submitted to the same recovery flow. An unknown synthetic resend address reached the generic PRG result. The desktop confirmation page was visually inspected; at 320px the page reflowed without horizontal overflow and the primary button remained within the card. Temporary viewport override reset. Screenshots are ignored artifacts tmp/verification-desktop.png and tmp/verification-mobile.png. An initial full-page mobile screenshot attempt timed out; standard viewport screenshots succeeded.
- All 34 PHP application/bootstrap/public/test files plus both modified public configuration files passed syntax checks, as did the JavaScript source/fixture files. Git whitespace checks passed (existing LF/CRLF conversion warnings only). SQL SHA-256 remains 29515ad151c5171642ac9a69eae44e27b635f27f55753bde511dde4d0d346286.
- Independent read-only security review found no blocking/high/medium issues. Two minor recovery-copy issues were corrected and controller tests extended afterward. SQL schema and private configuration remain unchanged; no external message, package installation, commit or push occurred.

Limitations/manual acceptance: the bounded lock test is not a complete verify/verify, verify/suspend or resend/resend concurrent end-to-end race; those need separately isolated concurrent fixtures. No successful live browser activation or committed synthetic registration was performed because live database fixtures remain rollback-only. Core activation and successful result rendering are tested separately. JavaScript-disabled keyboard operation, actual email-client fragment preservation, real password-manager/screen-reader behaviour, zoom, high contrast and additional browser engines still need manual checks. Fake capture alone cannot validate SMTP/TLS, provider acceptance, inbox/spam placement or delivery timing. Clock correctness depends on the server clock; all application timestamps are UTC. There is no durable retry/outbox, token cleanup job, real SMTP configuration or Login.

## Exact source/test/document manifest

26 files total (15 created, 11 modified). Paths below are relative to C:\xampp\htdocs\GigHub. Only this reviewed manifest is eligible for the explicitly approved final local commit; generated evidence and private configuration remain excluded.

Created:
- app/Controllers/EmailVerificationController.php
- app/Core/VerificationThrottle.php
- app/Mail/MailTransport.php
- app/Mail/VerificationMail.php
- app/Mail/InMemoryMailTransport.php
- app/Repositories/VerificationTokenRepository.php
- app/Services/EmailVerificationService.php
- app/Views/verification.php
- public/assets/verification.css
- public/assets/verification.js
- tests/verification.php
- tests/verification-database.php
- tests/verification-http.php
- tests/verification-ui.cjs
- docs/email-verification.md

Modified:
- app/Controllers/RegistrationController.php
- app/Core/Csrf.php
- app/Services/RegistrationService.php
- app/Views/register.php
- app/Views/registration-pending.php
- bootstrap/app.php
- config/defaults.php
- config/local.example.php
- docs/registration.md
- docs/database-design.md
- docs/database-decisions.md

Generated, ignored browser evidence: tmp/verification-desktop.png and tmp/verification-mobile.png. They contain no usable verification key.
