# Hoa Quán

Hoa Quán 是一个游戏助手基础骨架。当前版本实现基础账号、后台、多游戏账号卡片、游戏配置保存、WebSocket 常驻 worker 启动/停止第三方连接和运行日志存储；第三方 WebSocket 未配置或拒绝登录时会明确失败，不伪造执行结果。

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

如果是直接把代码文件夹或压缩包拷贝给别人部署，不依赖 Git 仓库，使用 [docs/code-copy-deploy.md](docs/code-copy-deploy.md)。

## 当前业务边界

- 具体游戏第三方协议尚未最终确定；添加游戏账号只保存渠道、游戏账号和加密后的游戏密码，不伪造登录成功或选服成功。
- 游戏账号密码使用 `game_account_credential_key` 或环境变量 `GAME_ACCOUNT_CREDENTIAL_KEY` 加密保存；密钥缺失时拒绝保存账号。
- 未添加游戏账号时，用户端显示原版风格的添加卡片。
- 游戏账号会写入 `ga_game_accounts`，启动成功前不代表第三方登录成功、选服成功或角色信息已同步。
- 等级、水滴、元宝、金币、订单统计等运行数据不落本地库；未启动或未获取到第三方数据时用户端显示 `0`。
- 游戏配置可以保存到本地 `config_json`，同步状态为 `local_unsynced`；启动时会读取这份 JSON 发送给第三方。
- 用户端点击“保存”会把游戏配置页当前完整配置保存为 `ga_game_accounts.config_json`；第三方读取时返回同一份 JSON，不生成假配置、不省略 `false` 或 `0`。
- 用户端每次进入游戏配置页都会展示“配置修改流程”提示，提醒先停止程序、修改配置、保存配置、再启动程序；该提示只说明流程，不替代真实保存接口。
- 用户端个人中心按原版结构展示邀请码、推广人数、我的点数、邀请链接复制、卡密兑换、福利卡和交易历史；角色绑定不需要用户手动填写，游戏账号启动成功后自动绑定；卡密/福利卡尚未接入真实发卡系统时会明确报错或显示真实空状态。
- 每个 `ga_users` 用户拥有唯一 `invite_code`。邀请链接进入注册页时会携带邀请码；注册只记录邀请关系，不立即发奖励。
- 邀请奖励触发条件是被邀请的新用户添加游戏账号并启动成功。第三方 `started` 回包确认后，系统自动绑定角色并发放奖励；角色标识优先使用 `third_party_account_id`，其次使用 `role_id`、`display_name`、游戏登录账号。
- 邀请奖励为邀请人 `balance + 1` 点，并写入 `ga_user_point_transactions`；同一邀请人每日奖励上限由 `ga_system_settings.invite_daily_limit` 控制，默认 `50`；同邀请人同 IP 每日奖励风控上限由 `invite_same_ip_daily_limit` 控制，默认 `3`。
- `ga_user_point_transactions` 通过 `uniq_invite_reward_user(type, related_user_id)` 在数据库层限制同一个被邀请账号只能产生一次邀请奖励流水。
- 第三方正式接入固定使用 WebSocket：用户端启动接口只提交启动任务，webman 常驻 worker 通过第三方长连接池发送账号启动包，并等待真实 `started` 回包后才标记为运行中。
- 第三方接口默认未启用，启动、加配额和配置同步请求都会明确失败；停止会提交停止任务并清理本地运行状态和当前日志。
- 运行日志由服务端按游戏账号维护，每个账号只保留最近 `2500` 条；账号仍运行时浏览器关闭后再登录仍可查看，账号停止或删除后清空当前日志。
- 用户注册和找回密码都需要邮箱验证码；SMTP 未启用或配置不完整时，发送验证码会明确失败。
- 登录公告来自后台 `ga_announcements` 的最新启用记录；没有启用公告时用户端不弹窗，不使用前端假公告。
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
- 后台“GameAssist用户”管理针对产品用户表 `ga_users`，不是后台账号或 webman-admin 的 `wa_users`。
- 后台仪表盘的今日注册、7日注册、30日注册和总用户数均统计 `ga_users.created_at`，不使用后台用户表。
- GameAssist 用户后台只允许查看、启用/禁用和重置密码；余额 `balance` 与到期日 `expire_at` 只读展示，不能通过普通用户管理表单修改。
- 后台“公告管理”维护登录公告表 `ga_announcements`。公告支持中越标题和正文、启用/停用、发布时间；正文每行可用 `[red]`、`[green]`、`[blue]` 前缀控制用户端颜色，不支持任意 HTML。

## 邀请与个人中心

- 个人中心接口：`GET /api/profile`，需要 Bearer token，返回用户信息、邀请码、邀请链接、推广人数、福利卡空状态和最近点数流水。
- 角色绑定没有用户端手动接口。用户添加游戏账号并启动成功后，`ThirdPartyConnectionWorker` 收到第三方 `started` 回包，会自动把该角色绑定到当前用户。
- 自动绑定成功后，如果当前用户来自有效邀请且邀请人当天未超过上限，系统即时给邀请人增加 `1` 点配额并记录流水。
- 卡密兑换接口当前为 `POST /api/profile/redeem-card`，在真实发卡系统接入前会返回明确错误，不创建假流水、不修改点数。

