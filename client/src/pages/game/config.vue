<template>
  <view class="page">
    <view class="topbar">
      <button class="back-button" @click="back">‹</button>
      <text class="page-title">{{ t('client.config.title') }}</text>
      <view class="top-actions">
        <button class="import-button" :disabled="loading || importDialog.importing" @click="importConfig">{{ t('client.config.import') }}</button>
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
              <view v-else-if="entry.type === 'select'" class="single-select-control">
                <picker
                  mode="selector"
                  :range="singleSelectOptions(entry)"
                  range-key="label"
                  :value="singleSelectIndex(entry)"
                  @change="selectSingleOption(entry, $event)"
                >
                  <view class="single-select-box">
                    <text>{{ selectedSingleOptionLabel(entry) }}</text>
                    <text class="single-select-arrow">⌄</text>
                  </view>
                </picker>
              </view>
              <view v-else-if="entry.type === 'multiSelect'" class="select-control">
                <view
                  :class="['select-box', isDropdownOpen(entry.path) ? 'open' : '']"
                  @click.stop="toggleDropdown(entry.path)"
                >
                  <view class="selected-tags">
                    <view v-for="option in selectedOptions(entry)" :key="option.value" class="selected-tag">
                      <text class="selected-tag-text">{{ optionText(entry, option.value) }}</text>
                      <text class="selected-tag-close" @click.stop="toggleMultiSelect(entry.path, option.value)">×</text>
                    </view>
                    <input
                      v-if="isDropdownOpen(entry.path) || selectedOptions(entry).length === 0"
                      class="select-search"
                      type="text"
                      :value="searchKeyword(entry.path)"
                      :placeholder="selectedOptions(entry).length === 0 ? t('client.config.select.placeholder') : ''"
                      @click.stop="openDropdown(entry.path)"
                      @input="setSearchKeyword(entry.path, $event.detail.value)"
                    />
                  </view>
                  <text class="select-arrow">⌄</text>
                </view>
                <view v-if="isDropdownOpen(entry.path)" class="select-dropdown">
                  <scroll-view scroll-y class="select-options">
                    <view
                      v-for="option in filteredOptions(entry)"
                      :key="option.value"
                      :class="['select-option', isMultiSelected(entry.path, option.value) ? 'selected' : '']"
                      @click.stop="toggleMultiSelect(entry.path, option.value)"
                    >
                      <text class="select-option-text">{{ optionText(entry, option.value) }}</text>
                      <text v-if="isMultiSelected(entry.path, option.value)" class="select-check">✓</text>
                    </view>
                    <view v-if="filteredOptions(entry).length === 0" class="select-empty">
                      {{ t('client.config.select.empty') }}
                    </view>
                  </scroll-view>
                </view>
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

    <view v-if="importDialog.visible && localeReady" class="modal-mask">
      <view class="import-dialog">
        <view class="import-head">
          <text class="import-title">{{ t('client.config.import_dialog.title') }}</text>
          <text class="import-close" @click="closeImportDialog()">×</text>
        </view>
        <text class="import-question">{{ t('client.config.import_dialog.question') }}</text>
        <text class="import-warning">{{ t('client.config.import_dialog.warning') }}</text>
        <view class="import-select">
          <picker
            mode="selector"
            :range="importOptions"
            range-key="label"
            :disabled="importDialog.loading || importDialog.importing || importOptions.length === 0"
            @change="selectImportAccount"
          >
            <view :class="['import-select-box', importOptions.length === 0 ? 'disabled' : '']">
              <text>{{ selectedImportLabel || t('client.config.import_dialog.placeholder') }}</text>
              <text class="import-select-arrow">⌄</text>
            </view>
          </picker>
        </view>
        <text v-if="importDialog.loading" class="import-empty">{{ t('client.config.import_dialog.loading') }}</text>
        <text v-else-if="importOptions.length === 0" class="import-empty">{{ t('client.config.import_dialog.empty') }}</text>
        <view class="import-actions">
          <button class="import-cancel" :disabled="importDialog.importing" @click="closeImportDialog()">{{ t('client.config.import_dialog.cancel') }}</button>
          <button class="import-confirm" :disabled="!importDialog.sourceAccountId || importDialog.importing" @click="confirmImportConfig">
            {{ t('client.config.import_dialog.confirm') }}
          </button>
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
const activeDropdownPath = ref('');
const selectSearch = reactive({});
const currentLocale = ref(getLocale());
const importDialog = reactive({
  visible: false,
  loading: false,
  importing: false,
  sourceAccountId: 0,
  accounts: [],
});

