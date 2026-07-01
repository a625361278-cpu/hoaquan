<template>
  <view class="page">
    <view class="shell">
      <view class="auth-card">
        <view class="brand-row">
          <view class="brand-mark">GA</view>
          <text class="brand-name">Hoa Quán</text>
        </view>

        <view class="tabs">
          <text :class="['tab', mode === 'login' ? 'active' : '']" @click="switchMode('login')">登录</text>
          <text :class="['tab', mode === 'register' ? 'active' : '']" @click="switchMode('register')">注册</text>
        </view>

        <view class="field">
          <text class="label"><text v-if="mode === 'register'" class="required">*</text> 用户名</text>
          <input v-model="account" class="input" placeholder="请输入用户名" />
        </view>

        <view v-if="mode === 'register'" class="field">
          <text class="label"><text class="required">*</text> 邮箱</text>
          <input v-model="email" class="input" placeholder="请输入邮箱" />
        </view>

        <view v-if="mode === 'register'" class="field">
          <text class="label"><text class="required">*</text> 邮箱验证码</text>
          <view class="code-row">
            <input v-model="emailCode" class="input code-input" placeholder="请输入6位验证码" />
            <button class="code-button" :disabled="codeSending || cooldown > 0" :loading="codeSending" @click="sendEmailCode">
              {{ cooldown > 0 ? `${cooldown}s` : '发送验证码' }}
            </button>
          </view>
        </view>

        <view class="field">
          <text class="label"><text v-if="mode === 'register'" class="required">*</text> 密码</text>
          <view class="password-box">
            <input v-model="password" class="input password-input" password placeholder="请输入密码（至少6位）" />
            <text class="eye">◎</text>
          </view>
        </view>

        <view v-if="mode === 'register'" class="field">
          <text class="label"><text class="required">*</text> 确认密码</text>
          <view class="password-box">
            <input v-model="passwordConfirmation" class="input password-input" password placeholder="请再次输入密码" />
            <text class="eye">◎</text>
          </view>
        </view>

        <view v-if="mode === 'login'" class="login-extra">
          <label class="remember-row">
            <checkbox :checked="remember" color="#27c7ff" @click="remember = !remember" />
            <text>记住登录状态</text>
          </label>
          <text class="forgot-link" @click="openResetDialog">忘记密码？</text>
        </view>

        <button class="primary" :loading="loading" @click="submit">{{ mode === 'login' ? '登 录' : '注 册' }}</button>
        <text class="hint" @click="switchMode(mode === 'login' ? 'register' : 'login')">
          {{ mode === 'login' ? '没有账号？立即注册' : '已有账号？立即登录' }}
        </text>
      </view>

      <view class="showcase">
        <view class="showcase-title">
          <view class="flower">✦</view>
          <text>Hoa Quán</text>
        </view>
        <text class="showcase-subtitle">账号托管 · 配置同步 · 执行日志</text>
        <view class="scene">
          <view class="moon"></view>
          <view class="stars">
            <text v-for="item in stars" :key="item" class="star">·</text>
          </view>
          <view class="garden-line">
            <text>✦</text>
            <text>✿</text>
            <text>◆</text>
            <text>✺</text>
            <text>✦</text>
          </view>
          <view class="ground"></view>
        </view>
      </view>
    </view>

    <view v-if="resetVisible" class="modal-mask">
      <view class="reset-dialog">
        <view class="dialog-head">
          <text class="dialog-title">找回账号</text>
          <text class="dialog-close" @click="closeResetDialog">×</text>
        </view>

        <view class="reset-field">
          <text class="reset-label">用户名</text>
          <input v-model="resetForm.account" class="reset-input" placeholder="请输入用户名" />
        </view>

        <view class="reset-field">
          <text class="reset-label">注册邮箱</text>
          <input v-model="resetForm.email" class="reset-input" placeholder="请输入注册时使用的邮箱" />
        </view>

        <view class="reset-field">
          <text class="reset-label">邮箱验证码</text>
          <view class="reset-code-row">
            <input v-model="resetForm.emailCode" class="reset-input reset-code-input" placeholder="请输入6位验证码" />
            <button
              class="reset-code-button"
              :disabled="resetCodeSending || resetCooldown > 0"
              :loading="resetCodeSending"
              @click="sendResetEmailCode"
            >
              {{ resetCooldown > 0 ? `${resetCooldown}s` : '发送验证码' }}
            </button>
          </view>
        </view>

        <view class="reset-field">
          <text class="reset-label">新密码</text>
          <view class="reset-password-box">
            <input v-model="resetForm.password" class="reset-input reset-password-input" password placeholder="请输入新密码（至少6位）" />
            <text class="reset-eye">◎</text>
          </view>
        </view>

        <view class="reset-field">
          <text class="reset-label">确认新密码</text>
          <view class="reset-password-box">
            <input v-model="resetForm.passwordConfirmation" class="reset-input reset-password-input" password placeholder="请再次输入新密码" />
            <text class="reset-eye">◎</text>
          </view>
        </view>

        <view class="dialog-actions">
          <button class="dialog-secondary" @click="closeResetDialog">取消</button>
          <button class="dialog-primary" :loading="resetLoading" @click="resetPassword">重置密码</button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { onUnmounted, ref } from 'vue';
