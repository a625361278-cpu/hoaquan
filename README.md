# Hoa Quán

Hoa Quán 是面向用户的游戏助手系统，包含用户端、webman-admin 后台、游戏账号管理、游戏配置保存、GatewayWorker 入站脚本连接池、运行日志、事件卡片、任务状态快照和运行资源状态。所有关键流程都依赖真实数据和真实状态：第三方脚本未连接、服务未启用、配置缺失或账号密码不可解密时都会明确失败，不用假数据伪装成功。

## 技术栈

- 后端：webman / Workerman 常驻进程
- 后台：webman-admin
- 实时通信：GatewayWorker 入站 WebSocket、用户端日志 WebSocket
- 用户端：uni-app Vue3，当前正式构建目标为 H5
- 数据库：MariaDB / MySQL 兼容，默认库名 `gameassist`
- Redis：用户 token、第三方脚本连接池、运行资源快照、日志队列、任务状态队列
- 配置来源：敏感连接信息来自 `server/.env`，业务开关和后台可维护配置来自 `ga_system_settings`

## 本地启动

后端：

```bash
cd server
php windows.php
```

Linux / 生产环境使用：

```bash
cd server
php start.php start -d
php start.php status
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

当前正式域名是 `hoavienpro.com`，线上项目目录是 `/data/www/hoavienpro`。服务器不是直接依赖 Git 工作区更新，更新时不要覆盖远端 `server/.env`、`server/runtime`、`server/vendor` 等生产文件。H5 前端在本地构建后上传，后端源码上传到同一项目目录。

当前线上路径：

- 后端入口：`/data/www/hoavienpro/server/start.php`
- H5 静态目录：`/data/www/hoavienpro/client/dist/build/h5`
- Caddy 站点域名：`hoavienpro.com`
- GatewayWorker WebSocket：`/ws/third-party/script` 反向代理到本机 `8792`
- 用户端日志 WebSocket：`/ws/game-accounts/{id}/logs` 反向代理到本机 `8791`

线上后台登录：

- 后台地址：`http://hoavienpro.com/app/admin/`
- 初始化管理员账号：`admin`
- 初始化默认密码：`admin123`
- 账号来源：`database/gameassist.sql` 会向 `wa_admins` 写入 `admin` 管理员，密码以 bcrypt 哈希保存。
- 线上当前密码不能从仓库反推出；如果生产环境已经改过密码，以线上数据库 `wa_admins` 的实际哈希或后台重置结果为准，不把未经验证的密码当成线上事实。

常规更新流程：

```bash
cd client
npm run build:h5
```

上传本地源码和 `client/dist/build/h5` 后，在服务器执行：

```bash
cd /data/www/hoavienpro/server
composer dump-autoload -o
php scripts/sync_database.php
php scripts/sync_admin.php
php start.php restart -d
php start.php status
```

`php start.php status` 正常时应能看到 `webman`、`game_account_log_ws`、`game_log_writer`、`game_task_state_writer`、`game_account_auto_restarter` 以及 GatewayWorker 的 Gateway / BusinessWorker / Register 进程。

通用部署文档仍保留：

- 当前线上更新 SOP：[docs/deploy-hoavienpro.md](docs/deploy-hoavienpro.md)
- CentOS / Caddy 部署参考：[docs/deploy-centos.md](docs/deploy-centos.md)
- 代码拷贝部署参考：[docs/code-copy-deploy.md](docs/code-copy-deploy.md)
- `deploy/server_update.sh` 只适用于服务器本身就是 Git 工作区的旧式部署，不是当前 `hoavienpro.com` 的默认更新方式。

## 文档维护口径

- `README.md` 记录项目真实架构、部署方式、维护边界和内部运行口径。
- [docs/third-party-game-config.md](docs/third-party-game-config.md) 和 [docs/协议说明.txt](docs/协议说明.txt) 面向第三方，只写对外协议字段、消息格式和配置数据结构；不要把 Redis、数据库表、队列、进程数等内部实现写进第三方协议说明。
- 游戏配置 schema、中文语言包、越南语待翻译文件和第三方协议说明需要保持字段一致；如果配置项、控件类型或资产 ID 变化，要同步检查这些文件。

## 当前业务边界

