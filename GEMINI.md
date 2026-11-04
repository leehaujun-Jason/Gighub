# GigHub — Persistent Antigravity Development Rules

## 1. Plan First
Before implementing each new module or sub-module, present:
- Proposed architecture and file changes.
- Database and schema impact (if any).
- Step-by-step implementation plan.
- Verification and test plan.
STOP and wait for explicit user approval before writing code.

## 2. Manual Browser Testing & Offline Verification
- Never autonomously navigate the application, log in, click buttons, or submit forms using browser automation agents (Playwright, Puppeteer, Chrome DevTools, or Computer Use). The user performs all browser testing manually.
- Focused offline tests (mocked PDO/repositories) and PHP CLI syntax checks (`php.exe -l`) are allowed and strongly encouraged to verify logic before manual testing.
- For browser testing, provide a structured manual test plan containing:
  - Test scenario and purpose.
  - Exact route/URL and HTTP method.
  - Sample input values and session state.
  - Expected on-screen and visual results.
  - Database assertions (read-only verification queries).

## 3. Extensions and Skills Governance
Recommend only essential, trusted extensions, skills, or MCP servers. Check whether equivalent functionality is already built into native PHP/Windows CLI before suggesting external tools. Explain required permissions and purpose upfront, and obtain explicit user approval before installing. Avoid redundant or untrusted tools.

## 4. Database Safety, Port 3309 Isolation & Backups
- Target only the clean MariaDB instance at `127.0.0.1:3309` (`gighub_dev` for development, `gighub_schema_test` for `--run-isolated` integration tests).
- NEVER start, inspect, or reference the damaged original XAMPP MySQL at port 3306 or `C:\xampp\mysql`.
- Read-only inspection is allowed without prior approval.
- Require a verified database backup and separate approval before any schema changes or financial data writes.
- Back up tables/data before structural or financial changes.

## 5. Git Discipline
- Preserve all completed commits (Modules 10–13) and leave the 14 deferred test/recovery files untouched.
- No destructive Git commands (`reset --hard`, `clean -fd`, `rebase`), no `git add .` / `git add -A`, and no remote push.
- Stage only explicit file paths and obtain user approval before committing.

## 6. Token Efficiency & Security Integrity
- Use targeted file reads and symbol-specific inspection; avoid rereading the whole repository.
- Token efficiency must not compromise financial transaction integrity or essential security checks.
- Verify changes with PHP CLI syntax checks (`php.exe -l`) and focused offline unit/service tests.
- Avoid unnecessary test harnesses, repeated scans, and overly verbose responses.

## 7. Beginner-Friendly Guidance
- Explain architectural decisions, lock ordering, and transaction boundaries clearly and concisely.
- Provide step-by-step instructions, clear file links, and sample test data for manual validation.

## 8. Compatibility & Conventions
- Maintain vanilla PHP 8.2, clean MariaDB 10.4.32, native sessions (`GIGHUBSESSID`), query routing (`?route=/...`), repository SQL ownership, service transaction ownership, and view output escaping.
- Money uses exact decimal strings (`DECIMAL(10,2)`). Dates and times use UTC `DATETIME` storage with Malaysia-local calendar dates for deadlines.
- Use Post/Redirect/Get with purpose-specific CSRF tokens for all state-changing forms.
- Do not introduce major frameworks, external CSS libraries, or redesign completed modules without approval.

## 9. Codex Handover Continuity
- Keep `AGENTS.md` unchanged as the shared project baseline.
- Keep documentation in `docs/` synchronized with each completed module.
- Record completed features, applied or pending migrations, verified checks, and remaining tasks so work can transition seamlessly between Antigravity and Codex.

## 10. Scope Control
- Prioritize completing the FYP MVP requirements.
- Avoid premature optimizations, speculative features, or unsolicited refactoring of existing modules.
- Report blockers or discrepancies promptly rather than expanding scope.

## 11. Approval Boundaries
Approval to implement a module does not authorize database migrations, live database writes, extension/skill installation, Git commits, or remote pushes. Each of these operations requires separate, explicit user approval.
