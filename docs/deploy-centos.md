# Hoa Quán CentOS 部署文档

本文档用于把 Hoa Quán 部署到一台新的 CentOS/Linux 服务器。项目由三部分组成：

- `server`：webman 后端，常驻进程，默认监听 `0.0.0.0:8790`
- `client`：uni-app H5，构建产物目录为 `client/dist/build/h5`
- `database`：MySQL 初始化 SQL

生产环境不要用 `5173` 访问。`5173` 是 Vite/uni-app 本地开发端口，只适合开发调试。外网部署应使用 Nginx 托管 H5，并把 `/api/` 和 `/app/admin/` 反向代理到后端 `8790`。

## 一、服务器要求

建议环境：

- Linux/CentOS
- PHP 8.2，必须启用 `fileinfo`
- Composer
- MySQL 5.7+ 或 MariaDB
- Redis
- Node.js 20+ 和 npm
- Nginx
- Git

检查命令：

```bash
php -v
php -m | grep fileinfo
composer --version
mysql --version
redis-cli --version
node -v
npm -v
nginx -v
git --version
```

如果 `php -m | grep fileinfo` 没有输出，先在服务器面板或 PHP 编译参数里启用 `fileinfo`。不要跳过这个扩展，因为 `webman-admin` 依赖的图片组件会校验它。

## 二、拉取代码

示例部署目录：

```bash
mkdir -p /data/www
cd /data/www
git clone https://github.com/a625361278-cpu/hoaquan.git hoaquan
cd /data/www/hoaquan
```

如果使用其他仓库地址，把上面的 Git 地址换成实际地址即可。

## 三、创建数据库

创建数据库并导入初始化 SQL：

```bash
mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS gameassist DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -uroot -p gameassist < database/gameassist.sql
```

导入后可以检查表是否存在：

```bash
mysql -uroot -p gameassist -e "SHOW TABLES;"
```

至少应能看到 `ga_users`、`ga_system_settings`、`wa_admins`、`wa_rules` 等表。

## 四、配置环境变量

复制示例文件：

```bash
cp server/.env.example server/.env
vi server/.env
```

填写示例：

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gameassist
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DATABASE=9
REDIS_PASSWORD=your_redis_password

