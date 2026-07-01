<template>
  <view class="page">
    <view class="header">
      <view>
        <text class="eyebrow">Hoa Quán</text>
        <text class="hello">你好，{{ user.nickname || user.account }}</text>
        <text class="meta">账号：{{ user.account }}</text>
      </view>
      <view class="header-actions">
        <button class="point-button" @click="goPointRecharge">点数充值</button>
        <button class="profile" @click="goProfile">我的</button>
      </view>
    </view>

    <view class="summary">
      <view class="summary-item">
        <text class="summary-label">余额</text>
        <text class="summary-value">{{ user.balance || '0.00' }}</text>
      </view>
      <view class="summary-item">
        <text class="summary-label">到期时间</text>
        <text class="summary-value">{{ user.expire_at || '未开通' }}</text>
      </view>
    </view>

    <view v-if="gameAccounts.length === 0" class="empty-card" @click="goAddGame">
      <view class="plus">+</view>
      <text class="empty-title">添加游戏账号</text>
      <text class="empty-desc">当前未绑定游戏账号，具体游戏接入后可在这里添加角色。</text>
    </view>

    <view v-else class="account-list">
      <view v-for="item in gameAccounts" :key="item.id" class="account-card">
        <text class="account-title">{{ item.display_name }}</text>
        <text class="account-meta">{{ item.status }}</text>
      </view>
    </view>

    <view v-if="rechargeVisible" class="modal-mask">
      <view class="recharge-panel">
        <view class="panel-head">
          <view class="title-row">
            <text class="title-icon">▤</text>
            <text class="title">点数充值</text>
          </view>
          <text class="close" @click="closeRecharge">×</text>
        </view>

        <view class="steps">
          <view class="step active">
            <text class="step-icon">▣</text>
            <text>选择套餐</text>
          </view>
          <view class="step-line"></view>
          <view class="step">
            <text class="step-icon">●</text>
            <text>扫码支付</text>
          </view>
          <view class="step-line"></view>
          <view class="step">
            <text class="step-icon">✓</text>
            <text>支付完成</text>
          </view>
        </view>

        <view class="recharge-content">
          <text class="section-title">推荐套餐</text>
          <view class="package-card">
            <text class="price">¥30.00</text>
            <text class="quota">30 点配额</text>
            <text class="limit">无限制</text>
            <text class="selected">✓</text>
          </view>
        </view>

        <view class="pay-row">
          <text class="pay-label">支付方式</text>
          <view class="pay-methods">
            <view class="pay-method active">
              <text class="wechat-dot">●</text>
              <text>微信支付</text>
            </view>
            <view class="pay-method">
              <text class="alipay-mark">支</text>
              <text>支付宝</text>
            </view>
          </view>
        </view>

        <view class="footer">
          <button class="cancel" @click="closeRecharge">取消</button>
          <button class="pay-button" @click="openPaymentWindow">立即支付 ¥30.00</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { request, requireLogin } from '../../utils/api';

const user = ref({});
const gameAccounts = ref([]);
const rechargeVisible = ref(false);

onShow(() => {
  if (requireLogin()) {
    loadHome();
  }
});

async function loadHome() {
  try {
    const me = await request({ url: '/api/me' });
    user.value = me.user;
    const accounts = await request({ url: '/api/game-accounts' });
    gameAccounts.value = accounts.items || [];
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
    if (error.message.includes('登录')) {
      uni.redirectTo({ url: '/pages/login/index' });
    }
  }
}

function goAddGame() {
  uni.navigateTo({ url: '/pages/game/add' });
}

function goProfile() {
  uni.navigateTo({ url: '/pages/profile/index' });
}

function goPointRecharge() {
  rechargeVisible.value = true;
}

function closeRecharge() {
  rechargeVisible.value = false;
}

