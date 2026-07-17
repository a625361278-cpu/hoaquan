CREATE TABLE IF NOT EXISTS `ga_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `account` varchar(64) NOT NULL COMMENT '登录账号',
  `email` varchar(128) DEFAULT NULL COMMENT '邮箱',
  `nickname` varchar(64) NOT NULL DEFAULT '' COMMENT '昵称',
  `password_hash` varchar(255) NOT NULL COMMENT '密码哈希',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额',
  `expire_at` date DEFAULT NULL COMMENT '到期时间',
  `invite_code` varchar(16) DEFAULT NULL COMMENT '专属邀请码',
  `invited_by_user_id` int unsigned DEFAULT NULL COMMENT '邀请人用户ID',
  `invite_registered_ip` varchar(64) NOT NULL DEFAULT '' COMMENT '邀请注册IP',
  `bound_role_id` varchar(128) DEFAULT NULL COMMENT '已绑定游戏角色ID',
  `role_bound_at` datetime DEFAULT NULL COMMENT '角色绑定时间',
  `invite_rewarded_at` datetime DEFAULT NULL COMMENT '邀请奖励发放时间',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态：1正常，0禁用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account` (`account`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `uniq_invite_code` (`invite_code`),
  UNIQUE KEY `uniq_bound_role_id` (`bound_role_id`),
  KEY `idx_invited_by_user_id` (`invited_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Hoa Quán用户表';

CREATE TABLE IF NOT EXISTS `ga_game_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '游戏账号ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `display_name` varchar(128) NOT NULL COMMENT '展示名称',
  `game_username` varchar(128) NOT NULL DEFAULT '' COMMENT '游戏登录账号',
  `game_uid` varchar(128) NOT NULL DEFAULT '' COMMENT 'Facebook或Google游戏UID',
  `game_password_cipher` text COMMENT '游戏密码密文',
  `game_token_cipher` longtext COMMENT 'Facebook或Google Token密文',
  `channel_code` varchar(64) NOT NULL DEFAULT 'official_app' COMMENT '渠道标识',
  `login_method` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '登录方式：1账号密码，2Facebook，3Google',
  `server_id` varchar(64) NOT NULL DEFAULT '' COMMENT '区服ID',
  `server_name` varchar(128) NOT NULL DEFAULT '' COMMENT '区服名称',
  `status` varchar(32) NOT NULL DEFAULT 'reserved' COMMENT '状态',
  `sync_status` varchar(32) NOT NULL DEFAULT 'local_unsynced' COMMENT '同步状态',
  `third_party_account_id` varchar(128) NOT NULL DEFAULT '' COMMENT '第三方账号标识',
  `log_session_id` varchar(64) NOT NULL DEFAULT '' COMMENT '当前运行日志会话',
  `desired_running` tinyint NOT NULL DEFAULT 0 COMMENT '用户期望运行：1继续运行，0停止',
  `auto_restart_attempts` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '自动重连连续失败次数',
  `auto_restart_next_at` datetime DEFAULT NULL COMMENT '下次自动重连时间',
  `auto_restart_last_error` text DEFAULT NULL COMMENT '最近自动重连错误',
  `expire_time` datetime DEFAULT NULL COMMENT '游戏账号到期时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `config_json` json DEFAULT NULL COMMENT '本地配置JSON',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_auto_restart_due` (`desired_running`, `status`, `auto_restart_next_at`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='预留游戏账号表';

CREATE TABLE IF NOT EXISTS `ga_game_account_task_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '任务状态ID',
  `game_account_id` int unsigned NOT NULL COMMENT '游戏账号ID',
  `state_json` longtext NOT NULL COMMENT '第三方任务状态JSON',
  `state_hash` char(64) NOT NULL COMMENT '任务状态SHA256',
  `state_bytes` int unsigned NOT NULL COMMENT '任务状态字节数',
  `saved_at` datetime NOT NULL COMMENT '第三方保存时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_task_state_account` (`game_account_id`),
  KEY `idx_task_state_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='游戏账号第三方任务状态表';

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

CREATE TABLE IF NOT EXISTS `ga_announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '公告ID',
  `title_zh_cn` varchar(128) NOT NULL COMMENT '中文标题',
  `title_vi` varchar(128) NOT NULL COMMENT '越南文标题',
  `content_zh_cn` text NOT NULL COMMENT '中文正文',
  `content_vi` text NOT NULL COMMENT '越南文正文',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '状态：1启用，0停用',
  `published_at` datetime NOT NULL COMMENT '发布时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_published` (`status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='登录公告表';

CREATE TABLE IF NOT EXISTS `ga_user_point_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '流水ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `type` varchar(32) NOT NULL COMMENT '类型',
  `amount` decimal(10,2) NOT NULL COMMENT '变动点数',
  `balance_after` decimal(10,2) NOT NULL COMMENT '变动后余额',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '说明',
  `related_user_id` int unsigned DEFAULT NULL COMMENT '关联用户ID',
  `related_role_id` varchar(128) NOT NULL DEFAULT '' COMMENT '关联角色ID',
  `related_payment_order_id` bigint unsigned DEFAULT NULL COMMENT '关联支付订单ID',
  `ip_address` varchar(64) NOT NULL DEFAULT '' COMMENT '触发IP',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_type_created` (`type`, `created_at`),
  UNIQUE KEY `uniq_invite_reward_user` (`type`, `related_user_id`),
  UNIQUE KEY `uniq_payment_recharge` (`type`, `related_payment_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户点数流水表';

CREATE TABLE IF NOT EXISTS `ga_payment_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '支付订单ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `provider` varchar(32) NOT NULL DEFAULT 'ronnypay' COMMENT '支付服务商',
  `package_code` varchar(64) NOT NULL COMMENT '套餐快照',
  `points` decimal(10,2) NOT NULL COMMENT '到账点数快照',
  `currency` char(3) NOT NULL DEFAULT 'VND' COMMENT '币种',
  `total_fee` decimal(18,2) NOT NULL COMMENT '订单金额',
  `customer_name` varchar(128) NOT NULL COMMENT '付款人姓名快照',
  `customer_mobile` varchar(64) NOT NULL COMMENT '付款人手机号快照',
  `bank_account` longtext NOT NULL COMMENT '付款账号快照',
  `idempotency_key` varchar(64) NOT NULL COMMENT '客户端幂等键',
  `merchant_order` varchar(64) NOT NULL COMMENT '商户订单号',
  `provider_order_number` varchar(64) DEFAULT NULL COMMENT '平台订单号',
  `status` varchar(32) NOT NULL COMMENT '订单状态',
  `pay_url` text DEFAULT NULL COMMENT '支付链接',
  `country` varchar(8) NOT NULL DEFAULT 'VN' COMMENT '国家',
  `product_code` varchar(64) NOT NULL DEFAULT '' COMMENT '支付产品编码快照',
  `wallet_type` varchar(64) NOT NULL DEFAULT '' COMMENT '钱包类型',
  `bank_code` varchar(64) NOT NULL DEFAULT '' COMMENT '银行或通道编码',
  `utr` varchar(128) NOT NULL DEFAULT '' COMMENT '支付参考号',
  `query_attempts` int unsigned NOT NULL DEFAULT 0 COMMENT '查单次数',
  `next_query_at` datetime DEFAULT NULL COMMENT '下次查单时间',
  `last_queried_at` datetime DEFAULT NULL COMMENT '最后查单时间',
  `notified_at` datetime DEFAULT NULL COMMENT '最后回调时间',
  `credited_at` datetime DEFAULT NULL COMMENT '点数入账时间',
  `last_error_code` varchar(32) NOT NULL DEFAULT '' COMMENT '最后错误码',
  `last_error_message` varchar(255) NOT NULL DEFAULT '' COMMENT '最后错误',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_merchant_order` (`merchant_order`),
  UNIQUE KEY `uniq_payment_provider_order` (`provider`,`provider_order_number`),
  UNIQUE KEY `uniq_payment_user_idempotency` (`user_id`, `idempotency_key`),
  KEY `idx_payment_reconcile` (`status`, `next_query_at`),
  KEY `idx_payment_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='支付订单表';

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
  ('third_party_ws_url', '', '第三方WebSocket地址'),
  ('third_party_ws_urls', '', '第三方WebSocket连接池地址列表，每行一个连接槽位'),
  ('third_party_ws_connection_capacity', '10', '第三方单条WebSocket连接最大承载账号数'),
  ('third_party_transport', 'websocket', '第三方通信方式：websocket或http'),
  ('third_party_sign_secret', '', '第三方签名密钥'),
  ('game_account_credential_key', '', '游戏账号密码加密密钥'),
  ('game_account_max_count', '3', '单个用户同时可存在的游戏账号数量上限'),
  ('registration_reward_points', '1', '新用户注册赠送配额点数，0表示关闭'),
  ('invite_reward_min_role_level', '30', '邀请奖励最低角色等级，允许1至9999'),
  ('payment_active_provider', 'disabled', '活动支付方式：disabled、ronnypay或mkpay'),
  ('payment_recharge_amount_vnd', '149000', '充值套餐金额，VND整数，只影响新订单'),
  ('payment_callback_allowed_ips', '', '支付回调来源IP白名单，多个IP可用换行、逗号、分号、空格或竖线分隔，留空不校验'),
  ('game_config_visibility_overrides', '{}', '游戏配置项用户端可见性相对默认值的覆盖JSON'),
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
