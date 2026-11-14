CREATE TABLE freelancer_portfolio (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    project_title VARCHAR(150) NOT NULL,
    project_url VARCHAR(2048) NOT NULL,
    description VARCHAR(500) NOT NULL,
    KEY ix_portfolio_owner (freelancer_id, id),
    CONSTRAINT fk_portfolio_entry_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
