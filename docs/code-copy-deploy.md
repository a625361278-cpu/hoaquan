# Hoa Quán 代码拷贝部署文档

本文档用于“把完整代码文件夹或压缩包直接发给对方部署”的场景。部署方不需要 Git 仓库，不需要远程仓库地址，也不需要 `.git` 目录。

生产环境不要访问 `5173`。`5173` 是本地开发端口。正式部署应由 Nginx 托管 H5，并把 `/api/` 和 `/app/admin/` 反向代理到 webman 后端 `8790`。

## 一、代码包内容

交付代码包必须包含：

```text
server/
client/
database/
deploy/
docs/
README.md
```

代码包不应包含：

```text
server/.env
真实数据库密码
真实 Redis 密码
SMTP 授权码
node_modules/
```

代码包不建议包含：

```text
server/vendor/
client/dist/
```

原因：

- `server/vendor/` 应在目标服务器用 Composer 按目标 PHP 环境安装。
- `client/dist/` 应在目标服务器或发布机重新构建。
- 不能把本机依赖目录当作正式部署结果，否则会掩盖服务器扩展缺失等真实问题。

## 二、服务器环境

推荐系统：

```text
Linux / CentOS
```

必须安装：

```text
Nginx
MySQL 5.7+ 或 MariaDB
Redis
PHP 8.2
Composer
Node.js 20+
npm
unzip 或 tar
```

检查命令：

```bash
nginx -v
mysql --version
redis-cli --version
php -v
composer --version
node -v
npm -v
unzip -v
tar --version
```

## 三、PHP 扩展要求

必须启用以下 PHP 扩展：

```text
fileinfo
gd
mbstring
openssl
curl
pdo
pdo_mysql
redis
json
session
iconv
filter
ctype
```

一条命令检查核心扩展：

```bash
php -m | grep -E 'fileinfo|gd|mbstring|openssl|curl|pdo|pdo_mysql|redis|json|session|iconv|filter|ctype'
```

逐项检查：

```bash
php -m | grep '^fileinfo$'
php -m | grep '^gd$'
php -m | grep '^mbstring$'
php -m | grep '^openssl$'
php -m | grep '^curl$'
php -m | grep '^PDO$'
php -m | grep '^pdo_mysql$'
php -m | grep '^redis$'
php -m | grep '^json$'
php -m | grep '^session$'
php -m | grep '^iconv$'
php -m | grep '^filter$'
php -m | grep '^ctype$'
```

安装 Composer 依赖后，再用 Composer 校验平台要求：

```bash
cd /data/www/hoaquan/server
composer check-platform-reqs --no-dev
```

如果 Composer 报错 `ext-fileinfo`、`ext-gd`、`ext-redis` 或 `ext-pdo_mysql` 缺失，必须先安装或启用对应 PHP 扩展，再重新安装依赖。不要用拷贝本地 `vendor` 的方式绕过错误。

### CentOS 常见安装命令

如果使用 yum 源安装 PHP 8.2，常见命令如下。不同服务器源名称可能不同，按实际 PHP 源调整包名。

```bash
yum install -y php php-cli php-common php-fpm php-mysqlnd php-pdo php-gd php-mbstring php-openssl php-curl php-fileinfo php-pecl-redis
```

如果使用宝塔面板，建议在面板里进入：

```text
软件商店 -> PHP 8.2 -> 安装扩展
```

至少安装或启用：

```text
fileinfo
gd
redis
```

启用后重新检查：

```bash
php -m | grep -E 'fileinfo|gd|redis|pdo_mysql'
```

## 四、上传并解压代码

目标目录示例：

```text
/data/www/hoaquan
```

先创建目录：

```bash
mkdir -p /data/www
```

### 方式 1：scp 上传 zip 包

本地打包时不要带 `node_modules`、`server/.env`、`.git`：

```bash
zip -r hoaquan.zip hoaquan -x "hoaquan/.git/*" "hoaquan/server/.env" "hoaquan/client/node_modules/*" "hoaquan/server/vendor/*" "hoaquan/client/dist/*"
```

上传：

```bash
scp hoaquan.zip root@服务器IP:/data/www/
```

服务器解压：

```bash
cd /data/www
unzip hoaquan.zip
mv hoaquan /data/www/hoaquan
```

如果解压后目录已经叫 `hoaquan`，`mv` 可以不执行。

