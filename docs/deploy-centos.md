# CentOS 部署说明

本文档用于把 Hoa Quán 部署到外网 CentOS 服务器。当前项目结构是：

- `server`：webman 后端，生产环境用 `php start.php start -d` 常驻运行。
- `client`：uni-app H5，生产环境构建到 `client/dist/build/h5`，由 Nginx 托管。
- `deploy/server_update.sh`：服务器一键更新脚本。

后端默认监听 `0.0.0.0:8790`，用于避开服务器上已有的 `7272`、`7273`、`8787`、`8788` 端口。

## 服务器依赖

需要提前安装：

- PHP 8.1+
- Composer
- MySQL 或 MariaDB
- Redis
- Node.js 20+ 和 npm
- Nginx
- Git

webman 是常驻进程，不走 PHP-FPM。

## 首次部署

示例部署目录：

```bash
mkdir -p /www/wwwroot
cd /www/wwwroot
git clone https://github.com/a625361278-cpu/hoaquan.git hoaquan
cd hoaquan
```

第一次部署先创建数据库并导入初始表：

```bash
mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS gameassist DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -uroot -p gameassist < database/gameassist.sql
```

复制环境变量示例文件，并填写服务器真实连接信息：

```bash
cp server/.env.example server/.env
vi server/.env
```

然后执行一键部署脚本：

```bash
bash deploy/server_update.sh
```

不要把 `server/.env`、真实 MySQL 密码、Redis 密码、SMTP 授权码提交到 Git。SMTP 配置应在服务器数据库 `ga_system_settings` 中维护。

## Nginx 示例

把 `server_name` 改成你的域名：

```nginx
server {
    listen 80;
    server_name example.com www.example.com;

    root /www/wwwroot/hoaquan/client/dist/build/h5;
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
}
```

检查并重载 Nginx：

```bash
nginx -t
systemctl reload nginx
```

## 后续一键更新

服务器进入项目目录执行：

```bash
cd /www/wwwroot/hoaquan
bash deploy/server_update.sh
```

脚本会执行：

1. 检查服务器工作区是否有未提交修改。
2. `git pull --ff-only origin master`
3. `composer install --no-dev`
4. `npm ci && npm run build:h5`
5. `php scripts/sync_database.php`
6. `php scripts/sync_admin.php`
7. `php start.php restart -d`
8. `php start.php status`

可选参数：

```bash
BRANCH=master bash deploy/server_update.sh
SKIP_CLIENT_BUILD=1 bash deploy/server_update.sh
SKIP_DB_SYNC=1 bash deploy/server_update.sh
```

## 常用后端命令

```bash
cd /www/wwwroot/hoaquan/server
php start.php start -d
php start.php restart -d
php start.php status
php start.php stop
```

## 访问地址

- 用户端：`http://你的域名/`
- 后台：`http://你的域名/app/admin/`

未登录访问 `/api/me` 返回业务错误是正常的；如果返回 `502`，优先检查 webman 是否运行。
