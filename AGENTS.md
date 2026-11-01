# GigHub repository guidance

## Scope and working style
- Student FYP: keep changes small, understandable and within the approved module.
- Inspect Git status and task-relevant files first; preserve existing user work.
- Read the relevant module document in `docs/`; distinguish historical results from current behavior.
- Inspect owning source when needed; avoid rereading the entire repository or old evidence.
- Follow approved business rules. Propose schema changes, dependencies and new scope before implementing.
- For new features, research actual reference source and propose a design before coding unless already approved.
- Give concise reports: changed files, verification results, limitations and required next decisions.

## Architecture and conventions
- Windows/XAMPP Apache, PHP 8.2, MariaDB 10.4; no unnecessary framework.
- PHP CLI: `C:\xampp\php\php.exe`; project: `C:\xampp\htdocs\GigHub`.
- Front controller: `public/index.php`; composition/routes: `bootstrap/app.php`.
- Routes use `?route=/...`; controllers return response arrays, repositories own SQL,
  services own business rules/transactions, and views live in `app/Views/`.
- Reuse `app/Core` configuration, PDO, session, AuthGuard and purpose-specific CSRF components.
- Use prepared statements, native prepares, utf8mb4, strict session SQL mode and UTC timestamps.
- Use Malaysia-local calendar dates for delivery-deadline rules; money uses exact decimal strings.
- Authorize on the server: private resources require authenticated owner scoping;
  approved public resources follow explicit visibility rules. Escape output,
  sanitize errors and use fixed redirects with Post/Redirect/Get after successful writes.
- Keep coupled writes atomic; preserve existing transaction/savepoint and lock-order conventions.
- Configuration merges defaults with ignored `config/local.php`; never expose private values.

## Database and operational safety
- Active clean MariaDB: `127.0.0.1:3309`, data directory
  `C:\MariaDB_Recovery\GigHub_Clean_Test\data`; development database: `gighub_dev`.
- NEVER start or use the damaged original `C:\xampp\mysql` instance/data directory.
- Preserve recovery snapshots, exports and clones; never substitute them as application targets.
- Obtain explicit user approval before development database writes (including seeds), schema changes,
  privilege/account changes, service starts/stops/restarts, Git commits or pushes.
- An existing explicit approval covers only its stated operations and environment; do not expand it.
- Never guess credentials, bypass authentication or fall back to administrator/development credentials.
- Keep credentials, private configuration, uploads, logs, backups, exports and caches outside commits.
- Never print secrets, raw tokens, complete verification links, cookies or sensitive database records.
- Keep SMTP and diagnostics disabled; obtain explicit approval before a live SMTP connection or email.
- Private upload storage belongs outside htdocs; no real payment processing.

## Risk-based verification
- Copy, CSS and small view changes: relevant syntax checks, focused offline tests and `git diff --check`.
- Logic changes: focused unit/controller/service tests plus relevant regressions.
- Database, authorization, transaction or security changes: use relevant isolated integration tests
  after proving every connection path is isolated; never weaken guards to make tests pass.
- Do not repeat full database/HTTP/browser acceptance without a specific regression concern.
- Database-writing tests MUST target `gighub_schema_test` on the clean instance at port 3309.
- CLI tests use `tests/integration-bootstrap.php` and explicit `--run-isolated` opt-in;
  use only dedicated private test credentials and verify account/server/datadir/database identity.
- Verify setup, fixtures, cleanup, secondary and subprocess connections; no development-config fallback.
- Mutating HTTP/browser tests use only the isolated loopback harness, never active Apache/development.
  Read `tests/http-acceptance/README.md` for runtime, opt-in, fixture and cleanup requirements.
- Inspect baseline contents/grants first; preserve unrelated rows. No TRUNCATE, disabled FKs,
  broad cleanup or automatic privilege escalation. Privilege changes need explicit approval.
- Track exact synthetic fixture IDs, clean only that run, restore temporary grants and verify integrity.
- A rollback can advance test auto-increment counters; do not promise byte-identical test files.
- Use fake/disabled mail in tests. Report actual pass/fail/skip counts and unsupported browser checks.
- Never claim unexecuted tests passed; distinguish fresh checks from historical acceptance.

## Git checkpoint rules
- Do not stage, commit, amend or push unless explicitly authorized for that task.
- Review tracked/untracked files and staged content; use explicit file paths, never `git add .` or `-A`.
- Stop on unexpected files, secrets, unresolved integrity failures or material scope differences.
