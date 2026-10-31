# GigHub initial database design

Status: accepted development database foundation after successful isolated MariaDB 10.4.32 import and runtime tests. PHP workflows remain untested. Prompt 07 does not authorize re-importing/modifying the test database or selecting it as the application database.

## Scope and implementation plan

Target: PHP 8.2, MariaDB 10.4.32, InnoDB, utf8mb4_unicode_ci. Exactly 20 tables, one deferred foreign-key ALTER, and two append-only ledger triggers. No database creation, data seeding, grants, connection, XAMPP change, dependency installation or automatic commit is included.

Authoring steps: (1) inspect clean Git state and existing files; (2) reconcile the report with the 14 approved decisions; (3) create schema with explicit constraints; (4) document transaction/authorization boundaries and assumptions; (5) perform offline structural checks and review the diff. No other plan file is created.

`database/schema.sql` is authoritative for exact SQL types, nullability, defaults, generated expressions and constraint names. The following dictionary is extracted directly from that file. Required foreign-key indexes are explicit. INT UNSIGNED IDs and PK/FK types match. Amounts use DECIMAL(10,2); RM is the only currency. Parse validated decimal input into integer sen in PHP; do not calculate money with floats. Datetimes are UTC by application connection policy, displayed in Asia/Kuala_Lumpur as needed. No global/server setting is changed by this schema.

## Database dictionary

For each table, the column definition gives its type, NULL rule and default verbatim. No default for role, event type, purpose, or submission type is intentional: the caller must explicitly supply the semantic choice. All lifecycle `status` columns have defaults. Nullable columns default to NULL implicitly when not written otherwise; TEXT fields have no explicit defaults for portability. A single-column PK or junction PK identifies each row. All foreign keys use RESTRICT, preserving historical references; authorized archive/status changes replace hard deletion.

### users

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `email` | `VARCHAR(254) NOT NULL` |
| `password_hash` | `VARCHAR(255) NOT NULL` |
| `display_name` | `VARCHAR(100) NOT NULL` |
| `role` | `ENUM('client','freelancer','admin') NOT NULL` |
| `status` | `ENUM('pending_verification','active','suspended') NOT NULL DEFAULT 'pending_verification'` |
| `email_verified_at` | `DATETIME NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_users_email UNIQUE (email)`
- `KEY ix_users_role_status (role, status)`
- `CONSTRAINT ck_users_email CHECK (CHAR_LENGTH(TRIM(email)) > 0)`
- `CONSTRAINT ck_users_name CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)`
- `CONSTRAINT ck_users_active_verified CHECK (status <> 'active' OR email_verified_at IS NOT NULL)`

### freelancer_profiles

| Column | Definition |
|---|---|
| `user_id` | `INT UNSIGNED NOT NULL` |
| `bio` | `TEXT NULL` |
| `portfolio_description` | `TEXT NULL` |
| `portfolio_url` | `VARCHAR(2048) NULL DEFAULT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (user_id)`
- `CONSTRAINT fk_freelancer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

### client_profiles

| Column | Definition |
|---|---|
| `user_id` | `INT UNSIGNED NOT NULL` |
| `company_name` | `VARCHAR(100) NULL DEFAULT NULL` |
| `bio` | `TEXT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (user_id)`
- `CONSTRAINT fk_client_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

### email_verification_tokens

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `user_id` | `INT UNSIGNED NOT NULL` |
| `token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` |
| `expires_at` | `DATETIME NOT NULL` |
| `used_at` | `DATETIME NULL DEFAULT NULL` |
| `revoked_at` | `DATETIME NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_email_token_hash UNIQUE (token_hash)`
- `KEY ix_email_tokens_user_expiry (user_id, expires_at)`
- `CONSTRAINT fk_email_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_email_token_hash CHECK (token_hash REGEXP '^[0-9a-f]{64}$')`
- `CONSTRAINT ck_email_token_expiry CHECK (expires_at > created_at)`
- `CONSTRAINT ck_email_token_consumed CHECK (used_at IS NULL OR revoked_at IS NULL)`

### skills

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `name` | `VARCHAR(50) NOT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_skills_name UNIQUE (name)`
- `CONSTRAINT ck_skills_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)`

### user_skills

| Column | Definition |
|---|---|
| `freelancer_id` | `INT UNSIGNED NOT NULL` |
| `skill_id` | `INT UNSIGNED NOT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (freelancer_id, skill_id)`
- `KEY ix_user_skills_skill (skill_id, freelancer_id)`
- `CONSTRAINT fk_user_skill_freelancer FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_user_skill_skill FOREIGN KEY (skill_id) REFERENCES skills (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

### jobs

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `client_id` | `INT UNSIGNED NOT NULL` |
| `awarded_bid_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `title` | `VARCHAR(150) NOT NULL` |
| `description` | `TEXT NOT NULL` |
| `budget` | `DECIMAL(10,2) NOT NULL` |
| `deadline` | `DATE NOT NULL` |
| `status` | `ENUM('draft','open','awarded','in_progress','under_review','disputed','completed','cancelled','closed_disputed') NOT NULL DEFAULT 'draft'` |
| `awarded_at` | `DATETIME NULL DEFAULT NULL` |
| `completed_at` | `DATETIME NULL DEFAULT NULL` |
| `closed_at` | `DATETIME NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_jobs_award_pair UNIQUE (id, awarded_bid_id)`
- `KEY ix_jobs_client_status (client_id, status)`
- `KEY ix_jobs_status_created (status, created_at, id)`
- `CONSTRAINT fk_job_client FOREIGN KEY (client_id) REFERENCES client_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_jobs_budget CHECK (budget > 0)`
- `CONSTRAINT ck_jobs_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)`
- `CONSTRAINT ck_jobs_description CHECK (CHAR_LENGTH(TRIM(description)) > 0)`
- `CONSTRAINT ck_jobs_award CHECK ( (status IN ('draft','open') AND awarded_bid_id IS NULL) OR (status IN ('awarded','in_progress','under_review','disputed','completed','closed_disputed') AND awarded_bid_id IS NOT NULL) OR status = 'cancelled' )`
- `CONSTRAINT ck_jobs_award_time CHECK ( (awarded_bid_id IS NULL AND awarded_at IS NULL) OR (awarded_bid_id IS NOT NULL AND awarded_at IS NOT NULL) )`
- `CONSTRAINT ck_jobs_completion CHECK (status <> 'completed' OR completed_at IS NOT NULL)`
- `CONSTRAINT ck_jobs_closure CHECK (status NOT IN ('completed','cancelled','closed_disputed') OR closed_at IS NOT NULL)`
- Deferred `fk_job_awarded_bid`: `(id, awarded_bid_id)` references `bids(job_id, id)` with RESTRICT on update/delete. Deferred here means added later in the file, not a deferrable SQL constraint.