import { request, setToken } from '../../utils/api';

const mode = ref('login');
const account = ref('');
const email = ref('');
const emailCode = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const remember = ref(true);
const loading = ref(false);
const codeSending = ref(false);
const cooldown = ref(0);
const resetVisible = ref(false);
const resetLoading = ref(false);
const resetCodeSending = ref(false);
const resetCooldown = ref(0);
const resetForm = ref({
  account: '',
  email: '',
  emailCode: '',
  password: '',
  passwordConfirmation: '',
});
const stars = Array.from({ length: 18 }, (_, index) => index);

let timer = null;
let resetTimer = null;

function switchMode(nextMode) {
  mode.value = nextMode;
}

function startCooldown(seconds) {
  cooldown.value = seconds;
  clearInterval(timer);
  timer = setInterval(() => {
    cooldown.value -= 1;
    if (cooldown.value <= 0) {
      clearInterval(timer);
      timer = null;
    }
  }, 1000);
}

function startResetCooldown(seconds) {
  resetCooldown.value = seconds;
  clearInterval(resetTimer);
  resetTimer = setInterval(() => {
    resetCooldown.value -= 1;
    if (resetCooldown.value <= 0) {
      clearInterval(resetTimer);
      resetTimer = null;
    }
  }, 1000);
}

function resetResetForm() {
  resetForm.value = {
    account: '',
    email: '',
    emailCode: '',
    password: '',
    passwordConfirmation: '',
  };
}

function openResetDialog() {
  resetForm.value.account = account.value;
  resetVisible.value = true;
}

function closeResetDialog() {
  resetVisible.value = false;
}

async function sendEmailCode() {
  if (!email.value) {
    uni.showToast({ title: '请输入邮箱', icon: 'none' });
    return;
  }

  codeSending.value = true;
  try {
    const data = await request({
      url: '/api/auth/email-code/send',
      method: 'POST',
      data: { email: email.value },
    });
    uni.showToast({ title: '验证码已发送', icon: 'none' });
    startCooldown(data.cooldown_seconds || 60);
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    codeSending.value = false;
  }
}

async function sendResetEmailCode() {
  if (!resetForm.value.account || !resetForm.value.email) {
    uni.showToast({ title: '请输入用户名和注册邮箱', icon: 'none' });
    return;
  }

  resetCodeSending.value = true;
  try {
    const data = await request({
      url: '/api/auth/password/email-code/send',
      method: 'POST',
      data: {
        account: resetForm.value.account,
        email: resetForm.value.email,
      },
    });
    uni.showToast({ title: '验证码已发送', icon: 'none' });
    startResetCooldown(data.cooldown_seconds || 60);
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    resetCodeSending.value = false;
  }
}

async function resetPassword() {
  if (!resetForm.value.account || !resetForm.value.email || !resetForm.value.emailCode || !resetForm.value.password || !resetForm.value.passwordConfirmation) {
    uni.showToast({ title: '请完整填写找回信息', icon: 'none' });
    return;
  }
  if (resetForm.value.password !== resetForm.value.passwordConfirmation) {
    uni.showToast({ title: '两次输入的密码不一致', icon: 'none' });
    return;
  }

  resetLoading.value = true;
  try {
    await request({
      url: '/api/auth/password/reset',
      method: 'POST',
      data: {
        account: resetForm.value.account,
        email: resetForm.value.email,
        email_code: resetForm.value.emailCode,
        password: resetForm.value.password,
        password_confirmation: resetForm.value.passwordConfirmation,
      },
    });
    uni.showToast({ title: '密码重置成功，请重新登录', icon: 'none' });
    resetVisible.value = false;
    resetResetForm();
    password.value = '';
    switchMode('login');
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    resetLoading.value = false;
  }
}

