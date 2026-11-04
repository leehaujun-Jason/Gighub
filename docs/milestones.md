# Module 14B — Job Milestone Definition MVP

## Scope & Architecture

Module 14B implements task and deliverable milestone definition for awarded, unfunded jobs. It enables Clients to establish checkpoint breakdowns before committing escrow funds, adhering to approved database decisions in `docs/database-decisions.md` (decision #5) and the schema invariants in `docs/database-design.md`.

In GigHub, milestones represent task deliverables and progress checkpoints. Per approved decision #5, milestones do not carry monetary amounts or partial escrow release allocations; the awarded bid price represents the full escrow commitment. No funds are charged or released during milestone definition.

## Routes & User Experience

All routes adhere to the query parameter convention `?route=...`:

- `GET /client/jobs/milestones&job_id={id}`: Milestone dashboard displaying sequential milestones, due dates, status badges, and management actions (Create, Edit, Delete).
- `POST /client/jobs/milestones/create`: Creates a new milestone for the specified awarded, unfunded job.
- `GET /client/jobs/milestones/edit&id={id}&job_id={job_id}`: Milestone editing interface with pre-filled title, description, and due date.
- `POST /client/jobs/milestones/update`: Updates an existing pending milestone.
- `POST /client/jobs/milestones/delete`: Deletes a pending milestone and sequentially reindexes subsequent positions.

Access points are linked from the awarded bid banner on `?route=/client/jobs/bids` and the Client Job Detail view `?route=/client/jobs/show`.

## Security, Validation & Ordering Safeguards

1. **Role-Based Access Control (RBAC):** Only active, email-verified accounts with role `client` can access milestone management routes. Freelancers and other roles receive HTTP 403 Forbidden.
2. **Strict Ownership Scoping:** The authenticated Client ID is derived from the session (`AuthGuard`). All queries verify that the job belongs to the session Client (`client_id = ?`). Attempting to access another client's job returns HTTP 404 Not Found.
3. **Job Lifecycle Enforcement:** Milestones can only be created, updated, or deleted when `jobs.status = 'awarded'`, `jobs.awarded_bid_id IS NOT NULL`, and no escrow record exists for the job (`hasEscrow = false`). Any attempt to alter milestones outside this window returns HTTP 409 Conflict.
4. **Lock Ordering & Concurrency:** Transaction boundaries in `MilestoneService` acquire row locks in strict order:
   - `jobs` row lock (`FOR UPDATE`)
   - `users` + `client_profiles` row lock (`FOR UPDATE`)
   - `escrows` existence verification under lock
   - `milestones` row lock (`FOR UPDATE`)
5. **Purpose-Specific CSRF:** State-changing requests (`create`, `update`, `delete`) require a valid `job_milestone` purpose CSRF token, rotated upon successful submission.
6. **Input Validation:**
   - Title: Required, trimmed, 1–150 characters, non-empty.
   - Description: Optional, trimmed, max 2,000 characters.
   - Due Date: Required `YYYY-MM-DD` format; must be a valid calendar date strictly between the current Malaysia date (`today`) and the job's delivery deadline (`jobs.deadline`), inclusive. Invalid dates or dates beyond the deadline return HTTP 422 Unprocessable Entity.
   - Limit: Maximum 20 milestones per job (`MAX_MILESTONES`).
7. **Sequential Reindexing & Unique Constraint:**
   - The table enforces `UNIQUE (job_id, position)` and `position > 0`.
   - New milestones are assigned `MAX(position) + 1` under transaction lock.
   - Upon deletion of position `N`, subsequent rows are decremented sequentially using `ORDER BY position ASC`:
     ```sql
     UPDATE milestones SET position = position - 1 WHERE job_id = ? AND position > ? ORDER BY position ASC
     ```
     Because the target slot was just vacated and rows are updated in ascending order, each position shifts down without duplicate collision.

## Database Impact, Grants & Migrations

- **No schema migration required.** The `milestones` table was already created in `database/schema.sql` with full constraints.
- **Environment Privilege Resolution:**
  - During initial live acceptance testing, milestone creation and editing succeeded, but milestone deletion failed with HTTP 503 (`DELETE command denied to user 'gighub_app'@'localhost' for table 'milestones'`).
  - Investigation revealed the application user `gighub_app@127.0.0.1` was originally provisioned with `SELECT, INSERT, UPDATE` on `milestones` (matching the pattern previously encountered in Module 12A on `user_skills`).
  - **Resolution:** The missing table-level privilege was granted directly on clean MariaDB port 3309:
    ```sql
    GRANT DELETE ON `gighub_dev`.`milestones` TO `gighub_app`@`127.0.0.1`;
    FLUSH PRIVILEGES;
    ```
  - This is an environment configuration adjustment, not a schema migration or DDL alteration.

## Verification & Manual Acceptance

### Offline Automated Verification
- `tests/milestones.php` executed offline with mocked PDO and repository: **71 PASS**.
  - Covers unauthenticated redirects, cross-role 403 denial, non-owner 404, invalid job lifecycle states (409), funded escrow rejection (409), title/date/deadline validation (422), 20-milestone limit enforcement (422), sequential reindexing order, CSRF verification, and transactional savepoints.
- PHP CLI syntax checks (`php -l`): all created and modified files passed with 0 errors.

### Confirmed Manual Acceptance (4 November 2026)
User confirmed all manual acceptance checks on Job 2 (`id = 2`):
1. **Creation:** Added two sequential milestones with valid due dates.
2. **Editing:** Updated Milestone 1 title and due date; changes persisted correctly.
3. **Validation:** Due dates beyond the job deadline (`2027-01-31`) were rejected with HTTP 422 inline errors.
4. **Deletion & Reindexing:** Deleted Milestone 1. Milestone 2 was sequentially shifted from position 2 to position 1 without uniqueness collisions.
5. **Database State:** Verified read-only that rows and positions match on-screen presentation.
6. **Role Isolation:** Freelancer access to `/client/jobs/milestones` was denied with HTTP 403.

## Next Stage

- **Module 14C:** Simulated Escrow Funding (transferring demo credit from Client wallet into escrow for awarded jobs with defined milestones).
