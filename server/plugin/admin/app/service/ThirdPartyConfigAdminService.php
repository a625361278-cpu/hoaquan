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
            'script_ws_url' => (string)($settings['third_party_script_ws_url'] ?? 'ws://hoavienpro.com/ws/third-party/script'),
            'script_full_url' => $this->fullScriptUrl((string)($settings['third_party_script_ws_url'] ?? 'ws://hoavienpro.com/ws/third-party/script'), $token),
            'sign_secret' => (string)($settings['third_party_sign_secret'] ?? ''),
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

        $this->settings->saveSettings([
            'third_party_enabled' => $enabled ? '1' : '0',
            'third_party_script_token' => $token,
            'third_party_script_ws_url' => $scriptWsUrl,
            'third_party_sign_secret' => trim((string)($payload['third_party_sign_secret'] ?? '')),
            'third_party_transport' => 'websocket',
        ]);
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