async function submit() {
  if (!account.value || !password.value) {
    uni.showToast({ title: '请输入用户名和密码', icon: 'none' });
    return;
  }

  if (mode.value === 'register') {
    if (!email.value || !emailCode.value || !passwordConfirmation.value) {
      uni.showToast({ title: '请完整填写注册信息', icon: 'none' });
      return;
    }
    if (password.value !== passwordConfirmation.value) {
      uni.showToast({ title: '两次输入的密码不一致', icon: 'none' });
      return;
    }
  }

  loading.value = true;
  try {
    const payload = mode.value === 'login'
      ? { account: account.value, password: password.value }
      : {
          account: account.value,
          email: email.value,
          email_code: emailCode.value,
          password: password.value,
          password_confirmation: passwordConfirmation.value,
        };

    const data = await request({
      url: mode.value === 'login' ? '/api/auth/login' : '/api/auth/register',
      method: 'POST',
      data: payload,
    });
    setToken(data.token);
    uni.reLaunch({ url: '/pages/index/index' });
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    loading.value = false;
  }
}

onUnmounted(() => {
  if (timer) {
    clearInterval(timer);
  }
  if (resetTimer) {
    clearInterval(resetTimer);
  }
});
</script>

<style scoped>
.page {
  min-height: 100vh;
  padding: 44rpx 28rpx;
  box-sizing: border-box;
  background:
    radial-gradient(circle at 76% 36%, rgba(41, 194, 255, 0.18), transparent 28%),
    linear-gradient(135deg, #07162c 0%, #10284d 48%, #143d5c 100%);
  color: #f7fbff;
}

.shell {
  width: 100%;
  max-width: 1040px;
  min-height: calc(100vh - 88rpx);
  margin: 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 88px;
}

.auth-card {
  width: 620rpx;
  max-width: 420px;
  padding: 48rpx;
  border: 1px solid rgba(214, 252, 255, 0.14);
  border-radius: 8px;
  background: rgba(29, 48, 85, 0.82);
  box-shadow: 0 36rpx 90rpx rgba(0, 0, 0, 0.24);
  box-sizing: border-box;
  backdrop-filter: blur(12px);
}

.brand-row,
.code-row,
.remember-row,
.login-extra,
.showcase-title,
.garden-line,
.dialog-head,
.reset-code-row,
.dialog-actions {
  display: flex;
  align-items: center;
}

.brand-row {
  gap: 20rpx;
  margin-bottom: 42rpx;
}

.brand-mark {
  width: 58rpx;
  height: 58rpx;
  line-height: 58rpx;
  text-align: center;
  border-radius: 8px;
  background: linear-gradient(135deg, #27b8ff, #16d6d0);
  color: #fff;
  font-size: 24rpx;
  font-weight: 800;
}

.brand-name {
  font-size: 42rpx;
  font-weight: 800;
}

.tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  padding: 8rpx;
  margin-bottom: 32rpx;
  border: 1px solid rgba(224, 244, 247, 0.18);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.08);
}

.tab {
  height: 72rpx;
  line-height: 72rpx;
  text-align: center;
  border-radius: 8px;
  color: rgba(236, 246, 248, 0.65);
  font-size: 28rpx;
  font-weight: 700;
}