### 方式 2：scp 上传 tar.gz 包

本地打包：

```bash
tar --exclude='hoaquan/.git' --exclude='hoaquan/server/.env' --exclude='hoaquan/client/node_modules' --exclude='hoaquan/server/vendor' --exclude='hoaquan/client/dist' -czf hoaquan.tar.gz hoaquan
```

上传：

```bash
scp hoaquan.tar.gz root@服务器IP:/data/www/
```

服务器解压：

```bash
cd /data/www
tar -zxvf hoaquan.tar.gz
mv hoaquan /data/www/hoaquan
```

### 方式 3：宝塔面板上传

在宝塔文件管理中上传压缩包到：

```text
/data/www/
```

然后在宝塔里解压，确保最终项目目录是：

```text
/data/www/hoaquan
```

## 五、创建并导入数据库

创建数据库：

```bash
mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS gameassist DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;"
```

导入初始化 SQL：

```bash
mysql -uroot -p gameassist < /data/www/hoaquan/database/gameassist.sql
```

检查表：

```bash
mysql -uroot -p gameassist -e "SHOW TABLES;"
```

至少应看到：

```text
ga_users
ga_system_settings
wa_admins
wa_rules
```

## 六、配置 server/.env

复制示例文件：

```bash
cd /data/www/hoaquan
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
```

说明：

- `DB_DATABASE` 必须和导入 SQL 的数据库一致。
- Redis 如果已有其他服务使用，必须给 Hoa Quán 单独选一个逻辑库，例如 `9`、`10`、`11`。
- `WEBMAN_USER` 和 `WEBMAN_GROUP` 是 webman worker 的运行用户。宝塔常见用户是 `www`。
- 不要把真实 `.env` 发给别人，也不要写进文档。

设置权限：

```bash
chown -R www:www /data/www/hoaquan
chmod 600 /data/www/hoaquan/server/.env
```

检查配置是否存在：

```bash
ls -l /data/www/hoaquan/server/.env
grep '^DB_DATABASE=' /data/www/hoaquan/server/.env
grep '^REDIS_DATABASE=' /data/www/hoaquan/server/.env
```

## 七、检查 Redis 独立库

无密码 Redis：

```bash
redis-cli -h 127.0.0.1 -p 6379 -n 9 PING
redis-cli -h 127.0.0.1 -p 6379 -n 9 DBSIZE
```

有密码 Redis：

```bash
REDISCLI_AUTH='你的Redis密码' redis-cli -h 127.0.0.1 -p 6379 -n 9 PING
REDISCLI_AUTH='你的Redis密码' redis-cli -h 127.0.0.1 -p 6379 -n 9 DBSIZE
```

如果 `DBSIZE` 不是 0，说明这个逻辑库已有数据。为了避免和其他项目混用，可以换一个空库，并同步修改：

```text
server/.env 的 REDIS_DATABASE
```

## 八、安装后端依赖

进入后端目录：

```bash
cd /data/www/hoaquan/server
```

安装依赖：

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

校验平台要求：

```bash
composer check-platform-reqs --no-dev
```

检查 autoload：

```bash
php -r "require 'vendor/autoload.php'; echo 'autoload ok'.PHP_EOL;"
```

## 九、同步数据库结构和后台菜单

进入后端目录：

```bash
cd /data/www/hoaquan/server
```

同步业务表和初始数据：

```bash
php scripts/sync_database.php
```

同步后台品牌、后台菜单和管理员权限：

```bash
php scripts/sync_admin.php
```

同步完成后，后台菜单包含 GameAssist 用户管理入口；仪表盘注册统计来自 `ga_users`，不是后台账号或 `wa_users`。

默认后台账号：

```text
admin / admin123
```

上线后建议立即登录后台修改密码。

## 十、构建用户端 H5

进入前端目录：

```bash
cd /data/www/hoaquan/client
```

安装依赖：

```bash
npm ci
```

构建 H5：

```bash
npm run build:h5
```

构建产物目录：

```text
/data/www/hoaquan/client/dist/build/h5
```

如果没有域名或 Nginx，不要用浏览器直接打开 `index.html` 文件，也不要访问 `5173`。生产必须通过 Nginx 或其他 Web 服务访问。

## 十一、配置 Nginx

### 域名方式

