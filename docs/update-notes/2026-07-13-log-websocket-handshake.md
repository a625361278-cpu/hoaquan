# 用户端日志 WebSocket 握手修复

## 问题

用户打开游戏账号日志面板时，`game_account_log_ws` 会在 WebSocket 握手阶段抛出 `TypeError` 并退出。前端随后降级为 HTTP 轮询，因此日志仍可查看，但实时日志通道没有真正建立。

根因是项目使用 Workerman 5.2.2：`onWebSocketConnect` 的第二个参数是 `Workerman\Protocols\Http\Request` 对象，原实现却声明为 `string`，并按原始 HTTP 报文解析。

## 修复

- 握手回调改为接收 Workerman `Request` 对象。
- 使用 `method()`、`path()`、`get()` 读取请求方法、日志路径、Token 和语言。
- 保留原有用户认证、账号归属校验和错误关闭行为。
- 增加真实 Workerman `Request` 回归测试，覆盖正确路径、错误路径和非 GET 请求。

## 影响范围

- 只影响用户端 `/ws/game-accounts/{id}/logs` 日志 WebSocket。
- 不修改第三方脚本 GatewayWorker 连接、普通日志/事件日志分类、数据库结构或前端协议。
- 后端更新后需要 reload 或 restart Workerman 常驻进程才会生效。
