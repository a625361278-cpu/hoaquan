# 数据库初始化

默认库名为 `gameassist`，MySQL 连接信息从 `server/.env` 读取，不提交真实凭据。

初始化顺序：

1. 创建数据库：`CREATE DATABASE IF NOT EXISTS gameassist DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;`
2. 复制 `server/.env.example` 为 `server/.env`，填写本机 MySQL 和 Redis 连接信息。
3. 导入 `server/plugin/admin/install.sql`，创建 webman-admin 的 `wa_` 后台表。
4. 导入 `database/gameassist.sql`，创建 GameAssist 的 `ga_` 业务表和本地开发账号。
5. 执行 `cd server && php scripts/sync_database.php`，同步业务字段、第三方 WebSocket 连接池配置项和 SMTP 配置项。
6. 执行 `cd server && php scripts/sync_admin.php`，同步后台品牌和 webman-admin 原生菜单。

本地开发账号：

- 初始化脚本会创建本地开发账号，便于本机调试。
- 生产部署后必须立即修改或禁用默认初始化账号，不要把真实后台账号密码写入仓库文档。
- 用户端注册会写入 `ga_users`，不会生成假游戏账号。

`ga_game_accounts` 默认不插入任何游戏账号，用户端会显示真实空状态。

`ga_game_accounts.login_method` 定义登录方式：历史账号和默认值 `1` 为账号密码，`2` 为 Facebook，`3` 为 Google。社交账号使用 `game_uid` 和 `game_token_cipher`，Token 通过与游戏密码相同的凭证密钥加密；公开接口和日志不得返回 Token 明文或密文。后台开关只控制新增，不修改已有账号。

`ga_announcements` 默认不插入任何公告。用户端登录公告必须由后台“公告管理”录入并启用，系统不会生成默认公告来伪装运营内容。

邀请功能使用 `ga_users.invite_code`、`ga_users.invited_by_user_id`、`ga_users.bound_role_id`、`ga_users.invite_rewarded_at` 和 `ga_user_point_transactions`。注册时只记录邀请关系；第三方返回匹配当前运行上下文的真实 `started` 后，系统用 `role_id` 自动绑定角色，第三方未返回时使用用户填写的 `game_username`。绑定本身不发奖；只有同一游戏账号保持真实运行、账号角色与用户绑定角色一致，且后续真实 `status.level` 达到后台阈值时才给邀请人增加 1 点并写入流水。

配额功能使用同一个 `ga_users.balance` 余额池。邀请奖励和后台赠送都会增加余额；用户把配额分配到游戏账号时会立即扣除余额并更新 `ga_game_accounts.expire_time`，账号不启动也按自然时间消耗，删除游戏账号不退回余额。

MoMoPay/RonnyPay 与 MkPay 充值订单统一保存在 `ga_payment_orders`，`provider` 决定回调和查单路由，`product_code` 保存 MkPay 产品码快照。`bank_account` 使用 `LONGTEXT` 保存用户提交的付款帐号快照，只去除首尾空格并校验非空，不转换为数值，因此前导零会被保留；MkPay 不接收该字段。平台订单号唯一约束为 `(provider, provider_order_number)`，不同平台的同名订单号可以共存。

`ga_system_settings.payment_active_provider` 只允许 `disabled`、`ronnypay`、`mkpay`；它只决定新订单通道，不改变历史订单。同步脚本仅在该配置缺失时根据旧 `RONNYPAY_ORDER_ENABLED` 初始化，已存在的后台选择不会被覆盖。

`ga_system_settings.payment_recharge_amount_vnd` 保存 `quota_30` 套餐的 VND 整数金额，默认 `149000`，只允许 `1` 至 `999999999`。新订单创建时一次读取该值，并把两位小数金额快照写入 `ga_payment_orders.total_fee`；历史订单、幂等重试、回调和查单继续使用订单快照。同步脚本只在配置缺失时补齐默认值并更新说明，不覆盖后台已保存金额。

`ga_system_settings.payment_callback_allowed_ips` 保存支付回调来源 IP 白名单，多个 IPv4/IPv6 地址可用换行、逗号、分号、空格或竖线分隔，留空时不校验来源 IP。配置后 RonnyPay 与 MkPay 回调请求的真实 IP 必须精确命中白名单；非法白名单配置会让回调处理失败并暴露配置错误，不会静默放行。

新用户注册赠送由 `ga_system_settings.registration_reward_points` 控制，默认 1 点，0 表示关闭。用户创建、初始余额和 `ga_user_point_transactions(type=registration_reward)` 流水在同一事务中提交，只影响后续新注册用户，不补发历史账号；它与角色等级达标后给邀请人的邀请奖励是两笔独立业务。

