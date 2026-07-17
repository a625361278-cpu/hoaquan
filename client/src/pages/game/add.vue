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
      <view v-if="form.login_method !== 1" class="tutorial-grid">
        <button class="tutorial-button" @click="openTutorial('android')">{{ t('client.add.tutorial_android') }}</button>
        <button class="tutorial-button" @click="openTutorial('ios')">{{ t('client.add.tutorial_ios') }}</button>
      </view>
    </view>

    <view class="actions">
      <button v-if="currentStep > 1" class="ghost" @click="previousStep">{{ t('client.add.previous') }}</button>
      <button class="primary" :disabled="submitting" @click="nextStep">
        {{ currentStep < 2 ? t('client.add.next') : (submitting ? t('client.add.verifying') : t('client.add.confirm')) }}
      </button>
    </view>

    <view v-if="tutorialDialog.visible" class="modal-mask" @click="closeTutorial">
      <view class="tutorial-dialog" @click.stop>
        <view class="tutorial-head">
          <text class="tutorial-title">{{ activeTutorial.title }}</text>
          <text class="tutorial-close" @click="closeTutorial">×</text>
        </view>
        <scroll-view class="tutorial-body" scroll-y>
          <text class="tutorial-doc-title">{{ activeTutorial.docTitle }}</text>
          <view class="tutorial-step" v-for="(step, index) in activeTutorial.steps" :key="step.text">
            <text class="tutorial-step-index">{{ index + 1 }}</text>
            <text class="tutorial-step-text">{{ step.text }}</text>
            <image
              v-for="image in step.images"
              :key="image"
              class="tutorial-image"
              :src="image"
              mode="widthFix"
              @click="previewTutorialImage(image)"
            />
          </view>
        </scroll-view>
        <view class="tutorial-actions">
          <button class="tutorial-secondary" @click="closeTutorial">{{ t('client.add.tutorial_close') }}</button>
          <button class="tutorial-primary" @click="downloadTutorial">{{ t('client.add.tutorial_download') }}</button>
        </view>
      </view>
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
const tutorialDialog = reactive({
  visible: false,
  type: 'android',
});
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

const SOCIAL_LOGIN_TUTORIALS = {
  android: {
    titleKey: 'client.add.tutorial_android_title',
    docTitle: 'Android 安卓 (Hướng dẫn liên kết cho tài khoản đăng nhập FB và Google)',
    download: '/static/tutorials/social-login/android/android-social-login.docx',
    steps: [
      { text: 'Đăng nhập vào game và nhấn vào cửa sổ nổi của SDK để hiển thị giao diện SDK', images: ['/static/tutorials/social-login/android/step-01.png'] },
      { text: 'Tắt kết nối mạng rồi nhấn ngay vào nút nạp', images: ['/static/tutorials/social-login/android/step-02.png', '/static/tutorials/social-login/android/step-03.png'] },
      { text: 'Sau khi hiện ra trang nạp thì sao chép đường link (URL) của trang nạp.', images: ['/static/tutorials/social-login/android/step-04.png'] },
      { text: 'Từ đường link, lấy token đăng nhập.', images: [] },
      { text: 'Dùng token và UID của tài khoản để thực hiện liên kết', images: ['/static/tutorials/social-login/android/step-05.png'] },
      { text: 'Lưu ý: Bạn nhấn ở cửa sổ nổi là sẽ hiển thị thông tin UID nha (UID: MXXXXX)', images: ['/static/tutorials/social-login/android/step-06.png'] },
    ],
  },
  ios: {
    titleKey: 'client.add.tutorial_ios_title',
    docTitle: 'IOS (Hướng dẫn liên kết cho tài khoản đăng nhập bằng Facebook và Google ở hệ điều hành IOS)',
    download: '/static/tutorials/social-login/ios/ios-social-login.docx',
    steps: [
      { text: 'Mở game và vào giao diện Nạp Web trong game', images: ['/static/tutorials/social-login/ios/step-01.png'] },
      { text: 'Sau đó vào Cài đặt -> Cellular -> Cellular Data -> Show All, rồi thực hiện tắt quyền sử dụng dữ liệu mạng của trình duyệt (Safari hoặc trình duyệt được sử dụng)', images: ['/static/tutorials/social-login/ios/step-02.png'] },
      { text: 'Quay lại game và nhấn vào nút chuyển sang trang Nạp Web.', images: ['/static/tutorials/social-login/ios/step-03.png'] },
      { text: 'Lúc này trình duyệt sẽ mở, rồi bạn nhấn lấy token đăng nhập từ URL nha', images: ['/static/tutorials/social-login/ios/step-04.png', '/static/tutorials/social-login/ios/step-05.png'] },
      { text: 'Dùng token và UID của tài khoản để thực hiện liên kết', images: ['/static/tutorials/social-login/ios/step-06.png'] },
      { text: 'Lưu ý: Bạn nhấn ở cửa sổ nổi là sẽ hiển thị thông tin UID nha (UID: MXXXXX)', images: ['/static/tutorials/social-login/ios/step-07.png'] },
    ],
  },
};

