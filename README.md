# Hoa Quán

Hoa Quán 是一个游戏助手基础骨架。当前版本只实现基础账号、后台、用户端首页和添加游戏账号预留入口，不包含具体游戏逻辑，也不会伪造第三方执行结果。

## 技术栈

- 后端：webman
- 后台：webman-admin
- 用户端：uni-app Vue3
- 数据库：MySQL，默认 `gameassist`
- 会话：Redis token，连接信息从 `server/.env` 读取

## 本地启动

后端：

```bash
cd server
php windows.php
```

同步后台品牌和原生菜单：

```bash
cd server
php scripts/sync_database.php
php scripts/sync_admin.php
```

用户端：

```bash
cd client
npm install
npm run dev:h5
```

默认本地后端地址为 `http://127.0.0.1:8790`。生产构建默认使用同域 `/api` 反向代理。

## 服务器部署

CentOS 部署和一键更新脚本见 [docs/deploy-centos.md](docs/deploy-centos.md)。服务器后续更新可在项目根目录执行：

```bash
bash deploy/server_update.sh
```

## 当前业务边界

- 具体游戏、系统配置和第三方 API 尚未确定，因此不创建假游戏配置。
- 未添加游戏账号时，用户端只显示空状态和添加入口。
- 点击添加游戏账号会返回“当前游戏接入未开放”，不会保存虚假角色。
- 第三方接口默认未启用，任何同步请求都会明确失败。
- 用户注册和找回密码都需要邮箱验证码；SMTP 未启用或配置不完整时，发送验证码会明确失败。
- 用户端“点数充值”是申请支付接口用的临时展示入口，只在当前页打开小窗口；点击“立即支付”才打开 `/static/temp-payment-apply.html#/` 静态页并显示“待接入充值”，不创建订单、不修改点数，正式支付接入后需要删除或替换。

## 本地账号

- 后台：`admin / admin123`
- 用户端：`player001 / 123456`
- 用户端支持邮箱验证码注册真实账号，注册数据写入 `ga_users`。
- 用户端支持通过“用户名 + 注册邮箱 + 邮箱验证码”重置密码，重置成功后需要重新登录。

账号来自数据库初始化脚本，不是前端假数据。

## 后台说明

- 后台采用 webman-admin 作为正式后台方案，后台名称为 `Hoa Quán 后台`。
- 后台登录页、侧边栏和浏览器标签图标统一使用 `gameassist-logo.svg`。
- 后台菜单来自 `plugin/admin/config/menu.php`，通过 `server/scripts/sync_admin.php` 同步到 `wa_rules`。
- 管理员角色保留 `rules='*'`，用于访问 webman-admin 原生的数据库、权限管理、会员管理、通用设置等基础功能。

## SMTP 配置

注册验证码和找回密码验证码都使用 `ga_system_settings` 中的 SMTP 配置，并按用途分别写入 Redis，二者不能混用。未启用或配置不完整时，发送验证码接口会明确失败。

QQ 邮箱常用配置示例：

- `smtp_enabled`：`1`
- `smtp_host`：`smtp.qq.com`
- `smtp_port`：`465`
- `smtp_encryption`：`ssl`
- `smtp_username`：你的完整 QQ 邮箱
- `smtp_password`：QQ 邮箱授权码
- `smtp_from_email`：你的完整 QQ 邮箱
- `smtp_from_name`：`Hoa Quán`
