-- GigHub initial schema, MariaDB 10.4.32. REVIEW ONLY: not executed.
-- Import only into an approved EMPTY database selected by the operator.
-- No CREATE DATABASE, USE, DROP, seed data, grants, or configuration changes.
-- DDL implicitly commits: this is not a rollback-safe migration or rerunnable script.
-- Application connections must use strict SQL mode, UTC, and utf8mb4.
-- See docs/database-design.md for cross-row and ownership requirements.

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    role ENUM('client','freelancer','admin') NOT NULL,
    status ENUM('pending_verification','active','suspended') NOT NULL DEFAULT 'pending_verification',
    email_verified_at DATETIME NULL DEFAULT NULL,
    onboarding_pending TINYINT UNSIGNED NOT NULL DEFAULT 0,
    avatar_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_users_email UNIQUE (email),
    KEY ix_users_role_status (role, status),
    CONSTRAINT ck_users_email CHECK (CHAR_LENGTH(TRIM(email)) > 0),
    CONSTRAINT ck_users_name CHECK (CHAR_LENGTH(TRIM(display_name)) > 0),
    CONSTRAINT ck_users_active_verified CHECK (status <> 'active' OR email_verified_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_profiles (
    user_id INT UNSIGNED NOT NULL,
    bio TEXT NULL,
    portfolio_description TEXT NULL,
    portfolio_url VARCHAR(2048) NULL DEFAULT NULL,
    headline VARCHAR(150) NULL DEFAULT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_freelancer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_education (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    institution VARCHAR(150) NOT NULL,
    qualification VARCHAR(150) NOT NULL,
    field_of_study VARCHAR(150) NULL,
    start_year SMALLINT UNSIGNED NULL,
    end_year SMALLINT UNSIGNED NULL,
    KEY ix_education_owner (freelancer_id, start_year, id),
    CONSTRAINT fk_education_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT,
    CONSTRAINT ck_education_years CHECK (end_year >= start_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_employment (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    job_title VARCHAR(150) NOT NULL,
    company VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    current_job TINYINT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NULL,
    KEY ix_employment_owner (freelancer_id, start_date, id),
    CONSTRAINT fk_employment_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT,
    CONSTRAINT ck_employment_dates CHECK ((end_date IS NULL OR end_date >= start_date) AND current_job IN (0,1) AND (current_job = 0 OR end_date IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_languages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    language VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proficiency ENUM('Basic','Conversational','Fluent','Native') NOT NULL,
    UNIQUE KEY uq_freelancer_language (freelancer_id, language),
    CONSTRAINT fk_language_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_portfolio (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    project_title VARCHAR(150) NOT NULL,
    project_url VARCHAR(2048) NOT NULL,
    description VARCHAR(500) NOT NULL,
    KEY ix_portfolio_owner (freelancer_id, id),
    CONSTRAINT fk_portfolio_entry_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_profiles (
    user_id INT UNSIGNED NOT NULL,
    company_name VARCHAR(100) NULL DEFAULT NULL,
    account_type ENUM('individual','company') NULL DEFAULT NULL,
    state VARCHAR(50) NULL DEFAULT NULL,
    city VARCHAR(100) NULL DEFAULT NULL,
    bio TEXT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_client_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_verification_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_email_token_hash UNIQUE (token_hash),
    KEY ix_email_tokens_user_expiry (user_id, expires_at),
    CONSTRAINT fk_email_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_email_token_hash CHECK (token_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT ck_email_token_expiry CHECK (expires_at > created_at),
    CONSTRAINT ck_email_token_consumed CHECK (used_at IS NULL OR revoked_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (token_hash),
    KEY ix_password_resets_user_issued (user_id, issued_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skills (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_skills_name UNIQUE (name),
    CONSTRAINT ck_skills_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_skills (
    freelancer_id INT UNSIGNED NOT NULL,
    skill_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (freelancer_id, skill_id),
    KEY ix_user_skills_skill (skill_id, freelancer_id),
    CONSTRAINT fk_user_skill_freelancer FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_user_skill_skill FOREIGN KEY (skill_id) REFERENCES skills (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    awarded_bid_id INT UNSIGNED NULL DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    budget DECIMAL(10,2) NOT NULL,
    deadline DATE NOT NULL,
    status ENUM('draft','open','awarded','in_progress','under_review','disputed','completed','cancelled','closed_disputed') NOT NULL DEFAULT 'draft',
    awarded_at DATETIME NULL DEFAULT NULL,
    completed_at DATETIME NULL DEFAULT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_jobs_award_pair UNIQUE (id, awarded_bid_id),
    KEY ix_jobs_client_status (client_id, status),
    KEY ix_jobs_status_created (status, created_at, id),
    CONSTRAINT fk_job_client FOREIGN KEY (client_id) REFERENCES client_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_jobs_budget CHECK (budget > 0),
    CONSTRAINT ck_jobs_title CHECK (CHAR_LENGTH(TRIM(title)) > 0),
    CONSTRAINT ck_jobs_description CHECK (CHAR_LENGTH(TRIM(description)) > 0),
    CONSTRAINT ck_jobs_award CHECK (
        (status IN ('draft','open') AND awarded_bid_id IS NULL)
        OR (status IN ('awarded','in_progress','under_review','disputed','completed','closed_disputed') AND awarded_bid_id IS NOT NULL)
        OR status = 'cancelled'
    ),
    CONSTRAINT ck_jobs_award_time CHECK (
        (awarded_bid_id IS NULL AND awarded_at IS NULL)
        OR (awarded_bid_id IS NOT NULL AND awarded_at IS NOT NULL)
    ),
    CONSTRAINT ck_jobs_completion CHECK (status <> 'completed' OR completed_at IS NOT NULL),
    CONSTRAINT ck_jobs_closure CHECK (status NOT IN ('completed','cancelled','closed_disputed') OR closed_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_skills (
    job_id INT UNSIGNED NOT NULL,
    skill_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (job_id, skill_id),
    KEY ix_job_skills_skill (skill_id, job_id),
    CONSTRAINT fk_job_skill_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_job_skill_skill FOREIGN KEY (skill_id) REFERENCES skills (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_requirement_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_job_requirement_storage (storage_key),
    KEY ix_job_requirement_job (job_id, id),
    CONSTRAINT fk_job_requirement_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bids (
    proposal_document_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    proposal_document_name VARCHAR(255) NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    freelancer_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    delivery_days SMALLINT UNSIGNED NOT NULL,
    cover_letter TEXT NOT NULL,
    status ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_bids_job_freelancer UNIQUE (job_id, freelancer_id),
    CONSTRAINT uq_bids_job_id UNIQUE (job_id, id),
    KEY ix_bids_freelancer_created (freelancer_id, created_at),
    CONSTRAINT fk_bid_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bid_freelancer FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_bids_amount CHECK (amount > 0),
    CONSTRAINT ck_bids_delivery CHECK (delivery_days > 0),
    CONSTRAINT ck_bids_letter CHECK (CHAR_LENGTH(TRIM(cover_letter)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The award FK is deferred until both sides of the job/bid relationship exist.
ALTER TABLE jobs ADD CONSTRAINT fk_job_awarded_bid
    FOREIGN KEY (id, awarded_bid_id) REFERENCES bids (job_id, id)
    ON DELETE RESTRICT ON UPDATE RESTRICT;

CREATE TABLE milestones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    due_date DATE NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    status ENUM('pending','in_progress','submitted','revisions_requested','approved') NOT NULL DEFAULT 'pending',
    approved_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_milestones_job_id UNIQUE (job_id, id),
    CONSTRAINT uq_milestones_position UNIQUE (job_id, position),
    CONSTRAINT fk_milestone_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_milestones_title CHECK (CHAR_LENGTH(TRIM(title)) > 0),
    CONSTRAINT ck_milestones_position CHECK (position > 0),
    CONSTRAINT ck_milestones_approval CHECK (
        (status = 'approved' AND approved_at IS NOT NULL)
        OR (status <> 'approved' AND approved_at IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_submissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    milestone_id INT UNSIGNED NULL DEFAULT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submission_type ENUM('milestone','final') NOT NULL,
    notes TEXT NOT NULL,
    deliverable_url VARCHAR(2048) NULL DEFAULT NULL,
    status ENUM('draft','submitted','approved','revisions_requested') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL DEFAULT NULL,
    reviewed_by INT UNSIGNED NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    feedback TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_submission_author UNIQUE (id, submitted_by),
    KEY ix_submissions_job_created (job_id, created_at, id),
    KEY ix_submissions_job_milestone (job_id, milestone_id),
    KEY ix_submissions_author (submitted_by),
    KEY ix_submissions_reviewer (reviewed_by),
    CONSTRAINT fk_submission_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_submission_milestone FOREIGN KEY (job_id, milestone_id) REFERENCES milestones (job_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_submission_author FOREIGN KEY (submitted_by) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_submission_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_submissions_type CHECK (
        (submission_type = 'milestone' AND milestone_id IS NOT NULL)
        OR (submission_type = 'final' AND milestone_id IS NULL)
    ),
    CONSTRAINT ck_submissions_notes CHECK (CHAR_LENGTH(TRIM(notes)) > 0),
    CONSTRAINT ck_submissions_review CHECK (
        (status = 'draft' AND submitted_at IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL)
        OR (status = 'submitted' AND submitted_at IS NOT NULL AND reviewed_by IS NULL AND reviewed_at IS NULL)
        OR (status IN ('approved','revisions_requested') AND submitted_at IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
    ),
    CONSTRAINT ck_submissions_revision CHECK (status <> 'revisions_requested' OR (feedback IS NOT NULL AND CHAR_LENGTH(TRIM(feedback)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_submission_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_link_position (submission_id, position),
    UNIQUE KEY uq_submission_link_url (submission_id, url),
    CONSTRAINT fk_submission_link_attempt FOREIGN KEY (submission_id) REFERENCES work_submissions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_submission_link_position CHECK (position > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE uploaded_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id INT UNSIGNED NOT NULL,
    purpose ENUM('portfolio','work_submission') NOT NULL,
    portfolio_user_id INT UNSIGNED NULL DEFAULT NULL,
    work_submission_id INT UNSIGNED NULL DEFAULT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(150) NULL DEFAULT NULL,
    description TEXT NULL,
    status ENUM('pending','active','quarantined','deleted') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_uploaded_storage_key UNIQUE (storage_key),
    KEY ix_uploaded_owner_status (owner_user_id, status),
    KEY ix_uploaded_portfolio (portfolio_user_id),
    KEY ix_uploaded_submission_owner (work_submission_id, owner_user_id),
    CONSTRAINT fk_uploaded_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uploaded_portfolio FOREIGN KEY (portfolio_user_id) REFERENCES freelancer_profiles (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_uploaded_submission_owner FOREIGN KEY (work_submission_id, owner_user_id) REFERENCES work_submissions (id, submitted_by) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_uploaded_target CHECK (
        (purpose = 'portfolio' AND portfolio_user_id IS NOT NULL AND portfolio_user_id = owner_user_id AND work_submission_id IS NULL)
        OR (purpose = 'work_submission' AND portfolio_user_id IS NULL AND work_submission_id IS NOT NULL)
    ),
    CONSTRAINT ck_uploaded_size CHECK (size_bytes > 0),
    CONSTRAINT ck_uploaded_name CHECK (CHAR_LENGTH(TRIM(original_name)) > 0),
    CONSTRAINT ck_uploaded_storage CHECK (storage_key REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT ck_uploaded_hash CHECK (sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT ck_uploaded_deleted CHECK (
        (status = 'deleted' AND deleted_at IS NOT NULL) OR (status <> 'deleted' AND deleted_at IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallets (
    user_id INT UNSIGNED NOT NULL,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_wallet_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE escrows (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    bid_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('locked','released','refunded') NOT NULL DEFAULT 'locked',
    funded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_escrows_job UNIQUE (job_id),
    CONSTRAINT uq_escrows_bid UNIQUE (bid_id),
    KEY ix_escrows_job_bid (job_id, bid_id),
    KEY ix_escrows_status (status),
    CONSTRAINT fk_escrow_selected_bid FOREIGN KEY (job_id, bid_id) REFERENCES jobs (id, awarded_bid_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_escrows_amount CHECK (amount > 0),
    CONSTRAINT ck_escrows_settled CHECK (
        (status = 'locked' AND settled_at IS NULL)
        OR (status IN ('released','refunded') AND settled_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    wallet_user_id INT UNSIGNED NOT NULL,
    escrow_id INT UNSIGNED NULL DEFAULT NULL,
    entry_type ENUM('demo_credit','escrow_fund','escrow_release','escrow_refund') NOT NULL,
    amount_delta DECIMAL(10,2) NOT NULL,
    source ENUM('demo_seed','client_funding','client_approval','mutual_cancellation','admin_decision') NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Indexed generated markers emulate conditional uniqueness without partial indexes.
    settlement_escrow_id INT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN entry_type IN ('escrow_release','escrow_refund') THEN escrow_id ELSE NULL END
    ) PERSISTENT,
    demo_credit_user_id INT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN entry_type = 'demo_credit' THEN wallet_user_id ELSE NULL END
    ) PERSISTENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_wallet_operation UNIQUE (operation_key),
    CONSTRAINT uq_wallet_escrow_type UNIQUE (escrow_id, entry_type),
    CONSTRAINT uq_wallet_settlement UNIQUE (settlement_escrow_id),
    CONSTRAINT uq_wallet_demo_credit UNIQUE (demo_credit_user_id),
    KEY ix_wallet_entries_user_created (wallet_user_id, created_at, id),
    KEY ix_wallet_entries_actor (actor_id),
    CONSTRAINT fk_wallet_entry_wallet FOREIGN KEY (wallet_user_id) REFERENCES wallets (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_wallet_entry_escrow FOREIGN KEY (escrow_id) REFERENCES escrows (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_wallet_entry_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_wallet_operation CHECK (CHAR_LENGTH(TRIM(operation_key)) > 0),
    CONSTRAINT ck_wallet_entry_semantics CHECK (
        (entry_type = 'demo_credit' AND escrow_id IS NULL AND amount_delta > 0 AND source = 'demo_seed')
        OR (entry_type = 'escrow_fund' AND escrow_id IS NOT NULL AND amount_delta < 0 AND source = 'client_funding')
        OR (entry_type = 'escrow_release' AND escrow_id IS NOT NULL AND amount_delta > 0 AND source IN ('client_approval','admin_decision'))
        OR (entry_type = 'escrow_refund' AND escrow_id IS NOT NULL AND amount_delta > 0 AND source IN ('mutual_cancellation','admin_decision'))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attachment_name VARCHAR(255) NULL,
    attachment_image TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_chat_bid_id (bid_id, id),
    KEY ix_chat_sender (sender_id),
    CONSTRAINT fk_chat_bid FOREIGN KEY (bid_id) REFERENCES bids (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_chat_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_chat_content CHECK (
        (attachment_key IS NULL AND attachment_name IS NULL AND attachment_image = 0 AND CHAR_LENGTH(TRIM(body)) > 0)
        OR (attachment_key IS NOT NULL AND attachment_name IS NOT NULL AND attachment_image IN (0,1))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_message_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attachment_name VARCHAR(255) NOT NULL,
    attachment_image TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_chat_attachment_message (message_id, id),
    CONSTRAINT fk_chat_attachment_message FOREIGN KEY (message_id) REFERENCES chat_messages (id) ON DELETE RESTRICT,
    CONSTRAINT ck_chat_attachment_image CHECK (attachment_image IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chat_read_watermarks (
    bid_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bid_id, user_id),
    KEY ix_watermark_user (user_id),
    CONSTRAINT fk_watermark_bid FOREIGN KEY (bid_id) REFERENCES bids (id) ON DELETE CASCADE,
    CONSTRAINT fk_watermark_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED NULL DEFAULT NULL,
    type ENUM('bid_received','award_selected','award_cancelled','escrow_funded','submission_received','revision_requested','milestone_approved','payment_released','payment_refunded','dispute_opened','dispute_resolved','cancellation_requested','cancellation_responded','job_cancelled','review_received','bid_not_selected','investigation_message') NOT NULL,
    investigation_dispute_id INT UNSIGNED NULL,
    investigation_participant_id INT UNSIGNED NULL,
    message VARCHAR(500) NOT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_notifications_recipient_read (recipient_id, read_at, id),
    KEY ix_notifications_job (job_id),
    CONSTRAINT fk_notification_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_notification_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_notifications_message CHECK (CHAR_LENGTH(TRIM(message)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disputes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    raised_by INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    response TEXT NULL,
    admin_id INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    decision ENUM('refund_client','pay_freelancer') NULL DEFAULT NULL,
    decision_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_disputes_job UNIQUE (job_id),
    KEY ix_disputes_status_created (status, created_at),
    KEY ix_disputes_raiser (raised_by),
    KEY ix_disputes_admin (admin_id),
    CONSTRAINT fk_dispute_escrow_job FOREIGN KEY (job_id) REFERENCES escrows (job_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_dispute_raiser FOREIGN KEY (raised_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_dispute_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_disputes_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT ck_disputes_resolution CHECK (
        (status = 'open' AND decision IS NULL AND decision_note IS NULL AND resolved_at IS NULL)
        OR (status = 'resolved' AND decision IS NOT NULL AND admin_id IS NOT NULL AND decision_note IS NOT NULL AND CHAR_LENGTH(TRIM(decision_note)) > 0 AND resolved_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispute_investigation_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_investigation_send (dispute_id, participant_id, sender_id, request_key),
    KEY ix_investigation_history (dispute_id, participant_id, id),
    CONSTRAINT fk_investigation_dispute FOREIGN KEY (dispute_id) REFERENCES disputes (id),
    CONSTRAINT fk_investigation_participant FOREIGN KEY (participant_id) REFERENCES users (id),
    CONSTRAINT fk_investigation_sender FOREIGN KEY (sender_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispute_investigation_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attachment_name VARCHAR(255) NOT NULL,
    attachment_image TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_investigation_attachment_key (attachment_key),
    KEY ix_investigation_attachments (message_id, id),
    CONSTRAINT fk_investigation_attachment_message FOREIGN KEY (message_id) REFERENCES dispute_investigation_messages (id),
    CONSTRAINT ck_investigation_attachment_image CHECK (attachment_image IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispute_investigation_watermarks (
    dispute_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (dispute_id, participant_id, user_id),
    KEY ix_investigation_watermark_viewer (user_id),
    CONSTRAINT fk_investigation_watermark_dispute FOREIGN KEY (dispute_id) REFERENCES disputes (id),
    CONSTRAINT fk_investigation_watermark_participant FOREIGN KEY (participant_id) REFERENCES users (id),
    CONSTRAINT fk_investigation_watermark_viewer FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cancellation_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','accepted','rejected','superseded') NOT NULL DEFAULT 'pending',
    responded_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_cancellation_job UNIQUE (job_id),
    KEY ix_cancellation_requester (requested_by),
    KEY ix_cancellation_responder (responded_by),
    CONSTRAINT fk_cancellation_escrow_job FOREIGN KEY (job_id) REFERENCES escrows (job_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cancellation_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cancellation_responder FOREIGN KEY (responded_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cancellation_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT ck_cancellation_response CHECK (
        (status IN ('pending','superseded') AND responded_by IS NULL AND responded_at IS NULL)
        OR (status IN ('accepted','rejected') AND responded_by IS NOT NULL AND responded_by <> requested_by AND responded_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id INT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    reviewee_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_reviews_job_reviewer UNIQUE (job_id, reviewer_id),
    KEY ix_reviews_reviewee_created (reviewee_id, created_at),
    KEY ix_reviews_reviewer (reviewer_id),
    CONSTRAINT fk_review_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_review_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_review_reviewee FOREIGN KEY (reviewee_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT ck_reviews_parties CHECK (reviewer_id <> reviewee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-statement triggers avoid client-specific DELIMITER commands.
-- They block ordinary UPDATE/DELETE (including REPLACE's delete path).
-- Privileged DDL such as TRUNCATE/DROP is outside this protection: deny it to the app.
CREATE TRIGGER trg_wallet_entries_no_update
BEFORE UPDATE ON wallet_entries FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_entries is append-only';

CREATE TRIGGER trg_wallet_entries_no_delete
BEFORE DELETE ON wallet_entries FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_entries is append-only';