const activeTutorial = computed(() => {
  const tutorial = SOCIAL_LOGIN_TUTORIALS[tutorialDialog.type] || SOCIAL_LOGIN_TUTORIALS.android;
  return {
    ...tutorial,
    title: t(tutorial.titleKey),
  };
});

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

function openTutorial(type) {
  tutorialDialog.type = type;
  tutorialDialog.visible = true;
}

function closeTutorial() {
  tutorialDialog.visible = false;
}

function previewTutorialImage(image) {
  const urls = activeTutorial.value.steps.flatMap(step => step.images || []);
  uni.previewImage({ urls, current: image });
}

function downloadTutorial() {
  const url = activeTutorial.value.download;
  if (typeof document !== 'undefined') {
    const link = document.createElement('a');
    link.href = url;
    link.download = url.split('/').pop();
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    return;
  }
  uni.downloadFile({
    url,
    success: result => {
      if (result.statusCode !== 200) {
        uni.showToast({ title: t('client.add.tutorial_download_failed'), icon: 'none' });
        return;
      }
      uni.openDocument({ filePath: result.tempFilePath });
    },
    fail: () => uni.showToast({ title: t('client.add.tutorial_download_failed'), icon: 'none' }),
  });
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

.tutorial-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18rpx;
  margin-top: 28rpx;
}

.tutorial-button {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 84rpx;
  padding: 0 18rpx;
  line-height: 1.3;
  margin: 0;
  border: 2rpx solid #f7b500;
  border-radius: 0;
  background: rgba(255, 255, 255, 0.28);
  color: #f5a400;
  font-size: 26rpx;
  font-weight: 800;
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

.modal-mask {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 72rpx 28rpx 32rpx;
  background: rgba(15, 23, 42, 0.34);
  box-sizing: border-box;
}

.tutorial-dialog {
  width: calc(100vw - 56rpx);
  max-width: 680px;
  height: 72vh;
  max-height: calc(100vh - 104rpx);
  display: flex;
  flex-direction: column;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 24rpx 70rpx rgba(15, 23, 42, 0.24);
  overflow: hidden;
}

.tutorial-head,
.tutorial-actions {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.tutorial-head {
  justify-content: space-between;
  gap: 20rpx;
  padding: 28rpx 30rpx 20rpx;
  border-bottom: 1px solid #eef2f7;
}

.tutorial-title {
  min-width: 0;
  color: #111827;
  font-size: 30rpx;
  font-weight: 800;
  line-height: 1.35;
}

.tutorial-close {
  flex-shrink: 0;
  width: 48rpx;
  height: 48rpx;
  color: #98a2b3;
  font-size: 44rpx;
  line-height: 44rpx;
  text-align: center;
}

.tutorial-body {
  flex: 1;
  min-height: 0;
  padding: 24rpx 30rpx;
  box-sizing: border-box;
}

.tutorial-doc-title {
  display: block;
  margin-bottom: 26rpx;
  color: #111827;
  font-size: 28rpx;
  font-weight: 800;
  line-height: 1.5;
}

.tutorial-step {
  margin-bottom: 28rpx;
}

.tutorial-step-index {
  display: inline-block;
  width: 38rpx;
  height: 38rpx;
  margin-right: 12rpx;
  border-radius: 50%;
  background: #29b6f6;
  color: #fff;
  font-size: 22rpx;
  font-weight: 800;
  line-height: 38rpx;
  text-align: center;
}

.tutorial-step-text {
  color: #253244;
  font-size: 26rpx;
  font-weight: 700;
  line-height: 1.55;
}

.tutorial-image {
  display: block;
  width: 100%;
  max-width: 560rpx;
  margin: 18rpx auto 0;
  border-radius: 8px;
  border: 1px solid #e5edf5;
}

.tutorial-actions {
  justify-content: flex-end;
  gap: 16rpx;
  padding: 20rpx 30rpx 28rpx;
  border-top: 1px solid #eef2f7;
}

.tutorial-secondary,
.tutorial-primary {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 180rpx;
  height: 72rpx;
  padding: 0 14rpx;
  line-height: 1.2;
  margin: 0;
  border-radius: 8px;
  font-size: 26rpx;
  font-weight: 800;
}

.tutorial-secondary {
  border: 1px solid #d6e2eb;
  background: #fff;
  color: #475467;
}

.tutorial-primary {
  background: #29b6f6;
  color: #fff;
}

@media (max-width: 420px) {
  .tutorial-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .tutorial-actions {
    flex-direction: column;
  }

  .tutorial-secondary,
  .tutorial-primary {
    width: 100%;
  }
}
</style>
