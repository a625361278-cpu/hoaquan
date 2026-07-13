# 第三方游戏配置 JSON 对接说明

本文档说明第三方脚本主动连接我方 GatewayWorker 后，服务端启动游戏账号时发送给脚本的 WebSocket 载荷结构，以及其中 `config` 游戏配置 JSON 的完整字段。字段由用户端配置 schema 生成，字段名与实际保存/发送结构一致。

## 连接方式

第三方脚本先主动连接我方 WebSocket：

```text
ws://example.com/ws/third-party/script?token=SCRIPT_POOL_TOKEN
```

- `SCRIPT_POOL_TOKEN` 由后台“第三方配置”的脚本池 Token 提供。
- 连接通过校验后会收到 `{"type":"ready","client_id":"...","state":"idle"}`。
- 一个连接同一时间只服务一个游戏账号。账号停止后该连接会断开；脚本如需继续接单，需要重新连接。
- 运行消息不再携带 `account_id`。账号归属由服务端连接绑定关系判断；未绑定连接发送业务消息会被关闭。
- 脚本保活只使用 JSON 心跳：`{"type":"heartbeat","script_version":"1.0.0"}`。建议每 15-20 秒发送一次，服务端只更新最近心跳和脚本版本，不返回业务回包；脚本不要等待 `pong`。
- 如果连接约 60 秒没有收到任何消息，服务端会释放该连接；脚本需要重新连接并等待新的 `ready`。
- 所有 WebSocket JSON 文本必须使用 UTF-8。第三方日志来源如果是 GBK/ANSI 等本地编码，发送前必须转换为 UTF-8；服务端不会静默猜测编码。

## 启动载荷

用户添加游戏账号时，服务端会先占用空闲连接发送 `login` 验证包。凭证字段与 `start` 相同且互斥，但不包含 `config/task_state`。第三方必须在20秒内返回同一 `request_id/session_id` 的 `type=login` 结果；`code` 为整数0或1，`msg` 为字符串，`code=1` 时 `server_name` 必须为非空字符串。只有真实成功结果才会保存账号。正常结果后连接恢复空闲；超时、非法响应或上下文不匹配时连接关闭。

正式第三方接入固定使用 WebSocket。用户点击启动后，服务端会从空闲脚本连接中分配一个连接，并向该连接发送下面的 `start` 包。`game_password` 或 `token` 只在启动通信中传递，不写入 `config`；`task_state` 是第三方最近一次已接收的任务数据快照，不写入 `config`。

`request_id` 是本次请求编号，不是游戏账号、区服或角色 ID；第三方回 `started/error/stopped` 时建议原样带回。`session_id` 是本次运行日志会话 ID。`login_method` 是整型账号登录方式枚举：`1` 使用 `game_username + game_password`，`2` 为 Facebook、`3` 为 Google，后两种都使用 `game_uid + token`。三组凭证字段互斥，第三方必须按该字段选择登录流程。当前游戏只有一个区服，启动包不传 `server_id`、`server_name`。

`start` 是幂等的“启动或重新绑定”指令。网络断开、我方服务重启或第三方脚本重连后，只要玩家没有手动停止账号，服务端会在有空闲脚本连接时再次发送 `start`。第三方在方式 `1` 使用 `game_username`、方式 `2/3` 使用 `game_uid` 判断任务是否已经运行：已经运行时不要重复启动，只把新连接绑定到原任务并返回 `started`；未运行时正常启动。本协议不另设 `resume` 消息。

Facebook / Google 启动包的凭证部分如下，其余 `request_id/session_id/config/task_state` 与完整示例一致：

```json
{
  "type": "start",
  "login_method": 2,
  "game_uid": "game_uid_001",
  "token": "facebook-token"
}
```

```json
{
  "type": "start",
  "login_method": 3,
  "game_uid": "game_uid_001",
  "token": "google-token"
}
```

服务端只接受 `request_id/session_id` 与当前连接绑定值一致的 `started` 回包；如果玩家已停止、账号配额已过期或旧启动回包迟到，服务端会忽略该回包并写入系统日志。