- 第三方运行通道已固定为 WebSocket；游戏配置字段以当前 schema 和协议说明为准持续维护。添加游戏账号当前只支持 APP 渠道，只保存游戏账号和加密后的游戏密码，不伪造登录成功或选服成功。
- 游戏账号密码使用 `game_account_credential_key` 或环境变量 `GAME_ACCOUNT_CREDENTIAL_KEY` 加密保存；密钥缺失时拒绝保存账号。
- 未添加游戏账号时，用户端显示原版风格的添加卡片。
- 游戏账号会写入 `ga_game_accounts`，启动成功前不代表第三方登录成功、选服成功或角色信息已同步。
- 等级、水滴、元宝、金币、订单统计等运行资源由第三方通过 WebSocket `status` 推送，服务端按账号保存 Redis 最新快照；未启动、未收到推送或字段缺失时用户端显示默认值。
- 游戏配置可以保存到本地 `config_json`，同步状态为 `local_unsynced`；启动时会读取这份 JSON 发送给第三方。
- 用户端点击“保存”会把游戏配置页当前完整配置保存为 `ga_game_accounts.config_json`；第三方读取时返回同一份 JSON，不生成假配置、不省略 `false` 或 `0`。
- 用户端点击“导入”可从同一用户的其他已配置游戏账号复制配置；确认后会立即覆盖当前账号的 `ga_game_accounts.config_json`，只复制配置 JSON，不复制游戏账号、密码、区服、运行状态或日志。
- 用户端每次进入游戏配置页都会展示“配置修改流程”提示，提醒先停止程序、修改配置、保存配置、再启动程序；该提示只说明流程，不替代真实保存接口。
- 用户端个人中心展示邀请码、推广人数、我的点数、邀请链接复制、角色绑定提示、修改密码和交易历史；角色绑定不需要用户手动填写，游戏账号启动成功后自动绑定。卡密兑换、福利卡和售后群未接入真实业务，用户端不展示入口，后端也不暴露不可用接口。
- 每个 `ga_users` 用户拥有唯一 `invite_code`。邀请链接进入注册页时会携带邀请码；注册只记录邀请关系，不立即发奖励。
- 邀请奖励触发条件是被邀请的新用户添加游戏账号并启动成功。第三方 `started` 回包确认后，系统自动绑定角色并发放奖励；角色唯一标识只使用 `role_id`，第三方未返回时使用用户填写的 `game_username`，`display_name` 仅用于展示。
- 邀请奖励为邀请人 `balance + 1` 点，并写入 `ga_user_point_transactions`；同一被邀请用户最多奖励一次，同一个 `role_id` 全平台最多奖励一次，同一邀请人每日奖励上限由 `ga_system_settings.invite_daily_limit` 控制，默认 `50`；同邀请人同 IP 每日奖励风控上限由 `invite_same_ip_daily_limit` 控制，默认 `3`。
- `ga_users.bound_role_id` 持久保存已绑定角色，删除游戏账号不会清除用户已绑定角色；`ga_user_point_transactions` 通过 `uniq_invite_reward_user(type, related_user_id)` 在数据库层限制同一个被邀请账号只能产生一次邀请奖励流水。
- 用户 `ga_users.balance` 是可分配配额余额。用户给某个游戏账号延期时会立即扣余额并写入 `ga_game_accounts.expire_time`，不依赖账号是否启动；删除账号不退还已分配配额。
- 游戏账号延期固定使用基础套餐 `10` 点兑换 `11` 天，额外配额 `N` 点增加 `N` 天；总扣点 `10 + N`，总天数 `11 + N`，延期起点为 `max(当前时间, 当前账号 expire_time)`。扣点流水写入 `ga_user_point_transactions(type=quota_consume)`。
- 第三方正式接入固定使用 WebSocket：第三方脚本主动连接我方 GatewayWorker，用户端启动接口会从空闲脚本连接中分配一个连接给当前账号，并等待 `request_id/session_id` 匹配且账号仍允许运行的真实 `started` 回包后才标记为运行中。
- 运行服务未启用或未准备好时，启动和配置同步请求都会明确失败；用户端启动未启用时返回“服务器未启用，请联系管理员”，没有空闲脚本连接时返回“服务器未准备好，请联系管理员”，账号不进入 `starting`。
- 启动账号前必须满足 `expire_time > 当前时间`。未配置或已到期会明确失败，不进入 `starting`，不占用第三方脚本连接。
- 玩家启动账号后会写入 `desired_running=1`。如果第三方连接异常断开或我方服务重启导致绑定丢失，账号进入 `reconnecting`，由 `game_account_auto_restarter` 在有空闲脚本连接时重新发送幂等 `start` 包；只有用户手动停止或第三方明确返回 `error` 时才清除运行意图。
- `game_account_expiry_watcher` 会定期扫描已到期且仍处于 `starting/running/reconnecting` 的账号；有脚本绑定时发送 `stop` 并标记 `stopping + desired_running=0`，无绑定时标记 `stopped + desired_running=0`，同时写入“配额到期停止”日志，阻止自动重连继续拉起。
- 运行日志分为普通日志和事件卡片历史。普通日志按本次运行会话保存，启动新会话和主动停止后清空；事件卡片历史按账号保留，跨停止/重启保留。两类数据每个账号各最多保留 `2500` 条。
- 日志写入按 1 万游戏账号设计：GatewayWorker 只把日志写入 64 个 Redis 分片队列，`game_log_writer` 默认 8 个进程按分片消费、内存聚合、批量落库。普通日志默认 10 秒或 50 行刷库一次，事件日志默认 2 秒或 20 条刷库一次；用户端读取结构不变，但普通日志可能有几秒延迟。
- 第三方任务数据通过当前已绑定账号的 WebSocket 连接读写，不提供 HTTP 接口，不传 `account_id`。保存消息先写入 Redis 队列，由 `game_task_state_writer` 聚合后批量入库；我方只保存每个游戏账号的最新 JSON 快照，停止、重启、自动重连不清空，删除游戏账号时清空；单账号默认上限 `256KB`，可通过 `GAME_TASK_STATE_MAX_BYTES` 调整。
- 用户注册和找回密码默认使用密保问题；`auth_verification_mode=email_code` 时才启用邮箱验证码。邮箱模式下 SMTP 未启用或配置不完整时，发送验证码会明确失败。
- 登录公告来自后台 `ga_announcements` 的最新启用记录；没有启用公告时用户端不弹窗，不使用前端假公告。
- 用户端“点数充值”是申请支付接口用的临时展示入口，只在当前页打开小窗口；点击“立即支付”才打开 `/static/temp-payment-apply.html#/` 静态页并显示“待接入充值”，不创建订单、不修改点数，正式支付接入后需要删除或替换。

