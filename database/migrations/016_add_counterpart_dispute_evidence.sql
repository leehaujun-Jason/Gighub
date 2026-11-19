-- Apply once after backup. Existing images remain initiator evidence; no historical rows are rewritten.
ALTER TABLE dispute_evidence_images
    ADD COLUMN evidence_side ENUM('initiator','counterpart') NOT NULL DEFAULT 'initiator',
    ADD COLUMN uploader_id INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_dispute_evidence_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_dispute_evidence_uploader CHECK (evidence_side='initiator' OR uploader_id IS NOT NULL),
    DROP INDEX uq_dispute_evidence_position,
    ADD UNIQUE KEY uq_dispute_evidence_position (dispute_id,evidence_side,display_position);
