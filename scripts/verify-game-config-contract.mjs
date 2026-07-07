import { readFileSync } from 'node:fs';

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
