<template>
  <view class="page">
    <view class="shell">
      <view class="auth-card">
        <view class="brand-row">
          <view class="brand-mark">GA</view>
          <text class="brand-name">Hoa Quán</text>
        </view>

        <view class="tabs">
          <text :class="['tab', mode === 'login' ? 'active' : '']" @click="switchMode('login')">{{ t('client.auth.login') }}</text>
          <text :class="['tab', mode === 'register' ? 'active' : '']" @click="switchMode('register')">{{ t('client.auth.register') }}</text>
        </view>

        <view class="field">
          <text class="label"><text v-if="mode === 'register'" class="required">*</text> {{ t('client.auth.account') }}</text>
          <input v-model="account" class="input" :placeholder="t('client.auth.placeholder_account')" @keydown="handleAuthKeydown" />
        </view>

        <view v-if="mode === 'register' && isEmailCodeMode" class="field">
          <text class="label"><text class="required">*</text> {{ t('client.auth.email') }}</text>
          <input v-model="email" class="input" :placeholder="t('client.auth.placeholder_email')" @keydown="handleAuthKeydown" />
        </view>

        <view v-if="mode === 'register' && isEmailCodeMode" class="field">
          <text class="label"><text class="required">*</text> {{ t('client.auth.email_code') }}</text>
          <view class="code-row">
            <input v-model="emailCode" class="input code-input" :placeholder="t('client.auth.placeholder_email_code')" @keydown="handleAuthKeydown" />
            <button class="code-button" :disabled="codeSending || cooldown > 0" :loading="codeSending" @click="sendEmailCode">
              {{ cooldown > 0 ? `${cooldown}s` : t('client.auth.send_code') }}
            </button>
          </view>
        </view>

        <view v-if="mode === 'register' && isSecurityQuestionMode" class="field">
          <text class="label"><text class="required">*</text> {{ t('client.auth.security_question') }}</text>
          <picker mode="selector" :range="securityQuestionLabels" @change="selectSecurityQuestion">
            <view class="input picker-value">{{ selectedSecurityQuestionLabel || t('client.auth.placeholder_security_question') }}</view>
          </picker>
        </view>

        <view v-if="mode === 'register' && isSecurityQuestionMode" class="field">
          <text class="label"><text class="required">*</text> {{ t('client.auth.security_answer') }}</text>
          <input v-model="securityAnswer" class="input" :placeholder="t('client.auth.placeholder_security_answer')" @keydown="handleAuthKeydown" />
        </view>

        <view class="field">
          <text class="label"><text v-if="mode === 'register'" class="required">*</text> {{ t('client.auth.password') }}</text>
          <view class="password-box">
            <input v-model="password" class="input password-input" password :placeholder="t('client.auth.placeholder_password')" @keydown="handleAuthKeydown" />
            <text class="eye">◎</text>
          </view>
        </view>

        <view v-if="mode === 'register'" class="field">
          <text class="label"><text class="required">*</text> {{ t('client.auth.confirm_password') }}</text>
          <view class="password-box">
            <input v-model="passwordConfirmation" class="input password-input" password :placeholder="t('client.auth.placeholder_password_again')" @keydown="handleAuthKeydown" />
            <text class="eye">◎</text>
          </view>
        </view>

        <view v-if="mode === 'login'" class="login-extra">
          <label class="remember-row">
            <checkbox :checked="remember" color="#27c7ff" @click="remember = !remember" />
            <text>{{ t('client.auth.remember') }}</text>
          </label>
          <text class="forgot-link" @click="openResetDialog">{{ t('client.auth.forgot_password') }}</text>
        </view>

        <button class="primary" :disabled="loading" :loading="loading" @click="submit">{{ mode === 'login' ? t('client.auth.login_button') : t('client.auth.register_button') }}</button>
        <text class="hint" @click="switchMode(mode === 'login' ? 'register' : 'login')">
          {{ mode === 'login' ? t('client.auth.no_account') : t('client.auth.already_has_account') }}
        </text>
        <view v-if="inviteCode" class="invite-tip">
          <text>{{ t('client.profile.invite_code_help') }}</text>
          <text class="invite-code">{{ inviteCode }}</text>
        </view>
        <view class="language-switch">
          <text :class="['language-option', currentLocale === 'zh_CN' ? 'active' : '']" @click="changeLocale('zh_CN')">{{ t('client.language.zh_CN') }}</text>
          <text class="language-divider">/</text>
          <text :class="['language-option', currentLocale === 'vi' ? 'active' : '']" @click="changeLocale('vi')">{{ t('client.language.vi') }}</text>
        </view>
      </view>

      <view class="showcase">
        <view class="showcase-title">
          <view class="flower">✦</view>
          <text>Hoa Quán</text>
        </view>
        <text class="showcase-subtitle">{{ t('client.auth.subtitle') }}</text>
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
          <text class="dialog-title">{{ t('client.auth.reset_account') }}</text>
          <text class="dialog-close" @click="closeResetDialog">×</text>
        </view>

        <view class="reset-field">
          <text class="reset-label">{{ t('client.auth.account') }}</text>
          <input v-model="resetForm.account" class="reset-input" :placeholder="t('client.auth.placeholder_account')" />
        </view>

        <view v-if="isEmailCodeMode" class="reset-field">
          <text class="reset-label">{{ t('client.auth.email') }}</text>
          <input v-model="resetForm.email" class="reset-input" :placeholder="t('client.auth.placeholder_register_email')" />
        </view>

        <view v-if="isEmailCodeMode" class="reset-field">
          <text class="reset-label">{{ t('client.auth.email_code') }}</text>
          <view class="reset-code-row">
            <input v-model="resetForm.emailCode" class="reset-input reset-code-input" :placeholder="t('client.auth.placeholder_email_code')" />
            <button
              class="reset-code-button"
              :disabled="resetCodeSending || resetCooldown > 0"
              :loading="resetCodeSending"
              @click="sendResetEmailCode"
            >
              {{ resetCooldown > 0 ? `${resetCooldown}s` : t('client.auth.send_code') }}
            </button>
          </view>
        </view>

        <view v-if="isSecurityQuestionMode" class="reset-field">
          <text class="reset-label">{{ t('client.auth.security_question') }}</text>
          <view class="reset-code-row">
            <view class="reset-input reset-code-input reset-question-display">
              {{ resetSecurityQuestion ? resetSecurityQuestion.label : t('client.auth.placeholder_lookup_security_question') }}
            </view>
            <button
              class="reset-code-button"
              :loading="resetQuestionLoading"
              @click="fetchResetSecurityQuestion"
            >
              {{ t('client.auth.get_security_question') }}
            </button>
          </view>
        </view>

        <view v-if="isSecurityQuestionMode" class="reset-field">
          <text class="reset-label">{{ t('client.auth.security_answer') }}</text>
          <input v-model="resetForm.securityAnswer" class="reset-input" :placeholder="t('client.auth.placeholder_security_answer')" />
        </view>

        <view class="reset-field">
          <text class="reset-label">{{ t('client.auth.new_password') }}</text>
          <view class="reset-password-box">
            <input v-model="resetForm.password" class="reset-input reset-password-input" password :placeholder="t('client.auth.placeholder_new_password')" />
            <text class="reset-eye">◎</text>
          </view>
        </view>

        <view class="reset-field">
          <text class="reset-label">{{ t('client.auth.confirm_new_password') }}</text>
          <view class="reset-password-box">
            <input v-model="resetForm.passwordConfirmation" class="reset-input reset-password-input" password :placeholder="t('client.auth.placeholder_password_again')" />
            <text class="reset-eye">◎</text>
          </view>
        </view>

        <view class="dialog-actions">
          <button class="dialog-secondary" @click="closeResetDialog">{{ t('client.recharge.cancel') }}</button>
          <button class="dialog-primary" :loading="resetLoading" @click="resetPassword">{{ t('client.auth.reset_password') }}</button>
        </view>
      </view>
    </view>

    <view v-if="registrationSuccessVisible" class="modal-mask registration-success-mask">
      <view class="registration-success-dialog">
        <view class="registration-success-head">
          <view class="registration-success-icon">✓</view>
          <text class="registration-success-title">{{ t('client.auth.register_success_title') }}</text>
        </view>

        <view class="registration-credential-table">
          <view class="registration-credential-row">
            <text class="registration-credential-label">{{ t('client.auth.account') }}</text>
            <text class="registration-credential-value">{{ registrationSuccess.account }}</text>
          </view>
          <view class="registration-credential-row">
            <text class="registration-credential-label">{{ t('client.auth.password') }}</text>
            <text class="registration-credential-value">{{ registrationSuccess.password }}</text>
          </view>
        </view>

        <text class="registration-success-notice">{{ t('client.auth.register_success_notice') }}</text>

        <view class="registration-success-actions">
          <button
            class="registration-success-button"
            :disabled="registrationConfirming"
            @click="confirmRegistrationSuccess"
          >
            {{ t('client.auth.register_success_acknowledge') }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { useI18n } from 'vue-i18n';
import { markLoginAnnouncementPending, request, setToken } from '../../utils/api';
import { getLocale, switchLocale } from '../../utils/i18n';

const { t } = useI18n();
const mode = ref('login');
const currentLocale = ref(getLocale());
const account = ref('');
const email = ref('');
const emailCode = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const securityQuestionKey = ref('');
const securityAnswer = ref('');
const inviteCode = ref('');
const remember = ref(true);
const loading = ref(false);
const authConfigLoaded = ref(false);
const verificationMode = ref('security_question');
const securityQuestions = ref([]);
const codeSending = ref(false);
const cooldown = ref(0);
const resetVisible = ref(false);
const resetLoading = ref(false);
const resetCodeSending = ref(false);
const resetQuestionLoading = ref(false);
const resetCooldown = ref(0);
const resetSecurityQuestion = ref(null);
const registrationSuccessVisible = ref(false);
const registrationConfirming = ref(false);
const registrationSuccess = ref({
  account: '',
  password: '',
  token: '',
});
const resetForm = ref({
  account: '',
  email: '',
  emailCode: '',
  securityAnswer: '',
  password: '',
  passwordConfirmation: '',
});
const stars = Array.from({ length: 18 }, (_, index) => index);
const isSecurityQuestionMode = computed(() => verificationMode.value === 'security_question');
const isEmailCodeMode = computed(() => verificationMode.value === 'email_code');
const securityQuestionLabels = computed(() => securityQuestions.value.map((item) => item.label));
const selectedSecurityQuestionLabel = computed(() => {
  const selected = securityQuestions.value.find((item) => item.key === securityQuestionKey.value);
  return selected ? selected.label : '';
});

let timer = null;
let resetTimer = null;

onLoad((options = {}) => {
  const code = String(options.invite || '').trim().toUpperCase();
  if (code) {
    inviteCode.value = code;
    mode.value = 'register';
  }
  loadAuthConfig();
});

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
    securityAnswer: '',
    password: '',
    passwordConfirmation: '',
  };
  resetSecurityQuestion.value = null;
}

