const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';
const TOKEN_KEY = 'gameassist_token';

export function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || '';
}

export function setToken(token) {
  uni.setStorageSync(TOKEN_KEY, token);
}

export function clearToken() {
  uni.removeStorageSync(TOKEN_KEY);
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
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      success: (res) => {
        const payload = res.data || {};
        if (payload.code !== 0) {
          reject(new Error(payload.msg || '请求失败'));
          return;
        }
        resolve(payload.data || {});
      },
      fail: () => reject(new Error('网络请求失败')),
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
