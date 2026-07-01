CREATE TABLE IF NOT EXISTS `ga_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `account` varchar(64) NOT NULL COMMENT '登录账号',
  `email` varchar(128) DEFAULT NULL COMMENT '邮箱',
  `nickname` varchar(64) NOT NULL DEFAULT '' COMMENT '昵称',
  `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额',
  `expire_at` date DEFAULT NULL COMMENT '到期时间',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：1正常，0禁用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account` (`account`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoa Quán用户表';

CREATE TABLE IF NOT EXISTS `ga_game_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '游戏账号ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `display_name` varchar(128) NOT NULL COMMENT '展示名称',
  `status` varchar(32) NOT NULL DEFAULT 'reserved' COMMENT '状态',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='预留游戏账号表';

CREATE TABLE IF NOT EXISTS `ga_system_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `name` varchar(128) NOT NULL COMMENT '配置键',
  `value` text NOT NULL COMMENT '配置值',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统配置表';

CREATE TABLE IF NOT EXISTS `ga_admin_operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `admin_id` int unsigned DEFAULT NULL COMMENT '管理员ID',
  `action` varchar(128) NOT NULL COMMENT '动作',
  `target_type` varchar(64) NOT NULL DEFAULT '' COMMENT '对象类型',
  `target_id` varchar(64) NOT NULL DEFAULT '' COMMENT '对象ID',
  `payload` json DEFAULT NULL COMMENT '操作内容',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='后台操作日志表';

INSERT INTO `ga_system_settings` (`name`, `value`, `remark`)
VALUES
  ('third_party_enabled', '0', '第三方接口是否启用：0否，1是'),
  ('third_party_base_url', '', '第三方接口地址'),
  ('third_party_sign_secret', '', '第三方签名密钥'),
  ('smtp_enabled', '0', 'SMTP是否启用：0否，1是'),
  ('smtp_host', '', 'SMTP服务器地址'),
  ('smtp_port', '587', 'SMTP端口'),
  ('smtp_username', '', 'SMTP账号'),
  ('smtp_password', '', 'SMTP密码或授权码'),
  ('smtp_encryption', 'tls', 'SMTP加密方式：tls、ssl、none'),
  ('smtp_from_email', '', '发件邮箱'),
  ('smtp_from_name', 'Hoa Quán', '发件名称')
ON DUPLICATE KEY UPDATE
  `remark` = VALUES(`remark`);

INSERT INTO `ga_users` (`account`, `nickname`, `password_hash`, `balance`, `expire_at`, `status`)
VALUES
  ('player001', '测试玩家', '$2y$10$Amj/bNl.j9kuD4p0RtOxKeGtckwtC3oGX83ldo4hqJdEEXuzYiB6.', '0.00', NULL, 1)
ON DUPLICATE KEY UPDATE
  `nickname` = VALUES(`nickname`),
  `status` = VALUES(`status`);

INSERT INTO `wa_admins` (`username`, `nickname`, `password`, `avatar`, `created_at`, `updated_at`, `status`)
VALUES
  ('admin', '超级管理员', '$2y$10$RITe1ayE3Ez3fnhYDpyYruMo.M7nh5jk7AVfvVCk6HSxGYouNm6oy', '/app/admin/avatar.png', NOW(), NOW(), 0)
ON DUPLICATE KEY UPDATE
  `nickname` = VALUES(`nickname`),
  `status` = VALUES(`status`);

INSERT IGNORE INTO `wa_admin_roles` (`role_id`, `admin_id`)
SELECT 1, `id` FROM `wa_admins` WHERE `username` = 'admin';
