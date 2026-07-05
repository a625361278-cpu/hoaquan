import { FLOWER_ART_OPTIONS, FLOWER_OPTIONS, VASE_OPTIONS } from './gameAssetOptions';

export const PREVIEW_CHANNEL = {
  code: 'official_app',
  titleKey: 'client.add.channel.official_app',
  descKey: 'client.add.channel.official_app_desc',
};

export const PREVIEW_SERVER = {
  id: 'local-preview',
  nameKey: 'client.add.server.local_preview',
  descKey: 'client.add.server.local_preview_desc',
};

const QUALITY_OPTIONS = [
  option('green', 'client.config.option.quality_green'),
  option('blue', 'client.config.option.quality_blue'),
  option('purple', 'client.config.option.quality_purple'),
  option('gold', 'client.config.option.quality_gold'),
  option('red', 'client.config.option.quality_red'),
];

const PLANTING_MODE_OPTIONS = [
  option('quality', 'client.config.option.mode_quality'),
  option('category', 'client.config.option.mode_category'),
  option('flower', 'client.config.option.mode_flower'),
  option('stock', 'client.config.option.mode_stock'),
  option('sixtyFour', 'client.config.option.mode_64_land'),
];

const FLOWER_MODE_OPTIONS = [
  option('quality', 'client.config.option.mode_quality'),
  option('flower', 'client.config.option.mode_flower'),
];

const STEAL_MODE_OPTIONS = [
  option('quality', 'client.config.option.mode_quality'),
  option('flower', 'client.config.option.mode_flower'),
  option('exclude', 'client.config.option.mode_exclude_flower'),
];

const SHARE_MODE_OPTIONS = [
  option('quality', 'client.config.option.mode_quality'),
  option('flower', 'client.config.option.mode_flower'),
];

const ACTIVITY_SPEED_OPTIONS = [
  option('slow', 'client.config.option.speed_slow'),
  option('normal', 'client.config.option.speed_normal'),
  option('fast', 'client.config.option.speed_fast'),
];

const PRIORITY_OPTIONS = [
  option('customerOrder', 'client.config.item.customer_order'),
  option('residentOrder', 'client.config.item.resident_order'),
  option('artSell', 'client.config.item.flower_art_sell'),
  option('flowerNews', 'client.config.item.flower_news'),
  option('palaceOrder', 'client.config.item.palace_order'),
  option('unionRace', 'client.config.item.union_race'),
];

const CATEGORY_COUNT_OPTIONS = [
  option('1', 'client.config.option.count_1'),
  option('2', 'client.config.option.count_2'),
  option('3', 'client.config.option.count_3'),
  option('4', 'client.config.option.count_4'),
];

const MARKET_PRICE_OPTIONS = [
  option('0', 'client.config.option.price_low'),
  option('1', 'client.config.option.price_middle'),
  option('2', 'client.config.option.price_high'),
];

const MARKET_PUT_MODE_OPTIONS = [
  option('inventory', 'client.config.option.mode_inventory_most'),
  option('flower', 'client.config.option.mode_flower'),
];

const UNION_RACE_TASK_OPTIONS = [
  option('vipShop', 'client.config.item.vip_shop_buy'),
  option('residentOrder', 'client.config.item.resident_order'),
  option('customerOrder', 'client.config.item.customer_order'),
  option('materialShop', 'client.config.item.material_shop_buy'),
  option('palaceOrder', 'client.config.item.palace_order'),
  option('pearlHire', 'client.config.item.pearl_hire'),
  option('friendSteal', 'client.config.item.friend_steal'),
  option('artSell', 'client.config.item.flower_art_sell'),
  option('artCraft', 'client.config.item.flower_art_craft'),
  option('flowerUpgrade', 'client.config.item.flower_upgrade'),
  option('plantHarvest', 'client.config.item.plant_harvest'),
  option('flowerCultivate', 'client.config.item.flower_cultivate'),
  option('animalInteract', 'client.config.item.animal_interact'),
];

