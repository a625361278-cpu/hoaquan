# hoavienpro.com 线上更新 SOP

本文档只适用于当前正式站点 `hoavienpro.com`。这是线上真实更新流程，不是通用新机部署流程。

## 固定环境

- 服务器：`172.235.255.126`
- 项目目录：`/data/www/hoavienpro`
- 后端入口：`/data/www/hoavienpro/server/start.php`
- H5 静态目录：`/data/www/hoavienpro/client/dist/build/h5`
- Web 服务：Caddy
- 后端 HTTP：`127.0.0.1:8790`
- 用户端日志 WebSocket：`127.0.0.1:8791`
- 第三方脚本 GatewayWorker：`127.0.0.1:8792`

## 禁止事项

- 不要把当前站点当成 Git 工作区执行 `git pull`。
- 不要直接套用 `deploy/server_update.sh`，它只适合服务器本身就是 Git 工作区的旧部署方式。
- 不要覆盖线上 `server/.env`，里面是正式数据库、Redis、密钥和后台配置。
- 不要上传 `client/node_modules`、`server/vendor`、`server/runtime`、`.git`、`.codex-remote-attachments`。
- 不要在服务器构建 H5。前端在本地构建后上传产物。
- 不要为了让更新通过而跳过 `php scripts/sync_database.php`；数据库结构改动必须同步，但脚本不会覆盖已有正式配置值。

## 更新前确认

在本地确认当前改动范围：

```bash
git status --short
git diff --name-only
```

如果改动包含以下内容，需要特别处理：

- `client/src/**`、`client/package*.json`、配置 schema 或语言包影响用户端展示：必须本地执行 `npm run build:h5` 并上传 H5 产物。
- `server/composer.json` 或 `server/composer.lock`：服务器需要执行 `composer install --no-dev --prefer-dist --optimize-autoloader`。
- `server/scripts/sync_database.php`、`database/gameassist.sql` 或模型字段：服务器需要执行 `php scripts/sync_database.php`。
- `server/plugin/admin/config/menu.php`、后台菜单或后台文案：服务器需要执行 `php scripts/sync_admin.php`。

## 本地构建 H5

只要用户端或配置页展示有变化，就在本地执行：

```bash
cd client
npm run build:h5
```

构建产物目录：

```text
client/dist/build/h5
```

## 上传文件

上传源码和 H5 产物到：

```text
/data/www/hoavienpro
```

上传时必须排除：

```text
.git
.codex-remote-attachments
client/node_modules
server/vendor
server/runtime
server/.env
```

如果使用压缩包上传，压缩包里不要包含上述目录或文件。解压时不要先清空 `/data/www/hoavienpro`，避免误删线上 `.env`、`vendor`、`runtime` 或上传中断导致站点不可用。

## 服务器执行

进入后端目录：

```bash
cd /data/www/hoavienpro/server
```

如果 Composer 依赖有变化：

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

如果 Composer 依赖没有变化，至少刷新 autoload：

```bash
composer dump-autoload -o
```

同步数据库和后台菜单：

```bash
php scripts/sync_database.php
php scripts/sync_admin.php
```

重启常驻进程：

```bash
php start.php restart -d
php start.php status
```

如果原进程没有启动，改用：

```bash
php start.php start -d
php start.php status
```

## 成功标准

`php start.php status` 至少要看到以下进程为 `[OK]`：

- `webman`
- `game_account_log_ws`
- `game_log_writer`
- `game_task_state_writer`
- `game_account_auto_restarter`
- `plugin.webman.gateway-worker.gateway`
- `plugin.webman.gateway-worker.worker`
- `plugin.webman.gateway-worker.register`

接口检查：

```bash
curl -s http://127.0.0.1:8790/api/auth/config
curl -I -H "Host: hoavienpro.com" http://127.0.0.1/
```

用户端检查：

- 打开 `http://hoavienpro.com/`
- 登录用户端
- 打开后台 `http://hoavienpro.com/app/admin/`
- 后台“脚本连接”页能正常打开
- 如果本次改了用户端页面，确认浏览器里看到的是新 H5 产物

## Caddy 反向代理要求

Caddy 配置需要等价包含以下规则：

```caddyfile
hoavienpro.com {
    root * /data/www/hoavienpro/client/dist/build/h5
    try_files {path} /index.html
    file_server

    reverse_proxy /api/* 127.0.0.1:8790
    reverse_proxy /app/admin* 127.0.0.1:8790
    reverse_proxy /ws/game-accounts/* 127.0.0.1:8791
    reverse_proxy /ws/third-party/script* 127.0.0.1:8792
}
```

修改 Caddy 配置后检查并重载：

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

## 数据库同步说明

`php scripts/sync_database.php` 的职责是补齐缺失表、字段、索引和系统配置项。

生产注意：

- 已存在的 `ga_system_settings.value` 不应被脚本覆盖。
- 正式数据库账号、Redis 密码、SMTP、第三方脚本 Token、游戏账号加密密钥都应该保留线上值。
- 如果同步脚本报错，不要用默认值绕过，要先确认字段、索引或数据状态的真实原因。

## 常见错误

### 页面没变

通常是只上传了源码，没上传本地构建后的 `client/dist/build/h5`。

处理：

```bash
cd client
npm run build:h5
```

然后重新上传 `client/dist/build/h5`。

### 启动账号提示服务器未启用

检查后台“运行服务配置”或数据库 `ga_system_settings`：

- `third_party_enabled=1`
- `third_party_script_token` 非空
- `third_party_script_ws_url=ws://hoavienpro.com/ws/third-party/script`

还要确认第三方脚本已用 Token 主动连接，后台“脚本连接”页存在空闲连接。

### WebSocket 连接不上

检查顺序：

```bash
cd /data/www/hoavienpro/server
php start.php status
caddy validate --config /etc/caddy/Caddyfile
systemctl status caddy
```

确认：

- GatewayWorker 进程为 `[OK]`
- Caddy 已代理 `/ws/third-party/script*` 到 `127.0.0.1:8792`
- 第三方使用的地址是 `ws://hoavienpro.com/ws/third-party/script?token=后台脚本池Token`

### 语言包或配置页异常

如果改过语言包、配置 schema 或资产 ID，更新前至少检查：

- `server/resource/translations/zh_CN/messages.json`
- `server/resource/translations/vi/messages.json`
- `docs/vi-messages-pending-translation.json`
- `docs/third-party-game-config.schema.json`
- `client/src/utils/gameConfigSchema.js`
- `client/src/utils/gameAssetOptions.js`
- `client/src/utils/gameElfOptions.js`

语言包 key 不一致、JSON 格式错误、配置字段漏同步都应该直接暴露并修复，不要用默认文案或空列表掩盖。
