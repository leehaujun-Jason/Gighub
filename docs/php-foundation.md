# PHP foundation

## Plan and scope

Implement a small PHP 8.2 application with explicit class loading, central array configuration, lazy PDO access, secure cookie sessions, two GET routes, and a single public front controller. No Composer packages, SQL execution, account features, SMTP, financial operations, or upload directories are included.

Steps: inspect existing files/Git; add failing foundation checks; implement configuration, PDO, sessions and routing; reconcile database documents; verify PHP syntax and local Apache responses; review access restrictions. Existing root index.php and .gitignore are preserved. No database is created, imported, queried, or changed by this task.

## Structure

```text
GigHub/
  .htaccess                 Default deny; existing root placeholder remains available
  index.php                 Existing placeholder, unchanged
  public/
    .htaccess               Public directory; no directory listings
    index.php               HTTP entry point, headers and generic error handling
  bootstrap/app.php         Loads config/classes, starts session, registers routes
  config/
    defaults.php            Safe defaults; database disabled, credentials empty
    local.example.php       Placeholder configuration; not loaded automatically
    local.php               Private development configuration; ignored by Git
  app/
    Core/Config.php         Merges defaults with optional local.php
    Core/Database.php       Lazy connection, native PDO prepares, strict mode and UTC
    Core/SecureSession.php  Cookie-only strict sessions
    Core/Router.php         Explicit GET routing
    Controllers/            Home and local health controllers
    Views/home.php          Minimal development landing page
  database/schema.sql       Unchanged accepted SQL
  docs/                     Design, decisions, and this guide
  tests/foundation.php      Dependency-free CLI checks; no DB connection
```

Private app, bootstrap, config, database, docs and tests directories have their own deny rules, including future index.php files. Root denial protects .git and other nonpublic files. PHP can include files denied to HTTP; HTTP access control does not block internal requires. This relies on Apache honoring .htaccess (verified locally); a future host must reproduce these protections, preferably with public/ as its document root. No XAMPP/server configuration was edited. Do not disable these rules or use PHP's built-in server on the repository root: it does not enforce .htaccess. There is no uploads directory or public upload route.

## Open locally

- Existing placeholder: http://localhost/GigHub/
- New front controller/home: http://localhost/GigHub/public/
- Local health: http://localhost/GigHub/public/index.php?route=/health
- Unknown route returns 404; malformed array route returns 400; non-GET method returns 405.

Routing intentionally uses a fixed query parameter, not rewrite rules or dynamic file paths. Route values are matched against a fixed registry; they are never used in require/include. The health response is intentionally small: application readiness, session readiness, and database=not_checked. It is hidden unless app.environment=local and REMOTE_ADDR is IPv4/IPv6 loopback. It does not trust forwarded headers, open PDO, show phpinfo, disclose paths/versions/configuration or send email. A same-machine reverse proxy would require a separate access policy before deployment.

## Configuration and database approval

Nothing is required to view the two public routes. Following explicit Prompt 09B approval, private local.php now selects gighub_dev with the dedicated gighub_app account over 127.0.0.1:3306. A cryptographically random password was generated locally and written only to that ignored file, with Windows ACLs restricted to the current user, SYSTEM and Administrators. Never print or commit its contents. Setup/connection verification succeeded before enabled=true was written. Do not create further resources, change grants or re-run setup automatically.

After approval, copy config/local.example.php to config/local.php, replace placeholders privately, and explicitly set database.enabled=true. The existing .gitignore already excludes config/local.php; never commit it, logs, or credentials. Do not paste credentials into documentation or browser errors. The loader uses PHP arrays, not .env or a secret manager. SMTP secrets/configuration and upload paths are not part of this foundation.

Database construction is lazy: a future controller/service must deliberately call Database::connection(). No current route does. The normal factory accepts only the exact gighub_dev name and rejects disabled/missing settings, DSN separator injection and invalid ports. It verifies SELECT DATABASE() before exposing the handle. The isolated validation schema remains blocked on the normal application path. The subsequently approved CLI-only Database::forSchemaValidation() factory requires the exact gighub_schema_test name and opts in only in memory; HTTP cannot use this exception. The earlier approved tests/pdo-connectivity.php run verified the isolated schema before development setup. Current local.php now targets gighub_dev, so that runner intentionally refuses it; never change its target to dev. The explicit schema-validation factory remains CLI-only and would require separately supplied authorized schema-test credentials. Do not grant the application account access to the test database.

