<template>
  <view class="page">
    <view class="panel">
      <view class="icon">+</view>
      <text class="title">游戏接入未开放</text>
      <text class="desc">当前还没有确定具体游戏和第三方接口，因此不能创建游戏账号或配置虚假的角色信息。</text>
      <button class="primary" @click="tryAdd">确认添加</button>
      <button class="ghost" @click="back">返回</button>
    </view>
  </view>
</template>

<script setup>
import { request, requireLogin } from '../../utils/api';

requireLogin();

async function tryAdd() {
  try {
    await request({ url: '/api/game-accounts', method: 'POST' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function back() {
  uni.navigateBack();
}
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding: 34rpx;
  box-sizing: border-box;
  background:
    radial-gradient(circle at 70% 18%, rgba(54, 200, 255, 0.18), transparent 28%),
    linear-gradient(160deg, #0d2027 0%, #14353d 54%, #234e60 100%);
  color: #f7fbff;
}

.panel {
  padding: 56rpx 36rpx;
  border: 1px solid rgba(224, 244, 247, 0.14);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.09);
  box-shadow: 0 20rpx 52rpx rgba(0, 0, 0, 0.16);
  text-align: center;
}

.icon,
.title,
.desc {
  display: block;
}

.icon {
  width: 112rpx;
  height: 112rpx;
  line-height: 106rpx;
  margin: 0 auto 28rpx;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.24);
  color: #fff;
  font-size: 78rpx;
}

.title {
  font-size: 38rpx;
  font-weight: 800;
}

.desc {
  margin-top: 20rpx;
  color: rgba(236, 246, 248, 0.68);
  font-size: 27rpx;
  line-height: 1.7;
  text-align: left;
}

.primary,
.ghost {
  height: 88rpx;
  line-height: 88rpx;
  margin-top: 36rpx;
  border-radius: 8px;
  font-size: 30rpx;
  font-weight: 800;
}

.primary {
  background: linear-gradient(135deg, #24c56a, #18a84e);
  color: #fff;
}

.ghost {
  margin-top: 20rpx;
  border: 1px solid rgba(224, 244, 247, 0.2);
  background: rgba(255, 255, 255, 0.08);
  color: rgba(236, 246, 248, 0.8);
}
</style>
