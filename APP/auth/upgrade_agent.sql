-- ============================================
-- 代理系统升级：两机共用数据库 + 总管理/代理权限
-- 执行前请备份数据库。本脚本可重复执行，已存在的列/索引会跳过。
-- ============================================

-- 请先在宝塔/phpMyAdmin 中选择目标数据库后再导入本文件

-- 管理员表增加角色：admin=总管理 agent=代理（若已存在则跳过）
SET @db = DATABASE();
SET @add_role = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'role');
SET @sql_role = IF(@add_role = 0,
  'ALTER TABLE `admin_users` ADD COLUMN `role` varchar(16) NOT NULL DEFAULT ''admin'' COMMENT ''admin=总管理 agent=代理'' AFTER `password`',
  'SELECT 1');
PREPARE stmt FROM @sql_role;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 卡密表增加生成者：NULL=总管理生成，非空=该 admin_users.id 的代理生成（若已存在则跳过）
SET @add_creator = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'card_keys' AND COLUMN_NAME = 'creator_id');
SET @sql_creator = IF(@add_creator = 0,
  'ALTER TABLE `card_keys` ADD COLUMN `creator_id` int unsigned DEFAULT NULL COMMENT ''生成者admin_users.id，NULL=总管理'' AFTER `remark`',
  'SELECT 1');
PREPARE stmt FROM @sql_creator;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 索引 idx_creator_id（若已存在则跳过）
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'card_keys' AND INDEX_NAME = 'idx_creator_id');
SET @sql_idx = IF(@has_idx = 0,
  'ALTER TABLE `card_keys` ADD KEY `idx_creator_id` (`creator_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 管理员表增加当前会话 ID（用于删除代理时踢下线，若已存在则跳过）
SET @add_sid = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'current_session_id');
SET @sql_sid = IF(@add_sid = 0,
  'ALTER TABLE `admin_users` ADD COLUMN `current_session_id` varchar(128) DEFAULT NULL COMMENT ''登录时的session_id，删除代理时用于踢下线'' AFTER `role`',
  'SELECT 1');
PREPARE stmt FROM @sql_sid;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