## 本地账号

- 后台：`admin / admin123`
- 用户端：`player001 / 123456`
- 用户端默认通过“用户名 + 密码 + 密保问题 + 密保答案”注册真实账号，注册数据写入 `ga_users`，密保答案只保存哈希。
- 用户端默认通过“用户名 + 密保答案”重置密码，重置成功后需要重新登录；旧账号未设置密保问题时会明确提示联系管理员处理。

账号来自数据库初始化脚本，不是前端假数据。

## 后台说明

- 后台采用 webman-admin 作为正式后台方案，后台名称为 `Hoa Quán 后台`。
- 后台登录页、侧边栏和浏览器标签图标统一使用 `gameassist-logo.svg`。
- 后台菜单来自 `plugin/admin/config/menu.php`，通过 `server/scripts/sync_admin.php` 同步到 `wa_rules`。
- 管理员角色保留 `rules='*'`，用于访问 webman-admin 原生的数据库、权限管理、会员管理、通用设置等基础功能。
- 后台“GameAssist用户”管理针对产品用户表 `ga_users`，不是后台账号或 webman-admin 的 `wa_users`。
- 后台仪表盘的今日注册、7日注册、30日注册和总用户数均统计 `ga_users.created_at`，不使用后台用户表。
- GameAssist 用户后台允许查看、启用/禁用、重置密码和“添加配额”。添加配额只增加产品用户 `ga_users.balance`，必须填写正整数点数，可填写备注；成功后写入 `ga_user_point_transactions(type=admin_grant)` 和 `ga_admin_operation_logs(action=gameassist_user.grant_quota)`。
- 后台“公告管理”维护登录公告表 `ga_announcements`。公告支持中越标题和正文、启用/停用、发布时间；正文每行可用 `[red]`、`[green]`、`[blue]` 前缀控制用户端颜色，不支持任意 HTML。
- 后台“运行服务配置”负责编辑运行服务启用状态、连接池 Token 和我方 WebSocket 地址；签名密钥为选填，仅用于历史 HTTP 接口签名校验。后台“脚本连接”只读展示在线连接数、空闲连接数、已绑定连接数、停止中连接数、连接明细、日志队列积压、最大分片积压、日志 writer 数和最近写入状态，不再配置第三方 URL、连接槽位或单连接容量。

