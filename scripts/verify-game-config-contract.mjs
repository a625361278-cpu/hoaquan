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

for (const docPath of ['docs/third-party-game-config.md', 'docs/协议说明.txt']) {
  const doc = readFileSync(docPath, 'utf8');
  assert(
    doc.includes('plant.flower.categoryCount') && doc.includes('可选值：1 / 2 / 4 / 8 / 16'),
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
}

console.log('game config contract verified');