## 用户端公告接口

- 用户端登录或注册成功进入首页后，会请求 `GET /api/announcements/latest`。
- 接口需要 Bearer token；返回 `data.announcement=null` 表示当前没有启用公告。
- 有公告时返回 `id`、`title`、`content_blocks`、`published_at`；`content_blocks` 为纯文本块，颜色只允许 `default`、`red`、`green`、`blue`。
- 已登录 token 直接打开首页不会重复触发“每次登录公告”；只有本次登录/注册成功后的首页会弹一次。

## 第三方配置接口

- 第三方正式通信已固定为 WebSocket，配置项 `third_party_transport` 仅保留旧配置兼容，不作为正式 HTTP 接入开关。
- WebSocket 模式需要配置 `third_party_ws_urls`，每行一个第三方长连接槽位；旧 `third_party_ws_url` 仅作为未配置多地址列表时的兼容来源。
- 单条连接承载账号数由 `third_party_ws_connection_capacity` 控制，默认 `10`。连接池满时启动会明确失败，不进入本地预览或伪造运行中。
- 常驻进程：`server/config/process.php` 中的 `third_party_connection_worker`，默认 1 个进程。它使用 Redis 指令队列 `gameassist:third_party_ws:commands`，连接状态写入 `gameassist:third_party_ws:accounts:{account_id}`，并在状态中记录账号所在连接槽位 `slot_id`。
- 用户端启动账号：`POST /api/game-accounts/{id}/start`。后端校验账号、验证密码可解密、读取本地配置 JSON，把状态改为 `starting` 并写入启动任务；接口成功只表示任务已提交，不表示第三方登录成功。
- 用户端停止账号：`POST /api/game-accounts/{id}/stop`。后端写入停止任务，把本地状态改为 `stopped`，并清空当前账号运行日志。
- 第三方读取游戏配置：`GET /api/third-party/game-accounts/{id}/config`。
- 第三方 WebSocket start/stop/started/log/status/error/stopped 协议、完整配置 JSON 示例和字段说明见 [docs/third-party-game-config.md](docs/third-party-game-config.md)。同一连接可承载多个账号，所以所有账号相关消息都必须带 `account_id`；JSON Schema 见 [docs/third-party-game-config.schema.json](docs/third-party-game-config.schema.json)，它是可选机器校验文件，不是实际传输数据。
- 第三方主动写日志：`POST /api/third-party/game-accounts/{id}/logs`，body 为 `{"logs":["..."]}` 或 `{"lines":["..."]}`。
- 第三方接口需要先在 `ga_system_settings` 配置 `third_party_enabled=1` 和 `third_party_sign_secret`。
- 请求头必须包含：
  - `X-Timestamp`：当前 Unix 时间戳，5 分钟内有效。
  - `X-Signature`：`hash_hmac('sha256', "{METHOD}\n{PATH}\n{timestamp}", third_party_sign_secret)`。
- 返回结构中的 `data.config` 就是保存到 `ga_game_accounts.config_json` 的配置 JSON，供第三方按原样读取。
- 用户端查看日志优先尝试本地 `/ws/game-accounts/{id}/logs`，不可用时切换为 `GET /api/game-accounts/{id}/logs?lastLine=0` HTTP 轮询。

## 多语言

- 后台和用户端支持 `zh_CN`、`vi` 两种语言，文案来自统一 JSON 语言包：`server/resource/translations/{zh_CN,vi}/messages.json`。
- 用户端通过 `/api/i18n/messages?locale=zh_CN|vi` 拉取语言包，所有业务 API 请求会携带 `X-Locale`。
- 后端语言选择优先级：`?lang=` / `?locale=`、`X-Locale` 请求头、`gameassist_locale` cookie、默认 `zh_CN`。
- 后台和用户端都提供语言切换入口；切换后会保存到本地存储或 cookie。
- 用户端游戏配置页的分组标题、配置项名称、问号说明、导入/保存提示都必须从语言包读取；礼仪分监控开启后才显示 `basic.reputation.threshold` 礼仪分阈值字段。
- 用户端游戏配置页的种植、订单、公会、活动分组和问号说明按原版页面复刻；原版没有说明的开关不显示问号，不用占位文案伪装。
- 用户端游戏配置页按原版支持开关展开子配置，例如自动种植展开加速/水滴/任务优先级/种植模式，订单展开数量上限和品质限定，公会展开分享/摸花/竞赛规则，活动展开领取体力、速度、重开、开箱等专项配置。
- 给越南客户翻译时，只提供 `server/resource/translations/vi/messages.json`。客户只能替换 value，不能删除、改名或新增 key。
- webman 是常驻进程，语言包会按文件修改时间自动刷新；覆盖 JSON 后无需改代码，但生产环境仍建议执行一次 `php start.php reload` 或重启服务，便于确认进程状态一致。
- 校验语言包完整性：

```bash
cd server
vendor/bin/phpunit tests/Feature/I18nServiceTest.php
```

- 若 `zh_CN` 与 `vi` key 不一致、value 为空或代码引用了不存在的翻译 key，测试或开发环境会直接暴露问题，不用默认文案掩盖。

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
