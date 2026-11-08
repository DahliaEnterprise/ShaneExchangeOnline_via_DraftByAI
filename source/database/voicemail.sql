USE shasta_exchange;

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_participants (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_read_at DATETIME NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_cp_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_cp_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    stored_name VARCHAR(80) NOT NULL UNIQUE,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'audio/ogg',
    byte_size INT UNSIGNED NOT NULL,
    duration_seconds DECIMAL(6,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_sender FOREIGN KEY (sender_id)
        REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_message_queue (conversation_id, created_at, id),
    INDEX idx_message_sender (sender_id)
) ENGINE=InnoDB;