const activeGroups = computed(() => {
  const tab = CONFIG_SCHEMA.find((item) => item.key === activeTab.value);
  return tab ? tab.groups : [];
});

const importOptions = computed(() => importDialog.accounts
  .filter((item) => Number(item.id) !== accountId.value && item.has_config)
  .map((item) => ({
    id: Number(item.id),
    label: accountLabel(item),
  })));

const selectedImportLabel = computed(() => {
  const option = importOptions.value.find((item) => item.id === importDialog.sourceAccountId);
  return option ? option.label : '';
});

onLoad(async (query = {}) => {
  if (requireLogin()) {
    accountId.value = Number(query.id || 0);
    try {
      currentLocale.value = getLocale();
      await loadLocaleMessages(currentLocale.value);
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
  importDialog.visible = true;
  importDialog.sourceAccountId = 0;
  importDialog.accounts = [];
  importDialog.loading = true;
  try {
    const result = await request({ url: '/api/game-accounts' });
    importDialog.accounts = Array.isArray(result.items) ? result.items : [];
  } catch (error) {
    closeImportDialog();
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    importDialog.loading = false;
  }
}

function closeImportDialog(force = false) {
  if (importDialog.importing && !force) {
    return;
  }
  importDialog.visible = false;
  importDialog.loading = false;
  importDialog.sourceAccountId = 0;
  importDialog.accounts = [];
}

function selectImportAccount(event) {
  const index = Number(event.detail.value);
  const option = importOptions.value[index];
  importDialog.sourceAccountId = option ? option.id : 0;
}

async function confirmImportConfig() {
  if (!importDialog.sourceAccountId) {
    uni.showToast({ title: t('client.config.import_dialog.placeholder'), icon: 'none' });
    return;
  }

  importDialog.importing = true;
  try {
    const result = await request({
      url: `/api/game-accounts/${accountId.value}/config/import`,
      method: 'POST',
      data: { source_account_id: importDialog.sourceAccountId },
    });
    account.value = result.account || account.value;
    Object.assign(config, mergeConfig(result.config || {}));
    closeImportDialog(true);
    uni.showToast({ title: t('client.config.import_success'), icon: 'none' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    importDialog.importing = false;
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

function openDropdown(path) {
  activeDropdownPath.value = path;
}

function toggleDropdown(path) {
  activeDropdownPath.value = activeDropdownPath.value === path ? '' : path;
}

function isDropdownOpen(path) {
  return activeDropdownPath.value === path;
}

function searchKeyword(path) {
  return selectSearch[path] || '';
}

function setSearchKeyword(path, value) {
  selectSearch[path] = value || '';
  activeDropdownPath.value = path;
}

function selectedOptions(entry) {
  const selected = getConfigValue(config, entry.path);
  if (!Array.isArray(selected)) {
    return [];
  }
  return selected
    .map((value) => (entry.options || []).find((option) => option.value === value) || { value, labelZh: value, labelVi: value })
    .filter(Boolean);
}

function singleSelectOptions(entry) {
  return (entry.options || []).map((option) => ({
    ...option,
    label: optionText(entry, option.value),
  }));
}

function singleSelectIndex(entry) {
  const value = String(getConfigValue(config, entry.path) ?? entry.defaultValue);
  const index = (entry.options || []).findIndex((option) => option.value === value);
  return index >= 0 ? index : 0;
}

function selectedSingleOptionLabel(entry) {
  const value = String(getConfigValue(config, entry.path) ?? entry.defaultValue);
  const match = (entry.options || []).find((option) => option.value === value);
  return match ? optionText(entry, match.value) : value;
}

function selectSingleOption(entry, event) {
  const index = Number(event.detail.value);
  const option = (entry.options || [])[index];
  if (!option) {
    return;
  }
  setConfigValue(config, entry.path, option.value);
}

function filteredOptions(entry) {
  const keyword = searchKeyword(entry.path).trim().toLowerCase();
  const options = entry.options || [];
  if (!keyword) {
    return options;
  }
  return options.filter((option) => {
    const label = optionText(entry, option.value).toLowerCase();
    const labelVi = String(option.labelVi || '').toLowerCase();
    return label.includes(keyword) || labelVi.includes(keyword) || String(option.value).includes(keyword);
  });
}

function optionText(entry, value) {
  const match = (entry.options || []).find((option) => option.value === value);
  if (!match) {
    return value;
  }
  if (match.labelKey) {
    return t(match.labelKey);
  }
  if (currentLocale.value === 'vi') {
    return match.labelVi || match.labelZh || value;
  }
  return match.labelZh || match.labelVi || value;
}

function accountLabel(item) {
  const name = item.display_name || item.game_username || `#${item.id}`;
  const server = item.server_name || item.server_id || '';
  return server ? `${name} - ${server}` : name;
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

.single-select-control {
  width: 190px;
}

.single-select-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 32px;
  padding: 0 10px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  box-sizing: border-box;
  background: #fff;
  color: #111827;
  font-size: 14px;
}

.single-select-arrow {
  color: #9ca3af;
  font-size: 14px;
}

.select-control {
  position: relative;
  width: 260px;
  min-height: 34px;
}

.select-box {
  display: flex;
  align-items: center;
  min-height: 34px;
  padding: 3px 30px 3px 6px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  box-sizing: border-box;
  background: #ffffff;
}

.select-box.open {
  border-color: #22c55e;
  box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.15);
}

.selected-tags {
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.selected-tag {
  display: inline-flex;
  align-items: center;
  max-width: 210px;
  height: 24px;
  padding: 0 6px;
  border-radius: 4px;
  box-sizing: border-box;
  background: #f3f4f6;
  color: #374151;
  font-size: 13px;
}

.selected-tag-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.selected-tag-close {
  flex: none;
  margin-left: 4px;
  color: #6b7280;
  font-size: 16px;
  line-height: 1;
}

.select-search {
  flex: 1;
  min-width: 68px;
  height: 24px;
  line-height: 24px;
  border: 0;
  color: #111827;
  font-size: 14px;
}

.select-arrow {
  position: absolute;
  top: 7px;
  right: 10px;
  color: #6b7280;
  font-size: 18px;
}

.select-dropdown {
  position: absolute;
  z-index: 20;
  top: calc(100% + 4px);
  left: 0;
  width: 100%;
  overflow: hidden;
  border-radius: 6px;
  background: #ffffff;
  box-shadow: 0 8px 22px rgba(15, 23, 42, 0.18);
}

.select-options {
  height: 260px;
  max-height: 260px;
}

.select-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 34px;
  padding: 0 10px;
  box-sizing: border-box;
  color: #111827;
  font-size: 14px;
}

.select-option.selected {
  background: #e6f4ff;
  color: #1677ff;
  font-weight: 600;
}

.select-option-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.select-check {
  flex: none;
  margin-left: 10px;
  color: #1677ff;
  font-size: 16px;
}

.select-empty {
  padding: 14px 10px;
  color: #9ca3af;
  font-size: 14px;
  text-align: center;
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

.import-dialog {
  width: min(520px, 92vw);
  padding: 20px 24px 16px;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 24px 48px rgba(15, 23, 42, 0.22);
  box-sizing: border-box;
}

.import-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.import-title {
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.import-close {
  width: 26px;
  height: 26px;
  line-height: 24px;
  color: #9ca3af;
  text-align: center;
  font-size: 26px;
}

.import-question,
.import-warning,
.import-empty {
  display: block;
  font-size: 14px;
  line-height: 1.6;
}

.import-question {
  margin-bottom: 10px;
  color: #111827;
}

.import-warning {
  margin-bottom: 14px;
  color: #f04438;
}

.import-select {
  margin-bottom: 8px;
}

.import-select-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 34px;
  padding: 0 12px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  box-sizing: border-box;
  background: #fff;
  color: #111827;
  font-size: 14px;
}

.import-select-box.disabled {
  background: #f9fafb;
  color: #9ca3af;
}

.import-select-arrow {
  color: #9ca3af;
  font-size: 18px;
}

.import-empty {
  min-height: 22px;
  color: #9ca3af;
}

.import-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 10px;
}

.import-cancel,
.import-confirm {
  min-width: 72px;
  height: 32px;
  line-height: 32px;
  margin: 0;
  padding: 0 14px;
  border-radius: 6px;
  font-size: 14px;
}

.import-cancel {
  border: 1px solid #d9d9d9;
  background: #fff;
  color: #111827;
}

.import-confirm {
  border: 1px solid #31b9f8;
  background: #31b9f8;
  color: #fff;
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

  .select-control {
    width: 150px;
  }

  .selected-tag {
    max-width: 112px;
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
