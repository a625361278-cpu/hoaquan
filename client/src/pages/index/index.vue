<template>
  <view class="page">
    <view class="topbar">
      <view class="brand">
        <text class="brand-icon">🌸</text>
        <text class="brand-name">Hoa Quán</text>
      </view>
      <view class="top-actions">
        <button class="balance" @click="goPointRecharge">💰 {{ t('client.home.balance') }}：{{ user.balance || '0' }}</button>
        <button class="profile" @click="goProfile">{{ t('client.home.my') }}</button>
      </view>
    </view>

    <view class="language-switch">
      <text :class="['language-option', currentLocale === 'zh_CN' ? 'active' : '']" @click="changeLocale('zh_CN')">{{ t('client.language.zh_CN') }}</text>
      <text class="language-divider">/</text>
      <text :class="['language-option', currentLocale === 'vi' ? 'active' : '']" @click="changeLocale('vi')">{{ t('client.language.vi') }}</text>
    </view>

    <view class="account-grid">
      <view v-for="item in gameAccounts" :key="item.id" class="account-card">
        <view class="account-head">
          <text class="account-name">{{ item.display_name || item.game_username }}</text>
          <view class="server-menu">
            <text class="server-name">{{ item.server_name || t('client.home.unknown_server') }}</text>
            <button class="more-button" @click.stop="toggleMenu(item.id)">⋮</button>
          </view>
          <view v-if="activeMenuId === item.id" class="menu-pop">
            <view class="menu-item" @click.stop="addQuota(item)">＋ {{ t('client.home.add_quota') }}</view>
            <view class="menu-item" @click.stop="openPasswordDialog(item)">🔒 {{ t('client.home.update_game_password') }}</view>
            <view class="menu-item danger" @click.stop="deleteAccount(item)">⌫ {{ t('client.home.delete_role') }}</view>
          </view>
        </view>

        <view class="resource-grid">
          <text v-for="field in resourceFields" :key="field.key" class="resource-item">
            {{ t(field.labelKey) }}：<text class="resource-value">{{ resourceValue(item, field.key) }}</text>
          </text>
        </view>

        <view class="expire-row">
          <text>{{ t('client.home.expire_at') }}：</text>
          <text :class="['expire-value', isExpired(item) ? 'expired' : '']">{{ expireText(item) }}</text>
        </view>

        <view class="card-actions">
          <text :class="['status-dot', item.status === 'running' ? 'online' : '', ['starting', 'stopping', 'reconnecting'].includes(item.status) ? 'pending' : '', item.status === 'error' ? 'error' : '']"></text>
          <text class="status-text">{{ statusText(item) }}</text>
          <button class="action primary" :disabled="isActiveAccount(item) || actionLoading" @click="startAccount(item)">{{ t('client.home.start') }}</button>
          <button class="action stop" :disabled="!isActiveAccount(item) || actionLoading" @click="stopAccount(item)">{{ t('client.home.stop') }}</button>
          <button class="action" @click="openLogs(item)">{{ t('client.home.logs') }}</button>
          <button class="action primary" @click="goGameConfig(item)">{{ t('client.home.config') }}</button>
        </view>
      </view>

      <view class="add-card" @click="goAddGame">
        <text class="plus">+</text>
        <text>{{ t('client.home.add_game') }}</text>
      </view>
    </view>

    <view v-if="passwordDialog.visible" class="modal-mask">
      <view class="small-dialog">
        <view class="dialog-head">
          <text class="dialog-title">{{ t('client.home.update_game_password') }}</text>
          <text class="close" @click="closePasswordDialog">×</text>
        </view>
        <input v-model="passwordDialog.password" class="dialog-input" password :placeholder="t('client.home.password_placeholder')" />
        <view class="dialog-actions">
          <button class="dialog-secondary" @click="closePasswordDialog">{{ t('admin.common.cancel') }}</button>
          <button class="dialog-primary" @click="submitPassword">{{ t('admin.common.submit') }}</button>
        </view>
      </view>
    </view>

    <view v-if="quotaDialog.visible" class="modal-mask">
      <view class="quota-dialog">
        <view class="dialog-head">
          <text class="dialog-title">{{ t('client.quota.title', { name: quotaDialog.account?.display_name || '' }) }}</text>
          <text class="close" @click="closeQuotaDialog">×</text>
        </view>
        <view class="quota-current">
          <view>
            <text>{{ t('client.quota.current_balance') }}：</text>
            <text class="quota-balance">{{ user.balance || '0' }}</text>
          </view>
          <button class="quota-recharge" @click="goPointRecharge">{{ t('client.recharge.title') }}</button>
        </view>
        <text class="quota-line">{{ t('client.quota.current_expire') }}：{{ quotaDialog.account?.expire_time || t('client.home.expired') }}</text>
        <text class="quota-line">{{ t('client.quota.choose_duration') }}：</text>

        <view class="quota-package">
          <view class="quota-package-main">
            <text class="quota-package-title">{{ t('client.quota.package_title') }}</text>
            <text class="quota-tag">{{ t('client.quota.package_badge') }}</text>
          </view>
          <text class="quota-cost">-{{ BASE_QUOTA_COST }}{{ t('client.quota.points_unit') }}</text>
          <text class="quota-desc">{{ t('client.quota.package_desc') }}</text>
        </view>

        <view class="quota-extra-head">
          <text>{{ t('client.quota.extra_label') }}：</text>
          <text class="quota-total">{{ quotaTotalCost }} {{ t('client.quota.points_unit') }}</text>
        </view>
        <text class="quota-warning">{{ t('client.quota.notice', { base: BASE_QUOTA_COST, extra: quotaDialog.extraPoints, total: quotaTotalCost, days: quotaTotalDays }) }}</text>
        <slider
          class="quota-slider"
          :value="quotaDialog.extraPoints"
          :min="0"
          :max="quotaExtraMax"
          :step="1"
          activeColor="#29b6f6"
          backgroundColor="#e5e7eb"
          @change="onQuotaSliderChange"
        />
        <view class="quota-extra-input">
          <text>0</text>
          <input v-model="quotaDialog.extraInput" class="quota-number" type="number" @input="onQuotaInputChange" />
        </view>
        <view class="quota-summary">
          <text>{{ t('client.quota.package_points', { points: BASE_QUOTA_COST }) }}</text>
          <text>{{ t('client.quota.extra_points', { points: quotaDialog.extraPoints }) }}</text>
        </view>
        <view class="quota-estimate">
          <text>{{ t('client.quota.estimated_expire') }}：</text>
          <text class="quota-estimate-time">{{ quotaEstimatedExpire }}</text>
        </view>
        <view class="dialog-actions">
          <button class="dialog-secondary" @click="closeQuotaDialog">{{ t('admin.common.cancel') }}</button>
          <button class="dialog-primary" :disabled="actionLoading" @click="submitQuota">{{ t('client.quota.confirm') }}</button>
        </view>
      </view>
    </view>

    <view v-if="logs.visible" class="modal-mask">
      <view class="log-dialog">
        <view class="log-head">
          <text class="log-title">📋 {{ t('client.logs.title') }} - {{ logs.account?.display_name || '' }} - {{ logs.account?.server_name || t('client.home.unknown_server') }}</text>
          <text class="close" @click="closeLogs">×</text>
        </view>
        <view class="log-body">
          <view class="log-main">
            <view class="log-tools">
              <picker
                class="log-category-picker"
                mode="selector"
                :range="logCategoryLabels"
                :value="activeLogCategoryIndex"
                @change="onLogCategoryChange"
              >
                <view class="log-category-select">
                  <text class="log-category-label">{{ selectedLogCategoryLabel }}</text>
                  <text class="log-category-arrow">⌄</text>
                </view>
              </picker>
              <input v-model="logs.search" class="log-search" :placeholder="t('client.logs.search')" />
              <button class="log-mode" @click="toggleLogMode">
                {{ logs.mode === 'raw' ? t('client.logs.event_cards') : t('client.logs.normal_logs') }}
              </button>
            </view>
            <view class="log-options">
              <label class="check"><checkbox :checked="logs.autoRefresh" @click="logs.autoRefresh = !logs.autoRefresh" />{{ t('client.logs.auto_refresh') }}</label>
              <label class="check"><checkbox :checked="logs.autoScroll" @click="logs.autoScroll = !logs.autoScroll" />{{ t('client.logs.auto_scroll') }}</label>
              <button class="transport" @click="toggleLogTransport">{{ logs.transport === 'ws' ? t('client.logs.transport_ws') : t('client.logs.transport_http') }}</button>
              <button class="clear-log" @click="clearLogs">{{ t('client.logs.clear_history') }}</button>
              <text class="recent-text">{{ t('client.logs.show_recent', { count: visibleLogCount }) }}</text>
            </view>
            <scroll-view class="log-list" scroll-y :scroll-into-view="logs.autoScroll ? logsTailId : ''">
              <view v-if="logs.mode === 'event'">
                <view v-for="event in filteredEvents" :key="event.id" class="event-card">
                  <view class="event-top">
                    <text class="event-module">{{ event.module }}</text>
                    <text class="event-status">{{ event.status }}</text>
                  </view>
                  <text class="event-title">{{ event.title || event.desc || event.module }}</text>
                  <text class="event-desc">{{ event.desc }}</text>
                </view>
              </view>
              <view v-else>
                <view v-for="(line, index) in filteredLogLines" :id="`log-${index}`" :key="index" class="log-line">
                  <text>{{ line }}</text>
                </view>
              </view>
              <view v-if="visibleLogCount === 0" class="empty-log">{{ t('client.logs.empty') }}</view>
              <view :id="logsTailId" class="log-tail"></view>
            </scroll-view>
            <view class="log-footer">
              <button class="dialog-primary" @click="closeLogs">{{ t('client.logs.close') }}</button>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view v-if="rechargeVisible" class="modal-mask">
      <view class="recharge-dialog">
        <view class="recharge-head">
          <view class="recharge-title-wrap">
            <text class="recharge-title-icon">▣</text>
            <text class="dialog-title">{{ t('client.recharge.title') }}</text>
          </view>
          <text class="close" @click="closeRecharge">×</text>
        </view>
        <view class="recharge-steps">
          <view class="recharge-step active">
            <text class="recharge-step-icon">▣</text>
            <text>{{ t('client.recharge.select_package') }}</text>
          </view>
          <view class="recharge-step-line"></view>
          <view class="recharge-step">
            <text class="recharge-step-icon">◉</text>
            <text>{{ t('client.recharge.scan') }}</text>
          </view>
          <view class="recharge-step-line"></view>
          <view class="recharge-step muted">
            <text class="recharge-step-icon">✓</text>
            <text>{{ t('client.recharge.pay_done') }}</text>
          </view>
        </view>

        <text class="recharge-section-title">{{ t('client.recharge.package') }}</text>
        <view class="recharge-package-card">
          <text class="recharge-price">{{ RECHARGE_PRICE }}</text>
          <text class="recharge-quota">{{ t('client.recharge.quota') }}</text>
          <text class="recharge-limit">{{ t('client.recharge.limit') }}</text>
          <text class="recharge-selected">✓</text>
        </view>

        <view class="recharge-pay-method">
          <text>{{ t('client.recharge.pay_method') }}</text>
          <text class="recharge-pending">{{ t('client.recharge.pending') }}</text>
        </view>

        <view class="recharge-actions">
          <button class="recharge-cancel" @click="closeRecharge">{{ t('client.recharge.cancel') }}</button>
          <button class="recharge-pay-button" @click="openPaymentWindow">{{ t('client.recharge.pay', { amount: RECHARGE_PRICE }) }}</button>
        </view>
      </view>
    </view>

    <view v-if="announcement.visible" class="announcement-mask">
      <view class="announcement-dialog" @click.stop>
        <view class="announcement-head">
          <view class="announcement-title">
            <text class="announcement-icon">📣</text>
            <text>{{ announcement.title || t('client.announcement.title') }}</text>
          </view>
          <text class="announcement-close" @click="closeAnnouncement">×</text>
        </view>
        <view class="announcement-content">
          <text
            v-for="(block, index) in announcement.blocks"
            :key="index"
            :class="['announcement-line', `announcement-${block.color || 'default'}`]"
          >
            {{ block.text }}
          </text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
