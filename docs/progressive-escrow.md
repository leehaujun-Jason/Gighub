# Progressive milestone escrow: Phase 1 implementation
Status: implemented; Migrations 015 and 016 deployed to development, with isolated checks and owner-reported manual financial acceptance passed. Progressive activation remains disabled.
Branch: feature/progressive-escrow-demo-payments. Recovery: 8805ffb2903a382b88d7cccca36db3fded3ca406.
All pre-existing jobs default legacy_full_v1, including unfunded jobs and 3+ milestone plans.
Activation is a trusted server setting: progressive_payments.enabled, default false.
Do not enable until Migration 015, grants, regression review and manual acceptance are approved.

## Application
PaymentMoney uses exact integer sen on 64-bit PHP; HALF_UP cumulative targets at 30/50 percent.
ProgressiveEscrowService and ProgressiveEscrowRepository coordinate allocation within caller-owned transactions.
Approval validates Client ownership, active verified participants, awarded bid, contract state and frozen plan.
Milestone approval/status, wallet credit, immutable disbursement, ledger, escrow counters and notifications commit together.
The second milestone pays cumulative 50 percent minus already released funds; the final payment is exact remainder.
Final approval atomically settles the remainder and completes progressive jobs. Legacy jobs retain separate approval/release.
The old release endpoint refuses progressive jobs, avoiding a second completion path.
Cancellation and binary Admin resolution allocate remaining escrow only. Earlier releases remain irreversible.
Minimum progressive principal RM10.00: validated at new posting, bid save, award and funding.
Creation assigns explicit policy when activation is enabled; no policy is accepted from user input.
Milestone funding freezes order and percentages; existing post-funding structural-edit guards remain in force.
Job row is the common serialization boundary. Progressive review/resolution first locks Client identity, Bid, Freelancer identity.
Existing downstream escrow/cancellation/dispute/milestone locks remain protected by the same Job boundary.
Allocator rechecks Escrow, ordered Milestones, target submission and destination wallet before guarded mutations.
No automatic retry after lock/commit ambiguity. Stable operation keys and unique stage/terminal indexes prevent repeated allocation.
Malformed/stale repeated approval requests may receive a rejection; they cannot cause another credit.
Wallet overflow fails the complete approval transaction.

## Migration
database/migrations/015_progressive_escrow.sql: 9 statements, expected 34 tables and 4 triggers.
Adds policy, frozen schedule and aggregate metadata; safely initializes new legacy aggregate fields from terminal escrow states.
Adds remaining_amount generated column, settled_mixed state and conservation/schedule checks.
Creates escrow_disbursements with unique operation, milestone and terminal allocation constraints and immutable triggers.
Adds ledger linkage, milestone_release/milestone_approval and guarded per-escrow/type uniqueness.
Preserves wallet operation/demo/terminal uniqueness and both original wallet immutable triggers.
Historical wallet ledger rows are never rewritten.
Canonical schema includes the same transition for empty-schema initialization; do not replay 015 after a canonical import.
Minimum new grant: SELECT, INSERT on escrow_disbursements; existing table grants cover new columns.
No runtime UPDATE/DELETE on immutable history; no database-wide or DDL grants.
Backup helper must be updated to 34 tables plus escrow_disbursements only during approved development deployment.
Activation false permits current 33-table legacy runtime; progressive SQL is reached only for explicitly progressive rows.

## Rehearsal evidence
Rehearsal used a disposable database and restricted account on the dedicated local MariaDB instance.
Original successful Phase 0.5 restore database was not changed.
The initial restore helper stopped because the updated grants template referenced the not-yet-created disbursement table.
Read-only inspection confirmed 33 restored tables and no 015 columns; no migration had run.
Continued from that verified state: executed 015 once, applied only isolated new-table grants, retained private runtime credentials.
All nine statements succeeded; 34 tables, four triggers and correct historical aggregate initialization verified.
All pre-migration historical row projections matched afterward, including original ledger and escrow fields.
At rehearsal time, source development remained unchanged at 33 tables. Subsequent approved deployments
of 015 and 016 verified 34 tables, four financial-history triggers, historical data/private files,
unchanged unrelated grants and wallet/escrow reconciliation. No progressive activation occurred.

## Tests
Run only on the authorized disposable database:
GIGHUB_PROGRESSIVE_TEST_CONFIG points to a private JSON file outside the repository and webroot.
It contains host, integer port, name, username and password; never publish it or use development credentials.
The controlled rehearsal runner requires loopback port 3309, a gighub_restore_ database with a hex suffix,
and a restricted gh_restore_ account. These are test guards, not normal application installation restrictions.
php tests/progressive-payments.php --run-isolated
Test runner rejects development targets and never reads application local.php.
Synthetic fixtures are retained in the disposable database; reruns append new synthetic accounts/contracts.
Cases include both schedules, RM10.01 rounding, minimum amount, duplicate approvals/final completion, legacy 3+ milestones,
legacy below RM10, cancellation at 30/50 percent, both dispute decisions, frozen structure, overflow rollback, ownership,
concurrent duplicate approvals, approval/cancellation and approval/dispute races, and global ledger-wallet reconciliation.
Four additional isolated administrator-trigger attempts were rejected with MariaDB error 1644, proving UPDATE/DELETE rejection
on both wallet_entries and escrow_disbursements without modifying those records.
The existing settlement suite passed 152 offline checks when loaded with current PaymentMoney/progressive/SubmissionValidator dependencies.
Phase 1.5 repaired only stale test fixtures/dependencies: milestones 100 PASS; submissions 252 PASS; settlement 152 PASS.
Current financial suite: 28 isolated runtime PASS. Current loopback HTTP/render suite: 18 PASS.
Four offline external confirmation-handler checks passed. Subsequent isolated tests exercised actual deadlocks,
lock timeouts, pre-commit rollback, a discarded HTTP response and safe retry. Owner manual financial acceptance
passed. Finite tests do not prove absence of every race, ambiguous commit or storage failure; sustained load,
cross-browser, mobile and assistive-technology acceptance are not established by the financial suite.
Run php tests/counterpart-evidence.php for focused offline response ownership, rollback and gallery checks.
Local HTTP/failure-injection orchestration and private credentials are intentionally not published.

## UI and review
Shared escrow-breakdown partial displays funded, released, refunded, remaining amounts and irreversible-payment notice.
Client/Freelancer milestone pages show percentage/status and approval confirmations.
Funding preview shows the schedule; Admin dossier/cancellation/dispute summaries show remaining-only allocation.
No banking, top-up, withdrawal or receipt feature is implemented.

## Deployment and rollback
Before approval: inspect current live baseline, fresh backup, isolated regression/constraint review and owner UI acceptance.
Pause financial writers during DDL; verify every statement and never retry a partial migration blindly.
Deploy schema and scoped grant, update backup inventory, verify 34 tables/four triggers, historical rows and fresh backup.
Deploy this compatible code with activation false; enable only after explicit approval.
Do not run checkpoint financial writers against any partially paid progressive contract.
Git rollback cannot undo payouts/schema/data. Preserve current evidence and reconcile; compensating immutable entries require approval.
No rollback script deletes disbursements or rewrites historical ledger rows.

Counterpart evidence and Migration 016 are described in [counterpart-evidence.md](counterpart-evidence.md).
Fresh installs import the canonical schema once; do not replay migrations already included in it.
Publishing this branch does not enable progressive_payments.enabled. Activation requires separate approval.