### job_skills

| Column | Definition |
|---|---|
| `job_id` | `INT UNSIGNED NOT NULL` |
| `skill_id` | `INT UNSIGNED NOT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (job_id, skill_id)`
- `KEY ix_job_skills_skill (skill_id, job_id)`
- `CONSTRAINT fk_job_skill_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_job_skill_skill FOREIGN KEY (skill_id) REFERENCES skills (id) ON DELETE RESTRICT ON UPDATE RESTRICT`

### bids

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `freelancer_id` | `INT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(10,2) NOT NULL` |
| `delivery_days` | `SMALLINT UNSIGNED NOT NULL` |
| `cover_letter` | `TEXT NOT NULL` |
| `status` | `ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_bids_job_freelancer UNIQUE (job_id, freelancer_id)`
- `CONSTRAINT uq_bids_job_id UNIQUE (job_id, id)`
- `KEY ix_bids_freelancer_created (freelancer_id, created_at)`
- `CONSTRAINT fk_bid_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_bid_freelancer FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_bids_amount CHECK (amount > 0)`
- `CONSTRAINT ck_bids_delivery CHECK (delivery_days > 0)`
- `CONSTRAINT ck_bids_letter CHECK (CHAR_LENGTH(TRIM(cover_letter)) > 0)`

### milestones

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(150) NOT NULL` |
| `description` | `TEXT NULL` |
| `due_date` | `DATE NOT NULL` |
| `position` | `SMALLINT UNSIGNED NOT NULL` |
| `status` | `ENUM('pending','in_progress','submitted','revisions_requested','approved') NOT NULL DEFAULT 'pending'` |
| `approved_at` | `DATETIME NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_milestones_job_id UNIQUE (job_id, id)`
- `CONSTRAINT uq_milestones_position UNIQUE (job_id, position)`
- `CONSTRAINT fk_milestone_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_milestones_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)`
- `CONSTRAINT ck_milestones_position CHECK (position > 0)`
- `CONSTRAINT ck_milestones_approval CHECK ( (status = 'approved' AND approved_at IS NOT NULL) OR (status <> 'approved' AND approved_at IS NULL) )`

### work_submissions

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `milestone_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `submitted_by` | `INT UNSIGNED NOT NULL` |
| `submission_type` | `ENUM('milestone','final') NOT NULL` |
| `notes` | `TEXT NOT NULL` |
| `deliverable_url` | `VARCHAR(2048) NULL DEFAULT NULL` |
| `status` | `ENUM('draft','submitted','approved','revisions_requested') NOT NULL DEFAULT 'draft'` |
| `submitted_at` | `DATETIME NULL DEFAULT NULL` |
| `reviewed_by` | `INT UNSIGNED NULL DEFAULT NULL` |
| `reviewed_at` | `DATETIME NULL DEFAULT NULL` |
| `feedback` | `TEXT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_submission_author UNIQUE (id, submitted_by)`
- `KEY ix_submissions_job_created (job_id, created_at, id)`
- `KEY ix_submissions_job_milestone (job_id, milestone_id)`
- `KEY ix_submissions_author (submitted_by)`
- `KEY ix_submissions_reviewer (reviewed_by)`
- `CONSTRAINT fk_submission_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_submission_milestone FOREIGN KEY (job_id, milestone_id) REFERENCES milestones (job_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_submission_author FOREIGN KEY (submitted_by) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_submission_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_submissions_type CHECK ( (submission_type = 'milestone' AND milestone_id IS NOT NULL) OR (submission_type = 'final' AND milestone_id IS NULL) )`
- `CONSTRAINT ck_submissions_notes CHECK (CHAR_LENGTH(TRIM(notes)) > 0)`
- `CONSTRAINT ck_submissions_review CHECK ( (status = 'draft' AND submitted_at IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status = 'submitted' AND submitted_at IS NOT NULL AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status IN ('approved','revisions_requested') AND submitted_at IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL) )`
- `CONSTRAINT ck_submissions_revision CHECK (status <> 'revisions_requested' OR (feedback IS NOT NULL AND CHAR_LENGTH(TRIM(feedback)) > 0))`

### uploaded_files

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `owner_user_id` | `INT UNSIGNED NOT NULL` |
| `purpose` | `ENUM('portfolio','work_submission') NOT NULL` |
| `portfolio_user_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `work_submission_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `storage_key` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` |
| `original_name` | `VARCHAR(255) NOT NULL` |
| `mime_type` | `VARCHAR(127) NOT NULL` |
| `size_bytes` | `INT UNSIGNED NOT NULL` |
| `sha256` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` |
| `title` | `VARCHAR(150) NULL DEFAULT NULL` |
| `description` | `TEXT NULL` |
| `status` | `ENUM('pending','active','quarantined','deleted') NOT NULL DEFAULT 'pending'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME NULL DEFAULT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_uploaded_storage_key UNIQUE (storage_key)`
- `KEY ix_uploaded_owner_status (owner_user_id, status)`
- `KEY ix_uploaded_portfolio (portfolio_user_id)`
- `KEY ix_uploaded_submission_owner (work_submission_id, owner_user_id)`
- `CONSTRAINT fk_uploaded_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_uploaded_portfolio FOREIGN KEY (portfolio_user_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_uploaded_submission_owner FOREIGN KEY (work_submission_id, owner_user_id) REFERENCES work_submissions (id, submitted_by) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_uploaded_target CHECK ( (purpose = 'portfolio' AND portfolio_user_id IS NOT NULL AND portfolio_user_id = owner_user_id AND work_submission_id IS NULL) OR (purpose = 'work_submission' AND portfolio_user_id IS NULL AND work_submission_id IS NOT NULL) )`
- `CONSTRAINT ck_uploaded_size CHECK (size_bytes > 0)`
- `CONSTRAINT ck_uploaded_name CHECK (CHAR_LENGTH(TRIM(original_name)) > 0)`
- `CONSTRAINT ck_uploaded_storage CHECK (storage_key REGEXP '^[0-9a-f]{64}$')`
- `CONSTRAINT ck_uploaded_hash CHECK (sha256 REGEXP '^[0-9a-f]{64}$')`
- `CONSTRAINT ck_uploaded_deleted CHECK ( (status = 'deleted' AND deleted_at IS NOT NULL) OR (status <> 'deleted' AND deleted_at IS NULL) )`

