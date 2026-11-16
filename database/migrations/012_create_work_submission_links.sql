CREATE TABLE work_submission_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_link_position (submission_id, position),
    UNIQUE KEY uq_submission_link_url (submission_id, url),
    CONSTRAINT fk_submission_link_attempt FOREIGN KEY (submission_id) REFERENCES work_submissions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_submission_link_position CHECK (position > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