```json
{
  "type": "start",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "session_id": "f3b33b8d8c8e4f44a0c6a3b7",
  "login_method": 1,
  "game_username": "game_account_001",
  "game_password": "plain-password-used-only-during-start",
  "task_state": {
    "exists": true,
    "state": {
      "currentTask": "plant",
      "cursor": 12
    },
    "saved_at": "2026-07-09 12:00:00"
  },
  "config": {
    "basic": {
      "reputation": {
        "enabled": false,
        "threshold": 80
      },
      "debug": false,
      "reconnectInterval": 5,
      "task": {
        "daily": false,
        "weekly": false,
        "main": false,
        "story": false,
        "achieve": false
      },
      "mail": false,
      "benefit": {
        "buff": false,
        "box": false,
        "shareRwd": false,
        "antiFraudBox": false
      },
      "sign": {
        "daily": false,
        "patch": false
      },
      "pearl": {
        "freePearl": false,
        "autoHire": false,
        "maxHireLevel": 0,
        "maxHireTicketUsage": 0,
        "open": false,
        "protectEnabled": false,
        "buyHireBook": false,
        "maxSpendDmd": 25
      },
      "shop": {
        "videoGift": false,
        "cultivateShop": {
          "autoBuy": false,
          "maxSpendGold": 0
        },
        "vipShop": {
          "autoBuy": false,
          "maxSpendDmd": 0,
          "maxSpendFloralCoin": 0
        }
      },
      "randomEvent": {
        "enabled": false
      },
      "cat": {
        "enabled": false,
        "autoRecall": false,
        "autoBuyFood": false,
        "autoFeed": false,
        "autoStroke": false
      }
    },
    "plant": {
      "cultivate": {
        "enabled": false,
        "videoSpeedup": false,
        "upgrade": false,
        "targetLevel": 20
      },
      "water": {
        "enabled": false,
        "timedEnabled": false,
        "threshold": 0,
        "forceCollectEnabled": false,
        "forceCollectTime": ""
      },
      "flower": {
        "unlockLand": false,
        "harvestEnabled": false,
        "plantEnabled": false,
        "videoSpeedup": false,
        "useSpeedCard": false,
        "speedCardLimit": 20,
        "waterThreshold": 0,
        "taskMode": true,
        "taskLogEnabled": false,
        "taskPriority": {
          "customerOrder": 1,
          "residentOrder": 2,
          "artSell": 6,
          "flowerNews": 3,
          "palaceOrder": 4,
          "unionRace": 3
        },
        "plantingMode": "quality",
        "flowerQuality": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "categoryCount": "4",
        "specificFlowers": [
          "23001"
        ],
        "minFlowerLevel": 0
      },
      "friendSteal": {
        "enabled": false,
        "includeElf": false,
        "stealMode": "quality",
        "qualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "specificFlowers": [
          "23001"
        ],
        "excludeFlowers": [],
        "buyCount": false,
        "buyStealCount": 0
      },
      "elves": {
        "plant": false,
        "selectedIds": [],
        "applyAid": false,
        "recvAid": false,
        "helpFriend": false,
        "dispatch": false,
        "speedupDispatch": false,
        "recvDispatchReward": false,
        "recvPass": false,
        "recvPassTask": false,
        "recvFlowerPass": false,
        "recvFlowerPassTask": false
      },
      "art": {
        "unlockShelf": false,
        "autoPut": false,
        "sellMode": "specified",
        "specifiedVases": [
          "3001"
        ],
        "specifiedArts": [],
        "flowerArtPerRack": 12,
        "exp": false,
        "bookReward": false
      },
      "market": {
        "unlockShelf": false,
        "autoPut": false,
        "putMode": "inventory",
        "specificFlowers": [
          "23001"
        ],
        "priceIndex": "0",
        "maxSell": 25,
        "putPassword": "",
        "autoBuyPutCount": false,
        "buyPutCount": 0,
        "autoBuyFromFriend": false,
        "buyMode": "quality",
        "buyQualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "buyFlowers": [
          "23001"
        ],
        "minPutTimeDiff": 0
      }
    },
    "order": {
      "resident": {
        "normal": false,
        "normalMaxNum": 1200,
        "satin": false,
        "satinMaxNum": 120,
        "building": false,
        "buildingMaxNum": 120,
        "qualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ]
      },
      "customer": {
        "enabled": false,
        "rejectEnabled": false
      },
      "palace": {
        "enabled": false,
        "qualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "ignoreQuality": false
      },
      "group": {
        "enabled": false,
        "oneMore": false,
        "submitOnlyCultivatedFlowers": false,
        "qualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ]
      }
    },
    "union": {
      "land": {
        "harvest": false,
        "plant": false,
        "plantMode": "quality",
        "flowers": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "specificFlowers": [
          "23001"
        ],
        "maxFlowerLevel": 0
      },
      "build": {
        "video": false,
        "coin": false,
        "dmd": false
      },
      "flower": {
        "share": false,
        "shareMode": "quality",
        "shareQualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "shareFlowers": [
          "23001"
        ],
        "touch": false,
        "touchMode": "quality",
        "touchQualities": [
          "green",
          "blue",
          "purple",
          "gold",
          "red"
        ],
        "touchFlowers": [
          "23001"
        ]
      },
      "fmlRace": {
        "enabled": false,
        "autoEnableModules": false,
        "useSpeedCard": false,
        "minScore": 25,
        "upgradedMinScore": 50,
        "dropLowScore": false,
        "onlyUpgradeTask": false,
        "excludeOtherUpgradeTask": true,
        "taskTypePriority": {
          "vipShop": 0,
          "residentOrder": 0,
          "customerOrder": 0,
          "materialShop": 0,
          "palaceOrder": 0,
          "pearlHire": 0,
          "friendSteal": 0,
          "artSell": 0,
          "artCraft": 0,
          "flowerUpgrade": 0,
          "plantHarvest": 0,
          "flowerCultivate": 0,
          "animalInteract": 0
        },
        "autoUpgradeTask": false,
        "deleteLowScoreTask": false,
        "deleteTaskMaxScore": 0,
        "keepSystemUpgrade": false,
        "keepPlayerUpgrade": false
      },
      "exchange": {
        "autoRecv": false
      },
      "energyForest": {
        "collect": false
      }
    },
    "activity": {
      "flowerLetter": {
        "enabled": false,
        "unlockSlot": false,
        "autoEnableModules": false
      },
      "flowerNews": {
        "enabled": false,
        "refreshEnabled": false,
        "maxFinishCountPerBatch": 0
      },
      "fishDry": {
        "enabled": false,
        "showResult": false,
        "autoRestart": false
      },
      "bubble": {
        "enabled": false
      },
      "fishFun": {
        "enabled": false,
        "autoClaimEnergy": false,
        "speed": "normal",
        "showResult": false,
        "autoRestart": false
      },
      "flowerStory": {
        "enabled": false,
        "autoClaimEnergy": false,
        "speed": "normal"
      },
      "redPacket": {
        "enabled": false
      },
      "recvLuck": {
        "enabled": false
      },
      "call": {
        "enabled": false
      },
      "familyHelp": {
        "enabled": false,
        "recvBoxes": false
      },
      "moneyTree": {
        "enabled": false
      },
      "zooGameElim": {
        "enabled": false
      },
      "lantern": {
        "enabled": false
      },
      "cake": {
        "enabled": false,
        "autoClaimEnergy": false,
        "useItems": false,
        "speed": "normal"
      },
      "merge": {
        "enabled": false,
        "autoClaimEnergy": false,
        "speed": "normal"
      },
      "spool": {
        "enabled": false,
        "autoClaimReward": false,
        "openBox": false,
        "autoRestart": false,
        "speed": "normal"
      },
      "dragonBoat": {
        "enabled": false,
        "autoSign": false,
        "autoOpenBox": false,
        "giftBuy": false
      },
      "honey": {
        "reward": false
      },
      "card": {
        "reward": false,
        "smoke": false
      }
    }
  }
}
```