## 邀请与个人中心

- 个人中心接口：`GET /api/profile`，需要 Bearer token，返回用户信息、邀请码、邀请链接、推广人数、角色绑定状态和最近点数流水。
- 角色绑定没有用户端手动接口。用户添加游戏账号并启动成功后，GatewayWorker 收到第三方脚本 `started` 回包，会自动用 `role_id` 绑定当前用户；第三方未返回 `role_id` 时使用用户填写的游戏账号。
- 自动绑定成功后，如果当前用户来自有效邀请、该用户未触发过邀请奖励、该 `role_id` 未奖励过且邀请人当天未超过上限，系统即时给邀请人增加 `1` 点配额并记录流水。

## 用户认证与找回密码

- 认证方式由 `ga_system_settings.auth_verification_mode` 控制，默认 `security_question`，可选 `email_code`。
- `GET /api/auth/config` 返回当前认证方式和固定密保题库，用户端注册/找回密码表单按该配置展示。
- `security_question` 模式下，`POST /api/auth/register` 需要 `account`、`password`、`password_confirmation`、`security_question_key`、`security_answer`，可选 `invite_code`；邮箱字段允许为空。
- `security_question` 模式下，找回密码先调用 `POST /api/auth/password/security-question` 获取该账号的密保问题，再调用 `POST /api/auth/password/reset` 提交 `account`、`security_answer`、新密码和确认密码。
- `email_code` 模式保留邮箱验证码注册和找回密码链路；未切换到该模式时，邮箱验证码发送接口会明确返回“邮箱验证已关闭”。
- 已登录用户修改密码使用 `POST /api/auth/password/change`，需要 Bearer token，并提交 `current_password`、`password`、`password_confirmation`。后端验证当前密码后更新真实密码哈希，成功后当前 token 失效并要求重新登录。

## 用户端公告接口

- 用户端登录或注册成功进入首页后，会请求 `GET /api/announcements/latest`。
- 接口需要 Bearer token；返回 `data.announcement=null` 表示当前没有启用公告。
- 有公告时返回 `id`、`title`、`content_blocks`、`published_at`；`content_blocks` 为纯文本块，颜色只允许 `default`、`red`、`green`、`blue`。
- 已登录 token 直接打开首页不会重复触发“每次登录公告”；只有本次登录/注册成功后的首页会弹一次。

## 运行服务接口