WEBMAN_USER=www
WEBMAN_GROUP=www
GAME_LOG_WRITER_COUNT=8
```

说明：

- `DB_DATABASE` 必须是实际导入 SQL 的数据库名。
- Redis 如果服务器上已有其他项目使用，建议给 Hoa Quán 单独选一个逻辑库，例如 `9`、`10`、`11`。
- `WEBMAN_USER` 和 `WEBMAN_GROUP` 用于让 webman worker 以普通用户运行。宝塔常见用户是 `www`。
- `GAME_LOG_WRITER_COUNT` 是日志写入进程数，1 万游戏账号默认建议 `8`。日志队列固定 64 个分片，同一账号固定进入同一分片，避免多进程并发写乱顺序。
- 不要把 `server/.env` 提交到 Git。
- 不要把数据库密码、Redis 密码、SMTP 授权码写进 README 或部署文档。

设置权限：

```bash
chown -R www:www /data/www/hoaquan
chmod 600 /data/www/hoaquan/server/.env
```

## 五、安装后端依赖

```bash
cd /data/www/hoaquan/server
composer install --no-dev --prefer-dist --optimize-autoloader
```

如果 Composer 提示缺少 `ext-fileinfo`，说明 PHP 还没有启用 `fileinfo`，先修 PHP 环境，再重新执行 Composer。不要用提交本地 `vendor` 的方式绕过这个错误。

## 六、同步数据库和后台菜单

```bash
cd /data/www/hoaquan/server
php scripts/sync_database.php
php scripts/sync_admin.php
```

这两个脚本用于同步项目业务表结构、后台品牌、webman-admin 原生菜单和管理员权限。后台的 GameAssist 用户管理入口和仪表盘注册统计都以 `ga_users` 为准，不统计后台账号或 `wa_users`。

`sync_database.php` 只会为缺失的 `ga_system_settings` 配置项插入默认值；已有配置项会保留当前 `value`，只更新备注说明。生产环境里的第三方地址、SMTP、认证方式和 `game_account_credential_key` 不应在升级时被脚本覆盖。

默认后台账号：

```text
admin / admin123
```

首次上线后建议尽快登录后台修改密码。

## 七、构建用户端 H5

```bash
cd /data/www/hoaquan/client
npm ci
npm run build:h5
```

构建完成后产物在：

```text
/data/www/hoaquan/client/dist/build/h5
```

生产环境不要直接打开 `index.html` 文件，也不要使用 `5173`。H5 需要通过 Nginx 或其他 Web 服务访问，这样前端路由和接口代理才会正常。

## 八、启动后端

```bash
cd /data/www/hoaquan/server
php start.php start -d
php start.php status
```

正常状态会看到类似：

```text
webman http://0.0.0.0:8790 [OK]
game_log_writer [OK]
```

日志写入链路为 GatewayWorker 写入 Redis 分片队列 `gameassist:game_logs:queue:{shard}`，再由 `game_log_writer` 内存聚合后批量写入 MariaDB 分段表。普通日志默认 10 秒或 50 行刷库一次，事件日志默认 2 秒或 20 条刷库一次；后台“第三方连接”页可查看日志积压、最大分片积压、writer 数和最近写入状态。

常用命令：

```bash
php start.php restart -d
php start.php stop
php start.php status
```

webman 是常驻进程，修改 PHP 代码或配置后需要 `restart` 或 `reload`。

## 九、Nginx 配置

推荐使用域名部署，监听 `80` 或 `443`：

```nginx
server {
    listen 80;
    server_name example.com www.example.com;

    root /data/www/hoaquan/client/dist/build/h5;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8790;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /app/admin/ {
        proxy_pass http://127.0.0.1:8790;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /ws/game-accounts/ {
        proxy_pass http://127.0.0.1:8791;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /ws/third-party/script {
        proxy_pass http://127.0.0.1:7272;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

如果暂时没有域名，只想用 `http://服务器IP:8900/` 访问，可以让 Nginx 监听 `8900`：

```nginx
server {
    listen 8900;
    server_name _;

    root /data/www/hoaquan/client/dist/build/h5;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8790;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /app/admin/ {
        proxy_pass http://127.0.0.1:8790;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /ws/game-accounts/ {
        proxy_pass http://127.0.0.1:8791;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /ws/third-party/script {
        proxy_pass http://127.0.0.1:7272;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

如果使用 Caddy，保持路径原样反向代理即可：

```caddyfile
hoavienpro.com {
    root * /data/www/hoaquan/client/dist/build/h5
    file_server
    try_files {path} /index.html

    reverse_proxy /api/* 127.0.0.1:8790
    reverse_proxy /app/admin/* 127.0.0.1:8790
    reverse_proxy /ws/game-accounts/* 127.0.0.1:8791
    reverse_proxy /ws/third-party/script* 127.0.0.1:7272
}
```

检查并重载 Nginx：

```bash
nginx -t
systemctl reload nginx
```

如果使用云服务器安全组或系统防火墙，需要放行对应端口：

```bash
firewall-cmd --permanent --add-port=8900/tcp
firewall-cmd --reload
```

云厂商控制台的安全组也要同步放行 `80`、`443` 或 `8900`。

## 十、访问地址

域名模式：

- 用户端：`http://example.com/`
- 后台：`http://example.com/app/admin/`

IP 临时端口模式：

- 用户端：`http://服务器IP:8900/`
- 后台：`http://服务器IP:8900/app/admin/`

不要访问：

- `http://服务器IP:5173/`：这是本地开发端口，生产服务器通常不会运行。
- `http://服务器IP:8790/`：这是后端内部端口，建议只给 Nginx 反向代理使用。

## 十一、一键更新

项目提供更新脚本：

```bash
cd /data/www/hoaquan
bash deploy/server_update.sh
```

脚本会执行：

1. 检查 Git 工作区是否干净。
2. 拉取远程代码。
3. 安装后端 Composer 依赖。
4. 构建用户端 H5。
5. 同步数据库和后台菜单。
6. 重启 webman。
7. 输出 webman 状态。

可选参数：

```bash
BRANCH=master bash deploy/server_update.sh
SKIP_CLIENT_BUILD=1 bash deploy/server_update.sh
SKIP_DB_SYNC=1 bash deploy/server_update.sh
PHP_BIN=/www/server/php/82/bin/php bash deploy/server_update.sh
COMPOSER_BIN=composer bash deploy/server_update.sh
NPM_BIN=npm bash deploy/server_update.sh
```

如果服务器上有多个 PHP 版本，建议显式指定 `PHP_BIN`，并确保对应版本启用了 `fileinfo`。

## 十二、上线检查

后端进程：

```bash
cd /data/www/hoaquan/server
php start.php status
```

接口连通：

```bash
curl -i http://127.0.0.1:8790/api/me
```

未登录时返回下面这种业务响应是正常的：

```json
{"code":401,"msg":"登录已失效，请重新登录","data":null}
```

Nginx 连通：

```bash
curl -I http://127.0.0.1:8900/
```

如果用域名部署，把 `127.0.0.1:8900` 换成实际域名。

## 十三、常见问题

### 1. `http://服务器IP:5173/` 访问不了

正常。`5173` 是开发环境端口。生产环境访问 Nginx 监听的端口，例如 `80`、`443` 或临时配置的 `8900`。

### 2. `http://服务器IP:8900/` 访问不了

按顺序检查：

```bash
nginx -t
systemctl status nginx
ss -lntp | grep 8900
curl -I http://127.0.0.1:8900/
```

如果服务器本机能访问，外网不能访问，通常是云服务器安全组或防火墙没有放行 `8900`。

### 3. 页面打开了，但接口 502

检查 webman 是否运行：

```bash
cd /data/www/hoaquan/server
php start.php status
```

如果没有运行：

```bash
php start.php start -d
```

### 4. Composer 安装失败，提示缺少 `ext-fileinfo`

启用 PHP `fileinfo` 扩展后重新安装：

```bash
php -m | grep fileinfo
composer install --no-dev --prefer-dist --optimize-autoloader
```

不要把本地 `server/vendor` 提交到 Git 来绕过扩展缺失。

### 5. 登录或注册接口返回 Redis 错误

检查 `server/.env`：

```bash
grep '^REDIS_' /data/www/hoaquan/server/.env
```

再测试 Redis：

```bash
redis-cli -h 127.0.0.1 -p 6379 -n 9 PING
```

如果 Redis 设置了密码，用服务器真实密码测试，不要把密码写进命令历史或文档。

### 6. 认证方式与邮箱验证码

注册和找回密码默认使用密保问题，配置项为 `ga_system_settings.auth_verification_mode=security_question`。只有切换为 `email_code` 时才会使用邮箱验证码。

如果已切换到 `email_code`，注册和找回密码验证码依赖后台数据库里的 SMTP 配置。SMTP 未启用、配置缺失或授权码错误时，接口会明确失败，不会假装发送成功。

需要在 `ga_system_settings` 中配置：

- `auth_verification_mode=email_code`
- `smtp_enabled`
- `smtp_host`
- `smtp_port`
- `smtp_username`
- `smtp_password`
- `smtp_encryption`
- `smtp_from_email`
- `smtp_from_name`

### 7. 修改 PHP 代码后不生效

webman 是常驻进程，需要重启：

```bash
cd /data/www/hoaquan/server
php start.php restart -d
```

### 8. 服务器上已有其他 webman 服务

Hoa Quán 后端默认监听 `8790`，如果冲突，修改：

```text
server/config/process.php
```

同时更新 Nginx 里的 `proxy_pass` 端口。