## WebSocket 消息

### 后端发送 stop

```json
{
  "type": "stop",
  "request_id": "b0ff0f0e5f584d1fb1c164a40ff6d68a",
  "session_id": "f3b33b8d8c8e4f44a0c6a3b7"
}
```

### 第三方返回 started

```json
{
  "type": "started",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "session_id": "f3b33b8d8c8e4f44a0c6a3b7",
  "role_id": "role-123",
  "display_name": "role-name"
}
```

`role_id` 是稳定唯一的游戏角色标识，用于平台用户绑定和邀请奖励去重，建议每次 `started` 都返回。允许常见账号/角色标识字符，最长 128 个字符，不能包含空白或控制字符。缺少 `role_id` 时，账号密码登录使用 `game_username`，Facebook/Google 登录使用 `game_uid` 作为绑定 ID。`display_name` 只用于展示角色名，不参与奖励和唯一身份判断。

### 第三方返回 error

```json
{
  "type": "error",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "session_id": "f3b33b8d8c8e4f44a0c6a3b7",
  "message": "login failed"
}
```

`error` 表示第三方明确判定任务失败，服务端会把账号标记为异常并停止自动重连。临时网络断开不要用 `error` 表示，直接断开连接即可，服务端会按用户运行意图尝试重新绑定。

### 第三方推送 log

```json
{
  "type": "log",
  "level": "info",
  "category": "runtime",
  "time": "2026-07-04 12:00:00",
  "message": "started"
}
```

普通日志按本次运行会话保存，最多保留 2500 条；主动停止和下次启动前会清空。普通日志会先进入 Redis 分片队列，由日志 writer 聚合后批量写库，用户端读取可能有几秒延迟。事件卡片历史只用于玩家游戏内事件展示，请优先使用结构化 `event` 消息；如果只能写在日志文本里，可在行尾追加 `[[EVT]]` 后接事件 JSON，服务端会解析并写入事件历史。连接断开、自动重连失败、配额到期停止等平台运行状态只写普通日志，不写事件卡片历史。

### 第三方推送 event

```json
{
  "type": "event",
  "event": {
    "module": "种植",
    "title": "完成种植任务",
    "desc": "已种植库存较少的花朵",
    "status": "success",
    "time": "2026-07-04 12:00:00"
  }
}
```

事件历史按游戏账号保存，跨停止/重启保留，最多保留 2500 条。事件日志同样经过分片队列和 writer 批量写库，但刷新周期更短。用户点击清除事件历史时才会清空。`event` 应描述游戏内行为或收益结果，不用于平台连接、重连、到期停止等系统运行状态。

### 第三方推送 status

```json
{
  "type": "status",
  "level": 14,
  "water": 1,
  "diamond": 754,
  "coin": 236000
}
```

`status` 用于同步当前账号卡片展示的运行资源快照，只能在连接已绑定游戏账号后发送，不传 `account_id`。服务端按本次消息保存最新快照，本次没有携带的字段在用户端显示默认值，不沿用上一包旧值。金币推荐字段为 `coin`；当前兼容第三方实际上报的 `gold`，服务端会按 `coin` 保存。如果同一包同时传 `coin` 和 `gold` 且值不同，服务端会按协议错误处理。

| 字段 | 类型 | 默认值 | 用户端显示 |
|---|---:|---:|---|
| `level` | number/string | `0` | 等级 |
| `water` | number/string | `0` | 水滴 |
| `diamond` | number/string | `0` | 元宝 |
| `coin` | number/string | `0` | 金币 |
| `gold` | number/string | `0` | 金币；兼容别名，推荐改用 `coin` |
| `speedCard` | number/string | `0` | 加速卡 |
| `hireBook` | number/string | `0` | 雇佣书 |
| `pearl` | number/string | `0` | 珍珠 |
| `floralCoin` | number/string | `0` | 花坊币 |
| `meowCoin` | number/string | `0` | 喵币 |
| `raceCoin` | number/string | `"0/0"` | 公会竞赛 |
| `flowerFinish` | number/string | `0` | 居民订单 |
| `satinFinish` | number/string | `0` | 绸缎订单 |
| `decorateFinish` | number/string | `0` | 建材订单 |
| `customerFinish` | number/string | `0` | 顾客订单 |

运行资源快照保存在 Redis，不写 MySQL，不进入普通日志。用户手动启动新会话、停止、账号异常结束或删除账号时会清空快照；自动重连期间会保留上一份快照，直到第三方重新推送新的 `status`。

### 第三方读取任务数据 task_state_get

```json
{
  "type": "task_state_get",
  "request_id": "state-read-1"
}
```

任务数据读写只走当前已绑定账号的 WebSocket 连接，不走 HTTP，不传 `account_id`。服务端根据连接绑定关系定位账号。`task_state_get` 和后端下发的 `start.task_state` 都优先返回最近一次已接收的 `task_state_save` 快照；如果该快照尚未落库，也会先返回它。没有保存过任务数据时，服务端返回 `exists:false` 和空对象：

```json
{
  "type": "task_state",
  "request_id": "state-read-1",
  "exists": false,
  "state": {},
  "saved_at": null
}
```

已有任务数据时返回：

```json
{
  "type": "task_state",
  "request_id": "state-read-1",
  "exists": true,
  "state": {
    "currentTask": "plant",
    "cursor": 12
  },
  "saved_at": "2026-07-08 12:00:00"
}
```

### 第三方保存任务数据 task_state_save

```json
{
  "type": "task_state_save",
  "request_id": "state-save-1",
  "state": {
    "currentTask": "plant",
    "cursor": 12
  }
}
```

`state` 必须是 JSON 对象，默认最大 256KB，可通过服务端环境变量 `GAME_TASK_STATE_MAX_BYTES` 调整。服务端校验通过并写入 Redis 队列后立即返回：

