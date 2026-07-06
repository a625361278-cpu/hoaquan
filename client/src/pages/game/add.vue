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
      <text class="section-title">{{ t('client.add.choose_channel') }}</text>
      <view class="channel-grid">
        <view
          v-for="channel in channels"
          :key="channel.code"
          :class="['channel-card', form.channel_code === channel.code ? 'active' : '']"
          @click="form.channel_code = channel.code"
        >
          <text class="channel-icon">{{ channel.icon }}</text>
          <text class="channel-name">{{ t(channel.titleKey) }}</text>
          <text class="channel-desc">{{ t(channel.descKey) }}</text>
        </view>
      </view>
      <text class="hint">{{ t('client.add.channel_placeholder') }}</text>
    </view>

    <view v-if="currentStep === 2" class="panel">
      <text class="section-title">{{ t('client.add.login_game') }}</text>
      <view class="field">
        <text class="label">{{ t('client.add.game_username') }}</text>
        <input v-model="form.game_username" class="input" :placeholder="t('client.add.placeholder_game_username')" />
      </view>
      <view class="field">
        <text class="label">{{ t('client.add.game_password') }}</text>
        <input v-model="form.game_password" class="input" password :placeholder="t('client.add.placeholder_game_password')" />
      </view>
      <text class="hint">{{ t('client.add.preview_login_hint') }}</text>
    </view>

    <view class="actions">
      <button v-if="currentStep > 1" class="ghost" @click="previousStep">{{ t('client.add.previous') }}</button>
      <button class="primary" :disabled="submitting" @click="nextStep">
        {{ currentStep < 2 ? t('client.add.next') : t('client.add.confirm') }}
      </button>
    </view>
  </view>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { request, requireLogin } from '../../utils/api';
import { PREVIEW_CHANNEL } from '../../utils/gameConfigSchema';

const { t } = useI18n();
const currentStep = ref(1);
const submitting = ref(false);
const channels = [
  { ...PREVIEW_CHANNEL, icon: 'APP' },
];
const form = reactive({
  channel_code: PREVIEW_CHANNEL.code,
  game_username: '',
  game_password: '',
});

const stepItems = [
  { value: 1, labelKey: 'client.add.step_channel' },
  { value: 2, labelKey: 'client.add.step_login' },
];

requireLogin();

function previousStep() {
  currentStep.value = Math.max(1, currentStep.value - 1);
}

async function nextStep() {
  if (currentStep.value < 2) {
    currentStep.value += 1;
    return;
  }

  if (!form.game_username || !form.game_password) {
    uni.showToast({ title: t('client.add.require_game_credentials'), icon: 'none' });
    return;
  }

  submitting.value = true;
  try {
    const result = await request({
      url: '/api/game-accounts',
      method: 'POST',
      data: {
        channel_code: form.channel_code,
        game_username: form.game_username,
        game_password: form.game_password,
      },
    });
    uni.showToast({ title: t('client.add.success'), icon: 'none' });
    const accountId = result.account && result.account.id;
    uni.redirectTo({ url: accountId ? `/pages/game/config?id=${accountId}` : '/pages/index/index' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    submitting.value = false;
  }
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
