import { readFileSync } from 'node:fs';
import { serializeGameConfigVisibilityCatalog } from './generate-game-config-visibility-catalog.mjs';

const expectedCategoryCountOptions = ['1', '2', '4', '8', '16'];

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function assertArrayEqual(actual, expected, label) {
  assert(
    JSON.stringify(actual) === JSON.stringify(expected),
    `${label} 应为 ${JSON.stringify(expected)}，实际为 ${JSON.stringify(actual)}`,
  );
}

const schemaSource = readFileSync('client/src/utils/gameConfigSchema.js', 'utf8');
const visibilityCatalogSource = readFileSync('shared/game-config-visibility.json', 'utf8');
assert(
  visibilityCatalogSource === serializeGameConfigVisibilityCatalog(),
  '游戏配置显示目录与 CONFIG_SCHEMA 不一致，请重新生成目录',
);
const visibilityCatalog = JSON.parse(visibilityCatalogSource);
assert(visibilityCatalog.itemCount === 196, '游戏配置显示目录必须包含196个配置行');
assert(visibilityCatalog.defaultHiddenCount === 33, '游戏配置显示目录必须默认隐藏33个配置行');
const categoryOptionsMatch = schemaSource.match(
  /const CATEGORY_COUNT_OPTIONS = \[\s*([\s\S]*?)\s*\];/,
);
assert(categoryOptionsMatch, '未找到 CATEGORY_COUNT_OPTIONS');
const categoryOptions = [...categoryOptionsMatch[1].matchAll(/option\('([^']+)'/g)].map(
  (match) => match[1],
);
assertArrayEqual(categoryOptions, expectedCategoryCountOptions, '前端选择数量选项');

const contractSchema = JSON.parse(
  readFileSync('docs/third-party-game-config.schema.json', 'utf8'),
);
const contractDebug = contractSchema.properties.config.properties.basic.properties.debug;
assert(contractDebug?.type === 'boolean', '协议 JSON schema 中 basic.debug 必须是 boolean');
assert(contractDebug.default === true, '协议 JSON schema 中 basic.debug 默认值必须是 true');
assert(
  /item\('basic\.debug', 'client\.config\.item\.item_log', true,/.test(schemaSource),
  '前端配置 schema 中 basic.debug 默认值必须是 true',
);

const contractCategoryCount =
  contractSchema.properties.config.properties.plant.properties.flower.properties.categoryCount;
assert(contractCategoryCount, '协议 JSON schema 未找到 plant.flower.categoryCount');
assertArrayEqual(contractCategoryCount.enum, expectedCategoryCountOptions, '协议 JSON schema 选择数量 enum');

const contractTaskPriority =
  contractSchema.properties.config.properties.plant.properties.flower.properties.taskPriority;
assert(contractTaskPriority?.type === 'object', '协议 JSON schema 中 plant.flower.taskPriority 必须是 object');

const contractUnionTaskPriority =
  contractSchema.properties.config.properties.union.properties.fmlRace.properties.taskTypePriority;
assert(
  contractUnionTaskPriority?.type === 'object',
  '协议 JSON schema 中 union.fmlRace.taskTypePriority 必须是 object',
);

assert(
  schemaSource.includes("multiSelectItem('plant.market.buyQualities'"),
  '前端配置 schema 缺少 plant.market.buyQualities',
);

const contractMarket = contractSchema.properties.config.properties.plant.properties.market;
const contractBuyQualities = contractMarket.properties.buyQualities;
assert(contractMarket.required.includes('buyQualities'), '协议 JSON schema 未要求 plant.market.buyQualities');
assert(contractBuyQualities?.type === 'array', '协议 JSON schema 中 plant.market.buyQualities 必须是 array');
assertArrayEqual(
  contractBuyQualities.default,
  ['green', 'blue', 'purple', 'gold', 'red'],
  '协议 JSON schema 中 plant.market.buyQualities 默认值',
);

for (const docPath of ['docs/third-party-game-config.md', 'docs/协议说明.txt']) {
  const doc = readFileSync(docPath, 'utf8');
  assert(
    /basic\.debug.*默认值：`?true`?/.test(doc),
    `${docPath} 中 basic.debug 默认值必须是 true`,
  );
  assert(
    /plant\.flower\.categoryCount.*可选值：1=`?"1"`? \/ 2=`?"2"`? \/ 4=`?"4"`? \/ 8=`?"8"`? \/ 16=`?"16"`?/.test(doc),
    `${docPath} 未写明选择数量可选值 1 / 2 / 4 / 8 / 16`,
  );
  assert(
    /plant\.flower\.taskPriority.*数据类型：`?object`?/.test(doc),
    `${docPath} 未写明 plant.flower.taskPriority 的数据类型为 object`,
  );
  assert(
    /union\.fmlRace\.taskTypePriority.*数据类型：`?object`?/.test(doc),
    `${docPath} 未写明 union.fmlRace.taskTypePriority 的数据类型为 object`,
  );
  assert(
    /plant\.market\.buyQualities.*数据类型：`?string\[\]`?/.test(doc),
    `${docPath} 未写明 plant.market.buyQualities 的数据类型为 string[]`,
  );
}

console.log('game config contract verified');
