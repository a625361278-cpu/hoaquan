import { createI18n } from 'vue-i18n';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';
export const LOCALE_KEY = 'gameassist_locale';
export const DEFAULT_LOCALE = 'vi';
export const SUPPORTED_LOCALES = ['zh_CN', 'vi'];
export const CLIENT_LOCALE_LOCKED = true;

export function normalizeLocale(locale) {
  if (CLIENT_LOCALE_LOCKED) {
    return DEFAULT_LOCALE;
  }
  return SUPPORTED_LOCALES.includes(locale) ? locale : DEFAULT_LOCALE;
}

export function getLocale() {
  return normalizeLocale(uni.getStorageSync(LOCALE_KEY) || DEFAULT_LOCALE);
}

export function persistLocale(locale) {
  const normalized = normalizeLocale(locale);
  uni.setStorageSync(LOCALE_KEY, normalized);
  if (typeof document !== 'undefined') {
    document.cookie = `${LOCALE_KEY}=${normalized}; path=/; max-age=31536000; SameSite=Lax`;
  }
  return normalized;
}

export const i18n = createI18n({
  legacy: false,
  locale: getLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: {},
});

export function translate(key, params = {}) {
  return i18n.global.t(key, params);
}

export function loadLocaleMessages(locale = getLocale()) {
  const normalized = normalizeLocale(locale);
  return new Promise((resolve, reject) => {
    uni.request({
      url: `${API_BASE_URL}/api/i18n/messages?locale=${encodeURIComponent(normalized)}`,
      method: 'GET',
      success: (res) => {
        const payload = res.data || {};
        if (payload.code !== 0 || !payload.data || !payload.data.messages) {
          reject(new Error(payload.msg || `i18n package load failed: ${normalized}`));
          return;
        }
        i18n.global.setLocaleMessage(normalized, payload.data.messages);
        i18n.global.locale.value = normalized;
        persistLocale(normalized);
        resolve(payload.data.messages);
      },
      fail: () => reject(new Error(`i18n package request failed: ${normalized}`)),
    });
  });
}

export async function switchLocale(locale) {
  const normalized = persistLocale(locale);
  await loadLocaleMessages(normalized);
  return normalized;
}
