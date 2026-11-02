-- Module 12A: nullable professional headline.
-- Applied separately to local gighub_dev; do not reapply there.
-- Other existing environments require their own preflight and approval below.
-- MariaDB 10.4. Run once, only after separate approval for each target database.
-- Verify the clean instance at 127.0.0.1:3309 and explicitly select the approved
-- gighub_dev or gighub_schema_test database before executing this file.
-- Preflight: freelancer_profiles must exist and headline must be absent.
-- Preserve a verified backup first. ALTER TABLE implicitly commits; a wrapping
-- transaction cannot roll this change back. Stop on error; do not blindly retry.
-- Existing rows receive NULL; existing columns, PK and FKs are unchanged.
-- Fresh empty installations use schema.sql instead; do not apply both.
-- After execution, verify the column definition, profile row count and keys.

ALTER TABLE freelancer_profiles
    ADD COLUMN headline VARCHAR(150) NULL DEFAULT NULL;