export const CONFIG_SCHEMA = [
  {
    key: 'basic',
    titleKey: 'client.config.tab.basic',
    groups: [
      {
        key: 'base',
        titleKey: 'client.config.group.basic_base',
        items: [
          item('basic.reputation.enabled', 'client.config.item.reputation_monitor', false, 'client.config.help.reputation_monitor'),
          numberItem('basic.reputation.threshold', 'client.config.item.reputation_threshold', 80, '', 'client.config.help.reputation_threshold', {
            visibleWhen: { path: 'basic.reputation.enabled', equals: true },
          }),
          item('basic.debug', 'client.config.item.item_log', false, 'client.config.help.item_log'),
          numberItem('basic.reconnectInterval', 'client.config.item.reconnect_interval', 5, 'client.config.unit.minute', 'client.config.help.reconnect_interval'),
        ],
      },
      {
        key: 'task',
        titleKey: 'client.config.group.task',
        items: [
          item('basic.task.daily', 'client.config.item.daily_task', false, 'client.config.help.daily_task'),
          item('basic.task.weekly', 'client.config.item.weekly_task', false, 'client.config.help.weekly_task'),
          item('basic.task.main', 'client.config.item.main_task', false, 'client.config.help.main_task'),
          item('basic.task.story', 'client.config.item.main_story', false, 'client.config.help.main_story'),
          item('basic.task.achieve', 'client.config.item.flower_reward', false, 'client.config.help.flower_reward'),
        ],
      },
      {
        key: 'mail',
        titleKey: 'client.config.group.mail',
        items: [
          item('basic.mail', 'client.config.item.auto_mail', false, 'client.config.help.auto_mail'),
        ],
      },
      {
        key: 'benefit',
        titleKey: 'client.config.group.benefit',
        items: [
          item('basic.benefit.buff', 'client.config.item.double_coin', false, 'client.config.help.double_coin'),
          item('basic.benefit.box', 'client.config.item.benefit_box', false, 'client.config.help.benefit_box'),
          item('basic.benefit.shareRwd', 'client.config.item.share_reward', false, 'client.config.help.share_reward'),
          item('basic.benefit.antiFraudBox', 'client.config.item.anti_fraud_box', false, 'client.config.help.anti_fraud_box'),
        ],
      },
      {
        key: 'daily_wish',
        titleKey: 'client.config.group.daily_wish',
        items: [
          item('basic.sign.daily', 'client.config.item.auto_wish'),
          item('basic.sign.patch', 'client.config.item.auto_retroactive'),
        ],
      },
      {
        key: 'pearl',
        titleKey: 'client.config.group.pearl',
        items: [
          item('basic.pearl.freePearl', 'client.config.item.free_pearl', false, 'client.config.help.free_pearl'),
          item('basic.pearl.autoHire', 'client.config.item.auto_hire', false, 'client.config.help.auto_hire'),
          numberItem('basic.pearl.maxHireLevel', 'client.config.item.level_limit', 0, '', 'client.config.help.level_limit', {
            visibleWhen: { path: 'basic.pearl.autoHire', equals: true },
          }),
          numberItem('basic.pearl.maxHireTicketUsage', 'client.config.item.hire_ticket_limit', 0, '', 'client.config.help.hire_ticket_limit', {
            visibleWhen: { path: 'basic.pearl.autoHire', equals: true },
          }),
          item('basic.pearl.open', 'client.config.item.auto_open_pearl'),
          item('basic.pearl.protectEnabled', 'client.config.item.protect_enabled', false, 'client.config.help.protect_enabled'),
          item('basic.pearl.buyHireBook', 'client.config.item.buy_hire_book', false, 'client.config.help.buy_hire_book'),
          numberItem('basic.pearl.maxSpendDmd', 'client.config.item.diamond_limit', 25, '', 'client.config.help.diamond_limit', {
            visibleWhen: { path: 'basic.pearl.buyHireBook', equals: true },
          }),
        ],
      },
      {
        key: 'shop',
        titleKey: 'client.config.group.shop',
        items: [
          item('basic.shop.videoGift', 'client.config.item.video_gift', false, 'client.config.help.video_gift'),
          item('basic.shop.cultivateShop.autoBuy', 'client.config.item.material_shop', false, 'client.config.help.material_shop'),
          numberItem('basic.shop.cultivateShop.maxSpendGold', 'client.config.item.gold_limit', 0, '', 'client.config.help.gold_limit', {
            visibleWhen: { path: 'basic.shop.cultivateShop.autoBuy', equals: true },
          }),
          item('basic.shop.vipShop.autoBuy', 'client.config.item.vip_shop', false, 'client.config.help.vip_shop'),
          numberItem('basic.shop.vipShop.maxSpendDmd', 'client.config.item.diamond_limit', 0, '', 'client.config.help.diamond_limit', {
            visibleWhen: { path: 'basic.shop.vipShop.autoBuy', equals: true },
          }),
          numberItem('basic.shop.vipShop.maxSpendFloralCoin', 'client.config.item.flower_coin_limit', 0, '', 'client.config.help.flower_coin_limit', {
            visibleWhen: { path: 'basic.shop.vipShop.autoBuy', equals: true },
          }),
        ],
      },
      {
        key: 'random_event',
        titleKey: 'client.config.group.random_event',
        items: [
          item('basic.randomEvent.enabled', 'client.config.item.random_event', false, 'client.config.help.random_event'),
        ],
      },
      {
        key: 'cat',
        titleKey: 'client.config.group.cat',
        items: [
          item('basic.cat.enabled', 'client.config.item.cat_switch'),
          item('basic.cat.autoRecall', 'client.config.item.auto_recall', false, 'client.config.help.auto_recall', {
            visibleWhen: { path: 'basic.cat.enabled', equals: true },
          }),
          item('basic.cat.autoBuyFood', 'client.config.item.auto_buy_cat_food', false, 'client.config.help.auto_buy_cat_food', {
            visibleWhen: { path: 'basic.cat.enabled', equals: true },
          }),
          item('basic.cat.autoFeed', 'client.config.item.auto_feed_cat', false, 'client.config.help.auto_feed_cat', {
            visibleWhen: { path: 'basic.cat.enabled', equals: true },
          }),
          item('basic.cat.autoStroke', 'client.config.item.auto_stroke_cat', false, 'client.config.help.auto_stroke_cat', {
            visibleWhen: { path: 'basic.cat.enabled', equals: true },
          }),
        ],
      },
    ],
  },
  {
    key: 'plant',
    titleKey: 'client.config.tab.plant',
    groups: [
      {
        key: 'cultivate',
        titleKey: 'client.config.group.cultivate',
        items: [
          item('plant.cultivate.enabled', 'client.config.item.auto_cultivate', false, 'client.config.help.auto_cultivate'),
          item('plant.cultivate.videoSpeedup', 'client.config.item.video_speedup', false, 'client.config.help.video_speedup'),
          item('plant.cultivate.upgrade', 'client.config.item.flower_upgrade', false, 'client.config.help.flower_upgrade'),
          numberItem('plant.cultivate.targetLevel', 'client.config.item.target_level', 20, '', 'client.config.help.target_level', {
            visibleWhen: { path: 'plant.cultivate.upgrade', equals: true },
          }),
        ],
      },
      {
        key: 'water',
        titleKey: 'client.config.group.water',
        items: [
          item('plant.water.enabled', 'client.config.item.water_drop', false, 'client.config.help.water_drop'),
          item('plant.water.timedEnabled', 'client.config.item.timed_water', false, 'client.config.help.timed_water', {
            visibleWhen: { path: 'plant.water.enabled', equals: true },
          }),
          numberItem('plant.water.threshold', 'client.config.item.water_threshold', 0, '', 'client.config.help.water_threshold', {
            visibleWhen: { path: 'plant.water.enabled', equals: true },
          }),
          item('plant.water.forceCollectEnabled', 'client.config.item.force_collect_water', false, 'client.config.help.force_collect_water', {
            visibleWhen: { path: 'plant.water.enabled', equals: true },
          }),
          textItem('plant.water.forceCollectTime', 'client.config.item.collect_time', '', 'client.config.help.collect_time', {
            visibleWhen: { path: 'plant.water.forceCollectEnabled', equals: true },
          }),
        ],
      },
      {
        key: 'flower',
        titleKey: 'client.config.group.flower',
        items: [
          item('plant.flower.unlockLand', 'client.config.item.unlock_land', false, 'client.config.help.unlock_land'),
          item('plant.flower.harvestEnabled', 'client.config.item.auto_harvest', false, 'client.config.help.auto_harvest'),
          item('plant.flower.plantEnabled', 'client.config.item.auto_plant', false, 'client.config.help.auto_plant'),
          item('plant.flower.videoSpeedup', 'client.config.item.video_speedup', false, 'client.config.help.video_speedup', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          item('plant.flower.useSpeedCard', 'client.config.item.use_speed_card', false, 'client.config.help.use_speed_card', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          numberItem('plant.flower.speedCardLimit', 'client.config.item.speed_card_limit', 20, '', 'client.config.help.speed_card_limit', {
            visibleWhen: { path: 'plant.flower.useSpeedCard', equals: true },
          }),
          numberItem('plant.flower.waterThreshold', 'client.config.item.keep_water', 0, '', 'client.config.help.keep_water', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          item('plant.flower.taskMode', 'client.config.item.task_priority_mode', true, 'client.config.help.task_priority_mode', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          item('plant.flower.taskLogEnabled', 'client.config.item.task_log', false, 'client.config.help.task_log', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          priorityGroupItem('plant.flower.taskPriority', 'client.config.item.task_priority', [
            { key: 'customerOrder', defaultValue: 1 },
            { key: 'residentOrder', defaultValue: 2 },
            { key: 'artSell', defaultValue: 6 },
            { key: 'flowerNews', defaultValue: 3 },
            { key: 'palaceOrder', defaultValue: 4 },
            { key: 'unionRace', defaultValue: 3 },
          ], PRIORITY_OPTIONS, 'client.config.help.task_priority', {
            visibleWhen: { path: 'plant.flower.taskMode', equals: true },
          }),
          radioItem('plant.flower.plantingMode', 'client.config.item.planting_mode', 'quality', PLANTING_MODE_OPTIONS, 'client.config.help.planting_mode', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
          multiSelectItem('plant.flower.flowerQuality', 'client.config.item.select_quality', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.select_quality', {
            visibleWhen: { path: 'plant.flower.plantingMode', equals: 'quality' },
          }),
          radioItem('plant.flower.categoryCount', 'client.config.item.select_count', '4', CATEGORY_COUNT_OPTIONS, 'client.config.help.select_count', {
            visibleWhen: { path: 'plant.flower.plantingMode', equals: 'category' },
          }),
          multiSelectItem('plant.flower.specificFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'plant.flower.plantingMode', equals: 'flower' },
          }),
          numberItem('plant.flower.minFlowerLevel', 'client.config.item.limit_flower_level', 0, '', 'client.config.help.limit_flower_level', {
            visibleWhen: { path: 'plant.flower.plantEnabled', equals: true },
          }),
        ],
      },
      {
        key: 'friend',
        titleKey: 'client.config.group.friend_steal',
        items: [
          item('plant.friendSteal.enabled', 'client.config.item.friend_steal', false, 'client.config.help.friend_steal'),
          item('plant.friendSteal.includeElf', 'client.config.item.steal_elf', false, 'client.config.help.steal_elf', {
            visibleWhen: { path: 'plant.friendSteal.enabled', equals: true },
          }),
          radioItem('plant.friendSteal.stealMode', 'client.config.item.steal_mode', 'quality', STEAL_MODE_OPTIONS, 'client.config.help.steal_mode', {
            visibleWhen: { path: 'plant.friendSteal.enabled', equals: true },
          }),
          multiSelectItem('plant.friendSteal.qualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: { path: 'plant.friendSteal.stealMode', equals: 'quality' },
          }),
          multiSelectItem('plant.friendSteal.specificFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'plant.friendSteal.stealMode', equals: 'flower' },
          }),
          multiSelectItem('plant.friendSteal.excludeFlowers', 'client.config.item.exclude_flower', [], FLOWER_OPTIONS, 'client.config.help.exclude_flower', {
            visibleWhen: { path: 'plant.friendSteal.stealMode', equals: 'exclude' },
          }),
          item('plant.friendSteal.buyCount', 'client.config.item.buy_steal_count', false, 'client.config.help.buy_steal_count', {
            visibleWhen: { path: 'plant.friendSteal.enabled', equals: true },
          }),
          numberItem('plant.friendSteal.buyStealCount', 'client.config.item.buy_steal_count_limit', 0, '', 'client.config.help.buy_steal_count_limit', {
            visibleWhen: { path: 'plant.friendSteal.buyCount', equals: true },
          }),
        ],
      },
      {
        key: 'elf',
        titleKey: 'client.config.group.elf',
        items: [
          item('plant.elves.plant', 'client.config.item.auto_plant_elf', false, 'client.config.help.auto_plant_elf'),
          textItem('plant.elves.selectedIds', 'client.config.item.specified_elf', '', 'client.config.help.specified_elf', {
            visibleWhen: { path: 'plant.elves.plant', equals: true },
          }),
          item('plant.elves.applyAid', 'client.config.item.apply_aid'),
          item('plant.elves.recvAid', 'client.config.item.recv_aid', false, 'client.config.help.recv_aid'),
          item('plant.elves.helpFriend', 'client.config.item.help_friend'),
          item('plant.elves.dispatch', 'client.config.item.dispatch_elf', false, 'client.config.help.dispatch_elf'),
          item('plant.elves.speedupDispatch', 'client.config.item.speedup_dispatch', false, 'client.config.help.speedup_dispatch'),
          item('plant.elves.recvDispatchReward', 'client.config.item.recv_dispatch_reward', false, 'client.config.help.recv_dispatch_reward'),
          item('plant.elves.recvPass', 'client.config.item.recv_pass', false, 'client.config.help.recv_pass'),
          item('plant.elves.recvPassTask', 'client.config.item.recv_pass_task', false, 'client.config.help.recv_pass_task'),
          item('plant.elves.recvFlowerPass', 'client.config.item.recv_flower_pass', false, 'client.config.help.recv_flower_pass'),
          item('plant.elves.recvFlowerPassTask', 'client.config.item.recv_flower_pass_task', false, 'client.config.help.recv_flower_pass_task'),
        ],
      },
      {
        key: 'flower_art',
        titleKey: 'client.config.group.flower_art',
        items: [
          item('plant.art.unlockShelf', 'client.config.item.unlock_flower_shelf', false, 'client.config.help.unlock_flower_shelf'),
          item('plant.art.autoPut', 'client.config.item.art_auto_put', false, 'client.config.help.art_auto_put'),
          radioItem('plant.art.sellMode', 'client.config.item.art_sell_mode', 'specified', [
            option('specified', 'client.config.option.mode_specified_vase'),
            option('full', 'client.config.option.mode_specified_art'),
            option('stock', 'client.config.option.mode_stock'),
          ], 'client.config.help.art_sell_mode', {
            visibleWhen: { path: 'plant.art.autoPut', equals: true },
          }),
          multiSelectItem('plant.art.specifiedVases', 'client.config.item.specified_vase', ['3001'], VASE_OPTIONS, 'client.config.help.specified_vase', {
            visibleWhen: { path: 'plant.art.sellMode', equals: 'specified' },
          }),
          multiSelectItem('plant.art.specifiedArts', 'client.config.item.specified_art', [], FLOWER_ART_OPTIONS, 'client.config.help.specified_art', {
            visibleWhen: { path: 'plant.art.sellMode', equals: 'full' },
          }),
          numberItem('plant.art.flowerArtPerRack', 'client.config.item.art_per_rack', 12, '', 'client.config.help.art_per_rack', {
            visibleWhen: { path: 'plant.art.autoPut', equals: true },
          }),
          item('plant.art.exp', 'client.config.item.art_exp', false, 'client.config.help.art_exp'),
          item('plant.art.bookReward', 'client.config.item.book_reward', false, 'client.config.help.book_reward'),
        ],
      },
      {
        key: 'flower_market',
        titleKey: 'client.config.group.flower_market',
        items: [
          item('plant.market.unlockShelf', 'client.config.item.market_unlock_shelf', false, 'client.config.help.market_unlock_shelf'),
          item('plant.market.autoPut', 'client.config.item.market_auto_put', false, 'client.config.help.market_auto_put'),
          radioItem('plant.market.putMode', 'client.config.item.market_put_mode', 'inventory', MARKET_PUT_MODE_OPTIONS, 'client.config.help.market_put_mode', {
            visibleWhen: { path: 'plant.market.autoPut', equals: true },
          }),
          multiSelectItem('plant.market.specificFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'plant.market.putMode', equals: 'flower' },
          }),
          radioItem('plant.market.priceIndex', 'client.config.item.price_index', '0', MARKET_PRICE_OPTIONS, 'client.config.help.price_index', {
            visibleWhen: { path: 'plant.market.autoPut', equals: true },
          }),
          numberItem('plant.market.maxSell', 'client.config.item.market_sell_limit', 25, '', 'client.config.help.market_sell_limit', {
            visibleWhen: { path: 'plant.market.autoPut', equals: true },
          }),
          textItem('plant.market.putPassword', 'client.config.item.market_password', '', 'client.config.help.market_password', {
            visibleWhen: { path: 'plant.market.autoPut', equals: true },
          }),
          item('plant.market.autoBuyPutCount', 'client.config.item.auto_buy_put_count', false, 'client.config.help.auto_buy_put_count', {
            visibleWhen: { path: 'plant.market.autoPut', equals: true },
          }),
          numberItem('plant.market.buyPutCount', 'client.config.item.buy_put_count_limit', 0, '', 'client.config.help.buy_put_count_limit', {
            visibleWhen: { path: 'plant.market.autoBuyPutCount', equals: true },
          }),
          item('plant.market.autoBuyFromFriend', 'client.config.item.friend_market_buy', false, 'client.config.help.friend_market_buy'),
          radioItem('plant.market.buyMode', 'client.config.item.market_buy_mode', 'quality', FLOWER_MODE_OPTIONS, 'client.config.help.market_buy_mode', {
            visibleWhen: { path: 'plant.market.autoBuyFromFriend', equals: true },
          }),
          multiSelectItem('plant.market.buyFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'plant.market.buyMode', equals: 'flower' },
          }),
          numberItem('plant.market.minPutTimeDiff', 'client.config.item.min_put_time_diff', 0, 'client.config.unit.minute', 'client.config.help.min_put_time_diff', {
            visibleWhen: { path: 'plant.market.autoBuyFromFriend', equals: true },
          }),
        ],
      },
    ],
  },
  {
    key: 'order',
    titleKey: 'client.config.tab.order',
    groups: [
      {
        key: 'resident_order',
        titleKey: 'client.config.group.order_resident',
        items: [
          item('order.resident.normal', 'client.config.item.resident_order', false, 'client.config.help.resident_order'),
          numberItem('order.resident.normalMaxNum', 'client.config.item.resident_order_limit', 1200, '', 'client.config.help.resident_order_limit', {
            visibleWhen: { path: 'order.resident.normal', equals: true },
          }),
          item('order.resident.satin', 'client.config.item.satin_order', false, 'client.config.help.satin_order'),
          numberItem('order.resident.satinMaxNum', 'client.config.item.satin_order_limit', 120, '', 'client.config.help.satin_order_limit', {
            visibleWhen: { path: 'order.resident.satin', equals: true },
          }),
          item('order.resident.building', 'client.config.item.building_order', false, 'client.config.help.building_order'),
          numberItem('order.resident.buildingMaxNum', 'client.config.item.building_order_limit', 120, '', 'client.config.help.building_order_limit', {
            visibleWhen: { path: 'order.resident.building', equals: true },
          }),
          multiSelectItem('order.resident.qualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: {
              any: [
                { path: 'order.resident.normal', equals: true },
                { path: 'order.resident.satin', equals: true },
                { path: 'order.resident.building', equals: true },
              ],
            },
          }),
        ],
      },
      {
        key: 'customer_order',
        titleKey: 'client.config.group.order_customer',
        items: [
          item('order.customer.enabled', 'client.config.item.auto_complete', false, 'client.config.help.customer_order'),
          item('order.customer.rejectEnabled', 'client.config.item.auto_reject', false, 'client.config.help.auto_reject', {
            visibleWhen: { path: 'order.customer.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'palace_order',
        titleKey: 'client.config.group.order_palace',
        items: [
          item('order.palace.enabled', 'client.config.item.auto_complete'),
          multiSelectItem('order.palace.qualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: { path: 'order.palace.enabled', equals: true },
          }),
          item('order.palace.ignoreQuality', 'client.config.item.ignore_quality', false, 'client.config.help.ignore_quality', {
            visibleWhen: { path: 'order.palace.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'group_order',
        titleKey: 'client.config.group.order_group',
        items: [
          item('order.group.enabled', 'client.config.item.auto_complete', false, 'client.config.help.group_order'),
          item('order.group.oneMore', 'client.config.item.one_more_order', false, 'client.config.help.one_more_order', {
            visibleWhen: { path: 'order.group.enabled', equals: true },
          }),
          item('order.group.submitOnlyCultivatedFlowers', 'client.config.item.only_cultivated', false, 'client.config.help.only_cultivated', {
            visibleWhen: { path: 'order.group.enabled', equals: true },
          }),
          multiSelectItem('order.group.qualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: { path: 'order.group.enabled', equals: true },
          }),
        ],
      },
    ],
  },
  {
    key: 'union',
    titleKey: 'client.config.tab.union',
    groups: [
      {
        key: 'land',
        titleKey: 'client.config.group.union_land',
        items: [
          item('union.land.harvest', 'client.config.item.union_harvest'),
          item('union.land.plant', 'client.config.item.union_plant', false, 'client.config.help.union_plant'),
          radioItem('union.land.plantMode', 'client.config.item.union_plant_mode', 'quality', [
            option('quality', 'client.config.option.mode_quality'),
            option('flower', 'client.config.option.mode_flower'),
            option('stock', 'client.config.option.mode_stock'),
          ], 'client.config.help.union_plant_mode', {
            visibleWhen: { path: 'union.land.plant', equals: true },
          }),
          multiSelectItem('union.land.flowers', 'client.config.item.select_quality', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.select_quality', {
            visibleWhen: { path: 'union.land.plantMode', equals: 'quality' },
          }),
          multiSelectItem('union.land.specificFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'union.land.plantMode', equals: 'flower' },
          }),
          numberItem('union.land.maxFlowerLevel', 'client.config.item.max_flower_level', 0, '', 'client.config.help.max_flower_level', {
            visibleWhen: { path: 'union.land.plant', equals: true },
          }),
        ],
      },
      {
        key: 'build',
        titleKey: 'client.config.group.union_build',
        items: [
          item('union.build.video', 'client.config.item.union_video_build', false, 'client.config.help.union_video_build'),
          item('union.build.coin', 'client.config.item.union_coin_build', false, 'client.config.help.union_coin_build'),
          item('union.build.dmd', 'client.config.item.union_dmd_build', false, 'client.config.help.union_dmd_build'),
        ],
      },
      {
        key: 'share',
        titleKey: 'client.config.group.union_share',
        items: [
          item('union.flower.share', 'client.config.item.union_share', false, 'client.config.help.union_share'),
          radioItem('union.flower.shareMode', 'client.config.item.share_mode', 'quality', SHARE_MODE_OPTIONS, 'client.config.help.share_mode', {
            visibleWhen: { path: 'union.flower.share', equals: true },
          }),
          multiSelectItem('union.flower.shareQualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: { path: 'union.flower.shareMode', equals: 'quality' },
          }),
          multiSelectItem('union.flower.shareFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'union.flower.shareMode', equals: 'flower' },
          }),
        ],
      },
      {
        key: 'touch',
        titleKey: 'client.config.group.union_touch',
        items: [
          item('union.flower.touch', 'client.config.item.union_touch', false, 'client.config.help.union_touch'),
          radioItem('union.flower.touchMode', 'client.config.item.touch_mode', 'quality', FLOWER_MODE_OPTIONS, 'client.config.help.touch_mode', {
            visibleWhen: { path: 'union.flower.touch', equals: true },
          }),
          multiSelectItem('union.flower.touchQualities', 'client.config.item.quality_limit', QUALITY_OPTIONS.map((item) => item.value), QUALITY_OPTIONS, 'client.config.help.quality_limit', {
            visibleWhen: { path: 'union.flower.touchMode', equals: 'quality' },
          }),
          multiSelectItem('union.flower.touchFlowers', 'client.config.item.select_flower', ['23001'], FLOWER_OPTIONS, 'client.config.help.select_flower', {
            visibleWhen: { path: 'union.flower.touchMode', equals: 'flower' },
          }),
        ],
      },
      {
        key: 'race',
        titleKey: 'client.config.group.union_race',
        items: [
          item('union.fmlRace.enabled', 'client.config.item.union_race_finish', false, 'client.config.help.union_race_finish'),
          item('union.fmlRace.autoEnableModules', 'client.config.item.union_auto_modules', false, 'client.config.help.union_auto_modules'),
          item('union.fmlRace.useSpeedCard', 'client.config.item.union_speed_card', false, 'client.config.help.union_speed_card'),
          numberItem('union.fmlRace.minScore', 'client.config.item.union_min_score', 25, '', 'client.config.help.union_min_score'),
          numberItem('union.fmlRace.upgradedMinScore', 'client.config.item.union_upgraded_min_score', 50, '', 'client.config.help.union_upgraded_min_score'),
          item('union.fmlRace.dropLowScore', 'client.config.item.union_drop_low_score', false, 'client.config.help.union_drop_low_score'),
          item('union.fmlRace.onlyUpgradeTask', 'client.config.item.union_only_upgraded', false, 'client.config.help.union_only_upgraded'),
          item('union.fmlRace.excludeOtherUpgradeTask', 'client.config.item.union_exclude_other_upgrade', true, 'client.config.help.union_exclude_other_upgrade'),
          priorityGroupItem('union.fmlRace.taskTypePriority', 'client.config.item.task_priority', [
            { key: 'vipShop', defaultValue: 0 },
            { key: 'residentOrder', defaultValue: 0 },
            { key: 'customerOrder', defaultValue: 0 },
            { key: 'materialShop', defaultValue: 0 },
            { key: 'palaceOrder', defaultValue: 0 },
            { key: 'pearlHire', defaultValue: 0 },
            { key: 'friendSteal', defaultValue: 0 },
            { key: 'artSell', defaultValue: 0 },
            { key: 'artCraft', defaultValue: 0 },
            { key: 'flowerUpgrade', defaultValue: 0 },
            { key: 'plantHarvest', defaultValue: 0 },
            { key: 'flowerCultivate', defaultValue: 0 },
            { key: 'animalInteract', defaultValue: 0 },
          ], UNION_RACE_TASK_OPTIONS, 'client.config.help.union_task_type_priority'),
          item('union.fmlRace.autoUpgradeTask', 'client.config.item.union_auto_upgrade_task', false, 'client.config.help.union_auto_upgrade_task'),
          item('union.fmlRace.deleteLowScoreTask', 'client.config.item.union_delete_low_score', false, 'client.config.help.union_delete_low_score'),
          numberItem('union.fmlRace.deleteTaskMaxScore', 'client.config.item.delete_score_limit', 0, '', 'client.config.help.delete_score_limit', {
            visibleWhen: { path: 'union.fmlRace.deleteLowScoreTask', equals: true },
          }),
          item('union.fmlRace.keepSystemUpgrade', 'client.config.item.keep_system_upgrade', false, 'client.config.help.keep_system_upgrade', {
            visibleWhen: { path: 'union.fmlRace.deleteLowScoreTask', equals: true },
          }),
          item('union.fmlRace.keepPlayerUpgrade', 'client.config.item.keep_player_upgrade', false, 'client.config.help.keep_player_upgrade', {
            visibleWhen: { path: 'union.fmlRace.deleteLowScoreTask', equals: true },
          }),
        ],
      },
      {
        key: 'exchange',
        titleKey: 'client.config.group.union_exchange',
        items: [
          item('union.exchange.autoRecv', 'client.config.item.union_exchange', false, 'client.config.help.union_exchange'),
        ],
      },
      {
        key: 'energy_forest',
        titleKey: 'client.config.group.union_energy_forest',
        items: [
          item('union.energyForest.collect', 'client.config.item.energy_collect'),
        ],
      },
    ],
  },
  {
    key: 'activity',
    titleKey: 'client.config.tab.activity',
    groups: [
      {
        key: 'flower_letter',
        titleKey: 'client.config.group.activity_flower_letter',
        items: [
          item('activity.flowerLetter.enabled', 'client.config.item.auto_complete', false, 'client.config.help.flower_letter'),
          item('activity.flowerLetter.unlockSlot', 'client.config.item.unlock_slot', false, 'client.config.help.unlock_slot', {
            visibleWhen: { path: 'activity.flowerLetter.enabled', equals: true },
          }),
          item('activity.flowerLetter.autoEnableModules', 'client.config.item.auto_enable_modules', false, 'client.config.help.auto_enable_modules', {
            visibleWhen: { path: 'activity.flowerLetter.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'flower_news',
        titleKey: 'client.config.group.activity_flower_news',
        items: [
          item('activity.flowerNews.enabled', 'client.config.item.auto_complete', false, 'client.config.help.flower_news'),
          item('activity.flowerNews.refreshEnabled', 'client.config.item.diamond_refresh', false, 'client.config.help.diamond_refresh', {
            visibleWhen: { path: 'activity.flowerNews.enabled', equals: true },
          }),
          numberItem('activity.flowerNews.maxFinishCountPerBatch', 'client.config.item.finish_count', 0, '', 'client.config.help.finish_count', {
            visibleWhen: { path: 'activity.flowerNews.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'fish_dry',
        titleKey: 'client.config.group.activity_fish_dry',
        items: [
          item('activity.fishDry.enabled', 'client.config.item.auto_participate'),
          item('activity.fishDry.showResult', 'client.config.item.show_result', false, 'client.config.help.show_result', {
            visibleWhen: { path: 'activity.fishDry.enabled', equals: true },
          }),
          item('activity.fishDry.autoRestart', 'client.config.item.auto_restart', false, 'client.config.help.auto_restart', {
            visibleWhen: { path: 'activity.fishDry.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'bubble',
        titleKey: 'client.config.group.activity_bubble',
        items: [
          item('activity.bubble.enabled', 'client.config.item.auto_participate'),
        ],
      },
      {
        key: 'fish_fun',
        titleKey: 'client.config.group.activity_fish_fun',
        items: [
          item('activity.fishFun.enabled', 'client.config.item.auto_participate'),
          item('activity.fishFun.autoClaimEnergy', 'client.config.item.auto_claim_energy', false, 'client.config.help.auto_claim_energy', {
            visibleWhen: { path: 'activity.fishFun.enabled', equals: true },
          }),
          radioItem('activity.fishFun.speed', 'client.config.item.speed', 'normal', ACTIVITY_SPEED_OPTIONS, 'client.config.help.speed', {
            visibleWhen: { path: 'activity.fishFun.enabled', equals: true },
          }),
          item('activity.fishFun.showResult', 'client.config.item.show_result', false, 'client.config.help.show_result', {
            visibleWhen: { path: 'activity.fishFun.enabled', equals: true },
          }),
          item('activity.fishFun.autoRestart', 'client.config.item.auto_restart', false, 'client.config.help.auto_restart', {
            visibleWhen: { path: 'activity.fishFun.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'flower_story',
        titleKey: 'client.config.group.activity_flower_story',
        items: [
          item('activity.flowerStory.enabled', 'client.config.item.auto_participate'),
          item('activity.flowerStory.autoClaimEnergy', 'client.config.item.auto_claim_energy', false, 'client.config.help.auto_claim_energy', {
            visibleWhen: { path: 'activity.flowerStory.enabled', equals: true },
          }),
          radioItem('activity.flowerStory.speed', 'client.config.item.speed', 'normal', ACTIVITY_SPEED_OPTIONS, 'client.config.help.speed', {
            visibleWhen: { path: 'activity.flowerStory.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'red_packet',
        titleKey: 'client.config.group.activity_red_packet',
        items: [
          item('activity.redPacket.enabled', 'client.config.item.auto_participate', false, 'client.config.help.red_packet'),
        ],
      },
      {
        key: 'recv_luck',
        titleKey: 'client.config.group.activity_recv_luck',
        items: [
          item('activity.recvLuck.enabled', 'client.config.item.auto_receive', false, 'client.config.help.recv_luck'),
        ],
      },
      {
        key: 'call',
        titleKey: 'client.config.group.activity_call',
        items: [
          item('activity.call.enabled', 'client.config.item.auto_call', false, 'client.config.help.call'),
        ],
      },
      {
        key: 'family_help',
        titleKey: 'client.config.group.activity_family_help',
        items: [
          item('activity.familyHelp.enabled', 'client.config.item.auto_help', false, 'client.config.help.family_help'),
          item('activity.familyHelp.recvBoxes', 'client.config.item.receive_boxes', false, 'client.config.help.receive_boxes', {
            visibleWhen: { path: 'activity.familyHelp.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'money_tree',
        titleKey: 'client.config.group.activity_money_tree',
        items: [
          item('activity.moneyTree.enabled', 'client.config.item.auto_money_tree'),
        ],
      },
      {
        key: 'zoo_elim',
        titleKey: 'client.config.group.activity_zoo_elim',
        items: [
          item('activity.zooGameElim.enabled', 'client.config.item.auto_participate', false, 'client.config.help.zoo_elim'),
        ],
      },
      {
        key: 'lantern',
        titleKey: 'client.config.group.activity_lantern',
        items: [
          item('activity.lantern.enabled', 'client.config.item.auto_answer', false, 'client.config.help.lantern'),
        ],
      },
      {
        key: 'cake',
        titleKey: 'client.config.group.activity_cake',
        items: [
          item('activity.cake.enabled', 'client.config.item.auto_put'),
          item('activity.cake.autoClaimEnergy', 'client.config.item.auto_claim_energy', false, 'client.config.help.auto_claim_energy', {
            visibleWhen: { path: 'activity.cake.enabled', equals: true },
          }),
          item('activity.cake.useItems', 'client.config.item.use_items', false, 'client.config.help.use_items', {
            visibleWhen: { path: 'activity.cake.enabled', equals: true },
          }),
          radioItem('activity.cake.speed', 'client.config.item.speed', 'normal', ACTIVITY_SPEED_OPTIONS, 'client.config.help.speed', {
            visibleWhen: { path: 'activity.cake.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'merge',
        titleKey: 'client.config.group.activity_merge',
        items: [
          item('activity.merge.enabled', 'client.config.item.auto_merge'),
          item('activity.merge.autoClaimEnergy', 'client.config.item.auto_claim_energy', false, 'client.config.help.auto_claim_energy', {
            visibleWhen: { path: 'activity.merge.enabled', equals: true },
          }),
          radioItem('activity.merge.speed', 'client.config.item.speed', 'normal', ACTIVITY_SPEED_OPTIONS, 'client.config.help.speed', {
            visibleWhen: { path: 'activity.merge.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'spool',
        titleKey: 'client.config.group.activity_spool',
        items: [
          item('activity.spool.enabled', 'client.config.item.auto_play'),
          item('activity.spool.autoClaimReward', 'client.config.item.auto_claim_reward', false, 'client.config.help.auto_claim_reward', {
            visibleWhen: { path: 'activity.spool.enabled', equals: true },
          }),
          item('activity.spool.openBox', 'client.config.item.open_box', false, 'client.config.help.open_box', {
            visibleWhen: { path: 'activity.spool.enabled', equals: true },
          }),
          item('activity.spool.autoRestart', 'client.config.item.auto_restart', false, 'client.config.help.auto_restart', {
            visibleWhen: { path: 'activity.spool.enabled', equals: true },
          }),
          radioItem('activity.spool.speed', 'client.config.item.speed', 'normal', ACTIVITY_SPEED_OPTIONS, 'client.config.help.speed', {
            visibleWhen: { path: 'activity.spool.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'dragon_boat',
        titleKey: 'client.config.group.activity_dragon_boat',
        items: [
          item('activity.dragonBoat.enabled', 'client.config.item.join_race', false, 'client.config.help.dragon_boat'),
          item('activity.dragonBoat.autoSign', 'client.config.item.auto_sign', false, 'client.config.help.auto_sign', {
            visibleWhen: { path: 'activity.dragonBoat.enabled', equals: true },
          }),
          item('activity.dragonBoat.autoOpenBox', 'client.config.item.auto_open_box', false, 'client.config.help.auto_open_box', {
            visibleWhen: { path: 'activity.dragonBoat.enabled', equals: true },
          }),
          item('activity.dragonBoat.giftBuy', 'client.config.item.gift_buy', false, 'client.config.help.gift_buy', {
            visibleWhen: { path: 'activity.dragonBoat.enabled', equals: true },
          }),
        ],
      },
      {
        key: 'honey',
        titleKey: 'client.config.group.activity_honey',
        items: [
          item('activity.honey.reward', 'client.config.item.receive_reward', false, 'client.config.help.honey_reward'),
        ],
      },
      {
        key: 'card',
        titleKey: 'client.config.group.activity_card',
        items: [
          item('activity.card.reward', 'client.config.item.card_reward', false, 'client.config.help.card_reward'),
          item('activity.card.smoke', 'client.config.item.card_smoke', false, 'client.config.help.card_smoke'),
        ],
      },
    ],
  },
];

export function createDefaultConfig() {
  const config = {};
  CONFIG_SCHEMA.forEach((tab) => {
    tab.groups.forEach((group) => {
      group.items.forEach((entry) => {
        if (entry.type === 'priorityGroup') {
          entry.entries.forEach((priorityEntry) => {
            setConfigValue(config, `${entry.path}.${priorityEntry.key}`, priorityEntry.defaultValue);
          });
          return;
        }
        setConfigValue(config, entry.path, entry.defaultValue);
      });
    });
  });
  return config;
}

export function mergeConfig(remoteConfig = {}) {
  return mergeDeep(createDefaultConfig(), remoteConfig || {});
}

export function getConfigValue(config, path) {
  return path.split('.').reduce((cursor, part) => (cursor && cursor[part] !== undefined ? cursor[part] : undefined), config);
}

export function setConfigValue(config, path, value) {
  const parts = path.split('.');
  let cursor = config;
  parts.slice(0, -1).forEach((part) => {
    if (!cursor[part] || typeof cursor[part] !== 'object') {
      cursor[part] = {};
    }
    cursor = cursor[part];
  });
  cursor[parts[parts.length - 1]] = value;
}

function item(path, labelKey, defaultValue = false, helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'switch', defaultValue, ...options };
}

function numberItem(path, labelKey, defaultValue, unitKey, helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'number', defaultValue, unitKey, ...options };
}

function textItem(path, labelKey, defaultValue = '', helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'text', defaultValue, ...options };
}

function radioItem(path, labelKey, defaultValue, optionsList, helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'radio', defaultValue, options: optionsList, ...options };
}

function multiSelectItem(path, labelKey, defaultValue, optionsList, helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'multiSelect', defaultValue, options: optionsList, ...options };
}

function priorityGroupItem(path, labelKey, entries, optionsList, helpKey = '', options = {}) {
  return { path, labelKey, helpKey, type: 'priorityGroup', entries, options: optionsList, ...options };
}

function option(value, labelKey) {
  return { value, labelKey };
}

function mergeDeep(target, source) {
  Object.keys(source).forEach((key) => {
    if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
      target[key] = mergeDeep(target[key] && typeof target[key] === 'object' ? target[key] : {}, source[key]);
      return;
    }
    target[key] = source[key];
  });
  return target;
}
