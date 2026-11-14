ALTER TABLE chat_messages
    DROP CONSTRAINT ck_chat_body,
    ADD COLUMN attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN attachment_name VARCHAR(255) NULL,
    ADD COLUMN attachment_image TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT ck_chat_content CHECK (
        (attachment_key IS NULL AND attachment_name IS NULL AND attachment_image = 0 AND CHAR_LENGTH(TRIM(body)) > 0)
        OR (attachment_key IS NOT NULL AND attachment_name IS NOT NULL AND attachment_image IN (0,1))
    );