import { useI18n } from 'vue-i18n';
import { API_BASE_URL, consumeLoginAnnouncementPending, getToken, request, requireLogin } from '../../utils/api';
import { getLocale, switchLocale } from '../../utils/i18n';

const { t } = useI18n();
const user = ref({});
const gameAccounts = ref([]);
const rechargeVisible = ref(false);
const currentLocale = ref(getLocale());
const activeMenuId = ref(null);
const actionLoading = ref(false);
const logsTailId = 'log-tail';
const BASE_QUOTA_COST = 10;
const BASE_QUOTA_DAYS = 11;
const RECHARGE_PRICE = '149000';
let logTimer = null;
let logSocket = null;

const announcement = reactive({
  visible: false,
  title: '',
  blocks: [],
});

const resourceFields = [
  { key: 'level', labelKey: 'client.home.resource.level' },
  { key: 'water', labelKey: 'client.home.resource.water' },
  { key: 'diamond', labelKey: 'client.home.resource.diamond' },
  { key: 'coin', labelKey: 'client.home.resource.coin' },
  { key: 'speedCard', labelKey: 'client.home.resource.speed_card' },
  { key: 'hireBook', labelKey: 'client.home.resource.hire_book' },
  { key: 'pearl', labelKey: 'client.home.resource.pearl' },
  { key: 'floralCoin', labelKey: 'client.home.resource.floral_coin' },
  { key: 'meowCoin', labelKey: 'client.home.resource.meow_coin' },
  { key: 'raceCoin', labelKey: 'client.home.resource.race' },
  { key: 'flowerFinish', labelKey: 'client.home.resource.flower_order' },
  { key: 'satinFinish', labelKey: 'client.home.resource.satin_order' },
  { key: 'decorateFinish', labelKey: 'client.home.resource.decorate_order' },
  { key: 'customerFinish', labelKey: 'client.home.resource.customer_order' },
];

