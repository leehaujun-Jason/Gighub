# Module 15B: Client Milestone Review & Revision Request

## 1. Overview and Business Context

Module 15B builds upon the foundational submission infrastructure established in Module 15A. It introduces the Client review loop for milestone deliverables on funded, in-progress projects in GigHub:

1. **Client Milestone Approval**: The owning Client can review the winning Freelancer's latest milestone deliverable and approve the checkpoint, marking it completed with an optional written commendation or approval note.
2. **Client Revision Requests**: The owning Client can request revisions if deliverables require corrections or improvements. Written feedback explaining the required revisions is mandatory (1–5,000 characters).
3. **Freelancer Feedback Visibility & Resubmission**: The winning Freelancer can inspect the Client's review decision and written feedback directly in their workspace. When revisions are requested, the Freelancer can submit an updated deliverable (Attempt #N+1), transitioning the milestone back to `submitted` for re-review.
4. **Historical Preservation**: All submission attempts, deliverable URLs, freelancer notes, review decisions, client feedback, and timestamps are immutably preserved in `work_submissions` to provide an auditable chronological record.
5. **Simulated Escrow Isolation**: Non-financial milestone checkpoint only. Escrow funds remain locked (`amount = 4300.00`, `status = 'locked'`). No wallet debits, credits, or simulated releases occur in Module 15B.

---

## 2. Architecture & Lifecycle State Transitions

