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
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('bid_received','award_selected','award_cancelled','escrow_funded','submission_received','revision_requested','milestone_approved','payment_released','payment_refunded','dispute_opened','dispute_resolved','cancellation_requested','cancellation_responded','job_cancelled','review_received','bid_not_selected','investigation_message') NOT NULL,
    ADD COLUMN investigation_dispute_id INT UNSIGNED NULL,
    ADD COLUMN investigation_participant_id INT UNSIGNED NULL;