- 第三方正式通信已固定为 WebSocket，配置项 `third_party_transport` 仅保留旧配置兼容，不作为正式 HTTP 接入开关。
- 第三方脚本主动连接我方地址：`ws://hoavienpro.com/ws/third-party/script?token=连接池Token`。Caddy 需要把 `/ws/third-party/script` 代理到本机 GatewayWorker 端口，默认 `8792`，可通过 `GATEWAY_PORT` 调整；GatewayWorker 内部起始端口默认 `2500`，可通过 `GATEWAY_START_PORT` 调整；Register 默认 `127.0.0.1:1238`，可通过 `GATEWAY_REGISTER_ADDRESS` 调整。
- 第三方脚本保活使用 JSON `heartbeat`，建议每 15-20 秒发送一次；服务端只更新连接最近心跳，不返回业务 `pong`。GatewayWorker 不主动发送 JSON ping，连接约 60 秒无任何消息会被释放。
- 运行服务需要先在后台“运行服务配置”或 `ga_system_settings` 配置 `third_party_enabled=1`、`third_party_script_token` 和 `third_party_script_ws_url`。`third_party_sign_secret` 可为空；为空时不影响脚本连接池 WebSocket，只影响历史 HTTP 签名接口。
- 后台不再配置第三方 URL、URL 列表或单连接容量；旧 `third_party_ws_url`、`third_party_ws_urls`、`third_party_ws_connection_capacity` 可暂留数据库用于兼容旧数据，但启动逻辑不读取。
- 常驻进程包括 GatewayWorker 的 Gateway、BusinessWorker、Register，以及 `server/config/process.php` 中的 `game_log_writer`、`game_task_state_writer`、`game_account_auto_restarter` 和 `game_account_expiry_watcher`。脚本连接状态写入 Redis 前缀 `gameassist:third_party_scripts:*`；日志写入 `gameassist:game_logs:queue:{shard}` 分片队列，`shard=account_id%64`，再由多 writer 聚合写入分段表。任务状态写入 `gameassist:game_task_states:queue:{shard}` 分片队列，再由任务状态 writer 聚合后批量 upsert。默认 `GAME_LOG_WRITER_COUNT=8`、`GAME_TASK_STATE_WRITER_COUNT=4`，可按服务器压力调整。
- 用户端启动账号：`POST /api/game-accounts/{id}/start`。后端先校验账号配额未到期，再验证密码可解密、读取本地配置 JSON、原子占用一个空闲脚本连接、写入 `starting`、`desired_running=1` 和日志会话，再向该脚本连接发送 `start` 包；接口成功只表示启动包已发出，不表示第三方登录成功。
- 用户端停止账号：`POST /api/game-accounts/{id}/stop`。后端向已绑定脚本发送 `stop` 后本地状态进入 `stopping` 并写入 `desired_running=0`；收到脚本 `stopped` 或连接关闭确认后才改为 `stopped` 并清空本次普通日志，事件卡片历史不会清空。
- 异常断线恢复：连接关闭但用户没有手动停止时，服务端不会把账号当作已停止，而是改为 `reconnecting`，保留原 `log_session_id` 和普通日志，等待空闲脚本连接后重发幂等 `start`。第三方应按 `game_username` 判断已有任务并重新绑定，不要重复启动；本协议不使用单独的 `resume` 消息。
- 用户端增加配额：`POST /api/game-accounts/{id}/quota`，请求体 `extra_points` 默认为 `0`。接口在事务内扣除用户余额、更新账号到期时间并写扣点流水；取消前端弹窗不会请求接口，也不会产生任何数据变更。
- 用户端删除账号会先尝试停止仍在运行态的第三方任务，再删除账号和相关日志/快照；已配置到账号的配额不返还到用户余额。
- 运行资源状态：第三方在账号绑定后发送 `status` 包刷新账号卡片资源，字段名以协议为准，例如金币字段是 `coin`。当前支持 `level`、`water`、`diamond`、`coin`、`speedCard`、`hireBook`、`pearl`、`floralCoin`、`meowCoin`、`raceCoin`、`flowerFinish`、`satinFinish`、`decorateFinish`、`customerFinish`。服务端只保存最新 Redis 快照，不写 MySQL；手动启动新会话、停止、账号异常结束或删除账号会清空快照，自动重连期间保留上一份快照。
- 第三方任务数据：脚本在收到 `start` 并进入绑定状态后，通过 WebSocket 发送 `task_state_get` 读取已落库历史快照，发送 `task_state_save` 保存最新任务 JSON。`task_state_save` 返回 `task_state_queued`，表示已进入写库队列；实际落库由 `game_task_state_writer` 异步批量处理。该数据由第三方维护，我方只负责按账号保存和在删除账号时清理。
- 第三方 WebSocket ready/start/stop/started/log/event/status/task_state_get/task_state_save/task_state_queued/error/stopped 协议、完整配置 JSON 示例和字段说明见 [docs/third-party-game-config.md](docs/third-party-game-config.md)。一个连接同一时间只绑定一个账号，运行消息不再传 `account_id`；账号归属由服务端连接绑定关系判断。JSON Schema 见 [docs/third-party-game-config.schema.json](docs/third-party-game-config.schema.json)，它是可选机器校验文件，不是实际传输数据。
- 配置页里的“指定花朵 / 指定花瓶 / 指定花艺 / 指定花灵”显示中越双语名称，但保存和第三方协议只传第三方提供的资产 ID。花朵、花瓶、花艺来自 `client/src/utils/gameAssetOptions.js`；花灵来自 `client/src/utils/gameElfOptions.js`、`docs/VN花灵.txt` 和 `docs/flower-elf-id-table.*`。名称为空或待定的资产要继续显示 ID/待定名并记录在 `ASSET_OPTION_ISSUES` 或待翻译文件，不能删除真实 ID 来隐藏数据问题。
- 历史签名 HTTP 辅助接口仍保留：`GET /api/third-party/game-accounts/{id}/config` 读取配置，`POST /api/third-party/game-accounts/{id}/logs` 写入日志，`POST /api/third-party/apply-config` 应用配置。这些接口不是正式启动、停止、任务状态或运行资源通道。
- 历史签名 HTTP 接口请求头必须包含：
  - `X-Timestamp`：当前 Unix 时间戳，5 分钟内有效。
  - `X-Signature`：`hash_hmac('sha256', "{METHOD}\n{PATH}\n{timestamp}", third_party_sign_secret)`。