```json
{
  "type": "task_state_queued",
  "request_id": "state-save-1",
  "queued_at": "2026-07-08 12:00:00",
  "bytes": 35
}
```

任务状态由专用 writer 进程异步批量入库，同一账号短时间内多次保存只落最新快照；如果内容与库内完全一致，writer 会跳过重复写入。`task_state_queued` 表示已接收、已写入 Redis 最新快照并进入写库队列，不表示数据库已经完成落库。任务数据是每个游戏账号一份最新快照，停止、重启、自动重连都不清空；只有删除游戏账号时清空。空闲连接发送 `task_state_get` / `task_state_save`、携带 `account_id`、`state` 不是对象、JSON 超限或账号已删除，都会返回协议错误并关闭连接。

### 第三方返回 stopped

```json
{
  "type": "stopped",
  "request_id": "b0ff0f0e5f584d1fb1c164a40ff6d68a",
  "session_id": "f3b33b8d8c8e4f44a0c6a3b7"
}
```

`stopped` 只应作为收到后端 `stop` 后的确认。如果第三方在未收到 `stop` 的情况下返回 `stopped`，服务端会按异常停止处理：玩家没有手动停止时会进入等待重连，并在有空闲脚本连接后重新发送幂等 `start`。

## config 完整字段

> 说明：控件类型用于说明前端配置项的展示方式；数据类型才是第三方实际收到的 JSON 字段格式。`switch` 的真实 JSON 类型为 `boolean`，取值只能是 `true` 或 `false`，不是 `1/0` 或字符串。

### 基础（basic）

#### 基础设置

- `basic.reputation.enabled`：礼仪分监控；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：每10分钟检查礼仪分，低于阈值时自动停止所有任务
- `basic.reputation.threshold`：礼仪分阈值；控件类型：`number`；数据类型：`number`；默认值：`80`；说明：礼仪分低于此值时停止所有任务
- `basic.debug`：道具日志；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后显示背包道具的增加和消耗详情
- `basic.reconnectInterval`：重连间隔；控件类型：`number`；数据类型：`number`；默认值：`5`；说明：自动顶号间隔，建议设置为 5 分钟

#### 任务配置

- `basic.task.daily`：每日任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成奖励，阶段宝箱奖励
- `basic.task.weekly`：每周任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每周任务完成奖励
- `basic.task.main`：主线任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取主线任务完成奖励
- `basic.task.story`：主线剧情；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动解锁主线剧情
- `basic.task.achieve`：花坊悬赏；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花坊悬赏完成奖励

#### 邮件配置

- `basic.mail`：自动领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取邮件奖励

#### 福利配置

- `basic.benefit.buff`：双倍金币；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：每4小时自动看视频领取双倍金币福利
- `basic.benefit.box`：福利宝箱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：每1小时自动开启福利宝箱
- `basic.benefit.shareRwd`：分享奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：当制作了新花艺、培育了新花朵或升级时自动分享，领取分享奖励
- `basic.benefit.antiFraudBox`：防骗宝箱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：每天自动签到并领取防骗宝箱奖励

#### 每日祈愿

- `basic.sign.daily`：自动祈愿；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `basic.sign.patch`：自动补签；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

#### 珍珠配置

- `basic.pearl.freePearl`：免费珍珠；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动看视频领取免费珍珠
- `basic.pearl.autoHire`：雇佣劳工；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动雇佣劳工
- `basic.pearl.maxHireLevel`：等级限制；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：只雇佣等级<=此值的用户，0表示不限制
- `basic.pearl.maxHireTicketUsage`：雇佣券上限；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：当日最大可以使用的雇佣券数量, 为0则不限制。
- `basic.pearl.open`：自动开珍珠；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `basic.pearl.protectEnabled`：开启防身；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后别人雇佣自己会消耗防身符
- `basic.pearl.buyHireBook`：买雇佣书；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：雇佣书不足时自动购买
- `basic.pearl.maxSpendDmd`：元宝上限；控件类型：`number`；数据类型：`number`；默认值：`25`；说明：购买雇佣书消耗最大元宝

#### 商城购买

- `basic.shop.videoGift`：视频礼包；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动观看视频领取礼包商城免费礼包
- `basic.shop.cultivateShop.autoBuy`：材料商店；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动买光材料商店所有耗材，自动刷新
- `basic.shop.cultivateShop.maxSpendGold`：金币上限；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：材料商店花费金币上限，0则不限制
- `basic.shop.vipShop.autoBuy`：vip商店；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动购买vip商店物品
- `basic.shop.vipShop.maxSpendDmd`：元宝上限；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：vip商店花费元宝上限，0则不限制
- `basic.shop.vipShop.maxSpendFloralCoin`：花坊币上限；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：vip商店花费花坊币上限，0则不限制

#### 随机事件

- `basic.randomEvent.enabled`：自动处理；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动处理随机事件

#### 喂猫撸猫

- `basic.cat.enabled`：总开关；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `basic.cat.autoRecall`：自动召回；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `basic.cat.autoBuyFood`：自动购买猫粮；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `basic.cat.autoFeed`：自动喂猫；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：保持猫粮盆满
- `basic.cat.autoStroke`：自动撸猫；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

### 种植（plant）

#### 培育配置

- `plant.cultivate.enabled`：自动培育；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动培育可培育花种
- `plant.cultivate.videoSpeedup`：视频加速；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动观看视频加速培育正在培育的花种，培育时间减半
- `plant.cultivate.upgrade`：鲜花升级；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费金币进行鲜花升级
- `plant.cultivate.targetLevel`：目标等级；控件类型：`number`；数据类型：`number`；默认值：`20`；说明：鲜花升级到目标等级

#### 水滴配置

