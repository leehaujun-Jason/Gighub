-- Additive migration; preserves existing disputes and private messages.
CREATE TABLE dispute_evidence_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_id INT UNSIGNED NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_position TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dispute_evidence_key (storage_key),
    UNIQUE KEY uq_dispute_evidence_position (dispute_id, display_position),
    CONSTRAINT ck_dispute_evidence_position CHECK (display_position < 6),
    CONSTRAINT fk_dispute_evidence_dispute FOREIGN KEY (dispute_id) REFERENCES disputes (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