账号延期规则固定为基础套餐 `10` 点兑换 `11` 天，额外配额 `N` 点增加 `N` 天；延期起点为 `max(当前时间, 当前账号 expire_time)`。扣点写入 `ga_user_point_transactions(type=quota_consume)`，后台赠送写入 `ga_user_point_transactions(type=admin_grant)`。

`ga_users.bound_role_id` 持久保存已绑定角色，删除游戏账号不会清除该绑定，避免同一平台用户重复换角色刷奖励。`ga_user_point_transactions` 有唯一索引 `uniq_invite_reward_user(type, related_user_id)`，用于从数据库层保证同一个被邀请账号不会重复产生邀请奖励流水；邀请奖励流水的 `related_role_id` 用于判断同一个游戏 `role_id` 全平台最多奖励一次。

`ga_user_point_transactions` 必须使用 InnoDB。邀请奖励会在同一个事务里同时更新邀请人余额、写点数流水、标记被邀请用户已奖励；如果历史库仍是 MyISAM，事务回滚和并发一致性都无法保证。`php scripts/sync_database.php` 会把该表引擎校正为 InnoDB。

后台“GameAssist用户/添加配额”只允许给产品用户 `ga_users.balance` 增加正整数点数，成功后还会写入 `ga_admin_operation_logs(action=gameassist_user.grant_quota)`，用于审计管理员操作。

`server/config/process.php` 注册的 `game_account_expiry_watcher` 会扫描 `ga_game_accounts.expire_time <= 当前时间` 且仍处于 `starting/running/reconnecting` 的账号，发送停止或标记停止，并清除 `desired_running`，避免到期账号被自动重连重新拉起。

运行普通日志不再写入旧表 `ga_game_account_logs`。真实普通日志按段存储在 `ga_game_account_log_segments`，事件卡片按段存储在 `ga_game_account_event_segments`，日志游标存储在 `ga_game_account_log_states`。`php scripts/sync_database.php` 会在旧 `ga_game_account_logs` 为空时删除它；如果非空会中止并提示人工确认，避免静默丢历史数据。

第三方任务数据使用 `ga_game_account_task_states` 保存每个游戏账号的最新落库快照；`task_state_save` 进入 Redis 队列时还会记录一份 pending 最新快照，供 `start.task_state` 和 `task_state_get` 优先读取，writer 落库后按 hash 精确清理 pending 快照。

`ga_system_settings.invite_reward_min_role_level` 控制邀请奖励最低角色等级，默认 `30`，只允许 `1` 至 `9999` 的整数。同步脚本只在配置缺失时补齐并更新说明，不覆盖后台保存值。旧库中的 `invite_daily_limit`、`invite_same_ip_daily_limit` 行为便于回滚可以保留，但新代码、新库和同步脚本不再读取或创建，邀请奖励不再受每日数量或 IP 限制。

`ga_system_settings.game_config_visibility_overrides` 保存后台“游戏配置项管理”相对正式默认值的全站可见性覆盖，值为“配置路径 => 布尔值”的 JSON 对象，默认 `{}`。完整 196 行目录由 `client/src/utils/gameConfigSchema.js` 生成到 `shared/game-config-visibility.json`，不能在数据库里另建一份字段目录；损坏 JSON、未知路径、非布尔值或“控制项隐藏但依赖项显示”的矛盾状态会明确报错。后台打开或关闭控制项时会递归联动依赖项，打开依赖项时也会同步打开所需控制项。该设置只控制用户端显示，不修改 `ga_game_accounts.config_json`，也不进入第三方配置响应。`php scripts/sync_database.php` 会补齐该设置，`php scripts/sync_admin.php` 会同步后台菜单。

第三方 WebSocket 使用连接池配置：`third_party_ws_urls` 每行一个连接槽位，`third_party_ws_connection_capacity` 控制单条连接最大承载账号数，默认 `10`。旧 `third_party_ws_url` 仅用于未配置多地址列表时兼容单地址。

后台“第三方连接”菜单来自 `server/plugin/admin/config/menu.php`，同步后写入 `wa_rules`。该页面不存储连接状态到 MySQL；槽位级运行状态由常驻 worker 写入 Redis：`gameassist:third_party_ws:slots:{slot_id}`。账号级运行状态仍写入 `gameassist:third_party_ws:accounts:{account_id}`。

后台菜单不写假数据，真实来源是 webman-admin 的 `plugin/admin/config/menu.php`，同步后会写入 `wa_rules`。

用户注册需要邮箱验证码。验证码存储在 Redis，邮件通过 `ga_system_settings` 中的 SMTP 配置发送；`smtp_enabled` 未启用或配置不完整时，发送验证码接口会明确失败。

公告正文每行可选颜色前缀：`[red]`、`[green]`、`[blue]`；无前缀为默认色。后端会拒绝不支持的颜色前缀，不允许用 HTML 代替真实结构化内容。
