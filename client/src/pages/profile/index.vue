<template>
  <view class="page">
    <view class="topbar">
      <text class="back" @click="goBack">‹</text>
      <text class="title">{{ t('client.profile.title') }}</text>
      <view class="avatar-small">{{ avatarText }}</view>
    </view>

    <view class="content">
      <view class="panel profile-panel">
        <view class="user-row">
          <view class="avatar">{{ avatarText }}</view>
          <view class="user-main">
            <text class="name">{{ user.nickname || user.account }}</text>
            <view class="invite-code-row">
              <text class="invite-code">{{ invite.code || '-' }}</text>
              <button class="icon-button" @click="showInviteHelp">?</button>
              <button class="icon-button" @click="copyInviteLink">□</button>
            </view>
          </view>
        </view>

        <view class="stats">
          <view class="stat">
            <text class="stat-value">{{ invite.invited_count || 0 }}</text>
            <text class="stat-label">{{ t('client.profile.invite_count') }}</text>
          </view>
          <view class="stat">
            <text class="stat-value">{{ user.balance || '0.00' }}</text>
            <text class="stat-label">{{ t('client.profile.my_points') }}</text>
          </view>
        </view>

        <view class="invite-link-block">
          <text class="section-label">{{ t('client.profile.invite_link') }}</text>
          <view class="copy-row">
            <input class="copy-input" :value="invite.link" disabled />
            <button class="copy-button" @click="copyInviteLink">{{ t('client.profile.copy') }}</button>
          </view>
        </view>

        <view class="role-box">
          <text v-if="roleBinding.role_id" class="role-bound">{{ t('client.profile.role_bound', { role: roleBinding.role_id }) }}</text>
          <text v-else class="role-auto">{{ t('client.profile.role_auto_bind') }}</text>
        </view>
        <text class="role-hint">{{ t('client.profile.role_auto_hint') }}</text>
      </view>

      <view class="panel action-panel">
        <button class="action-card" @click="openPasswordReset">
          {{ t('client.profile.modify_password') }}
        </button>
      </view>

      <view class="panel list-panel">
        <view class="panel-head">
          <text class="panel-title">{{ t('client.profile.transaction_history') }}</text>
          <button class="refresh-button" @click="loadProfile">{{ t('client.profile.refresh') }}</button>
        </view>
        <view v-if="transactions.length === 0" class="empty">{{ t('client.profile.no_transactions') }}</view>
        <view v-for="item in transactions" :key="item.id" class="transaction">
          <view class="transaction-main">
            <text class="transaction-type">{{ transactionType(item.type) }}</text>
            <text class="transaction-desc">{{ item.description }}</text>
            <text class="transaction-time">{{ item.created_at }}</text>
          </view>
          <view class="transaction-side">
            <text :class="['transaction-amount', Number(item.amount) >= 0 ? 'plus' : 'minus']">{{ formatAmount(item.amount) }}</text>
            <text class="transaction-balance">{{ t('client.profile.point_balance', { balance: item.balance_after }) }}</text>
          </view>
        </view>
      </view>

      <button class="logout" @click="logout">{{ t('client.profile.logout') }}</button>
    </view>

    <view v-if="passwordDialogVisible" class="modal-mask">
      <view class="password-dialog">
        <view class="dialog-head">
          <text class="dialog-title">{{ t('client.profile.change_password_title') }}</text>
          <text class="dialog-close" @click="closePasswordDialog">×</text>
        </view>

        <view class="dialog-field">
          <text class="dialog-label">{{ t('client.profile.current_password') }}</text>
          <input v-model="passwordForm.currentPassword" class="dialog-input" password :placeholder="t('client.profile.placeholder_current_password')" />
        </view>

        <view class="dialog-field">
          <text class="dialog-label">{{ t('client.profile.new_password') }}</text>
          <input v-model="passwordForm.password" class="dialog-input" password :placeholder="t('client.profile.placeholder_new_password')" />
        </view>

        <view class="dialog-field">
          <text class="dialog-label">{{ t('client.profile.confirm_new_password') }}</text>
          <input v-model="passwordForm.passwordConfirmation" class="dialog-input" password :placeholder="t('client.profile.placeholder_confirm_new_password')" />
        </view>

        <view class="dialog-actions">
          <button class="dialog-secondary" @click="closePasswordDialog">{{ t('client.recharge.cancel') }}</button>
          <button class="dialog-primary" :loading="passwordChanging" @click="changePassword">{{ t('client.profile.confirm_change_password') }}</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { useI18n } from 'vue-i18n';
