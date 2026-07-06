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
        $urlsText = trim((string)($settings['third_party_ws_urls'] ?? ''));
        if ($urlsText === '') {
            $urlsText = trim((string)($settings['third_party_ws_url'] ?? ''));
        }

        return [
            'enabled' => ($settings['third_party_enabled'] ?? '0') === '1',
            'ws_urls_text' => $urlsText,
            'ws_connection_capacity' => max(1, (int)($settings['third_party_ws_connection_capacity'] ?? 10)),
            'sign_secret' => (string)($settings['third_party_sign_secret'] ?? ''),
        ];
    }

    public function save(array $payload): void
    {
        $enabled = !empty($payload['third_party_enabled']) && (string)$payload['third_party_enabled'] !== '0';
        $urls = $this->normalizeUrls((string)($payload['third_party_ws_urls'] ?? ''));
        if ($enabled && $urls === []) {
            throw new RuntimeException(I18n::t('admin.third_party_config.websocket_required', [], $this->locale));
        }

        $capacity = (int)($payload['third_party_ws_connection_capacity'] ?? 10);
        if ($capacity < 1) {
            throw new RuntimeException(I18n::t('admin.third_party_config.capacity_invalid', [], $this->locale));
        }

        $this->settings->saveSettings([
            'third_party_enabled' => $enabled ? '1' : '0',
            'third_party_ws_urls' => implode("\n", $urls),
            'third_party_ws_url' => $urls[0] ?? '',
            'third_party_ws_connection_capacity' => (string)$capacity,
            'third_party_sign_secret' => trim((string)($payload['third_party_sign_secret'] ?? '')),
            'third_party_transport' => 'websocket',
        ]);
    }

    private function normalizeUrls(string $urlsText): array
    {
        $urls = preg_split('/\r\n|\r|\n/', $urlsText) ?: [];
        return array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            $urls
        ), static fn (string $url): bool => $url !== ''));
    }
}