- 历史配置读取接口返回结构中的 `data.config` 就是保存到 `ga_game_accounts.config_json` 的配置 JSON，供第三方按原样读取。
- 用户端查看日志优先尝试本地 `/ws/game-accounts/{id}/logs`，不可用时切换为 `GET /api/game-accounts/{id}/logs?lastLine=0&lastEvent=0` HTTP 轮询。普通日志读取 `logs/lastLine/log_session_id`，事件卡片历史读取 `events/lastEvent/categories`。

## 多语言

- 后台和用户端支持 `zh_CN`、`vi` 两种语言，文案来自统一 JSON 语言包：`server/resource/translations/{zh_CN,vi}/messages.json`。
- 用户端通过 `/api/i18n/messages?locale=zh_CN|vi` 拉取语言包，所有业务 API 请求会携带 `X-Locale`。
- 后端语言选择优先级：`?lang=` / `?locale=`、`X-Locale` 请求头、`gameassist_locale` cookie、默认 `zh_CN`。
- 后台和用户端都提供语言切换入口；切换后会保存到本地存储或 cookie。
- 用户端游戏配置页的分组标题、配置项名称、问号说明、导入/保存提示都必须从语言包读取；礼仪分监控开启后才显示 `basic.reputation.threshold` 礼仪分阈值字段。
- 用户端游戏配置页的种植、订单、公会、活动分组和问号说明保持原版复刻；原版没有说明的开关不显示问号，不用占位文案伪装。
- 用户端游戏配置页以原站当前页面为校验口径，开关展开、单选、下拉等控件形态要和原站一致；例如种植的“选择数量”为下拉选择，保存值仍是字符串。
- 用户端游戏配置页支持开关展开子配置，例如自动种植展开加速/水滴/任务优先级/种植模式，订单展开数量上限和品质限定，公会展开分享/摸花/竞赛规则，活动展开领取体力、速度、重开、开箱等专项配置。
- 用户端游戏配置页必须支持手机浏览器访问；移动端不得继承桌面表单左侧偏移，宽控件应在窄屏下换行并保持可见、可操作，不能因此修改配置字段语义或保存结构。
- 给越南客户翻译时，只提供 `server/resource/translations/vi/messages.json`。客户只能替换 value，不能删除、改名或新增 key。
- webman 是常驻进程，语言包会按文件修改时间自动刷新；覆盖 JSON 后无需改代码，但生产环境仍建议执行一次 `php start.php reload` 或重启服务，便于确认进程状态一致。
- 校验语言包完整性：

```bash
cd server
vendor/bin/phpunit tests/Feature/I18nServiceTest.php
```

- 若 `zh_CN` 与 `vi` key 不一致、value 为空或代码引用了不存在的翻译 key，测试或开发环境会直接暴露问题，不用默认文案掩盖。

## SMTP 配置

SMTP 只在 `ga_system_settings.auth_verification_mode=email_code` 时参与用户注册和找回密码。注册验证码和找回密码验证码都使用 `ga_system_settings` 中的 SMTP 配置，并按用途分别写入 Redis，二者不能混用。未启用或配置不完整时，发送验证码接口会明确失败。

QQ 邮箱常用配置示例：

- `smtp_enabled`：`1`
- `smtp_host`：`smtp.qq.com`
- `smtp_port`：`465`
- `smtp_encryption`：`ssl`
- `smtp_username`：你的完整 QQ 邮箱
- `smtp_password`：QQ 邮箱授权码
- `smtp_from_email`：你的完整 QQ 邮箱
- `smtp_from_name`：`Hoa Quán`
