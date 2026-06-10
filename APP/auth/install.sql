-- ============================================
-- 网页验证系统 - 完整数据库（唯一导入文件）
-- 在 phpMyAdmin 或命令行导入本文件即可完成：建库 + 全部表结构
-- 包含：管理员、卡密（含暂停/启用、到期时间、关联用户）、前端用户、登录IP记录
-- 一键安装请使用根目录 install.php 建库建表并创建管理员
-- ============================================

-- 请先在宝塔/phpMyAdmin 中选择目标数据库后再导入本文件

-- 管理员用户表（role: admin=总管理 agent=代理）
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码(bcrypt)',
  `role` varchar(16) NOT NULL DEFAULT 'admin' COMMENT 'admin=总管理 agent=代理',
  `current_session_id` varchar(128) DEFAULT NULL COMMENT '登录时的session_id，删除代理时用于踢下线',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员用户';

-- 卡密表
CREATE TABLE IF NOT EXISTS `card_keys` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `card_code` varchar(64) NOT NULL COMMENT '卡密',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=未使用 1=已使用',
  `paused` tinyint NOT NULL DEFAULT 0 COMMENT '0=启用 1=已暂停',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间，NULL=永久',
  `card_type` varchar(16) DEFAULT NULL COMMENT 'day=天卡24h week=周卡7*24h month=月卡30*24h',
  `user_id` int unsigned DEFAULT NULL COMMENT '使用该卡密注册的用户ID',
  `bound_ip` varchar(64) DEFAULT NULL COMMENT '注册时IP',
  `bound_at` datetime DEFAULT NULL COMMENT '使用时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `creator_id` int unsigned DEFAULT NULL COMMENT '生成者admin_users.id，NULL=总管理',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_code` (`card_code`),
  KEY `idx_status` (`status`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_creator_id` (`creator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='卡密';

-- 前端用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码(bcrypt)',
  `security_code` varchar(255) NOT NULL COMMENT '找回密码安全码(哈希)',
  `card_key_id` int unsigned DEFAULT NULL COMMENT '使用的卡密ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_card_key_id` (`card_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='前端用户';

-- 用户登录IP记录
CREATE TABLE IF NOT EXISTS `user_login_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `ip` varchar(64) NOT NULL COMMENT '登录IP',
  `login_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户登录IP记录';

-- 黑名单（IP / 用户）
CREATE TABLE IF NOT EXISTS `blacklist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(16) NOT NULL COMMENT 'ip=IP黑名单 user=用户黑名单',
  `value` varchar(255) NOT NULL COMMENT 'IP或用户名',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_value` (`type`,`value`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单';

-- 管理员操作日志
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