import { clearToken, request, requireLogin } from '../../utils/api';

const { t } = useI18n();
const user = ref({});
const invite = ref({});
const roleBinding = ref({});
const transactions = ref([]);
const passwordDialogVisible = ref(false);
const passwordChanging = ref(false);
const passwordForm = ref({
  currentPassword: '',
  password: '',
  passwordConfirmation: '',
});
const avatarText = computed(() => (user.value.nickname || user.value.account || 'GA').slice(0, 2).toUpperCase());

onShow(async () => {
  if (!requireLogin()) {
    return;
  }
  await loadProfile();
});

async function loadProfile() {
  try {
    const data = await request({ url: '/api/profile' });
    user.value = data.user || {};
    invite.value = data.invite || {};
    roleBinding.value = data.role_binding || {};
    transactions.value = data.transactions || [];
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function goBack() {
  uni.navigateBack({
    fail() {
      uni.reLaunch({ url: '/pages/index/index' });
    },
  });
}

function showInviteHelp() {
  uni.showModal({
    title: t('client.profile.invite_count'),
    content: t('client.profile.invite_code_help', { level: invite.value.min_role_level }),
    showCancel: false,
  });
}

function copyInviteLink() {
  if (!invite.value.link) {
    return;
  }
  uni.setClipboardData({
    data: invite.value.link,
    success() {
      uni.showToast({ title: t('client.profile.copy_success'), icon: 'none' });
    },
  });
}

function openPasswordReset() {
  resetPasswordForm();
  passwordDialogVisible.value = true;
}

function closePasswordDialog() {
  if (passwordChanging.value) {
    return;
  }
  passwordDialogVisible.value = false;
  resetPasswordForm();
}

function resetPasswordForm() {
  passwordForm.value = {
    currentPassword: '',
    password: '',
    passwordConfirmation: '',
  };
}

async function changePassword() {
  if (!passwordForm.value.currentPassword || !passwordForm.value.password || !passwordForm.value.passwordConfirmation) {
    uni.showToast({ title: t('client.profile.fill_change_password'), icon: 'none' });
    return;
  }
  if (passwordForm.value.password !== passwordForm.value.passwordConfirmation) {
    uni.showToast({ title: t('client.error.password_mismatch'), icon: 'none' });
    return;
  }

  passwordChanging.value = true;
  try {
    await request({
      url: '/api/auth/password/change',
      method: 'POST',
      data: {
        current_password: passwordForm.value.currentPassword,
        password: passwordForm.value.password,
        password_confirmation: passwordForm.value.passwordConfirmation,
      },
    });
    uni.showToast({ title: t('client.profile.password_change_success'), icon: 'none' });
    passwordDialogVisible.value = false;
    resetPasswordForm();
    clearToken();
    uni.reLaunch({ url: '/pages/login/index' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    passwordChanging.value = false;
  }
}

function transactionType(type) {
  if (type === 'invite_reward') {
    return t('client.profile.invite_count');
  }
  if (type === 'recharge') {
    return t('client.profile.recharge');
  }
  return type;
}

function formatAmount(amount) {
  const value = Number(amount || 0);
  return `${value >= 0 ? '+ ' : '- '}${Math.abs(value).toString().replace(/\.00$/, '')}`;
}

async function logout() {
  try {
    await request({ url: '/api/auth/logout', method: 'POST' });
  } finally {
    clearToken();
    uni.reLaunch({ url: '/pages/login/index' });
  }
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  background: linear-gradient(135deg, #6077e8 0%, #7a48ad 100%);
  color: #273849;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 56px;
  border-top: 3px solid #21ace4;
  background: #bfe9ff;
  color: #318fcb;
  font-weight: 800;
}

.back {
  position: absolute;
  left: 22rpx;
  top: 0;
  width: 60rpx;
  height: 56px;
  line-height: 54px;
  color: #263644;
  font-size: 52rpx;
  font-weight: 300;
}

.title {
  font-size: 34rpx;
}

.avatar-small {
  position: absolute;
  right: 18rpx;
  width: 48rpx;
  height: 48rpx;
  line-height: 48rpx;
  border-radius: 50%;
  background: #28bdf1;
  color: #fff;
  text-align: center;
  font-size: 18rpx;
  font-weight: 900;
}

.content {
  width: min(1160px, calc(100% - 48rpx));
  margin: 0 auto;
  padding: 48rpx 0 56rpx;
}

.panel {
  margin-bottom: 26rpx;
  padding: 28rpx;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.94);
  box-shadow: 0 14rpx 34rpx rgba(32, 26, 91, 0.14);
  box-sizing: border-box;
}

.profile-panel {
  padding: 34rpx 28rpx;
}

.user-row {
  display: flex;
  align-items: center;
  gap: 22rpx;
}

.avatar {
  width: 92rpx;
  height: 92rpx;
  line-height: 92rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #2bd0ff, #4e6ff3);
  color: #fff;
  text-align: center;
  font-weight: 900;
}

.user-main {
  min-width: 0;
}

.name {
  display: block;
  margin-bottom: 12rpx;
  font-size: 34rpx;
  font-weight: 800;
}

.invite-code-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
  color: #697789;
}

.invite-code {
  font-size: 26rpx;
}

.icon-button {
  width: 34rpx;
  height: 34rpx;
  line-height: 30rpx;
  padding: 0;
  border: 1px solid #9ba8b6;
  border-radius: 50%;
  background: transparent;
  color: #667582;
  font-size: 22rpx;
}

.stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  margin: 34rpx 0 26rpx;
  border-radius: 6px;
  background: #eaf0ff;
}

.stat {
  padding: 28rpx 12rpx;
  text-align: center;
}

.stat-value,
.stat-label {
  display: block;
}

.stat-value {
  font-size: 34rpx;
  font-weight: 900;
}

.stat-label {
  margin-top: 6rpx;
  color: #667382;
  font-size: 24rpx;
}

.section-label {
  display: block;
  margin-bottom: 10rpx;
  font-size: 26rpx;
}

.copy-row {
  display: flex;
  align-items: stretch;
}

.copy-input {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  padding: 0 20rpx;
  border: 1px solid #d6dde6;
  border-radius: 6px 0 0 6px;
  background: #fff;
  color: #263849;
  font-size: 26rpx;
  box-sizing: border-box;
}

.copy-button,
.refresh-button {
  height: 64rpx;
  line-height: 64rpx;
  padding: 0 28rpx;
  border-radius: 0 6px 6px 0;
  background: #2dbcf0;
  color: #fff;
  font-size: 26rpx;
  font-weight: 800;
}

.role-box {
  display: flex;
  align-items: center;
  margin-top: 22rpx;
}

.role-bound,
.role-auto {
  padding: 16rpx 20rpx;
  border-radius: 6px;
  font-size: 26rpx;
  font-weight: 700;
}

.role-bound {
  background: #ecfff5;
  color: #13834e;
}

.role-auto {
  background: #eef6ff;
  color: #3276b9;
}

.role-hint {
  display: block;
  margin-top: 12rpx;
  color: #7b8794;
  font-size: 23rpx;
}

.action-panel {
  display: flex;
  justify-content: center;
}

.action-card {
  width: 220rpx;
  height: 64rpx;
  line-height: 64rpx;
  padding: 0;
  border-radius: 6px;
  background: #2dbcf0;
  color: #fff;
  font-size: 26rpx;
  font-weight: 800;
  box-shadow: 0 8rpx 16rpx rgba(45, 188, 240, 0.22);
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18rpx;
}

.panel-title {
  display: block;
  margin-bottom: 22rpx;
  font-size: 32rpx;
  font-weight: 500;
}

.panel-head .panel-title {
  margin-bottom: 0;
}

.refresh-button {
  width: auto;
  height: 56rpx;
  line-height: 56rpx;
  border-radius: 6px;
  box-shadow: 0 8rpx 16rpx rgba(45, 188, 240, 0.22);
}

.list-panel {
  min-height: 176rpx;
}

.empty {
  padding: 48rpx 0;
  color: #7b8794;
  text-align: center;
  font-size: 26rpx;
}

.transaction {
  display: flex;
  justify-content: space-between;
  gap: 20rpx;
  padding: 24rpx 0;
  border-bottom: 1px solid #e6eaf0;
}

.transaction:last-child {
  border-bottom: 0;
}

.transaction-main {
  min-width: 0;
}

.transaction-type,
.transaction-desc,
.transaction-time,
.transaction-amount,
.transaction-balance {
  display: block;
}

.transaction-type {
  margin-bottom: 8rpx;
  font-weight: 800;
}

.transaction-desc,
.transaction-time {
  color: #5d6975;
  font-size: 24rpx;
}

.transaction-side {
  min-width: 120rpx;
  text-align: right;
}

.transaction-amount {
  font-size: 30rpx;
  font-weight: 900;
}

.transaction-amount.plus {
  color: #26b34a;
}

.transaction-amount.minus {
  color: #e8485a;
}

.transaction-balance {
  margin-top: 12rpx;
  color: #5d6975;
  font-size: 22rpx;
}

.logout {
  height: 82rpx;
  line-height: 82rpx;
  margin-top: 20rpx;
  border-radius: 8px;
  background: #ff4d5f;
  color: #fff;
  font-size: 28rpx;
  font-weight: 800;
}

.modal-mask {
  position: fixed;
  inset: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40rpx;
  box-sizing: border-box;
  background: rgba(2, 8, 23, 0.68);
}

.password-dialog {
  width: 680rpx;
  max-width: 520px;
  padding: 34rpx;
  border-radius: 8px;
  background: #ffffff;
  color: #152033;
  box-sizing: border-box;
  box-shadow: 0 34rpx 90rpx rgba(0, 0, 0, 0.34);
}

.dialog-head,
.dialog-actions {
  display: flex;
  align-items: center;
}

.dialog-head {
  justify-content: space-between;
  margin-bottom: 28rpx;
}

.dialog-title {
  font-size: 32rpx;
  font-weight: 800;
}

.dialog-close {
  width: 48rpx;
  height: 48rpx;
  line-height: 44rpx;
  text-align: center;
  color: #7b8495;
  font-size: 42rpx;
}

.dialog-field {
  margin-bottom: 22rpx;
}

.dialog-label {
  display: block;
  margin-bottom: 10rpx;
  color: #31405a;
  font-size: 24rpx;
}

.dialog-input {
  width: 100%;
  height: 76rpx;
  padding: 0 22rpx;
  border: 1px solid #d8dee9;
  border-radius: 8px;
  background: #fff;
  color: #152033;
  font-size: 28rpx;
  box-sizing: border-box;
}

.dialog-actions {
  justify-content: flex-end;
  gap: 16rpx;
  padding-top: 8rpx;
}

.dialog-secondary,
.dialog-primary {
  width: 180rpx;
  height: 66rpx;
  line-height: 66rpx;
  margin: 0;
  border-radius: 8px;
  font-size: 26rpx;
}

.dialog-secondary {
  border: 1px solid #d8dee9;
  background: #fff;
  color: #31405a;
}

.dialog-primary {
  background: linear-gradient(135deg, #35c6ff, #16a9e8);
  color: #fff;
}

@media (max-width: 640px) {
  .content {
    width: calc(100% - 28rpx);
    padding-top: 22rpx;
  }

  .panel {
    padding: 24rpx;
  }

  .stats {
    grid-template-columns: 1fr;
  }

  .action-panel {
    justify-content: stretch;
  }

  .action-card {
    width: 100%;
  }

  .password-dialog {
    width: 100%;
    padding: 30rpx 26rpx;
  }

  .dialog-actions {
    justify-content: stretch;
  }

  .dialog-secondary,
  .dialog-primary {
    flex: 1;
  }
}
</style>
