import { getLocale, translate } from './i18n';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';
const TOKEN_KEY = 'gameassist_token';
const LOGIN_ANNOUNCEMENT_PENDING_KEY = 'gameassist_login_announcement_pending';

export function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || '';
}

export function setToken(token) {
  uni.setStorageSync(TOKEN_KEY, token);
}

export function clearToken() {
  uni.removeStorageSync(TOKEN_KEY);
  uni.removeStorageSync(LOGIN_ANNOUNCEMENT_PENDING_KEY);
}

export function markLoginAnnouncementPending() {
  uni.setStorageSync(LOGIN_ANNOUNCEMENT_PENDING_KEY, '1');
}

export function consumeLoginAnnouncementPending() {
  const pending = uni.getStorageSync(LOGIN_ANNOUNCEMENT_PENDING_KEY) === '1';
  if (pending) {
    uni.removeStorageSync(LOGIN_ANNOUNCEMENT_PENDING_KEY);
  }
  return pending;
}

export function request(options) {
  const token = getToken();
  return new Promise((resolve, reject) => {
    uni.request({
      url: API_BASE_URL + options.url,
      method: options.method || 'GET',
      data: options.data || {},
      header: {
        'Content-Type': 'application/json',
        'X-Locale': getLocale(),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      success: (res) => {
        const payload = res.data || {};
        if (payload.code !== 0) {
          const error = new Error(payload.msg || translate('client.error.request_failed'));
          error.code = payload.code;
          reject(error);
          return;
        }
        resolve(payload.data || {});
      },
      fail: () => reject(new Error(translate('client.error.network'))),
    });
  });
}

export function requireLogin() {
  if (!getToken()) {
    uni.redirectTo({ url: '/pages/login/index' });
    return false;
  }
  return true;
}
