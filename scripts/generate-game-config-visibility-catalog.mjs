import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SCHEMA_PATH = resolve(ROOT, 'client/src/utils/gameConfigSchema.js');
const CATALOG_PATH = resolve(ROOT, 'shared/game-config-visibility.json');
const requireFromClient = createRequire(resolve(ROOT, 'client/package.json'));
const { parse } = requireFromClient('@babel/parser');

const DEFAULT_HIDDEN_GROUPS = new Set([
  'activity.flower_letter',
  'activity.flower_news',
  'activity.fish_dry',
  'activity.bubble',
  'activity.red_packet',
  'activity.recv_luck',
  'activity.call',
  'activity.family_help',
  'activity.money_tree',
  'activity.zoo_elim',
  'activity.lantern',
  'activity.spool',
  'activity.dragon_boat',
  'activity.card',
]);

const DEFAULT_HIDDEN_PATHS = new Set([
  'plant.elves.plant',
  'plant.elves.selectedIds',
  'plant.elves.speedupDispatch',
  'union.energyForest.collect',
]);

function property(object, name) {
  if (!object || object.type !== 'ObjectExpression') {
    throw new Error(`Expected object while reading ${name}`);
  }
  const found = object.properties.find((entry) => entry.type === 'ObjectProperty'
    && ((entry.key.type === 'Identifier' && entry.key.name === name)
      || (entry.key.type === 'StringLiteral' && entry.key.value === name)));
  if (!found) {
    throw new Error(`Missing object property: ${name}`);
  }
  return found.value;
}

function optionalProperty(object, name) {
  if (!object || object.type !== 'ObjectExpression') {
    return null;
  }
  const found = object.properties.find((entry) => entry.type === 'ObjectProperty'
    && ((entry.key.type === 'Identifier' && entry.key.name === name)
      || (entry.key.type === 'StringLiteral' && entry.key.value === name)));
  return found?.value ?? null;
}

function stringValue(node, label) {
  if (node?.type !== 'StringLiteral') {
    throw new Error(`${label} must be a string literal`);
  }
  return node.value;
}

function arrayElements(node, label) {
  if (node?.type !== 'ArrayExpression') {
    throw new Error(`${label} must be an array literal`);
  }
  return node.elements;
}

function collectConditionPaths(node, paths = new Set()) {
  if (!node) {
    return paths;
  }
  if (node.type === 'ObjectExpression') {
    for (const entry of node.properties) {
      if (entry.type !== 'ObjectProperty') continue;
      const key = entry.key.type === 'Identifier' ? entry.key.name : entry.key.value;
      if (key === 'path' && entry.value.type === 'StringLiteral') {
        paths.add(entry.value.value);
      }
      collectConditionPaths(entry.value, paths);
    }
  } else if (node.type === 'ArrayExpression') {
    node.elements.forEach((entry) => collectConditionPaths(entry, paths));
  }
  return paths;
}

function configSchemaNode(ast) {
  for (const statement of ast.program.body) {
    const declaration = statement.type === 'ExportNamedDeclaration' ? statement.declaration : statement;
    if (declaration?.type !== 'VariableDeclaration') continue;
    for (const item of declaration.declarations) {
      if (item.id.type === 'Identifier' && item.id.name === 'CONFIG_SCHEMA') {
        return item.init;
      }
    }
  }
  throw new Error('CONFIG_SCHEMA declaration not found');
}

export function buildGameConfigVisibilityCatalog(schemaSource = readFileSync(SCHEMA_PATH, 'utf8')) {
  const ast = parse(schemaSource, { sourceType: 'module' });
  const tabs = arrayElements(configSchemaNode(ast), 'CONFIG_SCHEMA').map((tabNode) => {
    const tabKey = stringValue(property(tabNode, 'key'), 'tab key');
    const groups = arrayElements(property(tabNode, 'groups'), `${tabKey}.groups`).map((groupNode) => {
      const groupKey = stringValue(property(groupNode, 'key'), 'group key');
      const groupDefaultVisible = !DEFAULT_HIDDEN_GROUPS.has(`${tabKey}.${groupKey}`);
      const items = arrayElements(property(groupNode, 'items'), `${tabKey}.${groupKey}.items`).map((itemNode) => {
        if (itemNode?.type !== 'CallExpression' || itemNode.callee.type !== 'Identifier') {
          throw new Error(`${tabKey}.${groupKey} contains a non-call configuration item`);
        }
        const path = stringValue(itemNode.arguments[0], 'config path');
        const labelKey = stringValue(itemNode.arguments[1], `${path} label key`);
        const objectArguments = itemNode.arguments.filter((argument) => argument?.type === 'ObjectExpression');
        const optionsNode = objectArguments.length > 0 ? objectArguments[objectArguments.length - 1] : null;
        const visibleWhen = optionalProperty(optionsNode, 'visibleWhen');
        return {
          path,
          labelKey,
          type: itemNode.callee.name,
          dependsOnPaths: [...collectConditionPaths(visibleWhen)].sort(),
          defaultVisible: groupDefaultVisible && !DEFAULT_HIDDEN_PATHS.has(path),
        };
      });
      return {
        key: groupKey,
        titleKey: stringValue(property(groupNode, 'titleKey'), `${tabKey}.${groupKey}.titleKey`),
        items,
      };
    });
    return {
      key: tabKey,
      titleKey: stringValue(property(tabNode, 'titleKey'), `${tabKey}.titleKey`),
      groups,
    };
  });

  const itemCount = tabs.reduce((tabTotal, tab) => tabTotal
    + tab.groups.reduce((groupTotal, group) => groupTotal + group.items.length, 0), 0);
  const defaultHiddenCount = tabs.reduce((tabTotal, tab) => tabTotal
    + tab.groups.reduce((groupTotal, group) => groupTotal
      + group.items.filter((item) => !item.defaultVisible).length, 0), 0);

  if (itemCount !== 196) {
    throw new Error(`Expected 196 game config UI items, found ${itemCount}`);
  }
  if (defaultHiddenCount !== 33) {
    throw new Error(`Expected 33 default-hidden game config UI items, found ${defaultHiddenCount}`);
  }

  return { version: 1, itemCount, defaultHiddenCount, tabs };
}

export function serializeGameConfigVisibilityCatalog(catalog = buildGameConfigVisibilityCatalog()) {
  return `${JSON.stringify(catalog, null, 2)}\n`;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const serialized = serializeGameConfigVisibilityCatalog();
  if (process.argv.includes('--check')) {
    const current = readFileSync(CATALOG_PATH, 'utf8');
    if (current !== serialized) {
      throw new Error('shared/game-config-visibility.json is out of date; run the generator');
    }
    console.log('game config visibility catalog is up to date');
  } else {
    mkdirSync(dirname(CATALOG_PATH), { recursive: true });
    writeFileSync(CATALOG_PATH, serialized, 'utf8');
    console.log('generated shared/game-config-visibility.json');
  }
}