### Milestone Status Transitions
- `pending` &rarr; `submitted` (Module 15A: Freelancer submits deliverable Attempt #1)
- `submitted` &rarr; `approved` (Module 15B: Client approves deliverable; sets `approved_at = UTC_TIMESTAMP()`)
- `submitted` &rarr; `revisions_requested` (Module 15B: Client requests revisions; `approved_at` remains `NULL`)
- `revisions_requested` &rarr; `submitted` (Module 15B: Freelancer submits revised deliverable Attempt #N+1)

### Submission Status Transitions (`work_submissions`)
- Created in `submitted` status (`reviewed_by = NULL`, `reviewed_at = NULL`, `feedback = NULL`)
- On Approval: `status = 'approved'`, `reviewed_by = client_id`, `reviewed_at = UTC_TIMESTAMP()`, `feedback = [optional note]`
- On Revision Request: `status = 'revisions_requested'`, `reviewed_by = client_id`, `reviewed_at = UTC_TIMESTAMP()`, `feedback = [mandatory text]`

### Database CHECK Constraints
- `ck_submissions_review`: When `status IN ('approved','revisions_requested')`, `submitted_at`, `reviewed_by`, and `reviewed_at` MUST be non-null.
- `ck_submissions_revision`: When `status = 'revisions_requested'`, `feedback` MUST be non-null and `CHAR_LENGTH(TRIM(feedback)) > 0`.
- `ck_milestones_approval`: When `status = 'approved'`, `approved_at` MUST be non-null; when `<> 'approved'`, `approved_at` MUST be `NULL`.

---

## 3. Concurrency Protection & Transaction Safety

All review mutations are encapsulated within database transactions utilizing strict lock ordering and atomic row-level guards:

### Lock Ordering
To prevent deadlocks between concurrent client review, freelancer resubmission, and administrative inspection, resources are locked in an invariable global sequence:
$$\text{jobs} \longrightarrow \text{users} \longrightarrow \text{milestones} \longrightarrow \text{work\_submissions}$$

1. **Job Lock (`SELECT ... FOR UPDATE`)**: Validates job existence, verifies client ownership (`client_id = authenticated_user`), and confirms `status = 'in_progress'`.
2. **Client User Lock (`SELECT ... FOR UPDATE`)**: Confirms client account is active, verified, and not suspended.
3. **Escrow Check**: Verifies that a locked escrow record exists for the job (`status = 'locked'`).
4. **Milestone Lock (`SELECT ... FOR UPDATE`)**: Confirms the milestone belongs to the job and is currently awaiting review (`status = 'submitted'`).
5. **Targeted Submission Lock (`SELECT ... FOR UPDATE`)**:
   - Explicit targeting check: Verifies that the submitted `submission_id` matches the latest attempt for `(job_id, milestone_id)`.
   - Stale targeting protection: Rejects out-of-order, stale, or already-reviewed submission IDs with HTTP 409 Conflict.
6. **Guarded Row Updates (`rowCount() === 1`)**:
   - `UPDATE work_submissions SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), feedback = ? WHERE id = ? AND status = 'submitted'`
   - `UPDATE milestones SET status = ?, approved_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND job_id = ? AND status = 'submitted'`
   - If either update returns `rowCount() !== 1` (e.g. concurrent review race), the transaction rolls back immediately and raises HTTP 409 Conflict.

---

## 4. Security, Authorization & PRG Workflow

- **Role-Based Access Control**:
  - Review endpoints (`POST /client/milestones/approve` and `POST /client/milestones/request-revision`) are restricted to active, verified Clients owning the job. Freelancers, unauthenticated users, or other clients receive 403 Forbidden or 404 Not Found.
  - Resubmission endpoint (`POST /freelancer/milestones/submit`) is restricted to the awarded Freelancer.
- **CSRF Token Separation**:
  - Reviews require purpose-specific CSRF token `'milestone_review'` (isolated from `'work_submission'` and `'job_milestone'`).
  - Rotated immediately upon successful state changes.
- **Post/Redirect/Get (PRG) & Notifications**:
  - All state-changing POST endpoints redirect back to the milestone workspace via 303 See Other.
  - Success and error messages are dispatched through the global dismissible notification system (`GigHub\Core\Notification`) rendering accessible `.gh-alert` banners.
  - Approval success message: `"Milestone approved successfully. Checkpoint marked as completed."`
  - Revision success message: `"Revision requested successfully. The freelancer can view your feedback."`
  - On validation errors (422), errors and previous input are preserved in `$_SESSION['review_errors']` and `$_SESSION['review_old']` and displayed inline beneath the input.

---

## 5. UI Architecture & Refinements

### Redesigned Submission History Cards (`partials/submission-history.php`)
- Replaced monolithic text blocks with individual responsive cards (`.gh-sub-card`).
- **4px Left Accent Border**: Color-coded by attempt status (Green for Approved, Amber for Revisions Requested, Brand Green for Pending Review).
- **Structured Header**: Clear Attempt Number badge (`Attempt #N`), pill status badge (`✓ Approved`, `↺ Revisions Requested`, `⏳ Pending Review`), and UTC submission timestamp.
- **Deliverable Link**: Explicitly labeled with external indicator `↗` and safe wrapping (`overflow-wrap: anywhere; word-break: break-all`).
- **Submission Notes**: Dedicated content block. Notes exceeding 300 characters feature an accessible, zero-JS HTML5 `<details><summary>` toggle showing a 240-character preview with expand/collapse controls (`Read full notes (N characters)` / `Collapse notes`).
- **Review Feedback Box**: Displayed in a distinct colored callout card within the attempt card, displaying review title, feedback body, and UTC review timestamp.
- **Unified Partial**: The same `partials/submission-history.php` component is reused across both Client and Freelancer milestone workspaces and the Freelancer work submission form.

### Redesigned Review Form Controls
- **Desktop 2-Column Responsive Grid**: Displays Approve Milestone and Request Revisions side-by-side on desktop ($\ge 768\text{px}$) and stacked on mobile.
- **Full-Width Form Controls**: Labels positioned above inputs; consistent padding, border radius, and accessible focus outlines (`outline: 2px solid rgba(23,79,64,0.25)`).
- **Textarea Sizing**: Approval note uses a 2-row textarea; Revision feedback uses a 4-row textarea with a minimum height of `5rem` (`rows="4"`).
- **Button Hierarchy**: Primary solid green button for approval (`.gh-button--approve`) and amber outline secondary button for revision request (`.gh-button--revision`), both meeting 44px touch targets.

---

## 6. Runtime Regression Root Cause & Fix

### Regression Description
Following the initial UI refinement, navigating to `/client/jobs/milestones` or `/freelancer/jobs/milestones` resulted in an unhandled 500 error: `"An application error occurred."`.

### Root Cause
1. Dynamic asset cache-busting used `hash_file('sha256', dirname(__DIR__, 3) . '/public/assets/milestones.css')`.
2. Because views are nested 4 levels deep (`app/Views/client/milestones/`), `dirname(__DIR__, 3)` resolved to `C:\xampp\htdocs\GigHub\app`, causing `hash_file` to attempt opening non-existent path `C:\xampp\htdocs\GigHub\app/public/assets/...`.
3. The resulting PHP Warning was converted by `public/index.php`'s strict `set_error_handler` into an uncaught `ErrorException`, which triggered the top-level 500 exception handler.
4. Additionally, `MilestoneController` looked only for `$query['job_id']`, rejecting `$query['id']` with 400.

### Fix Applied
1. Replaced dynamic `hash_file` lookups in views with standard static query versioning (`assets/milestones.css?v=2` and `assets/submissions.css?v=2`), eliminating filesystem operations during template rendering.
2. Added defensive variable fallbacks (`$milestoneSubs = $milestoneSubs ?? [];`, `$escape = $escape ?? ...;`) inside `partials/submission-history.php`.
3. Updated `MilestoneController` to accept both `$query['job_id']` and `$query['id']`.
4. Hardened test suites (`tests/milestones.php` and `tests/submissions.php`) by installing the strict `ErrorException` error handler in CLI, adding regression checks for view rendering with submitted milestones and histories.

---

## 7. Verification Evidence & Test Results

### Offline Automated Test Suites (All Passed with 0 Failures)
- `tests/milestones.php`: **PASS (81 checks)** — Milestone lifecycle, validation, shift re-indexing, RBAC, strict error handling, and view rendering.
- `tests/submissions.php`: **PASS (156 checks)** — Submission validation, review validation, lock ordering, guarded rollback, explicit targeting, resubmission flow, controller PRG, and notifications.
- `tests/escrow.php`: **PASS (60 checks)** — Escrow lifecycle, funding constraints, double-funding protection.
- `tests/wallet.php`: **PASS (45 checks)** — Wallet ledger operations, concurrency safety, balance invariants.
- `tests/awarding.php`: **PASS (55 checks)** — Bid selection, contract generation, state transitions.
- `tests/bidding.php`: **PASS (99 checks)** — Bid placement, revision, and authorization.
- `tests/authentication.php`: **PASS (59 checks)** — Session security, login, and identity management.
- **Total Passing Checks**: **555 tests passing across 7 suites**.

### Manual Acceptance Verification (Job 2 Fixtures)
Manual browser testing confirmed the complete review and resubmission loop on live database `gighub_dev`:
1. **Validation Rejection**: Client submitting blank revision feedback received an inline error and a dismissible warning notification (`"Please provide feedback explaining the requested revisions."`).
2. **Revision Request**: Client submitted revision request (`"Please add mobile responsive breakpoints..."`). Milestone transitioned to `revisions_requested`. Attempt #1 updated to `revisions_requested` with feedback preserved. Success banner flashed.
3. **Freelancer Resubmission**: Freelancer inspected Client feedback, accessed the resubmission form with feedback banner, and submitted Attempt #2 (`https://github.com/example/cafe-ordering/pull/2`). Milestone returned to `submitted`. Attempt #2 created in `submitted` status; Attempt #1 preserved in history.
4. **Milestone Approval**: Client reviewed Attempt #2 and approved with commendation note (`"Mobile layout and accessibility verified. Milestone approved!"`). Milestone transitioned to `approved` with UTC timestamp. Attempt #2 updated to `approved`.
5. **Post-Fix Workspace Retest**: Both Client and Freelancer milestone workspaces load with HTTP 200, rendering history cards and approved checkpoint badges with zero errors.

---

## 8. Current Database State (Post-Testing Baseline)

Read-only inspection of clean MariaDB port 3309 (`gighub_dev`) confirms:
- **`jobs` (id = 2)**: `client_id = 6`, `status = 'in_progress'`, `title = 'Cafe Ordering System development'`, `awarded_bid_id = 1`.
- **`milestones` (id = 2)**: `job_id = 2`, `status = 'approved'`, `approved_at = '2026-11-04 13:44:19'`.
- **`work_submissions`**:
  - **Attempt #2 (id = 2)**: `milestone_id = 2`, `submitted_by = 116`, `status = 'approved'`, `feedback = 'Mobile layout and accessibility verified. Milestone approved!'`.
  - **Attempt #1 (id = 1)**: `milestone_id = 2`, `submitted_by = 116`, `status = 'revisions_requested'`, `feedback = 'Please add mobile responsive breakpoints for the menu cards and include ARIA labels for accessibility.'`.
- **`escrows` (id = 1)**: `job_id = 2`, `amount = 4300.00`, `status = 'locked'`.
- **`wallets`**: Client 6 balance = `5700.00`, Freelancer 116 balance = `0.00` (zero funds moved).

---

## 9. Known Limitations & Deferred Scope

- **No Milestone Payout or Escrow Release**: In accordance with Decision #5, escrow release occurs only upon overall job completion (Module 15C). Approving a milestone does not release partial funds.
- **Single File / Deliverable URL**: Deliverables support an external HTTP/HTTPS URL and notes; direct binary file uploads remain deferred to future iterations.
- **Single Active Milestone Target**: In accordance with MVP requirements, review actions target the latest submission for an awaiting milestone.