### wallets

| Column | Definition |
|---|---|
| `user_id` | `INT UNSIGNED NOT NULL` |
| `balance` | `DECIMAL(10,2) NOT NULL DEFAULT 0.00` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (user_id)`
- `CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_wallet_balance CHECK (balance >= 0)`

### escrows

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `bid_id` | `INT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(10,2) NOT NULL` |
| `status` | `ENUM('locked','released','refunded') NOT NULL DEFAULT 'locked'` |
| `funded_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `settled_at` | `DATETIME NULL DEFAULT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_escrows_job UNIQUE (job_id)`
- `CONSTRAINT uq_escrows_bid UNIQUE (bid_id)`
- `KEY ix_escrows_job_bid (job_id, bid_id)`
- `KEY ix_escrows_status (status)`
- `CONSTRAINT fk_escrow_selected_bid FOREIGN KEY (job_id, bid_id) REFERENCES jobs (id, awarded_bid_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_escrows_amount CHECK (amount > 0)`
- `CONSTRAINT ck_escrows_settled CHECK ( (status = 'locked' AND settled_at IS NULL) OR (status IN ('released','refunded') AND settled_at IS NOT NULL) )`

### wallet_entries

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `wallet_user_id` | `INT UNSIGNED NOT NULL` |
| `escrow_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `entry_type` | `ENUM('demo_credit','escrow_fund','escrow_release','escrow_refund') NOT NULL` |
| `amount_delta` | `DECIMAL(10,2) NOT NULL` |
| `source` | `ENUM('demo_seed','client_funding','client_approval','mutual_cancellation','admin_decision') NOT NULL` |
| `actor_id` | `INT UNSIGNED NOT NULL` |
| `operation_key` | `VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` |
| `settlement_escrow_id` | `INT UNSIGNED GENERATED ALWAYS AS ( CASE WHEN entry_type IN ('escrow_release','escrow_refund') THEN escrow_id ELSE NULL END ) PERSISTENT` |
| `demo_credit_user_id` | `INT UNSIGNED GENERATED ALWAYS AS ( CASE WHEN entry_type = 'demo_credit' THEN wallet_user_id ELSE NULL END ) PERSISTENT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_wallet_operation UNIQUE (operation_key)`
- `CONSTRAINT uq_wallet_escrow_type UNIQUE (escrow_id, entry_type)`
- `CONSTRAINT uq_wallet_settlement UNIQUE (settlement_escrow_id)`
- `CONSTRAINT uq_wallet_demo_credit UNIQUE (demo_credit_user_id)`
- `KEY ix_wallet_entries_user_created (wallet_user_id, created_at, id)`
- `KEY ix_wallet_entries_actor (actor_id)`
- `CONSTRAINT fk_wallet_entry_wallet FOREIGN KEY (wallet_user_id) REFERENCES wallets (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_wallet_entry_escrow FOREIGN KEY (escrow_id) REFERENCES escrows (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_wallet_entry_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_wallet_operation CHECK (CHAR_LENGTH(TRIM(operation_key)) > 0)`
- `CONSTRAINT ck_wallet_entry_semantics CHECK ( (entry_type = 'demo_credit' AND escrow_id IS NULL AND amount_delta > 0 AND source = 'demo_seed') OR (entry_type = 'escrow_fund' AND escrow_id IS NOT NULL AND amount_delta < 0 AND source = 'client_funding') OR (entry_type = 'escrow_release' AND escrow_id IS NOT NULL AND amount_delta > 0 AND source IN ('client_approval','admin_decision')) OR (entry_type = 'escrow_refund' AND escrow_id IS NOT NULL AND amount_delta > 0 AND source IN ('mutual_cancellation','admin_decision')) )`

### chat_messages

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `bid_id` | `INT UNSIGNED NOT NULL` |
| `sender_id` | `INT UNSIGNED NOT NULL` |
| `body` | `TEXT NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `KEY ix_chat_bid_id (bid_id, id)`
- `KEY ix_chat_sender (sender_id)`
- `CONSTRAINT fk_chat_bid FOREIGN KEY (bid_id) REFERENCES bids (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_chat_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_chat_body CHECK (CHAR_LENGTH(TRIM(body)) > 0)`

### notifications

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `recipient_id` | `INT UNSIGNED NOT NULL` |
| `job_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `type` | `ENUM('bid_received','award_selected','award_cancelled','escrow_funded','submission_received','revision_requested','milestone_approved','payment_released','payment_refunded','dispute_opened','dispute_resolved','cancellation_requested','cancellation_responded','job_cancelled','review_received') NOT NULL` |
| `message` | `VARCHAR(500) NOT NULL` |
| `read_at` | `DATETIME NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `KEY ix_notifications_recipient_read (recipient_id, read_at, id)`
- `KEY ix_notifications_job (job_id)`
- `CONSTRAINT fk_notification_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_notification_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_notifications_message CHECK (CHAR_LENGTH(TRIM(message)) > 0)`

### disputes

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `raised_by` | `INT UNSIGNED NOT NULL` |
| `reason` | `TEXT NOT NULL` |
| `response` | `TEXT NULL` |
| `admin_id` | `INT UNSIGNED NULL DEFAULT NULL` |
| `status` | `ENUM('open','resolved') NOT NULL DEFAULT 'open'` |
| `decision` | `ENUM('refund_client','pay_freelancer') NULL DEFAULT NULL` |
| `decision_note` | `TEXT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `resolved_at` | `DATETIME NULL DEFAULT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_disputes_job UNIQUE (job_id)`
- `KEY ix_disputes_status_created (status, created_at)`
- `KEY ix_disputes_raiser (raised_by)`
- `KEY ix_disputes_admin (admin_id)`
- `CONSTRAINT fk_dispute_escrow_job FOREIGN KEY (job_id) REFERENCES escrows (job_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_dispute_raiser FOREIGN KEY (raised_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_dispute_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_disputes_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)`
- `CONSTRAINT ck_disputes_resolution CHECK ( (status = 'open' AND decision IS NULL AND decision_note IS NULL AND resolved_at IS NULL) OR (status = 'resolved' AND decision IS NOT NULL AND admin_id IS NOT NULL AND decision_note IS NOT NULL AND CHAR_LENGTH(TRIM(decision_note)) > 0 AND resolved_at IS NOT NULL) )`

### cancellation_requests

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `requested_by` | `INT UNSIGNED NOT NULL` |
| `reason` | `TEXT NOT NULL` |
| `status` | `ENUM('pending','accepted','rejected','superseded') NOT NULL DEFAULT 'pending'` |
| `responded_by` | `INT UNSIGNED NULL DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `responded_at` | `DATETIME NULL DEFAULT NULL` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_cancellation_job UNIQUE (job_id)`
- `KEY ix_cancellation_requester (requested_by)`
- `KEY ix_cancellation_responder (responded_by)`
- `CONSTRAINT fk_cancellation_escrow_job FOREIGN KEY (job_id) REFERENCES escrows (job_id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_cancellation_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_cancellation_responder FOREIGN KEY (responded_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_cancellation_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)`
- `CONSTRAINT ck_cancellation_response CHECK ( (status IN ('pending','superseded') AND responded_by IS NULL AND responded_at IS NULL) OR (status IN ('accepted','rejected') AND responded_by IS NOT NULL AND responded_by <> requested_by AND responded_at IS NOT NULL) )`

### reviews

| Column | Definition |
|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `job_id` | `INT UNSIGNED NOT NULL` |
| `reviewer_id` | `INT UNSIGNED NOT NULL` |
| `reviewee_id` | `INT UNSIGNED NOT NULL` |
| `rating` | `TINYINT UNSIGNED NOT NULL` |
| `comment` | `TEXT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

Keys, indexes, references and row checks:

- `PRIMARY KEY (id)`
- `CONSTRAINT uq_reviews_job_reviewer UNIQUE (job_id, reviewer_id)`
- `KEY ix_reviews_reviewee_created (reviewee_id, created_at)`
- `KEY ix_reviews_reviewer (reviewer_id)`
- `CONSTRAINT fk_review_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT fk_review_reviewee FOREIGN KEY (reviewee_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT`
- `CONSTRAINT ck_reviews_rating CHECK (rating BETWEEN 1 AND 5)`
- `CONSTRAINT ck_reviews_parties CHECK (reviewer_id <> reviewee_id)`

## Relationships and enforcement boundaries

- users -> optional freelancer_profiles or client_profiles: one-to-zero-or-one for each profile table. The service must ensure exactly the profile matching the fixed role; FKs alone cannot check the role enum or prevent both profile types. Admin has neither marketplace profile nor wallet.
- freelancer_profiles <-> skills through user_skills; jobs <-> skills through job_skills. Composite PKs make them mathematical sets. Skills are curated, trimmed, case-insensitively unique; IDs, not names, are compared.
- client_profiles -> jobs -> bids; freelancer_profiles -> bids. Jobs.awarded_bid_id is the canonical winner selection, with a same-job composite FK. It is NULL before selection. The extra unique pairs support explicit composite references even though id alone is already unique.
- jobs -> zero or one escrow; escrow (job_id,bid_id) must match jobs (id,awarded_bid_id). Thus it references the selected bid, which itself belongs to the job. After funding, the FK prevents changing/clearing the selection while the escrow remains. Failed funding creates no escrow. No separate pending-escrow row or contract table is necessary.
- bids.status is a display/workflow mirror, NOT the source of winner identity. All remain pending while selection is awaiting funding. A selected but unfunded bid is shown as selected/awaiting funding using jobs.status and awarded_bid_id, never as accepted or permission to start work. On successful funding, selected bid becomes accepted and other pending bids become rejected in the same transaction. The SQL does not independently prevent two accepted status labels: always use the jobs pointer for authorization and reconcile mirrors. No withdrawal/rebid feature in this initial design. On an unfunded award being reopened, retain pending bids and clear selection/time atomically; on draft/open/unfunded cancellation reject every pending bid atomically. Once funded, keep the selected bid accepted as historical award evidence even if the job is later refunded/cancelled/disputed; display that terminal job outcome separately. Reconciliation must find exactly one accepted label matching the canonical pointer for a funded job and no accepted label for an unfunded job. Multiple accepted labels or an accepted label on the wrong bid are invalid service states, even though the current FK/ENUM combination does not reject them.
- jobs -> milestones -> work_submissions; submissions may be final (no milestone) or milestone-specific. Composite FK ensures the milestone belongs to the job. submitted_by references a freelancer profile but the service must verify it is the awarded freelancer. There is no DB conditional unique index for pending submissions; lock the job before checking/inserting to allow at most one submitted attempt per milestone/final context.
- uploaded_files has exactly one portfolio or work-submission target. Portfolio ownership equals portfolio_user_id. The composite submission/owner FK requires file ownership to match the submission author. This enforces structural ownership, not authenticated-request authorization or entitlement to the awarded job.
- chat_messages belongs to one bid, which determines the pair: jobs.client_id and bids.freelancer_id. No broad job-wide group chat and no redundant receiver/job column. Sender participation remains a service check.
- wallets has one row per normal user. wallet_entries belongs to a wallet, actor and optionally escrow. Entries do not have pending/failed statuses: they represent committed successful operations only; failed operations roll back.
- disputes and cancellation_requests reference escrows.job_id, ensuring funded context. Unique job_id means one final dispute and one mutual cancellation request per funded job in this initial scope.
- reviews links a job and two users; service checks these are exactly its client/awarded freelancer and the job completed normally. Unique job/reviewer plus this check permits at most two reviews.
- notifications belongs to its recipient and optionally a job; recipients may only read/mark their own notifications.

## State transitions

Enums constrain values, not legal transitions or actor rights. State transitions below must be implemented server-side under locks.

| Object | Default and legal transitions |
|---|---|
| users | pending_verification -> active on valid email-token consumption; active -> suspended by Admin; suspended -> active only if verified, or pending_verification if not. Email change invalidates verification/tokens before reactivation. No ordinary role change. |
| jobs | draft -> open; open -> awarded on client selection; awarded -> in_progress only on successful funding. Failure remains awarded with no debit. Unfunded awarded -> open clears selection; draft/open/unfunded awarded -> cancelled by owner. in_progress -> under_review on final submission; under_review -> in_progress on revisions; under_review -> completed on full payout. Funded active -> disputed on escalation; disputed -> closed_disputed on full admin payout/refund. Funded active -> cancelled only on accepted mutual cancellation and refund. Terminal jobs do not reopen. |
| bids | pending while job open or selected/unfunded; selected pending -> accepted only on funding, losing pending -> rejected; unfunded cancellation rejects all. Accepted/rejected mirror states are final once funded. |
| milestones | pending -> in_progress -> submitted; submitted -> approved or revisions_requested; revisions_requested -> submitted via a NEW submission attempt. Approved stays approved. |
| work_submissions | draft -> submitted -> approved or revisions_requested. Draft allows attaching actual files before submission. Each retry is a NEW draft/attempt. Preserve submitted contents, attachment bindings and prior feedback; do not reset old attempts to draft. |
| uploaded_files | pending -> active after validation and successful storage, or quarantined/deleted; active -> quarantined/deleted only under evidence-preservation policy; quarantined -> active/deleted after authorized checking. deleted is terminal. Do not delete submitted evidence during active work/disputes; retain metadata after physical purge. |
| escrows | Created locked only upon funding. locked -> released OR refunded once, never back. No pending escrow, partial amount, or second settlement. |
| disputes | open -> resolved with admin, explicit refund_client/pay_freelancer decision, non-empty note and time, committed with settlement. No reopening. |
| cancellation_requests | pending -> accepted/rejected by counterpart; pending -> superseded when either party escalates a dispute. superseded is an automatic system closure, with no consenting responder. One request per job; rejection can lead to dispute. |
| reviews | No status: created after ordinary completion; not editable/deletable in the initial workflow. |
| notifications | Unread when read_at is NULL; recipient marks read once. |
| email_verification_tokens | No status enum: live when unused, unrevoked and unexpired. Live -> used, revoked, or expired; terminal, never reusable. |
| wallets / wallet_entries | No lifecycle status. Wallet balance is an accounting summary; entries are append-only committed events. |

The client defines at least one milestone before funding. Lock agreed scope, budget, selected bid amount/delivery_days and milestone definitions when selecting an award. Reopening an unfunded award is the only permitted reselection path. Do not edit a bid while selected. Later funded changes require a future explicitly approved change-request design, not silent edits.

All milestones must be approved before a final submission is sent for review. Approval of individual milestones never pays money. Final revision leaves prior milestone approvals intact; another final submission attempt is required. Deadlines do not automatically release/refund funds. A pending cancellation freezes work review and ordinary settlement; dispute escalation supersedes that request and freezes ordinary processing. No partial settlement.

## Financial invariants and database guarantees

1. One escrow per job and per selected bid (unique keys). The escrow amount snapshots the full selected bid amount; the service checks equality at funding and never changes it later.
2. At most one escrow_fund entry per escrow (unique escrow_id/entry_type).
3. At most ONE final settlement entry across both escrow_release and escrow_refund: `settlement_escrow_id` is a persistent generated value for either type, unique when non-NULL. It is NULL on credits/funding, permitting other entries. Using a unique pair of escrow/type alone would wrongly allow both a release AND a refund.
4. Globally unique, server-derived operation_key makes duplicate requests idempotent. Example formats: demo:user:<id>, escrow:<id>:fund, escrow:<id>:settle. Do not accept arbitrary fresh keys from the browser as a bypass. After authenticating and locking the job, look for an already committed operation before rejecting a legitimate retry solely because the job is now terminal. Compare escrow/job, wallet recipient, entry_type, signed exact amount, source and authorized actor against the intended operation. Return the existing result only for an exact authorized replay; a refund must never be reported as a successful payout (or vice versa). On any unexpected duplicate-key exception, roll back the WHOLE attempted transaction, then re-read committed state in a fresh transaction. A collision alone is not proof of success; never keep a balance update after a failed ledger insert.
5. One demo credit per wallet is enforced by the generated demo_credit_user_id unique marker. Approved: each Client receives RM10,000 simulated credit exactly once. Eligible role, exact amount and controlled issuance/audit are application rules; no credit is issued by the PHP foundation.
6. Signed entries: demo credit >0; client escrow funding <0; release/refund >0. Entry/source/nullability combinations are checked. Full amount, recipient and actor authorization are service checks, not guaranteed by these local checks. Derive the client from the locked job and freelancer from jobs.awarded_bid_id -> bids.freelancer_id; derive the amount from locked escrow, with equality to the frozen selected bid checked at funding. Funding debits only the client wallet by -amount; payout credits only the winning freelancer by +amount; refund credits only the client by +amount. Neither wallet IDs nor amounts from request fields override these values.
7. Two triggers unconditionally SIGNAL on wallet_entries UPDATE and DELETE. No DELIMITER is needed because each trigger has one SIGNAL statement. Do not use REPLACE or ON DUPLICATE KEY UPDATE for ledger retries. These guards do not defeat a privileged account that drops triggers/tables or truncates the ledger. Future app credentials must lack DDL/TRIGGER privileges and UPDATE/DELETE on entries; do not run the app as root.
8. In each committed state: wallet.balance = COALESCE(SUM(its amount_delta),0). Sum of normal wallet balances + sum of locked escrow amounts = sum of demo-credit amounts (empty sums treated as zero), assuming the no-fee/no-withdrawal design. Global equality alone cannot detect a wrong recipient or equal-and-opposite errors. Reconcile every escrow: exactly one funding entry of -escrow.amount in jobs.client_id wallet; locked means no settlement; released means exactly one +escrow.amount escrow_release in the selected bid freelancer wallet; refunded means exactly one +escrow.amount escrow_refund in the client wallet. Its ledger source must match normal approval, accepted mutual cancellation, or resolved Admin decision. Check bid.amount = escrow.amount, terminal timestamps, and matching job/dispute/cancellation outcomes as well. Check these in later reconciliation tests. Wallet.balance is intentionally a stored summary; escrow.amount intentionally preserves agreed terms. Both need transactional maintenance.
9. Database uniqueness prevents duplicate ledger settlements, but it does NOT automatically change balances, escrow/job status, or prove that funding happened. No triggers secretly move balances. All related updates must commit together as below.
10. Apply monetary bounds before arithmetic so credits do not overflow DECIMAL(10,2). No amounts >99,999,999.99 RM. Input uses at most two fractional digits; reject excess precision rather than relying on server rounding.

## Transaction boundaries and ownership

All changes use PDO prepared statements. Normal accounts must be active and verified before bids, publishing, funding, messages or work operations. The logged-in ID is taken from the session, never a trusted form field. Validate role AND object ownership. Use CSRF protection, secure session handling, output encoding, and rate limits for auth/email/upload requests. Cross-table rules below require application code and tests; importing this schema alone is not a working escrow implementation.

Use explicit InnoDB transactions and SELECT ... FOR UPDATE. For job operations: lock jobs first, then required bid/escrow/workflow records, then wallet rows in ascending user_id order. Every path uses this order; bounded deadlock retries rerun the complete transaction. Never hold locks while waiting for SMTP or uploading/scanning file bytes. Persist notifications with the business transaction; deliver browser updates only after commit.

| Operation | Atomic writes and checks |
|---|---|
| Register (10A with 10B-A integration) | Validate fixed public client/freelancer role; create only the pending_verification user (Argon2id hash, NULL email_verified_at) and exactly matching profile in one transaction. After its owned commit, 10B-A attempts verification issuance in a separate short transaction, then an in-memory capture outside all transactions. Failure leaves a recoverable pending account. No wallet, credit, external SMTP or authenticated session is created. Admin remains separately provisioned. Normalize email and handle the UNIQUE constraint race safely. See email-verification.md. |
| Verify email (10B-A) | Explicit CSRF-protected POST only. Use a public selector and SHA-256 hash bound to user/current email/random secret. Lock user first, then matching token; check eligibility, hash, exact 30-minute expiry, unused/unrevoked and ownership. Mark used, revoke other outstanding unused tokens and set email_verified_at/active together ONLY for eligible pending Client/Freelancer accounts. Leave active, suspended and Admin accounts unchanged. No auto-login/financial side effects. Verification proves mailbox control, not permission to lift suspension. |
| Resend/change email | Lock user then tokens, invalidate prior live tokens, create new hash and expiration. Email change clears email_verified_at and changes active to pending_verification; pending_verification remains pending_verification and suspended MUST remain suspended. Re-read status under the user lock; never copy a stale session status back into the row. Send only after commit; token consumption racing resend uses the same locks. |
| Bid | Lock open job; check active freelancer, no existing bid and valid price/duration. Insert pending bid and client notification. Duplicate constraint handles concurrency. No self-bidding; do not use role changes to bypass this. |
| Select award | Lock open job and selected pending bid; check owning active client and same-job bidder eligibility; set awarded_bid_id, awarded_at and status awarded. Notify selection. No wallet debit, no escrow, no right to start work yet. Losers remain pending until funding. |
| Withdraw unfunded award | Lock awarded job; confirm no escrow; clear pointer/time and return open, or cancel with closed_at. Notify parties. Only owner; no funded re-award. |
| Fund | Lock awarded job/bid, verify owner/price/milestones and no escrow; lock client wallet, verify sufficient balance. Insert locked escrow, debit exact amount, append negative escrow_fund entry, mark selected bid accepted/others rejected, change job to in_progress and notify. Roll back ALL if any check/insert fails. Failed funding leaves awarded selection unchanged. |
| Submit work | Lock in_progress job/context; verify winning freelancer, no pending cancellation/dispute and no already submitted attempt in that context. Verify owned active attachments, usable link or uploaded file, and all milestones approved for final submission. Freeze draft contents/files, set submitted_at/status, update milestone or job under_review, notify owner. |
| Review milestone | Lock job, milestone, current submitted attempt. Only owning client, no dispute/cancellation. Approve with reviewer/time and milestone approved_at; or store required feedback and revisions_requested states. No money moves. |
| Request final revisions | Lock under_review job and final submitted attempt; only owner. Store reviewer/time/feedback and revisions_requested, return job to in_progress. Preserve old attempt. |
| Approve/pay | Lock under_review job, final submitted attempt, locked escrow, freelancer wallet. Verify owner, all milestones approved, no dispute/cancellation, fund ledger exists and matches escrow. Credit exact amount, append unique escrow_release, set escrow released/settled_at, approve final submission, mark job completed/completed_at/closed_at, notify. Duplicate settlement rolls back all writes. |
| Request cancellation | Lock job in exactly in_progress or under_review and its locked escrow; require no dispute and no existing request. Either participant may request; insert one pending request. Freeze work approvals/ordinary settlement until counterpart response. |
| Accept cancellation | Lock job/request/locked escrow/client wallet; require job in in_progress or under_review, request pending, and no open dispute. Only the OTHER job participant may accept. Rejection requires the same pending/request/participant checks but does not touch money. Full refund entry + client balance credit + request accepted/responder/time + escrow refunded + job cancelled/closed_at + notices commit together. Reject stores responder/time and unfreezes normal workflow. |
| Escalate dispute | Lock job in exactly in_progress or under_review and its locked escrow; require no existing dispute; only its client/winning freelancer. Supersede pending cancellation, insert open dispute, set job disputed, notify Admin/parties. Chat remains available. Losing bidders cannot escalate. |
| Resolve dispute | Lock disputed job/open dispute/locked escrow/target wallet; require no pending or accepted cancellation (a pending request must already be superseded by escalation). Only an active, verified Admin. Choose full payout to winning freelancer OR full refund to client; exact amount, unique settlement, wallet credit, escrow terminal status/time, admin decision/note/time, job closed_disputed/closed_at, notices commit together. Never mark ordinary completed or enable reviews. |
| Review | Lock completed job; require reviewer/reviewee equal its two participants, opposite parties, normal completion (not disputed/cancelled). Insert once using UNIQUE(job_id,reviewer_id), notify reviewee. |
| Chat | Resolve bid pair; only client owner or THAT bidder may send/read. After funding, losing conversations are read-only for their original pair; winner chat continues through review/dispute, terminal conversations read-only. Admin can read only relevant authorized dispute evidence. Never return all job chats to every bidder. |

## File storage and access rules

Metadata is not a file store: no BLOBs or public filesystem paths. storage_key is 32 random bytes encoded as 64 lower-case hex characters, independent of the original filename. sha256 is the content digest; identical content is allowed under different keys. Never use original_name as a path. Resolve the key beneath a configured private root outside Apache's served directories and deny traversal. The exact root needs user approval before filesystem changes outside GigHub. No directory or upload handler is created here.

Accept uploaded bytes only via an authenticated owner operation. Check extension allowlist, content-detected MIME, positive size and configured limit; reject executable PHP/HTML/script content and archives initially unless separately approved. Do not trust the browser MIME. The SQL size type supplies a representational ceiling, not the approved security limit.

Portfolio uploads target the current freelancer's profile. Submission uploads target a draft submission by the current awarded freelancer; both composite FK and live authorization checks apply. A client may review/download only the awarded job's submitted files, never drafts/another bid's work. Portfolio downloads may be shown to logged-in marketplace users through an authorized PHP endpoint; never expose storage URLs. Admin reads submitted evidence only under authorized moderation/dispute access. Pending/quarantined/deleted files are not ordinarily downloadable. Use attachment disposition and nosniff on downloads; filenames are safely encoded.

The filesystem and MariaDB cannot share a transaction: stage/validate/store bytes outside the transaction; then lock the job and draft submission (or portfolio owner record), recheck current session ownership/eligibility and draft state, and insert pending metadata bound to that target. Activation, attachment changes/removal and final submission must acquire the same job/submission locks and recheck the target is still draft. Activate only when the object is present and verified. A pre-upload authorization check is not sufficient: a draft may have been submitted, cancelled or disputed while bytes were transferring. Never attach/activate additional bytes to a frozen submitted attempt; use a new authorized attempt instead. On failure compensate by deleting only the newly staged owned file or marking metadata unavailable. Background/manual reconciliation handles orphan bytes/missing files. Do not mark work submitted until attachments are active. Retain submitted evidence/bindings through disputes; do not let owners rewrite bytes under an existing key or reparent metadata after submission. File cleanup/retention and malware-scanning policy remain decisions, not functionality provided by SQL.

## Jaccard, reviews, receipts and notifications

Keep distinct skill IDs in junctions. Score = |job skills intersect user skills| / |job skills union user skills|; empty union => 0. Report threshold is 0.20, compare BEFORE display rounding. Open jobs only; order by score descending, created_at descending, id descending. Batch-fetch skills, avoid one query per job. Recommendations do not grant/deny bidding eligibility. No persisted score table. Extra freelancer skills can lower Jaccard: document that mathematical limitation.

Calculate ratings/counts from reviews, not mutable profile totals. Receipts and earnings come from immutable entries joined to escrow -> job -> selected bid/participants; entry source/actor/time identifies normal versus admin/cancellation settlement. Notifications are in-app database rows only, read by their recipient. SMTP verification is real email, not a notification row or pretend-success interface.

## Validation and later import gate

Offline checks performed without opening a database connection: exact 20-table inventory; balanced quoted expressions/parentheses in table definitions; PK presence; all status defaults; database-wide distinct constraint symbols; all FK source/target columns, matching types and explicit index coverage; two ledger triggers; generated unique settlement marker; no database creation, seed DML, grants or destructive SQL.

The initial offline checks were followed by the approved isolated runtime validation recorded below. Server acceptance and tested constraint behaviour are confirmed. Application workflows, authorization and concurrency remain untested. No dependency was installed.

No CREATE DATABASE/USE statement is included. No IF NOT EXISTS masks collisions. The deferred jobs FK deliberately resolves the table-creation cycle. DDL auto-commits, so a failed future import may leave a partial schema: stop on first error and review before retrying. Trigger creation needs the import account's TRIGGER privilege; runtime app account must not have it. Do not run schema.sql against existing work. User approval is required before any database creation/import, grants, filesystem storage setup, or SMTP configuration work.

Technical references checked for this draft:
- MariaDB CREATE TABLE and table-level FK syntax: https://mariadb.com/docs/server/server-usage/tables/create-table
- Generated PERSISTENT columns and unique indexes: https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns
- Triggers and SIGNAL: https://mariadb.com/docs/server/server-usage/triggers-events/triggers/trigger-overview
- InnoDB FOR UPDATE within explicit transactions: https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update

## Review clarifications: compatibility and cross-table states

Historical offline review on 2026-10-30 identified no definite syntax issue. Subsequent approved runtime validation confirmed all 23 schema statements were accepted by MariaDB 10.4.32; see the runtime record below.

Creation order: create jobs without its awarded-bid FK, then bids with job_id -> jobs.id, then ALTER jobs to add the same-job composite FK, then create escrows after both parent structures exist. All parent keys and matching unsigned child types are explicitly indexed. During later data operations insert job with NULL award, insert its bid, select that bid by updating the job, and create escrow only during successful funding. MariaDB checks these FKs immediately; the later ALTER is not a DEFERRABLE constraint and no foreign_key_checks bypass is needed. Funded escrow's reference prevents clearing/replacing its selected job/bid pair. Cyclic RESTRICT references intentionally make historical deletion difficult; use workflow states, not blanket FK disabling.

PERSISTENT generated columns use deterministic integer CASE expressions and nullable UNIQUE indexes, not partial-index syntax. Multiple NULL markers are allowed while an escrow ID has only one non-NULL settlement marker. The markers themselves are not FK columns or targets. SQL uses one event per trigger (separate UPDATE and DELETE) with a single SIGNAL SQLSTATE '45000' statement, so BEGIN/END and DELIMITER are unnecessary. Triggers do not reference NEW/OLD generated columns. Subsequent runtime validation confirmed this syntax, CHECK enforcement and generated-index/trigger operation on MariaDB 10.4.32. Do not substitute multi-event triggers or atomic-DDL features introduced in newer releases.

The schema deliberately leaves cross-row state validation to the future service, not broad additional triggers. Under the same job lock, enforce this consistency matrix:

| Committed outcome | Required cross-table combination |
|---|---|
| Award awaiting funding | jobs.awarded, selected same-job pending bid, no escrow or funding entry. Failed funding changes none of these. |
| Funded active | jobs.in_progress/under_review, selected bid accepted, other bids rejected, escrow locked and exactly one correct client debit. |
| Ordinary completion | jobs.completed, final submission approved, every milestone approved, escrow released, exactly one correct freelancer credit, no dispute or pending/accepted cancellation. |
| Accepted funded cancellation | jobs.cancelled, request accepted with opposite participant responder, escrow refunded, exactly one correct client credit, no dispute. |
| Dispute pending | jobs.disputed, dispute open, escrow locked, no settlement, cancellation absent/rejected/superseded (never pending/accepted). |
| Dispute resolved | jobs.closed_disputed, dispute resolved with matching Admin decision, escrow and unique settlement matching pay_freelancer or refund_client, no pending/accepted cancellation. |

For terminal cancellation/dispute, historical approved or submitted work attempts remain evidence; do not fabricate client approval or delete submissions. Do not create a new cancellation on a disputed job, settle a superseded request, or reopen a resolved dispute. One request and one formal dispute per funded job are approved limits. Suspended parties cannot perform normal marketplace actions; an active verified Admin may resolve their existing financial disputes. Withdrawal/timeouts and retention remain implementation choices.

`identity_confirmed_at` was removed because no real identity-verification workflow is specified. Email verification confirms only mailbox control and must never be described as identity/KYC verification. Adding identity verification later requires a separately approved workflow, privacy policy and schema design.


## Confirmed runtime validation and approved policies

The unchanged schema was imported once into a newly created, verified-empty gighub_schema_test on MariaDB 10.4.32. Existing database names were checked first. All 23 statements succeeded: 20 tables, one deferred FK ALTER and two triggers. information_schema, SHOW CREATE TABLE and SHOW CREATE TRIGGER verified 20 InnoDB/utf8mb4_unicode_ci tables, 20 primary keys, 37 foreign keys, 19 UNIQUE constraints, 43 CHECK constraints, 68 indexes, two ledger triggers and two generated columns.

The corrected runner recorded 65 PASS and one default-mode ENUM failure; passes included 46 expected rejections. Tested protections included duplicate accounts/skills/bids/escrows/reviews/disputes/cancellation, FK ownership and same-job references, money/rating ranges, milestone/submission checks, upload metadata ownership, one-time credit and settlement uniqueness, and immutable ledger triggers. Synthetic wallet/ledger totals reconciled. These fixtures prove row constraints, not complete legal business workflows.

Installed global sql_mode was NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION. Invalid users.role was accepted as an empty ENUM with warning 1265: Data truncated for column 'role' at row 1. STRICT_TRANS_TABLES on the test session rejected the same invalid value. Global settings were unchanged. Every future application connection MUST initialize strict SQL mode, UTC and utf8mb4. The factory prepares these settings but no successful application PDO connection is configured or tested.

All synthetic rows were rolled back and all 20 tables verified empty. Auto-increment counters may have advanced. The isolated schema is retained: do not reset/re-import it or select it as the application database. No real accounts, permanent credits, real payment details, emails or upload files were created. Unchanged source SQL SHA-256: 29515ad151c5171642ac9a69eae44e27b635f27f55753bde511dde4d0d346286.

Approved product policies:
- Real SMTP verification, 30-minute token expiry, 60-second resend cooldown, hashed single-use tokens. Actual token use/timing/delivery requires PHP implementation.
- Each Client receives RM10,000 simulated credit exactly once. No real payment, bank transfer, card processing or withdrawal. SQL uniqueness does not itself enforce Client role or exact credit amount.
- Bids may be above or below advertised budget; full escrow amount comes from the selected bid.
- Portfolio uploads: PDF/JPG/PNG/WEBP, maximum 5 MB. Deliverables: same types, maximum 10 MB. ZIP prohibited. Store bytes outside public htdocs; never serve direct storage URLs. Exact MB-to-bytes interpretation, detection, quota, retention and storage path remain implementation choices.
- Each funded job allows at most one mutual cancellation request and one formal dispute; funded cancellation requires counterpart consent.
- Suspended accounts cannot perform normal marketplace actions. An active verified Admin may resolve existing financial disputes involving them without reactivating either party.
- Retain fixed normal roles/separate Admin, separate award/funding, full final payout, submission history, private bid chat, normal-completion reviews, in-app notifications and Jaccard.

NOT TESTED by SQL validation: SMTP, token consumption/expiry/cooldown, filesystem/type/size checks, request authorization, suspended-account guards, complete state transitions, exact-recipient service implementation, crashes or concurrent requests. These require feature-level PHP tests. This is an accepted data foundation, not a working marketplace.

Prompt 07 prepares only documentation and PHP structure: no database access, re-import, SMTP, upload-directory creation, accounts or wallet credits. See php-foundation.md for lazy PDO, private-file protection, optional ignored local configuration and the local-only non-database health endpoint.