On a future approved PDO connection the factory uses charset=utf8mb4, exceptions, associative results, nonpersistent connections and ATTR_EMULATE_PREPARES=false. It sets only that connection's sql_mode (including STRICT_TRANS_TABLES) and time_zone='+00:00' before exposing it. This prevents reliance on the installed server's permissive default ENUM handling. Session initialization failure discards the handle and returns a generic error. Use prepare/execute for every future query containing user data; disabling emulation does not make interpolated SQL safe. Database and PHP timestamps use UTC; format user-visible dates separately.

## Security and limits

- Sessions use an application-specific name, cookie-only IDs, strict ID validation, HttpOnly, SameSite=Lax and /GigHub/public/ cookie scope. Fresh sessions regenerate their ID. Login-related regeneration/authorization are future work.
- Secure cookie flag follows the actual server HTTPS flag, not client-supplied proxy headers. Local HTTP cannot provide encrypted cookies; production requires HTTPS before login features are deployed.
- The public entry point disables displayed errors, buffers output, returns a generic 500 for uncaught exceptions, and logs only the error class. PDO messages/DSN/passwords/stack traces are not returned or logged by its handler. It sends no-store, nosniff, a restrictive CSP and no-referrer headers. Parse/startup failures before PHP executes still require secure hosting configuration; PHP linting reduces this risk, but application handlers cannot fix server-level misconfiguration.
- No authenticated action exists. CSRF protection, authentication rate limiting, ownership checks, SMTP handling, upload validation/authorization and transactional workflows must be added with their respective features. SameSite cookies alone do not provide complete CSRF protection.
- Local configuration is private PHP code; only trusted developers may edit it. The application does not accept configuration over HTTP.

## Verification

Run php tests/foundation.php from the project root. It uses defaults without loading local secrets, checks refused connections BEFORE PDO is opened, session settings, routing and health restrictions. It does not test a successful PDO connection, connection failure on the network, or database session settings at runtime. PHP -l checks syntax separately. Apache HTTP checks must verify private-file denial, status codes, session cookie flags and response sanitization; CLI tests alone cannot prove .htaccess behaviour.

The test runner fails before implementation and passes after it. No SQL or database inspection is part of the foundation checks. PHP's normal session mechanism can create anonymous session files; these are not marketplace accounts. No application log or private upload directory is created.

Recommended next step: approve an explicit application database connection configuration/test plan, then implement registration and real SMTP verification as a separate phase. Do not connect the existing validation database automatically, create another database, send SMTP, or seed wallets as part of that preparation.

## Historical Prompt 07 verification results

- 21 CLI checks passed after the initial expected missing-foundation failure.
- All 12 new PHP files passed php -l syntax checks.
- 18 Apache HTTP checks passed: existing root, public home, JSON health, malformed/missing routes, method rejection, private app/config/bootstrap/database/docs/tests, dotfiles and mixed-case/traversal requests. The first root-directory denial was corrected before completion; the root index.php file was never edited.
- Three further checks passed: existing session reuse, rejection of an unknown supplied session ID, and generic uncaught TypeError output without stack traces.
- git check-ignore confirms config/local.php is excluded. No local.php or credentials were created.
- SQL and PDF hashes match their pre-task values; root index.php and .gitignore match Git HEAD. No database was accessed in these checks.
- Successful PDO connection, database session SQL mode/UTC settings, and actual PDO network errors remain untested pending approved credentials and database selection. Session options are implemented and HTTP cookies checked; HTTPS transport is not tested on local HTTP.

## Approved CLI PDO connectivity validation

Command: php tests/pdo-connectivity.php. Run only with explicit approval: it opens the private configuration and connects to the existing gighub_schema_test. It never selects a fallback database or guesses credentials. Normal config may remain enabled=false; only the explicit CLI validation instance enables access in memory. Config contents/passwords, driver messages and stack traces are not printed. The runner is in the HTTP-denied tests directory and also rejects non-CLI execution.

