# 第三方游戏配置 JSON 对接说明

本文档说明启动游戏账号时传给第三方的 WebSocket 载荷结构，以及其中 `config` 游戏配置 JSON 的完整字段。字段由用户端配置 schema 生成，字段名与实际保存/发送结构一致。

## 启动载荷

正式第三方接入固定使用 WebSocket。用户点击启动后，服务端会向第三方连接发送下面的 `start` 包。`game_password` 只在启动通信中传递，不写入 `config`。

`request_id` 是本次请求编号，不是游戏账号、区服或角色 ID；第三方回 `started/error` 时建议原样带回。当前游戏只有一个区服，启动包不传 `server_id`、`server_name`。同一长连接可能承载多个账号，所有账号相关消息都必须带 `account_id`。

```json
{
  "type": "start",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "account_id": 10001,
  "game_username": "game_account_001",
  "game_password": "plain-password-used-only-during-start",
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
        "selectedIds": "",
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
  "account_id": 10001
}
```

### 第三方返回 started

```json
{
  "type": "started",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "account_id": 10001,
  "display_name": "role-name"
}
```

### 第三方返回 error

```json
{
  "type": "error",
  "request_id": "8f2e2e7c2d834f4f9f8e93b8fd15c111",
  "account_id": 10001,
  "message": "login failed"
}
```

### 第三方推送 log

```json
{
  "type": "log",
  "account_id": 10001,
  "level": "info",
  "category": "runtime",
  "time": "2026-07-04 12:00:00",
  "message": "started"
}
```

### 第三方推送 status

```json
{
  "type": "status",
  "account_id": 10001,
  "level": 14,
  "water": 1,
  "diamond": 754,
  "coin": 236000
}
```

## config 完整字段

