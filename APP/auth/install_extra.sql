-- ============================================
-- install.sql 基础库之外的补充表 / 字段
-- install.php 会自动执行，也可在 phpMyAdmin 手动导入
-- ============================================

-- 用户注册来源域名，已存在时可忽略重复字段报错
ALTER TABLE `users`
  ADD COLUMN `register_server` varchar(255) DEFAULT NULL COMMENT '注册时站点 Host' AFTER `card_key_id`;

-- 紧急停机站点
CREATE TABLE IF NOT EXISTS `emergency_stop_sites` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL,
  `name` varchar(128) DEFAULT NULL,
  `active` tinyint NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 试用卡领取记录
CREATE TABLE IF NOT EXISTS `trial_card_claims` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(128) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `card_code` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unlocked` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已人工解锁',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device` (`device_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 房间在线状态：IP -> 房间
CREATE TABLE IF NOT EXISTS `user_room_online` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(64) NOT NULL,
  `room` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip` (`ip`),
  KEY `idx_ip_updated_at` (`ip`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统公告
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

-- 页面链接配置
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面配置';

-- APP 默认远程更新配置；已存在配置时不覆盖后台保存值
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('version_code', '11'),
  ('version_name', 'v6.1.11'),
  ('apk_url', '/apk/ALinRadar-v6.1.11.apk'),
  ('apk_url_github', ''),
  ('apk_url_gitee', ''),
  ('update_title', '发现新版本'),
  ('update_message', '检测到新版本，请下载更新。')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
