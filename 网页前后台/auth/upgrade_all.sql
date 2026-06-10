-- ============================================
-- 老库一键升级：合并所有升级语句，执行本文件即可
-- 适用：之前装过旧版，只有 admin_users、card_keys 等基础表
-- 若某条报错 Duplicate column name 或 Table already exists，说明已有该结构，可忽略该条
-- 新装请用 install.php 或导入 install.sql，不要用本文件
-- ============================================

-- 请先在宝塔/phpMyAdmin 中选择目标数据库后再导入本文件

-- ----------------------------------------
-- 1. 为 card_keys 增加列（若列已存在会报错，可忽略）
-- ----------------------------------------
ALTER TABLE `card_keys` ADD COLUMN `expires_at` datetime DEFAULT NULL COMMENT '到期时间，NULL表示永久有效' AFTER `status`;
ALTER TABLE `card_keys` ADD COLUMN `user_id` int unsigned DEFAULT NULL COMMENT '使用该卡密注册的用户ID' AFTER `expires_at`;
ALTER TABLE `card_keys` ADD COLUMN `paused` tinyint NOT NULL DEFAULT 0 COMMENT '0=启用 1=已暂停' AFTER `status`;
ALTER TABLE `card_keys` ADD COLUMN `card_type` varchar(16) DEFAULT NULL COMMENT 'day=天卡24h week=周卡7*24h month=月卡30*24h' AFTER `expires_at`;

-- ----------------------------------------
-- 2. 前端用户表
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码(bcrypt)',
  `card_key_id` int unsigned DEFAULT NULL COMMENT '使用的卡密ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_card_key_id` (`card_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='前端用户';

-- ----------------------------------------
-- 3. 用户登录IP记录
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `user_login_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `ip` varchar(64) NOT NULL COMMENT '登录IP',
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户登录IP记录';

-- ----------------------------------------
-- 4. 黑名单表（IP/用户黑名单）
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `blacklist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(16) NOT NULL COMMENT 'ip=IP黑名单 user=用户黑名单',
  `value` varchar(255) NOT NULL COMMENT 'IP或用户名',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_value` (`type`,`value`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单';

-- ----------------------------------------
-- 5. 管理员操作日志（后台操作审计）
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `admin_operation_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_username` varchar(64) NOT NULL COMMENT '管理员用户名',
  `action` varchar(64) NOT NULL COMMENT '操作类型',
  `detail` varchar(500) DEFAULT NULL COMMENT '详情',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_admin` (`admin_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作日志';

-- ----------------------------------------
-- 6. 系统公告表（可选，当前版本未使用公告功能）
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `content` text NOT NULL COMMENT '公告内容',
  `enabled` tinyint NOT NULL DEFAULT 1 COMMENT '0=关闭 1=启用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序，越小越靠前',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enabled_sort` (`enabled`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统公告';

-- ----------------------------------------
-- 7. APP 远程配置 / 页面链接配置
-- ----------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面配置';

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('version_code', '11'),
  ('version_name', 'v6.1.11'),
  ('apk_url', '/apk/ALinRadar-v6.1.11.apk'),
  ('apk_url_github', ''),
  ('apk_url_gitee', ''),
  ('update_title', '发现新版本'),
  ('update_message', '检测到新版本，请下载更新。')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