- `plant.water.enabled`：水车水滴；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：3分钟领取一次水滴，这样才能最大化暴击，所以领取会略慢。
- `plant.water.timedEnabled`：限时水滴；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取限时水滴
- `plant.water.threshold`：水滴阈值；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：若设置100，你的水滴低于100点才会领取，0代表不限制，设置0才会及时领水哦，不理解的建议无脑设置0
- `plant.water.forceCollectEnabled`：无视阈值直接领；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：会根据设置好的时间段，到时间点后就无视水滴阈值且每次领水不等待3分钟直接领取
- `plant.water.forceCollectTime`：领取时间；控件类型：`text`；数据类型：`string`；默认值：`""`；说明：到该时间点后将无视水滴阈值并直接领取，时只能设置 16-23

#### 种花配置

- `plant.flower.unlockLand`：解锁土地；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费金币解锁可解锁的土地
- `plant.flower.harvestEnabled`：自动收获；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成土地收获
- `plant.flower.plantEnabled`：自动种植；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成土地浇水，加速，种植
- `plant.flower.videoSpeedup`：视频加速；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动观看视频加速收获，当所有土地种了花且可加速才会使用，避免浪费视频加速次数
- `plant.flower.useSpeedCard`：使用加速；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：使用加速券加速收获
- `plant.flower.speedCardLimit`：加速上限；控件类型：`number`；数据类型：`number`；默认值：`20`；说明：若设置100，则今日使用到100张就不使用了
- `plant.flower.waterThreshold`：保留水滴；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：保留多少水滴不用于浇花
- `plant.flower.taskMode`：任务优先；控件类型：`switch`；数据类型：`boolean`；默认值：`true`；说明：开启后：如果订单里缺花，系统会先种订单需要的花；发现有空地时，会直接插队用来种这些花；花种完以后，会自动恢复到原来设置的模式（指定品质 / 指定种类 / 指定花朵）
- `plant.flower.taskLogEnabled`：任务日志；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：是否显示种植任务队列日志
- `plant.flower.taskPriority`：任务优先级；控件类型：`priorityGroup`；数据类型：`object`；默认值：`{"customerOrder":1,"residentOrder":2,"artSell":6,"flowerNews":3,"palaceOrder":4,"unionRace":3}`；说明：配置订单任务的优先级，数字越小优先级越高，0是不做此任务，可以几个任务设置一样的数字，就会一起做这几个任务。有些玩家说居民订单不做，花艺不做，莳花不做，都跟您设置的数字有关，数字最大就会把任务排到最后，让您产生不做的错觉；对象键：customerOrder / residentOrder / artSell / flowerNews / palaceOrder / unionRace；可选值：顾客订单=`"customerOrder"` / 居民订单=`"residentOrder"` / 花艺售卖=`"artSell"` / 莳花纪闻=`"flowerNews"` / 宫廷订单=`"palaceOrder"` / 公会竞赛=`"unionRace"`
- `plant.flower.plantingMode`：种植模式；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：选择种植模式，只能启用一种模式。需要保持种植整洁的玩家请自行清空所有土地；可选值：指定品质=`"quality"` / 指定种类=`"category"` / 指定花朵=`"flower"` / 库存模式=`"stock"` / 64块地模式=`"sixtyFour"`
- `plant.flower.flowerQuality`：选择品质；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：选择要种植的花朵品质，可多选，库存少的优先种植。；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `plant.flower.categoryCount`：选择数量；控件类型：`select`；数据类型：`string`；默认值：`"4"`；说明：选择要种植几种花，库存少的优先种植。；可选值：1=`"1"` / 2=`"2"` / 4=`"4"` / 8=`"8"` / 16=`"16"`
- `plant.flower.specificFlowers`：选择花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：选择要种植的花朵，可多选，库存少的优先种植。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `plant.flower.minFlowerLevel`：限制花朵等级；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：限制种植的最低花朵等级，0则不限制，此项的设置只针对补库存，做订单和公会竞赛之类的不受此设置影响

#### 好友偷花

- `plant.friendSteal.enabled`：自动偷花；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：默认不会偷取花灵，但在好友种植花灵时会偷取花朵，需要在偷花模式里设置排除花朵，排除花灵主花
- `plant.friendSteal.includeElf`：偷取花灵；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后偷取有花灵的地块，关闭则跳过有花灵的地块
- `plant.friendSteal.stealMode`：偷花模式；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：选择偷花过滤模式：指定品质或指定花朵或排除花朵；可选值：指定品质=`"quality"` / 指定花朵=`"flower"` / 排除花朵=`"exclude"`
- `plant.friendSteal.qualities`：指定品质；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：只偷取指定品质的花朵；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `plant.friendSteal.specificFlowers`：指定花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：只偷取指定的花朵。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `plant.friendSteal.excludeFlowers`：排除花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`[]`；说明：不偷取指定的花朵，不想影响好友种植花灵的话，建议把所有花灵主花设置上，排除掉。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `plant.friendSteal.buyCount`：购买偷取次数；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：花费好友币购买偷取次数
- `plant.friendSteal.buyStealCount`：购买次数；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：每个好友购买多少次偷取次数

#### 花灵

- `plant.elves.plant`：自动种花灵；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：优先种植指定花灵，否则选择当期双倍加成花灵种植（8朵主花+其余辅花），需要打开种植系统自动收获和自动种植，每日花灵达到收获上限后恢复到原有种植模式
- `plant.elves.selectedIds`：指定花灵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`[]`；说明：数组内为花灵 ID，ID 来自当前花灵选项，不传显示名。
- `plant.elves.applyAid`：自动申请协助；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `plant.elves.recvAid`：自动领取协助加成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：当协助人数达到5人时自动领取协助加成
- `plant.elves.helpFriend`：自动协助好友；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `plant.elves.dispatch`：自动派遣花灵；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动将背包中的花灵派遣到空闲位置
- `plant.elves.speedupDispatch`：自动加速派遣；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：花费元宝加速派遣中的花灵
- `plant.elves.recvDispatchReward`：自动领取派遣奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：派遣完成后自动领取星辰币奖励

#### 花灵密令

