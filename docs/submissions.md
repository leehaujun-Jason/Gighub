# Module 15A — Freelancer Work Submission MVP

## Scope & Architecture

Module 15A implements milestone-level work deliverable submissions for awarded Freelancers on funded, in-progress jobs. It enables Freelancers to submit deliverable progress for specific checkpoints, preserves an append-only submission history, updates milestone statuses, and provides read-only submission viewing for the owning Client.

This module follows the approved database decisions in `docs/database-decisions.md` (Decision #7) and schema specifications in `docs/database-design.md`:
- **Checkpoint-Level Submissions:** Freelancers submit work against defined project milestones (`submission_type = 'milestone'`).
- **Append-Only Submission History:** Each submission is recorded as a distinct row in `work_submissions`, preserving all attempts, timestamps, notes, and deliverable links. Previous attempts are never overwritten or deleted.
- **Simulated & URL Deliverables:** In accordance with FYP MVP requirements and privacy governance (Issues #81 & #82), deliverables utilize external repository/cloud links (`deliverable_url`) and comprehensive documentation (`notes`). No arbitrary file upload infrastructure or filesystem mutations are introduced in this module.
- **No Client Review, Revisions, or Escrow Payouts:** Module 15A covers submission only. Client approval, revision requests, final job completion, and escrow fund release are strictly deferred to Module 15B.

---

## Routes & Workflow

All routes adhere to the query parameter convention `?route=...`:

- `GET /freelancer/milestones/submit&job_id={job_id}&milestone_id={milestone_id}`: Deliverable submission form for the winning Freelancer (`SubmissionController::createForm`). Displays project metadata, milestone scope, due date, deliverable URL input, notes textarea, and prior submission history.
- `POST /freelancer/milestones/submit`: Processes deliverable submission (`SubmissionController::submit`). Validates input, acquires row locks, atomically records the submission, transitions the milestone status, rotates the CSRF token, and redirects via PRG to `?route=/freelancer/jobs/milestones&job_id={job_id}` with a success notification.
- `GET /freelancer/jobs/milestones&job_id={job_id}`: Freelancer milestone dashboard. Displays *"Submit Deliverable"* button for eligible milestones (`pending` or `revisions_requested`), an *"Awaiting client review"* badge for submitted milestones, and historical submission cards with notes and safe external links.
- `GET /client/jobs/milestones&job_id={job_id}`: Client milestone dashboard. Displays updated milestone status (`Status: Submitted`), an informational note explaining client review will be available in Module 15B, and full read-only submission cards.

---

## Input Validation & Sanitization

Input is validated through `SubmissionValidator` (`app/Services/SubmissionValidator.php`):
1. **Submission Notes**:
   - Required.
   - Trimmed of leading and trailing whitespace.
   - Enforces a minimum length of 1 non-whitespace character (`CHAR_LENGTH(TRIM(notes)) > 0`, matching check constraint `ck_submissions_notes`).
   - Maximum length of 5,000 characters.
2. **Deliverable URL**:
   - Optional. If empty or whitespace-only, normalized to `null`.
   - If provided, validated as a proper HTTP/HTTPS address using `filter_var(..., FILTER_VALIDATE_URL)` and strict `^https?://` regex check.
   - Maximum length of 2,048 characters (matching schema `VARCHAR(2048)`).
   - Output rendering safely escapes the URL with `htmlspecialchars()` and enforces `target="_blank" rel="noopener noreferrer"` to prevent reverse tabnabbing and XSS.

---

## Transaction Safety & Atomic State Machine

The submission executes within a single atomic database transaction with strict row locking in `SubmissionService`:

1. **Lock Ordering**:
   Row locks are acquired in a uniform, deadlock-safe sequence:
   1. `jobs` row lock (`FOR UPDATE`)
   2. `users` + `freelancer_profiles` eligibility lock (`FOR UPDATE`)
   3. `milestones` row lock (`FOR UPDATE`)

2. **Invariants Checked Under Lock**:
   - Job must exist and have status `in_progress`.
   - The job's `awarded_bid_id` must match a winning bid belonging to the authenticated freelancer.
   - Escrow for the job must exist and be in status `locked`.
   - Milestone must exist, belong to the target job (`job_id`), and have status `pending` or `revisions_requested`.
   - **Duplicate Submission Guard**: If the milestone is already in `submitted` or `approved` status, further submissions are blocked with HTTP 409 Conflict.

3. **Atomic Operations**:
   - **Submission Record Insertion**: Inserts a row into `work_submissions` with `submission_type = 'milestone'`, `status = 'submitted'`, and `submitted_at = UTC_TIMESTAMP()`, keeping `reviewed_by`, `reviewed_at`, and `feedback` `NULL` (adhering strictly to check constraint `ck_submissions_review`).
   - **Milestone Status Update**: Updates `milestones.status` to `'submitted'` and `updated_at = UTC_TIMESTAMP()`.
   - Any failure or validation exception triggers an immediate transaction rollback, ensuring milestone and submission records remain strictly consistent.

---

## Notifications & User Experience (GEMINI.md Rule 12)

- **Success Notification**: Upon successful submission, a dismissible green alert is flashed:
  > *"Work deliverable submitted successfully. Awaiting client review."*
- **Validation Recovery**: Validation errors (HTTP 422) preserve user input in session storage (`$_SESSION['submission_old']`) and redirect back to the submission form with inline field errors and a dismissible warning alert.
- **CSRF Protection**: Protected with purpose-specific `work_submission_csrf` tokens rotated after successful writes.

---

## Database Impact & Migration Status

- **Zero Schema Migrations**: The `work_submissions` and `milestones` tables were already fully defined in `database/schema.sql` with all keys, constraints, and enums.
- **Zero Privilege Changes**: The application database user `gighub_app@127.0.0.1` already holds `SELECT, INSERT, UPDATE` on `work_submissions` and `SELECT, INSERT, UPDATE, DELETE` on `milestones`.

---

## Verification & Manual Acceptance

### Offline Automated Verification
- `tests/submissions.php`: **80 PASS** (mocked PDO, zero database connections).
  - Validates missing/whitespace notes rejection (422), 5000-char limits (422), invalid URL schemes/malformed URLs (422), valid HTTPS/HTTP URLs, unauthenticated redirects (303), client/admin submission denial (403), unassigned freelancer denial (404), non-existent job/milestone (404), mismatched job milestone (404), unfunded/unawarded job rejection (409), duplicate active submission prevention (409), atomic transaction rollback, CSRF token validation and rotation, exact success notification, and client/freelancer submission history listing.
- `tests/milestones.php`: **74 PASS**.
- `tests/escrow.php`: **60 PASS**.
- `tests/wallet.php`: **45 PASS**.
- `tests/awarding.php`: **55 PASS**.
- `tests/bidding.php`: **99 PASS**.
- PHP CLI syntax checks (`php.exe -l`): All created and modified files passed with 0 errors.
- Staged whitespace check (`git diff --cached --check`): Clean.

### Confirmed Manual Acceptance Results (Job 2)
Performed and verified on live clean MariaDB (`127.0.0.1:3309`, `gighub_dev`):
1. **Freelancer 116 Submission**:
   - Logged in as winning Freelancer 116.
   - Visited `?route=/freelancer/jobs/milestones&job_id=2`.
   - Verified Milestone #1 (`Frontend Table Layout & Ordering UI`) displayed *"Submit Deliverable"*.
   - Tested validation: empty notes and invalid URLs were rejected with inline errors and dismissible notifications.
   - Submitted deliverable with URL `https://github.com` and comprehensive frontend scope notes.
   - Confirmed PRG redirect with exact notification: *"Work deliverable submitted successfully. Awaiting client review."*
   - Milestone status updated to `Status: Submitted`.
   - Button replaced by badge *"Deliverable submitted — Awaiting client review"*.
   - Submission history card rendered with timestamp, safe external link, and notes.
2. **Client 6 Read-Only Review**:
   - Logged in as Client 6.
   - Visited `?route=/client/jobs/milestones&job_id=2`.
   - Milestone #1 reflected `Status: Submitted`.
   - Info notice displayed: *"Deliverable submitted by freelancer. Client review and milestone approval will be available in the next module."*
   - Submission card visible with full deliverable details.
   - No edit or submission controls displayed.
3. **Role Isolation**:
   - Client access to `/freelancer/milestones/submit` returned HTTP 403 Forbidden.
   - Unassigned freelancer access to `/freelancer/milestones/submit` returned HTTP 404 Not Found.
4. **Current Live Database State (Read-Only Verification)**:
   - `jobs` (id 2): `status = 'in_progress'`, `awarded_bid_id = 1`, `client_id = 6`.
   - `milestones` (id 2): `status = 'submitted'`, `title = 'Frontend Table Layout & Ordering UI'`, `due_date = '2026-12-31'`.
   - `work_submissions` (id 1): `job_id = 2`, `milestone_id = 2`, `submitted_by = 116`, `submission_type = 'milestone'`, `status = 'submitted'`, `deliverable_url = 'https://github.com'`, `submitted_at = '2026-11-04 12:34:16'`.
   - `escrows` (id 1): `amount = '4300.00'`, `status = 'locked'`.
   - `wallets` (user 6): `balance = '5700.00'`.
   - Wallet and escrow records remain completely unchanged.

---

## Known Limitations & Next Module

1. **Client Approval & Revision Actions**: Module 15A provides read-only viewing for the Client. Client deliverable review, approval, revision feedback forms, and milestone completion transitions will be introduced in Module 15B.
2. **Escrow Funds Remain Locked**: Escrow funds remain locked in status `locked`. Escrow release and payout to the Freelancer wallet occur upon final project completion.
3. **No File Uploads**: External deliverable URLs (e.g. GitHub, Figma, Cloud Storage) provide safe, robust deliverable submission without the security complexities of arbitrary file handling.

---

## Next Stage

- **Module 15B — Client Deliverable Review & Milestone Approval MVP**:
  - Client review interface for submitted deliverables.
  - Client approval (transitioning milestone to `approved` with `approved_at = UTC_TIMESTAMP()`).
  - Revision request workflow (transitioning milestone to `revisions_requested` with required client feedback, unlocking a new submission attempt for the Freelancer).
