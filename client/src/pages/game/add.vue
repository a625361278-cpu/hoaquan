<template>
  <view class="page">
    <view class="topbar">
      <button class="icon-button" @click="back">‹</button>
      <text class="page-title">{{ t('client.add.title') }}</text>
    </view>

    <view class="steps">
      <view v-for="item in stepItems" :key="item.value" :class="['step', currentStep >= item.value ? 'active' : '']">
        <text class="step-index">{{ currentStep > item.value ? '✓' : item.value }}</text>
        <text class="step-label">{{ t(item.labelKey) }}</text>
      </view>
    </view>

    <view v-if="currentStep === 1" class="panel">
      <text class="section-title">{{ t('client.add.choose_login_method') }}</text>
      <view class="channel-grid">
        <view
          v-for="method in loginMethods"
          :key="method.value"
          :class="['channel-card', form.login_method === method.value ? 'active' : '']"
          @click="form.login_method = method.value"
        >
          <text class="channel-icon">{{ method.icon }}</text>
          <text class="channel-name">{{ t(method.titleKey) }}</text>
          <text class="channel-desc">{{ t(method.descKey) }}</text>
        </view>
      </view>
    </view>

    <view v-if="currentStep === 2" class="panel">
      <text class="section-title">{{ t('client.add.login_game') }}</text>
      <view v-if="form.login_method === 1" class="field">
        <text class="label">{{ t('client.add.game_username') }}</text>
        <input v-model="form.game_username" class="input" :placeholder="t('client.add.placeholder_game_username')" />
      </view>
      <view v-if="form.login_method === 1" class="field">
        <text class="label">{{ t('client.add.game_password') }}</text>
        <input v-model="form.game_password" class="input" password :placeholder="t('client.add.placeholder_game_password')" />
      </view>
      <view v-if="form.login_method !== 1" class="field">
        <text class="label">{{ t('client.add.game_uid') }}</text>
        <input v-model="form.game_uid" class="input" :placeholder="t('client.add.placeholder_game_uid')" />
      </view>
      <view v-if="form.login_method !== 1" class="field">
        <text class="label">{{ t('client.add.token') }}</text>
        <input v-model="form.token" class="input" password maxlength="-1" :placeholder="t('client.add.placeholder_token')" />
      </view>
      <text class="hint">{{ t(form.login_method === 1 ? 'client.add.preview_login_hint' : 'client.add.social_login_hint') }}</text>
    </view>

    <view class="actions">
      <button v-if="currentStep > 1" class="ghost" @click="previousStep">{{ t('client.add.previous') }}</button>
      <button class="primary" :disabled="submitting" @click="nextStep">
        {{ currentStep < 2 ? t('client.add.next') : (submitting ? t('client.add.verifying') : t('client.add.confirm')) }}
      </button>
    </view>
  </view>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { useI18n } from 'vue-i18n';
import { request, requireLogin } from '../../utils/api';
import { LOGIN_METHOD_OPTIONS, PREVIEW_CHANNEL } from '../../utils/gameConfigSchema';

const { t } = useI18n();
const PENDING_VALIDATION_KEY = 'gameassist_pending_account_validation';
const currentStep = ref(1);
const submitting = ref(false);
const supportedLoginMethods = ref([]);
const loginMethods = computed(() => LOGIN_METHOD_OPTIONS.filter(item => supportedLoginMethods.value.includes(item.value)));
const form = reactive({
  channel_code: PREVIEW_CHANNEL.code,
  login_method: 1,
  game_username: '',
  game_password: '',
  game_uid: '',
  token: '',
});

const stepItems = [
  { value: 1, labelKey: 'client.add.step_channel' },
  { value: 2, labelKey: 'client.add.step_login' },
];

requireLogin();
onShow(async () => {
  await loadLoginMethods();
  await resumePendingValidation();
});