把 `example.com` 换成实际域名：

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
}
```

### IP + 8900 临时端口方式

如果暂时没有域名，可用 `http://服务器IP:8900/`：

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
}
```

检查 Nginx 配置：

```bash
nginx -t
```

重载 Nginx：

```bash
systemctl reload nginx
```

如果没有使用 systemd：

```bash
nginx -s reload
```

## 十二、放行端口

如果使用域名和 80 端口：

```bash
firewall-cmd --permanent --add-port=80/tcp
firewall-cmd --reload
```

如果使用 HTTPS：

```bash
firewall-cmd --permanent --add-port=443/tcp
firewall-cmd --reload
```

如果临时使用 `8900`：

```bash
firewall-cmd --permanent --add-port=8900/tcp
firewall-cmd --reload
```

云服务器还需要在云厂商控制台安全组中放行对应端口。

## 十三、启动后端

进入后端目录：

```bash
cd /data/www/hoaquan/server
```

启动：

```bash
php start.php start -d
```

查看状态：

```bash
php start.php status
```

重启：

```bash
php start.php restart -d
```

停止：

```bash
php start.php stop
```

正常状态应看到 webman 监听：

```text
http://0.0.0.0:8790
```

## 十四、上线验证

检查后端接口：

```bash
curl -i http://127.0.0.1:8790/api/me
```

未登录时返回下面这种业务响应是正常的：

```json
{"code":401,"msg":"登录已失效，请重新登录","data":null}
```

检查 Nginx：

```bash
curl -I http://127.0.0.1:8900/
```

外网访问：

```text
http://服务器IP:8900/
```

后台访问：

```text
http://服务器IP:8900/app/admin/
```

如果使用域名，把上面的 IP 和端口换成实际域名。

## 十五、访问地址说明

生产应访问：

```text
http://域名/
http://服务器IP:8900/
```

后台访问：

```text
http://域名/app/admin/
http://服务器IP:8900/app/admin/
```

不要访问：

```text
http://服务器IP:5173/
http://服务器IP:8790/
```

原因：

- `5173` 是本地开发端口，生产服务器不会启动这个端口。
- `8790` 是 webman 后端端口，建议只给 Nginx 内部反向代理。

## 十六、常见问题

### 1. Composer 提示缺少 `ext-fileinfo`

说明 PHP 没启用 `fileinfo`。先启用扩展：

```bash
php -m | grep '^fileinfo$'
```

确认有输出后重新安装：

```bash
cd /data/www/hoaquan/server
composer install --no-dev --prefer-dist --optimize-autoloader
```

### 2. Composer 提示缺少 `ext-redis`

说明 PHP 没启用 redis 扩展。启用后检查：

```bash
php -m | grep '^redis$'
```

再重新安装 Composer 依赖。

### 3. 页面打开但接口 502

检查后端是否运行：

```bash
cd /data/www/hoaquan/server
php start.php status
```

如果没有运行：

```bash
php start.php start -d
```

### 4. `http://服务器IP:8900/` 外网访问不了

先在服务器本机检查：

```bash
curl -I http://127.0.0.1:8900/
```

如果本机正常，外网不通，检查：

```bash
firewall-cmd --list-ports
```

并检查云服务器安全组是否放行 `8900`。

### 5. 登录或注册提示 Redis 错误

检查 Redis 配置：

```bash
grep '^REDIS_' /data/www/hoaquan/server/.env
```

测试 Redis：

```bash
redis-cli -h 127.0.0.1 -p 6379 -n 9 PING
```

如果有密码：

```bash
REDISCLI_AUTH='你的Redis密码' redis-cli -h 127.0.0.1 -p 6379 -n 9 PING
```

### 6. 邮箱验证码发送失败

注册和找回密码验证码依赖数据库里的 SMTP 配置。需要在 `ga_system_settings` 中配置：

```text
smtp_enabled
smtp_host
smtp_port
smtp_username
smtp_password
smtp_encryption
smtp_from_email
smtp_from_name
```

SMTP 未启用、配置缺失或授权码错误时，接口会明确失败，不会假装发送成功。

### 7. 修改 PHP 代码后不生效

webman 是常驻进程，需要重启：

```bash
cd /data/www/hoaquan/server
php start.php restart -d
```

### 8. 修改前端后不生效

重新构建 H5：

```bash
cd /data/www/hoaquan/client
npm run build:h5
```

然后刷新浏览器缓存。