const passwordDialog = reactive({
  visible: false,
  account: null,
  password: '',
});

const quotaDialog = reactive({
  visible: false,
  account: null,
  extraPoints: 0,
  extraInput: '0',
});

const logs = reactive({
  visible: false,
  account: null,
  lines: [],
  events: [],
  lastLine: 0,
  lastEvent: 0,
  category: t('client.logs.all'),
  search: '',
  mode: 'raw',
  autoRefresh: true,
  autoScroll: true,
  transport: 'ws',
});

onShow(() => {
  if (requireLogin()) {
    loadHome();
  }
});

onHide(closeLogSocket);
onUnload(closeLogSocket);

async function loadHome() {
  try {
    const me = await request({ url: '/api/me' });
    user.value = me.user;
    const accounts = await request({ url: '/api/game-accounts' });
    gameAccounts.value = accounts.items || [];
    await maybeShowLoginAnnouncement();
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
    if (error.code === 401) {
      uni.redirectTo({ url: '/pages/login/index' });
    }
  }
}

async function maybeShowLoginAnnouncement() {
  if (!consumeLoginAnnouncementPending()) {
    return;
  }

  try {
    const result = await request({ url: '/api/announcements/latest' });
    if (!result.announcement) {
      return;
    }

    if (!Array.isArray(result.announcement.content_blocks) || result.announcement.content_blocks.length === 0) {
      throw new Error(t('client.announcement.invalid_content'));
    }

    announcement.title = result.announcement.title || t('client.announcement.title');
    announcement.blocks = result.announcement.content_blocks;
    announcement.visible = true;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function closeAnnouncement() {
  announcement.visible = false;
  announcement.title = '';
  announcement.blocks = [];
}

async function changeLocale(locale) {
  currentLocale.value = await switchLocale(locale);
  logs.category = t('client.logs.all');
}

function resourceValue(item, key) {
  const value = item.resources && item.resources[key];
  return value === undefined || value === null || value === '' ? 0 : value;
}

function isExpired(item) {
  if (!item.expire_time) return true;
  return new Date(String(item.expire_time).replace(/-/g, '/')).getTime() < Date.now();
}

function expireText(item) {
  if (!item.expire_time) return t('client.home.expired');
  return item.expire_time;
}

function isActiveAccount(item) {
  return ['starting', 'running', 'reconnecting', 'stopping'].includes(item.status);
}

function statusText(item) {
  if (item.status === 'running') return t('client.home.status.running');
  if (item.status === 'starting') return t('client.home.status.starting');
  if (item.status === 'reconnecting') return t('client.home.status.reconnecting');
  if (item.status === 'stopping') return t('client.home.status.stopping');
  if (item.status === 'error') return t('client.home.status.error');
  if (item.status === 'stopped') return t('client.home.status.stopped');
  return t('client.home.status.local_preview');
}

function toggleMenu(id) {
  activeMenuId.value = activeMenuId.value === id ? null : id;
}

async function startAccount(item) {
  await accountAction(`/api/game-accounts/${item.id}/start`, 'POST');
}

async function stopAccount(item) {
  await accountAction(`/api/game-accounts/${item.id}/stop`, 'POST');
}

async function addQuota(item) {
  activeMenuId.value = null;
  quotaDialog.visible = true;
  quotaDialog.account = item;
  quotaDialog.extraPoints = 0;
  quotaDialog.extraInput = '0';
}

async function accountAction(url, method) {
  actionLoading.value = true;
  try {
    const result = await request({ url, method });
    if (result.account) {
      replaceAccount(result.account);
    }
    await loadHome();
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    actionLoading.value = false;
  }
}

function replaceAccount(account) {
  gameAccounts.value = gameAccounts.value.map((item) => (item.id === account.id ? account : item));
}

const quotaTotalCost = computed(() => BASE_QUOTA_COST + quotaDialog.extraPoints);
const quotaTotalDays = computed(() => BASE_QUOTA_DAYS + quotaDialog.extraPoints);
const quotaExtraMax = computed(() => Math.max(0, Math.floor(Number(user.value.balance || 0)) - BASE_QUOTA_COST));
const quotaEstimatedExpire = computed(() => {
  if (!quotaDialog.account) return '';
  const currentExpire = quotaDialog.account.expire_time
    ? new Date(String(quotaDialog.account.expire_time).replace(/-/g, '/')).getTime()
    : 0;
  const base = Math.max(Date.now(), Number.isFinite(currentExpire) ? currentExpire : 0);
  const estimated = new Date(base + quotaTotalDays.value * 24 * 60 * 60 * 1000);
  return formatDateTime(estimated);
});

function closeQuotaDialog() {
  quotaDialog.visible = false;
  quotaDialog.account = null;
  quotaDialog.extraPoints = 0;
  quotaDialog.extraInput = '0';
}

function onQuotaSliderChange(event) {
  setQuotaExtra(event.detail.value);
}

function onQuotaInputChange(event) {
  setQuotaExtra(event.detail.value);
}

function setQuotaExtra(value) {
  const parsed = Number.parseInt(String(value), 10);
  const next = Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
  quotaDialog.extraPoints = Math.min(next, quotaExtraMax.value);
  quotaDialog.extraInput = String(quotaDialog.extraPoints);
}

async function submitQuota() {
  if (!quotaDialog.account) return;
  if (Number(user.value.balance || 0) < quotaTotalCost.value) {
    uni.showToast({ title: t('client.quota.insufficient'), icon: 'none' });
    return;
  }
  actionLoading.value = true;
  try {
    const result = await request({
      url: `/api/game-accounts/${quotaDialog.account.id}/quota`,
      method: 'POST',
      data: { extra_points: quotaDialog.extraPoints },
    });
    if (result.account) {
      replaceAccount(result.account);
    }
    if (result.balance !== undefined) {
      user.value = { ...user.value, balance: result.balance };
    }
    closeQuotaDialog();
    await loadHome();
    uni.showToast({ title: t('client.quota.success'), icon: 'success' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    actionLoading.value = false;
  }
}

function formatDateTime(date) {
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function openPasswordDialog(item) {
  activeMenuId.value = null;
  passwordDialog.visible = true;
  passwordDialog.account = item;
  passwordDialog.password = '';
}

function closePasswordDialog() {
  passwordDialog.visible = false;
  passwordDialog.account = null;
  passwordDialog.password = '';
}

async function submitPassword() {
  if (!passwordDialog.password) {
    uni.showToast({ title: t('client.add.require_game_credentials'), icon: 'none' });
    return;
  }
  try {
    await request({
      url: `/api/game-accounts/${passwordDialog.account.id}/password`,
      method: 'POST',
      data: { game_password: passwordDialog.password },
    });
    closePasswordDialog();
    await loadHome();
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function deleteAccount(item) {
  activeMenuId.value = null;
  uni.showModal({
    title: t('client.home.delete_role'),
    content: t('client.home.delete_confirm'),
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await request({ url: `/api/game-accounts/${item.id}`, method: 'DELETE' });
        await loadHome();
      } catch (error) {
        uni.showToast({ title: error.message, icon: 'none' });
      }
    },
  });
}

function openLogs(item) {
  logs.visible = true;
  logs.account = item;
  logs.lines = [];
  logs.events = [];
  logs.lastLine = 0;
  logs.lastEvent = 0;
  logs.category = t('client.logs.all');
  fetchLogs(true);
  connectLogSocket();
  startLogTimer();
}

function closeLogs() {
  logs.visible = false;
  logs.account = null;
  stopLogTimer();
  closeLogSocket();
}

async function fetchLogs(reset = false) {
  if (!logs.account) return;
  try {
    const lastLine = reset ? 0 : logs.lastLine;
    const lastEvent = reset ? 0 : logs.lastEvent;
    const result = await request({ url: `/api/game-accounts/${logs.account.id}/logs?lastLine=${lastLine}&lastEvent=${lastEvent}` });
    applyLogPayload(result, reset);
    await nextTick();
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function applyLogPayload(payload, reset = false) {
  const incomingLines = Array.isArray(payload.logs) ? payload.logs.map((line) => String(line)) : [];
  const incomingEvents = Array.isArray(payload.events) ? payload.events : [];
  logs.lines = reset ? incomingLines : logs.lines.concat(incomingLines);
  logs.events = reset ? incomingEvents : logs.events.concat(incomingEvents);
  if (logs.lines.length > 2500) {
    logs.lines = logs.lines.slice(-2500);
  }
  if (logs.events.length > 2500) {
    logs.events = logs.events.slice(-2500);
  }
  logs.lastLine = payload.lastLine || logs.lastLine;
  logs.lastEvent = payload.lastEvent || logs.lastEvent;
}

function connectLogSocket() {
  closeLogSocket();
  if (logs.transport !== 'ws' || !logs.account) return;
  // #ifdef H5
  const httpBase = API_BASE_URL || window.location.origin;
  const url = new URL(httpBase, window.location.origin);
  url.port = url.port === '8790' ? '8791' : url.port;
  url.protocol = url.protocol.replace('http', 'ws');
  const base = `${url.protocol}//${url.host}`;
  logSocket = new WebSocket(`${base}/ws/game-accounts/${logs.account.id}/logs?token=${encodeURIComponent(getToken())}&locale=${encodeURIComponent(getLocale())}`);
  logSocket.onmessage = (event) => {
    try {
      const payload = JSON.parse(event.data);
      applyLogPayload({ ...payload, logs: payload.logs || payload.lines || [] }, false);
    } catch (error) {
      logs.lines = logs.lines.concat(String(event.data)).slice(-2500);
    }
  };
  logSocket.onerror = () => {
    closeLogSocket();
    logs.transport = 'http';
    startLogTimer();
  };
  // #endif
}

function closeLogSocket() {
  if (logSocket) {
    logSocket.close();
    logSocket = null;
  }
}

function startLogTimer() {
  stopLogTimer();
  logTimer = setInterval(() => {
    if (logs.visible && logs.autoRefresh) {
      fetchLogs(false);
    }
  }, 10000);
}

function stopLogTimer() {
  if (logTimer) {
    clearInterval(logTimer);
    logTimer = null;
  }
}

function toggleLogTransport() {
  logs.transport = logs.transport === 'ws' ? 'http' : 'ws';
  if (logs.transport === 'ws') {
    connectLogSocket();
  } else {
    closeLogSocket();
  }
}

function toggleLogMode() {
  logs.mode = logs.mode === 'raw' ? 'event' : 'raw';
  logs.category = t('client.logs.all');
}

function onLogCategoryChange(event) {
  const index = Number(event.detail.value);
  const category = logCategories.value[index];
  logs.category = category?.name || t('client.logs.all');
}

async function clearLogs() {
  if (!logs.account) return;
  try {
    const type = logs.mode === 'event' ? 'event' : 'normal';
    await request({ url: `/api/game-accounts/${logs.account.id}/logs?type=${type}`, method: 'DELETE' });
    if (type === 'event') {
      logs.events = [];
      logs.lastEvent = 0;
    } else {
      logs.lines = [];
      logs.lastLine = 0;
    }
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

const normalLogItems = computed(() => logs.lines.map((line) => {
  const moduleMatch = String(line).match(/\[([^\]]+)\]/);
  const evtIndex = String(line).indexOf('[[EVT]]');
  let event = null;
  if (evtIndex >= 0) {
    try {
      event = JSON.parse(String(line).slice(evtIndex + 7).trim());
    } catch (error) {
      event = null;
    }
  }
  return {
    raw: String(line),
    module: event?.module || moduleMatch?.[1] || t('client.logs.all'),
    event,
  };
}));

const eventLogItems = computed(() => logs.events.map((event, index) => ({
  id: event.id || `${event.event_no || index}`,
  module: event.module || t('client.logs.all'),
  title: event.title || '',
  desc: event.desc || event.message || '',
  status: event.status || '',
  time: event.time || '',
  raw: event,
  searchText: JSON.stringify(event),
})));

const activeLogItems = computed(() => (logs.mode === 'event' ? eventLogItems.value : normalLogItems.value));

const logCategories = computed(() => {
  const counts = new Map([[t('client.logs.all'), activeLogItems.value.length]]);
  activeLogItems.value.forEach((item) => counts.set(item.module, (counts.get(item.module) || 0) + 1));
  return Array.from(counts.entries()).map(([name, count]) => ({ name, count }));
});
const logCategoryLabels = computed(() => logCategories.value.map((cat) => `${cat.name}（${cat.count}）`));
const activeLogCategoryIndex = computed(() => {
  const index = logCategories.value.findIndex((cat) => cat.name === logs.category);
  return index >= 0 ? index : 0;
});
const selectedLogCategoryLabel = computed(() => logCategoryLabels.value[activeLogCategoryIndex.value] || `${t('client.logs.all')}（0）`);

const filteredLogItems = computed(() => activeLogItems.value.filter((item) => {
  const all = t('client.logs.all');
  const matchCategory = logs.category === all || item.module === logs.category;
  const raw = typeof item.raw === 'string' ? item.raw : item.searchText || JSON.stringify(item.raw);
  const matchSearch = !logs.search || raw.toLowerCase().includes(logs.search.toLowerCase());
  return matchCategory && matchSearch;
}));

const filteredLogLines = computed(() => filteredLogItems.value
  .filter((item) => typeof item.raw === 'string')
  .map((item) => item.raw));
const filteredEvents = computed(() => filteredLogItems.value
  .filter((item) => typeof item.raw !== 'string')
  .map((item) => ({ id: item.id, ...item.raw })));
const visibleLogCount = computed(() => (logs.mode === 'event' ? filteredEvents.value.length : filteredLogLines.value.length));

function goAddGame() {
  uni.navigateTo({ url: '/pages/game/add' });
}

function goGameConfig(item) {
  uni.navigateTo({ url: `/pages/game/config?id=${item.id}` });
}

function goProfile() {
  uni.navigateTo({ url: '/pages/profile/index' });
}

function goPointRecharge() {
  if (quotaDialog.visible) {
    closeQuotaDialog();
  }
  rechargeVisible.value = true;
}

function closeRecharge() {
  rechargeVisible.value = false;
}

function openPaymentWindow() {
  // #ifdef H5
  window.open(`${window.location.origin}/static/temp-payment-apply.html#/`, '_blank', 'noopener,noreferrer');
  // #endif
  // #ifndef H5
  uni.showToast({ title: t('client.recharge.h5_only'), icon: 'none' });
  // #endif
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding: 28rpx 42rpx;
  box-sizing: border-box;
  background: linear-gradient(180deg, #bceeff 0%, #d8f5ff 100%);
  color: #1f2937;
}

.topbar,
.brand,
.top-actions,
.account-head,
.server-menu,
.card-actions,
.language-switch,
.dialog-head,
.dialog-actions,
.log-head,
.log-tools,
.log-options,
.event-top {
  display: flex;
  align-items: center;
}

.topbar {
  justify-content: space-between;
  margin-bottom: 18rpx;
}

.brand {
  gap: 12rpx;
  font-size: 34rpx;
  font-weight: 800;
}

.brand-icon {
  font-size: 38rpx;
}

.top-actions {
  gap: 14rpx;
}

.balance,
.profile {
  height: 60rpx;
  line-height: 60rpx;
  margin: 0;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.9);
  color: #344054;
  font-size: 24rpx;
}

.language-switch {
  justify-content: flex-end;
  gap: 12rpx;
  margin-bottom: 24rpx;
  color: #7b8794;
  font-size: 24rpx;
}

.language-option.active {
  color: #1677ff;
  font-weight: 800;
}

.account-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 28rpx;
}

.account-card,
.add-card {
  min-height: 270rpx;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.94);
  box-shadow: 0 16rpx 30rpx rgba(87, 178, 220, 0.2);
}

.account-card {
  position: relative;
  padding: 28rpx;
}

.account-head {
  justify-content: space-between;
  margin-bottom: 22rpx;
}

.account-name {
  font-size: 30rpx;
  font-weight: 800;
}

.server-menu {
  gap: 8rpx;
}

.server-name {
  font-size: 24rpx;
  font-weight: 700;
}

.more-button {
  width: 54rpx;
  height: 54rpx;
  line-height: 50rpx;
  margin: 0;
  border-radius: 50%;
  background: #f2f4f7;
  color: #667085;
  font-size: 30rpx;
}

.menu-pop {
  position: absolute;
  top: 72rpx;
  right: 20rpx;
  z-index: 5;
  width: 210rpx;
  padding: 12rpx 0;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 18rpx 42rpx rgba(15, 23, 42, 0.16);
}

.menu-item {
  padding: 18rpx 24rpx;
  color: #1f2937;
  font-size: 24rpx;
}

.menu-item.danger {
  color: #d92d20;
}

.resource-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12rpx 16rpx;
}

.resource-item {
  color: #4b5563;
  font-size: 23rpx;
}

.resource-value {
  font-weight: 800;
  color: #111827;
}

.expire-row {
  margin-top: 14rpx;
  text-align: right;
  color: #667085;
  font-size: 23rpx;
}

.expire-value.expired {
  color: #f04438;
}

.card-actions {
  gap: 14rpx;
  margin-top: 22rpx;
  padding-top: 18rpx;
  border-top: 1px solid #edf2f7;
}

.status-dot {
  width: 18rpx;
  height: 18rpx;
  border-radius: 50%;
  background: #98a2b3;
}

.status-dot.online {
  background: #12b76a;
}

.status-dot.pending {
  background: #f79009;
}

.status-dot.error {
  background: #f04438;
}

.status-text {
  min-width: 56rpx;
  color: #667085;
  font-size: 22rpx;
}

.action {
  height: 56rpx;
  line-height: 56rpx;
  margin: 0;
  padding: 0 24rpx;
  border-radius: 8px;
  background: #b9ecff;
  color: #fff;
  font-size: 23rpx;
}

.action.primary {
  background: #29b6f6;
}

.action.stop {
  border: 1px solid #fecdca;
  background: #fff;
  color: #f04438;
}

.add-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border: 2px dashed #8ed8fb;
  background: rgba(255, 255, 255, 0.42);
  color: #667085;
  font-size: 26rpx;
}

.plus {
  font-size: 60rpx;
  line-height: 1;
}

.modal-mask {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.38);
}

.small-dialog,
.log-dialog {
  border-radius: 8px;
  background: #fff;
}

.small-dialog {
  width: min(560rpx, 92vw);
  padding: 28rpx;
}

.dialog-head,
.log-head {
  justify-content: space-between;
  margin-bottom: 22rpx;
}

.dialog-title,
.log-title {
  font-size: 30rpx;
  font-weight: 800;
}

.close {
  flex-shrink: 0;
  color: #98a2b3;
  font-size: 40rpx;
}

.dialog-input {
  height: 80rpx;
  padding: 0 18rpx;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
}

.dialog-actions {
  justify-content: flex-end;
  gap: 16rpx;
  margin-top: 24rpx;
}

.dialog-secondary,
.dialog-primary {
  width: 150rpx;
  height: 64rpx;
  line-height: 64rpx;
  margin: 0;
  border-radius: 8px;
  font-size: 24rpx;
}

.dialog-secondary {
  background: #f2f4f7;
  color: #475467;
}

.dialog-primary {
  background: #29b6f6;
  color: #fff;
}

.recharge-dialog {
  width: min(900rpx, 94vw);
  max-height: 90vh;
  padding: 32rpx;
  overflow-y: auto;
  border-radius: 8px;
  background: #fff;
  box-sizing: border-box;
}

.recharge-head,
.recharge-title-wrap,
.recharge-steps,
.recharge-step,
.recharge-pay-method,
.recharge-actions {
  display: flex;
  align-items: center;
}

.recharge-head {
  justify-content: space-between;
  margin-bottom: 22rpx;
}

.recharge-title-wrap {
  min-width: 0;
  gap: 10rpx;
}

.recharge-title-icon {
  flex-shrink: 0;
  color: #1f2937;
  font-size: 24rpx;
  line-height: 1;
}

.recharge-steps {
  flex-wrap: wrap;
  gap: 14rpx;
  margin-bottom: 30rpx;
  color: #8aa5c8;
  font-size: 23rpx;
}

.recharge-step {
  min-width: max-content;
  flex: 0 1 auto;
  gap: 8rpx;
  color: #7b9bc8;
}

.recharge-step.active {
  color: #1f2937;
  font-weight: 700;
}

.recharge-step.muted {
  color: #98a2b3;
}

.recharge-step-icon {
  flex-shrink: 0;
  font-size: 24rpx;
}

.recharge-step-line {
  height: 1px;
  min-width: 70rpx;
  flex: 1;
  background: #e5e7eb;
}

.recharge-section-title {
  display: block;
  margin-bottom: 14rpx;
  color: #1f2937;
  font-size: 28rpx;
  font-weight: 800;
}

.recharge-package-card {
  position: relative;
  width: 210rpx;
  min-height: 160rpx;
  padding: 22rpx 18rpx;
  border: 2px solid #1677ff;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  background: #fff;
}

.recharge-price {
  color: #1f2937;
  font-size: 30rpx;
  font-weight: 800;
  line-height: 1.2;
}

.recharge-quota {
  margin-top: 12rpx;
  color: #475467;
  font-size: 22rpx;
  font-weight: 700;
  text-align: center;
  overflow-wrap: anywhere;
}

.recharge-limit {
  margin-top: 12rpx;
  color: #18b84f;
  font-size: 22rpx;
  text-align: center;
  overflow-wrap: anywhere;
}

.recharge-selected {
  position: absolute;
  right: -12rpx;
  bottom: -12rpx;
  width: 34rpx;
  height: 34rpx;
  border-radius: 50%;
  background: #45c02d;
  color: #fff;
  font-size: 24rpx;
  font-weight: 800;
  line-height: 34rpx;
  text-align: center;
}

.recharge-pay-method {
  justify-content: space-between;
  margin-top: 28rpx;
  padding: 24rpx 0;
  border-bottom: 1px solid #edf2f7;
  color: #344054;
  font-size: 24rpx;
}

.recharge-pending {
  min-width: 160rpx;
  height: 56rpx;
  padding: 0 24rpx;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  color: #667085;
  line-height: 56rpx;
  text-align: center;
  box-sizing: border-box;
}

.recharge-actions {
  justify-content: flex-end;
  gap: 18rpx;
  margin-top: 22rpx;
}

.recharge-cancel,
.recharge-pay-button {
  height: 64rpx;
  line-height: 64rpx;
  margin: 0;
  border-radius: 8px;
  font-size: 24rpx;
}

.recharge-cancel {
  width: 150rpx;
  background: #fff;
  color: #1f2937;
  border: 1px solid #d0d5dd;
}

.recharge-pay-button {
  min-width: 260rpx;
  padding: 0 28rpx;
  background: #22c55e;
  color: #fff;
  box-shadow: 0 10rpx 22rpx rgba(34, 197, 94, 0.22);
}

.quota-dialog {
  width: min(700rpx, 94vw);
  max-height: 90vh;
  padding: 28rpx;
  overflow-y: auto;
  border-radius: 8px;
  background: #fff;
  box-sizing: border-box;
}

.quota-current,
.quota-package-main,
.quota-extra-head,
.quota-extra-input,
.quota-summary,
.quota-estimate {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.quota-current {
  margin-bottom: 12rpx;
  color: #475467;
  font-size: 24rpx;
}

.quota-balance,
.quota-total {
  color: #1677ff;
  font-weight: 800;
}

.quota-recharge {
  height: 52rpx;
  line-height: 52rpx;
  margin: 0;
  padding: 0 18rpx;
  border-radius: 8px;
  background: #eef7ff;
  color: #1677ff;
  font-size: 22rpx;
}

.quota-line {
  display: block;
  margin-top: 8rpx;
  color: #667085;
  font-size: 23rpx;
}

.quota-package {
  margin: 24rpx 0;
  padding: 22rpx;
  border: 1px solid #29b6f6;
  border-radius: 8px;
  background: #edfaff;
}

.quota-package-main {
  gap: 14rpx;
  justify-content: flex-start;
}

.quota-package-title {
  color: #1f2937;
  font-size: 26rpx;
  font-weight: 800;
}

.quota-tag {
  padding: 2rpx 8rpx;
  border-radius: 4px;
  background: #fff1f3;
  color: #e02020;
  font-size: 20rpx;
  font-weight: 800;
}

.quota-cost {
  display: block;
  margin-top: 8rpx;
  color: #e02020;
  font-size: 22rpx;
}

.quota-desc {
  display: block;
  margin-top: 10rpx;
  color: #667085;
  font-size: 22rpx;
}

.quota-extra-head {
  margin-top: 12rpx;
  color: #475467;
  font-size: 24rpx;
}

.quota-warning {
  display: block;
  margin: 16rpx 0;
  padding: 18rpx;
  border: 1px solid #fecdca;
  border-radius: 8px;
  background: #fff5f6;
  color: #e02020;
  font-size: 22rpx;
  font-weight: 700;
  line-height: 1.45;
}

.quota-slider {
  margin: 20rpx 0 12rpx;
}

.quota-extra-input {
  color: #98a2b3;
  font-size: 22rpx;
}

.quota-number {
  width: 136rpx;
  height: 62rpx;
  padding: 0 14rpx;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  color: #1f2937;
  text-align: center;
  box-sizing: border-box;
}

.quota-summary {
  margin-top: 18rpx;
  padding-bottom: 18rpx;
  border-bottom: 1px solid #edf2f7;
  color: #98a2b3;
  font-size: 22rpx;
}

.quota-estimate {
  margin-top: 18rpx;
  align-items: flex-start;
  flex-direction: column;
  gap: 8rpx;
  color: #667085;
  font-size: 23rpx;
}

.quota-estimate-time {
  color: #12b76a;
  font-weight: 800;
}

.log-dialog {
  width: min(1200px, 96vw);
  height: min(740px, 92vh);
  padding: 24rpx;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  overflow: hidden;
}

.log-body {
  display: flex;
  flex: 1;
  min-height: 0;
}

.log-main {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex: 1;
  flex-direction: column;
}

.log-tools {
  flex-shrink: 0;
  flex-wrap: wrap;
  gap: 14rpx;
}

.log-category-picker {
  width: 260rpx;
  flex-shrink: 0;
}

.log-category-select {
  height: 72rpx;
  padding: 0 18rpx;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-sizing: border-box;
  background: #fff;
  color: #1f2937;
  font-size: 24rpx;
}

.log-category-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.log-category-arrow {
  flex-shrink: 0;
  margin-left: 12rpx;
  color: #667085;
}

.log-search {
  flex: 1;
  min-width: 320rpx;
  height: 72rpx;
  padding: 0 18rpx;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
}

.log-mode,
.transport,
.clear-log {
  height: 64rpx;
  line-height: 64rpx;
  margin: 0;
  border-radius: 8px;
  background: #f2f8ff;
  color: #1677ff;
  font-size: 23rpx;
}

.log-options {
  flex-shrink: 0;
  flex-wrap: wrap;
  gap: 16rpx;
  margin: 14rpx 0;
  font-size: 23rpx;
}

.recent-text {
  margin-left: auto;
  color: #1677ff;
}

.log-list {
  flex: 1;
  min-height: 0;
  border-top: 1px solid #edf2f7;
  border-bottom: 1px solid #edf2f7;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  box-sizing: border-box;
}

.log-line {
  padding: 9rpx 0;
  border-bottom: 1px solid #f2f4f7;
  color: #344054;
  font-size: 22rpx;
  white-space: pre-wrap;
}

.event-card {
  margin: 12rpx 0;
  padding: 18rpx;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  background: #f8fbfd;
}

.event-top {
  justify-content: space-between;
}

.event-module {
  color: #1677ff;
  font-weight: 800;
}

.event-status {
  color: #12b76a;
}

.event-title,
.event-desc,
.empty-log,
.recharge-note {
  display: block;
  margin-top: 10rpx;
  color: #475467;
}

.log-footer {
  display: flex;
  flex-shrink: 0;
  justify-content: flex-end;
  margin-top: 16rpx;
}

.log-tail {
  height: 1px;
}

.announcement-mask {
  position: fixed;
  inset: 0;
  z-index: 30;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 96rpx;
  background: rgba(15, 23, 42, 0.28);
  box-sizing: border-box;
}

.announcement-dialog {
  width: min(760rpx, calc(100vw - 64rpx));
  padding: 28rpx 32rpx 34rpx;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 20rpx 48rpx rgba(15, 23, 42, 0.18);
  box-sizing: border-box;
}

.announcement-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}

.announcement-title {
  display: flex;
  align-items: center;
  gap: 10rpx;
  color: #111827;
  font-size: 30rpx;
  font-weight: 800;
}

.announcement-icon {
  font-size: 30rpx;
}

.announcement-close {
  flex-shrink: 0;
  width: 44rpx;
  height: 44rpx;
  color: #98a2b3;
  font-size: 40rpx;
  line-height: 40rpx;
  text-align: center;
}

.announcement-content {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.announcement-line {
  display: block;
  color: #1f2937;
  font-size: 26rpx;
  font-weight: 700;
  line-height: 1.5;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.announcement-red {
  color: #e02020;
}

.announcement-green {
  color: #008a4c;
}

.announcement-blue {
  color: #155bd5;
}

.announcement-default {
  color: #1f2937;
}

@media (max-width: 760px) {
  .page {
    padding: 22rpx;
  }

  .account-grid {
    grid-template-columns: 1fr;
  }

  .resource-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .log-dialog {
    width: calc(100vw - 32rpx);
    height: calc(100vh - 64rpx);
    padding: 24rpx;
  }

  .log-head {
    align-items: flex-start;
    gap: 16rpx;
  }

  .log-title {
    min-width: 0;
    flex: 1;
    line-height: 1.35;
  }

  .log-category-picker,
  .log-search,
  .log-mode {
    width: 100%;
    min-width: 0;
    flex: none;
  }

  .log-options {
    align-items: flex-start;
  }

  .recent-text {
    width: 100%;
    margin-left: 0;
    text-align: right;
  }

  .quota-dialog {
    width: calc(100vw - 32rpx);
    max-height: 88vh;
    padding: 24rpx;
  }

  .quota-current,
  .quota-summary {
    align-items: flex-start;
    flex-direction: column;
    gap: 12rpx;
  }

  .recharge-dialog {
    width: calc(100vw - 32rpx);
    max-height: 88vh;
    padding: 24rpx;
  }

  .recharge-head {
    align-items: flex-start;
    gap: 16rpx;
  }

  .recharge-steps {
    align-items: stretch;
    flex-wrap: nowrap;
    gap: 8rpx;
    font-size: 21rpx;
  }

  .recharge-step {
    min-width: 0;
    flex: 1;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .recharge-step-line {
    min-width: 18rpx;
    flex: 0 0 18rpx;
    margin-top: 18rpx;
  }

  .recharge-package-card {
    width: 220rpx;
    min-height: 172rpx;
  }

  .recharge-pay-method {
    align-items: flex-start;
    flex-direction: column;
    gap: 14rpx;
  }

  .recharge-pending {
    width: 100%;
  }

  .recharge-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .recharge-cancel,
  .recharge-pay-button {
    width: 100%;
  }
}
</style>
