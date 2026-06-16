-- 自在空间用户系统 - 数据库初始化脚本
-- 在宝塔面板的MySQL中执行此脚本

-- 1. 创建数据库
CREATE DATABASE IF NOT EXISTS `zizai_system` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `zizai_system`;

-- 2. 创建用户表
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(100),
    `avatar_url` VARCHAR(500),
    `bio` TEXT,
    `is_admin` BOOLEAN DEFAULT FALSE,
    `is_approved` BOOLEAN DEFAULT FALSE,
    `is_active` BOOLEAN DEFAULT TRUE,
    `storage_limit` BIGINT DEFAULT 10737418240, -- 10GB
    `used_storage` BIGINT DEFAULT 0,
    `registration_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_is_admin` (`is_admin`),
    INDEX `idx_is_approved` (`is_approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 创建注册申请表
CREATE TABLE IF NOT EXISTS `registration_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(100),
    `reason` TEXT,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reviewed_by` INT,
    `review_notes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME,
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_reviewed_by` (`reviewed_by`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 创建作品画廊表
CREATE TABLE IF NOT EXISTS `gallery_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `image_url` VARCHAR(500) NOT NULL,
    `thumbnail_url` VARCHAR(500),
    `category` VARCHAR(50),
    `tags` JSON,
    `is_public` BOOLEAN DEFAULT TRUE,
    `view_count` INT DEFAULT 0,
    `like_count` INT DEFAULT 0,
    `download_count` INT DEFAULT 0,
    `file_size` BIGINT,
    `mime_type` VARCHAR(100),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_category` (`category`),
    INDEX `idx_is_public` (`is_public`),
    INDEX `idx_created_at` (`created_at`),
    FULLTEXT `idx_search` (`title`, `description`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 创建云盘文件表
CREATE TABLE IF NOT EXISTS `cloud_files` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT NOT NULL,
    `mime_type` VARCHAR(100),
    `is_public` BOOLEAN DEFAULT FALSE,
    `download_count` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_is_public` (`is_public`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_filename` (`filename`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 创建操作日志表
CREATE TABLE IF NOT EXISTS `operation_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action_type` VARCHAR(50) NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `target_id` INT,
    `details` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action_type` (`action_type`),
    INDEX `idx_target` (`target_type`, `target_id`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 创建作品点赞表
CREATE TABLE IF NOT EXISTS `gallery_likes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `gallery_id` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_like` (`user_id`, `gallery_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_gallery_id` (`gallery_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`gallery_id`) REFERENCES `gallery_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 创建作品评论表
CREATE TABLE IF NOT EXISTS `gallery_comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `gallery_id` INT NOT NULL,
    `parent_id` INT,
    `content` TEXT NOT NULL,
    `is_public` BOOLEAN DEFAULT TRUE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_gallery_id` (`gallery_id`),
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`gallery_id`) REFERENCES `gallery_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_id`) REFERENCES `gallery_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 创建存储过程：检查用户存储空间
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `check_user_storage`(
    IN p_user_id INT,
    IN p_file_size BIGINT,
    OUT p_can_upload BOOLEAN
)
BEGIN
    DECLARE v_storage_limit BIGINT;
    DECLARE v_used_storage BIGINT;
    DECLARE v_free_storage BIGINT;
    
    -- 获取用户存储信息
    SELECT `storage_limit`, `used_storage`
    INTO v_storage_limit, v_used_storage
    FROM `users`
    WHERE `id` = p_user_id;
    
    -- 计算可用空间
    SET v_free_storage = v_storage_limit - v_used_storage;
    
    -- 检查是否可以上传
    IF v_free_storage >= p_file_size THEN
        SET p_can_upload = TRUE;
    ELSE
        SET p_can_upload = FALSE;
    END IF;
END //

DELIMITER ;

-- 10. 创建存储过程：更新用户存储使用量
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `update_user_storage`(
    IN p_user_id INT,
    IN p_file_size BIGINT,
    IN p_operation VARCHAR(10) -- 'add' or 'remove'
)
BEGIN
    IF p_operation = 'add' THEN
        UPDATE `users` 
        SET `used_storage` = `used_storage` + p_file_size,
            `updated_at` = CURRENT_TIMESTAMP
        WHERE `id` = p_user_id;
    ELSEIF p_operation = 'remove' THEN
        UPDATE `users` 
        SET `used_storage` = GREATEST(0, `used_storage` - p_file_size),
            `updated_at` = CURRENT_TIMESTAMP
        WHERE `id` = p_user_id;
    END IF;
END //

DELIMITER ;

-- 11. 创建触发器：文件上传时更新存储使用量
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `after_cloud_file_insert`
AFTER INSERT ON `cloud_files`
FOR EACH ROW
BEGIN
    CALL `update_user_storage`(NEW.`user_id`, NEW.`file_size`, 'add');
END //

DELIMITER ;

-- 12. 创建触发器：文件删除时更新存储使用量
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `after_cloud_file_delete`
AFTER DELETE ON `cloud_files`
FOR EACH ROW
BEGIN
    CALL `update_user_storage`(OLD.`user_id`, OLD.`file_size`, 'remove');
END //

DELIMITER ;

-- 13. 创建触发器：作品上传时更新存储使用量
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `after_gallery_item_insert`
AFTER INSERT ON `gallery_items`
FOR EACH ROW
BEGIN
    IF NEW.`file_size` IS NOT NULL THEN
        CALL `update_user_storage`(NEW.`user_id`, NEW.`file_size`, 'add');
    END IF;
END //

DELIMITER ;

-- 14. 创建触发器：作品删除时更新存储使用量
DELIMITER //

CREATE TRIGGER IF NOT EXISTS `after_gallery_item_delete`
AFTER DELETE ON `gallery_items`
FOR EACH ROW
BEGIN
    IF OLD.`file_size` IS NOT NULL THEN
        CALL `update_user_storage`(OLD.`user_id`, OLD.`file_size`, 'remove');
    END IF;
END //

DELIMITER ;

-- 15. 创建视图：用户存储概览
CREATE OR REPLACE VIEW `user_storage_overview` AS
SELECT 
    u.`id`,
    u.`username`,
    u.`email`,
    u.`storage_limit`,
    u.`used_storage`,
    u.`storage_limit` - u.`used_storage` as `free_storage`,
    ROUND(u.`used_storage` * 100.0 / u.`storage_limit`, 2) as `usage_percentage`,
    COUNT(DISTINCT cf.`id`) as `cloud_file_count`,
    COUNT(DISTINCT gi.`id`) as `gallery_item_count`,
    COALESCE(SUM(cf.`file_size`), 0) as `cloud_storage_used`,
    COALESCE(SUM(gi.`file_size`), 0) as `gallery_storage_used`
FROM `users` u
LEFT JOIN `cloud_files` cf ON u.`id` = cf.`user_id`
LEFT JOIN `gallery_items` gi ON u.`id` = gi.`user_id`
GROUP BY u.`id`, u.`username`, u.`email`, u.`storage_limit`, u.`used_storage`;

-- 16. 插入默认管理员账户
-- 密码: zizai123 (使用BCrypt加密)
INSERT IGNORE INTO `users` (
    `username`, `email`, `password_hash`, `display_name`, 
    `is_admin`, `is_approved`, `storage_limit`, `is_active`
) VALUES (
    'xiaoxin',
    'admin@zizaimedia.com',
    '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', -- zizai123
    '小新管理员',
    TRUE,
    TRUE,
    10737418240, -- 10GB
    TRUE
);

-- 17. 插入示例用户
INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `display_name`, `is_approved`, `storage_limit`) 
VALUES 
    ('user1', 'user1@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '用户一', TRUE, 10737418240),
    ('user2', 'user2@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '用户二', TRUE, 10737418240),
    ('user3', 'user3@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '用户三', FALSE, 10737418240);

-- 18. 插入示例注册申请
INSERT IGNORE INTO `registration_requests` (`username`, `email`, `password_hash`, `display_name`, `reason`, `status`) 
VALUES 
    ('newuser1', 'new1@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '新用户一', '希望加入自在空间社区', 'pending'),
    ('newuser2', 'new2@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '新用户二', '对数字艺术感兴趣', 'pending'),
    ('newuser3', 'new3@example.com', '$2a$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhgjR8IC.HLwWaQd.6QcK6', '新用户三', '需要云存储空间', 'approved');

-- 19. 创建索引优化
CREATE INDEX IF NOT EXISTS `idx_users_created_at` ON `users`(`created_at`);
CREATE INDEX IF NOT EXISTS `idx_gallery_public_created` ON `gallery_items`(`is_public`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_cloud_files_public_created` ON `cloud_files`(`is_public`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_operation_logs_user_action` ON `operation_logs`(`user_id`, `action_type`);

-- 20. 验证数据库初始化
SELECT '数据库初始化完成' as `message`;
SELECT COUNT(*) as `user_count` FROM `users`;
SELECT COUNT(*) as `gallery_count` FROM `gallery_items`;
SELECT COUNT(*) as `cloud_count` FROM `cloud_files`;
SELECT COUNT(*) as `log_count` FROM `operation_logs`;

-- 21. 显示管理员账户信息
SELECT 
    `username`,
    `email`,
    `is_admin`,
    `is_approved`,
    CONCAT(ROUND(`storage_limit` / 1073741824, 2), ' GB') as `storage_limit`,
    CONCAT(ROUND(`used_storage` / 1048576, 2), ' MB') as `used_storage`
FROM `users` 
WHERE `is_admin` = TRUE;

-- 22. 显示系统信息
SELECT 
    (SELECT COUNT(*) FROM `users`) as `total_users`,
    (SELECT COUNT(*) FROM `users` WHERE `is_admin` = TRUE) as `admin_users`,
    (SELECT COUNT(*) FROM `users` WHERE `is_approved` = TRUE) as `approved_users`,
    (SELECT COUNT(*) FROM `registration_requests` WHERE `status` = 'pending') as `pending_registrations`,
    (SELECT COUNT(*) FROM `gallery_items`) as `total_gallery_items`,
    (SELECT COUNT(*) FROM `cloud_files`) as `total_cloud_files`,
    (SELECT COALESCE(SUM(`file_size`), 0) FROM `cloud_files`) as `total_cloud_size`,
    (SELECT COALESCE(SUM(`file_size`), 0) FROM `gallery_items`) as `total_gallery_size`;