function openResetDialog() {
  resetForm.value.account = account.value;
  resetSecurityQuestion.value = null;
  resetVisible.value = true;
}

function closeResetDialog() {
  resetVisible.value = false;
}

async function changeLocale(locale) {
  currentLocale.value = await switchLocale(locale);
  await loadAuthConfig();
}

async function loadAuthConfig() {
  authConfigLoaded.value = false;
  try {
    const data = await request({ url: '/api/auth/config' });
    if (!['security_question', 'email_code'].includes(data.verification_mode)) {
      throw new Error(t('client.error.auth_config_invalid'));
    }
    if (data.verification_mode === 'security_question' && (!Array.isArray(data.security_questions) || data.security_questions.length === 0)) {
      throw new Error(t('client.error.auth_config_invalid'));
    }
    verificationMode.value = data.verification_mode;
    securityQuestions.value = Array.isArray(data.security_questions) ? data.security_questions : [];
    if (!securityQuestions.value.some((item) => item.key === securityQuestionKey.value)) {
      securityQuestionKey.value = securityQuestions.value[0]?.key || '';
    }
    authConfigLoaded.value = true;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  }
}

function selectSecurityQuestion(event) {
  const index = Number(event.detail.value);
  securityQuestionKey.value = securityQuestions.value[index]?.key || '';
}

