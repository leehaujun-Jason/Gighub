CREATE TABLE chat_message_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attachment_name VARCHAR(255) NOT NULL,
    attachment_image TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_chat_attachment_message (message_id, id),
    CONSTRAINT fk_chat_attachment_message FOREIGN KEY (message_id) REFERENCES chat_messages (id) ON DELETE RESTRICT,
    CONSTRAINT ck_chat_attachment_image CHECK (attachment_image IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
