<?php

namespace app\service;

use support\Db;

class SystemSettingService
{
    public const DEFAULT_GAME_ACCOUNT_MAX_COUNT = 3;
    public const MIN_GAME_ACCOUNT_MAX_COUNT = 1;
    public const MAX_GAME_ACCOUNT_MAX_COUNT = 100;
    public const DEFAULT_REGISTRATION_REWARD_POINTS = 1;
    public const MIN_REGISTRATION_REWARD_POINTS = 0;
    public const MAX_REGISTRATION_REWARD_POINTS = 1000;

    public const THIRD_PARTY_SETTING_NAMES = [
        'third_party_enabled',
        'third_party_base_url',
        'third_party_ws_url',
        'third_party_ws_urls',
        'third_party_ws_connection_capacity',
        'third_party_script_token',
        'third_party_script_ws_url',
        'third_party_transport',
        'third_party_sign_secret',
        'game_account_max_count',
        'facebook_login_enabled',
        'google_login_enabled',
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

        return [
            'enabled' => ($settings['third_party_enabled'] ?? '0') === '1',
            'base_url' => $settings['third_party_base_url'] ?? '',
            'script_token' => (string)($settings['third_party_script_token'] ?? ''),
            'script_ws_url' => (string)($settings['third_party_script_ws_url'] ?? app_env('THIRD_PARTY_SCRIPT_WS_URL', '')),
            'transport' => $settings['third_party_transport'] ?? 'websocket',
            'sign_secret' => $settings['third_party_sign_secret'] ?? '',
            'credential_key' => ($settings['game_account_credential_key'] ?? '') ?: app_env('GAME_ACCOUNT_CREDENTIAL_KEY', ''),
            'facebook_login_enabled' => ($settings['facebook_login_enabled'] ?? '1') === '1',
            'google_login_enabled' => ($settings['google_login_enabled'] ?? '1') === '1',
        ];
    }

    public function supportedLoginMethods(): array
    {
        $settings = $this->thirdPartyConfig();
        $methods = [GameAccountLoginMethod::ACCOUNT_PASSWORD];
        if ($settings['facebook_login_enabled']) {
            $methods[] = GameAccountLoginMethod::FACEBOOK;
        }
        if ($settings['google_login_enabled']) {
            $methods[] = GameAccountLoginMethod::GOOGLE;
        }
        return $methods;
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

    public function gameAccountMaxCount(): int
    {
        $raw = trim($this->get('game_account_max_count', (string)self::DEFAULT_GAME_ACCOUNT_MAX_COUNT));
        if (!preg_match('/^\d+$/', $raw)) {
            throw new \RuntimeException('游戏账号上限配置不是有效整数：' . $raw);
        }

        $value = (int)$raw;
        if ($value < self::MIN_GAME_ACCOUNT_MAX_COUNT || $value > self::MAX_GAME_ACCOUNT_MAX_COUNT) {
            throw new \RuntimeException('游戏账号上限配置超出允许范围：' . $value);
        }
        return $value;
    }

    public function registrationRewardPoints(): int
    {
        $raw = trim($this->get('registration_reward_points', (string)self::DEFAULT_REGISTRATION_REWARD_POINTS));
        if (!preg_match('/^\d+$/', $raw)) {
            throw new \RuntimeException('注册赠送点数配置不是有效整数：' . $raw);
        }

        $value = (int)$raw;
        if ($value < self::MIN_REGISTRATION_REWARD_POINTS || $value > self::MAX_REGISTRATION_REWARD_POINTS) {
            throw new \RuntimeException('注册赠送点数配置超出允许范围：' . $value);
        }
        return $value;
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
}
