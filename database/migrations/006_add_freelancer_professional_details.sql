CREATE TABLE freelancer_education (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    institution VARCHAR(150) NOT NULL,
    qualification VARCHAR(150) NOT NULL,
    field_of_study VARCHAR(150) NULL,
    start_year SMALLINT UNSIGNED NULL,
    end_year SMALLINT UNSIGNED NULL,
    KEY ix_education_owner (freelancer_id, start_year, id),
    CONSTRAINT fk_education_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT,
    CONSTRAINT ck_education_years CHECK (end_year >= start_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_employment (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    job_title VARCHAR(150) NOT NULL,
    company VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    current_job TINYINT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NULL,
    KEY ix_employment_owner (freelancer_id, start_date, id),
    CONSTRAINT fk_employment_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT,
    CONSTRAINT ck_employment_dates CHECK ((end_date IS NULL OR end_date >= start_date) AND current_job IN (0,1) AND (current_job = 0 OR end_date IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freelancer_languages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT UNSIGNED NOT NULL,
    language VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proficiency ENUM('Basic','Conversational','Fluent','Native') NOT NULL,
    UNIQUE KEY uq_freelancer_language (freelancer_id, language),
    CONSTRAINT fk_language_profile FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
