# Module 14C — Simulated Escrow Funding MVP & Shared Notification System

## Scope & Architecture

Module 14C implements simulated escrow funding for awarded freelance projects and introduces a site-wide dismissible notification system. It enables Clients to fund awarded jobs using their simulated wallet balance, locking the exact agreed bid amount into a single escrow record and advancing the job and bid to active execution.

This module follows approved database decisions in `docs/database-decisions.md` (Decision #5) and schema specifications in `docs/database-design.md`:
- **One escrow per job:** Exactly one escrow record is created per project, locking the full winning bid amount.
- **No partial milestone funding or release:** Milestones serve strictly as deliverable checkpoints. Escrow is committed in full upfront; no partial disbursements or payouts occur in this module.
- **Simulated funds only:** All transactions use simulated balances seeded via the Module 14A demo credit facility. No payment gateways, real financial networks, or external banking integrations are involved.

---

## Escrow Funding Lifecycle & Atomic Transaction

The funding process executes within a single atomic database transaction with strict row locking in `EscrowService`:

1. **Eligibility & Verification**:
   - Authenticated user must be an active, email-verified Client (`users.role = 'client'`).
   - Job must be owned by the Client (`jobs.client_id = ?`).
   - Job status must be `awarded` with a non-null `awarded_bid_id`.
   - The winning bid must belong to the job and have status `pending` or `accepted`.
   - The job must have at least one defined milestone checkpoint (`milestones` count &ge; 1).
   - No existing escrow record may exist for the job (`escrows` count = 0).

2. **Lock Ordering & Concurrency Protection**:
   To prevent deadlocks and race conditions across concurrent operations (e.g. concurrent claim/fund or double submissions), row locks are acquired in a strict, uniform sequence:
   1. `jobs` row lock (`FOR UPDATE`)
   2. `users` + `client_profiles` row lock (`FOR UPDATE`)
   3. `bids` row lock (`FOR UPDATE`)
   4. `wallets` row lock (`FOR UPDATE`)
   5. `escrows` existence verification under lock
   6. `milestones` count check under lock

3. **Atomic Operations**:
   - **Wallet Debit**: Client wallet balance is decremented by the exact bid amount (`DECIMAL(10,2)` string arithmetic).
   - **Ledger Entry**: An immutable debit record is inserted into `wallet_entries` (`entry_type = 'escrow_fund'`, `amount_delta = -amount`, `source = 'client_funding'`, `operation_key = 'escrow_fund:job:{id}'`).
   - **Escrow Creation**: A new row is inserted into `escrows` (`status = 'locked'`, `amount = bid.amount`, `funded_at = UTC NOW`).
   - **Job State Transition**: `jobs.status` transitions from `awarded` to `in_progress`.
   - **Bid State Transition**: Winning `bids.status` transitions to `accepted` (if not already accepted).
   - Any failure or concurrency conflict triggers an immediate transaction rollback, ensuring wallet balances and escrow state remain consistent.

---

## Routes & User Experience

All routes adhere to the query parameter convention `?route=...`:

- `GET /client/jobs/fund&job_id={id}`: Pre-funding review screen (`EscrowController::preview`). Shows agreed project price, current wallet balance, projected post-funding balance, deliverable summary, and a confirmation form. If the wallet balance is insufficient, a prompt directs the user to the wallet funding screen.
- `POST /client/jobs/fund/confirm`: Processes the atomic funding transaction (`EscrowController::confirm`). Uses `escrow_fund` purpose-specific CSRF token and Post/Redirect/Get (PRG) redirecting to `?route=/client/jobs/show&id={id}` with a success notification.
- `GET /freelancer/jobs/milestones&job_id={id}`: Read-only milestone checkpoint view for the assigned winning Freelancer (`MilestoneController::freelancerIndex`).
- `GET /client/jobs/milestones&job_id={id}`: Milestone view for the owning Client, automatically switching to read-only mode once the job is funded.

---

## Post-Funding Milestone Behavior

Once escrow is funded:
- **Client Workspace**: The Client can view all milestone checkpoints for progress monitoring, but mutation controls (Add Milestone form, Edit links, Delete forms) are hidden. A prominent informational notice displays: *"Milestones are locked after funding. Escrow has been funded and work is underway. Deliverable checkpoints are displayed in read-only mode."* Direct POST requests to milestone mutation endpoints are rejected with HTTP 409 Conflict.
- **Freelancer Workspace**: The winning Freelancer can access the read-only milestone view via a *"View Milestones"* link on their accepted bid card in `?route=/freelancer/bids` or via `?route=/freelancer/jobs/milestones&job_id={id}`. Checkpoints are presented clearly, and unassigned freelancers or other roles are denied access (HTTP 404/403).

---

## Global Dismissible Notification System (GEMINI.md Rule 12)

A unified notification component replaces full-page plain-text error dumps for recoverable validation errors and operational notices:

- **Component & Partial**: `GigHub\Core\Notification` (`flash`, `success`, `error`, `warning`, `info`, `consume`) paired with `app/Views/partials/notifications.php`.
- **Global Header Mount**: Mounted directly below the navigation bar in `app/Views/partials/header.php`, making alerts accessible site-wide.
- **Styling**: Defined in `public/assets/components.css` (`.gh-notifications-container`, `.gh-alert`, `.gh-alert-success`, `.gh-alert-error`, `.gh-alert-warning`, `.gh-alert-info`, `.gh-alert-close`).
- **Accessibility & UX**:
  - `role="alert"` for errors; `role="status"` for info/warning/success.
  - Container has `aria-live="polite"`.
  - Visible, keyboard-accessible dismiss button (`&times;`) with min 36px touch target.
  - Flash session storage with Post/Redirect/Get: messages are consumed upon rendering so page reloads do not repeat notices.
  - Legacy session notices (`wallet_notice`, `milestone_notice`, `job_posting_notice`, `escrow_fund_error`) are bridged and deduplicated automatically.

### Remaining Standalone Error Pages
Per Rule 12, non-recoverable security and infrastructure responses maintain their dedicated HTTP status codes and plain-text or layout responses rather than flashing notifications:
- HTTP 303 Redirect to `/login`: Unauthenticated requests to protected endpoints.
- HTTP 403 Forbidden: Cross-role violations (e.g. Freelancer accessing Client wallet or milestone mutation routes).
- HTTP 404 Not Found: Access to non-existent resources or objects owned by another user.
- HTTP 400 Bad Request: Malformed input (e.g. invalid integer identifiers).
- HTTP 503 Service Unavailable: Database connectivity loss or unhandled infrastructure exceptions.

---

## Database Impact & Migration Status

- **No schema migrations required**: The `escrows` and `wallet_entries` tables were already fully defined in `database/schema.sql` with appropriate constraints (`UNIQUE (job_id)`, foreign keys, decimal precision).
- **No privilege changes required**: The existing database grants for `gighub_app@127.0.0.1` on `gighub_dev` already included `SELECT, INSERT, UPDATE` on `escrows`, `wallets`, and `wallet_entries`.

---

## Verification & Manual Acceptance

### Offline Automated Verification
- `tests/escrow.php`: **60 PASS** (no database connections).
  - Validates preview calculations, insufficient funds rejection (422), unawarded job rejection (409), unfunded without milestones rejection (422), double-funding prevention (409), atomic transaction rollback on failure, exact `DECIMAL(10,2)` decimal math, CSRF token rotation and validation, and cross-role RBAC isolation.
- `tests/milestones.php`: **74 PASS** (covers post-funding read-only locking, freelancer read-only view, and mutation rejections).
- `tests/wallet.php`: **45 PASS**.
- `tests/awarding.php`: **55 PASS**.
- `tests/bidding.php`: **99 PASS**.
- PHP CLI syntax checks (`php.exe -l`): All modified and created files passed with 0 errors.

### Confirmed Manual Acceptance Results (Job 2)
The manual acceptance checks were performed on live clean MariaDB (`127.0.0.1:3309`, database `gighub_dev`):
1. **Pre-funding Baseline**:
   - Job 2: Client ID 6, status `awarded`, winning Bid ID 1.
   - Bid 1: Freelancer ID 116, amount `RM4,300.00`.
   - Client Wallet: `RM10,000.00` (seeded via demo credit in Module 14A).
   - Milestones: 1 defined checkpoint.
2. **Funding Execution**:
   - Navigated to `?route=/client/jobs/fund&job_id=2`.
   - Verified preview: agreed amount `RM4,300.00`, balance `RM10,000.00`, remaining `RM5,700.00`.
   - Confirmed funding: redirected to `?route=/client/jobs/show&id=2` via PRG with success notification.
3. **Post-Funding Database Verification**:
   - `escrows`: 1 record created (`id = 1`, `job_id = 2`, `bid_id = 1`, `amount = '4300.00'`, `status = 'locked'`).
   - `wallet_entries`: Debit entry created (`id = 2`, `wallet_user_id = 6`, `amount_delta = '-4300.00'`, `entry_type = 'escrow_fund'`, `operation_key = 'escrow_fund:job:2'`).
   - `wallets`: Client 6 balance updated from `RM10,000.00` to `RM5,700.00`.
   - `jobs`: Job 2 status transitioned to `in_progress`.
   - `bids`: Bid 1 status transitioned to `accepted`.
4. **Post-Funding Milestone Read-Only Checks**:
   - Client 6 viewing `/client/jobs/milestones&job_id=2`: Displayed locked notice; Add/Edit/Delete controls hidden.
   - Freelancer 116 viewing `/freelancer/jobs/milestones&job_id=2`: Displayed freelancer read-only view with link to My Bids.
5. **Notification UI & Dismissal**:
   - Dismissible alerts render with clear status colors and icons.
   - Close button (`&times;`) removes the notification from DOM.
   - Page refresh does not duplicate consumed notices.
6. **Role Isolation**:
   - Freelancers cannot access `/client/jobs/fund` or `/client/jobs/milestones` (HTTP 403).
   - Unassigned freelancers cannot access `/freelancer/jobs/milestones` for Job 2 (HTTP 404).

---

## Subsequent Modules

- **Module 15 — Deliverable Submission & Milestone Approval MVP**: Detailed in `docs/submissions.md`, `docs/milestone-reviews.md`, and `docs/final-submissions.md`.
- **Module 16 — Simulated Escrow Release & Job Completion**: Implemented atomic escrow release, freelancer wallet credit, and job completion. Detailed in `docs/settlement.md`.