Verified result: PASS; selected database gighub_schema_test; server 10.4.32-MariaDB; session STRICT_TRANS_TABLES present; time_zone +00:00; client/connection/results charsets utf8mb4; PDO emulation disabled; a parameterized SELECT succeeded; all 20 expected base table names matched exactly. The factory's existing SET SESSION initialization affects only its connection. Inspection uses SELECT metadata inside a READ ONLY transaction, then rolls it back; no DML, DDL, data seeding, database creation, or global settings changes occur.

Foundation tests now have 22 checks (no DB connection) including refusal of a CLI validation target other than gighub_schema_test. Existing tests continue to confirm the normal path rejects the isolated schema. Syntax checks pass. SQL/PDF hashes remain unchanged. No registration, login, SMTP, wallet credits or upload work is implemented. This verifies connection settings and read-only inspection, not authentication security or transactional business workflows.

Minimal changes for this step: app/Core/Database.php adds an explicit guarded CLI validation factory, tests/pdo-connectivity.php adds the read-only runner, tests/foundation.php adds the wrong-target guard check, and this guide records the approved exception/results. No private configuration changes or automatic commits.

## Prompt 09B development setup record

Preflight reconfirmed the exact MariaDB 10.4.32 local server, port 3306, administrative provisioning authority, absent gighub_dev and absent gighub_app@127.0.0.1, and validated schema hash. The approved provisioning ran once; all 23 schema statements succeeded. Object counts: 20 InnoDB/utf8mb4_unicode_ci tables, 20 PKs, 37 FKs, 19 UNIQUE constraints, 43 CHECKs, 68 indexes, two generated columns and two ledger triggers. All application tables remained empty. No test records were inserted.

The dedicated account has exactly the approved table-level mapping (55 privilege rows across 20 tables): SELECT/INSERT/UPDATE on users, freelancer_profiles, client_profiles, email_verification_tokens, jobs, bids, milestones, work_submissions, uploaded_files, wallets, escrows, notifications, disputes and cancellation_requests; SELECT/INSERT/DELETE on user_skills and job_skills; SELECT/INSERT on wallet_entries, chat_messages and reviews; SELECT only on skills. No database-wide, global, grant-option or DDL privileges were granted. USAGE in account metadata is not a data-access privilege. Ledger UPDATE/DELETE is not granted.

CLI validation through the normal PDO abstraction, using enabled=true in memory first, confirmed selected gighub_dev, authenticated gighub_app@127.0.0.1, version 10.4.32-MariaDB, strict session mode, UTC, utf8mb4 in client/connection/results, native prepares, parameterized read-only SELECT and the exact expected table inventory. Metadata inspection ran in a rolled-back read-only transaction. Attempts to connect to all 14 pre-existing non-metadata databases were denied, including gighub_schema_test. Built-in information_schema metadata visibility is normal and is not access to unrelated application data.

Both ledger trigger definitions, BEFORE events, SIGNAL bodies and administrative DEFINER security context were inspected using the administrative connection, without inserting/updating/deleting ledger records. Runtime application users do not need TRIGGER permission; maintain the authorized trigger-definer account when changing deployment administration later.

The reserved schema-test snapshot (table definitions, row counts and trigger metadata) matched before and after provisioning. Global SQL mode, source schema and PDF remained unchanged. Only after all checks passed was private local.php enabled. The normal enabled configuration then successfully connected to gighub_dev. The application home returned 200, health returned 200 with database=not_checked, and direct HTTP access to local.php returned 403. Private file permissions remained restricted; Git ignores local.php. No schema-test import, SMTP, sample accounts, wallet credits, upload directories, feature implementation or automatic commit occurred.

Minimal files changed in this step: app/Core/Database.php (exact target allowlist and selected-database guard); tests/foundation.php (unexpected database rejection test); private config/local.php (approved restricted development credentials, enabled only after validation); this guide (setup record). The foundation suite passes 23 checks without connecting to a database. No actual write-path business workflows or concurrent settlement tests were run in development. Registration/Login remain the next separate implementation phase.
