<?php

namespace app\service;

use support\Db;

class SystemSettingService
{
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

    public function thirdPartyConfig(): array
    {
        $rows = Db::table('ga_system_settings')
            ->whereIn('name', ['third_party_enabled', 'third_party_base_url', 'third_party_sign_secret'])
            ->get();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row->name] = $row->value;
        }

        return [
            'enabled' => ($settings['third_party_enabled'] ?? '0') === '1',
            'base_url' => $settings['third_party_base_url'] ?? '',
            'sign_secret' => $settings['third_party_sign_secret'] ?? '',
        ];
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
