# 数据库初始化

默认库名为 `gameassist`，MySQL 连接信息从 `server/.env` 读取，不提交真实凭据。

初始化顺序：

1. 创建数据库：`CREATE DATABASE IF NOT EXISTS gameassist DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;`
2. 复制 `server/.env.example` 为 `server/.env`，填写本机 MySQL 和 Redis 连接信息。
3. 导入 `server/plugin/admin/install.sql`，创建 webman-admin 的 `wa_` 后台表。
4. 导入 `database/gameassist.sql`，创建 GameAssist 的 `ga_` 业务表和本地开发账号。
5. 执行 `cd server && php scripts/sync_database.php`，同步业务字段和 SMTP 配置项。
6. 执行 `cd server && php scripts/sync_admin.php`，同步后台品牌和 webman-admin 原生菜单。

本地开发账号：

- 后台：`admin / admin123`
- 用户端：`player001 / 123456`
- 用户端注册会写入 `ga_users`，不会生成假游戏账号。

`ga_game_accounts` 默认不插入任何游戏账号，用户端会显示真实空状态。

后台菜单不写假数据，真实来源是 webman-admin 的 `plugin/admin/config/menu.php`，同步后会写入 `wa_rules`。

用户注册需要邮箱验证码。验证码存储在 Redis，邮件通过 `ga_system_settings` 中的 SMTP 配置发送；`smtp_enabled` 未启用或配置不完整时，发送验证码接口会明确失败。
