-- ONLINE NOTICE BOARD DATABASE
CREATE DATABASE IF NOT EXISTS online_notice_board;
USE online_notice_board;

CREATE TABLE IF NOT EXISTS admin (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO admin (name, username, password)
VALUES ('System Administrator', 'admin', 'e6e061838856bf47e1de730719fb2609')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password = VALUES(password);

CREATE TABLE IF NOT EXISTS category (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO category (category_name)
VALUES ('Academics'), ('Sports'), ('Events'), ('General')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

CREATE TABLE IF NOT EXISTS notice (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category INT NOT NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    expiresAt DATETIME NOT NULL,
    file VARCHAR(255) NULL,
    admin_id INT NOT NULL,
    pin TINYINT DEFAULT 0,
    views INT DEFAULT 0,
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Low',
    visibility ENUM('public', 'students', 'staff') DEFAULT 'public',
    is_deleted TINYINT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notice_category FOREIGN KEY (category) REFERENCES category(id),
    CONSTRAINT fk_notice_admin FOREIGN KEY (admin_id) REFERENCES admin(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add description column for existing databases where notice table already exists:
SET @notice_description_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notice'
      AND COLUMN_NAME = 'description'
);
SET @notice_description_sql = IF(
    @notice_description_exists = 0,
    'ALTER TABLE notice ADD COLUMN description TEXT NULL AFTER title',
    'SELECT 1'
);
PREPARE notice_description_stmt FROM @notice_description_sql;
EXECUTE notice_description_stmt;
DEALLOCATE PREPARE notice_description_stmt;

CREATE TABLE IF NOT EXISTS notice_files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    notice_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    CONSTRAINT fk_notice_files_notice FOREIGN KEY (notice_id) REFERENCES notice(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expired_notice (
    id INT PRIMARY KEY AUTO_INCREMENT,
    original_notice_id INT NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category INT NULL,
    category_name VARCHAR(100) NULL,
    createdAt DATETIME NULL,
    expiresAt DATETIME NOT NULL,
    file VARCHAR(255) NULL,
    admin_id INT NULL,
    admin_name VARCHAR(100) NULL,
    pin TINYINT DEFAULT 0,
    views INT DEFAULT 0,
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Low',
    visibility ENUM('public', 'students', 'staff') DEFAULT 'public',
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expired_notice_files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expired_notice_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    CONSTRAINT fk_expired_notice_files_notice
        FOREIGN KEY (expired_notice_id) REFERENCES expired_notice(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    notice_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_notice FOREIGN KEY (notice_id) REFERENCES notice(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto move expired notices query reference (application handles archive migration automatically):
-- DELETE FROM notice WHERE expiresAt < NOW();
-- Keep application-level cleanupExpiredNotices() enabled to move expired notices
-- into expired_notice / expired_notice_files and keep their attachments available.
