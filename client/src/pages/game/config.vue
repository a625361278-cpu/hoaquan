<template>
  <view class="page">
    <view class="topbar">
      <button class="back-button" @click="back">‹</button>
      <text class="page-title">{{ t('client.config.title') }}</text>
      <view class="top-actions">
        <button class="import-button" :disabled="loading" @click="importConfig">{{ t('client.config.import') }}</button>
        <button class="save-button" :disabled="saving" @click="saveConfig">{{ t('client.config.save') }}</button>
      </view>
    </view>

    <view class="config-shell">
      <view v-if="account.sync_status === 'local_unsynced'" class="notice">
        {{ t('client.config.local_unsynced_notice') }}
      </view>

      <scroll-view scroll-x class="tabs">
        <view class="tab-row">
          <view
            v-for="tab in CONFIG_SCHEMA"
            :key="tab.key"
            :class="['tab', activeTab === tab.key ? 'active' : '']"
            @click="activeTab = tab.key"
          >
            {{ t(tab.titleKey) }}
          </view>
        </view>
      </scroll-view>

      <view class="form-panel">
        <text v-if="!localeReady || loading" class="loading-text">
          {{ localeReady ? t('client.config.loading') : '' }}
        </text>
        <view v-else v-for="group in activeGroups" :key="group.key" class="group">
          <view class="group-heading">
            <view class="group-line" />
            <text class="group-title">{{ t(group.titleKey) }}</text>
            <view class="group-line" />
          </view>

          <view v-for="entry in visibleItems(group.items)" :key="entry.path" class="config-row">
            <view class="row-label">
              <text class="label-text">{{ t(entry.labelKey) }}</text>
              <view
                v-if="entry.helpKey"
                class="help-wrap"
                @mouseenter="openHelp(entry.path)"
                @mouseleave="closeHelp"
                @click="toggleHelp(entry.path)"
              >
                <text class="help">?</text>
                <view v-if="activeHelpPath === entry.path" class="help-tooltip">
                  {{ t(entry.helpKey) }}
                </view>
              </view>
              <text class="colon">:</text>
            </view>
            <view class="control-area">
              <switch
                v-if="entry.type === 'switch'"
                :checked="Boolean(getConfigValue(config, entry.path))"
                color="#9ca3af"
                @change="setConfigValue(config, entry.path, $event.detail.value)"
              />
              <view v-else-if="entry.type === 'number'" class="number-control">
                <input
                  class="number-input"
                  type="number"
                  :value="String(getConfigValue(config, entry.path) ?? entry.defaultValue)"
                  @input="setConfigValue(config, entry.path, Number($event.detail.value || 0))"
                />
                <text v-if="entry.unitKey" class="unit">{{ t(entry.unitKey) }}</text>
              </view>
              <input
                v-else-if="entry.type === 'text'"
                class="text-input"
                type="text"
                :value="String(getConfigValue(config, entry.path) ?? entry.defaultValue)"
                @input="setConfigValue(config, entry.path, $event.detail.value)"
              />
              <radio-group
                v-else-if="entry.type === 'radio'"
                class="radio-control"
                @change="setConfigValue(config, entry.path, $event.detail.value)"
              >
                <label v-for="option in entry.options" :key="option.value" class="radio-option">
                  <radio
                    :value="option.value"
                    :checked="getConfigValue(config, entry.path) === option.value"
                    color="#1677ff"
                  />
                  <text>{{ t(option.labelKey) }}</text>
                </label>
              </radio-group>
              <view v-else-if="entry.type === 'multiSelect'" class="multi-select-control">
                <button
                  v-for="option in entry.options"
                  :key="option.value"
                  :class="['multi-select-chip', isMultiSelected(entry.path, option.value) ? 'selected' : '']"
                  @click="toggleMultiSelect(entry.path, option.value)"
                >
                  {{ t(option.labelKey) }}
                </button>
              </view>
              <view v-else-if="entry.type === 'priorityGroup'" class="priority-control">
                <view v-for="priorityEntry in entry.entries" :key="priorityEntry.key" class="priority-row">
                  <text class="priority-label">{{ optionText(entry, priorityEntry.key) }}</text>
                  <input
                    class="priority-input"
                    type="number"
                    :value="String(getConfigValue(config, `${entry.path}.${priorityEntry.key}`) ?? priorityEntry.defaultValue)"
                    @input="setConfigValue(config, `${entry.path}.${priorityEntry.key}`, Number($event.detail.value || 0))"
                  />
                </view>
              </view>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view v-if="configFlowVisible && localeReady" class="modal-mask">
      <view class="config-flow-dialog">
        <view class="config-flow-body">
          <view class="config-flow-icon">i</view>
          <view class="config-flow-main">
            <text class="config-flow-title">{{ t('client.config.flow.title') }}</text>
            <text class="config-flow-text">{{ t('client.config.flow.steps') }}</text>
            <text class="config-flow-text">{{ t('client.config.flow.save_required') }}</text>
          </view>
        </view>
        <view class="config-flow-actions">
          <button class="config-flow-button" @click="configFlowVisible = false">{{ t('client.config.flow.ok') }}</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref, toRaw } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { useI18n } from 'vue-i18n';
