# Email verification — Phase 10B-A

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
