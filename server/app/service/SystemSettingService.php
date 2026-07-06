<?php

namespace app\service;

use support\Db;

class SystemSettingService
{
    public const THIRD_PARTY_SETTING_NAMES = [
        'third_party_enabled',
        'third_party_base_url',
        'third_party_ws_url',
        'third_party_ws_urls',
        'third_party_ws_connection_capacity',
        'third_party_transport',
        'third_party_sign_secret',
    ];

    public const SMTP_SETTING_NAMES = [
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_email',
        'smtp_from_name',
    ];

    public const AUTH_VERIFICATION_MODES = [
        'security_question',
        'email_code',
    ];

    public function thirdPartyConfig(): array
    {
        $rows = Db::table('ga_system_settings')
            ->whereIn('name', array_merge(self::THIRD_PARTY_SETTING_NAMES, ['game_account_credential_key']))
            ->get();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->name] = $row->value;
        }

        $wsUrls = $this->parseWsUrls(
            (string)($settings['third_party_ws_urls'] ?? ''),
            (string)($settings['third_party_ws_url'] ?? '')
        );

        return [
            'enabled' => ($settings['third_party_enabled'] ?? '0') === '1',
            'base_url' => $settings['third_party_base_url'] ?? '',
            'ws_url' => $wsUrls[0] ?? '',
            'ws_urls' => $wsUrls,
            'ws_connection_capacity' => max(1, (int)($settings['third_party_ws_connection_capacity'] ?? 10)),
            'transport' => $settings['third_party_transport'] ?? 'websocket',
            'sign_secret' => $settings['third_party_sign_secret'] ?? '',
            'credential_key' => $settings['game_account_credential_key'] ?: app_env('GAME_ACCOUNT_CREDENTIAL_KEY', ''),
        ];
    }

    public function thirdPartyRawSettings(): array
    {
        return $this->settingsByNames(self::THIRD_PARTY_SETTING_NAMES);
    }

    public function saveSettings(array $settings): void
    {
        foreach ($settings as $name => $value) {
            Db::table('ga_system_settings')->updateOrInsert(
                ['name' => $name],
                ['value' => (string)$value]
            );
        }
    }

    public function smtpConfig(): array
    {
        $settings = $this->settingsByNames(self::SMTP_SETTING_NAMES);

        return [
            'enabled' => ($settings['smtp_enabled'] ?? '0') === '1',
            'host' => trim((string)($settings['smtp_host'] ?? '')),
            'port' => (int)($settings['smtp_port'] ?? 0),
            'username' => trim((string)($settings['smtp_username'] ?? '')),
            'password' => (string)($settings['smtp_password'] ?? ''),
            'encryption' => trim((string)($settings['smtp_encryption'] ?? 'tls')),
            'from_email' => trim((string)($settings['smtp_from_email'] ?? '')),
            'from_name' => trim((string)($settings['smtp_from_name'] ?? 'Hoa Quán')),
        ];
    }

    public function authVerificationMode(): string
    {
        $mode = trim($this->get('auth_verification_mode', 'security_question'));
        if (!in_array($mode, self::AUTH_VERIFICATION_MODES, true)) {
            throw new \RuntimeException('认证方式配置错误：' . $mode);
        }
        return $mode;
    }

    public function get(string $name, string $default = ''): string
    {
        $value = Db::table('ga_system_settings')->where('name', $name)->value('value');
        return $value === null ? $default : (string)$value;
    }

    private function settingsByNames(array $names): array
    {
        $rows = Db::table('ga_system_settings')->whereIn('name', $names)->get();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->name] = $row->value;
        }
        return $settings;
    }

    private function parseWsUrls(string $multiLineValue, string $legacyValue): array
    {
        $source = trim($multiLineValue) !== '' ? $multiLineValue : $legacyValue;
        $urls = preg_split('/\r\n|\r|\n/', $source) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            $urls
        ), static fn (string $url): bool => $url !== ''));
    }
}
