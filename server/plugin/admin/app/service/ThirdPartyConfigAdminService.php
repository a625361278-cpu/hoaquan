<?php

namespace plugin\admin\app\service;

use app\service\SystemSettingService;
use app\support\I18n;
use RuntimeException;

class ThirdPartyConfigAdminService
{
    public function __construct(
        private ?SystemSettingService $settings = null,
        private string $locale = I18n::DEFAULT_LOCALE
    )
    {
        $this->settings ??= new SystemSettingService();
        $this->locale = I18n::normalizeLocale($this->locale);
    }

    public function config(): array
    {
        $settings = $this->settings->thirdPartyRawSettings();
        $token = (string)($settings['third_party_script_token'] ?? '');

        return [
            'enabled' => ($settings['third_party_enabled'] ?? '0') === '1',
            'script_token' => $token,
            'script_ws_url' => (string)($settings['third_party_script_ws_url'] ?? ''),
            'script_full_url' => $this->fullScriptUrl((string)($settings['third_party_script_ws_url'] ?? ''), $token),
            'sign_secret' => (string)($settings['third_party_sign_secret'] ?? ''),
            'game_account_max_count' => $this->gameAccountMaxCount($settings['game_account_max_count'] ?? (string)SystemSettingService::DEFAULT_GAME_ACCOUNT_MAX_COUNT),
            'facebook_login_enabled' => ($settings['facebook_login_enabled'] ?? '1') === '1',
            'google_login_enabled' => ($settings['google_login_enabled'] ?? '1') === '1',
        ];
    }

    public function save(array $payload): void
    {
        $enabled = !empty($payload['third_party_enabled']) && (string)$payload['third_party_enabled'] !== '0';
        $token = trim((string)($payload['third_party_script_token'] ?? ''));
        if (!empty($payload['reset_script_token'])) {
            $token = bin2hex(random_bytes(24));
        }
        if ($enabled && $token === '') {
            throw new RuntimeException(I18n::t('admin.third_party_config.script_token_required', [], $this->locale));
        }
        $scriptWsUrl = trim((string)($payload['third_party_script_ws_url'] ?? ''));
        if ($enabled && $scriptWsUrl === '') {
            throw new RuntimeException(I18n::t('admin.third_party_config.script_url_required', [], $this->locale));
        }
        $gameAccountMaxCount = $this->gameAccountMaxCount($payload['game_account_max_count'] ?? '');

        $this->settings->saveSettings([
            'third_party_enabled' => $enabled ? '1' : '0',
            'third_party_script_token' => $token,
            'third_party_script_ws_url' => $scriptWsUrl,
            'third_party_sign_secret' => trim((string)($payload['third_party_sign_secret'] ?? '')),
            'third_party_transport' => 'websocket',
            'game_account_max_count' => (string)$gameAccountMaxCount,
            'facebook_login_enabled' => !empty($payload['facebook_login_enabled']) && (string)$payload['facebook_login_enabled'] !== '0' ? '1' : '0',
            'google_login_enabled' => !empty($payload['google_login_enabled']) && (string)$payload['google_login_enabled'] !== '0' ? '1' : '0',
        ]);
    }

    private function gameAccountMaxCount(mixed $value): int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^\d+$/', $raw)) {
            throw new RuntimeException(I18n::t('admin.third_party_config.game_account_max_count_invalid', [
                'min' => SystemSettingService::MIN_GAME_ACCOUNT_MAX_COUNT,
                'max' => SystemSettingService::MAX_GAME_ACCOUNT_MAX_COUNT,
            ], $this->locale));
        }
        $count = (int)$raw;
        if ($count < SystemSettingService::MIN_GAME_ACCOUNT_MAX_COUNT || $count > SystemSettingService::MAX_GAME_ACCOUNT_MAX_COUNT) {
            throw new RuntimeException(I18n::t('admin.third_party_config.game_account_max_count_invalid', [
                'min' => SystemSettingService::MIN_GAME_ACCOUNT_MAX_COUNT,
                'max' => SystemSettingService::MAX_GAME_ACCOUNT_MAX_COUNT,
            ], $this->locale));
        }
        return $count;
    }

    private function fullScriptUrl(string $baseUrl, string $token): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || $token === '') {
            return $baseUrl;
        }
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . 'token=' . rawurlencode($token);
    }
}
