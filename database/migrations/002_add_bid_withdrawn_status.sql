-- Module 12B: applied separately to local gighub_dev; do not reapply there.
-- Other existing environments require their own preflight and approval.
-- MariaDB 10.4. Verify the approved clean instance at 127.0.0.1:3309, back up,
-- explicitly select the approved target database and inspect bids.status first.
-- Existing expected enum: pending, accepted, rejected; default pending, NOT NULL.
-- Apply once only after separate approval; stop on unexpected schema or errors.
-- Append withdrawn to preserve existing enum ordinal values and all bid records.
-- No indexes, foreign keys, users, grants or business records are changed here.
-- DDL implicitly commits. Do not use a transaction as a rollback guarantee.
-- Fresh installations use schema.sql instead; do not apply both.

ALTER TABLE bids
    MODIFY COLUMN status ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending';
