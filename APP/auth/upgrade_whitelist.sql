-- ============================================
-- IP 白名单功能升级脚本
-- 用于为 8888/9999 端口 WebSocket 连接添加白名单访问控制
-- 在 phpMyAdmin 或命令行执行：mysql -u root -p 数据库名 < upgrade_whitelist.sql
-- ============================================

-- IP 白名单表
-- 用户登录后自动将其 IP 加入白名单，登出/卡密过期/暂停后移除
CREATE TABLE IF NOT EXISTS `ip_whitelist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(64) NOT NULL COMMENT '客户端 IP',
  `user_id` int unsigned NOT NULL COMMENT '对应用户 ID',
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '用户名（冗余，方便查询）',
  `expires_at` datetime DEFAULT NULL COMMENT '白名单到期时间，NULL=永久（对应卡密永久）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次加入时间',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最近刷新（每次登录都更新）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip` (`ip`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebSocket 端口 IP 白名单';