- `plant.elves.recvPass`：等级奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花灵密令等级奖励，不会做针对性做花灵密令任务哦，日常做其他任务会有顺带做到花灵密令的部分任务，做完了就会顺便领取
- `plant.elves.recvPassTask`：任务奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花灵密令任务奖励，不会做针对性做花灵密令任务哦，日常做其他任务会有顺带做到花灵密令的部分任务，做完了就会顺便领取

#### 花之密令

- `plant.elves.recvFlowerPass`：等级奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花之密令等级奖励
- `plant.elves.recvFlowerPassTask`：任务奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花之密令任务奖励

#### 花艺上架

- `plant.art.unlockShelf`：自动解锁花架；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动解锁花架
- `plant.art.autoPut`：自动上架；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动上架花艺，自动领取金币收益
- `plant.art.sellMode`：上架模式；控件类型：`radio`；数据类型：`string`；默认值：`"specified"`；说明：无；可选值：指定花瓶=`"specified"` / 指定花艺=`"full"` / 库存模式=`"stock"`
- `plant.art.specifiedVases`：指定花瓶；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["3001"]`；说明：指定花瓶，优先选择有库存的上架，否则进行制作，如果花朵库存不足需要配合种植开启任务优先进行使用。数组内为花瓶 ID，ID 来自第三方提供的花瓶表，不传显示名。
- `plant.art.specifiedArts`：指定花艺；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`[]`；说明：指定花艺，优先选择有库存的上架，否则进行制作，如果花朵库存不足需要配合种植开启任务优先进行使用。数组内为花艺 ID，ID 来自第三方提供的花艺表，不传显示名。
- `plant.art.flowerArtPerRack`：上架数量；控件类型：`number`；数据类型：`number`；默认值：`12`；说明：每个花架上架多少花艺
- `plant.art.exp`：花艺经验；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花艺制作经验
- `plant.art.bookReward`：图鉴奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取鲜花收藏，花瓶收藏，花艺收藏奖励

#### 花贸市场

- `plant.market.unlockShelf`：解锁货架；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费元宝解锁花贸市场货架
- `plant.market.autoPut`：自动上架；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取花贸市场收益并上架花朵，注意上架会消耗元宝，请谨慎开启！
- `plant.market.putMode`：上架策略；控件类型：`radio`；数据类型：`string`；默认值：`"inventory"`；说明：无；可选值：库存最多=`"inventory"` / 指定花朵=`"flower"`
- `plant.market.specificFlowers`：选择花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：选择要上架的花朵，可多选，库存多的优先上架。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `plant.market.priceIndex`：上架价格；控件类型：`radio`；数据类型：`string`；默认值：`"0"`；说明：无；可选值：最低=`"0"` / 中等=`"1"` / 最高=`"2"`
- `plant.market.maxSell`：上架数量；控件类型：`number`；数据类型：`number`；默认值：`25`；说明：无
- `plant.market.putPassword`：上架密码；控件类型：`text`；数据类型：`string`；默认值：`""`；说明：保护自己上架的花朵，防止被他人购买（4位数字）
- `plant.market.autoBuyPutCount`：购买上架次数；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：当免费上架次数用完时，自动花费元宝购买上架次数
- `plant.market.buyPutCount`：购买次数；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：购买多少上架次数
- `plant.market.autoBuyFromFriend`：好友摊位扫货；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动购买好友货架的花朵
- `plant.market.buyMode`：扫货策略；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：无；可选值：指定品质=`"quality"` / 指定花朵=`"flower"`
- `plant.market.buyQualities`：指定品质；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：只购买指定品质的花朵；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `plant.market.buyFlowers`：指定花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：只购买指定的花朵。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `plant.market.minPutTimeDiff`：最小上架时长；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：只购买上架时间超过此时长的花朵，0表示不限制。单位：秒

### 订单（order）

#### 居民订单

- `order.resident.normal`：居民订单；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动提交居民订单，不包括建材和绸缎订单，如果花库存不足，需要配合种植开启任务优先使用
- `order.resident.normalMaxNum`：居民订单上限；控件类型：`number`；数据类型：`number`；默认值：`1200`；说明：居民订单单日最大完成次数
- `order.resident.satin`：绸缎订单；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动提交绸缎订单，如果花库存不足，需要配合种植开启任务优先使用
- `order.resident.satinMaxNum`：绸缎订单上限；控件类型：`number`；数据类型：`number`；默认值：`120`；说明：绸缎订单单日最大完成次数
- `order.resident.building`：建材订单；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动提交建材订单，如果花库存不足，需要配合种植开启任务优先使用
- `order.resident.buildingMaxNum`：建材订单上限；控件类型：`number`；数据类型：`number`；默认值：`120`；说明：建材订单单日最大完成次数
- `order.resident.qualities`：品质限定；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：仅提交指定品质的花朵到居民订单；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`

#### 顾客订单

- `order.customer.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成顾客订单
- `order.customer.rejectEnabled`：自动拒绝；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动拒绝无法培育且库存不足的订单

#### 宫廷订单

- `order.palace.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `order.palace.qualities`：品质限定；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：仅接受指定品质的宫廷订单，不符合时自动免费刷新一次（每天限1次），刷新后仍不符合则跳过；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `order.palace.ignoreQuality`：不论品质；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后，若没有用户设置的品质，且没有免费刷新了，则会无视品质做完这个宫廷订单

#### 组团订单

- `order.group.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成团单
- `order.group.oneMore`：再来一单；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：花费元宝再来一单
- `order.group.submitOnlyCultivatedFlowers`：仅已培育；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：仅提交已培育的花朵
- `order.group.qualities`：品质限定；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：仅提交指定品质的花朵到团单；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`

### 公会（union）

#### 公会土地

- `union.land.harvest`：自动收获；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `union.land.plant`：自动种植；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动种植空闲土地，自动将不符合限定条件的已种土地替换为目标花朵
- `union.land.plantMode`：种植策略；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：三种模式均为低等级优先；可选值：指定品质=`"quality"` / 指定花朵=`"flower"` / 库存模式=`"stock"`
- `union.land.flowers`：指定品质；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：留空则不限制品质；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `union.land.specificFlowers`：指定花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：留空则不限定花朵。数组内为鲜花 ID，ID 来自第三方提供的鲜花表，不传显示名。
- `union.land.maxFlowerLevel`：最高等级限制；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：花朵等级高于该值的不种，0表示不限制，比如设置了13，就会种植低于13级且为你所有花里最低等级的花

