# 2026-07-07 GatewayWorker 与高吞吐日志更新说明

## 修改原因

第三方接入方向已调整为第三方脚本主动连接我方 WebSocket，并且预估后期会达到 1 万游戏账号规模。此前的主动外连和单队列逐条日志写库方案无法稳定支撑该规模，也不利于后台观察脚本池和日志积压状态。

## 核心变更

- 第三方脚本主动连接 `ws://example.com/ws/third-party/script?token=脚本池Token`，通过 Token 校验后进入空闲连接池。
- 用户启动账号时，后端从空闲脚本连接中分配一个连接，一个连接同一时间只绑定一个游戏账号。
- 运行消息不再携带 `account_id`，账号归属由服务端连接绑定关系判断。
- 日志系统改为 64 个 Redis 分片队列，`shard = account_id % 64`，同一账号固定进入同一分片。
- `game_log_writer` 默认 8 个进程，按分片消费、内存聚合、批量写入 MariaDB 分段表。
- 新增 `ga_game_account_log_states` 游标表，记录每个账号/会话/日志类型的最后序号、保留条数和最后段号，避免每次写入扫描分段表。
- 普通日志仍按本次运行会话保存，启动新会话和主动停止后清空，最多 2500 条。
- 事件卡片历史按账号保存，跨停止/重启保留，最多 2500 条。
- 后台第三方连接页新增日志积压、最大分片积压、日志 writer 数和最近写入状态。
- 协议说明、README、部署文档和越南待翻译清单已同步更新。

## 部署注意

- 更新服务器后必须执行 `php scripts/sync_database.php`，用于创建 `ga_game_account_log_states` 表和补齐日志相关配置项。
- `sync_database.php` 只新增缺失表/字段/配置项，不覆盖线上已有正式配置值。
- 常驻进程需要重启，确保 GatewayWorker、BusinessWorker、Register 和 `game_log_writer` 使用新代码。
- GatewayWorker 默认端口为：对外 WebSocket `8792`、内部起始端口 `2500`、Register `127.0.0.1:1238`；如同机已有其他 Workerman/GatewayWorker 项目，可通过 `GATEWAY_PORT`、`GATEWAY_START_PORT`、`GATEWAY_REGISTER_ADDRESS` 在 `.env` 调整。
- 默认建议设置 `GAME_LOG_WRITER_COUNT=8`；服务器资源不足时可降低，但需要观察后台日志积压是否持续增长。
- 当前文档仍使用 `ws://`，正式商用建议升级为 `wss://`，因为启动包会传游戏账号和密码。

## 自测情况

- `php -l` 已检查新增和修改的 PHP 文件。
- `vendor\\bin\\phpunit` 已通过。
- `npm run build:h5` 已通过。
- zh_CN / vi 语言包 key 集一致。
- `git diff --check` 无空白格式错误。