import { request, requireLogin } from '../../utils/api';
import { getLocale, loadLocaleMessages } from '../../utils/i18n';
import { CONFIG_SCHEMA, getConfigValue, mergeConfig, setConfigValue } from '../../utils/gameConfigSchema';

const { t } = useI18n();
const activeTab = ref(CONFIG_SCHEMA[0].key);
const accountId = ref(0);
const account = ref({});
const config = reactive(mergeConfig());
const loading = ref(false);
const localeReady = ref(false);
const saving = ref(false);
const activeHelpPath = ref('');
const configFlowVisible = ref(false);

const activeGroups = computed(() => {
  const tab = CONFIG_SCHEMA.find((item) => item.key === activeTab.value);
  return tab ? tab.groups : [];
});

onLoad(async (query = {}) => {
  if (requireLogin()) {
    accountId.value = Number(query.id || 0);
    try {
      await loadLocaleMessages(getLocale());
      localeReady.value = true;
      configFlowVisible.value = true;
      await loadConfig();
    } catch (error) {
      console.error(error);
      uni.showToast({ title: error.message, icon: 'none' });
    }
  }
});

async function loadConfig() {
  if (!accountId.value) {
    uni.showToast({ title: t('client.config.missing_account'), icon: 'none' });
    return false;
  }

  loading.value = true;
  try {
    const result = await request({ url: `/api/game-accounts/${accountId.value}/config` });
    account.value = result.account || {};
    Object.assign(config, mergeConfig(result.config || {}));
    return true;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
    return false;
  } finally {
    loading.value = false;
  }
}

async function importConfig() {
  const imported = await loadConfig();
  if (imported) {
    uni.showToast({ title: t('client.config.import_success'), icon: 'none' });
  }
}