function openPaymentWindow() {
  // 临时页面仅用于支付接口申请演示，不创建订单、不改变点数、不写入支付状态。
  // #ifdef H5
  window.open(`${window.location.origin}/static/temp-payment-apply.html#/`, '_blank', 'noopener,noreferrer');
  // #endif
  // #ifndef H5
  uni.showToast({ title: '请在H5浏览器中打开支付演示页', icon: 'none' });
  // #endif
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding: 34rpx;
  box-sizing: border-box;
  background:
    radial-gradient(circle at 78% 20%, rgba(54, 200, 255, 0.18), transparent 30%),
    linear-gradient(160deg, #0d2027 0%, #14353d 54%, #234e60 100%);
  color: #f7fbff;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 30rpx;
}

.eyebrow,
.hello,
.meta,
.summary-label,
.summary-value,
.empty-title,
.empty-desc,
.account-title,
.account-meta {
  display: block;
}

.eyebrow {
  margin-bottom: 10rpx;
  color: #58e887;
  font-size: 22rpx;
  font-weight: 700;
}

.hello {
  font-size: 40rpx;
  font-weight: 800;
}

.meta {
  margin-top: 10rpx;
  color: rgba(236, 246, 248, 0.62);
  font-size: 24rpx;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 16rpx;
}

.profile,
.point-button {
  width: 116rpx;
  height: 64rpx;
  line-height: 64rpx;
  margin: 0;
  border-radius: 8px;
  font-size: 26rpx;
}

.profile {
  border: 1px solid rgba(88, 232, 135, 0.42);
  background: rgba(36, 197, 106, 0.16);
  color: #bdf7d0;
}

.point-button {
  width: 160rpx;
  border: 1px solid rgba(54, 200, 255, 0.42);
  background: rgba(54, 200, 255, 0.14);
  color: #bdefff;
}

.summary {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20rpx;
  margin-bottom: 28rpx;
}

.summary-item,
.account-card {
  padding: 28rpx;
  border: 1px solid rgba(224, 244, 247, 0.12);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.09);
  box-shadow: 0 16rpx 40rpx rgba(0, 0, 0, 0.14);
}

.summary-label {
  color: rgba(236, 246, 248, 0.58);
  font-size: 24rpx;
}

.summary-value {
  margin-top: 14rpx;
  font-size: 32rpx;
  font-weight: 800;
}

.empty-card {
  min-height: 440rpx;
  border: 2rpx dashed rgba(224, 244, 247, 0.26);
  border-radius: 8px;
  background:
    linear-gradient(135deg, rgba(36, 197, 106, 0.18), rgba(54, 200, 255, 0.14)),
    rgba(255, 255, 255, 0.06);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}

.plus {
  width: 108rpx;
  height: 108rpx;
  line-height: 104rpx;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.24);
  color: #fff;
  font-size: 78rpx;
}

.empty-title {
  margin-top: 26rpx;
  font-size: 34rpx;
  font-weight: 800;
}

.empty-desc {
  width: 520rpx;
  max-width: 82%;
  margin-top: 14rpx;
  color: rgba(236, 246, 248, 0.62);
  font-size: 24rpx;
  line-height: 1.6;
}

.account-card {
  margin-bottom: 20rpx;
}

.account-title {
  font-size: 32rpx;
  font-weight: 800;
}

