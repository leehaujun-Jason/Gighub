-- Review and substitute the database and account names before applying.
-- Run as the setup administrator after importing schema.sql.
-- No runtime grant is provided for the currently unused uploaded_files table.
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`users` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`client_profiles` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`freelancer_profiles` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`email_verification_tokens` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`bids` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`work_submissions` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`wallets` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`escrows` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`chat_read_watermarks` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`notifications` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`disputes` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`dispute_investigation_watermarks` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON `gighub_local`.`cancellation_requests` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`freelancer_education` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`freelancer_employment` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`freelancer_languages` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`freelancer_portfolio` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`jobs` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `gighub_local`.`milestones` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `gighub_local`.`password_reset_tokens` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `gighub_local`.`user_skills` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `gighub_local`.`job_skills` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `gighub_local`.`job_requirement_documents` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`work_submission_links` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`wallet_entries` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`chat_messages` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`chat_message_attachments` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`dispute_investigation_messages` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`dispute_investigation_attachments` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT, INSERT ON `gighub_local`.`reviews` TO 'gighub_runtime'@'127.0.0.1';
GRANT SELECT ON `gighub_local`.`skills` TO 'gighub_runtime'@'127.0.0.1';
