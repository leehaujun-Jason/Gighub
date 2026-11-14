ALTER TABLE users ADD COLUMN onboarding_pending TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE client_profiles ADD COLUMN account_type ENUM('individual','company') NULL DEFAULT NULL;
