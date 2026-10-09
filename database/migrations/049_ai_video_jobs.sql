CREATE TABLE IF NOT EXISTS ai_video_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    platform VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    payload JSON NOT NULL,
    input_path VARCHAR(500) NOT NULL,
    post_id INT UNSIGNED NULL UNIQUE,
    error_code VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    INDEX idx_ai_video_queue (status, id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES content_posts(id) ON DELETE SET NULL
) ENGINE=InnoDB;