.account-meta {
  margin-top: 8rpx;
  color: rgba(236, 246, 248, 0.62);
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

.recharge-panel {
  width: min(600px, calc(100vw - 40px));
  overflow: hidden;
  border-radius: 8px;
  background: #fff;
  color: #1f2937;
  box-shadow: 0 28rpx 80rpx rgba(0, 0, 0, 0.28);
}

.panel-head,
.title-row,
.steps,
.step,
.pay-row,
.pay-methods,
.pay-method,
.footer {
  display: flex;
  align-items: center;
}

.panel-head {
  justify-content: space-between;
  padding: 20px 22px 6px;
}

.title-row {
  gap: 12rpx;
}

.title-icon {
  color: #111827;
  font-size: 16px;
}

.title {
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.close {
  width: 24px;
  height: 24px;
  line-height: 20px;
  text-align: center;
  color: #8a8f99;
  font-size: 28px;
}

.steps {
  padding: 0 22px 28px;
  color: #999;
  font-size: 14px;
}

.step {
  gap: 8rpx;
  white-space: nowrap;
}

.step.active {
  color: #1687ff;
}

.step-icon {
  font-size: 22px;
}

.step-line {
  flex: 1;
  height: 1px;
  margin: 0 18px;
  background: #e5e7eb;
}

.recharge-content {
  padding: 0 22px;
}

.section-title {
  display: block;
  margin-bottom: 14px;
  color: #111827;
  font-size: 16px;
  font-weight: 800;
}

.package-card {
  position: relative;
  width: 140px;
  height: 104px;
  padding: 18px 12px;
  border: 1px solid #178bff;
  border-radius: 8px;
  box-sizing: border-box;
  text-align: center;
  box-shadow: 0 0 0 2px rgba(23, 139, 255, 0.12);
}

.price,
.quota,
.limit {
  display: block;
}

.price {
  color: #1f2937;
  font-size: 18px;
  font-weight: 800;
}

.quota {
  margin-top: 8px;
  color: #4b5563;
  font-size: 13px;
  font-weight: 700;
}

.limit {
  margin-top: 10px;
  color: #22c55e;
  font-size: 13px;
}

.selected {
  position: absolute;
  right: -10px;
  bottom: -10px;
  width: 20px;
  height: 20px;
  line-height: 20px;
  border-radius: 50%;
  background: #43bf33;
  color: #fff;
  font-size: 14px;
}

.pay-row {
  justify-content: space-between;
  padding: 26px 22px 20px;
}

.pay-label {
  color: #374151;
  font-size: 14px;
}

.pay-methods {
  border: 1px solid #d7dce3;
  border-radius: 6px;
  overflow: hidden;
}

.pay-method {
  height: 30px;
  padding: 0 16px;
  border-left: 1px solid #d7dce3;
  color: #374151;
  font-size: 14px;
  gap: 6px;
}

.pay-method:first-child {
  border-left: 0;
}

.pay-method.active {
  border: 1px solid #177dff;
  color: #1687ff;
  margin: -1px 0 -1px -1px;
}

.wechat-dot {
  color: #19c35b;
  font-size: 18px;
}

.alipay-mark {
  color: #ff7b1a;
  font-size: 16px;
  font-weight: 800;
}

.footer {
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 22px 18px;
  border-top: 1px solid #edf0f4;
}

.cancel,
.pay-button {
  height: 42px;
  line-height: 42px;
  margin: 0;
  border-radius: 8px;
  font-size: 15px;
}

.cancel {
  width: 100px;
  border: 1px solid #d7dce3;
  background: #fff;
  color: #374151;
}

.pay-button {
  width: 148px;
  background: linear-gradient(135deg, #49c8ff, #20aeea);
  color: #fff;
  box-shadow: 0 8px 18px rgba(32, 174, 234, 0.3);
}

@media (max-width: 420px) {
  .header {
    align-items: flex-start;
    gap: 20rpx;
  }

  .header-actions {
    flex-direction: column;
    align-items: flex-end;
  }

  .modal-mask {
    padding: 24rpx;
  }

  .recharge-panel {
    width: 100%;
  }

  .panel-head,
  .steps,
  .recharge-content,
  .pay-row,
  .footer {
    padding-left: 18px;
    padding-right: 18px;
  }

  .steps {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
  }

  .step-line {
    display: none;
  }

  .pay-row {
    align-items: flex-start;
    flex-direction: column;
    gap: 12px;
  }

  .footer {
    justify-content: stretch;
  }

  .cancel,
  .pay-button {
    flex: 1;
    width: auto;
  }
}
</style>
