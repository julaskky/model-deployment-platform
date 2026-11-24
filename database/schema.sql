
CREATE DATABASE IF NOT EXISTS model_deployment_platform
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE model_deployment_platform;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS models (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    status ENUM('pending_upload', 'uploaded', 'analyzing',
                'ready', 'needs_configuration', 'unsupported',
                'deployment_failed', 'deployed')
        NOT NULL DEFAULT 'pending_upload',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_model_slug (slug),
    INDEX idx_models_user (user_id),

    CONSTRAINT fk_models_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS model_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending_upload', 'uploaded', 'analyzing',
                'ready', 'needs_configuration', 'unsupported')
        NOT NULL DEFAULT 'pending_upload',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_model_version (model_id, version),

    CONSTRAINT fk_versions_model
        FOREIGN KEY (model_id) REFERENCES models(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS model_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_version_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL UNIQUE,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    upload_status ENUM('pending', 'completed', 'failed')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_files_version
        FOREIGN KEY (model_version_id)
        REFERENCES model_versions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
