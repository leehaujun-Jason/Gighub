# Module 16: Simulated Escrow Release & Job Completion

## 1. Overview and Business Context

Module 16 concludes the simulated financial lifecycle of awarded freelance projects in GigHub. Following the completion and client approval of all milestone checkpoints and final deliverables (Modules 15A–15C), Module 16 provides the atomic settlement workflow:

1. **Simulated Escrow Release**: The owning Client confirms release of the locked escrow funds held for the completed project.
2. **Exactly-Once Freelancer Credit**: The full locked escrow amount is credited directly to the winning Freelancer's simulated wallet, and an immutable ledger entry is written.
3. **Job Lifecycle Completion**: The job status transitions to `completed`, recording the UTC completion and closing timestamps atomically in the same transaction.
4. **Freelancer Wallet & Navigation**: Dedicated Freelancer Wallet interface (`?route=/freelancer/wallet`), accessible via main navigation, completion card links, and breadcrumbs.
5. **Human-Readable Transaction History**: Wallet transaction ledger renders a clickable `Related Job` column instead of technical operation keys, with strict authorization checks preventing cross-user information leakage.

> [!IMPORTANT]
> **Simulated Accounting Only**: GigHub is an educational Final Year Project (FYP) platform. All wallets, escrow balances, and ledger movements represent simulated accounting using exact decimal strings (`DECIMAL(10,2)`). No real currency, credit cards, banking networks, or payment gateways are integrated.

---

## 2. Architecture & Lifecycle State Transitions

### Job State Transitions
$$\text{under\_review} \longrightarrow \text{completed}$$
- Prior state: `under_review` (final deliverable accepted by client in Module 15C).
- Final state: `completed`.
- Timestamp: `completed_at` and `closed_at` set to the exact UTC timestamp of the settlement commit.

### Escrow State Transitions
$$\text{locked} \longrightarrow \text{released}$$
- Prior state: `locked` (funded by client in Module 14C).
- Final state: `released`.
- Timestamp: `settled_at` set to the exact UTC timestamp of the settlement commit.
- Invariant: Exactly one escrow per job (`UNIQUE KEY (job_id)`).

### Wallet & Ledger Mutations
- **Client Wallet Balance**: Untouched during settlement (funds were already debited during escrow funding in Module 14C).
- **Freelancer Wallet Balance**: Incremented by the exact locked escrow amount ($+\text{RM }4,300.00$).
- **Wallet Ledger Entry**: A single immutable credit record is appended to `wallet_entries`:
  - `entry_type`: `'escrow_release'`
  - `amount_delta`: `$amount` (positive decimal)
  - `source`: `'client_approval'`
  - `actor_id`: Client user ID
  - `operation_key`: `'escrow_release:job:{job_id}'`
  - `escrow_id`: Referenced escrow ID

---

## 3. Settlement Preconditions & Eligibility

Before initiating the settlement transaction, `EscrowService::releaseEscrowAndCompleteJob()` enforces the following strict preconditions under database row locks:

1. **Client Identity & Ownership**:
   - The acting user must be authenticated, active, and email-verified with role `client`.
   - The job must belong to the acting Client (`jobs.client_id = ?`).
2. **Job Lifecycle State**:
   - The job must be in status `under_review`. Jobs in `draft`, `open`, `awarded`, `in_progress`, or already `completed` are rejected (HTTP 409 Conflict).
   - The job must reference an accepted bid (`jobs.awarded_bid_id IS NOT NULL`).
3. **Awarded Freelancer Validation**:
   - The awarded bid must belong to the job and have status `accepted`.
   - The winning Freelancer must be active, email-verified, and not suspended (`users.status = 'active'`).
4. **Freelancer Wallet Existence**:
   - The winning Freelancer must already possess a wallet record (`wallets.user_id = freelancer_id`).
   - Settlement **never** silently creates a wallet during financial transfer. Freelancers initialize their wallet by visiting `?route=/freelancer/wallet`.
5. **Escrow Record Invariants**:
   - Exactly one escrow record must exist for the job with status `locked` (`escrows.status = 'locked'`).
   - The escrow amount must match the accepted winning bid amount exactly.
   - Escrow must not have been previously settled (`settled_at IS NULL`).
6. **Milestone Checkpoints**:
   - At least one milestone must exist for the job.
   - **All milestones must be in status `approved`**.
7. **Final Deliverable Approval**:
   - The latest final submission (`work_submissions` with `submission_type = 'final'`) must exist and have status `approved`.
8. **Double-Payout / Idempotency Check**:
   - No existing ledger entry in `wallet_entries` may match `operation_key = 'escrow_release:job:{job_id}'`.
   - No ledger entry may exist with `escrow_id = ?` and `entry_type IN ('escrow_release', 'escrow_refund')`.

---

## 4. Atomic Transaction Sequence & Lock Ordering

To prevent concurrency conflicts, double-spending, and deadlocks across concurrent requests, row locks are acquired in a strict, uniform hierarchy:

```
Step 1: Lock Job                    (SELECT ... FROM jobs WHERE id = ? FOR UPDATE)
Step 2: Lock Client Account         (SELECT ... FROM users WHERE id = ? FOR UPDATE)
Step 3: Lock Awarded Bid            (SELECT ... FROM bids WHERE id = ? FOR UPDATE)
Step 4: Lock Freelancer Account     (SELECT ... FROM users WHERE id = ? FOR UPDATE)
Step 5: Lock Freelancer Wallet      (SELECT ... FROM wallets WHERE user_id = ? FOR UPDATE)
Step 6: Lock Escrow Record          (SELECT ... FROM escrows WHERE job_id = ? FOR UPDATE)
Step 7: Lock Milestone Rows         (SELECT ... FROM milestones WHERE job_id = ? FOR UPDATE)
Step 8: Lock Final Submission       (SELECT ... FROM work_submissions ... FOR UPDATE)
Step 9: Idempotency Verification    (Check existing operation_key and settlement ledger)
Step 10: Release Escrow             (UPDATE escrows SET status = 'released', settled_at = UTC NOW ...)
Step 11: Credit Freelancer Wallet   (UPDATE wallets SET balance = balance + amount ...)
Step 12: Write Ledger Entry         (INSERT INTO wallet_entries (..., operation_key, ...))
Step 13: Mark Job Completed         (UPDATE jobs SET status = 'completed', completed_at = UTC NOW ...)
Step 14: Queue Notification         (INSERT INTO notifications for freelancer)
Step 15: Commit Transaction
```

If any lock check, balance update, ledger write, or constraint fails, the entire transaction rolls back immediately (`PDO::rollBack()`), leaving wallet balances and job state untouched.

---

## 5. Freelancer Wallet Initialization Rules

Freelancers manage their earnings through a dedicated wallet interface:

- **Route**: `GET ?route=/freelancer/wallet` (serviced by `WalletController::showFreelancer`).
- **Authorization**: Only active, email-verified accounts with role `freelancer` may access this route. Clients attempting access receive HTTP 403 Forbidden.
- **Lazy Initialization Outside Settlement**:
  - When an eligible Freelancer visits their wallet page for the first time, `WalletService::getWalletOverview()` calls `WalletRepository::ensureWallet()`.
  - The wallet row is initialized with balance **`RM0.00`**.
  - Subsequent visits preserve existing balances and transaction history.
- **Demo Credit Exemption**:
  - Freelancers **never** receive the Client one-time demo credit of RM10,000.00.
  - The demo credit claim button and badge are strictly hidden on Freelancer views.
  - POST requests to `/client/wallet/claim-demo-credit` from freelancers are rejected with HTTP 403.

---

## 6. Client & Freelancer UI Workflows

### Client Completion & Release Workflow
1. **Under Review (Final Approved)**:
   - When the final deliverable is approved, the Client Milestone workspace (`?route=/client/jobs/milestones&job_id={id}`) renders the **Release Escrow Payment & Complete Project** panel.
   - Displays authoritative escrow amount (`RM 4,300.00`), awarded freelancer name, and confirmation notice.
   - Confirmation form submits via POST to `?route=/client/jobs/escrow/release` with a purpose-specific CSRF token (`escrow_release`).
2. **Post-Settlement Celebration**:
   - Redirects back to the milestone workspace via 303 PRG with a success alert: *"Escrow payment of RM4,300.00 released successfully. Project is now marked completed."*
   - Renders the **✓ Project Completed & Escrow Settled** celebration card:
     - `Status: Completed;`
     - `Escrow Released: RM 4,300.00;`
     - `Awarded Freelancer: {name};`
     - `Completed (UTC): {timestamp};`
   - Provides a **"View Wallet Ledger"** button linking directly to `?route=/client/wallet&job_id={id}`.

### Freelancer Completion Workflow
1. **Under Review**:
   - Displays **"Final Work Deliverable Approved by Client!"** notice.
   - Indicates that deliverables are accepted and project is awaiting financial escrow settlement.
2. **Post-Settlement**:
   - Displays in-app payment notification: *"Payment of RM4,300.00 released for Cafe Ordering System development. Funds have been credited to your wallet."*
   - Milestone workspace renders the **🎉 Project Completed — Escrow Payment Released!** celebration card:
     - `Status: Completed;`
     - `Payment Credited: +RM 4,300.00;`
     - `Completed (UTC): {timestamp};`
   - Provides a **"View My Wallet"** button linking directly to `?route=/freelancer/wallet&job_id={id}`.

---

## 7. UI/UX Refinements (Client & Freelancer)

Following manual acceptance testing, four UI/UX refinements were implemented:

### 1. Spacing & Punctuation
- Structured all settlement metadata items inside CSS Grid (`.gh-settlement-meta-grid`) with semantic `<div class="gh-settlement-item"><span class="gh-settlement-label">...:</span> <span class="gh-settlement-value">...;</span></div>`.
- Added colons (`:`) after labels and semicolons (`;`) after values.
- Monospace/tabular number formatting for monetary figures (`.gh-settlement-value--amount`).