#### 公会建设

- `union.build.video`：视频建设；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动观看视频进行公会建设
- `union.build.coin`：金币建设；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费金币进行公会建设
- `union.build.dmd`：元宝建设；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费元宝进行公会建设

#### 公会分享

- `union.flower.share`：自动分享；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动分享花到公会分享栏
- `union.flower.shareMode`：分享模式；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：选择分享模式：品质模式或指定花模式；可选值：指定品质=`"quality"` / 指定花朵=`"flower"`
- `union.flower.shareQualities`：品质限定；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：限定要分享到公会的花朵品质；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `union.flower.shareFlowers`：指定花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：选择要分享到公会的具体花朵

#### 公会摸花

- `union.flower.touch`：自动摸花；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动摸取别人分享的花
- `union.flower.touchMode`：摸花模式；控件类型：`radio`；数据类型：`string`；默认值：`"quality"`；说明：选择摸花模式：品质模式或指定花模式；可选值：指定品质=`"quality"` / 指定花朵=`"flower"`
- `union.flower.touchQualities`：品质限定；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["green","blue","purple","gold","red"]`；说明：限定要从公会拿取的花朵品质；可选值：绿=`"green"` / 蓝=`"blue"` / 紫=`"purple"` / 金=`"gold"` / 红=`"red"`
- `union.flower.touchFlowers`：指定花朵；控件类型：`multiSelect`；数据类型：`string[]`；默认值：`["23001"]`；说明：选择要从公会摸取的具体花朵

#### 公会竞赛

- `union.fmlRace.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取，完成公会竞赛任务
- `union.fmlRace.autoEnableModules`：自动启用模块；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：根据任务类型自动启用相关模块，任务完成后自动恢复原始配置
- `union.fmlRace.useSpeedCard`：种植任务用加速卡；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：公会竞赛种植任务期间临时开启加速卡，忽略加速卡上限（任务结束后自动恢复原始配置）
- `union.fmlRace.minScore`：限制分数-未升级；控件类型：`number`；数据类型：`number`；默认值：`25`；说明：接分数不低于此值的普通任务，0 表示不限制。
- `union.fmlRace.upgradedMinScore`：限制分数-升级后；控件类型：`number`；数据类型：`number`；默认值：`50`；说明：接分数不低于此值的玩家升级后任务/原金任务（双倍分数），0 表示不限制，举例：「限制分数-未升级」设置20，「限制分数-升级后」设置50，则接20分以上普通任务，50分以上升级后任务。注意！若要开启【放弃低分任务】+【自动升级任务】，需要设置「限制分数-未升级」的值乘以2大于等于「限制分数-升级后」的值。否则会出现元宝升级后把这个任务放弃的情况
- `union.fmlRace.dropLowScore`：放弃低分任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：根据「限制分数-未升级」「限制分数-升级后」的设置来判断，例：未升级设置了25，升级后设置了50，则任务列表里有小于等于25的未升级会放弃，有小于等于50的已升级会放弃。开启此项又开启了【自动升级任务】的话，必须要按照「限制分数-未升级」乘以2大于等于「限制分数-升级后」这个规则来设置，否则会出现元宝升级后把这个任务放弃的情况。
- `union.fmlRace.onlyUpgradeTask`：只接已升级任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：比如设置了限制27分，开启后就只会接大于等于54分的任务，会使公会任务做的非常慢，慎重开启（系统/玩家升级好的双倍任务比较少，捡漏的可能是比较小的哦）
- `union.fmlRace.excludeOtherUpgradeTask`：排除他人升级任务；控件类型：`switch`；数据类型：`boolean`；默认值：`true`；说明：基于礼貌的开关，开启后，公会其他玩家用元宝升级的任务就不会去接了
- `union.fmlRace.taskTypePriority`：任务优先级；控件类型：`priorityGroup`；数据类型：`object`；默认值：`{"vipShop":0,"residentOrder":0,"customerOrder":0,"materialShop":0,"palaceOrder":0,"pearlHire":0,"friendSteal":0,"artSell":0,"artCraft":0,"flowerUpgrade":0,"plantHarvest":0,"flowerCultivate":0,"animalInteract":0}`；说明：设置每种任务类型的接取优先级。数字越小越优先；填 0 表示不接此类任务。优先级相同时分数高优先。；对象键：vipShop / residentOrder / customerOrder / materialShop / palaceOrder / pearlHire / friendSteal / artSell / artCraft / flowerUpgrade / plantHarvest / flowerCultivate / animalInteract；可选值：vip商店购买=`"vipShop"` / 居民订单=`"residentOrder"` / 顾客订单=`"customerOrder"` / 材料商店购买=`"materialShop"` / 宫廷订单=`"palaceOrder"` / 珍珠采集雇佣=`"pearlHire"` / 自动偷花=`"friendSteal"` / 花艺售卖=`"artSell"` / 花艺制作=`"artCraft"` / 鲜花升级=`"flowerUpgrade"` / 种植收获=`"plantHarvest"` / 花种培育=`"flowerCultivate"` / 动物互动=`"animalInteract"`
- `union.fmlRace.autoUpgradeTask`：自动升级任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：领取任务后花费元宝自动升级，开启此项又开启了【放弃低分任务】的话，必须要按照「限制分数-未升级」的值乘以2大于等于「限制分数-升级后」的值，这个规则来设置，否则会出现元宝升级后把这个任务放弃的情况。
- `union.fmlRace.deleteLowScoreTask`：删除低分任务；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：会长/副会长专属：自动删除低于指定分数的任务
- `union.fmlRace.deleteTaskMaxScore`：删除分数上限；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：低于此分数的未领取任务将被自动删除
- `union.fmlRace.keepSystemUpgrade`：保留原金；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会保留系统自动出的原金任务，不判断分数
- `union.fmlRace.keepPlayerUpgrade`：保留已升级；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会保留玩家用元宝升级过的任务，不判断分数

