<template>
  <view class="page">
    <view class="card">
      <view class="avatar">{{ avatarText }}</view>
      <text class="name">{{ user.nickname || user.account }}</text>
      <text class="line">账号：{{ user.account }}</text>
      <text class="line">邮箱：{{ user.email || '未绑定' }}</text>
      <text class="line">余额：{{ user.balance || '0.00' }}</text>
      <text class="line">到期时间：{{ user.expire_at || '未开通' }}</text>
    </view>
    <button class="logout" @click="logout">退出登录</button>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { clearToken, request, requireLogin } from '../../utils/api';

const user = ref({});
const avatarText = computed(() => (user.value.nickname || user.value.account || 'GA').slice(0, 2).toUpperCase());

onShow(async () => {
  if (!requireLogin()) {
    return;
  }
  try {
    const data = await request({ url: '/api/me' });
    user.value = data.user;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
});

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
  padding: 34rpx;
  box-sizing: border-box;
  background:
    radial-gradient(circle at 70% 18%, rgba(36, 197, 106, 0.18), transparent 28%),
    linear-gradient(160deg, #0d2027 0%, #14353d 54%, #234e60 100%);
  color: #f7fbff;
}

.card {
  padding: 46rpx 36rpx;
  border: 1px solid rgba(224, 244, 247, 0.14);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.09);
  box-shadow: 0 20rpx 52rpx rgba(0, 0, 0, 0.16);
}

.avatar {
  width: 112rpx;
  height: 112rpx;
  line-height: 112rpx;
  margin-bottom: 28rpx;
  border-radius: 8px;
  background: linear-gradient(135deg, #24c56a, #36c8ff);
  color: #fff;
  text-align: center;
  font-weight: 900;
}

.name,
.line {
  display: block;
}

.name {
  margin-bottom: 24rpx;
  font-size: 40rpx;
  font-weight: 800;
}

.line {
  margin-top: 18rpx;
  color: rgba(236, 246, 248, 0.72);
  font-size: 28rpx;
}

.logout {
  height: 88rpx;
  line-height: 88rpx;
  margin-top: 32rpx;
  border-radius: 8px;
  background: #ff4d5f;
  color: #fff;
  font-size: 30rpx;
  font-weight: 800;
}
</style>