async function sendEmailCode() {
  if (!email.value) {
    uni.showToast({ title: t('client.error.require_email'), icon: 'none' });
    return;
  }

  codeSending.value = true;
  try {
    const data = await request({
      url: '/api/auth/email-code/send',
      method: 'POST',
      data: { email: email.value },
    });
    uni.showToast({ title: t('client.auth.captcha_sent'), icon: 'none' });
    startCooldown(data.cooldown_seconds || 60);
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    codeSending.value = false;
  }
}

async function sendResetEmailCode() {
  if (!resetForm.value.account || !resetForm.value.email) {
    uni.showToast({ title: t('client.error.require_account_email'), icon: 'none' });
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
    uni.showToast({ title: t('client.auth.captcha_sent'), icon: 'none' });
    startResetCooldown(data.cooldown_seconds || 60);
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    resetCodeSending.value = false;
  }
}

async function resetPassword() {
  if (!authConfigLoaded.value) {
    uni.showToast({ title: t('client.error.auth_config_unavailable'), icon: 'none' });
    return;
  }
  const missingEmailReset = isEmailCodeMode.value && (!resetForm.value.email || !resetForm.value.emailCode);
  const missingSecurityReset = isSecurityQuestionMode.value && (!resetSecurityQuestion.value || !resetForm.value.securityAnswer);
  if (!resetForm.value.account || missingEmailReset || missingSecurityReset || !resetForm.value.password || !resetForm.value.passwordConfirmation) {
    uni.showToast({ title: t('client.error.fill_reset'), icon: 'none' });
    return;
  }
  if (resetForm.value.password !== resetForm.value.passwordConfirmation) {
    uni.showToast({ title: t('client.error.password_mismatch'), icon: 'none' });
    return;
  }

  resetLoading.value = true;
  try {
    await request({
      url: '/api/auth/password/reset',
      method: 'POST',
      data: isEmailCodeMode.value
        ? {
            account: resetForm.value.account,
            email: resetForm.value.email,
            email_code: resetForm.value.emailCode,
            password: resetForm.value.password,
            password_confirmation: resetForm.value.passwordConfirmation,
          }
        : {
            account: resetForm.value.account,
            security_answer: resetForm.value.securityAnswer,
            password: resetForm.value.password,
            password_confirmation: resetForm.value.passwordConfirmation,
          },
    });
    uni.showToast({ title: t('client.auth.password_reset_success'), icon: 'none' });
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

async function fetchResetSecurityQuestion() {
  if (!resetForm.value.account) {
    uni.showToast({ title: t('client.error.require_username'), icon: 'none' });
    return;
  }

  resetQuestionLoading.value = true;
  try {
    const data = await request({
      url: '/api/auth/password/security-question',
      method: 'POST',
      data: { account: resetForm.value.account },
    });
    resetSecurityQuestion.value = data.security_question;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    resetQuestionLoading.value = false;
  }
}

function handleAuthKeydown(event) {
  const keyboardEvent = event?.detail?.originalEvent || event;
  if (keyboardEvent?.key !== 'Enter'
    || keyboardEvent?.repeat
    || keyboardEvent?.isComposing
    || keyboardEvent?.keyCode === 229) {
    return;
  }

  keyboardEvent.preventDefault?.();
  event?.preventDefault?.();
  if (loading.value || resetVisible.value || registrationSuccessVisible.value || registrationConfirming.value) {
    return;
  }

  submit();
}

async function submit() {
  if (loading.value || registrationSuccessVisible.value || registrationConfirming.value) {
    return;
  }

  const submittedMode = mode.value;
  if (submittedMode === 'register' && !authConfigLoaded.value) {
    uni.showToast({ title: t('client.error.auth_config_unavailable'), icon: 'none' });
    return;
  }
  if (!account.value || !password.value) {
    uni.showToast({ title: t('client.error.require_username_password'), icon: 'none' });
    return;
  }

  if (submittedMode === 'register') {
    const missingEmailRegister = isEmailCodeMode.value && (!email.value || !emailCode.value);
    const missingSecurityRegister = isSecurityQuestionMode.value && (!securityQuestionKey.value || !securityAnswer.value);
    if (missingEmailRegister || missingSecurityRegister || !passwordConfirmation.value) {
      uni.showToast({ title: t('client.error.fill_register'), icon: 'none' });
      return;
    }
    if (password.value !== passwordConfirmation.value) {
      uni.showToast({ title: t('client.error.password_mismatch'), icon: 'none' });
      return;
    }
  }

  loading.value = true;
  try {
    const payload = submittedMode === 'login'
      ? { account: account.value, password: password.value }
      : isEmailCodeMode.value
        ? {
            account: account.value,
            email: email.value,
            email_code: emailCode.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
            invite_code: inviteCode.value,
          }
        : {
            account: account.value,
            security_question_key: securityQuestionKey.value,
            security_answer: securityAnswer.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
            invite_code: inviteCode.value,
          };

    const data = await request({
      url: submittedMode === 'login' ? '/api/auth/login' : '/api/auth/register',
      method: 'POST',
      data: payload,
    });

    if (submittedMode === 'login') {
      setToken(data.token);
      markLoginAnnouncementPending();
      uni.reLaunch({ url: '/pages/index/index' });
      return;
    }

    const registeredAccount = typeof data?.user?.account === 'string' ? data.user.account.trim() : '';
    if (!data?.token || !registeredAccount || !payload.password) {
      throw new Error(t('client.error.register_response_invalid'));
    }

    registrationSuccess.value = {
      account: registeredAccount,
      password: payload.password,
      token: data.token,
    };
    password.value = '';
    passwordConfirmation.value = '';
    registrationSuccessVisible.value = true;
  } catch (error) {
    uni.showToast({ title: error.message, icon: 'none' });
  } finally {
    loading.value = false;
  }
}

function confirmRegistrationSuccess() {
  if (registrationConfirming.value) {
    return;
  }
  if (!registrationSuccess.value.token || !registrationSuccess.value.account || !registrationSuccess.value.password) {
    uni.showToast({ title: t('client.error.register_response_invalid'), icon: 'none' });
    return;
  }

  registrationConfirming.value = true;
  const token = registrationSuccess.value.token;
  setToken(token);
  markLoginAnnouncementPending();
  registrationSuccess.value = {
    account: '',
    password: '',
    token: '',
  };
  password.value = '';
  passwordConfirmation.value = '';
  registrationSuccessVisible.value = false;
  uni.reLaunch({ url: '/pages/index/index' });
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

.picker-value {
  line-height: 78rpx;
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

.language-switch {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12rpx;
  margin-top: 22rpx;
  color: rgba(236, 246, 248, 0.62);
  font-size: 24rpx;
}

.invite-tip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  margin-top: 20rpx;
  padding: 18rpx 20rpx;
  border: 1px solid rgba(39, 199, 255, 0.24);
  border-radius: 8px;
  background: rgba(39, 199, 255, 0.1);
  color: rgba(238, 249, 255, 0.8);
  font-size: 24rpx;
}

.invite-code {
  color: #27c7ff;
  font-weight: 800;
}

.language-option {
  color: rgba(236, 246, 248, 0.7);
}

.language-option.active {
  color: #5bd8ff;
  font-weight: 800;
}

.language-divider {
  color: rgba(236, 246, 248, 0.32);
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

.registration-success-dialog {
  width: 680rpx;
  max-width: 520px;
  max-height: calc(100vh - 80rpx);
  overflow-y: auto;
  padding: 38rpx 38rpx 34rpx;
  border-radius: 12px;
  background: #ffffff;
  color: #152033;
  box-sizing: border-box;
  box-shadow: 0 34rpx 90rpx rgba(0, 0, 0, 0.34);
}

.registration-success-head {
  display: flex;
  align-items: center;
  gap: 18rpx;
  margin-bottom: 30rpx;
}

.registration-success-icon {
  width: 52rpx;
  height: 52rpx;
  flex: 0 0 52rpx;
  line-height: 52rpx;
  text-align: center;
  border-radius: 999px;
  background: linear-gradient(135deg, #35c6ff, #16a9e8);
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 800;
  box-shadow: 0 10rpx 24rpx rgba(41, 194, 255, 0.28);
}

.registration-success-title {
  font-size: 34rpx;
  font-weight: 800;
}

.registration-credential-table {
  overflow: hidden;
  border: 1px solid #d8dee9;
  border-radius: 8px;
}

.registration-credential-row {
  display: grid;
  grid-template-columns: 190rpx minmax(0, 1fr);
  min-height: 82rpx;
}

.registration-credential-row + .registration-credential-row {
  border-top: 1px solid #d8dee9;
}

.registration-credential-label,
.registration-credential-value {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 18rpx 20rpx;
  box-sizing: border-box;
  font-size: 28rpx;
}

.registration-credential-label {
  border-right: 1px solid #d8dee9;
  background: #f7f9fc;
  color: #31405a;
  font-weight: 700;
}

.registration-credential-value {
  min-width: 0;
  overflow-wrap: anywhere;
  color: #152033;
  font-weight: 800;
}

.registration-success-notice {
  display: block;
  margin: 30rpx 0 28rpx;
  color: #ff5a78;
  font-size: 26rpx;
  font-weight: 700;
  line-height: 1.6;
  text-align: center;
}

.registration-success-actions {
  display: flex;
  justify-content: flex-end;
}

.registration-success-button {
  min-width: 180rpx;
  height: 70rpx;
  line-height: 70rpx;
  margin: 0;
  padding: 0 30rpx;
  border-radius: 8px;
  background: linear-gradient(135deg, #35c6ff, #16a9e8);
  color: #ffffff;
  font-size: 27rpx;
  font-weight: 800;
  box-shadow: 0 14rpx 32rpx rgba(41, 194, 255, 0.28);
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

.reset-question-display {
  line-height: 76rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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

  .registration-success-dialog {
    width: 100%;
    padding: 32rpx 26rpx 28rpx;
  }

  .registration-credential-row {
    grid-template-columns: 150rpx minmax(0, 1fr);
  }

  .registration-credential-label,
  .registration-credential-value {
    padding: 16rpx 14rpx;
    font-size: 25rpx;
  }

  .registration-success-button {
    width: 100%;
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