#### 公会竞赛积分兑换

- `union.exchange.autoRecv`：自动领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会自动领取公会竞赛奖励，并且兑换材料，不想自动领取的勿开

#### 能量森林

- `union.energyForest.collect`：自动收集能量；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

### 活动（activity）

#### 花笺集芳

- `activity.flowerLetter.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成花笺集芳任务，自动领取阶段宝箱奖励
- `activity.flowerLetter.unlockSlot`：解锁槽位；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动花费元宝解锁任务槽位
- `activity.flowerLetter.autoEnableModules`：自动开启模块；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：根据任务自动启用对应功能模块（种植、花艺售卖、居民订单、顾客订单、珍珠雇佣等），任务完成后自动恢复到您开始的设置

#### 莳花纪闻

- `activity.flowerNews.enabled`：自动完成；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成莳花纪闻订单任务，如果花库存不足，需要配合种植开启任务优先使用
- `activity.flowerNews.refreshEnabled`：元宝刷新；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：花费元宝立即刷新莳花纪闻订单任务
- `activity.flowerNews.maxFinishCountPerBatch`：完成分数；控件类型：`number`；数据类型：`number`；默认值：`0`；说明：每期活动最多完成多少分，即获得花史残页数量，0则不限制

#### 丰仓鱼干

- `activity.fishDry.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.fishDry.showResult`：显示结果；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.fishDry.autoRestart`：失败重启；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

#### 奇妙泡泡

- `activity.bubble.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

#### 鱼乐无穷

- `activity.fishFun.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.fishFun.autoClaimEnergy`：体力领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成后的体力奖励
- `activity.fishFun.speed`：游戏倍速；控件类型：`select`；数据类型：`string`；默认值：`"normal"`；说明：选择游戏倍速，倍速越高单次移动消耗体力越多；可选值：慢速=`"slow"` / 普通=`"normal"` / 快速=`"fast"`
- `activity.fishFun.showResult`：显示结果；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.fishFun.autoRestart`：失败重启；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

#### 花漾物语

- `activity.flowerStory.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.flowerStory.autoClaimEnergy`：体力领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成后的体力奖励
- `activity.flowerStory.speed`：游戏倍速；控件类型：`select`；数据类型：`string`；默认值：`"normal"`；说明：选择游戏倍速，倍速越高单次移动消耗体力越多；可选值：慢速=`"slow"` / 普通=`"normal"` / 快速=`"fast"`

#### 红包雨

- `activity.redPacket.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动抢红包

#### 迎新接福

- `activity.recvLuck.enabled`：自动领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取福袋

#### 杨紫打call

- `activity.call.enabled`：自动打call；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动为杨紫打call活动

#### 为家业助力

- `activity.familyHelp.enabled`：自动助力；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动为家业助力
- `activity.familyHelp.recvBoxes`：领取助力奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取家业助力的奖励

#### 摇钱树

- `activity.moneyTree.enabled`：自动摇钱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无

#### 花香满园

- `activity.zooGameElim.enabled`：自动参与；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动进行花香满园消消乐活动

#### 元宵灯谜

- `activity.lantern.enabled`：自动答题；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动完成元宵灯谜答题并领取奖励

#### 香卉甜糕

- `activity.cake.enabled`：自动投放；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.cake.autoClaimEnergy`：体力领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成后的体力奖励
- `activity.cake.useItems`：使用道具；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.cake.speed`：游戏倍速；控件类型：`select`；数据类型：`string`；默认值：`"normal"`；说明：选择游戏倍速，倍速越高单次消耗体力越多；需要解锁足够积分才能使用高倍速；可选值：慢速=`"slow"` / 普通=`"normal"` / 快速=`"fast"`

#### 田园奇趣

- `activity.merge.enabled`：自动合并；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.merge.autoClaimEnergy`：体力领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成后的体力奖励
- `activity.merge.speed`：游戏倍速；控件类型：`select`；数据类型：`string`；默认值：`"normal"`；说明：选择游戏倍速，倍速越高单次消耗体力越多；需要消耗足够体力才能使用高倍速；可选值：慢速=`"slow"` / 普通=`"normal"` / 快速=`"fast"`

#### 梳丝引线

- `activity.spool.enabled`：自动玩；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：无
- `activity.spool.autoClaimReward`：体力领取；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动领取每日任务完成后的体力奖励
- `activity.spool.openBox`：开启宝箱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：自动开启宝箱
- `activity.spool.autoRestart`：自动重开；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后，死了就会重新开始继续玩
- `activity.spool.speed`：游戏倍数；控件类型：`select`；数据类型：`string`；默认值：`"normal"`；说明：选择游戏倍速，倍速越高单次消耗体力越多；需要消耗足够体力才能使用高倍速；可选值：慢速=`"slow"` / 普通=`"normal"` / 快速=`"fast"`

#### 龙舟竞渡

- `activity.dragonBoat.enabled`：参赛；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会玩龙舟
- `activity.dragonBoat.autoSign`：签到；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会签到
- `activity.dragonBoat.autoOpenBox`：开宝箱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：开启后会开宝箱
- `activity.dragonBoat.giftBuy`：购买元宝道具；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：慎重开启，开启后会总共花费900元宝购买5次龙舟鼓

#### 百花成蜜

- `activity.honey.reward`：领奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：只会领奖励，不会做指定任务

#### 卡册

- `activity.card.reward`：领取奖励；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：会领取福利卡包，开启卡包
- `activity.card.smoke`：凝烟成炱；控件类型：`switch`；数据类型：`boolean`；默认值：`false`；说明：会领取灯芯草并且按顺序凝烟

## JSON Schema

机器校验文件见同目录 `third-party-game-config.schema.json`。