async function saveConfig() {
  saving.value = true;
  try {
    const result = await request({
      url: `/api/game-accounts/${accountId.value}/config`,
      method: 'POST',
      data: { config: cloneConfig(config) },
    });
    account.value = result.account || account.value;
    uni.showToast({ title: t('client.config.save_success'), icon: 'none' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    saving.value = false;
  }
}

function visibleItems(items) {
  return items.filter((entry) => isVisible(entry.visibleWhen));
}

function isVisible(condition) {
  if (!condition) {
    return true;
  }
  if (Array.isArray(condition)) {
    return condition.every((item) => isVisible(item));
  }
  if (condition.any) {
    return condition.any.some((item) => isVisible(item));
  }
  if (condition.all) {
    return condition.all.every((item) => isVisible(item));
  }
  const value = getConfigValue(config, condition.path);
  if (Object.prototype.hasOwnProperty.call(condition, 'equals')) {
    return value === condition.equals;
  }
  if (Array.isArray(condition.in)) {
    return condition.in.includes(value);
  }
  return Boolean(value);
}

function isMultiSelected(path, value) {
  const selected = getConfigValue(config, path);
  return Array.isArray(selected) && selected.includes(value);
}

function toggleMultiSelect(path, value) {
  const selected = getConfigValue(config, path);
  const next = Array.isArray(selected) ? [...selected] : [];
  const index = next.indexOf(value);
  if (index >= 0) {
    next.splice(index, 1);
  } else {
    next.push(value);
  }
  setConfigValue(config, path, next);
}

function optionText(entry, value) {
  const match = (entry.options || []).find((option) => option.value === value);
  return match ? t(match.labelKey) : value;
}

function openHelp(path) {
  activeHelpPath.value = path;
}

function closeHelp() {
  activeHelpPath.value = '';
}

function toggleHelp(path) {
  activeHelpPath.value = activeHelpPath.value === path ? '' : path;
}

function back() {
  const pages = getCurrentPages();
  if (pages.length > 1) {
    uni.navigateBack();
    return;
  }
  uni.redirectTo({ url: '/pages/index/index' });
}

function cloneConfig(value) {
  return JSON.parse(JSON.stringify(toRaw(value)));
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  box-sizing: border-box;
  background: #eef2f6;
  color: #111827;
}

.topbar {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 56px;
  padding: 0 18px;
  box-sizing: border-box;
  background: #c7ecff;
  border-bottom: 1px solid #9fd8f5;
}

.back-button {
  position: absolute;
  left: 16px;
  width: 34px;
  height: 34px;
  line-height: 30px;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  color: #111827;
  font-size: 30px;
  font-weight: 300;
}

.page-title {
  color: #4097ff;
  font-size: 20px;
  font-weight: 700;
}

.top-actions {
  position: absolute;
  right: 26px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.import-button,
.save-button {
  width: 62px;
  height: 32px;
  line-height: 32px;
  margin: 0;
  padding: 0;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
}

.import-button {
  border: 1px solid #d9d9d9;
  background: #fff;
  color: #111827;
}

.save-button {
  border: 1px solid #31b9f8;
  background: #31b9f8;
  color: #fff;
}

.config-shell {
  max-width: 1100px;
  min-height: calc(100vh - 56px);
  margin: 0 auto;
  padding: 20px 20px 88px;
  box-sizing: border-box;
  background: #fff;
}

.notice {
  margin-bottom: 14px;
  padding: 12px 16px;
  border: 1px solid #fed7aa;
  border-radius: 6px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 13px;
  line-height: 1.5;
}

.tabs {
  border-bottom: 1px solid #edf0f4;
  white-space: nowrap;
}

.tab-row {
  display: flex;
  align-items: center;
  gap: 24px;
  min-width: max-content;
  height: 34px;
}

.tab {
  height: 34px;
  line-height: 32px;
  border-bottom: 2px solid transparent;
  color: #111827;
  font-size: 14px;
}

.tab.active {
  border-bottom-color: #1890ff;
  color: #1890ff;
}

.form-panel {
  position: relative;
  padding-top: 34px;
}

.group {
  margin-bottom: 24px;
}

.group-heading {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
}

.group-line {
  flex: 1;
  height: 1px;
  background: #edf0f4;
}

.group-line:first-child {
  flex: 0 0 46px;
}

.group-title {
  margin: 0 16px;
  color: #111827;
  font-size: 16px;
  font-weight: 500;
}

.config-row {
  display: flex;
  align-items: center;
  width: 520px;
  min-height: 40px;
  margin: 0 auto 14px 160px;
}

.row-label {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  width: 220px;
  padding-right: 8px;
  box-sizing: border-box;
  color: #111827;
  font-size: 14px;
}

.label-text,
.colon {
  display: block;
}

.help-wrap {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  margin-left: 4px;
}

.help {
  width: 15px;
  height: 15px;
  line-height: 15px;
  border: 1px solid #9ca3af;
  border-radius: 50%;
  color: #64748b;
  text-align: center;
  font-size: 11px;
  font-weight: 700;
}

.help-tooltip {
  position: absolute;
  z-index: 20;
  left: 50%;
  bottom: 24px;
  max-width: 280px;
  min-width: 160px;
  transform: translateX(-50%);
  padding: 8px 10px;
  border-radius: 6px;
  background: rgba(17, 17, 17, 0.92);
  color: #fff;
  text-align: left;
  white-space: normal;
  font-size: 13px;
  line-height: 1.45;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
}

.help-tooltip::after {
  position: absolute;
  left: 50%;
  bottom: -5px;
  width: 10px;
  height: 10px;
  transform: translateX(-50%) rotate(45deg);
  background: rgba(17, 17, 17, 0.92);
  content: '';
}

.control-area {
  display: flex;
  align-items: center;
  min-width: 220px;
}

:deep(.uni-switch-wrapper) {
  display: inline-flex;
  align-items: center;
  width: 44px;
  height: 22px;
}

:deep(.uni-switch-input) {
  width: 44px !important;
  height: 22px !important;
  margin: 0 !important;
  border: 0 !important;
  border-radius: 999px !important;
  background-color: rgba(0, 0, 0, 0.25) !important;
  box-shadow: none !important;
  transition: background-color 0.2s ease;
}

:deep(.uni-switch-input::before) {
  display: none !important;
}

:deep(.uni-switch-input::after) {
  top: 2px !important;
  left: 2px !important;
  width: 18px !important;
  height: 18px !important;
  border-radius: 50% !important;
  background-color: #fff !important;
  box-shadow: 0 2px 4px rgba(0, 35, 75, 0.2) !important;
  transform: translateX(0) !important;
  transition: left 0.2s ease, box-shadow 0.2s ease !important;
}

:deep(.uni-switch-input.uni-switch-input-checked) {
  background-color: #1677ff !important;
}

:deep(.uni-switch-input.uni-switch-input-checked::after) {
  left: 24px !important;
}

:deep(.uni-switch-input:hover::after) {
  box-shadow: 0 2px 8px rgba(0, 35, 75, 0.28) !important;
}

.number-control {
  display: flex;
  align-items: center;
}

.number-input,
.text-input,
.priority-input {
  width: 170px;
  height: 32px;
  padding: 0 10px;
  border: 1px solid #d9d9d9;
  border-radius: 6px 0 0 6px;
  background: #fff;
  box-sizing: border-box;
  text-align: left;
  font-size: 14px;
}

.text-input {
  border-radius: 6px;
}

.number-control .number-input:last-child {
  border-radius: 6px;
}

.unit {
  height: 32px;
  line-height: 30px;
  padding: 0 12px;
  border: 1px solid #d9d9d9;
  border-left: 0;
  border-radius: 0 6px 6px 0;
  box-sizing: border-box;
  background: #fff;
  color: #111827;
  font-size: 14px;
}

.radio-control {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 220px;
}

.radio-option {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 24px;
  color: #111827;
  font-size: 14px;
}

.multi-select-control {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  width: 240px;
  min-height: 32px;
  padding: 4px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  box-sizing: border-box;
  background: #fff;
}

.multi-select-chip {
  min-width: 40px;
  height: 26px;
  line-height: 24px;
  margin: 0;
  padding: 0 8px;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  background: #f8fafc;
  color: #111827;
  font-size: 13px;
}

.multi-select-chip.selected {
  border-color: #1677ff;
  background: #e7f0ff;
  color: #1677ff;
}

.priority-control {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 360px;
}

.priority-row {
  display: grid;
  grid-template-columns: 150px 170px;
  align-items: center;
  column-gap: 16px;
}

.priority-label {
  color: #111827;
  font-size: 14px;
}

.priority-input {
  border-radius: 6px;
}

.loading-text {
  display: block;
  margin-top: 20px;
  text-align: center;
  color: #64748b;
  font-size: 14px;
}

.modal-mask {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  box-sizing: border-box;
  background: rgba(0, 0, 0, 0.42);
}

.config-flow-dialog {
  width: min(416px, 92vw);
  padding: 22px 24px 20px;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.22);
  box-sizing: border-box;
}

.config-flow-body {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.config-flow-icon {
  flex: 0 0 auto;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  border-radius: 50%;
  background: #3db6f2;
  color: #fff;
  font-size: 14px;
  font-weight: 800;
  line-height: 22px;
  text-align: center;
}

.config-flow-main {
  min-width: 0;
}

.config-flow-title {
  display: block;
  margin-bottom: 10px;
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.config-flow-text {
  display: block;
  margin-bottom: 22px;
  color: #111827;
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-wrap;
}

.config-flow-actions {
  display: flex;
  justify-content: flex-end;
}

.config-flow-button {
  min-width: 88px;
  height: 32px;
  line-height: 32px;
  margin: 0;
  padding: 0 16px;
  border: 0;
  border-radius: 6px;
  background: #1296db;
  color: #fff;
  font-size: 14px;
  box-shadow: 0 6px 14px rgba(18, 150, 219, 0.28);
}

@media (max-width: 767px) {
  .topbar {
    justify-content: flex-start;
    height: 60px;
    padding-left: 58px;
  }

  .page-title {
    max-width: calc(100vw - 210px);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 18px;
  }

  .top-actions {
    right: 12px;
    gap: 8px;
  }

  .import-button,
  .save-button {
    width: 56px;
  }

  .config-shell {
    padding: 14px 12px 56px;
  }

  .tab-row {
    gap: 18px;
  }

  .form-panel {
    padding-top: 22px;
  }

  .group-heading {
    margin-bottom: 12px;
  }

  .group-line:first-child {
    flex: 1;
  }

  .config-row {
    justify-content: space-between;
    width: 100%;
    min-height: 48px;
    margin-bottom: 0;
    border-bottom: 1px solid #eef2f7;
  }

  .row-label {
    justify-content: flex-start;
    width: auto;
    min-width: 0;
    padding-right: 10px;
  }

  .control-area {
    min-width: 0;
  }

  .number-input {
    width: 96px;
  }

  .text-input {
    width: 140px;
  }

  .radio-control {
    min-width: 130px;
  }

  .multi-select-control {
    width: 150px;
  }

  .priority-control {
    width: 180px;
  }

  .priority-row {
    grid-template-columns: 1fr 70px;
    column-gap: 8px;
  }

  .priority-input {
    width: 70px;
  }

  .help-tooltip {
    left: 0;
    transform: none;
  }

  .help-tooltip::after {
    left: 14px;
    transform: rotate(45deg);
  }
}
</style>