.tab.active {
  color: #fff;
  background: linear-gradient(135deg, #35c6ff, #16a9e8);
  box-shadow: 0 12rpx 32rpx rgba(41, 194, 255, 0.28);
}

.field {
  margin-bottom: 24rpx;
}

.label {
  display: block;
  margin-bottom: 12rpx;
  color: rgba(236, 246, 248, 0.82);
  font-size: 24rpx;
}

.required {
  color: #ff5a78;
}

.input {
  width: 100%;
  height: 78rpx;
  padding: 0 22rpx;
  border: 1px solid rgba(232, 248, 250, 0.3);
  border-radius: 8px;
  background: rgba(228, 244, 248, 0.16);
  color: #fff;
  font-size: 28rpx;
  box-sizing: border-box;
}

.password-box {
  position: relative;
}

.password-input {
  padding-right: 70rpx;
}

.eye {
  position: absolute;
  right: 24rpx;
  top: 19rpx;
  color: rgba(236, 246, 248, 0.55);
  font-size: 30rpx;
}

.code-row {
  gap: 16rpx;
}

.code-input {
  flex: 1;
}

.code-button {
  width: 180rpx;
  height: 78rpx;
  line-height: 78rpx;
  margin: 0;
  border: 1px solid rgba(54, 200, 255, 0.65);
  border-radius: 8px;
  background: rgba(20, 74, 102, 0.68);
  color: #40cfff;
  font-size: 24rpx;
}

.login-extra {
  justify-content: space-between;
  margin: 8rpx 0 28rpx;
}

.remember-row {
  gap: 10rpx;
  color: rgba(236, 246, 248, 0.72);
  font-size: 24rpx;
}

.forgot-link {
  color: rgba(91, 216, 255, 0.86);
  font-size: 24rpx;
}

.primary {
  height: 86rpx;
  line-height: 86rpx;
  margin: 14rpx 0 0;
  border-radius: 8px;
  background: linear-gradient(135deg, #35c6ff, #16a9e8);
  color: #fff;
  font-size: 30rpx;
  font-weight: 800;
  box-shadow: 0 16rpx 36rpx rgba(41, 194, 255, 0.28);
}

.hint {
  display: block;
  margin-top: 28rpx;
  text-align: center;
  color: rgba(236, 246, 248, 0.62);
  font-size: 24rpx;
}

.showcase {
  width: 600rpx;
  max-width: 500px;
  display: none;
}

.showcase-title {
  gap: 18rpx;
  justify-content: center;
  font-size: 44rpx;
  font-weight: 800;
}

.flower {
  width: 54rpx;
  height: 54rpx;
  line-height: 54rpx;
  text-align: center;
  border-radius: 8px;
  background: rgba(41, 194, 255, 0.18);
  color: #5bd8ff;
}

.showcase-subtitle {
  display: block;
  margin: 12rpx 0 44rpx;
  text-align: center;
  color: rgba(236, 246, 248, 0.56);
  font-size: 24rpx;
}

.scene {
  position: relative;
  height: 340rpx;
  overflow: hidden;
  border: 1px solid rgba(91, 216, 255, 0.16);
  border-radius: 8px;
  background: linear-gradient(180deg, #061427 0%, #081c31 70%, #0b4430 71%, #0f3b28 100%);
  box-shadow: 0 34rpx 88rpx rgba(0, 0, 0, 0.28);
}

.moon {
  position: absolute;
  right: 58rpx;
  top: 42rpx;
  width: 78rpx;
  height: 78rpx;
  border-radius: 999px;
  background: #ffe071;
  box-shadow: 0 0 42rpx rgba(255, 224, 113, 0.6);
}

.stars {
  position: absolute;
  inset: 28rpx 38rpx auto 38rpx;
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  color: rgba(255, 255, 255, 0.72);
  font-size: 34rpx;
}

.garden-line {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 82rpx;
  justify-content: space-around;
  color: #5bd8ff;
  font-size: 44rpx;
}

.ground {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 72rpx;
  height: 4rpx;
  background: #38cfff;
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

.reset-dialog {
  width: 680rpx;
  max-width: 520px;
  max-height: calc(100vh - 80rpx);
  overflow-y: auto;
  padding: 34rpx;
  border-radius: 8px;
  background: #ffffff;
  color: #152033;
  box-sizing: border-box;
  box-shadow: 0 34rpx 90rpx rgba(0, 0, 0, 0.34);
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

.reset-field {
  margin-bottom: 22rpx;
}

.reset-label {
  display: block;
  margin-bottom: 10rpx;
  color: #31405a;
  font-size: 24rpx;
}

.reset-input {
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

.reset-code-row {
  gap: 14rpx;
}

.reset-code-input {
  flex: 1;
}

.reset-code-button {
  width: 190rpx;
  height: 76rpx;
  line-height: 76rpx;
  margin: 0;
  border: 1px solid #23aeea;
  border-radius: 8px;
  background: #eef9ff;
  color: #139ad8;
  font-size: 24rpx;
}

.reset-password-box {
  position: relative;
}

.reset-password-input {
  padding-right: 70rpx;
}

.reset-eye {
  position: absolute;
  right: 24rpx;
  top: 18rpx;
  color: #8b95a7;
  font-size: 30rpx;
}

.dialog-actions {
  justify-content: flex-end;
  gap: 16rpx;
  padding-top: 8rpx;
}

.dialog-secondary,
.dialog-primary {
  width: 160rpx;
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

@media (min-width: 900px) {
  .page {
    padding: 0 40px;
  }

  .shell {
    min-height: 100vh;
    align-items: center;
  }

  .auth-card {
    width: 420px;
    padding: 40px;
  }

  .showcase {
    display: block;
  }
}

@media (min-width: 1280px) {
  .page {
    padding: 0 48px;
  }

  .shell {
    max-width: 1060px;
    gap: 96px;
  }

  .showcase {
    width: 500px;
  }
}

@media (max-width: 360px) {
  .auth-card {
    width: 100%;
    padding: 36rpx 28rpx;
  }

  .code-button {
    width: 160rpx;
    font-size: 22rpx;
  }

  .login-extra {
    align-items: flex-start;
    gap: 16rpx;
    flex-direction: column;
  }

  .reset-dialog {
    width: 100%;
    padding: 30rpx 26rpx;
  }

  .reset-code-row {
    align-items: stretch;
  }

  .reset-code-button {
    width: 168rpx;
    font-size: 22rpx;
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
