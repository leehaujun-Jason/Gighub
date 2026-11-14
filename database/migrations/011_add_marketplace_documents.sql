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

ALTER TABLE bids
    ADD COLUMN proposal_document_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN proposal_document_name VARCHAR(255) NULL;

ALTER TABLE notifications MODIFY COLUMN type ENUM('bid_received','award_selected','award_cancelled','escrow_funded','submission_received','revision_requested','milestone_approved','payment_released','payment_refunded','dispute_opened','dispute_resolved','cancellation_requested','cancellation_responded','job_cancelled','review_received','bid_not_selected') NOT NULL;
