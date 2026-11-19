-- Migration 015: additive progressive policy/history; never rewrite wallet ledger rows.
-- Apply once, with financial writers paused, after verified backup and isolated rehearsal.
ALTER TABLE jobs ADD COLUMN financial_policy ENUM('legacy_full_v1','progressive_v1') NOT NULL DEFAULT 'legacy_full_v1';
ALTER TABLE milestones ADD COLUMN payout_basis_points SMALLINT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT ck_milestone_payout_basis CHECK (payout_basis_points IS NULL OR payout_basis_points IN (3000,2000));
ALTER TABLE escrows
    ADD COLUMN financial_policy ENUM('legacy_full_v1','progressive_v1') NOT NULL DEFAULT 'legacy_full_v1',
    ADD COLUMN milestone_count_at_funding TINYINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN released_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN remaining_amount DECIMAL(10,2) GENERATED ALWAYS AS (amount-released_amount-refunded_amount) PERSISTENT;
-- Initialize only newly introduced aggregate metadata from authoritative terminal states.
UPDATE escrows SET released_amount=CASE WHEN status='released' THEN amount ELSE 0 END,
    refunded_amount=CASE WHEN status='refunded' THEN amount ELSE 0 END;
ALTER TABLE escrows DROP CONSTRAINT ck_escrows_settled,
    MODIFY COLUMN status ENUM('locked','released','refunded','settled_mixed') NOT NULL DEFAULT 'locked',
    ADD CONSTRAINT ck_escrows_totals CHECK (released_amount>=0 AND refunded_amount>=0 AND released_amount+refunded_amount<=amount),
    ADD CONSTRAINT ck_escrows_schedule CHECK (financial_policy='legacy_full_v1' OR (milestone_count_at_funding IN (1,2) AND milestone_count_at_funding IS NOT NULL AND amount>=10.00)),
    ADD CONSTRAINT ck_escrows_settled CHECK (
        (financial_policy='legacy_full_v1' AND ((status='locked' AND settled_at IS NULL) OR (status IN ('released','refunded') AND settled_at IS NOT NULL)))
        OR (financial_policy='progressive_v1' AND (
            (status='locked' AND settled_at IS NULL AND remaining_amount>0)
            OR (status='released' AND settled_at IS NOT NULL AND remaining_amount=0 AND refunded_amount=0)
            OR (status='refunded' AND settled_at IS NOT NULL AND remaining_amount=0 AND released_amount=0)
            OR (status='settled_mixed' AND settled_at IS NOT NULL AND remaining_amount=0 AND released_amount>0 AND refunded_amount>0))));
CREATE TABLE escrow_disbursements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    escrow_id INT UNSIGNED NOT NULL,
    milestone_id INT UNSIGNED NULL DEFAULT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    phase ENUM('milestone','final','cancellation','dispute') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    operation_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    terminal_escrow_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN phase<>'milestone' THEN escrow_id ELSE NULL END) PERSISTENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_disbursement_operation (operation_key),
    UNIQUE KEY uq_disbursement_milestone (escrow_id,milestone_id),
    UNIQUE KEY uq_disbursement_terminal (terminal_escrow_id),
    KEY ix_disbursement_recipient (recipient_user_id,created_at,id),
    CONSTRAINT fk_disbursement_escrow FOREIGN KEY (escrow_id) REFERENCES escrows(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_disbursement_milestone FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_disbursement_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_disbursement_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_disbursement_amount CHECK (amount>0),
    CONSTRAINT ck_disbursement_operation CHECK (CHAR_LENGTH(TRIM(operation_key))>0),
    CONSTRAINT ck_disbursement_milestone CHECK ((phase='milestone' AND milestone_id IS NOT NULL) OR (phase<>'milestone' AND milestone_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE wallet_entries
    ADD COLUMN disbursement_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN escrow_once_type VARCHAR(30) GENERATED ALWAYS AS (
        CASE WHEN entry_type IN ('escrow_fund','escrow_release','escrow_refund') THEN entry_type ELSE NULL END) PERSISTENT,
    ADD UNIQUE KEY uq_wallet_disbursement (disbursement_id),
    ADD CONSTRAINT fk_wallet_disbursement FOREIGN KEY (disbursement_id) REFERENCES escrow_disbursements(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    MODIFY COLUMN entry_type ENUM('demo_credit','escrow_fund','escrow_release','escrow_refund','milestone_release') NOT NULL,
    MODIFY COLUMN source ENUM('demo_seed','client_funding','client_approval','mutual_cancellation','admin_decision','milestone_approval') NOT NULL,
    DROP INDEX uq_wallet_escrow_type,
    ADD UNIQUE KEY uq_wallet_escrow_type (escrow_id,escrow_once_type),
    DROP CONSTRAINT ck_wallet_entry_semantics,
    ADD CONSTRAINT ck_wallet_entry_semantics CHECK (
        (entry_type='demo_credit' AND escrow_id IS NULL AND amount_delta>0 AND source='demo_seed' AND disbursement_id IS NULL)
        OR (entry_type='escrow_fund' AND escrow_id IS NOT NULL AND amount_delta<0 AND source='client_funding' AND disbursement_id IS NULL)
        OR (entry_type='escrow_release' AND escrow_id IS NOT NULL AND amount_delta>0 AND source IN ('client_approval','admin_decision'))
        OR (entry_type='escrow_refund' AND escrow_id IS NOT NULL AND amount_delta>0 AND source IN ('mutual_cancellation','admin_decision'))
        OR (entry_type='milestone_release' AND escrow_id IS NOT NULL AND amount_delta>0 AND source='milestone_approval' AND disbursement_id IS NOT NULL));
CREATE TRIGGER trg_disbursements_no_update BEFORE UPDATE ON escrow_disbursements FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='escrow_disbursements is append-only';
CREATE TRIGGER trg_disbursements_no_delete BEFORE DELETE ON escrow_disbursements FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='escrow_disbursements is append-only';