| 分页 | 分组 | 字段路径 | 类型 | 默认值 | 说明 | 选项 |
| --- | --- | --- | --- | --- | --- | --- |
| 基础 | 基础设置 | `basic.reputation.enabled` | switch | `false` | 每10分钟检查礼仪分，低于阈值时自动停止所有任务 | - |
| 基础 | 基础设置 | `basic.reputation.threshold` | number | `80` | 礼仪分低于此值时停止所有任务 | - |
| 基础 | 基础设置 | `basic.debug` | switch | `false` | 开启后记录道具增加和消耗详情 | - |
| 基础 | 基础设置 | `basic.reconnectInterval` | number | `5` | 网络异常断开后自动重连间隔，默认设置为 5 分钟 | - |
| 基础 | 任务配置 | `basic.task.daily` | switch | `false` | 自动领取每日任务完成奖励，阶段宝箱奖励 | - |
| 基础 | 任务配置 | `basic.task.weekly` | switch | `false` | 自动领取每周任务完成奖励 | - |
| 基础 | 任务配置 | `basic.task.main` | switch | `false` | 自动领取主线任务完成奖励 | - |
| 基础 | 任务配置 | `basic.task.story` | switch | `false` | 自动领取主线剧情任务奖励 | - |
| 基础 | 任务配置 | `basic.task.achieve` | switch | `false` | 自动领取花坊悬赏完成奖励 | - |
| 基础 | 邮件配置 | `basic.mail` | switch | `false` | 自动领取邮件奖励 | - |
| 基础 | 福利配置 | `basic.benefit.buff` | switch | `false` | 自动领取双倍金币福利 | - |
| 基础 | 福利配置 | `basic.benefit.box` | switch | `false` | 每1小时自动开启福利宝箱 | - |
| 基础 | 福利配置 | `basic.benefit.shareRwd` | switch | `false` | 当制作了新花艺、培育了新花朵或升级时自动分享，领取分享奖励 | - |
| 基础 | 福利配置 | `basic.benefit.antiFraudBox` | switch | `false` | 每天自动签到并领取防骗宝箱奖励 | - |
| 基础 | 每日祈愿 | `basic.sign.daily` | switch | `false` | 自动祈愿 | - |
| 基础 | 每日祈愿 | `basic.sign.patch` | switch | `false` | 自动补签 | - |
| 基础 | 珍珠配置 | `basic.pearl.freePearl` | switch | `false` | 自动看视频领取免费珍珠 | - |
| 基础 | 珍珠配置 | `basic.pearl.autoHire` | switch | `false` | 自动雇佣劳工 | - |
| 基础 | 珍珠配置 | `basic.pearl.maxHireLevel` | number | `0` | 超过该等级后不再执行对应操作，0 表示不限制。 | - |
| 基础 | 珍珠配置 | `basic.pearl.maxHireTicketUsage` | number | `0` | 限制自动雇佣可使用的雇佣券数量，0 表示不限制。 | - |
| 基础 | 珍珠配置 | `basic.pearl.open` | switch | `false` | 自动开珍珠 | - |
| 基础 | 珍珠配置 | `basic.pearl.protectEnabled` | switch | `false` | 开启后会保留玩家用元宝开启的防身状态 | - |
| 基础 | 珍珠配置 | `basic.pearl.buyHireBook` | switch | `false` | 自动购买雇佣书 | - |
| 基础 | 珍珠配置 | `basic.pearl.maxSpendDmd` | number | `25` | 限制本功能最多消耗的元宝数量，0 表示不消耗。 | - |
| 基础 | 商城购买 | `basic.shop.videoGift` | switch | `false` | 自动领取视频礼包 | - |
| 基础 | 商城购买 | `basic.shop.cultivateShop.autoBuy` | switch | `false` | 自动购买材料商店道具 | - |
| 基础 | 商城购买 | `basic.shop.cultivateShop.maxSpendGold` | number | `0` | 限制本功能最多消耗的金币数量，0 表示不限制。 | - |
| 基础 | 商城购买 | `basic.shop.vipShop.autoBuy` | switch | `false` | 自动购买VIP商店道具 | - |
| 基础 | 商城购买 | `basic.shop.vipShop.maxSpendDmd` | number | `0` | 限制本功能最多消耗的元宝数量，0 表示不消耗。 | - |
| 基础 | 商城购买 | `basic.shop.vipShop.maxSpendFloralCoin` | number | `0` | 限制本功能最多消耗的花坊币数量，0 表示不限制。 | - |
| 基础 | 随机事件 | `basic.randomEvent.enabled` | switch | `false` | 自动处理随机事件 | - |
| 基础 | 喂猫撸猫 | `basic.cat.enabled` | switch | `false` | 总开关 | - |
| 基础 | 喂猫撸猫 | `basic.cat.autoRecall` | switch | `false` | 开启后自动召回外出的猫。 | - |
| 基础 | 喂猫撸猫 | `basic.cat.autoBuyFood` | switch | `false` | 猫粮不足时自动购买。 | - |
| 基础 | 喂猫撸猫 | `basic.cat.autoFeed` | switch | `false` | 开启后自动完成喂猫。 | - |
| 基础 | 喂猫撸猫 | `basic.cat.autoStroke` | switch | `false` | 开启后自动完成撸猫互动。 | - |
| 种植 | 培育配置 | `plant.cultivate.enabled` | switch | `false` | 自动培育可培育花种 | - |
| 种植 | 培育配置 | `plant.cultivate.videoSpeedup` | switch | `false` | 自动观看视频加速培育正在培育的花种，培育时间减半 | - |
| 种植 | 培育配置 | `plant.cultivate.upgrade` | switch | `false` | 自动花费金币进行鲜花升级 | - |
| 种植 | 培育配置 | `plant.cultivate.targetLevel` | number | `20` | 自动培育或升级时使用的目标等级 | - |
| 种植 | 水滴配置 | `plant.water.enabled` | switch | `false` | 3分钟领取一次水滴，这样才能最大化暴击，所以领取会略慢。 | - |
| 种植 | 水滴配置 | `plant.water.timedEnabled` | switch | `false` | 开启后领取限时水滴。 | - |
| 种植 | 水滴配置 | `plant.water.threshold` | number | `0` | 水滴低于阈值时停止消耗，避免影响后续任务 | - |
| 种植 | 水滴配置 | `plant.water.forceCollectEnabled` | switch | `false` | 无视水滴阈值直接领取或消耗水滴 | - |
| 种植 | 水滴配置 | `plant.water.forceCollectTime` | text | `空字符串` | 强制领取水滴的时间配置，按第三方脚本支持格式填写。 | - |
| 种植 | 种花配置 | `plant.flower.unlockLand` | switch | `false` | 自动花费金币解锁可解锁的土地 | - |
| 种植 | 种花配置 | `plant.flower.harvestEnabled` | switch | `false` | 自动完成土地收获 | - |
| 种植 | 种花配置 | `plant.flower.plantEnabled` | switch | `false` | 自动完成土地浇水，加速，种植 | - |
| 种植 | 种花配置 | `plant.flower.videoSpeedup` | switch | `false` | 自动观看视频加速培育正在培育的花种，培育时间减半 | - |
| 种植 | 种花配置 | `plant.flower.useSpeedCard` | switch | `false` | 开启后种植过程允许使用加速卡。 | - |
| 种植 | 种花配置 | `plant.flower.speedCardLimit` | number | `20` | 限制本次功能最多使用的加速卡数量。 | - |
| 种植 | 种花配置 | `plant.flower.waterThreshold` | number | `0` | 水滴低于该值时停止消耗。 | - |
| 种植 | 种花配置 | `plant.flower.taskMode` | switch | `true` | 开启后按下方任务优先级执行种花任务。 | - |
| 种植 | 种花配置 | `plant.flower.taskLogEnabled` | switch | `false` | 记录种植、收获和任务缺口等执行日志 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.customerOrder` | number | `1` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.residentOrder` | number | `2` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.artSell` | number | `6` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.flowerNews` | number | `3` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.palaceOrder` | number | `4` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.taskPriority.unionRace` | number | `3` | 数字越小优先级越高，按原版配置项填写。 | - |
| 种植 | 种花配置 | `plant.flower.plantingMode` | radio | `quality` | 选择自动种植使用的匹配策略。 | 指定品质 / 指定种类 / 指定花朵 / 库存模式 / 64块地模式 |
| 种植 | 种花配置 | `plant.flower.flowerQuality` | multiSelect | `["green","blue","purple","gold","red"]` | 选择允许参与该功能的花朵品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 种植 | 种花配置 | `plant.flower.categoryCount` | radio | `"4"` | 指定种类模式下选择种类数量。 | 1 / 2 / 3 / 4 |
| 种植 | 种花配置 | `plant.flower.specificFlowers` | multiSelect | `["23001"]` | 指定花朵模式下选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 种植 | 种花配置 | `plant.flower.minFlowerLevel` | number | `0` | 花朵等级超过该值后不参与，0 表示不限制。 | - |
| 种植 | 好友偷花 | `plant.friendSteal.enabled` | switch | `false` | 默认不会偷取花灵，但在好友种植花灵时会偷取花朵，需要在偷花模式里设置排除花朵，排除花灵主花 | - |
| 种植 | 好友偷花 | `plant.friendSteal.includeElf` | switch | `false` | 好友偷花时包含花灵相关地块。 | - |
| 种植 | 好友偷花 | `plant.friendSteal.stealMode` | radio | `quality` | 选择好友偷花的目标匹配方式。 | 指定品质 / 指定花朵 / 排除花朵 |
| 种植 | 好友偷花 | `plant.friendSteal.qualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 种植 | 好友偷花 | `plant.friendSteal.specificFlowers` | multiSelect | `["23001"]` | 指定花朵偷花时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 种植 | 好友偷花 | `plant.friendSteal.excludeFlowers` | multiSelect | `[]` | 排除花朵模式下选择不偷取的鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 种植 | 好友偷花 | `plant.friendSteal.buyCount` | switch | `false` | 偷花次数不足时自动购买次数 | - |
| 种植 | 好友偷花 | `plant.friendSteal.buyStealCount` | number | `0` | 限制自动购买偷花次数的数量。 | - |
| 种植 | 花灵 | `plant.elves.plant` | switch | `false` | 优先种植指定花灵，否则选择当期双倍加成花灵种植（8朵主花+其余辅花），需要打开种植系统自动收获和自动种植，每日花灵达到收获上限后恢复到原有种植模式 | - |
| 种植 | 花灵 | `plant.elves.selectedIds` | text | `空字符串` | 填写需要自动种植的花灵标识，多个值按第三方脚本支持格式分隔。 | - |
| 种植 | 花灵 | `plant.elves.applyAid` | switch | `false` | 自动申请协助 | - |
| 种植 | 花灵 | `plant.elves.recvAid` | switch | `false` | 当协助人数达到5人时自动领取协助加成 | - |
| 种植 | 花灵 | `plant.elves.helpFriend` | switch | `false` | 自动协助好友 | - |
| 种植 | 花灵 | `plant.elves.dispatch` | switch | `false` | 自动将背包中的花灵派遣到空闲位置 | - |
| 种植 | 花灵 | `plant.elves.speedupDispatch` | switch | `false` | 花费元宝加速派遣中的花灵 | - |
| 种植 | 花灵 | `plant.elves.recvDispatchReward` | switch | `false` | 派遣完成后自动领取星辰币奖励 | - |
| 种植 | 花灵 | `plant.elves.recvPass` | switch | `false` | 自动领取通行证奖励。 | - |
| 种植 | 花灵 | `plant.elves.recvPassTask` | switch | `false` | 自动处理通行证任务奖励。 | - |
| 种植 | 花灵 | `plant.elves.recvFlowerPass` | switch | `false` | 自动领取花灵通行证奖励。 | - |
| 种植 | 花灵 | `plant.elves.recvFlowerPassTask` | switch | `false` | 自动处理花灵通行证任务奖励。 | - |
| 种植 | 花艺上架 | `plant.art.unlockShelf` | switch | `false` | 自动解锁花架 | - |
| 种植 | 花艺上架 | `plant.art.autoPut` | switch | `false` | 自动上架花艺，自动领取金币收益 | - |
| 种植 | 花艺上架 | `plant.art.sellMode` | radio | `specified` | 选择花艺上架售卖策略。 | 指定花瓶 / 指定花艺 / 库存模式 |
| 种植 | 花艺上架 | `plant.art.specifiedVases` | multiSelect | `["3001"]` | 指定花瓶模式下选择花瓶 ID，ID 以第三方提供的花瓶表为准。 | 花瓶 ID |
| 种植 | 花艺上架 | `plant.art.specifiedArts` | multiSelect | `[]` | 指定花艺模式下选择花艺 ID，ID 以第三方提供的花艺表为准。 | 花艺 ID |
| 种植 | 花艺上架 | `plant.art.flowerArtPerRack` | number | `12` | 每个货架上架的花艺数量。 | - |
| 种植 | 花艺上架 | `plant.art.exp` | switch | `false` | 自动领取花艺制作经验 | - |
| 种植 | 花艺上架 | `plant.art.bookReward` | switch | `false` | 自动领取鲜花收藏，花瓶收藏，花艺收藏奖励 | - |
| 种植 | 花贸市场 | `plant.market.unlockShelf` | switch | `false` | 自动花费元宝解锁花贸市场货架 | - |
| 种植 | 花贸市场 | `plant.market.autoPut` | switch | `false` | 自动领取花贸市场收益并上架花朵，注意上架会消耗元宝，请谨慎开启！ | - |
| 种植 | 花贸市场 | `plant.market.putMode` | radio | `inventory` | 选择花贸市场自动上架策略。 | 库存最多 / 指定花朵 |
| 种植 | 花贸市场 | `plant.market.specificFlowers` | multiSelect | `["23001"]` | 指定花朵上架时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 种植 | 花贸市场 | `plant.market.priceIndex` | radio | `"0"` | 花贸市场上架价格档位。 | 最低 / 中等 / 最高 |
| 种植 | 花贸市场 | `plant.market.maxSell` | number | `25` | 限制花贸市场本次最多上架数量。 | - |
| 种植 | 花贸市场 | `plant.market.putPassword` | text | `空字符串` | 需要口令时填写，不需要可留空。 | - |
| 种植 | 花贸市场 | `plant.market.autoBuyPutCount` | switch | `false` | 次数不足时自动购买花贸市场上架次数。 | - |
| 种植 | 花贸市场 | `plant.market.buyPutCount` | number | `0` | 限制自动购买上架次数的数量。 | - |
| 种植 | 花贸市场 | `plant.market.autoBuyFromFriend` | switch | `false` | 自动购买好友货架的花朵 | - |
| 种植 | 花贸市场 | `plant.market.buyMode` | radio | `quality` | 选择从好友市场购买时的目标匹配方式。 | 指定品质 / 指定花朵 |
| 种植 | 花贸市场 | `plant.market.buyFlowers` | multiSelect | `["23001"]` | 好友摊位扫货指定花朵时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 种植 | 花贸市场 | `plant.market.minPutTimeDiff` | number | `0` | 只购买达到该上架时间间隔的商品。 | - |
| 订单 | 居民订单 | `order.resident.normal` | switch | `false` | 自动提交居民订单，不包括建材和绸缎订单，如果花库存不足，需要配合种植开启任务优先使用 | - |
| 订单 | 居民订单 | `order.resident.normalMaxNum` | number | `1200` | 居民订单自动完成数量上限。 | - |
| 订单 | 居民订单 | `order.resident.satin` | switch | `false` | 自动提交绸缎订单，如果花库存不足，需要配合种植开启任务优先使用 | - |
| 订单 | 居民订单 | `order.resident.satinMaxNum` | number | `120` | 绸缎订单自动完成数量上限。 | - |
| 订单 | 居民订单 | `order.resident.building` | switch | `false` | 自动提交建材订单，如果花库存不足，需要配合种植开启任务优先使用 | - |
| 订单 | 居民订单 | `order.resident.buildingMaxNum` | number | `120` | 建材订单自动完成数量上限。 | - |
| 订单 | 居民订单 | `order.resident.qualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 订单 | 顾客订单 | `order.customer.enabled` | switch | `false` | 自动完成顾客订单 | - |
| 订单 | 顾客订单 | `order.customer.rejectEnabled` | switch | `false` | 顾客订单不满足条件时自动拒绝。 | - |
| 订单 | 宫廷订单 | `order.palace.enabled` | switch | `false` | 自动完成 | - |
| 订单 | 宫廷订单 | `order.palace.qualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 订单 | 宫廷订单 | `order.palace.ignoreQuality` | switch | `false` | 开启后宫廷订单不按品质过滤。 | - |
| 订单 | 组团订单 | `order.group.enabled` | switch | `false` | 自动完成团单 | - |
| 订单 | 组团订单 | `order.group.oneMore` | switch | `false` | 组队订单完成后继续接取下一单。 | - |
| 订单 | 组团订单 | `order.group.submitOnlyCultivatedFlowers` | switch | `false` | 组队订单只提交已培育花朵。 | - |
| 订单 | 组团订单 | `order.group.qualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 公会 | 公会土地 | `union.land.harvest` | switch | `false` | 自动收获 | - |
| 公会 | 公会土地 | `union.land.plant` | switch | `false` | 自动种植空闲土地，自动将不符合限定条件的已种土地替换为目标花朵 | - |
| 公会 | 公会土地 | `union.land.plantMode` | radio | `quality` | 选择公会土地自动种植策略。 | 指定品质 / 指定花朵 / 库存模式 |
| 公会 | 公会土地 | `union.land.flowers` | multiSelect | `["green","blue","purple","gold","red"]` | 选择允许参与该功能的花朵品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 公会 | 公会土地 | `union.land.specificFlowers` | multiSelect | `["23001"]` | 公会土地指定花朵种植时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 公会 | 公会土地 | `union.land.maxFlowerLevel` | number | `0` | 超过该等级的花朵不参与，0 表示不限制。 | - |
| 公会 | 公会建设 | `union.build.video` | switch | `false` | 自动观看视频进行公会建设 | - |
| 公会 | 公会建设 | `union.build.coin` | switch | `false` | 自动花费金币进行公会建设 | - |
| 公会 | 公会建设 | `union.build.dmd` | switch | `false` | 自动花费元宝进行公会建设 | - |
| 公会 | 公会分享 | `union.flower.share` | switch | `false` | 自动分享花到公会分享栏 | - |
| 公会 | 公会分享 | `union.flower.shareMode` | radio | `quality` | 选择公会自动分享花朵的匹配方式。 | 指定品质 / 指定花朵 |
| 公会 | 公会分享 | `union.flower.shareQualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 公会 | 公会分享 | `union.flower.shareFlowers` | multiSelect | `["23001"]` | 公会分享指定花朵时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 公会 | 公会摸花 | `union.flower.touch` | switch | `false` | 自动摸取别人分享的花 | - |
| 公会 | 公会摸花 | `union.flower.touchMode` | radio | `quality` | 选择公会自动摸花的匹配方式。 | 指定品质 / 指定花朵 |
| 公会 | 公会摸花 | `union.flower.touchQualities` | multiSelect | `["green","blue","purple","gold","red"]` | 只处理选中的品质。 | 绿 / 蓝 / 紫 / 金 / 红 |
| 公会 | 公会摸花 | `union.flower.touchFlowers` | multiSelect | `["23001"]` | 公会摸花指定花朵时选择鲜花 ID，ID 以第三方提供的鲜花表为准。 | 鲜花 ID |
| 公会 | 公会竞赛 | `union.fmlRace.enabled` | switch | `false` | 自动领取，完成公会竞赛任务 | - |
| 公会 | 公会竞赛 | `union.fmlRace.autoEnableModules` | switch | `false` | 根据任务类型自动启用相关模块，任务完成后自动恢复原始配置 | - |
| 公会 | 公会竞赛 | `union.fmlRace.useSpeedCard` | switch | `false` | 公会竞赛种植任务期间临时开启加速卡，忽略加速卡上限（任务结束后自动恢复原始配置） | - |
| 公会 | 公会竞赛 | `union.fmlRace.minScore` | number | `25` | 接分数不低于此值的普通任务，0 表示不限制。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.upgradedMinScore` | number | `50` | 接分数不低于此值的玩家升级后任务/原金任务（双倍分数），0 表示不限制，举例：「限制分数-未升级」设置20，「限制分数-升级后」设置50，则接20分以上普通任务，50分以上升级后任务。注意！若要开启【放弃低分任务】+【自动升级任务】，需要设置「限制分数-未升级」的值乘以2大于等于「限制分数-升级后」的值。否则会出现元宝升级后把这个任务放弃的情况 | - |
| 公会 | 公会竞赛 | `union.fmlRace.dropLowScore` | switch | `false` | 根据「限制分数-未升级」「限制分数-升级后」的设置来判断，例：未升级设置了25，升级后设置了50，则任务列表里有小于等于25的未升级会放弃，有小于等于50的已升级会放弃。开启此项又开启了【自动升级任务】的话，必须要按照「限制分数-未升级」乘以2大于等于「限制分数-升级后」这个规则来设置，否则会出现元宝升级后把这个任务放弃的情况。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.onlyUpgradeTask` | switch | `false` | 比如设置了限制27分，开启后就只会接大于等于54分的任务，会使公会任务做的非常慢，慎重开启（系统/玩家升级好的双倍任务比较少，捡漏的可能是比较小的哦） | - |
| 公会 | 公会竞赛 | `union.fmlRace.excludeOtherUpgradeTask` | switch | `true` | 基于礼貌的开关，开启后，公会其他玩家用元宝升级的任务就不会去接了 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.vipShop` | number | `0` | 公会竞赛任务类型优先级：vip商店购买，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.residentOrder` | number | `0` | 公会竞赛任务类型优先级：居民订单，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.customerOrder` | number | `0` | 公会竞赛任务类型优先级：顾客订单，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.materialShop` | number | `0` | 公会竞赛任务类型优先级：材料商店购买，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.palaceOrder` | number | `0` | 公会竞赛任务类型优先级：宫廷订单，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.pearlHire` | number | `0` | 公会竞赛任务类型优先级：珍珠采集雇佣，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.friendSteal` | number | `0` | 公会竞赛任务类型优先级：好友偷花，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.artSell` | number | `0` | 公会竞赛任务类型优先级：花艺售卖，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.artCraft` | number | `0` | 公会竞赛任务类型优先级：花艺制作，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.flowerUpgrade` | number | `0` | 公会竞赛任务类型优先级：鲜花升级，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.plantHarvest` | number | `0` | 公会竞赛任务类型优先级：种植收获，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.flowerCultivate` | number | `0` | 公会竞赛任务类型优先级：花种培育，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.taskTypePriority.animalInteract` | number | `0` | 公会竞赛任务类型优先级：动物互动，数字越小优先级越高。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.autoUpgradeTask` | switch | `false` | 领取任务后花费元宝自动升级，开启此项又开启了【放弃低分任务】的话，必须要按照「限制分数-未升级」的值乘以2大于等于「限制分数-升级后」的值，这个规则来设置，否则会出现元宝升级后把这个任务放弃的情况。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.deleteLowScoreTask` | switch | `false` | 会长/副会长专属：自动删除低于指定分数的任务 | - |
| 公会 | 公会竞赛 | `union.fmlRace.deleteTaskMaxScore` | number | `0` | 低于或等于该分数的任务可被删除，0 表示不删除。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.keepSystemUpgrade` | switch | `false` | 删除低分任务时保留系统原有金色升级任务。 | - |
| 公会 | 公会竞赛 | `union.fmlRace.keepPlayerUpgrade` | switch | `false` | 删除低分任务时保留玩家已升级任务。 | - |
| 公会 | 公会竞赛积分兑换 | `union.exchange.autoRecv` | switch | `false` | 开启后会自动领取公会竞赛奖励，并且兑换材料，不想自动领取的勿开 | - |
| 公会 | 能量森林 | `union.energyForest.collect` | switch | `false` | 自动收集能量 | - |
| 活动 | 花笺集芳 | `activity.flowerLetter.enabled` | switch | `false` | 自动完成花笺集芳任务，自动领取阶段宝箱奖励 | - |
| 活动 | 花笺集芳 | `activity.flowerLetter.unlockSlot` | switch | `false` | 活动中自动解锁可用槽位。 | - |
| 活动 | 花笺集芳 | `activity.flowerLetter.autoEnableModules` | switch | `false` | 活动开启后自动启用相关模块。 | - |
| 活动 | 莳花纪闻 | `activity.flowerNews.enabled` | switch | `false` | 自动完成莳花纪闻订单任务，如果花库存不足，需要配合种植开启任务优先使用 | - |
| 活动 | 莳花纪闻 | `activity.flowerNews.refreshEnabled` | switch | `false` | 允许活动中使用元宝刷新。 | - |
| 活动 | 莳花纪闻 | `activity.flowerNews.maxFinishCountPerBatch` | number | `0` | 限制每批次完成数量或分数，0 表示不限制。 | - |
| 活动 | 丰仓鱼干 | `activity.fishDry.enabled` | switch | `false` | 自动参与 | - |
| 活动 | 丰仓鱼干 | `activity.fishDry.showResult` | switch | `false` | 活动完成后显示结果信息。 | - |
| 活动 | 丰仓鱼干 | `activity.fishDry.autoRestart` | switch | `false` | 活动结束后自动重新开始。 | - |
| 活动 | 奇妙泡泡 | `activity.bubble.enabled` | switch | `false` | 自动参与 | - |
| 活动 | 鱼乐无穷 | `activity.fishFun.enabled` | switch | `false` | 自动参与 | - |
| 活动 | 鱼乐无穷 | `activity.fishFun.autoClaimEnergy` | switch | `false` | 自动领取活动体力。 | - |
| 活动 | 鱼乐无穷 | `activity.fishFun.speed` | radio | `normal` | 选择活动执行速度。 | 慢速 / 普通 / 快速 |
| 活动 | 鱼乐无穷 | `activity.fishFun.showResult` | switch | `false` | 活动完成后显示结果信息。 | - |
| 活动 | 鱼乐无穷 | `activity.fishFun.autoRestart` | switch | `false` | 活动结束后自动重新开始。 | - |
| 活动 | 花漾物语 | `activity.flowerStory.enabled` | switch | `false` | 自动参与 | - |
| 活动 | 花漾物语 | `activity.flowerStory.autoClaimEnergy` | switch | `false` | 自动领取活动体力。 | - |
| 活动 | 花漾物语 | `activity.flowerStory.speed` | radio | `normal` | 选择活动执行速度。 | 慢速 / 普通 / 快速 |
| 活动 | 红包雨 | `activity.redPacket.enabled` | switch | `false` | 自动抢红包 | - |
| 活动 | 迎新接福 | `activity.recvLuck.enabled` | switch | `false` | 自动领取福袋 | - |
| 活动 | 杨紫打call | `activity.call.enabled` | switch | `false` | 自动为杨紫打call活动 | - |
| 活动 | 为家业助力 | `activity.familyHelp.enabled` | switch | `false` | 自动为家业助力 | - |
| 活动 | 为家业助力 | `activity.familyHelp.recvBoxes` | switch | `false` | 自动领取活动宝箱。 | - |
| 活动 | 摇钱树 | `activity.moneyTree.enabled` | switch | `false` | 自动摇钱 | - |
| 活动 | 花香满园 | `activity.zooGameElim.enabled` | switch | `false` | 自动进行花香满园消消乐活动 | - |
| 活动 | 元宵灯谜 | `activity.lantern.enabled` | switch | `false` | 自动完成元宵灯谜答题并领取奖励 | - |
| 活动 | 香卉甜糕 | `activity.cake.enabled` | switch | `false` | 自动投放 | - |
| 活动 | 香卉甜糕 | `activity.cake.autoClaimEnergy` | switch | `false` | 自动领取活动体力。 | - |
| 活动 | 香卉甜糕 | `activity.cake.useItems` | switch | `false` | 活动中允许自动使用道具。 | - |
| 活动 | 香卉甜糕 | `activity.cake.speed` | radio | `normal` | 选择活动执行速度。 | 慢速 / 普通 / 快速 |
| 活动 | 田园奇趣 | `activity.merge.enabled` | switch | `false` | 自动合并 | - |
| 活动 | 田园奇趣 | `activity.merge.autoClaimEnergy` | switch | `false` | 自动领取活动体力。 | - |
| 活动 | 田园奇趣 | `activity.merge.speed` | radio | `normal` | 选择活动执行速度。 | 慢速 / 普通 / 快速 |
| 活动 | 梳丝引线 | `activity.spool.enabled` | switch | `false` | 自动玩 | - |
| 活动 | 梳丝引线 | `activity.spool.autoClaimReward` | switch | `false` | 自动领取活动奖励。 | - |
| 活动 | 梳丝引线 | `activity.spool.openBox` | switch | `false` | 自动打开活动宝箱。 | - |
| 活动 | 梳丝引线 | `activity.spool.autoRestart` | switch | `false` | 活动结束后自动重新开始。 | - |
| 活动 | 梳丝引线 | `activity.spool.speed` | radio | `normal` | 选择活动执行速度。 | 慢速 / 普通 / 快速 |
| 活动 | 龙舟竞渡 | `activity.dragonBoat.enabled` | switch | `false` | 开启后会玩龙舟 | - |
| 活动 | 龙舟竞渡 | `activity.dragonBoat.autoSign` | switch | `false` | 活动中自动签到。 | - |
| 活动 | 龙舟竞渡 | `activity.dragonBoat.autoOpenBox` | switch | `false` | 活动中自动打开宝箱。 | - |
| 活动 | 龙舟竞渡 | `activity.dragonBoat.giftBuy` | switch | `false` | 允许活动中自动购买礼包。 | - |
| 活动 | 百花成蜜 | `activity.honey.reward` | switch | `false` | 只会领奖励，不会做指定任务 | - |
| 活动 | 卡册 | `activity.card.reward` | switch | `false` | 会领取福利卡包，开启卡包 | - |
| 活动 | 卡册 | `activity.card.smoke` | switch | `false` | 会领取灯芯草并且按顺序凝烟 | - |

## JSON Schema

机器校验文件见同目录 `third-party-game-config.schema.json`。