### 2. Action Button Spacing
- Wrapped action buttons in dedicated containers (`.gh-settlement-actions`, `.gh-release-form-wrap`, `.gh-panel-action-wrap`) providing `20px–24px` (`1.25rem–1.5rem`) top margin separating text from buttons.
- Styled buttons with consistent height, padding, hover transitions, and responsive mobile stacking.

### 3. Human-Readable `Related Job` Table Column
- Replaced the technical `Operation` column (`escrow_release:job:2`) in the wallet ledger with `Related Job`.
- Preserved `operation_key` in database schema and queries.
- Queried via safe `LEFT JOIN` on `escrows`, `jobs`, and `bids` in `WalletRepository::getEntries`.
- **Authorization Scoping**:
  - Client viewing wallet: clickable link pointing to `?route=/client/jobs/show&id={job_id}` only if they own the job.
  - Freelancer viewing wallet: clickable link pointing to `?route=/freelancer/jobs/milestones&job_id={job_id}` only if they were awarded the job.
  - Fallback: Non-job records (e.g. `demo_credit`) display `<span class="gh-wallet-fallback">General Wallet Activity</span>`.

### 4. Back Navigation & Breadcrumbs
- **Client Wallet**:
  - Default: `← Back to Client Workspace`
  - With `job_id`: `← Back to Job Milestones` · `Client Workspace`
- **Freelancer Wallet**:
  - Default: `← Back to Freelancer Workspace`
  - With `job_id`: `← Back to Job Milestones` · `Freelancer Workspace`
- **Client Milestones**:
  - `← Back to Job Details` · `Client Workspace`
- **Freelancer Milestones**:
  - `← Back to My Bids` · `Freelancer Workspace`

---

## 8. Financial Invariants & Security Protections

1. **Exact Decimal Arithmetic**: All monetary balances and deltas use `DECIMAL(10,2)` strings handled via `bcadd` / `number_format`. No floating-point inaccuracies.
2. **Immutable Append-Only Ledger**: Database triggers (`trg_wallet_entries_no_update`, `trg_wallet_entries_no_delete`) unconditionally forbid updates and deletions on `wallet_entries`.
3. **Idempotency Key**: Unique constraint on `operation_key` prevents duplicate releases for the same job, even under network retries or concurrent submissions.
4. **Balance Conservation**: Escrow release credits the freelancer wallet without debiting the client wallet again (client was debited during funding in Module 14C).
5. **Purpose-Specific CSRF**: State-changing release requests require a valid `'escrow_release'` CSRF token.
6. **PRG & Flash Notifications**: Prevents duplicate form submissions on page refresh.

---

## 9. Verification & Acceptance Testing

### Automated Offline Test Suite
10 test suites comprising **893 total offline checks** passed with zero database connections:
- `tests/settlement.php`: **152 checks PASS** (lock ordering, atomic release, rollbacks, repeat prevention, CSRF, RBAC, view rendering).
- `tests/wallet.php`: **50 checks PASS** (initial balance, demo credit, ledger pagination, `Related Job` column, back nav).
- `tests/escrow.php`: **60 checks PASS** (funding calculations, insufficient balance, double-funding prevention).
- `tests/milestones.php`: **81 checks PASS** (milestone locking, progress tracking).
- `tests/submissions.php`: **252 checks PASS** (milestone and final submissions, attempt history, reviews).
- `tests/bidding.php`: **99 checks PASS** (bid placement, acceptance).
- `tests/awarding.php`: **55 checks PASS** (job awarding, winning bid linking).
- `tests/freelancer-profile.php`: **49 checks PASS** (freelancer profile verification).
- `tests/homepage.php`: **37 checks PASS** (public landing routes).
- `tests/shared-navigation.php`: **58 checks PASS** (navigation bars, role scoping).

### Confirmed Live Database State (Port 3309, `gighub_dev`)
Verified via read-only queries following user manual acceptance testing:
- **Job 2**: `status = 'completed'`, `completed_at = '2026-11-05 06:29:55'`
- **Escrow 1**: `status = 'released'`, `amount = '4300.00'`, `settled_at = '2026-11-05 06:29:55'`
- **Client (User 6) Wallet**: Balance = `RM5,700.00` (RM10,000 demo credit - RM4,300 funded escrow).
- **Freelancer (User 116) Wallet**: Balance = `RM4,300.00` (+RM4,300 released escrow).
- **Wallet Entries**: Exactly 3 ledger records:
  1. ID 1: `demo_credit`, `+10000.00`, user 6
  2. ID 2: `escrow_fund`, `-4300.00`, user 6, job 2
  3. ID 3: `escrow_release`, `+4300.00`, user 116, job 2

---

## 10. Known Limitations & Future Work

1. **Simulated Payouts Only**: Wallets are closed-loop demo accounts. No bank withdrawals, Stripe/PayPal transfers, or currency exchanges exist.
2. **Full Escrow Release**: Release is all-or-nothing based on the agreed winning bid. Partial milestone-based payout splits are deferred to post-MVP.
3. **No Dispute Resolution**: In the current MVP, completed final approval automatically enables release. Dispute mediation and arbitration workflows are post-MVP enhancements.