async function loadLoginMethods() {
  try {
    const result = await request({ url: '/api/game-accounts' });
    const methods = result.supported_login_methods;
    if (!Array.isArray(methods) || !methods.every(value => [1, 2, 3].includes(Number(value)))) {
      throw new Error(t('client.add.login_methods_invalid'));
    }
    supportedLoginMethods.value = methods.map(Number);
    if (!supportedLoginMethods.value.includes(form.login_method)) {
      form.login_method = supportedLoginMethods.value[0];
    }
  } catch (error) {
    supportedLoginMethods.value = [];
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function previousStep() {
  currentStep.value = Math.max(1, currentStep.value - 1);
}

async function nextStep() {
  if (currentStep.value < 2) {
    if (!supportedLoginMethods.value.includes(form.login_method)) {
      uni.showToast({ title: t('client.add.login_methods_invalid'), icon: 'none' });
      return;
    }
    currentStep.value += 1;
    return;
  }

  const credentialsValid = form.login_method === 1
    ? Boolean(form.game_username.trim() && form.game_password.trim())
    : Boolean(form.game_uid.trim() && form.token.trim());
  if (!credentialsValid) {
    uni.showToast({ title: t(form.login_method === 1 ? 'client.add.require_game_credentials' : 'client.add.require_social_credentials'), icon: 'none' });
    return;
  }

  submitting.value = true;
  try {
    const result = await request({
      url: '/api/game-accounts',
      method: 'POST',
      data: form.login_method === 1 ? {
        channel_code: form.channel_code,
        login_method: form.login_method,
        game_username: form.game_username,
        game_password: form.game_password,
      } : {
        channel_code: form.channel_code,
        login_method: form.login_method,
        game_uid: form.game_uid,
        token: form.token,
      },
    });
    if (!result.validation_id) {
      throw new Error(t('client.add.login_validation_error'));
    }
    uni.setStorageSync(PENDING_VALIDATION_KEY, result.validation_id);
    await pollValidation(result.validation_id);
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    submitting.value = false;
  }
}

async function resumePendingValidation() {
  const validationId = uni.getStorageSync(PENDING_VALIDATION_KEY);
  if (!validationId || submitting.value) return;
  currentStep.value = 2;
  submitting.value = true;
  try {
    await pollValidation(validationId);
  } catch (error) {
    uni.removeStorageSync(PENDING_VALIDATION_KEY);
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    submitting.value = false;
  }
}

async function pollValidation(validationId) {
  const deadline = Date.now() + 25000;
  let lastNetworkError = null;
  while (Date.now() <= deadline) {
    let result;
    try {
      result = await request({ url: `/api/game-account-validations/${validationId}` });
      lastNetworkError = null;
    } catch (error) {
      lastNetworkError = error;
      await delay(1000);
      continue;
    }
    if (result.status === 'verifying') {
      await delay(1000);
      continue;
    }
    uni.removeStorageSync(PENDING_VALIDATION_KEY);
    if (result.status === 'success') {
      uni.showToast({ title: t('client.add.login_validation_success'), icon: 'none' });
      const accountId = result.account && result.account.id;
      uni.redirectTo({ url: accountId ? `/pages/game/config?id=${accountId}` : '/pages/index/index' });
      return;
    }
    if (result.status === 'rejected') {
      throw new Error(result.message || t('client.add.login_validation_rejected'));
    }
    if (result.status === 'timeout') {
      throw new Error(t('client.add.login_validation_timeout'));
    }
    throw new Error(t('client.add.login_validation_error'));
  }
  throw lastNetworkError || new Error(t('client.add.login_validation_timeout'));
}

function delay(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function back() {
  uni.navigateBack();
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding: 28rpx;
  box-sizing: border-box;
  background: linear-gradient(180deg, #bceeff 0%, #d8f5ff 100%);
  color: #1f2937;
}

.topbar,
.steps,
.step,
.actions {
  display: flex;
  align-items: center;
}

.topbar {
  gap: 18rpx;
  margin-bottom: 28rpx;
}

.icon-button {
  width: 72rpx;
  height: 72rpx;
  line-height: 66rpx;
  margin: 0;
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.86);
  color: #263545;
  font-size: 44rpx;
}

.page-title {
  font-size: 36rpx;
  font-weight: 800;
}

.steps {
  justify-content: space-between;
  gap: 12rpx;
  margin-bottom: 26rpx;
}

.step {
  flex: 1;
  min-width: 0;
  gap: 10rpx;
  padding: 16rpx 12rpx;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.68);
  color: #7b8794;
  font-size: 24rpx;
}

.step.active {
  background: #ffffff;
  color: #1677ff;
  box-shadow: 0 12rpx 28rpx rgba(74, 183, 230, 0.18);
}

.step-index {
  width: 34rpx;
  height: 34rpx;
  line-height: 34rpx;
  border-radius: 50%;
  background: #eaf6ff;
  text-align: center;
  font-size: 20rpx;
  font-weight: 800;
}

.step-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.panel {
  padding: 32rpx;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 18rpx 48rpx rgba(61, 153, 197, 0.18);
}

.section-title,
.channel-name,
.channel-desc,
.hint,
.label {
  display: block;
}

.section-title {
  margin-bottom: 24rpx;
  font-size: 32rpx;
  font-weight: 800;
}

.channel-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 18rpx;
}

.channel-card {
  min-height: 148rpx;
  padding: 22rpx;
  border: 1px solid #d8e6ef;
  border-radius: 8px;
  background: #f8fbfd;
  text-align: center;
}

.channel-card.active {
  border-color: #42b8f0;
  background: #e9f8ff;
  box-shadow: 0 10rpx 28rpx rgba(33, 169, 232, 0.18);
}

.channel-icon {
  display: inline-block;
  min-width: 58rpx;
  height: 42rpx;
  line-height: 42rpx;
  margin-bottom: 12rpx;
  border-radius: 8px;
  background: #29b6f6;
  color: #fff;
  font-size: 20rpx;
  font-weight: 800;
}

.channel-name {
  font-size: 28rpx;
  font-weight: 800;
}

.channel-desc,
.hint {
  margin-top: 10rpx;
  color: #667085;
  font-size: 24rpx;
  line-height: 1.6;
}

.field {
  margin-bottom: 24rpx;
}

.label {
  margin-bottom: 12rpx;
  color: #475467;
  font-size: 26rpx;
}

.input {
  height: 88rpx;
  padding: 0 22rpx;
  border: 1px solid #d6e2eb;
  border-radius: 8px;
  background: #fff;
  color: #111827;
  font-size: 28rpx;
}

.actions {
  justify-content: flex-end;
  gap: 18rpx;
  margin-top: 28rpx;
}

.primary,
.ghost {
  width: 220rpx;
  height: 84rpx;
  line-height: 84rpx;
  margin: 0;
  border-radius: 8px;
  font-size: 28rpx;
  font-weight: 800;
}

.primary {
  background: #29b6f6;
  color: #fff;
}

.ghost {
  border: 1px solid #d6e2eb;
  background: rgba(255, 255, 255, 0.8);
  color: #475467;
}
</style>
